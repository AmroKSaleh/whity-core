<?php

namespace Whity\Auth;

/**
 * JWT Parser for creating and validating JWT tokens with HS256 signature
 *
 * Handles creation of JWT tokens with HMAC-SHA256 signature and parsing/validation
 * of existing tokens. Verifies signature integrity and token expiration.
 */
class JwtParser
{
    private string $secret;

    /**
     * Constructor
     *
     * @param string $secret The secret key used for signing tokens
     */
    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    /**
     * Parse and validate a JWT token
     *
     * Splits the token into header, payload, and signature components.
     * Verifies the signature using HMAC-SHA256 and checks token expiration.
     * Validates that jti and type fields are present in the payload.
     *
     * @param string $token The JWT token to parse
     * @return array|null The decoded payload as an array, or null if invalid/expired/missing required fields
     */
    public function parse(string $token): ?array
    {
        // Split token by '.'
        $parts = explode('.', $token);

        // Token must have exactly 3 parts
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // Verify signature
        $expectedSignature = $this->sign($headerB64 . '.' . $payloadB64);
        if (!hash_equals($expectedSignature, $signatureB64)) {
            return null;
        }

        // Decode payload
        $payloadJson = $this->base64Decode($payloadB64);
        if ($payloadJson === false) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if ($payload === null) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        // Validate required fields: jti and type
        if (!isset($payload['jti']) || !isset($payload['type'])) {
            return null;
        }

        return $payload;
    }

    /**
     * Create a new JWT token
     *
     * Generates a JWT token with HS256 signature, automatically adding
     * an expiration timestamp and unique jti (token ID) to the payload.
     *
     * @param array $payload The payload data to include in the token
     * @param int $expiresIn Token expiration time in seconds (default: 3600)
     * @param string $type Token type: 'access' (15 min) or 'refresh' (7 days)
     * @return string The complete JWT token
     */
    public function create(array $payload, int $expiresIn = 3600, string $type = 'access'): string
    {
        // Generate unique jti: 16 random bytes converted to 32-char hex string
        $payload['jti'] = bin2hex(random_bytes(16));

        // Add token type
        $payload['type'] = $type;

        // Add expiration claim
        $payload['exp'] = time() + $expiresIn;

        // Create header
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT'
        ];

        // Encode header and payload
        $headerB64 = $this->base64Encode(json_encode($header));
        $payloadB64 = $this->base64Encode(json_encode($payload));

        // Create signature
        $signature = $this->sign($headerB64 . '.' . $payloadB64);

        // Return complete token
        return $headerB64 . '.' . $payloadB64 . '.' . $signature;
    }

    /**
     * Sign a message using HMAC-SHA256 and encode it in URL-safe base64
     *
     * @param string $message The message to sign
     * @return string The base64-encoded signature
     */
    private function sign(string $message): string
    {
        $signature = hash_hmac('sha256', $message, $this->secret, true);
        return $this->base64Encode($signature);
    }

    /**
     * Encode data using URL-safe base64
     *
     * Replaces +, /, and = characters with URL-safe equivalents.
     *
     * @param string $data The data to encode
     * @return string The base64-encoded string (URL-safe)
     */
    private function base64Encode(string $data): string
    {
        $encoded = base64_encode($data);
        // Make URL-safe: replace +, /, and remove padding
        return strtr($encoded, '+/', '-_');
    }

    /**
     * Decode URL-safe base64 data
     *
     * Reverses the URL-safe transformations and decodes base64.
     *
     * @param string $data The base64-encoded string (URL-safe)
     * @return string|false The decoded data, or false on failure
     */
    private function base64Decode(string $data): string|false
    {
        // Reverse URL-safe transformations
        $data = strtr($data, '-_', '+/');
        // Add padding if needed
        $data = str_pad($data, strlen($data) % 4, '=', STR_PAD_RIGHT);
        return base64_decode($data, true);
    }
}
