<?php

namespace Whity\Auth;

use Whity\Database\Database;

/**
 * Backup Codes Service
 *
 * Handles backup code generation, hashing, validation, and management for two-factor authentication.
 * Backup codes are single-use codes that allow users to access their account if their 2FA device is lost.
 *
 * Code format: XXXX-XXXX-XXXX (12 alphanumeric characters with hyphens)
 * Storage: Hashed with bcrypt for security
 * Versioning: Supports invalidating old code sets when user regenerates
 */
class BackupCodesService
{
    private mixed $db;

    /**
     * Constructor
     *
     * @param Database|mixed $db Database connection (typically Database, but can be any object implementing query interface)
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Generate backup codes
     *
     * Generates random single-use codes in XXXX-XXXX-XXXX format.
     *
     * @param int $count Number of codes to generate (default: 15)
     * @return array Array of unencrypted backup codes in XXXX-XXXX-XXXX format
     */
    public function generateCodes(int $count = 15): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            // Generate 12 random alphanumeric characters
            $randomChars = '';
            for ($j = 0; $j < 12; $j++) {
                // Use uppercase letters and digits only
                $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                $randomChars .= $chars[random_int(0, strlen($chars) - 1)];
            }

            // Format as XXXX-XXXX-XXXX
            $code = substr($randomChars, 0, 4) . '-' . substr($randomChars, 4, 4) . '-' . substr($randomChars, 8, 4);
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * Hash a backup code using bcrypt
     *
     * Uses password_hash with PASSWORD_BCRYPT for one-way hashing.
     * Suitable for storing in database.
     *
     * @param string $code The unencrypted backup code
     * @return string The bcrypt hash of the code
     */
    public function hashCode(string $code): string
    {
        return password_hash($code, PASSWORD_BCRYPT);
    }

    /**
     * Validate a backup code for a user
     *
     * Checks if the code exists, is unused, and matches the specified version.
     * If valid, marks the code as used.
     *
     * @param int $userId The user ID
     * @param string $code The unencrypted backup code to validate
     * @param int $expectedVersion The expected version of the code
     * @return bool True if code is valid and unused, false otherwise
     */
    public function validateCode(int $userId, string $code, int $expectedVersion): bool
    {
        try {
            // Query for unused codes with the expected version
            $result = $this->db->query(
                'SELECT id, code FROM backup_codes
                 WHERE user_id = :user_id AND version = :version AND used = false
                 LIMIT 1',
                [
                    'user_id' => $userId,
                    'version' => $expectedVersion
                ]
            );

            $row = $result->fetch();

            if (!$row) {
                return false;
            }

            // Verify the code matches the stored hash
            if (!password_verify($code, $row['code'])) {
                return false;
            }

            // Mark the code as used
            $this->db->query(
                'UPDATE backup_codes SET used = true, used_at = NOW() WHERE id = :id',
                ['id' => $row['id']]
            );

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Invalidate all backup codes with an old version
     *
     * Marks all codes with a specific version as used.
     * Called when user regenerates backup codes to invalidate the old set.
     *
     * @param int $userId The user ID
     * @param int $oldVersion The old version to invalidate
     * @return void
     */
    public function invalidateOldCodes(int $userId, int $oldVersion): void
    {
        $this->db->query(
            'UPDATE backup_codes SET used = true, used_at = NOW()
             WHERE user_id = :user_id AND version = :version',
            [
                'user_id' => $userId,
                'version' => $oldVersion
            ]
        );
    }

    /**
     * Get the count of available (unused) backup codes for a user
     *
     * @param int $userId The user ID
     * @return int Count of unused backup codes
     */
    public function getAvailableCodeCount(int $userId): int
    {
        try {
            $result = $this->db->query(
                'SELECT COUNT(*) as count FROM backup_codes
                 WHERE user_id = :user_id AND used = false',
                ['user_id' => $userId]
            );

            $row = $result->fetch();
            return (int) $row['count'];
        } catch (\Exception $e) {
            return 0;
        }
    }
}
