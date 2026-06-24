<?php

namespace Whity\Auth;

use PDO;
use Whity\Mcp\Auth\McpPrincipal;

/**
 * Token Validator for validating JWT access and refresh tokens
 *
 * Validates JWT tokens from cookies using JwtParser and enforces two revocation
 * controls (WC-185):
 *   1. Per-token revocation — the token's `jti` is checked against the
 *      revoked_tokens table (used on logout and password change).
 *   2. Per-user token epoch — the token's `token_epoch` claim is checked against
 *      the issuing user's CURRENT `users.token_epoch`. Bumping a user's epoch
 *      (on a password change) invalidates ALL of that user's previously-issued
 *      tokens at once, across every device.
 *
 * Both access and refresh tokens are subject to both controls. Returns decoded
 * token claims on success or null on failure.
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
     * signature, checks the token type is 'access', verifies it has not expired,
     * checks the jti has not been revoked, and verifies the token's epoch matches
     * the issuing user's current epoch (WC-185).
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

        // Per-token revocation: a logout / password change adds the access jti to
        // the revocation table, so a stolen or old access token stops validating
        // immediately rather than living until expiry (WC-185).
        if ($this->isTokenRevoked($claims['jti'])) {
            return null;
        }

        // Per-user epoch: reject a token issued under an older epoch.
        if (!$this->isTokenEpochCurrent($claims)) {
            return null;
        }

        return $claims;
    }

    /**
     * Validate refresh token from cookie
     *
     * Retrieves the refresh token from the refresh_token cookie, validates its
     * signature, checks the token type is 'refresh', verifies it has not expired,
     * checks that it hasn't been revoked, and verifies the token's epoch matches
     * the issuing user's current epoch (WC-185).
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

        // Per-user epoch: an epoch bump (password change) must invalidate refresh
        // tokens too, not only the individually-revoked jtis (WC-185).
        if (!$this->isTokenEpochCurrent($claims)) {
            return null;
        }

        return $claims;
    }

    /**
     * Validate an MCP bearer token passed from the Authorization header.
     *
     * Checks: signature + expiry (via JwtParser), type='mcp', aud='mcp',
     * jti not in revoked_tokens, jti exists in mcp_tokens (must have been
     * issued via the issuance endpoint — guards against hand-crafted tokens).
     * Epoch checking is intentionally skipped for MCP tokens; revocation is
     * explicit via DELETE /api/mcp/tokens/{jti}.
     *
     * @param string $token Raw bearer token string.
     * @return McpPrincipal|null Validated principal, or null on any failure.
     */
    public function validateMcpToken(string $token): ?McpPrincipal
    {
        $claims = $this->jwtParser->parse($token);
        if ($claims === null) {
            return null;
        }

        if (($claims['type'] ?? null) !== 'mcp') {
            return null;
        }

        if (($claims['aud'] ?? null) !== 'mcp') {
            return null;
        }

        $jti = $claims['jti'] ?? null;
        if (!is_string($jti) || $jti === '') {
            return null;
        }

        if ($this->isTokenRevoked($jti)) {
            return null;
        }

        if (!$this->isMcpTokenRegistered($jti)) {
            return null;
        }

        $userId        = $claims['user_id'] ?? null;
        $tenantId      = $claims['tenant_id'] ?? null;
        $principalKind = $claims['principal_kind'] ?? 'user';
        $scope         = $claims['scope'] ?? [];

        if (!is_int($userId) || !is_int($tenantId)) {
            return null;
        }

        if (!is_string($principalKind)) {
            return null;
        }

        if (!is_array($scope)) {
            return null;
        }

        return new McpPrincipal(
            userId: $userId,
            tenantId: $tenantId,
            principalKind: $principalKind,
            scope: $scope,
            jti: $jti,
        );
    }

    /**
     * Check if a token has been revoked
     *
     * Queries the revoked_tokens table to check if the given jti (token ID)
     * has been marked as revoked.
     *
     * revoked_tokens is the sanctioned GLOBAL (non-tenant-scoped) revocation
     * table: a jti is unique across the whole platform, so the lookup carries no
     * tenant predicate.
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

    /**
     * Check if a JTI has been registered in mcp_tokens.
     *
     * Guards against hand-crafted tokens with valid signatures — the token
     * must have been issued via the issuance endpoint to appear here.
     */
    private function isMcpTokenRegistered(string $jti): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT 1 FROM mcp_tokens WHERE jti = ? LIMIT 1');
            $stmt->execute([$jti]);
            return (bool) $stmt->fetchColumn();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Verify the token's epoch is not older than the issuing user's current epoch.
     *
     * The token's `token_epoch` claim is compared against `users.token_epoch` for
     * the token's (user_id, tenant_id). A token is rejected when its epoch is LESS
     * than the stored one (it predates a password change). A MISSING claim is
     * treated as 0, so pre-migration tokens map to the default user epoch (0).
     *
     * Tenant isolation: `users` is a tenant-owned table, so the lookup is scoped
     * to BOTH the user id AND the tenant id from the token — one tenant's epoch
     * can never gate another tenant's user (the system tenant uses id 0, which is
     * a normal value here). When the token carries no `user_id`, there is no user
     * to scope an epoch to, so this control does not apply (signature/exp/type and
     * jti-revocation still do). Fail closed on a genuine DB error or a missing
     * user row (e.g. a deleted account).
     *
     * @param array<string, mixed> $claims The decoded token claims.
     * @return bool True when the epoch is current (or not applicable), false to reject.
     */
    private function isTokenEpochCurrent(array $claims): bool
    {
        $userId = $claims['user_id'] ?? null;
        $tenantId = $claims['tenant_id'] ?? null;

        // No user/tenant to scope to: the per-user epoch control does not apply.
        if ($userId === null || $tenantId === null) {
            return true;
        }

        // Missing claim ⇒ epoch 0 (pre-migration tokens map to the default).
        $tokenEpoch = isset($claims['token_epoch']) ? (int) $claims['token_epoch'] : 0;

        try {
            // Tenant-scoped lookup on the tenant-owned users table.
            $stmt = $this->db->prepare(
                'SELECT token_epoch FROM users WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $stmt->execute([$userId, $tenantId]);
            $stored = $stmt->fetchColumn();

            // No matching user row (deleted account, or a forged/mismatched
            // tenant claim): fail closed.
            if ($stored === false) {
                return false;
            }

            // Reject tokens minted before the current epoch.
            return $tokenEpoch >= (int) $stored;
        } catch (\Exception) {
            // Fail closed on any database error.
            return false;
        }
    }
}
