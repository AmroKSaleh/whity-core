<?php

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Auth\TotpService;
use Whity\Auth\BackupCodesService;
use Whity\Auth\TokenValidator;
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
     * Constructor
     *
     * @param PDO $db Database connection
     * @param TotpService $totpService TOTP service for secret generation and validation
     * @param BackupCodesService $backupCodesService Backup codes service
     * @param TokenValidator $tokenValidator Token validator for access tokens
     */
    public function __construct(
        PDO $db,
        TotpService $totpService,
        BackupCodesService $backupCodesService,
        TokenValidator $tokenValidator
    ) {
        $this->db = $db;
        $this->totpService = $totpService;
        $this->backupCodesService = $backupCodesService;
        $this->tokenValidator = $tokenValidator;
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
            $stmt = $this->db->prepare('SELECT email, two_factor_enabled FROM users WHERE id = ?');
            $stmt->execute([$userId]);
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
            return Response::error('Failed to setup 2FA: ' . $e->getMessage(), 500);
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

            // Parse request body
            $body = json_decode($request->getBody(), true);

            if (empty($body['code']) || empty($body['secret'])) {
                return Response::error('Code and secret are required', 400);
            }

            $code = $body['code'];
            $secret = $body['secret'];

            // Validate the code against the unencrypted secret
            if (!$this->totpService->validateCode($this->totpService->encryptSecret($secret), $code)) {
                return Response::error('Invalid authentication code', 401);
            }

            // Encrypt and store the secret
            $encryptedSecret = $this->totpService->encryptSecret($secret);

            // Update user: set 2FA enabled and store encrypted secret
            $stmt = $this->db->prepare('
                UPDATE users
                SET two_factor_secret = ?, two_factor_enabled = true, two_factor_backup_codes_version = 1
                WHERE id = ?
            ');
            $stmt->execute([$encryptedSecret, $userId]);

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

            return Response::json([
                'backup_codes' => $codes,
                'message' => 'Two-factor authentication enabled successfully'
            ], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to confirm 2FA: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/auth/2fa/disable - Disable 2FA for user
     *
     * Disables 2FA by setting two_factor_enabled = false.
     * Backup codes are kept for reference but future logins won't require 2FA.
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

            // Disable 2FA
            $stmt = $this->db->prepare('
                UPDATE users
                SET two_factor_enabled = false
                WHERE id = ?
            ');
            $stmt->execute([$userId]);

            return Response::json([
                'message' => 'Two-factor authentication disabled'
            ], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to disable 2FA: ' . $e->getMessage(), 500);
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

            // Get user and check if 2FA is enabled
            $stmt = $this->db->prepare('
                SELECT two_factor_enabled, two_factor_backup_codes_version
                FROM users
                WHERE id = ?
            ');
            $stmt->execute([$userId]);
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

            // Invalidate old codes
            $this->backupCodesService->invalidateOldCodes($userId, $oldVersion);

            // Increment version in users table
            $updateStmt = $this->db->prepare('
                UPDATE users
                SET two_factor_backup_codes_version = ?
                WHERE id = ?
            ');
            $updateStmt->execute([$newVersion, $userId]);

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
            return Response::error('Failed to regenerate backup codes: ' . $e->getMessage(), 500);
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

            // Get user's 2FA status
            $stmt = $this->db->prepare('
                SELECT two_factor_enabled
                FROM users
                WHERE id = ?
            ');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return Response::error('User not found', 404);
            }

            // Get available backup code count
            $codeCount = $this->backupCodesService->getAvailableCodeCount($userId);

            return Response::json([
                'enabled' => (bool) $user['two_factor_enabled'],
                'backup_codes_available' => $codeCount
            ], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to get 2FA status: ' . $e->getMessage(), 500);
        }
    }
}
