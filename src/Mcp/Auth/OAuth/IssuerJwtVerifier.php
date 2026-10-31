<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth\OAuth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Whity\Auth\Oidc\JwksProvider;
use Whity\Core\Http\HttpClient;

/**
 * Verifies a JWT signed by the configured OAuth authorization server
 * (`MCP_OAUTH_ISSUER`, ADR-0015) and returns its claims.
 *
 * Shared by the two places that trust that server's key: access tokens at /mcp
 * ({@see OAuthAccessTokenVerifier}) and the workspace-list assertion
 * ({@see OAuthWorkspacesHandler}). What each JWT is FOR is told apart by its
 * `aud` (and, for the assertion, its `typ`), never by which code verified it.
 *
 * Verification (firebase/php-jwt, the library the SSO path already uses):
 *   - the token's unverified `iss` must be the configured issuer, checked first
 *     so a stray bearer never costs a network fetch;
 *   - when a type is asked for, the header's `typ` must be it (RFC 8725 §3.11),
 *     so a JWT minted for one purpose cannot be replayed for another;
 *   - signature against the issuer's JWKS, found through its RFC 8414 or OIDC
 *     metadata (whose `issuer` must match) and cached with key-rotation refresh.
 *     Only asymmetric keys are used: a symmetric (`oct`) key in a JWKS would let
 *     anyone holding it mint tokens, so it is dropped;
 *   - `exp` required, `iss` exact, and `aud` must name the expected audience
 *     (a string, or an array containing it).
 *
 * Instance state is the discovered JWKS location only, configuration that is
 * the same for every request: FrankenPHP worker-safe.
 */
final class IssuerJwtVerifier
{
    /** firebase/php-jwt clock-skew tolerance, the same as JwtParser's. */
    private const LEEWAY_SECONDS = 30;

    /** Key types that are public keys. `oct` (a shared secret) is not one. */
    private const ASYMMETRIC_KEY_TYPES = ['RSA', 'EC', 'OKP'];

    private ?string $discoveredJwksUri = null;

    public function __construct(
        private readonly string $issuer,
        private readonly HttpClient $http,
        private readonly JwksProvider $jwks,
        private readonly ?string $jwksUri = null,
    ) {}

    /**
     * Built when {@see ProtectedResource} is on, with MCP_OAUTH_JWKS_URI
     * (optional; discovered otherwise).
     */
    public static function fromEnv(ProtectedResource $resource, HttpClient $http, JwksProvider $jwks): ?self
    {
        $issuer = $resource->issuer();
        if ($issuer === null) {
            return null;
        }
        return new self($issuer, $http, $jwks, self::env('MCP_OAUTH_JWKS_URI'));
    }

    /** The issuer every verified JWT comes from. */
    public function issuer(): string
    {
        return $this->issuer;
    }

    /**
     * The verified claims, or null when the JWT is not valid for `$audience`
     * (and, when given, of type `$type`).
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $token, string $audience, ?string $type = null): ?array
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
        // The header is covered by the signature checked below, so reading it
        // before that check only saves a fetch; it never decides acceptance alone.
        if ($type !== null && (!is_string($header['typ'] ?? null) || strcasecmp($header['typ'], $type) !== 0)) {
            return null;
        }

        $jwksUri = $this->jwksUri ?? $this->discoverJwksUri();
        if ($jwksUri === null) {
            return null;
        }

        $kid = is_string($header['kid'] ?? null) ? $header['kid'] : null;
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
        if (!in_array($audience, is_array($aud) ? $aud : [$aud], true)) {
            return null;
        }

        return $claims;
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
