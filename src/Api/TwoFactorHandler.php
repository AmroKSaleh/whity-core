<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Http\JsonBody;
use Whity\Auth\TotpService;
use Whity\Auth\BackupCodesService;
use Whity\Auth\TokenValidator;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Tenant\TenantContext;
use PDO;

/**
 * Two-Factor Authentication API Handler
 *
 * Handles 2FA setup, confirmation, disable, regeneration, and status endpoints.
 * All endpoints require valid access token authentication.
 *
 * Endpoints:
 * - POST /api/auth/2fa/setup - Generate secret and QR code for 2FA setup
 * - POST /api/auth/2fa/confirm - Confirm 2FA setup with TOTP code
 * - POST /api/auth/2fa/disable - Disable 2FA for user
 * - POST /api/auth/2fa/regenerate-codes - Regenerate backup codes
 * - GET /api/auth/2fa/status - Get 2FA status and backup code count
 */
class TwoFactorHandler
{
    private PDO $db;
    private TotpService $totpService;
    private BackupCodesService $backupCodesService;
    private TokenValidator $tokenValidator;

    /**
     * Optional security audit-trail writer (WC-34). When set, 2FA enable/disable
     * are recorded to the audit log. Null in contexts that do not audit.
     */
    private ?AuditLogger $auditLogger;

    /**
     * Constructor
     *
     * @param PDO $db Database connection
     * @param TotpService $totpService TOTP service for secret generation and validation
     * @param BackupCodesService $backupCodesService Backup codes service
     * @param TokenValidator $tokenValidator Token validator for access tokens
     * @param AuditLogger|null $auditLogger Optional audit-trail writer (WC-34).
     */
    public function __construct(
        PDO $db,
        TotpService $totpService,
        BackupCodesService $backupCodesService,
        TokenValidator $tokenValidator,
        ?AuditLogger $auditLogger = null
    ) {
        $this->db = $db;
        $this->totpService = $totpService;
        $this->backupCodesService = $backupCodesService;
        $this->tokenValidator = $tokenValidator;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Resolve the tenant predicate for self-scoped 2FA user-row statements.
     *
     * The authenticated 2FA endpoints run behind EnforceTenantIsolation, so the
     * request tenant is locked into {@see TenantContext}. A non-system tenant
     * pins every users read/write to `(id, tenant_id)` so a row is never touched
     * outside its tenant (defense-in-depth, even though these are keyed on the
     * caller's own id). The SYSTEM tenant (id 0) — and an unresolved context —
     * stay unscoped, matching the platform convention used across the admin
     * handlers (WC-190).
     *
     * @return int|null The tenant id to scope on, or null for an unscoped lookup.
     */
    private function scopeTenantId(): ?int
    {
        $tenantId = TenantContext::getTenantId();
        return ($tenantId === null || $tenantId === 0) ? null : $tenantId;
    }

    /**
     * Coerce a DB boolean column to a real bool across drivers.
     *
     * CRITICAL: this codebase's pdo_pgsql returns the STRING "f" for a false
     * boolean, and PHP's (bool) cast treats the non-empty string "f" as TRUE.
     * A naive (bool)$row['two_factor_enabled'] therefore reports EVERY user as
     * 2FA-enabled on PostgreSQL — inverting setup()/status()/regenerateCodes()
     * false-branches. Mirrors AuthHandler::dbTruthy() and RelationRepository::toBool():
     * SQLite yields 0/1 (int), Postgres 't'/'f' (string), in-process seeds a bool.
     *
     * @param mixed $value Raw column value from a boolean field.
     */
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
     * Read 2FA state for a caller from the IDENTITY source (ADR 0005 §1).
     *
     * When a profile_id is available in the claims the authoritative IDENTITY
     * store is `profiles`; otherwise fall back to the legacy `users` row (dual-
     * window transition). This ensures status/setup/regenerateCodes/disable all
     * read from the same store that login challenges against (WC-c35c4ce0 rewrote
     * login to read from profiles, so reading `users` here would cause split-brain).
     *
     * Returns an associative array with at least:
     *   email                         (string)  — from profile_emails (IDENTITY)
     *   two_factor_enabled            (mixed)   — raw column value (use dbTruthy)
     *   two_factor_backup_codes_version (int)
     * or null when neither source resolves the caller.
     *
     * @param array<string, mixed> $claims    Validated token claims.
     * @param int                  $userId    Legacy users.id (for fallback path).
     * @param int|null             $tenantId  Resolved tenant (null = system/unscoped).
     * @return array<string, mixed>|null
     */
    private function readIdentityRow(array $claims, int $userId, ?int $tenantId): ?array
    {
        // Prefer the profile path: resolve profileId, then read from profiles +
        // profile_emails which is what login reads (ADR 0005 §1-2).
        $profileId = $this->resolveProfileId($claims, $userId, $tenantId);

        if ($profileId !== null && $profileId > 0) {
            try {
                // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
                $pStmt = $this->db->prepare(
                    'SELECT p.two_factor_enabled, p.two_factor_backup_codes_version,
                            pe.email
                     FROM profiles p
                     JOIN profile_emails pe ON pe.profile_id = p.id AND pe.is_primary = true
                     WHERE p.id = ? LIMIT 1'
                );
                $pStmt->execute([$profileId]);
                $row = $pStmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($row) && $row !== []) {
                    return $row;
                }
            } catch (\Exception) {
                // Fall through to the legacy path if the profile query fails.
            }
        }

        // Legacy fallback: read from the users row (dual-window transition).
        if ($tenantId === null) {
            // @tenant-guard-ignore: self-scoped by the caller's own token-derived user id
            $uStmt = $this->db->prepare(
                'SELECT email, two_factor_enabled, two_factor_backup_codes_version
                 FROM users WHERE id = ? LIMIT 1'
            );
            $uStmt->execute([$userId]);
        } else {
            $uStmt = $this->db->prepare(
                'SELECT email, two_factor_enabled, two_factor_backup_codes_version
                 FROM users WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $uStmt->execute([$userId, $tenantId]);
        }
        $row = $uStmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Resolve the caller's profile_id so 2FA state can be mirrored onto the
     * PROFILE model (ADR 0005). After the WC-c35c4ce0 login rewrite, login reads
     * two_factor_enabled/secret/backup_codes_version from `profiles`, so a 2FA
     * change written only to `users` would be invisible to login (no challenge)
     * — exactly the split-brain the mirror closes. Prefers the token's
     * profile_id claim; falls back to a profile_emails lookup by the user's email.
     *
     * @param array<string, mixed> $claims Validated access-token claims.
     * @return int|null The profile id, or null when none can be resolved.
     */
    private function resolveProfileId(array $claims, int $userId, ?int $tenantId): ?int
    {
        if (isset($claims['profile_id']) && is_numeric($claims['profile_id'])) {
            return (int) $claims['profile_id'];
        }

        try {
            // Resolve the user's email, then the globally-unique profile_email.
            if ($tenantId === null) {
                // @tenant-guard-ignore: self-scoped by the caller's own token-derived user id
                $uStmt = $this->db->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
                $uStmt->execute([$userId]);
            } else {
                $uStmt = $this->db->prepare('SELECT email FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
                $uStmt->execute([$userId, $tenantId]);
            }
            $email = $uStmt->fetchColumn();
            if (!is_string($email) || $email === '') {
                return null;
            }

            // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2); UNIQUE(email)
            $peStmt = $this->db->prepare('SELECT profile_id FROM profile_emails WHERE email = ? LIMIT 1');
            $peStmt->execute([$email]);
            $profileId = $peStmt->fetchColumn();

            return (is_int($profileId) || is_string($profileId)) && (int) $profileId > 0
                ? (int) $profileId
                : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Mirror a 2FA state change onto the caller's profile row so the profile-based
     * login sees it (challenge on login, backup-code version for recovery). Safe
     * no-op when no profile can be resolved (pre-migration account).
     *
     * @param array<string, mixed> $claims                  Validated token claims.
     * @param string|null          $encryptedSecretOrNull   Encrypted secret, or null to clear.
     * @param bool                 $enabled                 New two_factor_enabled value.
     * @param int|null             $backupCodesVersion       New version, or null to leave unchanged.
     */
    private function mirrorTwoFactorToProfile(
        array $claims,
        int $userId,
        ?int $tenantId,
        ?string $encryptedSecretOrNull,
        bool $enabled,
        ?int $backupCodesVersion
    ): void {
        $profileId = $this->resolveProfileId($claims, $userId, $tenantId);
        if ($profileId === null) {
            return;
        }

        try {
            if ($backupCodesVersion !== null) {
                // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
                $this->db->prepare(
                    'UPDATE profiles
                     SET two_factor_secret = ?, two_factor_enabled = ?,
                         two_factor_backup_codes_version = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?'
                )->execute([$encryptedSecretOrNull, $enabled ? 1 : 0, $backupCodesVersion, $profileId]);
            } else {
                // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
                $this->db->prepare(
                    'UPDATE profiles
                     SET two_factor_secret = ?, two_factor_enabled = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?'
                )->execute([$encryptedSecretOrNull, $enabled ? 1 : 0, $profileId]);
            }
        } catch (\Exception $e) {
            error_log('[TwoFactorHandler] profile 2FA mirror failed: ' . $e->getMessage());
        }
    }

    /**
     * Record a 2FA audit entry when an audit logger is configured.
     *
     * The 2FA routes run behind tenant isolation, so the tenant, actor and IP are
     * resolved from the request-scoped audit context; the acting user is the
     * target. No secret/code material is ever included.
     *
     * @param string $action The audit action key (e.g. `auth.2fa.enabled`).
     * @param int    $userId The acting user id (also the target).
     * @return void
     */
    private function audit(string $action, int $userId): void
    {
        if ($this->auditLogger === null) {
            return;
        }

        $this->auditLogger->record($action, [
            'actor_user_id' => $userId,
            'target_type' => 'user',
            'target_id' => $userId,
        ]);
    }

    /**
     * POST /api/auth/2fa/setup - Generate secret and QR code for 2FA setup
     *
     * Generates a new TOTP secret and returns it with a QR code URL for scanning.
     * User must then confirm with the TOTP code via the confirm endpoint.
     *
     * @param Request $request The incoming request
     * @return Response JSON response with secret and QR code URL
     */
    public function setup(Request $request): Response
    {
        try {
            // Validate access token
            $claims = $this->tokenValidator->validateAccessToken();
            if ($claims === null) {
                return Response::error('Unauthorized', 401);
            }

            $userId = $claims['user_id'] ?? null;
            if (!$userId) {
                return Response::error('Invalid token claims', 401);
            }

            // Get user email for QR code.
            // IDENTITY data: email and 2FA state are now read from profiles/profile_emails
            // (ADR 0005 §1-2) via readIdentityRow(), which prefers the profile path
            // when a profile_id claim is present and falls back to users for dual-window.
            // WC-191: tenant scoping preserved via scopeTenantId().
            $tenantId = $this->scopeTenantId();
            $user = $this->readIdentityRow($claims, (int) $userId, $tenantId);

            if (!$user) {
                return Response::error('User not found', 404);
            }

            // Check if user already has 2FA enabled. dbTruthy(), NOT a raw bool
            // cast: on PG two_factor_enabled comes back as "f"/"t" and (bool)"f"
            // is TRUE, which would wrongly 400 ("already enabled") a user who has
            // 2FA DISABLED and block them from ever enabling it.
            if (self::dbTruthy($user['two_factor_enabled'])) {
                return Response::error('2FA is already enabled for this user', 400);
            }

            // Generate new TOTP secret
            $secret = $this->totpService->generateSecret();

            // Generate QR code URL
            $qrCodeUrl = $this->totpService->generateQrCodeUrl($user['email'], $secret);

            return Response::json([
                'secret' => $secret,
                'qrCodeUrl' => $qrCodeUrl
            ], 200);
        } catch (\Exception $e) {
            error_log('[TwoFactorHandler] setup failed: ' . $e->getMessage());
            return Response::error('Failed to setup 2FA', 500);
        }
    }

    /**
     * POST /api/auth/2fa/confirm - Confirm 2FA setup with TOTP code
     *
     * Validates the provided TOTP code against the secret, then:
     * - Encrypts and stores the secret
     * - Generates 15 backup codes
     * - Marks 2FA as enabled
     *
     * @param Request $request The incoming request with code and secret in body
     * @return Response JSON response with backup codes
     */
    public function confirm(Request $request): Response
    {
        try {
            // Validate access token
            $claims = $this->tokenValidator->validateAccessToken();
            if ($claims === null) {
                return Response::error('Unauthorized', 401);
            }

            $userId = $claims['user_id'] ?? null;
            if (!$userId) {
                return Response::error('Invalid token claims', 401);
            }

            // Parse request body (envelope validated upstream, WC-189).
            $body = JsonBody::parsed($request);

            if (empty($body['code']) || empty($body['secret'])) {
                return Response::error('Code and secret are required', 400);
            }

            $code = $body['code'];
            $secret = $body['secret'];

            // Validate the code against the plaintext secret the client just submitted.
            if (!$this->totpService->verifyPlainCode($secret, $code)) {
                return Response::error('Invalid authentication code', 401);
            }

            // Encrypt and store the secret
            $encryptedSecret = $this->totpService->encryptSecret($secret);

            // Update user: set 2FA enabled and store encrypted secret
            // WC-191: pin the self write to the caller's tenant (system stays unscoped).
            $tenantId = $this->scopeTenantId();
            if ($tenantId === null) {
                // @tenant-guard-ignore: self-scoped on the caller's own token-derived user id; the tenant-resolved branch additionally binds tenant_id
                $stmt = $this->db->prepare('
                    UPDATE users
                    SET two_factor_secret = ?, two_factor_enabled = true, two_factor_backup_codes_version = 1
                    WHERE id = ?
                ');
                $stmt->execute([$encryptedSecret, $userId]);
            } else {
                $stmt = $this->db->prepare('
                    UPDATE users
                    SET two_factor_secret = ?, two_factor_enabled = true, two_factor_backup_codes_version = 1
                    WHERE id = ? AND tenant_id = ?
                ');
                $stmt->execute([$encryptedSecret, $userId, $tenantId]);
            }

            // Generate 15 backup codes
            $codes = $this->backupCodesService->generateCodes(15);

            // Hash and store backup codes
            foreach ($codes as $code) {
                $hashedCode = $this->backupCodesService->hashCode($code);
                $insertStmt = $this->db->prepare('
                    INSERT INTO backup_codes (user_id, code, version, used)
                    VALUES (?, ?, ?, false)
                ');
                $insertStmt->execute([$userId, $hashedCode, 1]);
            }

            // Mirror onto the profile so login challenges for 2FA (ADR 0005).
            $this->mirrorTwoFactorToProfile(
                $claims,
                (int) $userId,
                $tenantId,
                $encryptedSecret,
                true,
                1
            );

            $this->audit('auth.2fa.enabled', (int) $userId);

            return Response::json([
                'backup_codes' => $codes,
                'message' => 'Two-factor authentication enabled successfully'
            ], 200);
        } catch (\Exception $e) {
            error_log('[TwoFactorHandler] confirm failed: ' . $e->getMessage());
            return Response::error('Failed to confirm 2FA', 500);
        }
    }

    /**
     * POST /api/auth/2fa/disable - Disable 2FA for user
     *
     * Disables 2FA by setting two_factor_enabled = false and invalidating backup codes.
     *
     * @param Request $request The incoming request
     * @return Response JSON response confirming disable
     */
    public function disable(Request $request): Response
    {
        try {
            // Validate access token
            $claims = $this->tokenValidator->validateAccessToken();
            if ($claims === null) {
                return Response::error('Unauthorized', 401);
            }

            $userId = $claims['user_id'] ?? null;
            if (!$userId) {
                return Response::error('Invalid token claims', 401);
            }

            // WC-191: pin the self read/write to the caller's tenant (system stays unscoped).
            $tenantId = $this->scopeTenantId();

            // Get current version before disabling.
            // IDENTITY data: backup_codes_version is read from profiles (ADR 0005 §1)
            // via readIdentityRow(), which prefers the profile path and falls back to
            // users for the dual-window transition.
            $user = $this->readIdentityRow($claims, (int) $userId, $tenantId);

            if ($user && (int) $user['two_factor_backup_codes_version'] > 0) {
                // Invalidate all backup codes for this version. Cast both args to
                // int: under PostgreSQL (and the RealEngine SQLite harness with
                // STRINGIFY_FETCHES) the version and the token-derived id come
                // back as strings, but BackupCodesService is strictly int-typed.
                $this->backupCodesService->invalidateOldCodes(
                    (int) $userId,
                    (int) $user['two_factor_backup_codes_version']
                );
            }

            // Disable 2FA
            // WC-191: pin the self write to the caller's tenant (system stays unscoped).
            if ($tenantId === null) {
                // @tenant-guard-ignore: self-scoped on the caller's own token-derived user id; the tenant-resolved branch additionally binds tenant_id
                $updateStmt = $this->db->prepare('
                    UPDATE users
                    SET two_factor_enabled = false
                    WHERE id = ?
                ');
                $updateStmt->execute([$userId]);
            } else {
                $updateStmt = $this->db->prepare('
                    UPDATE users
                    SET two_factor_enabled = false
                    WHERE id = ? AND tenant_id = ?
                ');
                $updateStmt->execute([$userId, $tenantId]);
            }

            // Mirror the disable onto the profile so login stops challenging and
            // the stale secret is cleared (ADR 0005). Version 0 = no backup codes.
            $this->mirrorTwoFactorToProfile(
                $claims,
                (int) $userId,
                $tenantId,
                null,
                false,
                0
            );

            $this->audit('auth.2fa.disabled', (int) $userId);

            return Response::json([
                'message' => 'Two-factor authentication disabled'
            ], 200);
        } catch (\Exception $e) {
            error_log('[TwoFactorHandler] disable failed: ' . $e->getMessage());
            return Response::error('Failed to disable 2FA', 500);
        }
    }

    /**
     * POST /api/auth/2fa/regenerate-codes - Regenerate backup codes
     *
     * Invalidates all old backup codes and generates 15 new ones.
     * Increments the backup_codes_version to ensure old codes are unusable.
     *
     * @param Request $request The incoming request
     * @return Response JSON response with new backup codes
     */
    public function regenerateCodes(Request $request): Response
    {
        try {
            // Validate access token
            $claims = $this->tokenValidator->validateAccessToken();
            if ($claims === null) {
                return Response::error('Unauthorized', 401);
            }

            $userId = $claims['user_id'] ?? null;
            if (!$userId) {
                return Response::error('Invalid token claims', 401);
            }

            // WC-191: pin the self read/write to the caller's tenant (system stays unscoped).
            $tenantId = $this->scopeTenantId();

            // Get user and check if 2FA is enabled.
            // IDENTITY data: 2FA state is read from profiles (ADR 0005 §1) via
            // readIdentityRow(), which prefers the profile path when profile_id
            // is in the claims and falls back to users for dual-window.
            $user = $this->readIdentityRow($claims, (int) $userId, $tenantId);

            if (!$user) {
                return Response::error('User not found', 404);
            }

            // dbTruthy(), NOT a raw cast: on PG "f" is truthy, which would BYPASS
            // this guard and let a user WITHOUT 2FA regenerate/insert backup codes.
            if (!self::dbTruthy($user['two_factor_enabled'])) {
                return Response::error('2FA is not enabled for this user', 400);
            }

            // Get current version
            $oldVersion = (int) $user['two_factor_backup_codes_version'];
            $newVersion = $oldVersion + 1;

            // Invalidate old codes. Cast the id: it originates from the token
            // claim and may be a string, but BackupCodesService is strictly typed.
            $this->backupCodesService->invalidateOldCodes((int) $userId, $oldVersion);

            // Increment version in users table
            // WC-191: pin the self write to the caller's tenant (system stays unscoped).
            if ($tenantId === null) {
                // @tenant-guard-ignore: self-scoped on the caller's own token-derived user id; the tenant-resolved branch additionally binds tenant_id
                $updateStmt = $this->db->prepare('
                    UPDATE users
                    SET two_factor_backup_codes_version = ?
                    WHERE id = ?
                ');
                $updateStmt->execute([$newVersion, $userId]);
            } else {
                $updateStmt = $this->db->prepare('
                    UPDATE users
                    SET two_factor_backup_codes_version = ?
                    WHERE id = ? AND tenant_id = ?
                ');
                $updateStmt->execute([$newVersion, $userId, $tenantId]);
            }

            // Mirror the version bump onto the profile so the login backup-code
            // path (which reads two_factor_backup_codes_version from profiles)
            // accepts the NEW codes and rejects the old (ADR 0005). Secret and
            // enabled state are unchanged by a regenerate, so only the version
            // is written here.
            $profileId = $this->resolveProfileId($claims, (int) $userId, $tenantId);
            if ($profileId !== null) {
                try {
                    // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
                    $this->db->prepare(
                        'UPDATE profiles
                         SET two_factor_backup_codes_version = ?, updated_at = CURRENT_TIMESTAMP
                         WHERE id = ?'
                    )->execute([$newVersion, $profileId]);
                } catch (\Exception $e) {
                    error_log('[TwoFactorHandler] profile backup-code version mirror failed: ' . $e->getMessage());
                }
            }

            // Generate 15 new codes
            $codes = $this->backupCodesService->generateCodes(15);

            // Hash and store new backup codes with new version
            foreach ($codes as $code) {
                $hashedCode = $this->backupCodesService->hashCode($code);
                $insertStmt = $this->db->prepare('
                    INSERT INTO backup_codes (user_id, code, version, used)
                    VALUES (?, ?, ?, false)
                ');
                $insertStmt->execute([$userId, $hashedCode, $newVersion]);
            }

            return Response::json([
                'backup_codes' => $codes,
                'message' => 'Backup codes regenerated successfully'
            ], 200);
        } catch (\Exception $e) {
            error_log('[TwoFactorHandler] regenerateBackupCodes failed: ' . $e->getMessage());
            return Response::error('Failed to regenerate backup codes', 500);
        }
    }

    /**
     * GET /api/auth/2fa/status - Get 2FA status and backup code count
     *
     * Returns the current 2FA status and number of available backup codes.
     *
     * @param Request $request The incoming request
     * @return Response JSON response with 2FA status and backup code count
     */
    public function status(Request $request): Response
    {
        try {
            // Validate access token
            $claims = $this->tokenValidator->validateAccessToken();
            if ($claims === null) {
                return Response::error('Unauthorized', 401);
            }

            $userId = $claims['user_id'] ?? null;
            if (!$userId) {
                return Response::error('Invalid token claims', 401);
            }

            // Get user's 2FA status and backup codes version.
            // IDENTITY data: 2FA state is now read from profiles (ADR 0005 §1) via
            // readIdentityRow(), which prefers the profile path and falls back to
            // users for the dual-window transition. WC-191 tenant scoping preserved.
            $tenantId = $this->scopeTenantId();
            $user = $this->readIdentityRow($claims, (int) $userId, $tenantId);

            if (!$user) {
                return Response::error('User not found', 404);
            }

            // Get available backup code count for current version only. Cast both
            // args to int: under a real engine the id (from the token) and the
            // version come back as strings, but BackupCodesService is int-typed.
            $codeCount = (int) $user['two_factor_backup_codes_version'] > 0
                ? $this->backupCodesService->getAvailableCodeCount(
                    (int) $userId,
                    (int) $user['two_factor_backup_codes_version']
                )
                : 0;

            return Response::json([
                // dbTruthy(), NOT (bool): on PG (bool)"f" === true would report
                // enabled=true for EVERY user (including those with 2FA disabled).
                'enabled' => self::dbTruthy($user['two_factor_enabled']),
                'backup_codes_available' => $codeCount
            ], 200);
        } catch (\Exception $e) {
            error_log('[TwoFactorHandler] status failed: ' . $e->getMessage());
            return Response::error('Failed to get 2FA status', 500);
        }
    }
}
