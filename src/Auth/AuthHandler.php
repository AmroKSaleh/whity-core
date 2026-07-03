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
    /**
     * A fixed, VALID bcrypt hash used ONLY to burn a constant amount of CPU on
     * the unknown-email login path so it cannot be timed apart from a
     * known-email/wrong-password attempt (email-enumeration side-channel). It is
     * a real cost-12 hash of a random throwaway string — no real password
     * verifies against it, and because it is well-formed, password_verify() does
     * the full bcrypt work (a malformed hash would early-return and defeat this).
     * Cost 12 matches PASSWORD_BCRYPT's default in this runtime, so the dummy
     * verify takes the same ~time as a genuine credential check.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$Msv8wMU6LrRUtycJ5F93f.ljsdMU8FOM9dIP1xaHLYgpDuyKS5eVe';

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

        // Normalize the submitted email BEFORE the lookup. Migration 035 and the
        // seeder store LOWER(TRIM(email)) in profile_emails (email is globally
        // UNIQUE, case-folded). Querying with the raw submitted value would 401 a
        // user who typed a mixed-case/whitespace-padded address that logged in
        // fine pre-rewrite. Normalize identically here so the case-insensitive
        // contract is preserved. The original is retained only for audit display.
        $rawEmail = $body['email'];
        $email    = is_string($rawEmail) ? self::normalizeEmail($rawEmail) : '';
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
            //
            // TIMING GUARD (email enumeration): this path returns BEFORE the real
            // password_verify() below. Without compensation, an unknown email
            // responds in ~0ms while a known email + wrong password takes the full
            // bcrypt time (~tens of ms), letting an attacker enumerate registered
            // emails by response latency despite the identical 401 body. Burn one
            // bcrypt verify against a fixed dummy hash so both paths cost the same.
            password_verify(is_string($password) ? $password : '', self::DUMMY_PASSWORD_HASH);

            $this->audit('auth.login.failure', $request, null, null, [
                'email'  => $email,
                'reason' => 'profile_not_found',
            ]);
            $this->loginThrottle?->recordFailure(null, $ip);
            return Response::error('Invalid credentials', 401);
        }

        $profileId = (int) $profileEmailRow['profile_id'];

        // Unverified emails must never authenticate (ADR 0005 §2).
        //
        // Use dbTruthy(), NOT (bool)/empty(): on PostgreSQL `verified` comes back
        // as the string "f", and (bool)"f" === true, which would SKIP this guard
        // and let unverified emails in on production Postgres (the SQLite tests
        // never caught it because SQLite returns 0/1).
        //
        // Return a GENERIC 401 "Invalid credentials" (NOT a distinct 403
        // "not verified"): a verification-specific error is a user-enumeration
        // oracle — it reveals that the email is registered but unverified, vs the
        // 401 for an unknown email. The "please verify your email" prompt belongs
        // in the registration/verification flow, not the login error. Throttle is
        // keyed on the resolved profileId (the email IS registered) so repeated
        // probes of a known-but-unverified account are rate-limited per profile.
        if (!self::dbTruthy($profileEmailRow['verified'])) {
            // Same timing guard as the unknown-email path: this also returns
            // before the real password_verify(), so burn one dummy bcrypt to keep
            // the unverified-email response indistinguishable by latency.
            password_verify(is_string($password) ? $password : '', self::DUMMY_PASSWORD_HASH);

            $this->audit('auth.login.failure', $request, null, $profileId, [
                'email'  => $email,
                'reason' => 'email_not_verified',
            ]);
            $this->loginThrottle?->recordFailure($profileId, $ip);
            return Response::error('Invalid credentials', 401);
        }

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
                'email'  => $email,
                'reason' => 'profile_row_missing',
            ]);
            $this->loginThrottle?->recordFailure($profileId, $ip);
            return Response::error('Invalid credentials', 401);
        }

        if (!password_verify((string) $password, (string) $profile['password_hash'])) {
            $this->audit('auth.login.failure', $request, null, $profileId, [
                'email'  => $email,
                'reason' => 'invalid_password',
            ]);
            $this->loginThrottle?->recordFailure($profileId, $ip);
            return Response::error('Invalid credentials', 401);
        }

        // ── Step 4: resolve active memberships (ADR 0005 §6) ─────────────────
        // Zero → refuse; exactly one → log in directly (INCLUDING a single
        // tenant-0 membership: that is the legitimate system admin, not an
        // escalation); many → prompt for selection (never auto-pick, so tenant 0
        // is never silently preferred over a real tenant).
        $memberships = $this->listActiveMemberships($profileId);

        if ($memberships === []) {
            // Zero active memberships (none, or all suspended/invited). Return a
            // GENERIC 401 rather than a distinct status: a membership-specific
            // error is a user-enumeration oracle.
            $this->loginThrottle?->recordFailure($profileId, $ip);
            return Response::error('Invalid credentials', 401);
        }

        // ── Step 3: 2FA challenge (secret lives on the profile) ──────────────
        // dbTruthy(), NOT !empty(): on Postgres two_factor_enabled is the string
        // "f" for false, which !empty() treats as true (same class of bug as the
        // verified guard above). 2FA is resolved BEFORE tenant selection: the
        // second factor proves identity; tenant choice (if multiple) happens after
        // 2FA completes. The temp token carries the pre-selected active_tenant_id
        // ONLY when exactly one membership exists; with multiple, completeTwoFaLogin
        // returns the selection prompt.
        if (self::dbTruthy($profile['two_factor_enabled'] ?? false)) {
            $tempClaims = [
                'profile_id' => $profileId,
                'email'      => $email,
            ];
            if (count($memberships) === 1) {
                $only = $memberships[0]['tenant_id'];
                $tempClaims['active_tenant_id'] = $only;
                $legacyRow2fa = $this->fetchLegacyUserRow($email, $only);
                if ($legacyRow2fa !== null) {
                    $tempClaims['user_id']   = (int) $legacyRow2fa['id'];
                    $tempClaims['tenant_id'] = $only;
                }
                $this->audit('auth.login.2fa_required', $request, $only, $profileId);
            } else {
                // Multiple: tenant selection deferred to after 2FA completion.
                $this->audit('auth.login.2fa_required', $request, null, $profileId);
            }

            // Short-lived 'temp' token (5 min) — not epoch-checked (wrong type).
            CookieManager::setTempToken(
                $this->jwtParser->create($tempClaims, 300, 'temp'),
                300
            );

            return Response::json(['requires_2fa' => true], 202);
        }

        // ── Step 5: single membership → log in directly; multiple → prompt ───
        if (count($memberships) > 1) {
            return $this->requireTenantSelection($profileId, $email, $memberships);
        }

        // Exactly one selectable membership: issue the session directly.
        $activeTenantId = $memberships[0]['tenant_id'];
        $this->loginThrottle?->clearUser($profileId);
        return $this->issueSessionForProfile(
            $profileId,
            $activeTenantId,
            $email,
            (int) ($profile['token_epoch'] ?? 0),
            $request
        );
    }

    /**
     * POST /api/auth/select-tenant — ADR 0005 §6 tenant selection completion.
     *
     * The login step returns { requires_tenant_selection: true, memberships:[…] }
     * and issues NO session; instead it sets a short-lived selection cookie
     * binding the profile. This endpoint takes the chosen tenant_id, RE-VALIDATES
     * that the caller still holds an ACTIVE membership in that tenant, and only
     * then mints the session JWT. The selection token binds the two calls so a
     * caller can never select a tenant they do not belong to.
     *
     * Tenant 0 is NOT special-cased here: if the caller genuinely HOLDS an active
     * tenant-0 membership (the system admin), selecting it is LEGITIMATE system
     * authority, not an escalation. The escalation the review flagged is a
     * multi-membership user having tenant 0 silently auto-picked — that is closed
     * upstream by NEVER auto-selecting for multi-membership (always prompt); it is
     * not closed by refusing a tenant the caller actually belongs to. The real
     * integrity guard (don't grant tenant-0 memberships to non-admins) lives at
     * membership creation (see MembershipRepository::insert follow-up note).
     *
     * @param Request              $request HTTP request with { tenant_id } in body.
     * @param array<string, mixed> $params  Unused route params.
     * @return Response Session (200) on success, or 400/401 on invalid selection.
     */
    public function handleSelectTenant(Request $request, array $params = []): Response
    {
        $token = CookieManager::getTenantSelectionToken();
        if ($token === null) {
            return Response::error('No pending tenant selection', 401);
        }

        $claims = $this->jwtParser->parse($token);
        if ($claims === null
            || ($claims['type'] ?? null) !== 'tenant_select'
            || !isset($claims['profile_id']) || !is_numeric($claims['profile_id'])
        ) {
            return Response::error('Invalid or expired selection token', 401);
        }

        $profileId = (int) $claims['profile_id'];
        $email     = isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : '';

        $body = JsonBody::parsed($request);
        if (!isset($body['tenant_id']) || !is_numeric($body['tenant_id'])) {
            return Response::error('tenant_id is required', 400);
        }
        $tenantId = (int) $body['tenant_id'];

        // Re-validate: the caller MUST still hold an active membership in this
        // REAL tenant (hasActiveMembershipInTenant excludes tenant 0). This is the
        // authorization gate — never trust the submitted tenant_id alone.
        if (!$this->hasActiveMembershipInTenant($profileId, $tenantId)) {
            return Response::error('Invalid tenant selection', 401);
        }

        // Re-read the profile epoch (the selection token is short-lived but the
        // session must carry the current epoch).
        $tokenEpoch = $this->currentProfileTokenEpoch($profileId);

        CookieManager::clearTenantSelectionToken();
        $this->loginThrottle?->clearUser($profileId);

        return $this->issueSessionForProfile($profileId, $tenantId, $email, $tokenEpoch, $request);
    }

    /**
     * Build the tenant-selection prompt response (ADR 0005 §6, multi-membership).
     *
     * Issues NO session. Sets a short-lived (5-min) selection cookie binding the
     * profile so POST /api/auth/select-tenant can complete the login, and returns
     * the selectable memberships for the UI.
     *
     * @param list<array{tenant_id:int, tenant_name:string, role:string}> $memberships
     */
    private function requireTenantSelection(int $profileId, string $email, array $memberships): Response
    {
        CookieManager::setTenantSelectionToken(
            $this->jwtParser->create(
                ['profile_id' => $profileId, 'email' => $email],
                300,
                'tenant_select'
            ),
            300
        );

        return Response::json([
            'requires_tenant_selection' => true,
            'memberships'               => $memberships,
        ], 200);
    }

    /**
     * Mint the access + refresh session for a resolved (profile, tenant) pair and
     * set the auth cookies. Shared by the single-membership login path, the
     * post-2FA completion, and the tenant-selection endpoint so token/claim
     * semantics (dual-claim window) are identical across all three.
     *
     * Callers MUST have already authorised (profile_id, tenantId): this method
     * does not re-check membership.
     *
     * FOLLOW-UP NOTE (epoch cutover, later step): when a legacy users row exists
     * this embeds users.token_epoch and DISCARDS the passed $profileEpoch. That is
     * correct for the dual-claim window (TokenValidator checks users.token_epoch
     * for dual tokens), but it is fragile for the POST-CUTOVER window where only
     * profiles.token_epoch is bumped and the users row is being retired: the two
     * epochs must be reconciled (take the max) when the legacy row is pruned. The
     * epoch cutover handoff is deferred to the users-table retirement step; do not
     * change the source-of-truth here without that step.
     *
     * @param int                  $profileId       Authenticated profile.
     * @param int                  $activeTenantId  The resolved (authorised) tenant.
     * @param string               $email           Normalised email for claims/response.
     * @param int                  $profileEpoch    Fallback epoch when no legacy users row.
     * @param Request              $request         For audit context.
     * @param string               $auditAction     Audit event name to emit for this session
     *                                               issuance. Defaults to the login event; the
     *                                               tenant-switch path passes a distinct event so
     *                                               switches are not indistinguishable from logins
     *                                               in the audit trail (WC-f8164c87).
     * @param array<string, mixed> $auditMetadata   Extra audit metadata to attach to the event.
     */
    private function issueSessionForProfile(
        int $profileId,
        int $activeTenantId,
        string $email,
        int $profileEpoch,
        Request $request,
        string $auditAction = 'auth.login.success',
        array $auditMetadata = []
    ): Response {
        // Legacy users row (dual-claim window): carries role_id + legacy epoch.
        $legacyRow = $this->fetchLegacyUserRow($email, $activeTenantId);

        $roleName   = '';
        $tokenEpoch = $profileEpoch;
        if ($legacyRow !== null) {
            // @tenant-guard-ignore: role-name lookup by globally-unique role id (SERIAL PK)
            $roleStmt = $this->db->prepare('SELECT name FROM roles WHERE id = ?');
            $roleStmt->execute([$legacyRow['role_id']]);
            $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
            if ($roleRow !== false) {
                $roleName = (string) $roleRow['name'];
            }
            $tokenEpoch = (int) ($legacyRow['token_epoch'] ?? 0);
        }

        $newClaims = [
            'profile_id'       => $profileId,
            'active_tenant_id' => $activeTenantId,
        ];
        $baseClaims = [
            'email'       => $email,
            'role'        => $roleName,
            'token_epoch' => $tokenEpoch,
        ];
        if ($legacyRow !== null) {
            $baseClaims['user_id']   = (int) $legacyRow['id'];
            $baseClaims['tenant_id'] = $activeTenantId;
        }
        $allClaims = $newClaims + $baseClaims;

        CookieManager::setAccessToken($this->jwtParser->create($allClaims, 900, 'access'), 900);
        CookieManager::setRefreshToken($this->jwtParser->create($allClaims, 604800, 'refresh'), 604800);

        $this->audit($auditAction, $request, $activeTenantId, $profileId, $auditMetadata);

        $userId = $legacyRow !== null ? (int) $legacyRow['id'] : $profileId;

        return Response::json([
            'user' => [
                'id'        => $userId,
                'email'     => $email,
                'role'      => $roleName,
                'tenant_id' => $activeTenantId,
            ]
        ], 200);
    }

    /**
     * List the profile's active memberships (ADR 0005 §6).
     *
     * Returns one row per active membership, each as {tenant_id, tenant_name,
     * role}, ordered by tenant_id ASC for a stable UI.
     *
     * SYSTEM TENANT (id 0): tenant 0 is the LEGITIMATE home of the system admin
     * (system@whity.local, seeded by migration 036 with its ONLY membership in
     * tenant 0). It is therefore NOT filtered out here — a single tenant-0
     * membership is a valid login that yields correct system authority.
     *
     * The escalation the review flagged is a MULTI-membership profile where a
     * naive "lowest tenant_id" auto-pick would silently prefer 0 over a real
     * tenant. That is closed at the CALL SITE, not here: a profile with >1 active
     * membership is ALWAYS sent through the §6 selection prompt (no auto-pick at
     * all), so tenant 0 can never be silently minted for a multi-membership user.
     * The genuine integrity guard (don't grant tenant-0 memberships to non-admins)
     * belongs at membership CREATION — see the follow-up note on
     * MembershipRepository::insert.
     *
     * @tenant-guard-ignore: login flow — enumerates all tenant memberships for one profile (ADR 0005 §6)
     *
     * @param int $profileId The profile whose memberships to query.
     * @return list<array{tenant_id:int, tenant_name:string, role:string}>
     */
    private function listActiveMemberships(int $profileId): array
    {
        try {
            // @tenant-guard-ignore: login flow — enumerates all tenant memberships for one profile (ADR 0005 §6)
            $stmt = $this->db->prepare(
                "SELECT m.tenant_id, t.name AS tenant_name, r.name AS role
                 FROM memberships m
                 JOIN tenants t ON t.id = m.tenant_id
                 LEFT JOIN roles r ON r.id = m.role_id
                 WHERE m.profile_id = ? AND m.status = 'active'
                 ORDER BY m.tenant_id ASC"
            );
            $stmt->execute([$profileId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'tenant_id'   => (int) $row['tenant_id'],
                    'tenant_name' => (string) ($row['tenant_name'] ?? ''),
                    'role'        => (string) ($row['role'] ?? ''),
                ];
            }
            return $out;
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * True when the profile holds an ACTIVE membership in the given tenant.
     *
     * Tenant 0 is allowed here: it is the system admin's legitimate tenant, and
     * this is an existence check for a tenant the caller EXPLICITLY selected (the
     * §6 completion). The multi-membership escalation is prevented by requiring an
     * explicit selection, not by hiding tenant 0.
     *
     * @tenant-guard-ignore: login flow — membership existence check for one profile (ADR 0005 §6)
     */
    private function hasActiveMembershipInTenant(int $profileId, int $tenantId): bool
    {
        try {
            // @tenant-guard-ignore: login flow — membership existence check (ADR 0005 §6)
            $stmt = $this->db->prepare(
                "SELECT 1 FROM memberships
                 WHERE profile_id = ? AND tenant_id = ? AND status = 'active'
                 LIMIT 1"
            );
            $stmt->execute([$profileId, $tenantId]);
            return $stmt->fetchColumn() !== false;
        } catch (\Exception) {
            return false;
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
     * Resolve the profile_id to mirror a self-service password change onto.
     *
     * Prefers the validated token's profile_id claim (present for dual-claim and
     * profile-native tokens); falls back to a profile_emails lookup by the user's
     * current email when the claim is absent (older dual tokens). Returns null
     * when no profile can be resolved (pre-migration account with no profile yet
     * — the users-row bump alone is correct in that case).
     *
     * @param array<string, mixed> $claims Validated access-token claims.
     */
    private function resolveProfileIdForUpdate(array $claims, string $email): ?int
    {
        if (isset($claims['profile_id']) && is_numeric($claims['profile_id'])) {
            return (int) $claims['profile_id'];
        }

        if ($email === '') {
            return null;
        }

        try {
            // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2); UNIQUE(email)
            $stmt = $this->db->prepare(
                'SELECT profile_id FROM profile_emails WHERE email = ? LIMIT 1'
            );
            $stmt->execute([$email]);
            $profileId = $stmt->fetchColumn();

            return $profileId !== false ? (int) $profileId : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Coerce a DB boolean column to a real bool across drivers.
     *
     * CRITICAL: pdo_pgsql returns the STRING "f" for a false boolean, and PHP's
     * (bool) cast — and empty() — treat the non-empty string "f" as TRUE. Using
     * a naive `(bool)`/`!empty()` on a Postgres boolean therefore inverts the
     * guard (e.g. an UNVERIFIED email would be treated as verified). This mirrors
     * the coercion in migration 035 and {@see RelationRepository::toBool()}:
     * SQLite yields 0/1 (int), Postgres yields 't'/'f' (string), and an
     * in-process seed may hand back a native bool — all three are normalised here.
     *
     * @param mixed $value Raw column value from a boolean field.
     */
    /**
     * Normalize an email to the stored canonical form: LOWER(TRIM()).
     *
     * profile_emails stores case-folded, trimmed addresses (migration 035 +
     * seeder), and email is globally UNIQUE. Login MUST normalize the submitted
     * value the same way or a mixed-case/padded address 401s despite matching a
     * stored profile_email. Kept in one place so the login and any future
     * verification/registration paths agree on the canonical form.
     */
    private static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function dbTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        $normalised = strtolower(trim((string) $value));

        return !in_array($normalised, ['', '0', 'f', 'false', 'no'], true);
    }

    /**
     * Handle GET /api/me - Get current user session
     *
     * Returns the current authenticated user's data by validating the
     * access token from cookies. Also includes the caller's own active
     * memberships (WC-f8164c87) so the sidenav tenant-switcher can render
     * the available tenants without a separate round-trip.
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

        // Resolve the profile_id for the memberships lookup (new-claims token
        // preferred; fall back gracefully so legacy-only tokens still work).
        $profileId = isset($claims['profile_id']) && is_numeric($claims['profile_id'])
            ? (int) $claims['profile_id']
            : null;

        $memberships = $profileId !== null
            ? $this->listActiveMemberships($profileId)
            : [];

        // Resolve the active tenant for display. Legacy tokens carry `tenant_id`;
        // post-cutover (new-claims-only) tokens carry `active_tenant_id` and leave
        // `tenant_id` NULL. Fall back to `active_tenant_id` so the reported active
        // tenant is correct for BOTH token shapes (mirrors
        // TokenValidator::extractPrincipal and the auth-context login path) — a
        // NULL here made the sidebar TenantSwitcher show "No tenant" and never mark
        // the active tenant after a switch (WC-f8164c87).
        $activeTenantId = $claims['tenant_id'] ?? $claims['active_tenant_id'] ?? null;

        // Return user data from token claims
        return Response::json([
            'user' => [
                'id' => $claims['user_id'],
                'email' => $claims['email'],
                'role' => $claims['role'],
                'tenant_id' => $activeTenantId,
            ],
            'memberships' => $memberships,
        ], 200);
    }

    /**
     * Handle POST /api/auth/switch-tenant — authenticated tenant switch
     * (WC-f8164c87).
     *
     * Lets an ALREADY-LOGGED-IN profile with 2+ active memberships change their
     * active tenant without re-logging in. Security model:
     *
     *  1. Requires a FULL session (access token cookie) — NOT a selection cookie.
     *  2. Takes {tenant_id} in the request body.
     *  3. Re-validates that the caller's profile holds an ACTIVE membership in
     *     the requested tenant (never trust the body alone — 403 otherwise).
     *  4. Re-mints the session JWT with the new active_tenant_id using the same
     *     issueSessionForProfile() path as login, so claim semantics, epoch
     *     handling, and dual-claim invariants are identical.
     *  5. Returns the new session user shape and re-issues both auth cookies.
     *
     * Split-brain invariant: the re-minted token carries ONLY the chosen
     * active_tenant_id. Any prior active_tenant_id is superseded — no two
     * valid session cookies can exist for different active tenants at the same
     * time (both cookies are replaced atomically by CookieManager::set*).
     *
     * @param Request              $request HTTP request with { tenant_id } in body.
     * @param array<string, mixed> $params  Unused route params.
     * @return Response Session (200), 400, 401, or 403.
     */
    public function handleSwitchTenant(Request $request, array $params = []): Response
    {
        // Require a full session (validated access token).
        $claims = $this->tokenValidator->validateAccessToken();
        if ($claims === null) {
            return Response::error('Unauthorized', 401);
        }

        // Resolve the profile from the token — only new-claims tokens carry
        // profile_id; legacy-only tokens cannot switch tenants.
        $profileId = isset($claims['profile_id']) && is_numeric($claims['profile_id'])
            ? (int) $claims['profile_id']
            : null;

        if ($profileId === null) {
            return Response::error('Tenant switching requires a current-session token', 401);
        }

        $body = JsonBody::parsed($request);
        if (!isset($body['tenant_id']) || !is_numeric($body['tenant_id'])) {
            return Response::error('tenant_id is required', 400);
        }
        $targetTenantId = (int) $body['tenant_id'];

        // Authorization gate: the profile MUST hold an ACTIVE membership in the
        // target tenant. Never trust the request body alone — this is the
        // security boundary that prevents escalation to any arbitrary tenant.
        if (!$this->hasActiveMembershipInTenant($profileId, $targetTenantId)) {
            return Response::error('Access to the requested tenant is forbidden', 403);
        }

        // Re-read the email from claims for issueSessionForProfile().
        $email = isset($claims['email']) && is_string($claims['email'])
            ? $claims['email']
            : '';

        // Re-read the current profile epoch so the freshly minted token
        // reflects any post-login password changes.
        $profileEpoch = $this->currentProfileTokenEpoch($profileId);

        // Mint a new session for the chosen (profile, tenant) pair using the
        // same issueSessionForProfile() as the login path — identical claim
        // model, epoch semantics, dual-claim window behaviour.
        return $this->issueSessionForProfile(
            $profileId,
            $targetTenantId,
            $email,
            $profileEpoch,
            $request,
            'auth.tenant_switch',
            ['to_tenant_id' => $targetTenantId]
        );
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
            // Mirror the password change onto the PROFILE model (ADR 0005).
            //
            // Epoch (MAJOR-1): a dual-claim token is epoch-checked against
            // users.token_epoch, but a NEW-CLAIMS-ONLY token (profile_id, no
            // user_id) — and every token once the legacy users row is pruned — is
            // checked against profiles.token_epoch. Bumping only users.token_epoch
            // would leave those sessions alive after a password change. So we bump
            // BOTH epochs; the profile bump is what actually revokes profile-native
            // sessions.
            //
            // password_hash: login now authenticates against profiles.password_hash
            // (the #181 fix), so a change that touched only users.password would
            // leave the OLD password working for login and the NEW one failing.
            // Keep the profile hash in lock-step with the users row.
            $profileId = $this->resolveProfileIdForUpdate($claims, (string) $user['email']);
            if ($profileId !== null) {
                // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
                $this->db->prepare(
                    'UPDATE profiles
                     SET password_hash = ?, token_epoch = token_epoch + 1, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?'
                )->execute([password_hash((string) $body['password'], PASSWORD_BCRYPT), $profileId]);
            }

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

        // The id to use for backup_codes operations. backup_codes.user_id is an
        // FK to users.id (migration 035 deliberately did NOT re-point it to
        // profiles), so this MUST be a legacy users.id — never a profile_id.
        // Null means "no legacy users row" (profile-native account): backup-code
        // validation is skipped rather than run against a non-existent user id.
        $backupCodesUserId        = null;

        // New path is keyed on profile_id ALONE — active_tenant_id may be absent
        // when the profile has multiple memberships (tenant selection is deferred
        // to after 2FA completes, ADR 0005 §6).
        $hasNewClaims = isset($claims['profile_id']) && is_numeric($claims['profile_id']);

        if ($hasNewClaims) {
            $profileId      = (int) $claims['profile_id'];
            // active_tenant_id present only for the single-membership case.
            $activeTenantId = isset($claims['active_tenant_id']) && is_numeric($claims['active_tenant_id'])
                ? (int) $claims['active_tenant_id']
                : null;
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

            // Resolve the LEGACY users.id for backup-code validation. The temp
            // token carries user_id when the profile still has a legacy users row
            // (dual window); prefer it, else look it up by (email, active tenant).
            // profile_id is NOT interchangeable here: on a profile-native account
            // (profile_id != any users.id) passing profile_id would validate
            // against the wrong user's codes or hit an FK violation on rotation.
            if (isset($claims['user_id']) && is_numeric($claims['user_id'])) {
                $backupCodesUserId = (int) $claims['user_id'];
            } elseif ($activeTenantId !== null) {
                $email2fa = isset($claims['email']) && is_string($claims['email'])
                    ? $claims['email']
                    : '';
                $legacyRow2fa = $email2fa !== ''
                    ? $this->fetchLegacyUserRow($email2fa, $activeTenantId)
                    : null;
                $backupCodesUserId = $legacyRow2fa !== null ? (int) $legacyRow2fa['id'] : null;
            }
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
            // Legacy path: the temp-token user_id IS the users.id — safe for
            // backup_codes (which is keyed on users.id).
            $backupCodesUserId = (int) $userId;

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

        // Backup-code fallback. Keyed on the LEGACY users.id ($backupCodesUserId),
        // NOT profile_id/throttleId: backup_codes.user_id is an FK to users.id.
        // When no legacy users row exists (profile-native account) we cannot run
        // backup-code validation against a real user id, so it is skipped and the
        // caller falls through to the invalid-code path (fail closed) rather than
        // querying with a profile_id that would match the wrong user or none.
        if (!$isValid && $backupCodesVersion > 0 && $backupCodesUserId !== null) {
            try {
                $backupCodesService = $this->getBackupCodesService();
                if ($backupCodesService->validateCode($backupCodesUserId, $code, $backupCodesVersion)) {
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

        $hasNewClaims = isset($claims['profile_id']) && is_numeric($claims['profile_id']);

        if ($hasNewClaims) {
            $profileId = (int) $claims['profile_id'];

            // active_tenant_id present only for the single-membership case. When
            // absent, the profile has MULTIPLE memberships: 2FA is now satisfied,
            // so hand off to tenant selection (ADR 0005 §6) instead of minting a
            // session for an arbitrary tenant. Re-list to guard against membership
            // changes since the challenge was issued.
            if (!isset($claims['active_tenant_id']) || !is_numeric($claims['active_tenant_id'])) {
                $memberships = $this->listActiveMemberships($profileId);
                if ($memberships === []) {
                    return Response::error('Invalid credentials', 401);
                }
                if (count($memberships) === 1) {
                    // Collapsed to one since the challenge: issue directly.
                    return $this->issueSessionForProfile(
                        $profileId,
                        $memberships[0]['tenant_id'],
                        $email,
                        $this->currentProfileTokenEpoch($profileId),
                        new Request('POST', '/api/login/2fa', [])
                    );
                }
                return $this->requireTenantSelection($profileId, $email, $memberships);
            }

            // Single-membership 2FA: active_tenant_id already resolved.
            return $this->issueSessionForProfile(
                $profileId,
                (int) $claims['active_tenant_id'],
                $email,
                $this->currentProfileTokenEpoch($profileId),
                new Request('POST', '/api/login/2fa', [])
            );
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
