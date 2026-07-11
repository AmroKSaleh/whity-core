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
 *
 * Migration 038 re-keyed backup_codes from users.id to profiles.id.
 * All methods accept a profile_id (profiles.id) instead of a user_id (users.id).
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
     * Validate a backup code for a profile
     *
     * Checks if the code exists, is unused, and matches the specified version.
     * If valid, marks the code as used.
     *
     * @param int $profileId The profile ID (profiles.id, migration 038)
     * @param string $code The unencrypted backup code to validate
     * @param int $expectedVersion The expected version of the code
     * @return bool True if code is valid and unused, false otherwise
     */
    public function validateCode(int $profileId, string $code, int $expectedVersion): bool
    {
        try {
            // Fetch ALL unused codes for this profile+version. A backup code may
            // be presented in ANY order, so the submitted code must be checked
            // against every candidate hash — not one arbitrary row (the old
            // LIMIT 1 rejected every code except whichever the engine returned
            // first). The code itself cannot be part of the WHERE: it is stored
            // as a bcrypt hash and only password_verify() can match it.
            $result = $this->db->query(
                'SELECT id, code FROM backup_codes
                 WHERE profile_id = :profile_id AND version = :version AND used = false',
                [
                    'profile_id' => $profileId,
                    'version'    => $expectedVersion
                ]
            );

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $result->fetchAll();

            foreach ($rows as $row) {
                if (!isset($row['code']) || !password_verify($code, (string) $row['code'])) {
                    continue;
                }

                // ATOMIC single-use burn: the `AND used = false` predicate means
                // only the request that actually flips the row wins (rowCount 1);
                // a second concurrent request presenting the same code flips
                // nothing (rowCount 0) and is rejected. The DB serialises the
                // UPDATE, so one single-use code can authenticate at most once
                // even under concurrent FrankenPHP workers.
                $update = $this->db->query(
                    'UPDATE backup_codes SET used = true, used_at = NOW() WHERE id = :id AND used = false',
                    ['id' => $row['id']]
                );

                return $update->rowCount() === 1;
            }

            return false;
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
     * @param int $profileId The profile ID (profiles.id, migration 038)
     * @param int $oldVersion The old version to invalidate
     * @return void
     */
    public function invalidateOldCodes(int $profileId, int $oldVersion): void
    {
        $this->db->query(
            'UPDATE backup_codes SET used = true, used_at = NOW()
             WHERE profile_id = ? AND version = ?',
            [$profileId, $oldVersion]
        );
    }

    /**
     * Get the count of available (unused) backup codes for a profile
     *
     * @param int $profileId The profile ID (profiles.id, migration 038)
     * @param int $currentVersion The current backup codes version (optional, for filtering)
     * @return int Count of unused backup codes for current version
     */
    public function getAvailableCodeCount(int $profileId, ?int $currentVersion = null): int
    {
        try {
            if ($currentVersion !== null) {
                $result = $this->db->query(
                    'SELECT COUNT(*) as count FROM backup_codes
                     WHERE profile_id = ? AND used = false AND version = ?',
                    [$profileId, $currentVersion]
                );
            } else {
                $result = $this->db->query(
                    'SELECT COUNT(*) as count FROM backup_codes
                     WHERE profile_id = ? AND used = false',
                    [$profileId]
                );
            }

            $row = $result->fetch(\PDO::FETCH_ASSOC);
            return (int) ($row['count'] ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }
}
