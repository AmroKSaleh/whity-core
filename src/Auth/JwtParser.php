<?php

declare(strict_types=1);

namespace Whity\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * JWT parser/issuer (HS256) built on firebase/php-jwt.
 *
 * Issues and validates HMAC-SHA256 tokens. Decoding uses an explicit HS256
 * algorithm allowlist (defence-in-depth against alg-confusion), requires a valid
 * `exp`, and honours `nbf`/`iat` with a small leeway for clock skew. On top of the
 * standard validation, application policy requires the `jti` and `type` claims.
 *
 * The token-type discriminator, jti generation and revocation handling
 * (TokenValidator) are unchanged; only the encode/decode + claim hygiene moved to
 * the library, which also fixes the previous hand-rolled base64url padding bug.
 */
class JwtParser
{
    /** Clock-skew tolerance (seconds) applied to exp/nbf/iat checks. */
    private const LEEWAY_SECONDS = 60;

    private string $secret;

    /**
     * @param string $secret The secret key used for signing tokens.
     */
    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    /**
     * Parse and validate a JWT.
     *
     * Verifies the HS256 signature (algorithm allowlisted), enforces `exp`, honours
     * `nbf`/`iat` with leeway, and requires the `jti` and `type` claims.
     *
     * @param string $token The JWT to parse.
     * @return array<string, mixed>|null Decoded claims, or null if the token is
     *   invalid, expired, not-yet-valid, or missing a required claim.
     */
    public function parse(string $token): ?array
    {
        // Library config (not request state): tolerate minor clock skew on
        // exp/nbf/iat. Set deterministically each call — worker-safe.
        JWT::$leeway = self::LEEWAY_SECONDS;

        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (\Throwable $e) {
            // Invalid signature, malformed token, expired, or not-yet-valid.
            return null;
        }

        /** @var array<string, mixed>|null $claims */
        $claims = json_decode((string) json_encode($decoded), true);
        if (!is_array($claims)) {
            return null;
        }

        // `exp` is required: firebase/php-jwt only enforces it when present, so a
        // token issued without an expiry must be rejected here.
        if (!isset($claims['exp'])) {
            return null;
        }

        // Application policy: jti (revocation handle) and type (access/refresh)
        // must be present.
        if (!isset($claims['jti']) || !isset($claims['type'])) {
            return null;
        }

        return $claims;
    }

    /**
     * Create a signed JWT, adding a unique jti, the token type, and iat/exp.
     *
     * @param array<string, mixed> $payload The payload claims to include.
     * @param int $expiresIn Token lifetime in seconds (default 3600).
     * @param string $type Token type, e.g. 'access' or 'refresh'.
     * @return string The encoded JWT.
     */
    public function create(array $payload, int $expiresIn = 3600, string $type = 'access'): string
    {
        $now = time();
        $payload['jti'] = bin2hex(random_bytes(16));
        $payload['type'] = $type;
        $payload['iat'] = $now;
        $payload['exp'] = $now + $expiresIn;

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    /**
     * Verify a THIRD-PARTY-signed OIDC ID token against a provider JWKS (WC-24a1).
     *
     * This is the federated-sign-in path and is entirely separate from the
     * internal HS256 path above: external ID tokens are asymmetrically signed
     * (RS256/ES256), keyed by the JWT `kid` header, and carry OIDC claims
     * (`iss`/`aud`/`nonce`) — not our `jti`/`type`. The provider's public keys
     * are supplied as a decoded JWKS (fetch + cache handled by {@see JwksProvider}),
     * so this method does no I/O and is deterministic/worker-safe.
     *
     * Validates, in order: a supported asymmetric signature (RS256/ES256 only —
     * `none`/HS* are rejected because the keyset only carries asymmetric keys),
     * `exp` (required), `iss` exact-match, `aud` contains the expected audience,
     * and `nonce` exact-match when one is expected (the anti-replay binding to the
     * authorization request). Returns the claims on success, null on ANY failure.
     *
     * @param string               $token            The ID token (compact JWS).
     * @param array<string, mixed>  $jwks            The provider JWKS (`{"keys":[...]}`).
     * @param string               $expectedIssuer   Required `iss` value.
     * @param string               $expectedAudience Required member of `aud`.
     * @param string|null          $expectedNonce    Required `nonce`, or null to skip.
     * @return array<string, mixed>|null Verified claims, or null if invalid.
     */
    public function verifyExternalIdToken(
        string $token,
        array $jwks,
        string $expectedIssuer,
        string $expectedAudience,
        ?string $expectedNonce = null,
    ): ?array {
        JWT::$leeway = self::LEEWAY_SECONDS;

        try {
            // parseKeySet builds a kid => Key map, each Key carrying the JWKS
            // entry's own algorithm; JWT::decode then selects the key by the
            // token's `kid` header and verifies with that key's asymmetric alg.
            // An RS256 default covers JWKS entries that omit `alg` (e.g. some
            // providers) without ever enabling a symmetric algorithm.
            $keySet = JWK::parseKeySet($jwks, 'RS256');
            $decoded = JWT::decode($token, $keySet);
        } catch (\Throwable) {
            // Unknown kid, bad signature, malformed, expired, or not-yet-valid.
            return null;
        }

        /** @var array<string, mixed>|null $claims */
        $claims = json_decode((string) json_encode($decoded), true);
        if (!is_array($claims)) {
            return null;
        }

        // exp is mandatory for an ID token (firebase enforces it only when present).
        if (!isset($claims['exp'])) {
            return null;
        }

        // Issuer must match exactly.
        if (($claims['iss'] ?? null) !== $expectedIssuer) {
            return null;
        }

        // Audience: the claim is a string or a list; our client id must be in it.
        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (!in_array($expectedAudience, $audiences, true)) {
            return null;
        }

        // Nonce binds the token to THIS authorization request (anti-replay).
        if ($expectedNonce !== null && ($claims['nonce'] ?? null) !== $expectedNonce) {
            return null;
        }

        return $claims;
    }
}
