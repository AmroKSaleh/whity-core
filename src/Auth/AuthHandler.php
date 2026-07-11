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
     * 5. Issue the post-cutover JWT { profile_id, active_tenant_id, email,
     *    role, token_epoch } — no legacy user_id/tenant_id claims (step E).
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
        $tokenMode = self::isTokenMode($request);

        if (count($memberships) > 1) {
            return $this->requireTenantSelection($profileId, $email, $memberships, $tokenMode);
        }

        // Exactly one selectable membership: issue the session directly.
        $activeTenantId = $memberships[0]['tenant_id'];
        $this->loginThrottle?->clearUser($profileId);
        return $this->issueSessionForProfile(
            $profileId,
            $activeTenantId,
            $email,
            (int) ($profile['token_epoch'] ?? 0),
            $request,
            $tokenMode,
            recordNewSession: true
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
        $tokenMode = self::isTokenMode($request);

        // In token mode, accept the selection token from the request body field
        // `selection_token` (returned by the login step in token mode).
        // In cookie mode, read it from the tenant_select_token cookie as before.
        if ($tokenMode) {
            $body = JsonBody::parsed($request);
            $rawToken = $body['selection_token'] ?? null;
            $token = is_string($rawToken) ? $rawToken : null;
        } else {
            $token = CookieManager::getTenantSelectionToken();
        }

        if ($token === null) {
            return Response::error('No pending tenant selection', 401);
        }

        $claims = $this->jwtParser->parse($token);
        if ($claims === null
            || ($claims['type'] ?? null) !== 'tenant_select'
            || !isset($claims['profile_id']) || !is_int($claims['profile_id']) || $claims['profile_id'] <= 0
        ) {
            return Response::error('Invalid or expired selection token', 401);
        }

        $profileId = $claims['profile_id'];
        $email     = isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : '';

        $body = JsonBody::parsed($request);
        if (!isset($body['tenant_id']) || !is_numeric($body['tenant_id'])) {
            return Response::error('tenant_id is required', 400);
        }
        $tenantId = (int) $body['tenant_id'];

        // Re-validate: the caller MUST still hold an ACTIVE membership in the
        // selected tenant. This is the authorization gate — never trust the
        // submitted tenant_id alone. It applies uniformly to tenant 0: system
        // authority is grantable here ONLY to a profile that genuinely holds a
        // tenant-0 membership, so a non-system user selecting 0 is rejected (no
        // such membership) — the tenant-0 escalation guard.
        if (!$this->hasActiveMembershipInTenant($profileId, $tenantId)) {
            return Response::error('Invalid tenant selection', 401);
        }

        // Re-read the profile epoch (the selection token is short-lived but the
        // session must carry the current epoch).
        $tokenEpoch = $this->currentProfileTokenEpoch($profileId);

        // Cookie mode only: clear the selection cookie after use.
        if (!$tokenMode) {
            CookieManager::clearTenantSelectionToken();
        }
        $this->loginThrottle?->clearUser($profileId);

        return $this->issueSessionForProfile(
            $profileId,
            $tenantId,
            $email,
            $tokenEpoch,
            $request,
            $tokenMode,
            recordNewSession: true
        );
    }

    /**
     * Build the tenant-selection prompt response (ADR 0005 §6, multi-membership).
     *
     * Issues NO session. When in cookie mode, sets a short-lived (5-min) selection
     * cookie binding the profile so POST /api/auth/select-tenant can complete the
     * login. In token mode (WC-ddcd16ad) the selection token is returned in the
     * JSON body instead of a cookie, so non-browser clients can carry it.
     *
     * @param list<array{tenant_id:int, tenant_name:string, role:string}> $memberships
     * @param bool $tokenMode When true, return the selection token in the body.
     */
    private function requireTenantSelection(int $profileId, string $email, array $memberships, bool $tokenMode = false): Response
    {
        $selectionToken = $this->jwtParser->create(
            ['profile_id' => $profileId, 'email' => $email],
            300,
            'tenant_select'
        );

        if ($tokenMode) {
            // In token mode, return the selection token in the body — the client
            // will send it back as X-Selection-Token on the select-tenant endpoint.
            return Response::json([
                'requires_tenant_selection' => true,
                'memberships'               => $memberships,
                'selection_token'           => $selectionToken,
            ], 200);
        }

        CookieManager::setTenantSelectionToken($selectionToken, 300);

        return Response::json([
            'requires_tenant_selection' => true,
            'memberships'               => $memberships,
        ], 200);
    }

    /**
     * Complete a FEDERATED (SSO/OIDC) login for an already-resolved profile
     * (WC-ae16). Called by the SSO callback AFTER the external ID token has been
     * verified and mapped to a local profile (via external_identities). Mirrors
     * the password path's post-authentication step (ADR 0005 §6): resolve active
     * memberships, then single → mint session, multiple → tenant-selection prompt,
     * zero → refuse.
     *
     * Deliberately does NOT re-run local 2FA: the external IdP performed the
     * authentication, and the identity link is the proof of identity. The
     * membership chokepoint inside issueSessionForProfile still fails closed if
     * the caller has no active membership in the target tenant.
     *
     * TRUST-TIER SCOPING (WC-f3b17bd2): a GLOBAL-TRUST (operator) login passes
     * `$restrictToTenantId = null` and gets the person-centric behaviour below —
     * the session may target any tenant the profile belongs to. A TENANT-TRUST
     * (bring-your-own IdP) login passes the flow's tenant id: the session is then
     * confined to THAT tenant (fails closed if the profile is not an active
     * member), so a tenant IdP can never mint a session into another tenant the
     * person happens to belong to.
     *
     * @param int      $profileId          The local profile the verified identity maps to.
     * @param string   $email              The profile's login email (for claims/audit).
     * @param Request  $request            The callback request (for cookies / audit / IP).
     * @param int|null $restrictToTenantId When non-null, confine the session to this
     *                                     tenant (tenant-trust); when null, use the
     *                                     profile's own memberships (global-trust).
     */
    public function completeFederatedLogin(
        int $profileId,
        string $email,
        Request $request,
        ?int $restrictToTenantId = null,
    ): Response {
        if ($restrictToTenantId !== null) {
            // Tenant-trust: the session may ONLY target the configuring tenant.
            if (!$this->hasActiveMembershipInTenant($profileId, $restrictToTenantId)) {
                return Response::error('No active membership', 403);
            }
            return $this->issueSessionForProfile(
                $profileId,
                $restrictToTenantId,
                $email,
                $this->currentProfileTokenEpoch($profileId),
                $request,
                self::isTokenMode($request),
                auditAction: 'auth.login.sso',
                recordNewSession: true,
            );
        }

        $memberships = $this->listActiveMemberships($profileId);
        if ($memberships === []) {
            // No active membership (none, or all suspended/invited) → cannot mint
            // a session. Generic message (no enumeration of link/membership state).
            return Response::error('No active membership', 403);
        }

        $tokenEpoch = $this->currentProfileTokenEpoch($profileId);
        $tokenMode = self::isTokenMode($request);

        if (count($memberships) > 1) {
            return $this->requireTenantSelection($profileId, $email, $memberships, $tokenMode);
        }

        return $this->issueSessionForProfile(
            $profileId,
            $memberships[0]['tenant_id'],
            $email,
            $tokenEpoch,
            $request,
            $tokenMode,
            auditAction: 'auth.login.sso',
            recordNewSession: true,
        );
    }

    /**
     * Resolve validated access-token claims from the request.
     *
     * Precedence (WC-ddcd16ad):
     *   1. Cookie `access_token` — browser context; wins when present.
     *   2. Authorization: Bearer header — non-browser token-mode context.
     *
     * Rationale for cookie-first: CSRF attacks exploit AMBIENT (cookie) credentials;
     * an explicit Authorization header set by a native client is not forgeable
     * cross-site (the CORS preflight stops it).  We keep the browser flow identical
     * by never reading the Bearer header when a cookie is already present.  A
     * native client that also inadvertently sends a cookie (e.g. a misconfigured
     * client library) therefore authenticates via the cookie path — this is safe
     * because cookies are always validated with the same rigour.
     *
     * @param Request $request The incoming HTTP request.
     * @return array<string, mixed>|null Decoded claims, or null when no valid token.
     */
    private function resolveAccessClaims(Request $request): ?array
    {
        // Cookie path — classic browser flow.
        $fromCookie = $this->tokenValidator->validateAccessToken();
        if ($fromCookie !== null) {
            return $fromCookie;
        }

        // Bearer fallback — token mode / non-browser client.
        $authHeader = $request->getHeader('Authorization');
        if ($authHeader !== null && preg_match('/^Bearer\s+(\S+)$/', $authHeader, $m) === 1) {
            return $this->tokenValidator->validateAccessTokenFromBearer($m[1]);
        }

        return null;
    }

    /**
     * Determine whether the request opts in to token-body mode (WC-ddcd16ad).
     *
     * Token mode is signalled by the request header `X-Auth-Mode: token` on the
     * issuance endpoints (login, select-tenant, switch-tenant, refresh).  When
     * present, tokens are returned in the JSON body and NO Set-Cookie is emitted.
     * When absent, the classic cookie flow is used (zero regression for browsers).
     *
     * @param Request $request The incoming HTTP request.
     * @return bool True when the caller has opted in to token-body mode.
     */
    private static function isTokenMode(Request $request): bool
    {
        $header = $request->getHeader('X-Auth-Mode');
        return $header !== null && strtolower(trim($header)) === 'token';
    }

    /**
     * Mint the access + refresh session for a resolved (profile, tenant) pair and
     * either set the auth cookies (browser mode) or return the tokens in the JSON
     * body (token mode, WC-ddcd16ad). Shared by the single-membership login path,
     * the post-2FA completion, and the tenant-selection endpoint.
     *
     * Post-cutover (step E): emits only {profile_id, active_tenant_id, email,
     * role, token_epoch} — no legacy user_id/tenant_id claims. Role is resolved
     * from the active membership (memberships is the canonical role store,
     * ADR 0005 §6). Epoch is always profiles.token_epoch.
     *
     * Callers MUST have already authorised (profile_id, tenantId): this method
     * does not re-check membership.
     *
     * @param int                  $profileId       Authenticated profile.
     * @param int                  $activeTenantId  The resolved (authorised) tenant.
     * @param string               $email           Normalised email for claims/response.
     * @param int                  $profileEpoch    The current profiles.token_epoch.
     * @param Request              $request         For audit context.
     * @param bool                 $tokenMode       When true, return tokens in body (WC-ddcd16ad);
     *                                               when false, set cookies (classic browser flow).
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
        bool $tokenMode = false,
        string $auditAction = 'auth.login.success',
        array $auditMetadata = [],
        bool $recordNewSession = false,
        ?string $rotateSessionFromJti = null
    ): Response
    {
        // Resolve role from the active membership (post-cutover: no legacy users row).
        // @tenant-guard-ignore: memberships is the canonical role store (ADR 0005 §6)
        $roleName = '';
        $membershipStmt = $this->db->prepare(
            "SELECT r.name AS role
             FROM memberships m
             LEFT JOIN roles r ON r.id = m.role_id
             WHERE m.profile_id = ? AND m.tenant_id = ? AND m.status = 'active'
             LIMIT 1"
        );
        $membershipStmt->execute([$profileId, $activeTenantId]);
        $membershipRow = $membershipStmt->fetch(PDO::FETCH_ASSOC);
        if ($membershipRow === false) {
            // Fail closed: no ACTIVE membership in the target tenant means it was
            // revoked/suspended (possibly during the 2FA challenge window) or never
            // existed. Never mint a session on a stale claim — critically, for the
            // system tenant (0) the ActiveTenantMembershipGuard bypasses the
            // per-request membership check, so a stale active_tenant_id=0 claim here
            // would otherwise grant lingering system authority. Every other caller
            // pre-verifies membership; this is the chokepoint that also covers the
            // single-membership 2FA branch, which trusts the temp-token claim.
            return Response::json(['error' => 'No active membership for the requested tenant'], 401);
        }
        $roleName = (string) ($membershipRow['role'] ?? '');

        $claims = [
            'profile_id'       => $profileId,
            'active_tenant_id' => $activeTenantId,
            'email'            => $email,
            'role'             => $roleName,
            'token_epoch'      => $profileEpoch,
        ];

        $accessTokenStr  = $this->jwtParser->create($claims, 900, 'access');
        $refreshTokenStr = $this->jwtParser->create($claims, 604800, 'refresh');

        // Session registry (WC-f): record a new interactive session on login /
        // tenant selection, or rotate the caller's existing session on a re-mint
        // (switch-tenant / logout-others). Best-effort — a session-table failure
        // must never break auth. Device-token exchanges pass neither flag, so a
        // native client's ephemeral access token is NOT tracked here (it lives in
        // the devices list instead).
        if ($recordNewSession || $rotateSessionFromJti !== null) {
            $this->recordSession(
                $accessTokenStr,
                $refreshTokenStr,
                $profileId,
                $activeTenantId,
                $request,
                $recordNewSession,
                $rotateSessionFromJti
            );
        }

        if ($tokenMode) {
            // Token-body mode (WC-ddcd16ad): return tokens in JSON, set NO cookies.
        } else {
            // Classic browser flow: store tokens in HttpOnly cookies.
            CookieManager::setAccessToken($accessTokenStr, 900);
            CookieManager::setRefreshToken($refreshTokenStr, 604800);
        }

        $this->audit($auditAction, $request, $activeTenantId, $profileId, $auditMetadata);

        $userShape = [
            'id'        => $profileId,
            'email'     => $email,
            'role'      => $roleName,
            'tenant_id' => $activeTenantId,
        ];

        if ($tokenMode) {
            return Response::json([
                'access_token'  => $accessTokenStr,
                'refresh_token' => $refreshTokenStr,
                'token_type'    => 'Bearer',
                'expires_in'    => 900,
                'user'          => $userShape,
            ], 200);
        }

        return Response::json(['user' => $userShape], 200);
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
        // Validate access token (cookie first, Bearer fallback for token-mode clients).
        $claims = $this->resolveAccessClaims($request);

        if ($claims === null) {
            return Response::error('Unauthorized', 401);
        }

        // Post-cutover: profile_id is the canonical identity (ADR 0005 §1).
        $profileId = isset($claims['profile_id']) && is_int($claims['profile_id'])
            ? $claims['profile_id']
            : null;

        if ($profileId === null) {
            return Response::error('Unauthorized', 401);
        }

        $activeTenantId = isset($claims['active_tenant_id']) && is_int($claims['active_tenant_id'])
            ? $claims['active_tenant_id']
            : null;

        $email  = isset($claims['email']) ? (string) $claims['email'] : null;
        $role   = isset($claims['role']) ? (string) $claims['role'] : null;

        $memberships = $this->listActiveMemberships($profileId);

        return Response::json([
            'user' => [
                'id'        => $profileId,
                'email'     => $email,
                'role'      => $role,
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
        // In token mode, fall back to Bearer header when the cookie is absent.
        $tokenMode = self::isTokenMode($request);
        $claims = $this->resolveAccessClaims($request);
        if ($claims === null) {
            return Response::error('Unauthorized', 401);
        }

        // Resolve the profile from the token. Post-cutover every session token
        // carries a positive-int profile_id (is_int, never is_numeric).
        $profileId = isset($claims['profile_id']) && is_int($claims['profile_id']) && $claims['profile_id'] > 0
            ? $claims['profile_id']
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
            $tokenMode,
            'auth.tenant_switch',
            ['to_tenant_id' => $targetTenantId],
            // Same device continuing under a new active tenant → rotate the
            // caller's existing session row (matched by its current access jti).
            rotateSessionFromJti: isset($claims['jti']) && is_string($claims['jti']) ? $claims['jti'] : null
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
     * On a successful change the session is re-issued with the fresh epoch so the
     * (possibly new) email is reflected immediately by a subsequent `GET /api/me`
     * and the caller is not logged out by their own password change. In cookie
     * mode the access + refresh cookies are re-set; in token mode (X-Auth-Mode:
     * token, WC-ddcd16ad) the fresh tokens are returned in the body and NO cookie
     * is set. On a password change the caller's PRESENTED tokens are revoked —
     * the Bearer access + body refresh in token mode, or the two cookies in cookie
     * mode. The updated user is returned via the public shape (id/email/role) and
     * the bcrypt hash is never exposed. A structured audit record is logged.
     *
     * @param Request              $request HTTP request with optional `email`,
     *                                      `password` and required `current_password`.
     * @param array<string, mixed> $params  Unused route params.
     * @return Response Updated user (200) or an error (400/401/409).
     */
    public function handleUpdateMe(Request $request, array $params = []): Response
    {
        // Self-only: the acting user comes from the validated token, never the body.
        $claims = $this->resolveAccessClaims($request);
        if ($claims === null) {
            return Response::error('Unauthorized', 401);
        }

        $tokenMode = self::isTokenMode($request);

        $profileId = isset($claims['profile_id']) && is_int($claims['profile_id'])
            ? $claims['profile_id']
            : null;
        $activeTenantId = isset($claims['active_tenant_id']) && is_int($claims['active_tenant_id'])
            ? $claims['active_tenant_id']
            : null;

        if ($profileId === null || $activeTenantId === null) {
            return Response::error('Unauthorized', 401);
        }

        $body = JsonBody::parsed($request);

        $emailProvided    = array_key_exists('email', $body) && is_string($body['email']);
        $passwordProvided = isset($body['password']) && is_string($body['password']) && $body['password'] !== '';

        if (!$emailProvided && !$passwordProvided) {
            return Response::error('No changes provided', 400);
        }

        // Load the profile row (global identity table).
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $profStmt = $this->db->prepare(
            'SELECT id, password_hash, token_epoch FROM profiles WHERE id = ? LIMIT 1'
        );
        $profStmt->execute([$profileId]);
        $profile = $profStmt->fetch(PDO::FETCH_ASSOC);
        if (!$profile) {
            return Response::error('Unauthorized', 401);
        }

        // Load the primary profile_email row.
        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2)
        $peStmt = $this->db->prepare(
            'SELECT id, email FROM profile_emails WHERE profile_id = ? AND is_primary = TRUE LIMIT 1'
        );
        $peStmt->execute([$profileId]);
        $profileEmail = $peStmt->fetch(PDO::FETCH_ASSOC);
        if (!$profileEmail) {
            return Response::error('Unauthorized', 401);
        }

        // The current password must be supplied and verified for ANY change.
        $currentPassword = isset($body['current_password']) && is_string($body['current_password'])
            ? $body['current_password']
            : '';
        if ($currentPassword === '' || !password_verify($currentPassword, (string) $profile['password_hash'])) {
            return Response::error('Current password is incorrect', 401);
        }

        $profileUpdates    = [];
        $profileParams     = [];
        $newEmail          = (string) $profileEmail['email'];
        $passwordChanged   = false;

        if ($emailProvided) {
            $email = self::normalizeEmail((string) $body['email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return Response::error('Invalid email format', 400);
            }

            if ($email !== (string) $profileEmail['email']) {
                // Uniqueness: profile_emails.email is globally UNIQUE.
                // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2)
                $checkStmt = $this->db->prepare(
                    'SELECT id FROM profile_emails WHERE email = ? AND profile_id != ? LIMIT 1'
                );
                $checkStmt->execute([$email, $profileId]);
                if ($checkStmt->fetch()) {
                    return Response::error('Email already exists', 409);
                }

                // Update the primary profile_email row.
                // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2)
                $this->db->prepare(
                    'UPDATE profile_emails SET email = ? WHERE id = ?'
                )->execute([$email, $profileEmail['id']]);
                $newEmail = $email;
            }
        }

        if ($passwordProvided) {
            $newPassword = (string) $body['password'];
            try {
                PasswordPolicy::validate($newPassword);
            } catch (\InvalidArgumentException $e) {
                return Response::error($e->getMessage(), 400);
            }

            $profileUpdates[] = 'password_hash = ?';
            $profileParams[]  = password_hash($newPassword, PASSWORD_BCRYPT);
            $profileUpdates[] = 'token_epoch = token_epoch + 1';
            $passwordChanged  = true;
        }

        if ($profileUpdates !== []) {
            $profileUpdates[] = 'updated_at = CURRENT_TIMESTAMP';
            $profileParams[]  = $profileId;
            // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
            $this->db->prepare(
                'UPDATE profiles SET ' . implode(', ', $profileUpdates) . ' WHERE id = ?'
            )->execute($profileParams);
        }

        if ($passwordChanged) {
            // Revoke the caller's CURRENT session tokens on password change
            // (WC-185). In token mode the presented tokens are the Bearer access
            // header + the `refresh_token` body field — NOT cookies — so revoke
            // exactly those; in cookie mode revoke the two auth cookies.
            if ($tokenMode) {
                $this->revokeTokens([
                    $this->bearerToken($request),
                    $this->bodyRefreshToken($request),
                ]);
            } else {
                $this->revokeCurrentSessionTokens();
            }
        }

        // Re-issue the session with the fresh epoch so the caller is not logged
        // out by their own password change (the old tokens were just revoked).
        $currentEpoch = $this->currentProfileTokenEpoch($profileId);
        $role = isset($claims['role']) ? (string) $claims['role'] : '';

        $newClaims = [
            'profile_id'       => $profileId,
            'active_tenant_id' => $activeTenantId,
            'email'            => $newEmail,
            'role'             => $role,
            'token_epoch'      => $currentEpoch,
        ];

        $accessToken  = $this->jwtParser->create($newClaims, 900, 'access');
        $refreshToken = $this->jwtParser->create($newClaims, 604800, 'refresh');

        // Rotate the caller's session row to the re-minted jtis (matched by the
        // access jti this request carried) so it stays live/listable after an
        // email or password change. Best-effort.
        $currentJti = isset($claims['jti']) && is_string($claims['jti']) ? $claims['jti'] : null;
        if ($currentJti !== null) {
            $this->recordSession($accessToken, $refreshToken, $profileId, $activeTenantId, $request, false, $currentJti);
        }

        $this->logProfileUpdate($activeTenantId, $profileId, $emailProvided, $passwordChanged);

        if ($tokenMode) {
            // Token-body mode (WC-ddcd16ad): return the fresh tokens in the body,
            // set NO cookies.
            return Response::json([
                'user' => [
                    'id'    => $profileId,
                    'email' => $newEmail,
                    'role'  => $role,
                ],
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type'    => 'Bearer',
                'expires_in'    => 900,
            ], 200);
        }

        // Cookie mode: re-issue both auth cookies with the fresh epoch.
        CookieManager::setAccessToken($accessToken, 900);
        CookieManager::setRefreshToken($refreshToken, 604800);

        return Response::json(['user' => [
            'id'    => $profileId,
            'email' => $newEmail,
            'role'  => $role,
        ]], 200);
    }

    /**
     * Handle POST /api/v1/me/logout-others — sign out of all OTHER sessions and
     * devices, keeping only the caller's current session (WC-b-logout-others).
     *
     * Mechanism: bump `profiles.token_epoch`, which invalidates EVERY token the
     * profile holds — access, refresh, AND device credentials (all epoch-checked)
     * — across every browser/app/device, then immediately re-mint the CURRENT
     * session at the new epoch so the caller stays signed in here. Same primitive
     * a password change uses (handleUpdateMe), minus the password change. Because
     * revocation is a global epoch bump (sessions are stateless — no per-session
     * table), this is all-other-sessions, not selective; a specific native device
     * is instead revoked individually via DELETE /api/v1/devices/{id}.
     *
     * Self-authenticating (cookie OR Bearer access token) via resolveAccessClaims,
     * like the sibling /me and /auth/refresh endpoints.
     *
     * @param array<string, mixed> $params
     */
    public function handleLogoutOthers(Request $request, array $params = []): Response
    {
        $claims = $this->resolveAccessClaims($request);
        if ($claims === null) {
            return Response::error('Unauthorized', 401);
        }

        // Fail closed (WC-idcut-E): profile_id must be a strict positive int;
        // active_tenant_id may be 0 (system tenant), so require >= 0.
        $profileId      = isset($claims['profile_id']) && is_int($claims['profile_id']) ? $claims['profile_id'] : null;
        $activeTenantId = isset($claims['active_tenant_id']) && is_int($claims['active_tenant_id']) ? $claims['active_tenant_id'] : null;
        if ($profileId === null || $profileId <= 0 || $activeTenantId === null || $activeTenantId < 0) {
            return Response::error('Unauthorized', 401);
        }
        $email = isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : '';
        $currentJti = isset($claims['jti']) && is_string($claims['jti']) ? $claims['jti'] : '';

        // Mark every OTHER session row revoked (list accuracy + blacklist), keeping
        // the caller's current one (matched by its access jti). Best-effort.
        if ($currentJti !== '') {
            try {
                $this->sessions()->revokeAllExcept($profileId, $activeTenantId, $currentJti);
            } catch (\Throwable $e) {
                error_log('[sessions] logout-others revokeAllExcept failed: ' . $e->getMessage());
            }
        }

        // Bump the epoch → every OTHER token for this profile (access/refresh/device)
        // is now epoch-stale and rejected on next use.
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $this->db->prepare(
            'UPDATE profiles SET token_epoch = token_epoch + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        )->execute([$profileId]);

        // Re-mint the CURRENT session at the new epoch so THIS device stays signed
        // in, rotating its session row to the fresh jtis. issueSessionForProfile
        // re-resolves the role from an ACTIVE membership (fails closed if revoked),
        // sets cookies (cookie mode) or returns tokens (token mode), and audits.
        return $this->issueSessionForProfile(
            $profileId,
            $activeTenantId,
            $email,
            $this->currentProfileTokenEpoch($profileId),
            $request,
            self::isTokenMode($request),
            'auth.logout_others',
            [],
            rotateSessionFromJti: $currentJti !== '' ? $currentJti : null
        );
    }

    /**
     * Record or rotate the interactive session row for a freshly-minted access +
     * refresh pair (WC-f-sessions-table). Best-effort: it must NEVER throw into
     * the auth path, so any session-table error is logged and swallowed.
     */
    private function recordSession(
        string $accessToken,
        string $refreshToken,
        int $profileId,
        int $activeTenantId,
        Request $request,
        bool $recordNew,
        ?string $rotateFromJti
    ): void {
        try {
            $ac = $this->jwtParser->parse($accessToken);
            $rf = $this->jwtParser->parse($refreshToken);
            $accessJti  = is_array($ac) && isset($ac['jti']) ? (string) $ac['jti'] : '';
            $refreshJti = is_array($rf) && isset($rf['jti']) ? (string) $rf['jti'] : '';
            if ($accessJti === '' || $refreshJti === '') {
                return;
            }
            $expiresAt = is_array($rf) && isset($rf['exp'])
                ? date('Y-m-d H:i:s', (int) $rf['exp'])
                : date('Y-m-d H:i:s', time() + 604800);

            $sessions = $this->sessions();
            if ($recordNew) {
                $sessions->start(
                    $profileId,
                    $activeTenantId,
                    $accessJti,
                    $refreshJti,
                    $expiresAt,
                    $request->getHeader('User-Agent'),
                    $this->clientIp($request)
                );
            } elseif ($rotateFromJti !== null) {
                $sessions->rotate($rotateFromJti, $accessJti, $refreshJti, $expiresAt);
            }
        } catch (\Throwable $e) {
            error_log('[sessions] record/rotate failed: ' . $e->getMessage());
        }
    }

    /** The session registry service (constructed per call; no ctor change). */
    private function sessions(): SessionService
    {
        return new SessionService($this->db);
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
        $this->revokeTokens([
            CookieManager::getAccessToken(),
            CookieManager::getRefreshToken(),
        ]);
    }

    /**
     * Revoke a set of raw JWT strings by recording each one's jti in the global
     * revoked_tokens table (WC-185). Nulls and unparseable tokens are skipped.
     * Used to kill the caller's presented tokens on a password change, from
     * EITHER auth mode (cookies, or Bearer access + body refresh in token mode).
     *
     * @param array<int, string|null> $tokens Raw JWT strings (nulls skipped).
     * @return void
     */
    private function revokeTokens(array $tokens): void
    {
        foreach ($tokens as $token) {
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

        $tokenMode = self::isTokenMode($request);

        // Resolve the refresh token:
        //  - Cookie mode: read from the refresh_token cookie (classic browser flow).
        //  - Token mode (WC-ddcd16ad): read from Authorization: Bearer header or
        //    the body field "refresh_token". Cookie is tried first so a browser
        //    that accidentally sends both always goes through the cookie path.
        if ($tokenMode) {
            // Accept from cookie first (safety), then Bearer, then body.
            $claims = $this->tokenValidator->validateRefreshToken();
            if ($claims === null) {
                $rawToken = null;
                $authHeader = $request->getHeader('Authorization');
                if ($authHeader !== null && preg_match('/^Bearer\s+(\S+)$/', $authHeader, $m) === 1) {
                    $rawToken = $m[1];
                }
                if ($rawToken === null) {
                    $body = JsonBody::parsed($request);
                    $bodyToken = $body['refresh_token'] ?? null;
                    $rawToken = is_string($bodyToken) ? $bodyToken : null;
                }
                $claims = $rawToken !== null
                    ? $this->tokenValidator->validateRefreshTokenFromString($rawToken)
                    : null;
            }
        } else {
            $claims = $this->tokenValidator->validateRefreshToken();
        }

        if ($claims === null) {
            return Response::error('Unauthorized', 401);
        }

        // Re-read the CURRENT epoch rather than copying the refresh token's
        // claim: if the epoch was bumped after this refresh token was minted,
        // validateRefreshToken() would already have rejected it — but re-reading
        // guarantees the new access token never carries a stale epoch that
        // outlives the refresh token (WC-185).
        //
        // Always use profiles.token_epoch (post-cutover: no legacy users.token_epoch).
        //
        // FAIL CLOSED (WC-idcut-E): profile_id and active_tenant_id MUST both be
        // present as strict positive ints. Defaulting a missing/mistyped claim to
        // 0 would mint a session with profile_id=0/active_tenant_id=0 — i.e.
        // SYSTEM-TENANT authority — a privilege escalation. Use is_int (never
        // is_numeric): a numeric string must not be silently coerced here.
        $profileId      = $claims['profile_id'] ?? null;
        $activeTenantId = $claims['active_tenant_id'] ?? null;
        if (!is_int($profileId) || $profileId <= 0
            || !is_int($activeTenantId) || $activeTenantId <= 0
        ) {
            return Response::error('Unauthorized', 401);
        }

        $tokenEpoch = $this->currentProfileTokenEpoch($profileId);

        // Post-cutover: carry ONLY the new claims — never emit legacy user_id/tenant_id.
        $newClaims = [
            'profile_id'       => $profileId,
            'active_tenant_id' => $activeTenantId,
            'email'       => $claims['email'] ?? '',
            'role'        => $claims['role'] ?? '',
            'token_epoch' => $tokenEpoch,
        ];

        $accessToken     = $this->jwtParser->create($newClaims, 900, 'access');
        $newRefreshToken = $this->jwtParser->create($newClaims, 604800, 'refresh');

        // Rotate the interactive session row in place, matched by the OLD refresh
        // jti this call presented, so the family stays one row across rotations.
        $oldRefreshJti = isset($claims['jti']) && is_string($claims['jti']) ? $claims['jti'] : null;
        if ($oldRefreshJti !== null) {
            $this->recordSession($accessToken, $newRefreshToken, $profileId, $activeTenantId, $request, false, $oldRefreshJti);
        }

        if ($tokenMode) {
            // Token-body mode: return new tokens in JSON, set NO cookies.
            return Response::json([
                'access_token'  => $accessToken,
                'refresh_token' => $newRefreshToken,
                'token_type'    => 'Bearer',
                'expires_in'    => 900,
            ], 200);
        }

        // Cookie mode: set the new access token cookie (classic browser flow).
        // A new refresh token cookie is also issued to rotate it.
        CookieManager::setAccessToken($accessToken, 900);
        CookieManager::setRefreshToken($newRefreshToken, 604800);

        // Return success response
        return Response::json([
            'status' => 'success'
        ], 200);
    }

    /**
     * Handle POST /api/v1/devices/token — exchange a device credential for a
     * short-lived session (WC-b-device-tokens).
     *
     * The native client presents its long-lived device credential (type='device',
     * issued at enrollment) as `Authorization: Bearer` or in the body field
     * `credential`, and receives a fresh access+refresh session (token-body mode —
     * device clients never use cookies). This is the "per-device refresh
     * credential" step: the durable, individually-revocable enrollment mints
     * ephemeral sessions on demand.
     *
     * PUBLIC route: the credential self-authenticates (no prior session), exactly
     * like the MCP bearer surface. Two kill switches stop a leaked credential:
     * revoking the device (DELETE /api/v1/devices/{id}) inserts its jti into
     * revoked_tokens; and a password change bumps profiles.token_epoch, which the
     * epoch-checked validateDeviceToken rejects — so a stolen credential cannot be
     * laundered into persistence that survives a password change. Either way no
     * further sessions can be minted, and any already-issued access token dies
     * within its 15-minute TTL.
     *
     * @param array<string, mixed> $params
     */
    public function handleDeviceTokenExchange(Request $request, array $params = []): Response
    {
        $ip = $this->clientIp($request);

        // Throttle by IP before touching the credential (mirrors handleRefresh).
        if ($this->loginThrottle !== null && $this->loginThrottle->isThrottled(null, $ip)) {
            return Response::error('Too many attempts', 429);
        }

        // Resolve the device credential: Authorization: Bearer, else body field.
        $rawToken = null;
        $authHeader = $request->getHeader('Authorization');
        if ($authHeader !== null && preg_match('/^Bearer\s+(\S+)$/', $authHeader, $m) === 1) {
            $rawToken = $m[1];
        }
        if ($rawToken === null) {
            $body = JsonBody::parsed($request);
            $bodyToken = $body['credential'] ?? null;
            $rawToken = is_string($bodyToken) ? $bodyToken : null;
        }

        $claims = $rawToken !== null ? $this->tokenValidator->validateDeviceToken($rawToken) : null;
        if ($claims === null) {
            $this->loginThrottle?->recordFailure(null, $ip);
            return Response::error('Invalid device credential', 401);
        }

        // Fail closed (WC-idcut-E): profile_id must be a strict positive int;
        // active_tenant_id may be 0 (system tenant), so require >= 0. A missing or
        // mistyped claim must never default to 0/system authority.
        $profileId      = $claims['profile_id'] ?? null;
        $activeTenantId = $claims['active_tenant_id'] ?? null;
        if (!is_int($profileId) || $profileId <= 0 || !is_int($activeTenantId) || $activeTenantId < 0) {
            return Response::error('Invalid device credential', 401);
        }
        $email = isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : '';
        $jti   = isset($claims['jti']) && is_string($claims['jti']) ? $claims['jti'] : '';

        // Best-effort last-seen bump; a failed touch must not block the exchange.
        if ($jti !== '') {
            try {
                // @tenant-guard-ignore: jti is a platform-wide UNIQUE 128-bit handle bound to this validated credential — a jti-only predicate cannot touch another tenant's device row (same as isDeviceRegistered).
                $touch = $this->db->prepare('UPDATE devices SET last_seen_at = NOW() WHERE jti = ?');
                $touch->execute([$jti]);
            } catch (\Throwable) {
                // ignore — last_seen is telemetry, not a gate
            }
        }

        // Re-read the CURRENT epoch so the minted access token is never stale
        // (same rationale as handleRefresh, WC-185).
        $tokenEpoch = $this->currentProfileTokenEpoch($profileId);

        // Native clients always want body tokens — force token mode.
        return $this->issueSessionForProfile(
            $profileId,
            $activeTenantId,
            $email,
            $tokenEpoch,
            $request,
            true,
            'auth.device.exchange'
        );
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
        // Revoke whichever auth tokens are present, from EITHER auth mode
        // (WC-ddcd16ad). Logout is idempotent, so any missing or unparseable
        // token is simply skipped. All jtis are recorded in the global
        // revoked_tokens table.
        //
        // Sources, in order:
        //  1. Cookie access_token + refresh_token — classic browser flow.
        //  2. Authorization: Bearer <access> — a token-mode client keeps its
        //     tokens in memory (no cookies); without this, logout would revoke
        //     NOTHING and the access token would live for up to 15 min and the
        //     refresh token for 7 days (revocation contract broken).
        //  3. `refresh_token` body field — symmetric with handleRefresh's
        //     token-mode input so the refresh jti is revoked too.
        // The cookie branch simply no-ops when no cookies are present, so this
        // handles both modes without a mode flag.
        $tokens = [
            CookieManager::getAccessToken(),
            CookieManager::getRefreshToken(),
            $this->bearerToken($request),
            $this->bodyRefreshToken($request),
        ];

        foreach ($tokens as $token) {
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

        // Clear both cookies (a no-op for token-mode clients that never set them).
        CookieManager::clearAccessToken();
        CookieManager::clearRefreshToken();

        // Return success response
        return Response::json([
            'status' => 'logged out'
        ], 200);
    }

    /**
     * Extract a well-formed `Authorization: Bearer <token>` value, or null.
     *
     * @param Request $request The incoming HTTP request.
     * @return string|null The bearer token, or null when absent/malformed.
     */
    private function bearerToken(Request $request): ?string
    {
        $authHeader = $request->getHeader('Authorization');
        if ($authHeader !== null && preg_match('/^Bearer\s+(\S+)$/', $authHeader, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * Extract a `refresh_token` string from the request body, or null.
     *
     * @param Request $request The incoming HTTP request.
     * @return string|null The refresh token, or null when absent/non-string.
     */
    private function bodyRefreshToken(Request $request): ?string
    {
        $body      = JsonBody::parsed($request);
        $bodyToken = $body['refresh_token'] ?? null;
        return is_string($bodyToken) ? $bodyToken : null;
    }

    /**
     * Handle POST /api/login/2fa - Validate 2FA code and complete login
     *
     * Processes the second step of two-factor authentication. The 2FA secret
     * lives on the `profiles` row (migration 035). The temp token MUST carry
     * `profile_id` (enforced post-cutover — step E).
     *
     * Flow:
     * 1. Read the temp token cookie and extract profile_id (required).
     * 2. Load the profile row to get two_factor_secret / backup_codes_version.
     * 3. Validate TOTP code; fall back to backup code validation.
     * 4. On success: call completeTwoFaLogin() to mint the full access/refresh pair.
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

        // WC-0abcc29f: throttle check keyed on profile_id (post-cutover).
        $throttleId = null;
        if (isset($claims['profile_id']) && is_numeric($claims['profile_id'])) {
            $throttleId = (int) $claims['profile_id'];
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

        // ── Resolve 2FA credentials from the profile ──────────────────────────
        // Post-cutover: profile_id is required in the temp token (ADR 0005 §1)
        // and must be a positive int (is_int, never is_numeric).
        if (!isset($claims['profile_id']) || !is_int($claims['profile_id']) || $claims['profile_id'] <= 0) {
            return Response::error('Invalid temporary token', 401);
        }

        $profileId      = $claims['profile_id'];
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

        // backup_codes is keyed on profile_id (migration 038).
        $backupCodesUserId = $profileId;

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

        // Backup-code fallback. backup_codes is keyed on profile_id after
        // migration 038 ($backupCodesUserId holds the resolved profiles.id).
        // When no profile could be resolved, backup-code validation is skipped
        // (fail-closed) and the caller falls through to the invalid-code path.
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

        // Thread the REAL request so token mode (WC-ddcd16ad) is honoured: a 2FA
        // user sending X-Auth-Mode: token must receive body tokens, not cookies.
        return $this->completeTwoFaLogin($claims, $request);
    }

    /**
     * Complete 2FA login by creating access and refresh tokens.
     *
     * Post-cutover: the temp token always carries profile_id (enforced by
     * handle2fa()). Mints the full access + refresh pair using the same
     * algorithm as the single-factor login path (handle()). Token mode
     * (WC-ddcd16ad) is honoured: X-Auth-Mode: token returns body tokens.
     *
     * @param array<string, mixed> $claims  Token claims from temporary token.
     * @param Request              $request The real /login/2fa request (for token-mode detection).
     * @return Response User data with tokens in cookies OR body (200)
     */
    private function completeTwoFaLogin(array $claims, Request $request): Response
    {
        // Clear temporary token cookie
        CookieManager::clearTempToken();

        $tokenMode = self::isTokenMode($request);

        $email = isset($claims['email']) && is_string($claims['email'])
            ? $claims['email']
            : '';

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
                    $request,
                    $tokenMode,
                    recordNewSession: true
                );
            }
            return $this->requireTenantSelection($profileId, $email, $memberships, $tokenMode);
        }

        // Single-membership 2FA: active_tenant_id already resolved.
        return $this->issueSessionForProfile(
            $profileId,
            (int) $claims['active_tenant_id'],
            $email,
            $this->currentProfileTokenEpoch($profileId),
            $request,
            $tokenMode,
            recordNewSession: true
        );
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
