<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth\OAuth;

use PDO;
use Whity\Auth\Oidc\JwksProvider;
use Whity\Core\Http\HttpClient;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * GET /api/v1/oauth/workspaces: which workspaces a person belongs to, asked by
 * the OAuth authorization server (`MCP_OAUTH_ISSUER`) for its consent screen
 * (WHIT-802, ADR-0015 Amendment 1).
 *
 * The authorization server lets the person pick a workspace and puts it in the
 * /mcp access token as `tenant_id`. To offer the choice it needs this
 * instance's answer, so it calls here with a short-lived assertion it signs
 * with the same published key it signs access tokens with.
 *
 * No user RBAC: this is machine to machine. The caller is authorised by the
 * issuer's key alone, the same trust /mcp already places in that key, and the
 * answer is scoped to the one person the assertion names. It is a public route
 * for the tenant middleware (there is no tenant yet: picking one is the point),
 * covered by the kernel's pre-auth per-IP rate limit, and never an MCP tool.
 *
 * The assertion ({@see IssuerJwtVerifier}): header `typ` exactly
 * `whity-workspaces+jwt`, so an /mcp access token (`at+jwt`) cannot be replayed
 * here; `iss` the configured issuer; `aud` exactly this endpoint's URL; `exp`
 * required and at most {@see self::MAX_LIFETIME_SECONDS} after `iat`; `sub` a
 * non-empty string.
 *
 * Who: `(iss, sub)` through the GLOBAL-trust links of `external_identities`,
 * exactly as an access token resolves. An unlinked subject is a normal answer
 * (`linked: false`), so the consent screen can tell the person to sign in to
 * Whity once first.
 *
 * Answers 404 while OAuth is off, like the protected-resource metadata, and a
 * bare 401 for any assertion that does not verify, saying nothing about why.
 */
final class OAuthWorkspacesHandler
{
    /** The route as registered; the Router serves it under /api/v1. */
    public const PATH = '/api/oauth/workspaces';

    /** The versioned path the assertion's `aud` names, after APP_URL. */
    public const VERSIONED_PATH = '/api/v1/oauth/workspaces';

    /** The JWT header `typ` an assertion must carry. */
    public const ASSERTION_TYPE = 'whity-workspaces+jwt';

    /** The longest an assertion may live, `exp` - `iat`. */
    public const MAX_LIFETIME_SECONDS = 120;

    /**
     * @param IssuerJwtVerifier|null $verifier Null when OAuth is off.
     * @param string|null            $audience APP_URL + {@see self::VERSIONED_PATH}, or null when OAuth is off.
     */
    public function __construct(
        private readonly ?IssuerJwtVerifier $verifier,
        private readonly ?string $audience,
        private readonly PDO $db,
    ) {}

    /** On when {@see ProtectedResource} is (MCP_OAUTH_ISSUER and APP_URL both set). */
    public static function fromEnv(ProtectedResource $resource, string $appUrl, HttpClient $http, JwksProvider $jwks, PDO $db): self
    {
        $verifier = IssuerJwtVerifier::fromEnv($resource, $http, $jwks);
        return new self($verifier, $verifier === null ? null : self::audienceFor($appUrl), $db);
    }

    /** The URL an assertion's `aud` must name, or null with no APP_URL. */
    public static function audienceFor(string $appUrl): ?string
    {
        $appUrl = rtrim(trim($appUrl), '/');
        return $appUrl === '' ? null : $appUrl . self::VERSIONED_PATH;
    }

    /**
     * GET /api/v1/oauth/workspaces
     *
     * @param array<string, mixed> $params
     */
    public function list(Request $request, array $params = []): Response
    {
        if ($this->verifier === null || $this->audience === null) {
            return Response::error('Not found', 404);
        }

        $subject = $this->subject($request);
        if ($subject === null) {
            return Response::error('Unauthorized', 401)->withHeaders([
                'WWW-Authenticate' => 'Bearer error="invalid_token"',
                'Cache-Control'    => 'no-store',
            ]);
        }

        $link = (new ExternalIdentityRepository($this->db))
            ->findGlobalByIssuerSubject($this->verifier->issuer(), $subject);

        $body = $link === null
            ? ['linked' => false, 'workspaces' => []]
            : ['linked' => true, 'workspaces' => $this->workspaces((int) $link['profile_id'])];

        return Response::json($body)->withHeaders(['Cache-Control' => 'no-store']);
    }

    /** The assertion's verified `sub`, or null when it does not verify. */
    private function subject(Request $request): ?string
    {
        $header = trim((string) $request->getHeader('Authorization'));
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return null;
        }

        $claims = $this->verifier?->verify($m[1], (string) $this->audience, self::ASSERTION_TYPE);
        if ($claims === null) {
            return null;
        }

        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        if (!is_int($iat) || !is_int($exp) || $exp - $iat > self::MAX_LIFETIME_SECONDS) {
            return null;
        }

        $sub = $claims['sub'] ?? null;
        return is_string($sub) && $sub !== '' ? $sub : null;
    }

    /**
     * The profile's active workspaces, never the system tenant, in id order.
     *
     * @return list<array{id: int, name: string, slug: string|null}>
     */
    private function workspaces(int $profileId): array
    {
        // @tenant-guard-ignore: lists which tenants a profile belongs to before any tenant is chosen, so there is none to scope by; scoped by the profile the issuer-signed assertion resolved to
        $stmt = $this->db->prepare(
            "SELECT t.id, t.name, t.slug FROM memberships m JOIN tenants t ON t.id = m.tenant_id
             WHERE m.profile_id = ? AND m.status = 'active' AND m.tenant_id <> 0
             ORDER BY t.id"
        );
        $stmt->execute([$profileId]);

        $workspaces = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $slug = $row['slug'] ?? null;
            $workspaces[] = [
                'id'   => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => is_string($slug) && $slug !== '' ? $slug : null,
            ];
        }
        return $workspaces;
    }
}
