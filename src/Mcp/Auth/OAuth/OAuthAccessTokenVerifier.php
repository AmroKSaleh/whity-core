<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth\OAuth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use PDO;
use Whity\Auth\ActiveTenantMembershipGuard;
use Whity\Auth\Oidc\JwksProvider;
use Whity\Core\Http\HttpClient;
use Whity\Core\Identity\ExternalIdentityRepository;
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
 * Verification (firebase/php-jwt, the library the SSO path already uses):
 *   - the token's unverified `iss` must be the configured issuer, checked first
 *     so a stray bearer never costs a network fetch;
 *   - signature against the issuer's JWKS, found through its RFC 8414 or OIDC
 *     metadata (whose `issuer` must match) and cached with key-rotation refresh.
 *     Only asymmetric keys are used: a symmetric (`oct`) key in a JWKS would let
 *     anyone holding it mint tokens, so it is dropped;
 *   - `exp` required, `iss` exact, and `aud` must name this MCP endpoint
 *     (RFC 8707). A token minted for another resource is not accepted here.
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
 * Returns null for a token that is not valid (the 401 that sends a client to
 * sign in) and throws {@see OAuthTokenRefusedException} for one that is valid
 * but unusable (a 403 with a message, since signing in again would not help).
 *
 * Instance state is the discovered JWKS location only, configuration that is
 * the same for every request: FrankenPHP worker-safe.
 */
final class OAuthAccessTokenVerifier
{
    public const DEFAULT_TENANT_CLAIM = 'tenant_id';

    /** firebase/php-jwt clock-skew tolerance, the same as JwtParser's. */
    private const LEEWAY_SECONDS = 30;

    /** Key types that are public keys. `oct` (a shared secret) is not one. */
    private const ASYMMETRIC_KEY_TYPES = ['RSA', 'EC', 'OKP'];

    private ?string $discoveredJwksUri = null;

    public function __construct(
        private readonly string $issuer,
        private readonly string $resource,
        private readonly HttpClient $http,
        private readonly JwksProvider $jwks,
        private readonly PDO $db,
        private readonly ?string $jwksUri = null,
        private readonly string $tenantClaim = self::DEFAULT_TENANT_CLAIM,
    ) {}

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
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return null;
        }
        $header  = self::decodeSegment($segments[0]);
        $payload = self::decodeSegment($segments[1]);
        if ($header === null || $payload === null || ($payload['iss'] ?? null) !== $this->issuer) {
            return null;
        }

        $claims = $this->verifiedClaims($token, is_string($header['kid'] ?? null) ? $header['kid'] : null);
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
     * Signature and the registered claims, or null.
     *
     * @return array<string, mixed>|null
     */
    private function verifiedClaims(string $token, ?string $kid): ?array
    {
        $jwksUri = $this->jwksUri ?? $this->discoverJwksUri();
        if ($jwksUri === null) {
            return null;
        }

        JWT::$leeway = self::LEEWAY_SECONDS;
        try {
            $keys = self::asymmetricKeys($this->jwks->getForKid($jwksUri, $kid));
            if ($keys === []) {
                return null;
            }
            // Each Key carries its JWKS entry's own algorithm, and JWT::decode
            // refuses a token whose `alg` differs from its key's, so the token
            // cannot choose `none` or a symmetric algorithm for itself.
            $decoded = JWT::decode($token, JWK::parseKeySet(['keys' => $keys], 'RS256'));
        } catch (\Throwable) {
            return null;
        }

        /** @var array<string, mixed>|null $claims */
        $claims = json_decode((string) json_encode($decoded), true);
        if (!is_array($claims) || !isset($claims['exp']) || ($claims['iss'] ?? null) !== $this->issuer) {
            return null;
        }

        $aud = $claims['aud'] ?? null;
        if (!in_array($this->resource, is_array($aud) ? $aud : [$aud], true)) {
            return null;
        }

        return $claims;
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

    /**
     * The JWKS location from the issuer's metadata: RFC 8414 first, then OIDC
     * discovery. Not cached on failure, so a server that was briefly down is
     * tried again on the next request.
     */
    private function discoverJwksUri(): ?string
    {
        if ($this->discoveredJwksUri !== null) {
            return $this->discoveredJwksUri;
        }
        foreach ($this->metadataUrls() as $url) {
            try {
                $doc = $this->http->getJson($url);
            } catch (\Throwable) {
                $doc = null;
            }
            if (
                is_array($doc)
                && ($doc['issuer'] ?? null) === $this->issuer
                && is_string($doc['jwks_uri'] ?? null)
                && str_starts_with(strtolower($doc['jwks_uri']), 'https://')
            ) {
                return $this->discoveredJwksUri = $doc['jwks_uri'];
            }
        }
        return null;
    }

    /** @return list<string> */
    private function metadataUrls(): array
    {
        $parts  = parse_url($this->issuer);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path   = rtrim((string) ($parts['path'] ?? ''), '/');
        return [
            // RFC 8414 §3.1: the well-known segment goes before the issuer's path.
            $origin . '/.well-known/oauth-authorization-server' . $path,
            $this->issuer . '/.well-known/openid-configuration',
        ];
    }

    /**
     * @param array<string, mixed> $jwks
     * @return list<array<string, mixed>>
     */
    private static function asymmetricKeys(array $jwks): array
    {
        $keys = [];
        foreach (is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [] as $key) {
            if (
                is_array($key)
                && in_array($key['kty'] ?? null, self::ASYMMETRIC_KEY_TYPES, true)
                && !(isset($key['alg']) && is_string($key['alg']) && (str_starts_with(strtoupper($key['alg']), 'HS') || strtolower($key['alg']) === 'none'))
                && ($key['use'] ?? 'sig') === 'sig'
            ) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /** @return array<string, mixed>|null */
    private static function decodeSegment(string $segment): ?array
    {
        $json = base64_decode(strtr($segment, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);
        $value = is_string($value) ? trim($value) : '';
        return $value === '' ? null : $value;
    }
}
