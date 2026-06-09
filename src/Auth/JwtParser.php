<?php

declare(strict_types=1);

namespace Whity\Auth;

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
}
