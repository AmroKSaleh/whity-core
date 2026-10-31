<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth\OAuth;

use PDO;
use Whity\Auth\ActiveTenantMembershipGuard;
use Whity\Auth\Oidc\JwksProvider;
use Whity\Core\Http\HttpClient;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Auth\McpScope;

/**
 * Accepts access tokens from the configured OAuth authorization server at /mcp
 * (WHIT-709 part B, ADR-0015).
 *
 * Part A told clients where to sign in. This is the other half: the token a
 * client comes back with is verified and turned into the same McpPrincipal an
 * MCP token produces, so RBAC, scope, the tenant switch, rate limits and audit
 * all apply unchanged.
 *
 * Verification is {@see IssuerJwtVerifier}'s: signature against the issuer's
 * published asymmetric key, `exp` required, `iss` exact, and `aud` must name
 * this MCP endpoint (RFC 8707). A token minted for another resource is not
 * accepted here.
 *
 * Who the token is: `(iss, sub)` through the GLOBAL-trust namespace of
 * `external_identities`, the links single sign-on already keeps. The issuer is
 * operator configuration, which is exactly what global trust means (migration
 * 047). There is no linking or provisioning here: a person signs in to Whity
 * once with the same provider, and from then on their token resolves.
 *
 * Which workspace: the tenant claim (`tenant_id` unless configured) when the
 * token carries one, else the profile's only active membership, else refused.
 * Either way an ACTIVE membership is required, so suspension takes effect
 * immediately. The system tenant (id 0) is never reachable this way: its
 * authority needs no membership row, so an outside server naming it would
 * otherwise grant cross-tenant power to any linked account.
 *
 * Who acts (WHIT-825): a token whose client the authorization server marks
 * as an agent (`whity_agent: true`) is an `ai-agent` principal acting for the
 * token's person, under the agent model of MCP-TOOL-SURFACE §5.11. The token
 * carries no grant entries, so the default grant applies: reads Allowed,
 * writes Ask first (a proposal the person approves). Any other token, the claim
 * absent or not exactly `true`, is the person's own client (`oauth`), as
 * before. The claim is believed only because the signature, issuer and
 * audience checks above passed: it is the trusted issuer's statement, and an
 * agent principal can only ever do less than the person.
 *
 * Returns null for a token that is not valid (the 401 that sends a client to
 * sign in) and throws {@see OAuthTokenRefusedException} for one that is valid
 * but unusable (a 403 with a message, since signing in again would not help).
 *
 * Instance state is configuration that is the same for every request:
 * FrankenPHP worker-safe.
 */
final class OAuthAccessTokenVerifier
{
    public const DEFAULT_TENANT_CLAIM = 'tenant_id';

    /** The claim the authorization server sets on an agent client's tokens. */
    public const AGENT_CLAIM = 'whity_agent';

    /**
     * An agent's principal id: stable across its access tokens, so its
     * proposals stay its own after a refresh, and never equal to an MCP
     * token's jti. Fits agent_principal_id (64).
     */
    public const AGENT_ID_PREFIX = 'oauth-agent:';

    private readonly IssuerJwtVerifier $jwt;

    public function __construct(
        private readonly string $issuer,
        private readonly string $resource,
        HttpClient $http,
        JwksProvider $jwks,
        private readonly PDO $db,
        ?string $jwksUri = null,
        private readonly string $tenantClaim = self::DEFAULT_TENANT_CLAIM,
    ) {
        $this->jwt = new IssuerJwtVerifier($issuer, $http, $jwks, $jwksUri);
    }

    /**
     * Built when {@see ProtectedResource} is on, from MCP_OAUTH_JWKS_URI
     * (optional; discovered otherwise) and MCP_OAUTH_TENANT_CLAIM.
     */
    public static function fromEnv(ProtectedResource $resource, HttpClient $http, JwksProvider $jwks, PDO $db): ?self
    {
        if (!$resource->enabled()) {
            return null;
        }
        $jwksUri = self::env('MCP_OAUTH_JWKS_URI');
        $claim   = self::env('MCP_OAUTH_TENANT_CLAIM');
        return new self(
            (string) $resource->issuer(),
            (string) $resource->resource(),
            $http,
            $jwks,
            $db,
            $jwksUri,
            $claim ?? self::DEFAULT_TENANT_CLAIM,
        );
    }

    /**
     * @throws OAuthTokenRefusedException When the token is valid but cannot be used here.
     */
    public function verify(string $token): ?McpPrincipal
    {
        $claims = $this->jwt->verify($token, $this->resource);
        if ($claims === null) {
            return null;
        }

        $subject = $claims['sub'] ?? null;
        if (!is_string($subject) || $subject === '') {
            return null;
        }

        $scope = self::scopeFrom($claims);
        if ($scope === []) {
            throw new OAuthTokenRefusedException(
                'The token grants none of the MCP scopes (' . implode(', ', McpScope::CAPABILITIES) . '). Reconnect and allow access.'
            );
        }

        $link = (new ExternalIdentityRepository($this->db))->findGlobalByIssuerSubject($this->issuer, $subject);
        if ($link === null) {
            throw new OAuthTokenRefusedException(
                'This sign-in is not linked to a Whity account. Sign in to Whity once with the same account, then connect again.'
            );
        }
        $profileId = (int) $link['profile_id'];
        $tenantId  = $this->tenantFor($profileId, $claims);

        $guard = new ActiveTenantMembershipGuard($this->db);
        if (!$guard->allows(['profile_id' => $profileId, 'active_tenant_id' => $tenantId])) {
            throw new OAuthTokenRefusedException('This account is not an active member of the workspace the token names.');
        }

        $jti = $claims['jti'] ?? null;

        if (self::isAgentClient($claims)) {
            return new McpPrincipal(
                profileId: $profileId,
                userId: $profileId,
                tenantId: $tenantId,
                principalKind: AgentScopeGrant::AGENT_KIND,
                scope: $scope,
                jti: self::AGENT_ID_PREFIX . substr(hash('sha256', self::clientOf($claims) . "\n" . $profileId), 0, 40),
            );
        }

        return new McpPrincipal(
            profileId: $profileId,
            userId: $profileId,
            tenantId: $tenantId,
            principalKind: 'oauth',
            scope: $scope,
            // A stable per-token handle for notification bookkeeping. Prefixed so
            // it can never collide with an MCP token's jti.
            jti: 'oauth:' . (is_string($jti) && $jti !== '' ? $jti : substr(hash('sha256', $token), 0, 32)),
        );
    }

    /**
     * Whether the token's client is an agent: the claim is exactly `true`.
     * Anything else (absent, "true", 1) is a person's own client.
     *
     * @param array<string, mixed> $claims
     */
    private static function isAgentClient(array $claims): bool
    {
        return ($claims[self::AGENT_CLAIM] ?? null) === true;
    }

    /**
     * The OAuth client an agent token was issued to (RFC 9068 `client_id`, or
     * `azp`). An agent must say which client it is, or its proposals could not
     * be told apart from another agent's.
     *
     * @param array<string, mixed> $claims
     * @throws OAuthTokenRefusedException
     */
    private static function clientOf(array $claims): string
    {
        $client = $claims['client_id'] ?? $claims['azp'] ?? null;
        if (!is_string($client) || $client === '') {
            throw new OAuthTokenRefusedException('An agent token must name its client (client_id). Ask the sign-in server to add it.');
        }
        return $client;
    }

    /**
     * The workspace the token acts in.
     *
     * @param array<string, mixed> $claims
     * @throws OAuthTokenRefusedException
     */
    private function tenantFor(int $profileId, array $claims): int
    {
        if (array_key_exists($this->tenantClaim, $claims)) {
            $value = $claims[$this->tenantClaim];
            $tenantId = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
            if ($tenantId === null || $tenantId <= 0) {
                throw new OAuthTokenRefusedException(sprintf(
                    'The token\'s "%s" claim must name a workspace by its number. The system workspace cannot be used through sign-in; use an MCP token.',
                    $this->tenantClaim,
                ));
            }
            return $tenantId;
        }

        // @tenant-guard-ignore: resolves which tenant a signed-in profile acts in, so there is no tenant yet to scope by; the result is re-checked by ActiveTenantMembershipGuard
        $stmt = $this->db->prepare(
            "SELECT tenant_id FROM memberships WHERE profile_id = ? AND status = 'active' AND tenant_id <> 0 LIMIT 2"
        );
        $stmt->execute([$profileId]);
        $tenants = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($tenants) === 1) {
            return (int) $tenants[0];
        }
        if ($tenants === []) {
            throw new OAuthTokenRefusedException('This account is not an active member of any workspace.');
        }
        throw new OAuthTokenRefusedException(sprintf(
            'This account belongs to several workspaces and the token does not say which. The sign-in server must add a "%s" claim.',
            $this->tenantClaim,
        ));
    }

    /**
     * The MCP scopes the token grants: RFC 9068 `scope` (space-separated), or
     * the `scp` some servers use instead.
     *
     * @param array<string, mixed> $claims
     * @return list<string>
     */
    private static function scopeFrom(array $claims): array
    {
        $raw = $claims['scope'] ?? $claims['scp'] ?? null;
        $granted = is_string($raw) ? preg_split('/\s+/', trim($raw)) : (is_array($raw) ? $raw : []);
        $granted = array_filter((array) $granted, 'is_string');
        return array_values(array_intersect(McpScope::CAPABILITIES, $granted));
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);
        $value = is_string($value) ? trim($value) : '';
        return $value === '' ? null : $value;
    }
}
