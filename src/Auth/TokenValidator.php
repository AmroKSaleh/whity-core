<?php

namespace Whity\Auth;

use PDO;
use Whity\Auth\Exception\InvalidMembershipException;
use Whity\Mcp\Auth\McpPrincipal;

/**
 * Token Validator for validating JWT access and refresh tokens
 *
 * Validates JWT tokens from cookies using JwtParser and enforces two revocation
 * controls (WC-185):
 *   1. Per-token revocation — the token's `jti` is checked against the
 *      revoked_tokens table (used on logout and password change).
 *   2. Per-profile token epoch — the token's `token_epoch` claim is checked
 *      against `profiles.token_epoch`. Bumping an epoch (on password change)
 *      invalidates ALL of that profile's tokens at once across every device.
 *
 * WC-idcut-E: post-cutover — only {profile_id, active_tenant_id} claim shape
 * is accepted. Legacy {user_id, tenant_id} claims are no longer read.
 * The dual-claim split-brain guard is removed (only one tenant claim exists).
 *
 * Both access and refresh tokens are subject to both controls. Returns decoded
 * token claims on success or null on failure.
 */
class TokenValidator
{
    private JwtParser $jwtParser;
    private PDO $db;

    /**
     * Membership gate: a {profile_id, active_tenant_id} token requires an active
     * membership in its declared tenant (or system-tenant authority, id 0).
     */
    private ActiveTenantMembershipGuard $membershipGuard;

    /**
     * @param JwtParser $jwtParser The JWT parser instance for token validation
     * @param PDO $db Database connection for revocation checks
     */
    public function __construct(JwtParser $jwtParser, PDO $db)
    {
        $this->jwtParser = $jwtParser;
        $this->db = $db;
        $this->membershipGuard = new ActiveTenantMembershipGuard($db);
    }

    /**
     * Validate access token from cookie.
     *
     * @return array|null The decoded token claims on success, null on any failure
     */
    public function validateAccessToken(): ?array
    {
        $token = CookieManager::getAccessToken();
        if ($token === null) {
            return null;
        }

        $claims = $this->jwtParser->parse($token);
        if ($claims === null) {
            return null;
        }

        if (($claims['type'] ?? null) !== 'access') {
            return null;
        }

        if ($this->isTokenRevoked($claims['jti'])) {
            return null;
        }

        if (!$this->isTokenEpochCurrent($claims)) {
            return null;
        }

        if (!$this->membershipGuard->allows($claims)) {
            return null;
        }

        return $claims;
    }

    /**
     * Validate refresh token from cookie.
     *
     * @return array|null The decoded token claims on success, null on any failure
     */
    public function validateRefreshToken(): ?array
    {
        $token = CookieManager::getRefreshToken();
        if ($token === null) {
            return null;
        }

        $claims = $this->jwtParser->parse($token);
        if ($claims === null) {
            return null;
        }

        if (($claims['type'] ?? null) !== 'refresh') {
            return null;
        }

        if ($this->isTokenRevoked($claims['jti'])) {
            return null;
        }

        if (!$this->isTokenEpochCurrent($claims)) {
            return null;
        }

        if (!$this->membershipGuard->allows($claims)) {
            return null;
        }

        return $claims;
    }

    /**
     * Validate a regular session access JWT passed as a Bearer token string.
     *
     * Non-browser (token mode) equivalent of validateAccessToken() — applies
     * the same validation chain against a raw bearer string.
     *
     * @param string $token Raw bearer token string.
     * @return array<string, mixed>|null Decoded claims on success, null on any failure.
     */
    public function validateAccessTokenFromBearer(string $token): ?array
    {
        $claims = $this->jwtParser->parse($token);
        if ($claims === null) {
            return null;
        }

        if (($claims['type'] ?? null) !== 'access') {
            return null;
        }

        $jti = $claims['jti'] ?? null;
        if (!is_string($jti) || $jti === '') {
            return null;
        }

        if ($this->isTokenRevoked($jti)) {
            return null;
        }

        if (!$this->isTokenEpochCurrent($claims)) {
            return null;
        }

        if (!$this->membershipGuard->allows($claims)) {
            return null;
        }

        return $claims;
    }

    /**
     * Validate a refresh token passed as a Bearer string or body field.
     *
     * Used by handleRefresh() in token mode (WC-ddcd16ad).
     *
     * @param string $token Raw token string (not from cookie).
     * @return array<string, mixed>|null Decoded claims on success, null on any failure.
     */
    public function validateRefreshTokenFromString(string $token): ?array
    {
        $claims = $this->jwtParser->parse($token);
        if ($claims === null) {
            return null;
        }

        if (($claims['type'] ?? null) !== 'refresh') {
            return null;
        }

        $jti = $claims['jti'] ?? null;
        if (!is_string($jti) || $jti === '') {
            return null;
        }

        if ($this->isTokenRevoked($jti)) {
            return null;
        }

        if (!$this->isTokenEpochCurrent($claims)) {
            return null;
        }

        if (!$this->membershipGuard->allows($claims)) {
            return null;
        }

        return $claims;
    }

    /**
     * Validate an MCP bearer token passed from the Authorization header.
     *
     * Checks: signature + expiry (via JwtParser), type='mcp', aud='mcp',
     * jti not in revoked_tokens, jti exists in mcp_tokens.
     * Epoch checking is intentionally skipped for MCP tokens; revocation is
     * explicit via DELETE /api/mcp/tokens/{jti}.
     *
     * WC-idcut-E: profile_id/active_tenant_id only.
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

        if (!$this->membershipGuard->allows($claims)) {
            return null;
        }

        $ids = $this->principalIdsFromClaims($claims);
        if ($ids === null) {
            return null;
        }
        [$resolvedId, $tenantId] = $ids;

        $principalKind = $claims['principal_kind'] ?? 'user';
        $scope         = $claims['scope'] ?? [];

        if (!is_string($principalKind)) {
            return null;
        }

        if (!is_array($scope)) {
            return null;
        }

        /** @var string[] $scope */
        return new McpPrincipal(
            profileId: $resolvedId,
            userId: $resolvedId,
            tenantId: $tenantId,
            principalKind: $principalKind,
            scope: $scope,
            jti: $jti,
        );
    }

    /**
     * Validate a regular session access JWT passed as a Bearer token.
     *
     * Returns a McpPrincipal with principalKind='session' and full MCP scope.
     * WC-idcut-E: profile_id/active_tenant_id only; userId == profileId.
     *
     * @param string $token Raw bearer token string.
     * @return McpPrincipal|null Validated principal, or null on any failure.
     */
    public function validateSessionBearerForMcp(string $token): ?McpPrincipal
    {
        $claims = $this->jwtParser->parse($token);
        if ($claims === null) {
            return null;
        }

        if (($claims['type'] ?? null) !== 'access') {
            return null;
        }

        $jti = $claims['jti'] ?? null;
        if (!is_string($jti) || $jti === '') {
            return null;
        }

        if ($this->isTokenRevoked($jti)) {
            return null;
        }

        if (!$this->isTokenEpochCurrent($claims)) {
            return null;
        }

        if (!$this->membershipGuard->allows($claims)) {
            return null;
        }

        $ids = $this->principalIdsFromClaims($claims);
        if ($ids === null) {
            return null;
        }
        [$resolvedId, $tenantId] = $ids;

        return new McpPrincipal(
            profileId: $resolvedId,
            userId: $resolvedId,
            tenantId: $tenantId,
            principalKind: 'session',
            scope: ['tools:list', 'tools:call', 'resources:read', 'prompts:list'],
            jti: $jti,
        );
    }

    /**
     * Validate any bearer token for MCP access.
     *
     * Tries the dedicated MCP token path first (type='mcp'), then falls back to a
     * regular session access token (type='access').
     *
     * @param string $token Raw bearer token string.
     * @return McpPrincipal|null Validated principal, or null on any failure.
     */
    public function validateBearerForMcp(string $token): ?McpPrincipal
    {
        return $this->validateMcpToken($token) ?? $this->validateSessionBearerForMcp($token);
    }

    /**
     * Derive the MCP principal's [profileId, tenantId] from post-cutover claims.
     *
     * WC-idcut-E: profile_id/active_tenant_id only. Non-integer values fail closed.
     *
     * @param array<string, mixed> $claims The decoded token claims.
     * @return array{0: int, 1: int}|null [profileId, tenantId], or null on failure.
     */
    private function principalIdsFromClaims(array $claims): ?array
    {
        $profileId = $claims['profile_id'] ?? null;
        $tenantId  = $claims['active_tenant_id'] ?? null;

        if (!is_int($profileId) || !is_int($tenantId)) {
            return null;
        }

        return [$profileId, $tenantId];
    }

    /**
     * Check if a token has been revoked.
     *
     * revoked_tokens is the sanctioned GLOBAL (non-tenant-scoped) revocation
     * table: a jti is unique across the whole platform.
     *
     * @param string $jti The JWT ID to check for revocation
     * @return bool True if the token is revoked, false otherwise
     */
    private function isTokenRevoked(string $jti): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT 1 FROM revoked_tokens WHERE jti = ? LIMIT 1');
            $stmt->execute([$jti]);
            return (bool) $stmt->fetchColumn();
        } catch (\Exception) {
            return true;
        }
    }

    /**
     * Check if a JTI has been registered in mcp_tokens.
     *
     * Guards against hand-crafted tokens with valid signatures.
     */
    private function isMcpTokenRegistered(string $jti): bool
    {
        try {
            // @tenant-guard-ignore: jti is a platform-wide unique handle (like revoked_tokens.jti);
            // querying by jti alone cannot pull a different tenant's row.
            $stmt = $this->db->prepare('SELECT 1 FROM mcp_tokens WHERE jti = ? LIMIT 1');
            $stmt->execute([$jti]);
            return (bool) $stmt->fetchColumn();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Verify the token's epoch is not older than the profile's current epoch.
     *
     * WC-idcut-E: epoch is checked against profiles.token_epoch only.
     * The legacy users.token_epoch path is removed. A token with no profile_id
     * claim is exempt from epoch checking (but still subject to signature/exp/jti).
     *
     * `profiles` is a sanctioned GLOBAL identity table (ADR 0005 §1) — keyed by
     * primary key alone. Fails closed on a missing profile row or DB error.
     *
     * @param array<string, mixed> $claims The decoded token claims.
     * @return bool True when the epoch is current (or not applicable), false to reject.
     */
    private function isTokenEpochCurrent(array $claims): bool
    {
        $tokenEpoch = isset($claims['token_epoch']) ? (int) $claims['token_epoch'] : 0;
        $profileId  = $claims['profile_id'] ?? null;

        if (is_int($profileId)) {
            return $this->isProfileEpochCurrent($profileId, $tokenEpoch);
        }

        // No profile_id claim — epoch check does not apply.
        return true;
    }

    /**
     * Epoch check against profiles.token_epoch.
     *
     * @param int $profileId  The token's profile_id claim.
     * @param int $tokenEpoch The token's embedded epoch (missing claim => 0).
     * @return bool True when the epoch is current, false to reject.
     */
    private function isProfileEpochCurrent(int $profileId, int $tokenEpoch): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT token_epoch FROM profiles WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$profileId]);
            $stored = $stmt->fetchColumn();

            if ($stored === false) {
                return false;
            }

            return $tokenEpoch >= (int) $stored;
        } catch (\Exception) {
            return false;
        }
    }
}
