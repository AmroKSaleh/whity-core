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

            // Get user email for QR code
            // WC-191: pin the self read to the caller's tenant (system stays unscoped).
            $tenantId = $this->scopeTenantId();
            if ($tenantId === null) {
                $stmt = $this->db->prepare('SELECT email, two_factor_enabled FROM users WHERE id = ?');
                $stmt->execute([$userId]);
            } else {
                $stmt = $this->db->prepare('SELECT email, two_factor_enabled FROM users WHERE id = ? AND tenant_id = ?');
                $stmt->execute([$userId, $tenantId]);
            }
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return Response::error('User not found', 404);
            }

            // Check if user already has 2FA enabled
            if ($user['two_factor_enabled']) {
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

            // Get current version before disabling
            if ($tenantId === null) {
                $stmt = $this->db->prepare('
                    SELECT two_factor_backup_codes_version
                    FROM users
                    WHERE id = ?
                ');
                $stmt->execute([$userId]);
            } else {
                $stmt = $this->db->prepare('
                    SELECT two_factor_backup_codes_version
                    FROM users
                    WHERE id = ? AND tenant_id = ?
                ');
                $stmt->execute([$userId, $tenantId]);
            }
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

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

            // Get user and check if 2FA is enabled
            if ($tenantId === null) {
                $stmt = $this->db->prepare('
                    SELECT two_factor_enabled, two_factor_backup_codes_version
                    FROM users
                    WHERE id = ?
                ');
                $stmt->execute([$userId]);
            } else {
                $stmt = $this->db->prepare('
                    SELECT two_factor_enabled, two_factor_backup_codes_version
                    FROM users
                    WHERE id = ? AND tenant_id = ?
                ');
                $stmt->execute([$userId, $tenantId]);
            }
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return Response::error('User not found', 404);
            }

            if (!$user['two_factor_enabled']) {
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

            // Get user's 2FA status and backup codes version
            // WC-191: pin the self read to the caller's tenant (system stays unscoped).
            $tenantId = $this->scopeTenantId();
            if ($tenantId === null) {
                $stmt = $this->db->prepare('
                    SELECT two_factor_enabled, two_factor_backup_codes_version
                    FROM users
                    WHERE id = ?
                ');
                $stmt->execute([$userId]);
            } else {
                $stmt = $this->db->prepare('
                    SELECT two_factor_enabled, two_factor_backup_codes_version
                    FROM users
                    WHERE id = ? AND tenant_id = ?
                ');
                $stmt->execute([$userId, $tenantId]);
            }
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

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
                'enabled' => (bool) $user['two_factor_enabled'],
                'backup_codes_available' => $codeCount
            ], 200);
        } catch (\Exception $e) {
            error_log('[TwoFactorHandler] status failed: ' . $e->getMessage());
            return Response::error('Failed to get 2FA status', 500);
        }
    }
}
