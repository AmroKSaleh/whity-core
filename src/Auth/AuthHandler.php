<?php

namespace Whity\Auth;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\PasswordPolicy;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Http\JsonBody;
use PDO;
use PDOStatement;

/**
 * Simple adapter to make PDO compatible with Database interface
 * Used internally for BackupCodesService instantiation
 */
class DatabaseQueryWrapper
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}

/**
 * Authentication handler for login and token endpoints
 *
 * Handles login, token refresh, logout, and session validation endpoints.
 * Uses HTTP-only cookies for token storage and implements token revocation
 * for secure logout.
 */
class AuthHandler
{
    private PDO $db;
    private JwtParser $jwtParser;
    private TokenValidator $tokenValidator;
    private ?TotpService $totpService = null;
    private ?BackupCodesService $backupCodesService = null;
    private ?object $databaseWrapper = null;

    /**
     * PSR-3 logger for structured audit records (e.g. self-service profile
     * updates). Defaults to a {@see NullLogger} so tests stay output-clean;
     * production wires the application logger.
     */
    private LoggerInterface $logger;

    /**
     * Optional audit-trail writer (WC-34). When set, login success/failure and
     * 2FA-login events are recorded to the security audit log. Null in contexts
     * (most tests) that do not exercise auditing.
     */
    private ?AuditLogger $auditLogger;

    /**
     * Optional brute-force throttle (WC-0abcc29f). When set, failed login
     * attempts are counted per-user and per-IP; exceeding either threshold
     * returns 429. Null in tests that do not exercise throttling.
     */
    private ?LoginThrottleService $loginThrottle;

    /**
     * Constructor
     *
     * @param PDO $db Database connection
     * @param JwtParser $jwtParser JWT parser for token creation
     * @param TokenValidator|null $tokenValidator Token validator for token validation (optional)
     * @param object|null $databaseWrapper Optional Database wrapper for BackupCodesService (for testing)
     * @param TotpService|null $totpService Shared TOTP service for login-path 2FA validation.
     *     Inject the SAME instance used by the setup/confirm path so the secret-encryption key is
     *     identical end-to-end (see WC-95). When omitted, the key is resolved from the single shared
     *     accessor TotpService::resolveEncryptionKey(), which cannot diverge from the setup path.
     * @param LoggerInterface|null $logger Optional PSR-3 logger for structured audit records
     *     (self-service profile updates, WC-64). Defaults to a NullLogger when omitted.
     * @param AuditLogger|null $auditLogger Optional security audit-trail writer (WC-34). When
     *     omitted, login/2FA events are not audited (keeps existing tests untouched).
     * @param LoginThrottleService|null $loginThrottle Optional brute-force throttle (WC-0abcc29f).
     *     When omitted, throttling is disabled (keeps existing tests untouched).
     */
    public function __construct(
        PDO $db,
        JwtParser $jwtParser,
        ?TokenValidator $tokenValidator = null,
        ?object $databaseWrapper = null,
        ?TotpService $totpService = null,
        ?LoggerInterface $logger = null,
        ?AuditLogger $auditLogger = null,
        ?LoginThrottleService $loginThrottle = null
    ) {
        $this->db = $db;
        $this->jwtParser = $jwtParser;
        $this->tokenValidator = $tokenValidator ?? new TokenValidator($jwtParser, $db);
        $this->databaseWrapper = $databaseWrapper;
        $this->totpService = $totpService;
        $this->logger = $logger ?? new NullLogger();
        $this->auditLogger = $auditLogger;
        $this->loginThrottle = $loginThrottle;
    }

    /**
     * Record a security audit entry when an audit logger is configured.
     *
     * The auth path knows the tenant/actor before the request-scoped context is
     * populated (it runs as a public route), so it passes them explicitly. The
     * client IP is derived from the request's forwarding headers. No credential
     * material is ever included in the metadata.
     *
     * @param string               $action   The audit action key.
     * @param Request               $request  The incoming request (for IP derivation).
     * @param int|null              $tenantId The tenant the action belongs to.
     * @param int|null              $actorId  The acting user id, if known.
     * @param array<string, mixed>  $metadata Action-specific, secret-free metadata.
     * @return void
     */
    private function audit(string $action, Request $request, ?int $tenantId, ?int $actorId, array $metadata = []): void
    {
        if ($this->auditLogger === null) {
            return;
        }

        $this->auditLogger->record($action, [
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorId,
            'target_type' => 'user',
            'target_id' => $actorId,
            'metadata' => $metadata,
            'ip_address' => $this->clientIp($request),
        ]);
    }

    /**
     * Resolve the NEW identity claims {profile_id, active_tenant_id} for token
     * issuance during the dual-claim window (WC-d4340daf, ADR 0005 §5).
     *
     * DUAL-CLAIM WINDOW & REMOVAL PLAN (identity flow, ADR 0005):
     *  - NOW (WC-d4340daf): every minted access/refresh token carries the
     *    legacy {user_id, tenant_id, email, role} claims PLUS — when the login
     *    identity resolves to a migrated profile — the new
     *    {profile_id, active_tenant_id} claims. Validators read the new claims
     *    first and fall back to the legacy ones, so both token shapes coexist.
     *  - NEXT (users→profiles data migration): backfills profiles /
     *    profile_emails / memberships for every existing user, after which all
     *    newly-minted tokens carry both claim sets.
     *  - THEN (login/auth rewrite, ADR 0005 §6, task #103): login resolves the
     *    profile FIRST (globally-unique email) and the legacy claims stop being
     *    read anywhere server-side.
     *  - FINALLY: after every pre-rewrite refresh token has expired (7-day
     *    TTL) plus one release of soak, the legacy claims are dropped from
     *    issuance and the fallback read paths (TokenValidator / TenantContext /
     *    MCP principal derivation / web auth-context) are deleted.
     *
     * The new claims are added ONLY when the email maps to a profile
     * (profile_emails, globally unique) AND that profile holds an ACTIVE
     * membership in the token's tenant (or the tenant is the system tenant 0,
     * which needs no membership by the id-0 convention). Otherwise the token
     * stays legacy-only: baking new claims into a token whose membership the
     * validator's gate would refuse (pre-migration users have no membership
     * rows yet — the data migration is the NEXT task) would brick the session.
     *
     * @param string $email    The authenticated user's email.
     * @param int    $tenantId The tenant the token is being minted for.
     * @return array{}|array{profile_id: int, active_tenant_id: int}
     */
    private function identityClaims(string $email, int $tenantId): array
    {
        try {
            // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2); the email is globally unique by schema
            $stmt = $this->db->prepare('SELECT profile_id FROM profile_emails WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $profileId = $stmt->fetchColumn();

            if ($profileId === false) {
                // Pre-migration user: no profile yet — mint legacy-only claims.
                return [];
            }

            $profileId = (int) $profileId;

            // System tenant (id 0) carries cross-tenant authority and needs no
            // membership row; every other tenant requires an ACTIVE membership.
            if ($tenantId !== 0) {
                $membershipStmt = $this->db->prepare(
                    "SELECT 1 FROM memberships
                     WHERE profile_id = ? AND tenant_id = ? AND status = 'active'
                     LIMIT 1"
                );
                $membershipStmt->execute([$profileId, $tenantId]);
                if (!$membershipStmt->fetchColumn()) {
                    return [];
                }
            }

            return ['profile_id' => $profileId, 'active_tenant_id' => $tenantId];
        } catch (\Exception) {
            // Fail open to LEGACY-ONLY claims (never to new claims): a broken
            // identity lookup must not block logins during the dual window.
            return [];
        }
    }

    /**
     * Carry the new identity claims from an already-validated token's claims.
     *
     * Used on re-mint paths (refresh, self-service cookie re-issue) so the new
     * claim pair survives re-minting without a fresh identity lookup. Returns
     * the pair only when BOTH claims are present and integer-valued (partial
     * sets are never issued and must not be propagated).
     *
     * @param array<string, mixed> $claims Validated source-token claims.
     * @return array{}|array{profile_id: int, active_tenant_id: int}
     */
    private function carriedIdentityClaims(array $claims): array
    {
        $profileId = $claims['profile_id'] ?? null;
        $activeTenantId = $claims['active_tenant_id'] ?? null;

        if (is_int($profileId) && is_int($activeTenantId)) {
            return ['profile_id' => $profileId, 'active_tenant_id' => $activeTenantId];
        }

        return [];
    }

    /**
     * Best-effort client IP extraction from forwarding headers.
     *
     * Reads the trusted, proxy-set client-IP header via {@see ClientIp} — raw
     * client-supplied `X-Forwarded-For` / `X-Real-IP` are NOT trusted (WC-b19ff21a).
     * Returns null when absent (e.g. CLI / no trusted proxy). Never throws.
     *
     * @param Request $request The incoming request.
     * @return string|null The client IP, or null.
     */
    private function clientIp(Request $request): ?string
    {
        return ClientIp::fromRequest($request);
    }

    /**
     * Handle login request (POST /api/login)
     *
     * Authenticates via the profile model (ADR 0005 §6, fixes #181):
     *
     * 1. Look up a VERIFIED profile_email by email — globally unique by schema
     *    (UNIQUE(email) on profile_emails), which structurally eliminates the
     *    cross-tenant login ambiguity described in issue #181.
     * 2. Verify the password_hash on the profiles row (credentials live on the
     *    profile, not duplicated per-tenant).
     * 3. (If 2FA enabled on the profile) issue a short-lived temp token and
     *    return 202 — the 2FA challenge completes login.
     * 4. Resolve active memberships (ADR 0005 §6 step 4):
     *      - Zero active memberships → 403 "no active membership"
     *      - All memberships invited only → 403 "account pending"
     *      - Exactly one active → auto-selected as active_tenant_id
     *      - Multiple active → deterministic default: lowest tenant_id
     *        (the tenant-switcher, a later step, lets the user change it)
     * 5. Issue the new-claims JWT { profile_id, active_tenant_id } plus legacy
     *    { user_id, tenant_id } during the dual-claim window (WC-d4340daf).
     *
     * BACKWARD COMPAT: the legacy users row is still consulted for role lookup
     * and to emit the legacy JWT claims during the dual-claim window. The old
     * tenant-ambiguous SELECT-by-email-on-users is NEVER executed.
     *
     * @param Request $request HTTP request with email and password in JSON body
     * @return Response HTTP response with user data (200), 2FA challenge (202),
     *                  membership error (403), or credential error (401)
     */
    public function handle(Request $request, array $params = []): Response
    {
        // Parse request body (envelope validated upstream, WC-189).
        $body = JsonBody::parsed($request);

        // Validate request has email and password
        if (!isset($body['email']) || !isset($body['password'])) {
            return Response::error('Email and password are required', 401);
        }

        $email    = $body['email'];
        $password = $body['password'];
        $ip       = $this->clientIp($request);

        // WC-0abcc29f: check IP throttle before touching the DB — a heavily
        // throttled IP must not learn whether an email is registered.
        if ($this->loginThrottle !== null && $this->loginThrottle->isThrottled(null, $ip)) {
            return Response::error('Too many attempts', 429);
        }

        // ── Step 1: resolve profile from the globally-unique verified email ────
        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2); UNIQUE(email) makes this unambiguous across all tenants — this is the structural fix for #181
        $peStmt = $this->db->prepare(
            'SELECT pe.profile_id, pe.verified
             FROM profile_emails pe
             WHERE pe.email = ?
             LIMIT 1'
        );
        $peStmt->execute([$email]);
        $profileEmailRow = $peStmt->fetch(PDO::FETCH_ASSOC);

        if ($profileEmailRow === false) {
            // No profile_email row: email is not registered in the profile model.
            $this->audit('auth.login.failure', $request, null, null, [
                'email'  => is_string($email) ? $email : null,
                'reason' => 'profile_not_found',
            ]);
            $this->loginThrottle?->recordFailure(null, $ip);
            return Response::error('Invalid credentials', 401);
        }

        // Unverified emails must never authenticate (ADR 0005 §2).
        if (!(bool) $profileEmailRow['verified']) {
            $this->audit('auth.login.failure', $request, null, null, [
                'email'  => is_string($email) ? $email : null,
                'reason' => 'email_not_verified',
            ]);
            $this->loginThrottle?->recordFailure(null, $ip);
            return Response::error('Email address is not verified', 403);
        }

        $profileId = (int) $profileEmailRow['profile_id'];

        // WC-0abcc29f: throttle check keyed on the profile id.
        if ($this->loginThrottle !== null && $this->loginThrottle->isThrottled($profileId, null)) {
            return Response::error('Too many attempts', 429);
        }

        // ── Step 2: load the profile and verify credentials ──────────────────
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1), not tenant-owned
        $profStmt = $this->db->prepare(
            'SELECT id, password_hash, two_factor_enabled, two_factor_secret,
                    two_factor_backup_codes_version, token_epoch
             FROM profiles
             WHERE id = ?
             LIMIT 1'
        );
        $profStmt->execute([$profileId]);
        $profile = $profStmt->fetch(PDO::FETCH_ASSOC);

        if ($profile === false) {
            // Should not happen: profile_email FK should keep the profile alive.
            $this->audit('auth.login.failure', $request, null, null, [
                'email'  => is_string($email) ? $email : null,
                'reason' => 'profile_row_missing',
            ]);
            $this->loginThrottle?->recordFailure($profileId, $ip);
            return Response::error('Invalid credentials', 401);
        }

        if (!password_verify((string) $password, (string) $profile['password_hash'])) {
            $this->audit('auth.login.failure', $request, null, $profileId, [
                'email'  => is_string($email) ? $email : null,
                'reason' => 'invalid_password',
            ]);
            $this->loginThrottle?->recordFailure($profileId, $ip);
            return Response::error('Invalid credentials', 401);
        }

        // ── Step 3: 2FA challenge (secret lives on the profile) ──────────────
        if (!empty($profile['two_factor_enabled'])) {
            // First factor passed; second factor still required.
            // The temp token carries the profile_id and the pre-selected
            // active_tenant_id so completeTwoFaLogin() can skip the membership
            // resolution a second time.  The active_tenant_id is resolved below
            // and stored in the temp token's claims.

            // ── Step 4 (inline for 2FA): resolve memberships ─────────────────
            $activeTenantId2fa = $this->resolveActiveTenantId($profileId);
            if ($activeTenantId2fa === null) {
                // Zero active memberships.
                return Response::error('No active membership', 403);
            }

            // Look up the legacy users row to carry legacy claims in temp token.
            $legacyRow2fa = $this->fetchLegacyUserRow((string) $email, $activeTenantId2fa);

            $tempClaims = [
                'profile_id'       => $profileId,
                'active_tenant_id' => $activeTenantId2fa,
                'email'            => is_string($email) ? $email : '',
            ];
            if ($legacyRow2fa !== null) {
                $tempClaims['user_id']   = (int) $legacyRow2fa['id'];
                $tempClaims['tenant_id'] = $activeTenantId2fa;
            }

            $this->audit('auth.login.2fa_required', $request, $activeTenantId2fa, $profileId);

            // Short-lived 'temp' token (5 min) — not epoch-checked (wrong type).
            CookieManager::setTempToken(
                $this->jwtParser->create($tempClaims, 300, 'temp'),
                300
            );

            return Response::json(['requires_2fa' => true], 202);
        }

        // ── Step 4: resolve active memberships and pick active_tenant_id ─────
        $activeTenantId = $this->resolveActiveTenantId($profileId);
        if ($activeTenantId === null) {
            // Zero active memberships (or all suspended/invited).
            return Response::error('No active membership', 403);
        }

        // ── Step 5: look up the legacy users row (dual-claim window) ─────────
        // The users row in the resolved active tenant carries role_id and the
        // legacy epoch. When no users row exists (profile-only post-cutover) we
        // fall back gracefully to profile epoch and omit legacy claims.
        $legacyRow = $this->fetchLegacyUserRow((string) $email, $activeTenantId);

        // Role name: prefer the legacy users row; fall back to a placeholder
        // (the role is resolved from memberships post-cutover, but that rework
        // is a later step — do not expand scope here).
        $roleName  = '';
        $tokenEpoch = (int) ($profile['token_epoch'] ?? 0);
        if ($legacyRow !== null) {
            // @tenant-guard-ignore: role-name lookup by globally-unique role id (SERIAL PK)
            $roleStmt = $this->db->prepare('SELECT name FROM roles WHERE id = ?');
            $roleStmt->execute([$legacyRow['role_id']]);
            $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
            if ($roleRow !== false) {
                $roleName = (string) $roleRow['name'];
            }
            // During the dual window, epoch is still stored on users (see
            // TokenValidator::isTokenEpochCurrent — dual tokens are validated
            // against users.token_epoch).
            $tokenEpoch = (int) ($legacyRow['token_epoch'] ?? 0);
        }

        // ── Issue tokens ─────────────────────────────────────────────────────
        // Always include the new identity claims (profile_id / active_tenant_id).
        // During the dual-claim window also include legacy claims when a users
        // row exists, so existing validators/sessions are unaffected.
        $newClaims = [
            'profile_id'       => $profileId,
            'active_tenant_id' => $activeTenantId,
        ];

        $baseClaims = [
            'email'       => is_string($email) ? $email : '',
            'role'        => $roleName,
            'token_epoch' => $tokenEpoch,
        ];
        if ($legacyRow !== null) {
            $baseClaims['user_id']   = (int) $legacyRow['id'];
            $baseClaims['tenant_id'] = $activeTenantId;
        }

        // Merge: new claims first so they are never shadowed by legacy keys.
        $allClaims = $newClaims + $baseClaims;

        $accessToken  = $this->jwtParser->create($allClaims, 900, 'access');   // 15 min
        $refreshToken = $this->jwtParser->create($allClaims, 604800, 'refresh'); // 7 days

        CookieManager::setAccessToken($accessToken, 900);
        CookieManager::setRefreshToken($refreshToken, 604800);

        // WC-0abcc29f: successful login clears per-profile failure counter.
        $this->loginThrottle?->clearUser($profileId);

        $this->audit('auth.login.success', $request, $activeTenantId, $profileId);

        $userId = $legacyRow !== null ? (int) $legacyRow['id'] : $profileId;

        return Response::json([
            'user' => [
                'id'        => $userId,
                'email'     => is_string($email) ? $email : '',
                'role'      => $roleName,
                'tenant_id' => $activeTenantId,
            ]
        ], 200);
    }

    /**
     * Resolve the active_tenant_id for a profile by its memberships (ADR 0005 §6).
     *
     * Algorithm:
     *  - Filter memberships to status = 'active' only.
     *  - Zero active rows → return null (caller must refuse login).
     *  - Exactly one active row → return its tenant_id.
     *  - Multiple active rows → return the LOWEST tenant_id as a deterministic
     *    default (the tenant-switcher, a later step, lets the user change it).
     *    Rationale: lowest id is stable, reproducible across machines, and
     *    requires no extra configuration — consistent with ADR 0005 §6.
     *
     * @tenant-guard-ignore: login flow — enumerates all tenant memberships for one profile (ADR 0005 §6)
     *
     * @param int $profileId The profile whose memberships to query.
     * @return int|null The selected active_tenant_id, or null when none.
     */
    private function resolveActiveTenantId(int $profileId): ?int
    {
        try {
            // @tenant-guard-ignore: login flow — enumerates all tenant memberships for one profile (ADR 0005 §6)
            $stmt = $this->db->prepare(
                "SELECT tenant_id FROM memberships
                 WHERE profile_id = ? AND status = 'active'
                 ORDER BY tenant_id ASC
                 LIMIT 2"
            );
            $stmt->execute([$profileId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                return null;
            }

            // Exactly one or multiple: the first row (lowest tenant_id) wins.
            return (int) $rows[0]['tenant_id'];
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Fetch the legacy users row for (email, tenant_id) during the dual window.
     *
     * Returns null when the users row no longer exists (post-cutover scenario
     * where the users table has been pruned). Callers must handle null gracefully.
     *
     * @return array<string, mixed>|null
     */
    private function fetchLegacyUserRow(string $email, int $tenantId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, email, password, role_id, tenant_id, token_epoch
                 FROM users
                 WHERE email = ? AND tenant_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$email, $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Handle GET /api/me - Get current user session
     *
     * Returns the current authenticated user's data by validating the
     * access token from cookies.
     *
     * @param Request $request HTTP request
     * @return Response User data on success (200) or 401 on auth failure
     */
    public function handleMe(Request $request, array $params = []): Response
    {
        // Validate access token
        $claims = $this->tokenValidator->validateAccessToken();

        if ($claims === null) {
            return Response::error('Unauthorized', 401);
        }

        // Return user data from token claims
        return Response::json([
            'user' => [
                'id' => $claims['user_id'],
                'email' => $claims['email'],
                'role' => $claims['role'],
                'tenant_id' => $claims['tenant_id'],
            ]
        ], 200);
    }

    // MIN_PASSWORD_LENGTH is now the single PasswordPolicy::MIN_LENGTH constant.

    /**
     * Handle PATCH /api/me - Self-service profile update (WC-64).
     *
     * Updates the CURRENTLY AUTHENTICATED user only. The acting user is resolved
     * exclusively from the validated access-token claims (never from the request
     * body), so this endpoint can never edit an arbitrary id and needs no
     * admin/RBAC permission beyond being authenticated.
     *
     * Tenant scoping: `/api/me` is a public route in
     * {@see \Whity\Http\Middleware\EnforceTenantIsolation} (it answers from the
     * token alone and TenantContext is intentionally NOT populated here, exactly
     * like {@see self::handleMe()}). The tenant boundary is therefore enforced
     * directly from the JWT's `tenant_id` claim: every read/uniqueness/write query
     * is scoped to `(id, tenant_id)` from the token, so the update can never reach
     * across tenants.
     *
     * Editable fields:
     *  - `email`    — validated for RFC format and for uniqueness WITHIN the
     *                 caller's tenant (the schema enforces UNIQUE(tenant_id, email)).
     *  - `password` — requires and verifies the CURRENT password before setting a
     *                 new bcrypt hash; the new password must satisfy
     *                 {@see \Whity\Core\PasswordPolicy::MIN_LENGTH}.
     *
     * `current_password` is required whenever a change is requested. The display
     * name is derived from the email local-part (there is no `users.name` column),
     * so it is read-only and any `name` in the body is ignored.
     *
     * On a successful change the access and refresh cookies are re-issued so the
     * (possibly new) email is reflected immediately by a subsequent `GET /api/me`,
     * which reads from token claims. The updated user is returned via the public
     * shape (id/email/role) and the bcrypt hash is never exposed. A structured
     * audit record carrying `tenant_id` and the acting `user_id` is logged.
     *
     * @param Request              $request HTTP request with optional `email`,
     *                                      `password` and required `current_password`.
     * @param array<string, mixed> $params  Unused route params.
     * @return Response Updated user (200) or an error (400/401/409).
     */
    public function handleUpdateMe(Request $request, array $params = []): Response
    {
        // Self-only: the acting user comes from the validated token, never the body.
        $claims = $this->tokenValidator->validateAccessToken();
        if ($claims === null) {
            return Response::error('Unauthorized', 401);
        }

        $userId = $claims['user_id'] ?? null;
        $tenantId = $claims['tenant_id'] ?? null;
        if ($userId === null || $tenantId === null) {
            return Response::error('Unauthorized', 401);
        }

        $body = JsonBody::parsed($request);

        $emailProvided = array_key_exists('email', $body) && is_string($body['email']);
        $passwordProvided = isset($body['password']) && is_string($body['password']) && $body['password'] !== '';

        // Nothing genuinely editable was supplied.
        if (!$emailProvided && !$passwordProvided) {
            return Response::error('No changes provided', 400);
        }

        // Load the current user, strictly scoped to the token's tenant. A missing
        // row (e.g. deleted account) is reported as 401 so no tenant/account
        // existence is leaked on this self-service path.
        $stmt = $this->db->prepare(
            'SELECT id, email, password, role_id, tenant_id FROM users WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([$userId, $tenantId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return Response::error('Unauthorized', 401);
        }

        // The current password must be supplied and verified for ANY change.
        $currentPassword = isset($body['current_password']) && is_string($body['current_password'])
            ? $body['current_password']
            : '';
        if ($currentPassword === '' || !password_verify($currentPassword, (string) $user['password'])) {
            return Response::error('Current password is incorrect', 401);
        }

        $updates = [];
        $updateParams = [];
        $newEmail = (string) $user['email'];

        if ($emailProvided) {
            $email = trim((string) $body['email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return Response::error('Invalid email format', 400);
            }

            if ($email !== (string) $user['email']) {
                // Uniqueness is scoped to the caller's own tenant (matches the
                // UNIQUE(tenant_id, email) constraint).
                $checkStmt = $this->db->prepare(
                    'SELECT id FROM users WHERE email = ? AND tenant_id = ? AND id != ?'
                );
                $checkStmt->execute([$email, $tenantId, $userId]);
                if ($checkStmt->fetch()) {
                    return Response::error('Email already exists for this tenant', 409);
                }

                $updates[] = 'email = ?';
                $updateParams[] = $email;
                $newEmail = $email;
            }
        }

        $passwordChanged = false;
        if ($passwordProvided) {
            $newPassword = (string) $body['password'];
            try {
                PasswordPolicy::validate($newPassword);
            } catch (\InvalidArgumentException $e) {
                return Response::error($e->getMessage(), 400);
            }

            $updates[] = 'password = ?';
            $updateParams[] = password_hash($newPassword, PASSWORD_BCRYPT);
            $passwordChanged = true;

            // A password change bumps the user's token epoch so EVERY token issued
            // before now — this session and any other device — is invalidated by
            // the epoch check in TokenValidator (WC-185). Only bump on an actual
            // password change; an email-only change must not invalidate sessions.
            $updates[] = 'token_epoch = token_epoch + 1';
        }

        // A request that only re-sends the same email (no real change) is a no-op:
        // return the current record without touching the row.
        if ($updates === []) {
            return Response::json(['user' => $this->shapeSelf($user)], 200);
        }

        $updateParams[] = $userId;
        $updateParams[] = $tenantId;
        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ? AND tenant_id = ?';
        $this->db->prepare($sql)->execute($updateParams);

        if ($passwordChanged) {
            // Belt-and-suspenders alongside the epoch bump: explicitly revoke the
            // caller's CURRENT access and refresh jtis so they are rejected even
            // before the epoch check (and regardless of it). Net effect: all of
            // this user's existing tokens are dead.
            $this->revokeCurrentSessionTokens();
        }

        // Re-issue auth cookies so a subsequent GET /api/me (which reads token
        // claims) reflects the new email immediately AND carries the post-change
        // epoch (so the freshly issued tokens are not themselves invalidated by
        // the bump). The role is unchanged.
        $role = isset($claims['role']) ? (string) $claims['role'] : '';
        $currentEpoch = $this->currentTokenEpoch((int) $userId, (int) $tenantId);
        // Dual-claim window (WC-d4340daf): carry the new identity claims from
        // the validated token (the profile identity is unchanged by an email/
        // password edit) so the re-issued cookies keep the same claim model.
        $this->reissueAuthCookies(
            (int) $userId,
            (int) $tenantId,
            $newEmail,
            $role,
            $currentEpoch,
            $this->carriedIdentityClaims($claims)
        );

        $this->logProfileUpdate((int) $tenantId, (int) $userId, $emailProvided, $passwordChanged);

        $user['email'] = $newEmail;

        return Response::json(['user' => $this->shapeSelf($user)], 200);
    }

    /**
     * Shape a raw users row into the public self profile contract.
     *
     * Returns only id/email/role (resolved by name) and never the password hash,
     * mirroring the {@see self::handleMe()} response shape.
     *
     * @param array<string, mixed> $user Raw users row (must include id, email, role_id).
     * @return array{id: int, email: string, role: string}
     */
    private function shapeSelf(array $user): array
    {
        $roleName = '';
        if (isset($user['role_id'])) {
            // @tenant-guard-ignore: role-name lookup by globally-unique role id (SERIAL PK); the role id was just read from the authenticated user row
            $roleStmt = $this->db->prepare('SELECT name FROM roles WHERE id = ?');
            $roleStmt->execute([$user['role_id']]);
            $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($roleRow) && isset($roleRow['name'])) {
                $roleName = (string) $roleRow['name'];
            }
        }

        return [
            'id' => (int) ($user['id'] ?? 0),
            'email' => (string) ($user['email'] ?? ''),
            'role' => $roleName,
        ];
    }

    /**
     * Re-issue the access and refresh token cookies for the acting user.
     *
     * Used after a self-service profile change so the new email propagates to the
     * token-derived {@see self::handleMe()} response without requiring a re-login.
     *
     * @param int    $userId     The acting user id.
     * @param int    $tenantId   The acting tenant id.
     * @param string $email      The (possibly updated) email to embed in the tokens.
     * @param string $role       The user's role name (unchanged by this endpoint).
     * @param int    $tokenEpoch The user's CURRENT token epoch, embedded so the
     *                           re-issued tokens survive a same-request epoch bump (WC-185).
     * @param array{}|array{profile_id: int, active_tenant_id: int} $identityClaims
     *                           New identity claims carried from the validated
     *                           source token (WC-d4340daf dual-claim window).
     * @return void
     */
    private function reissueAuthCookies(
        int $userId,
        int $tenantId,
        string $email,
        string $role,
        int $tokenEpoch,
        array $identityClaims = []
    ): void {
        $accessToken = $this->jwtParser->create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'email' => $email,
            'role' => $role,
            'token_epoch' => $tokenEpoch,
        ] + $identityClaims, 900, 'access');

        $refreshToken = $this->jwtParser->create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'email' => $email,
            'role' => $role,
            'token_epoch' => $tokenEpoch,
        ] + $identityClaims, 604800, 'refresh');

        CookieManager::setAccessToken($accessToken, 900);
        CookieManager::setRefreshToken($refreshToken, 604800);
    }

    /**
     * Read a user's CURRENT token epoch, tenant-scoped.
     *
     * `users` is a tenant-owned table, so the lookup carries both the user id and
     * the tenant id (the system tenant uses id 0). Returns 0 when the row or the
     * column cannot be read, matching the validator's missing-claim=0 convention.
     *
     * @param int $userId   The user id.
     * @param int $tenantId The tenant id.
     * @return int The stored token epoch (0 when unavailable).
     */
    private function currentTokenEpoch(int $userId, int $tenantId): int
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT token_epoch FROM users WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $stmt->execute([$userId, $tenantId]);
            $epoch = $stmt->fetchColumn();

            return $epoch === false ? 0 : (int) $epoch;
        } catch (\Exception) {
            return 0;
        }
    }

    /**
     * Read a profile's CURRENT token epoch (WC-d4340daf).
     *
     * Epoch source for post-cutover tokens that carry {profile_id,
     * active_tenant_id} but no legacy user_id/tenant_id. `profiles` is a
     * global identity table (ADR 0005 §1) — no tenant predicate applies.
     * Returns 0 when the row or the column cannot be read, matching the
     * validator's missing-claim=0 convention.
     *
     * @param int $profileId The profile id (0 when the claim was absent).
     * @return int The stored token epoch (0 when unavailable).
     */
    private function currentProfileTokenEpoch(int $profileId): int
    {
        if ($profileId <= 0) {
            return 0;
        }

        try {
            // @tenant-guard-ignore: profiles is a global identity table (ADR 0005 §1), not tenant-owned.
            $stmt = $this->db->prepare(
                'SELECT token_epoch FROM profiles WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$profileId]);
            $epoch = $stmt->fetchColumn();

            return $epoch === false ? 0 : (int) $epoch;
        } catch (\Exception) {
            return 0;
        }
    }

    /**
     * Revoke the caller's CURRENT access and refresh jtis (WC-185).
     *
     * Reads both auth cookies, parses each, and records its jti in the global
     * revoked_tokens table. Used on a password change so the in-flight session's
     * tokens are killed immediately. Idempotent and best-effort: a missing or
     * unparseable cookie is simply skipped.
     *
     * @return void
     */
    private function revokeCurrentSessionTokens(): void
    {
        foreach ([CookieManager::getAccessToken(), CookieManager::getRefreshToken()] as $token) {
            if ($token === null) {
                continue;
            }

            $claims = $this->jwtParser->parse($token);
            if ($claims === null) {
                continue;
            }

            $jti = $claims['jti'] ?? null;
            $exp = $claims['exp'] ?? null;
            if ($jti !== null && $exp !== null) {
                $this->revokeJti((string) $jti, (int) $exp);
            }
        }
    }

    /**
     * Record a single jti in the global revoked_tokens table (WC-185).
     *
     * revoked_tokens is the sanctioned GLOBAL (non-tenant-scoped) revocation
     * table — a jti is unique platform-wide, so there is no tenant predicate.
     * The expiry is written as a portable 'Y-m-d H:i:s' literal (accepted by both
     * PostgreSQL and SQLite, and the format the cleanup job compares against),
     * derived from the token's own exp so the row self-prunes once the token is
     * dead anyway. Inserts are de-duplicated on the UNIQUE jti so a double logout
     * / repeated revoke stays idempotent. Failure is swallowed: a best-effort
     * revoke must never break logout/profile-update, and the epoch check is the
     * authoritative backstop.
     *
     * @param string $jti The JWT ID to revoke.
     * @param int    $exp The token's expiry as a Unix timestamp.
     * @return void
     */
    private function revokeJti(string $jti, int $exp): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?)
                 ON CONFLICT (jti) DO NOTHING'
            );
            $stmt->execute([$jti, date('Y-m-d H:i:s', $exp)]);
        } catch (\Exception) {
            // Best-effort: never let a revocation failure break the caller.
        }
    }

    /**
     * Emit a structured audit record for a self-service profile update.
     *
     * Always includes the tenant id and the acting user id; never includes the
     * new email or any credential material.
     *
     * @param int  $tenantId       The acting tenant id.
     * @param int  $userId         The acting user id.
     * @param bool $emailChanged   Whether the email was changed.
     * @param bool $passwordChanged Whether the password was changed.
     * @return void
     */
    private function logProfileUpdate(int $tenantId, int $userId, bool $emailChanged, bool $passwordChanged): void
    {
        $this->logger->info('Self-service profile updated', [
            'event' => 'auth.me.update',
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'email_changed' => $emailChanged,
            'password_changed' => $passwordChanged,
        ]);
    }

    /**
     * Handle POST /api/auth/refresh - Refresh access token
     *
     * Issues a new access token when the refresh token is valid and not revoked.
     * Validates the refresh token from cookies and creates a new access token.
     *
     * @param Request $request HTTP request
     * @return Response Success response with new access token set in cookie (200) or 401 on failure
     */
    public function handleRefresh(Request $request, array $params = []): Response
    {
        $ip = $this->clientIp($request);

        // WC-0abcc29f: check IP throttle before touching the token.
        if ($this->loginThrottle !== null && $this->loginThrottle->isThrottled(null, $ip)) {
            return Response::error('Too many attempts', 429);
        }

        // Validate refresh token
        $claims = $this->tokenValidator->validateRefreshToken();

        if ($claims === null) {
            return Response::error('Unauthorized', 401);
        }

        // Re-read the CURRENT epoch rather than copying the refresh token's
        // claim: if the epoch was bumped after this refresh token was minted,
        // validateRefreshToken() would already have rejected it — but re-reading
        // guarantees the new access token never carries a stale epoch that
        // outlives the refresh token (WC-185).
        //
        // Epoch source depends on the claim shape (WC-d4340daf): tokens with
        // legacy ids read users.token_epoch (tenant-scoped); post-cutover tokens
        // (profile_id only, no user_id) read profiles.token_epoch — falling back
        // to the users lookup with ids coerced from missing claims would resolve
        // user 0/tenant 0 and silently mint epoch 0, breaking password-change
        // revocation for those tokens.
        $hasLegacyIds = isset($claims['user_id'], $claims['tenant_id'])
            && is_numeric($claims['user_id']) && is_numeric($claims['tenant_id']);
        $tokenEpoch = $hasLegacyIds
            ? $this->currentTokenEpoch((int) $claims['user_id'], (int) $claims['tenant_id'])
            : $this->currentProfileTokenEpoch(
                isset($claims['profile_id']) && is_numeric($claims['profile_id'])
                    ? (int) $claims['profile_id']
                    : 0
            );

        // Dual-claim re-mint (WC-d4340daf): the new access token carries the
        // SAME claim model as the refresh token. New claims are carried over
        // when present (the validated refresh token already passed the
        // membership gate); a LEGACY refresh token is upgraded in place when
        // the identity has been migrated since it was minted, so long-lived
        // (7-day) refresh sessions converge on the new claim shape without a
        // re-login. Epoch/revocation semantics are unchanged.
        $identityClaims = $this->carriedIdentityClaims($claims);
        if (
            $identityClaims === [] && $hasLegacyIds
            && isset($claims['email']) && is_string($claims['email'])
        ) {
            $identityClaims = $this->identityClaims($claims['email'], (int) $claims['tenant_id']);
        }

        // Create new access token (15 minutes). Legacy claims are carried only
        // when the refresh token had them — a post-cutover token must never be
        // re-minted with null/zero legacy ids (WC-d4340daf).
        $baseClaims = ['token_epoch' => $tokenEpoch];
        foreach (['user_id', 'tenant_id', 'email', 'role'] as $carried) {
            if (array_key_exists($carried, $claims)) {
                $baseClaims[$carried] = $claims[$carried];
            }
        }
        $accessToken = $this->jwtParser->create($baseClaims + $identityClaims, 900, 'access'); // 15 minutes

        // Set new access token cookie
        CookieManager::setAccessToken($accessToken, 900);

        // Return success response
        return Response::json([
            'status' => 'success'
        ], 200);
    }

    /**
     * Handle POST /api/auth/logout - Logout and revoke tokens
     *
     * Revokes BOTH the access and refresh tokens by adding each one's jti to the
     * global revoked_tokens table, then clears both cookies. Revoking the access
     * jti (WC-185) is what stops a stolen/cached access token from working until
     * its natural expiry after the user logs out — previously only the refresh
     * jti was revoked.
     *
     * This endpoint is idempotent - returns 200 even if no token is present, and a
     * repeated logout re-revokes the same jtis harmlessly (ON CONFLICT DO NOTHING).
     *
     * @param Request $request HTTP request
     * @return Response Logout confirmation (200) on success, even if no token
     */
    public function handleLogout(Request $request, array $params = []): Response
    {
        // Revoke whichever auth tokens are present (logout is idempotent, so a
        // missing or unparseable cookie is simply skipped). Both access and
        // refresh jtis are recorded in the global revoked_tokens table.
        foreach ([CookieManager::getAccessToken(), CookieManager::getRefreshToken()] as $token) {
            if ($token === null) {
                continue;
            }

            $claims = $this->jwtParser->parse($token);
            if ($claims === null) {
                continue;
            }

            $jti = $claims['jti'] ?? null;
            $exp = $claims['exp'] ?? null;
            if ($jti !== null && $exp !== null) {
                $this->revokeJti((string) $jti, (int) $exp);
            }
        }

        // Clear both cookies
        CookieManager::clearAccessToken();
        CookieManager::clearRefreshToken();

        // Return success response
        return Response::json([
            'status' => 'logged out'
        ], 200);
    }

    /**
     * Handle POST /api/login/2fa - Validate 2FA code and complete login
     *
     * Processes the second step of two-factor authentication.  After the
     * profile-based login rewrite (WC-c35c4ce0) the 2FA secret lives on the
     * `profiles` row (migration 035), not on the legacy `users` row.  The temp
     * token carries `profile_id` and `active_tenant_id` set by handle().
     *
     * Flow:
     * 1. Read the temp token cookie and extract profile_id + active_tenant_id.
     * 2. Load the profile row to get two_factor_secret / backup_codes_version.
     * 3. Validate TOTP code; fall back to backup code validation.
     * 4. On success: call completeTwoFaLogin() to mint the full access/refresh pair.
     *
     * Backward compat: temp tokens minted by the OLD login path carry user_id /
     * tenant_id instead of profile_id / active_tenant_id.  We fall back to the
     * legacy users-row look-up for those tokens so in-flight 2FA sessions at
     * deploy time are not broken.
     *
     * @param Request $request HTTP request with 2FA code in JSON body
     * @return Response User data on success (200) or error (401)
     */
    public function handle2fa(Request $request, array $params = []): Response
    {
        // Get temporary token from cookie
        $tempToken = CookieManager::getTempToken();

        if ($tempToken === null) {
            return Response::error('Invalid or expired temporary token', 401);
        }

        // Parse temp token
        $claims = $this->jwtParser->parse($tempToken);

        if ($claims === null) {
            return Response::error('Invalid or expired temporary token', 401);
        }

        // WC-0abcc29f: throttle check. Key on profile_id (new path) or user_id
        // (legacy temp tokens that pre-date this rewrite).
        $throttleId = null;
        if (isset($claims['profile_id']) && is_numeric($claims['profile_id'])) {
            $throttleId = (int) $claims['profile_id'];
        } elseif (isset($claims['user_id']) && is_numeric($claims['user_id'])) {
            $throttleId = (int) $claims['user_id'];
        }

        if ($throttleId === null) {
            return Response::error('Invalid temporary token', 401);
        }

        $ip = $this->clientIp($request);
        if ($this->loginThrottle !== null && $this->loginThrottle->isThrottled($throttleId, $ip)) {
            return Response::error('Too many attempts', 429);
        }

        // Parse request body to get 2FA code (envelope validated upstream, WC-189).
        $body = JsonBody::parsed($request);

        if (!isset($body['code']) || empty($body['code'])) {
            return Response::error('2FA code is required', 401);
        }

        $code = $body['code'];

        // ── Resolve 2FA credentials from the profile (new path) ──────────────
        $twoFactorSecret          = null;
        $backupCodesVersion       = 0;
        $auditTenantId            = null;
        $auditActorId             = $throttleId;

        $hasNewClaims = isset($claims['profile_id'], $claims['active_tenant_id'])
            && is_numeric($claims['profile_id']);

        if ($hasNewClaims) {
            $profileId      = (int) $claims['profile_id'];
            $activeTenantId = (int) $claims['active_tenant_id'];
            $auditTenantId  = $activeTenantId;
            $auditActorId   = $profileId;

            // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
            $profStmt = $this->db->prepare(
                'SELECT two_factor_secret, two_factor_backup_codes_version
                 FROM profiles WHERE id = ? LIMIT 1'
            );
            $profStmt->execute([$profileId]);
            $profRow = $profStmt->fetch(PDO::FETCH_ASSOC);

            if ($profRow === false) {
                return Response::error('Profile not found', 401);
            }

            $twoFactorSecret    = $profRow['two_factor_secret'];
            $backupCodesVersion = (int) ($profRow['two_factor_backup_codes_version'] ?? 0);
        } else {
            // Backward-compat: legacy temp token (user_id / tenant_id only).
            // This branch can be removed once all in-flight 2FA sessions have
            // expired (max 5 minutes post-deploy).
            $userId   = $claims['user_id'] ?? null;
            $tenantId = $claims['tenant_id'] ?? null;

            if ($userId === null) {
                return Response::error('Invalid temporary token', 401);
            }

            $auditTenantId = $tenantId !== null ? (int) $tenantId : null;
            $auditActorId  = (int) $userId;

            if ($tenantId === null || (int) $tenantId === 0) {
                // @tenant-guard-ignore: system-tenant / unresolved-context branch
                $legStmt = $this->db->prepare(
                    'SELECT two_factor_secret, two_factor_backup_codes_version
                     FROM users WHERE id = ? LIMIT 1'
                );
                $legStmt->execute([$userId]);
            } else {
                $legStmt = $this->db->prepare(
                    'SELECT two_factor_secret, two_factor_backup_codes_version
                     FROM users WHERE id = ? AND tenant_id = ? LIMIT 1'
                );
                $legStmt->execute([$userId, (int) $tenantId]);
            }
            $legRow = $legStmt->fetch(PDO::FETCH_ASSOC);

            if ($legRow === false) {
                return Response::error('User not found', 401);
            }

            $twoFactorSecret    = $legRow['two_factor_secret'];
            $backupCodesVersion = (int) ($legRow['two_factor_backup_codes_version'] ?? 0);
        }

        // ── Validate TOTP / backup code ──────────────────────────────────────
        $isValid = false;

        if (!empty($twoFactorSecret)) {
            try {
                $totpService = $this->getTotpService();
                if ($totpService->validateCode($twoFactorSecret, $code)) {
                    $isValid = true;
                }
            } catch (\Exception) {
                // Continue to backup code validation
            }
        }

        if (!$isValid && $backupCodesVersion > 0) {
            try {
                $backupCodesService = $this->getBackupCodesService();
                if ($backupCodesService->validateCode($throttleId, $code, $backupCodesVersion)) {
                    $isValid = true;
                }
            } catch (\Exception) {
                // Both validations failed
            }
        }

        if (!$isValid) {
            $this->audit(
                'auth.2fa.verify_failure',
                $request,
                $auditTenantId,
                $auditActorId,
                ['reason' => 'invalid_2fa_code']
            );
            $this->loginThrottle?->recordFailure($throttleId, $ip ?? null);
            return Response::error('Invalid 2FA code', 401);
        }

        // Second factor verified — full login completes.
        $this->loginThrottle?->clearUser($throttleId);
        $this->audit('auth.2fa.verify_success', $request, $auditTenantId, $auditActorId);
        $this->audit('auth.login.success', $request, $auditTenantId, $auditActorId, ['second_factor' => true]);

        return $this->completeTwoFaLogin($claims);
    }

    /**
     * Complete 2FA login by creating access and refresh tokens.
     *
     * After the profile-based login rewrite (WC-c35c4ce0) the temp token
     * carries {profile_id, active_tenant_id, email} (plus optional legacy
     * {user_id, tenant_id} during the dual window).  This method mints the
     * full access + refresh pair using the same algorithm as the single-factor
     * login path (handle()), preserving all dual-claim semantics.
     *
     * Backward compat: legacy temp tokens (user_id/tenant_id only) are handled
     * by the else-branch below and will continue to work until expired.
     *
     * @param array<string, mixed> $claims Token claims from temporary token
     * @return Response User data with tokens set in cookies (200)
     */
    private function completeTwoFaLogin(array $claims): Response
    {
        // Clear temporary token cookie
        CookieManager::clearTempToken();

        $email = isset($claims['email']) && is_string($claims['email'])
            ? $claims['email']
            : '';

        $hasNewClaims = isset($claims['profile_id'], $claims['active_tenant_id'])
            && is_numeric($claims['profile_id']);

        if ($hasNewClaims) {
            // New-path temp token: profile_id + active_tenant_id already resolved.
            $profileId      = (int) $claims['profile_id'];
            $activeTenantId = (int) $claims['active_tenant_id'];

            // Re-read the profile's current epoch (WC-185).
            $tokenEpoch = $this->currentProfileTokenEpoch($profileId);

            // Resolve role + legacy epoch from the users row (dual window).
            $roleName  = '';
            $legacyRow = $this->fetchLegacyUserRow($email, $activeTenantId);
            if ($legacyRow !== null) {
                // @tenant-guard-ignore: role-name lookup by globally-unique role id (SERIAL PK)
                $roleStmt = $this->db->prepare('SELECT name FROM roles WHERE id = ?');
                $roleStmt->execute([$legacyRow['role_id']]);
                $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
                if ($roleRow !== false) {
                    $roleName = (string) $roleRow['name'];
                }
                // Dual window: epoch lives on users during this transition.
                $tokenEpoch = $this->currentTokenEpoch(
                    (int) $legacyRow['id'],
                    $activeTenantId
                );
            }

            $newClaims  = ['profile_id' => $profileId, 'active_tenant_id' => $activeTenantId];
            $baseClaims = ['email' => $email, 'role' => $roleName, 'token_epoch' => $tokenEpoch];
            if ($legacyRow !== null) {
                $baseClaims['user_id']   = (int) $legacyRow['id'];
                $baseClaims['tenant_id'] = $activeTenantId;
            }
            $allClaims = $newClaims + $baseClaims;

            CookieManager::setAccessToken(
                $this->jwtParser->create($allClaims, 900, 'access'),
                900
            );
            CookieManager::setRefreshToken(
                $this->jwtParser->create($allClaims, 604800, 'refresh'),
                604800
            );

            $userId = $legacyRow !== null ? (int) $legacyRow['id'] : $profileId;

            return Response::json([
                'user' => ['id' => $userId, 'email' => $email, 'role' => $roleName]
            ], 200);
        }

        // Backward-compat: legacy temp token (user_id / tenant_id).
        $userId   = $claims['user_id'];
        $tenantId = $claims['tenant_id'];

        // Get role name
        // @tenant-guard-ignore: role-name lookup keyed on the authenticated user's globally-unique id (SERIAL PK)
        $roleStmt = $this->db->prepare('SELECT name FROM roles WHERE id = (SELECT role_id FROM users WHERE id = ?)');
        $roleStmt->execute([$userId]);
        $roleData = $roleStmt->fetch(PDO::FETCH_ASSOC);

        $roleName = $roleData !== false ? (string) $roleData['name'] : '';

        // Re-read the CURRENT epoch so the tokens are not stale.
        $tokenEpoch = $this->currentTokenEpoch((int) $userId, (int) $tenantId);

        // Dual-claim upgrade if the identity is now migrated.
        $identityClaims = $this->identityClaims((string) $email, (int) $tenantId);

        $legacyClaims = [
            'user_id'     => $userId,
            'tenant_id'   => $tenantId,
            'email'       => $email,
            'role'        => $roleName,
            'token_epoch' => $tokenEpoch,
        ];

        CookieManager::setAccessToken(
            $this->jwtParser->create($legacyClaims + $identityClaims, 900, 'access'),
            900
        );
        CookieManager::setRefreshToken(
            $this->jwtParser->create($legacyClaims + $identityClaims, 604800, 'refresh'),
            604800
        );

        return Response::json([
            'user' => ['id' => $userId, 'email' => $email, 'role' => $roleName]
        ], 200);
    }

    /**
     * Get the TotpService used for login-path 2FA validation.
     *
     * Prefers the instance injected via the constructor (the same one the setup/confirm path uses).
     * If none was injected, it builds one from the single shared key accessor
     * {@see TotpService::resolveEncryptionKey()} so this path can never diverge from the key used to
     * encrypt the stored secret (the WC-95 root cause).
     *
     * @return TotpService
     */
    private function getTotpService(): TotpService
    {
        if ($this->totpService === null) {
            $this->totpService = new TotpService(TotpService::resolveEncryptionKey());
        }
        return $this->totpService;
    }

    /**
     * Get or instantiate BackupCodesService
     *
     * @return BackupCodesService
     */
    private function getBackupCodesService(): BackupCodesService
    {
        if ($this->backupCodesService === null) {
            // Use provided database wrapper (for testing) or create a new one from PDO
            $dbWrapper = $this->databaseWrapper ?? new DatabaseQueryWrapper($this->db);
            $this->backupCodesService = new BackupCodesService($dbWrapper);
        }
        return $this->backupCodesService;
    }
}
