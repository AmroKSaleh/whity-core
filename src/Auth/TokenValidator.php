<?php

namespace Whity\Auth;

use PDO;

/**
 * Token Validator for validating JWT access and refresh tokens
 *
 * Validates JWT tokens from cookies using JwtParser and checks revocation status
 * for refresh tokens. Returns decoded token claims on success or null on failure.
 */
class TokenValidator
{
    private JwtParser $jwtParser;
    private PDO $db;

    /**
     * Constructor
     *
     * @param JwtParser $jwtParser The JWT parser instance for token validation
     * @param PDO $db Database connection for revocation checks
     */
    public function __construct(JwtParser $jwtParser, PDO $db)
    {
        $this->jwtParser = $jwtParser;
        $this->db = $db;
    }

    /**
     * Validate access token from cookie
     *
     * Retrieves the access token from the access_token cookie, validates its
     * signature, checks the token type is 'access', and verifies it has not expired.
     *
     * @return array|null The decoded token claims on success, null on any failure
     */
    public function validateAccessToken(): ?array
    {
        // Get access token from cookie
        $token = CookieManager::getAccessToken();

        // Return null if token not found
        if ($token === null) {
            return null;
        }

        // Parse and validate token
        $claims = $this->jwtParser->parse($token);

        if ($claims === null) {
            return null;
        }

        // Verify token type is 'access'
        if (($claims['type'] ?? null) !== 'access') {
            return null;
        }

        return $claims;
    }

    /**
     * Validate refresh token from cookie
     *
     * Retrieves the refresh token from the refresh_token cookie, validates its
     * signature, checks the token type is 'refresh', verifies it has not expired,
     * and checks that it hasn't been revoked.
     *
     * @return array|null The decoded token claims on success, null on any failure
     */
    public function validateRefreshToken(): ?array
    {
        // Get refresh token from cookie
        $token = CookieManager::getRefreshToken();

        // Return null if token not found
        if ($token === null) {
            return null;
        }

        // Parse and validate token
        $claims = $this->jwtParser->parse($token);

        if ($claims === null) {
            return null;
        }

        // Verify token type is 'refresh'
        if (($claims['type'] ?? null) !== 'refresh') {
            return null;
        }

        // Check revocation table
        if ($this->isTokenRevoked($claims['jti'])) {
            return null;
        }

        return $claims;
    }

    /**
     * Check if a token has been revoked
     *
     * Queries the revoked_tokens table to check if the given jti (token ID)
     * has been marked as revoked.
     *
     * @param string $jti The JWT ID to check for revocation
     * @return bool True if the token is revoked, false otherwise
     */
    private function isTokenRevoked(string $jti): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT 1 FROM revoked_tokens WHERE jti = ? LIMIT 1');
            $stmt->execute([$jti]);
            // rowCount() is unreliable for SELECTs across drivers (e.g. returns 0 on
            // SQLite), so a revoked token could slip through. fetchColumn() returns the
            // selected `1` when a row matches (truthy) or false when none does.
            return (bool) $stmt->fetchColumn();
        } catch (\Exception) {
            // If database query fails, err on the side of caution and reject the token
            return true;
        }
    }
}
