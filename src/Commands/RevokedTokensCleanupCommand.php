<?php

declare(strict_types=1);

namespace Whity\Commands;

use PDO;

/**
 * Cron Command: Cleanup Revoked Tokens
 *
 * Deletes expired revocation entries from the revoked_tokens table.
 * Designed to be run as a cron job to keep the table small and fast.
 *
 * Retention policy: a revocation row only needs to outlive the token it
 * revokes. Once expires_at is in the past the underlying token is dead anyway
 * (the epoch/exp checks reject it without consulting this table), so the row is
 * safe to delete. This job prunes those expired rows daily, so the table holds
 * only not-yet-expired revocations plus any recently-expired rows still awaiting
 * the next cron pass. See docs/wiki/Cron-Operations.md for scheduling.
 *
 * revoked_tokens is a sanctioned GLOBAL (non-tenant-scoped) table — a jti is
 * unique platform-wide, so this DELETE intentionally carries no tenant_id
 * predicate. See {@see \Whity\Core\Tenant\SanctionedGlobalTables}.
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
            // Delete all expired revocation entries. CURRENT_TIMESTAMP is
            // standard SQL — identical to NOW() on PostgreSQL and, unlike NOW(),
            // also evaluated by SQLite — so the real-engine test runs the SAME
            // delete locally (SQLite) and in CI (PostgreSQL). expires_at is
            // written as a UTC 'Y-m-d H:i:s' literal and CURRENT_TIMESTAMP yields
            // the same UTC format on SQLite, so the string comparison is correct.
            $stmt = $this->db->prepare('DELETE FROM revoked_tokens WHERE expires_at < CURRENT_TIMESTAMP');
            $stmt->execute();

            $count = $stmt->rowCount();
            echo "Cleaned {$count} expired revocation entries\n";
        } catch (\Exception $e) {
            echo "Error cleaning expired revoked tokens: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}
