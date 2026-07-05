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
     * Resolve the caller's profile_id from validated access-token claims.
     *
     * Post-cutover (WC-idcut-E): every valid session token carries a positive-int
     * profile_id (ADR 0005 §1); there is no legacy users.id / email fallback. A
     * missing or non-positive claim means the caller has no usable identity and
     * the endpoint answers 401.
     *
     * @param array<string, mixed> $claims Validated access-token claims.
     * @return int|null The positive profile id, or null when absent/invalid.
     */
    private function resolveProfileId(array $claims): ?int
    {
        $profileId = $claims['profile_id'] ?? null;

        return is_int($profileId) && $profileId > 0 ? $profileId : null;
    }

    /**
     * Read 2FA state for a caller from the IDENTITY source (ADR 0005 §1).
     *
     * Post-cutover the authoritative IDENTITY store is `profiles` + `profile_emails`
     * — the same store login challenges against. The legacy `users`-table read is
     * gone (WC-idcut-E).
     *
     * Returns an associative array with:
     *   email                           (string) — from profile_emails (IDENTITY)
     *   two_factor_enabled              (mixed)  — raw column value (use dbTruthy)
     *   two_factor_backup_codes_version (int)
     * or null when the profile cannot be resolved.
     *
     * @param int $profileId The caller's positive profile id.
     * @return array<string, mixed>|null
     */
    private function readIdentityRow(int $profileId): ?array
    {
        // @tenant-guard-ignore: profiles / profile_emails are sanctioned GLOBAL identity tables (ADR 0005 §1-2)
        $pStmt = $this->db->prepare(
            'SELECT p.two_factor_enabled, p.two_factor_backup_codes_version,
                    pe.email
             FROM profiles p
             JOIN profile_emails pe ON pe.profile_id = p.id AND pe.is_primary = true
             WHERE p.id = ? LIMIT 1'
        );
        $pStmt->execute([$profileId]);
        $row = $pStmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * Write a 2FA state change onto the caller's PROFILE row (ADR 0005 §1) so the
     * profile-based login sees it (challenge on login, backup-code version for
     * recovery). This is the authoritative write post-cutover — there is no
     * `users`-table write anymore.
     *
     * @param int         $profileId              The caller's positive profile id.
     * @param string|null $encryptedSecretOrNull  Encrypted secret, or null to clear.
     * @param bool        $enabled                New two_factor_enabled value.
     * @param int|null    $backupCodesVersion     New version, or null to leave unchanged.
     */
    private function writeTwoFactorToProfile(
        int $profileId,
        ?string $encryptedSecretOrNull,
        bool $enabled,
        ?int $backupCodesVersion
    ): void {
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
    }

    /**
     * Record a 2FA audit entry when an audit logger is configured.
     *
     * The 2FA routes run behind tenant isolation, so the tenant, actor and IP are
     * resolved from the request-scoped audit context; the acting principal is the
     * target. No secret/code material is ever included.
     *
     * Post-cutover (WC-idcut-E) the acting identity IS the profile_id — the
     * canonical identity (ADR 0005 §1). The `actor_user_id`/`target_id` audit
     * keys are retained for schema stability and now carry the profile id.
     *
     * @param string $action    The audit action key (e.g. `auth.2fa.enabled`).
     * @param int    $profileId The acting profile id (also the target).
     * @return void
     */
    private function audit(string $action, int $profileId): void
    {
        if ($this->auditLogger === null) {
            return;
        }

        $this->auditLogger->record($action, [
            'actor_user_id' => $profileId,
            'target_type' => 'user',
            'target_id' => $profileId,
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

            $profileId = $this->resolveProfileId($claims);
            if ($profileId === null) {
                return Response::error('Invalid token claims', 401);
            }

            // Get email for QR code. IDENTITY data (email + 2FA state) is read
            // from profiles/profile_emails (ADR 0005 §1-2) via readIdentityRow().
            $user = $this->readIdentityRow($profileId);

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

            $profileId = $this->resolveProfileId($claims);
            if ($profileId === null) {
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

            // Encrypt and store the secret.
            $encryptedSecret = $this->totpService->encryptSecret($secret);

            // Enable 2FA + store the secret on the PROFILE (ADR 0005 §1) — the
            // authoritative identity store that login challenges against. This
            // sets two_factor_enabled, the secret, and backup_codes_version = 1.
            $this->writeTwoFactorToProfile($profileId, $encryptedSecret, true, 1);

            // Generate 15 backup codes.
            $codes = $this->backupCodesService->generateCodes(15);

            // Hash and store backup codes (keyed on profile_id, migration 038).
            foreach ($codes as $code) {
                $hashedCode = $this->backupCodesService->hashCode($code);
                $insertStmt = $this->db->prepare('
                    INSERT INTO backup_codes (profile_id, code, version, used)
                    VALUES (?, ?, ?, false)
                ');
                $insertStmt->execute([$profileId, $hashedCode, 1]);
            }

            $this->audit('auth.2fa.enabled', $profileId);

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

            $profileId = $this->resolveProfileId($claims);
            if ($profileId === null) {
                return Response::error('Invalid token claims', 401);
            }

            // Get current version before disabling. IDENTITY data is read from
            // profiles (ADR 0005 §1) via readIdentityRow().
            $user = $this->readIdentityRow($profileId);

            if ($user && (int) $user['two_factor_backup_codes_version'] > 0) {
                // Invalidate all backup codes for this version. backup_codes is
                // keyed on profile_id (migration 038). Cast the version to int:
                // under PostgreSQL (and the RealEngine SQLite harness with
                // STRINGIFY_FETCHES) it comes back as a string, but
                // BackupCodesService is strictly int-typed.
                $this->backupCodesService->invalidateOldCodes(
                    $profileId,
                    (int) $user['two_factor_backup_codes_version']
                );
            }

            // Disable 2FA on the PROFILE (ADR 0005 §1): clear the secret, flip
            // enabled off, and reset backup_codes_version to 0 so login stops
            // challenging immediately.
            $this->writeTwoFactorToProfile($profileId, null, false, 0);

            $this->audit('auth.2fa.disabled', $profileId);

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

            $profileId = $this->resolveProfileId($claims);
            if ($profileId === null) {
                return Response::error('Invalid token claims', 401);
            }

            // Get 2FA state from profiles (ADR 0005 §1) via readIdentityRow().
            $user = $this->readIdentityRow($profileId);

            if (!$user) {
                return Response::error('User not found', 404);
            }

            // dbTruthy(), NOT a raw cast: on PG "f" is truthy, which would BYPASS
            // this guard and let a user WITHOUT 2FA regenerate/insert backup codes.
            if (!self::dbTruthy($user['two_factor_enabled'])) {
                return Response::error('2FA is not enabled for this user', 400);
            }

            // Get current version.
            $oldVersion = (int) $user['two_factor_backup_codes_version'];
            $newVersion = $oldVersion + 1;

            // Invalidate old codes (backup_codes keyed on profile_id, migration 038).
            $this->backupCodesService->invalidateOldCodes($profileId, $oldVersion);

            // Bump the version on the PROFILE (ADR 0005 §1) so the login
            // backup-code path accepts the NEW codes and rejects the old. Secret
            // and enabled state are unchanged by a regenerate, so only the
            // version is written here (null secret arg is not passed — we call
            // the version-only profile write directly).
            // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
            $this->db->prepare(
                'UPDATE profiles
                 SET two_factor_backup_codes_version = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?'
            )->execute([$newVersion, $profileId]);

            // Generate 15 new codes.
            $codes = $this->backupCodesService->generateCodes(15);

            // Hash and store new backup codes with the new version (keyed on
            // profile_id, migration 038).
            foreach ($codes as $code) {
                $hashedCode = $this->backupCodesService->hashCode($code);
                $insertStmt = $this->db->prepare('
                    INSERT INTO backup_codes (profile_id, code, version, used)
                    VALUES (?, ?, ?, false)
                ');
                $insertStmt->execute([$profileId, $hashedCode, $newVersion]);
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

            $profileId = $this->resolveProfileId($claims);
            if ($profileId === null) {
                return Response::error('Invalid token claims', 401);
            }

            // Get 2FA status + backup-codes version from profiles (ADR 0005 §1).
            $user = $this->readIdentityRow($profileId);

            if (!$user) {
                return Response::error('User not found', 404);
            }

            // Get available backup code count for the current version only.
            // backup_codes is keyed on profile_id (migration 038). Cast the
            // version to int: under a real engine it comes back as a string, but
            // BackupCodesService is strictly int-typed.
            $codeCount = (int) $user['two_factor_backup_codes_version'] > 0
                ? $this->backupCodesService->getAvailableCodeCount(
                    $profileId,
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
