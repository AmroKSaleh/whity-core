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
     * Membership gate for the new {profile_id, active_tenant_id} claim pair
     * (WC-d4340daf, ADR 0005 §5). Legacy tokens pass through unchecked; a
     * new-claims token requires an active membership in its declared tenant
     * (or system-tenant authority, id 0).
     */
    private ActiveTenantMembershipGuard $membershipGuard;

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
        $this->membershipGuard = new ActiveTenantMembershipGuard($db);
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

        // WC-c35c4ce0 security follow-up (b): when BOTH legacy {tenant_id} and
        // new {active_tenant_id} claims are present, they must be equal. A
        // mismatch means McpPrincipal (reads tenant_id) and TenantContext (reads
        // active_tenant_id) would resolve different tenants — a "split-brain"
        // that could let a caller pass the membership gate for one tenant while
        // running tenant-scoped queries against another.
        if ($this->hasSplitBrainClaims($claims)) {
            return null;
        }

        // Dual-claim window (WC-d4340daf): a token carrying the new
        // {profile_id, active_tenant_id} claims must be backed by an ACTIVE
        // membership in that tenant (per-membership suspension, ADR 0005 §5).
        // Legacy tokens (no new claims) skip this — pre-migration users have
        // no membership rows yet, and their behaviour must not change.
        if (!$this->membershipGuard->allows($claims)) {
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

        // WC-c35c4ce0 security follow-up (b): split-brain invariant (same as
        // the access-token path above).
        if ($this->hasSplitBrainClaims($claims)) {
            return null;
        }

        // Dual-claim membership gate (WC-d4340daf): refresh tokens carry the
        // same claim model as access tokens, so a suspended membership stops
        // the refresh re-mint immediately (ADR 0005 §5).
        if (!$this->membershipGuard->allows($claims)) {
            return null;
        }

        return $claims;
    }

    /**
     * Validate a regular session access JWT passed as a Bearer token string.
     *
     * This is the non-browser (token mode) equivalent of validateAccessToken():
     * it applies the SAME validation chain — signature/expiry, type='access',
     * jti revocation, epoch, split-brain, membership gate — against a raw bearer
     * string rather than reading the access_token cookie.  The result is the
     * decoded claims array (identical contract to validateAccessToken()) so all
     * downstream handlers work unchanged in either auth mode.
     *
     * Precedence note (WC-ddcd16ad): callers that need cookie-OR-bearer logic
     * should call validateAccessToken() first (cookie wins), then this method
     * as a fallback when the cookie is absent.
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

        if ($this->hasSplitBrainClaims($claims)) {
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
     * Used by handleRefresh() in token mode (WC-ddcd16ad): accepts the refresh
     * token from Authorization: Bearer or a body field when X-Auth-Mode: token
     * was used at login.  Applies the SAME validation chain as validateRefreshToken()
     * (type='refresh', jti revocation, epoch, split-brain, membership gate).
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

        if ($this->hasSplitBrainClaims($claims)) {
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

        // Dual-claim membership gate (WC-d4340daf): an MCP token carrying the
        // new {profile_id, active_tenant_id} claims is membership-gated like
        // every other token; legacy-claim MCP tokens are unaffected.
        if (!$this->membershipGuard->allows($claims)) {
            return null;
        }

        $ids = $this->principalIdsFromClaims($claims);
        if ($ids === null) {
            return null;
        }
        [$resolvedId, $tenantId] = $ids;

        // Post-040 MCP tokens carry profile_id; pre-040 (or session-bearer path)
        // may carry user_id instead. Expose both so callers can read either.
        $profileId = isset($claims['profile_id']) && is_int($claims['profile_id'])
            ? $claims['profile_id']
            : $resolvedId;

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
            profileId: $profileId,
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
     * Accepts the same tokens issued by the standard login flow, allowing native
     * clients to call the MCP server without a separate token-issuance step.
     * Checks: signature + expiry (via JwtParser), type='access', jti not in
     * revoked_tokens, token epoch current (password change invalidates all sessions).
     * Returns a McpPrincipal with principalKind='session' and full MCP scope;
     * actual tool access is still gated per-call by RoleChecker.
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

        // Dual-claim membership gate (WC-d4340daf): session bearers follow the
        // same rules as the cookie path — new-claims tokens need a live
        // membership, legacy tokens keep current behaviour.
        if (!$this->membershipGuard->allows($claims)) {
            return null;
        }

        $ids = $this->principalIdsFromClaims($claims);
        if ($ids === null) {
            return null;
        }
        [$resolvedId, $tenantId] = $ids;

        // Session bearers during the dual-window carry user_id; post-E they will
        // carry profile_id. Expose both so session-bearer MCP access keeps working.
        $profileId = isset($claims['profile_id']) && is_int($claims['profile_id'])
            ? $claims['profile_id']
            : $resolvedId;

        return new McpPrincipal(
            profileId: $profileId,
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
     * regular session access token (type='access'). Returns the first successful
     * principal, or null if both fail.
     *
     * @param string $token Raw bearer token string.
     * @return McpPrincipal|null Validated principal, or null on any failure.
     */
    public function validateBearerForMcp(string $token): ?McpPrincipal
    {
        return $this->validateMcpToken($token) ?? $this->validateSessionBearerForMcp($token);
    }

    /**
     * Derive the MCP principal's (userId, tenantId) from either claim shape.
     *
     * Dual-claim window (WC-d4340daf): tokens may carry the legacy
     * {user_id, tenant_id} claims, the new {profile_id, active_tenant_id}
     * claims, or both. The legacy pair is preferred when present (it matches
     * the ids the rest of the request pipeline still resolves against); a
     * new-claims-only token (post-cutover shape) derives the principal from
     * profile_id / active_tenant_id instead, keeping McpPrincipal working for
     * both shapes. Non-integer values fail closed.
     *
     * @param array<string, mixed> $claims The decoded token claims.
     * @return array{0: int, 1: int}|null [userId, tenantId], or null when
     *   neither claim shape yields a valid integer pair.
     */
    private function principalIdsFromClaims(array $claims): ?array
    {
        $userId   = $claims['user_id'] ?? $claims['profile_id'] ?? null;
        $tenantId = $claims['tenant_id'] ?? $claims['active_tenant_id'] ?? null;

        if (!is_int($userId) || !is_int($tenantId)) {
            return null;
        }

        return [$userId, $tenantId];
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
     * Verify the token's epoch is not older than the issuing identity's current epoch.
     *
     * The token's `token_epoch` claim is compared against the stored epoch for
     * the token's identity. A token is rejected when its epoch is LESS than the
     * stored one (it predates a password change). A MISSING claim is treated as
     * 0, so pre-migration tokens map to the default epoch (0).
     *
     * DUAL-CLAIM WINDOW (WC-d4340daf) — which table anchors the epoch:
     *  - Tokens carrying the LEGACY {user_id, tenant_id} pair — including the
     *    dual-claim tokens minted today — keep checking `users.token_epoch`
     *    EXACTLY as before. This is deliberate: the password-change path
     *    (handleUpdateMe) bumps the epoch on the `users` row during the dual
     *    window, so validating dual tokens against `profiles` instead would
     *    let a session survive a password change (a revocation regression).
     *  - NEW-CLAIMS-ONLY tokens (post-cutover shape: profile_id but no
     *    user_id) are checked against `profiles.token_epoch` (ADR 0005 §5:
     *    epoch invalidation moves to the profile — one person, all tenants,
     *    all devices). Once the login rewrite moves the epoch bump to
     *    `profiles`, the users-table branch is removed with the legacy claims.
     *  - A token with NEITHER identity carries nothing to scope an epoch to,
     *    so this control does not apply (signature/exp/type and
     *    jti-revocation still do) — unchanged legacy behaviour.
     *
     * Tenant isolation: `users` is a tenant-owned table, so the lookup is scoped
     * to BOTH the user id AND the tenant id from the token — one tenant's epoch
     * can never gate another tenant's user (the system tenant uses id 0, which is
     * a normal value here). `profiles` is a sanctioned GLOBAL identity table
     * (ADR 0005 §1) keyed by its primary key alone. Fail closed on a genuine DB
     * error or a missing identity row (e.g. a deleted account).
     *
     * @param array<string, mixed> $claims The decoded token claims.
     * @return bool True when the epoch is current (or not applicable), false to reject.
     */
    private function isTokenEpochCurrent(array $claims): bool
    {
        $userId   = $claims['user_id'] ?? null;
        $tenantId = $claims['tenant_id'] ?? null;

        // Missing claim ⇒ epoch 0 (pre-migration tokens map to the default).
        $tokenEpoch = isset($claims['token_epoch']) ? (int) $claims['token_epoch'] : 0;

        // No legacy user/tenant to scope to: a NEW-CLAIMS-ONLY token
        // (post-cutover shape) is epoch-checked against profiles.token_epoch;
        // a token with neither identity is exempt (unchanged behaviour).
        if ($userId === null || $tenantId === null) {
            $profileId = $claims['profile_id'] ?? null;
            if (is_int($profileId)) {
                return $this->isProfileEpochCurrent($profileId, $tokenEpoch);
            }

            return true;
        }

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

    /**
     * Epoch check for new-claims-only tokens against profiles.token_epoch.
     *
     * `profiles` is a sanctioned GLOBAL identity table (ADR 0005 §1) — it has
     * no tenant_id column, so the lookup is keyed by primary key alone. Fails
     * closed on a missing profile row (deleted identity) or any DB error.
     *
     * @param int $profileId  The token's profile_id claim.
     * @param int $tokenEpoch The token's embedded epoch (missing claim ⇒ 0).
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

    /**
     * Detect the split-brain invariant violation (WC-c35c4ce0, security follow-up b).
     *
     * During the dual-claim window tokens carry BOTH legacy {tenant_id} and new
     * {active_tenant_id} claims.  McpPrincipal reads `tenant_id` while
     * TenantContext reads `active_tenant_id`; if the two differ, a caller could
     * pass the ActiveTenantMembershipGuard for tenant A while executing all
     * tenant-scoped queries against tenant B.
     *
     * The invariant: when BOTH integer-valued claims are present, they MUST be
     * equal. A mismatch is rejected as an invalid token.
     *
     * Only triggers when both claims are present and integer-valued.  Tokens
     * with only legacy claims (no active_tenant_id) or only new claims (no
     * tenant_id) are unaffected — the invariant only applies in the overlap.
     *
     * @param array<string, mixed> $claims Decoded token claims.
     * @return bool True when the invariant is violated (token must be rejected).
     */
    private function hasSplitBrainClaims(array $claims): bool
    {
        $tenantId       = $claims['tenant_id'] ?? null;
        $activeTenantId = $claims['active_tenant_id'] ?? null;

        // Invariant only applies when BOTH claims are present and integer-typed.
        if (!is_int($tenantId) || !is_int($activeTenantId)) {
            return false;
        }

        return $tenantId !== $activeTenantId;
    }
}
