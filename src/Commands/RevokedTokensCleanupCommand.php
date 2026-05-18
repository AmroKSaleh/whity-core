<?php

namespace Whity\Commands;

use PDO;

/**
 * Cron Command: Cleanup Revoked Tokens
 *
 * Deletes expired revocation entries from the revoked_tokens table.
 * Designed to be run as a cron job to keep the table small and fast.
 *
 * Usage:
 *   php public/index.php revoked-tokens:cleanup
 *
 * Cron Schedule:
 *   0 2 * * * php /var/www/whity/public/index.php revoked-tokens:cleanup
 *   (Runs daily at 2:00 AM UTC, off-peak time)
 */
class RevokedTokensCleanupCommand
{
    private PDO $db;

    /**
     * Constructor
     *
     * @param PDO $db Database connection
     */
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Execute the cleanup command
     *
     * Deletes all revoked token entries where expires_at is in the past.
     * Outputs the count of deleted entries.
     */
    public function execute(): void
    {
        try {
            // Delete all expired revocation entries
            $stmt = $this->db->prepare('DELETE FROM revoked_tokens WHERE expires_at < NOW()');
            $stmt->execute();

            $count = $stmt->rowCount();
            echo "Cleaned {$count} expired revocation entries\n";
        } catch (\Exception $e) {
            echo "Error cleaning expired revoked tokens: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}
