<?php

namespace Whity\Auth;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\PasswordPolicy;
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
     */
    public function __construct(
        PDO $db,
        JwtParser $jwtParser,
        ?TokenValidator $tokenValidator = null,
        ?object $databaseWrapper = null,
        ?TotpService $totpService = null,
        ?LoggerInterface $logger = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->db = $db;
        $this->jwtParser = $jwtParser;
        $this->tokenValidator = $tokenValidator ?? new TokenValidator($jwtParser, $db);
        $this->databaseWrapper = $databaseWrapper;
        $this->totpService = $totpService;
        $this->logger = $logger ?? new NullLogger();
        $this->auditLogger = $auditLogger;
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
     * Prefers the first hop in `X-Forwarded-For`, then `X-Real-IP`. Returns null
     * when neither is present (e.g. CLI). Never throws.
     *
     * @param Request $request The incoming request.
     * @return string|null The client IP, or null.
     */
    private function clientIp(Request $request): ?string
    {
        $forwarded = $request->getHeader('X-Forwarded-For');
        if (is_string($forwarded) && $forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if ($first !== '') {
                return substr($first, 0, 45);
            }
        }

        $realIp = $request->getHeader('X-Real-IP');
        if (is_string($realIp) && $realIp !== '') {
            return substr(trim($realIp), 0, 45);
        }

        return null;
    }

    /**
     * Handle login request (POST /api/login)
     *
     * Processes login requests by:
     * 1. Extracting email and password from request body
     * 2. Querying users table by email (globally unique)
     * 3. Verifying password using password_verify()
     * 4. Creating access and refresh JWT tokens
     * 5. Setting tokens in HTTP-only cookies
     * 6. Returning only user data (no token in JSON body)
     *
     * @param Request $request HTTP request with email and password in JSON body
     * @return Response HTTP response with user data (200) or error (401)
     */
    public function handle(Request $request, array $params = []): Response
    {
        // Parse request body (envelope validated upstream, WC-189).
        $body = JsonBody::parsed($request);

        // Validate request has email and password
        if (!isset($body['email']) || !isset($body['password'])) {
            return Response::error('Email and password are required', 401);
        }

        $email = $body['email'];
        $password = $body['password'];

        // Query user by email with 2FA fields (globally unique). token_epoch is
        // selected so issued tokens carry the user's CURRENT epoch (WC-185).
        // @tenant-guard-ignore: login resolves a user by globally-unique email (platform identity convention); tenant is derived from the matched row
        $stmt = $this->db->prepare('
            SELECT id, email, password, role_id, tenant_id, two_factor_enabled, two_factor_secret, two_factor_backup_codes_version, token_epoch
            FROM users
            WHERE email = ?
        ');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // User not found
        if (!$user) {
            // Failed login: no authenticated user/tenant yet. Record under the
            // system tenant with the attempted email (no credential material).
            $this->audit('auth.login.failure', $request, null, null, [
                'email' => is_string($email) ? $email : null,
                'reason' => 'user_not_found',
            ]);
            return Response::error('Invalid credentials', 401);
        }

        // Verify password
        if (!password_verify($password, $user['password'])) {
            $this->audit('auth.login.failure', $request, (int) $user['tenant_id'], (int) $user['id'], [
                'email' => is_string($email) ? $email : null,
                'reason' => 'invalid_password',
            ]);
            return Response::error('Invalid credentials', 401);
        }

        // Check if 2FA is enabled
        if (!empty($user['two_factor_enabled'])) {
            // First factor passed; the second factor is still required. Record the
            // challenge so the trail shows the partial authentication.
            $this->audit('auth.login.2fa_required', $request, (int) $user['tenant_id'], (int) $user['id']);
            // Create temporary token (5 minutes) for 2FA verification. This is a
            // short-lived 'temp' token, NOT an access/refresh token: it is never
            // epoch-checked (validateAccess/RefreshToken reject any other type),
            // so it carries no token_epoch — the epoch is read fresh and embedded
            // when the real tokens are minted in completeTwoFaLogin() (WC-185).
            $tempToken = $this->jwtParser->create([
                'user_id' => $user['id'],
                'tenant_id' => $user['tenant_id'],
                'email' => $user['email']
            ], 300, 'temp'); // 5 minutes

            // Set temporary token cookie
            CookieManager::setTempToken($tempToken, 300);

            // Return 202 Accepted with requires_2fa flag
            return Response::json([
                'requires_2fa' => true
            ], 202);
        }

        // Get role name
        // @tenant-guard-ignore: role-name lookup by globally-unique role id (SERIAL PK); the role id was just read from the authenticated user row
        $roleStmt = $this->db->prepare('SELECT name FROM roles WHERE id = ?');
        $roleStmt->execute([$user['role_id']]);
        $roleData = $roleStmt->fetch(PDO::FETCH_ASSOC);

        if (!$roleData) {
            return Response::error('Role not found', 500);
        }

        $roleName = $roleData['name'];

        // The user's current token epoch is embedded in every minted token so a
        // later epoch bump invalidates them (missing column ⇒ 0). (WC-185)
        $tokenEpoch = (int) ($user['token_epoch'] ?? 0);

        // Create access token (15 minutes)
        $accessToken = $this->jwtParser->create([
            'user_id' => $user['id'],
            'tenant_id' => $user['tenant_id'],
            'email' => $user['email'],
            'role' => $roleName,
            'token_epoch' => $tokenEpoch
        ], 900, 'access'); // 15 minutes

        // Create refresh token (7 days)
        $refreshToken = $this->jwtParser->create([
            'user_id' => $user['id'],
            'tenant_id' => $user['tenant_id'],
            'email' => $user['email'],
            'role' => $roleName,
            'token_epoch' => $tokenEpoch
        ], 604800, 'refresh'); // 7 days

        // Set cookies
        CookieManager::setAccessToken($accessToken, 900);
        CookieManager::setRefreshToken($refreshToken, 604800);

        // Successful single-factor login.
        $this->audit('auth.login.success', $request, (int) $user['tenant_id'], (int) $user['id']);

        // Return success response with user data only (no token in body)
        return Response::json([
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $roleName,
                'tenant_id' => (int) $user['tenant_id'],
            ]
        ], 200);
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
        $this->reissueAuthCookies((int) $userId, (int) $tenantId, $newEmail, $role, $currentEpoch);

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
     * @return void
     */
    private function reissueAuthCookies(int $userId, int $tenantId, string $email, string $role, int $tokenEpoch): void
    {
        $accessToken = $this->jwtParser->create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'email' => $email,
            'role' => $role,
            'token_epoch' => $tokenEpoch,
        ], 900, 'access');

        $refreshToken = $this->jwtParser->create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'email' => $email,
            'role' => $role,
            'token_epoch' => $tokenEpoch,
        ], 604800, 'refresh');

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
        // Validate refresh token
        $claims = $this->tokenValidator->validateRefreshToken();

        if ($claims === null) {
            return Response::error('Unauthorized', 401);
        }

        // Re-read the user's CURRENT epoch (tenant-scoped) rather than copying the
        // refresh token's claim: if the epoch was bumped after this refresh token
        // was minted, validateRefreshToken() would already have rejected it — but
        // re-reading guarantees the new access token never carries a stale epoch
        // that outlives the refresh token (WC-185).
        $tokenEpoch = $this->currentTokenEpoch((int) $claims['user_id'], (int) $claims['tenant_id']);

        // Create new access token (15 minutes)
        $accessToken = $this->jwtParser->create([
            'user_id' => $claims['user_id'],
            'tenant_id' => $claims['tenant_id'],
            'email' => $claims['email'],
            'role' => $claims['role'],
            'token_epoch' => $tokenEpoch
        ], 900, 'access'); // 15 minutes

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
     * Processes the second step of two-factor authentication by validating
     * a TOTP code or backup code provided by the user.
     *
     * Flow:
     * 1. Get temporary token from cookie
     * 2. Parse temp token to extract user_id
     * 3. Fetch user's 2FA secret and backup codes version
     * 4. Try TOTP validation first, then backup code validation
     * 5. If either valid: call completeTwoFaLogin() to create access/refresh tokens
     * 6. If both invalid: return 401
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

        // Parse temp token to extract user_id
        $claims = $this->jwtParser->parse($tempToken);

        if ($claims === null) {
            return Response::error('Invalid or expired temporary token', 401);
        }

        // Extract user_id from claims
        $userId = $claims['user_id'] ?? null;

        if ($userId === null) {
            return Response::error('Invalid temporary token', 401);
        }

        // WC-191: tenant claim from the temp token scopes the second-factor re-fetch.
        $tenantId = $claims['tenant_id'] ?? null;

        // Parse request body to get 2FA code (envelope validated upstream, WC-189).
        $body = JsonBody::parsed($request);

        if (!isset($body['code']) || empty($body['code'])) {
            return Response::error('2FA code is required', 401);
        }

        $code = $body['code'];

        // Fetch user's 2FA secret and backup codes version
        // WC-191: scope the 2FA-login user lookup to the temp token's tenant so the
        // second-factor re-fetch can never touch a same-id user in another tenant.
        // The SYSTEM tenant (id 0) — and a token missing the claim — stay unscoped,
        // matching the platform convention.
        if ($tenantId === null || (int) $tenantId === 0) {
            // @tenant-guard-ignore: system-tenant / unresolved-context branch; scoped else-branch binds tenant_id
            $stmt = $this->db->prepare('
                SELECT id, email, role_id, tenant_id, two_factor_secret, two_factor_backup_codes_version
                FROM users
                WHERE id = ?
            ');
            $stmt->execute([$userId]);
        } else {
            $stmt = $this->db->prepare('
                SELECT id, email, role_id, tenant_id, two_factor_secret, two_factor_backup_codes_version
                FROM users
                WHERE id = ? AND tenant_id = ?
            ');
            $stmt->execute([$userId, (int) $tenantId]);
        }
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return Response::error('User not found', 401);
        }

        // Try TOTP validation first
        $isValid = false;

        if ($user['two_factor_secret']) {
            try {
                $totpService = $this->getTotpService();
                if ($totpService->validateCode($user['two_factor_secret'], $code)) {
                    $isValid = true;
                }
            } catch (\Exception) {
                // Continue to backup code validation
            }
        }

        // Try backup code validation if TOTP failed
        if (!$isValid && $user['two_factor_backup_codes_version'] > 0) {
            try {
                $backupCodesService = $this->getBackupCodesService();
                if ($backupCodesService->validateCode($userId, $code, $user['two_factor_backup_codes_version'])) {
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
                isset($user['tenant_id']) ? (int) $user['tenant_id'] : null,
                (int) $userId,
                ['reason' => 'invalid_2fa_code']
            );
            return Response::error('Invalid 2FA code', 401);
        }

        // Second factor verified — full login completes.
        $this->audit(
            'auth.2fa.verify_success',
            $request,
            isset($user['tenant_id']) ? (int) $user['tenant_id'] : null,
            (int) $userId
        );
        $this->audit(
            'auth.login.success',
            $request,
            isset($user['tenant_id']) ? (int) $user['tenant_id'] : null,
            (int) $userId,
            ['second_factor' => true]
        );

        return $this->completeTwoFaLogin($claims);
    }

    /**
     * Complete 2FA login by creating access and refresh tokens
     *
     * Called after successful 2FA code validation. Creates access and refresh tokens,
     * clears the temporary token cookie, and returns user data.
     *
     * @param array $claims Token claims from temporary token
     * @return Response User data with tokens set in cookies (200)
     */
    private function completeTwoFaLogin(array $claims): Response
    {
        // Clear temporary token cookie
        CookieManager::clearTempToken();

        // Extract user info
        $userId = $claims['user_id'];
        $tenantId = $claims['tenant_id'];
        $email = $claims['email'];

        // Get role name
        // @tenant-guard-ignore: role-name lookup keyed on the authenticated user's globally-unique id (SERIAL PK)
        $roleStmt = $this->db->prepare('SELECT name FROM roles WHERE id = (SELECT role_id FROM users WHERE id = ?)');
        $roleStmt->execute([$userId]);
        $roleData = $roleStmt->fetch(PDO::FETCH_ASSOC);

        if (!$roleData) {
            return Response::error('Role not found', 500);
        }

        $roleName = $roleData['name'];

        // Read the user's CURRENT epoch (tenant-scoped) so the 2FA-completed
        // tokens carry it, exactly like the single-factor login path (WC-185).
        $tokenEpoch = $this->currentTokenEpoch((int) $userId, (int) $tenantId);

        // Create access token (15 minutes)
        $accessToken = $this->jwtParser->create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'email' => $email,
            'role' => $roleName,
            'token_epoch' => $tokenEpoch
        ], 900, 'access'); // 15 minutes

        // Create refresh token (7 days)
        $refreshToken = $this->jwtParser->create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'email' => $email,
            'role' => $roleName,
            'token_epoch' => $tokenEpoch
        ], 604800, 'refresh'); // 7 days

        // Set cookies
        CookieManager::setAccessToken($accessToken, 900);
        CookieManager::setRefreshToken($refreshToken, 604800);

        // Return success response with user data
        return Response::json([
            'user' => [
                'id' => $userId,
                'email' => $email,
                'role' => $roleName
            ]
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
