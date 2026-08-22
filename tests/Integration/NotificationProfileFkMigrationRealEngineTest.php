<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Migrations\AddProfileFksToNotificationTables;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Database\Database;

/**
 * #751 — migration 105 gives `notifications.recipient_profile_id` and
 * `user_notification_preferences.profile_id` the profile foreign key they were
 * created without.
 *
 * Runs on BOTH engines from one file: the SQLite path when the default suite
 * runs it, real PostgreSQL when PHPUNIT_PG_DSN is set (the postgres job). That
 * matters more than usual here — SQLite cannot attach a constraint to an
 * existing table, so the two engines take completely different code paths
 * through the migration (`ALTER TABLE … ADD CONSTRAINT` versus a table rebuild),
 * and only one of them is exercised by any single run.
 *
 * The load-bearing case is ORPHANS. A deployment that has been running holds
 * rows the new constraint rejects, and PostgreSQL validates existing rows as it
 * attaches a foreign key — so a migration that has not dealt with them does not
 * warn, it fails partway through a release. `up()` is therefore re-run here
 * against a schema that has been put back into its pre-105 shape and then
 * deliberately polluted, which is the only way to test the branch that a fresh
 * database never reaches.
 */
final class NotificationProfileFkMigrationRealEngineTest extends TestCase
{
    private const TENANT = 7;
    private const PROFILE = 4001;

    private PDO $pdo;

    private Database $db;

    /**
     * Load the migration class explicitly.
     *
     * Migration files are not in the PSR-4 map — they are `require_once`d by
     * {@see SchemaFromMigrations} as it runs them. On PostgreSQL the schema
     * usually comes from a CLONE of a cached template, so the migrations never
     * run in this process and the class is never loaded. A test that names a
     * migration class therefore has to require it itself, or it dies with
     * "Class … not found" on the postgres job while passing on SQLite.
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2)
            . '/database/migrations/105_add_profile_fks_to_notification_tables.php';
    }

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->db = Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $this->db->forceConnect();

        // SQLite honours ON DELETE only under this pragma, and it is off by
        // default — without it the cascade tests below would pass on PostgreSQL
        // and silently prove nothing on the engine the unit job runs.
        if ($this->driver() !== 'pgsql') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }

        $this->pdo->exec(
            "INSERT INTO tenants (id, name, slug) VALUES (" . self::TENANT . ", 'fk-test', 'fk-test')"
        );
        $this->pdo->exec(
            'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                   two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (' . self::PROFILE . ", 'recipient', 'hash', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        SchemaFromMigrations::syncSequences($this->pdo);
    }

    // ── the constraint exists, and cascades ─────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fkProvider(): array
    {
        return [
            'notifications' => ['notifications', 'recipient_profile_id'],
            'preferences' => ['user_notification_preferences', 'profile_id'],
        ];
    }

    /**
     * @dataProvider fkProvider
     */
    public function testColumnCarriesAForeignKeyToProfiles(string $table, string $column): void
    {
        self::assertNotNull(
            $this->foreignKeyOn($table, $column),
            "{$table}.{$column} must reference profiles(id) after migration 105 — #751."
        );
    }

    /**
     * @dataProvider fkProvider
     */
    public function testTheForeignKeyCascadesRatherThanNullingOrRefusing(string $table, string $column): void
    {
        self::assertSame(
            'CASCADE',
            $this->foreignKeyOn($table, $column),
            "{$table}.{$column} must be ON DELETE CASCADE. SET NULL would keep the row — and for "
            . 'notifications that means keeping the subject and body written about a person who '
            . 'has been deleted, which is the data-protection failure #751 is about.'
        );
    }

    public function testDeletingAProfileDeletesTheNotificationBodyWrittenAboutThem(): void
    {
        $this->insertNotification(1, self::PROFILE, 'Payslip ready', 'Your March payslip is attached.');
        $this->insertPreference(1, self::PROFILE);

        $this->pdo->exec('DELETE FROM profiles WHERE id = ' . self::PROFILE);

        self::assertSame(0, $this->countWhere('notifications', 'recipient_profile_id', self::PROFILE));
        self::assertSame(0, $this->countWhere('user_notification_preferences', 'profile_id', self::PROFILE));
    }

    public function testDeletingAProfileDoesNotTouchAnUnaddressedNotification(): void
    {
        // A NULL recipient is a legal state, which is exactly why SET NULL would
        // be wrong: it makes an erased person's message look like this one.
        $this->insertNotification(2, null, 'Maintenance window', 'The system will be offline.');
        $this->insertNotification(3, self::PROFILE, 'Payslip ready', 'Your March payslip is attached.');

        $this->pdo->exec('DELETE FROM profiles WHERE id = ' . self::PROFILE);

        self::assertSame(1, $this->rowCount('notifications'));
        self::assertSame('Maintenance window', $this->subjectOf(2));
    }

    public function testAnInsertNamingAProfileThatDoesNotExistIsRefused(): void
    {
        $this->expectException(\PDOException::class);
        $this->insertNotification(4, 999999, 'nobody', 'nobody');
    }

    // ── orphans, idempotency, reversibility ─────────────────────────────────

    /**
     * The case a fresh database never reaches: rows the constraint rejects were
     * already there when the release ran.
     */
    public function testUpDeletesPreExistingOrphansAndStillInstallsTheConstraint(): void
    {
        $this->toPre105State();

        $this->insertNotification(10, self::PROFILE, 'kept', 'still has a profile');
        $this->insertNotification(11, 888888, 'orphan', 'body about a deleted person');
        $this->insertNotification(12, null, 'unaddressed', 'no recipient at all');
        $this->insertDelivery(500, 11);
        $this->insertPreference(10, self::PROFILE);
        $this->insertPreference(11, 888888);

        $this->runUp();

        self::assertSame(0, $this->countWhere('notifications', 'recipient_profile_id', 888888),
            'the orphaned notification — body included — must be gone.');
        self::assertSame(0, $this->countWhere('user_notification_preferences', 'profile_id', 888888),
            'the orphaned preference row must be gone.');
        self::assertSame(0, $this->rowCount('notification_deliveries'),
            'the orphan notification\'s deliveries must go with it on both engines.');

        self::assertSame(2, $this->rowCount('notifications'), 'the valid and unaddressed rows must survive.');
        self::assertSame(1, $this->rowCount('user_notification_preferences'));

        self::assertSame('CASCADE', $this->foreignKeyOn('notifications', 'recipient_profile_id'));
        self::assertSame('CASCADE', $this->foreignKeyOn('user_notification_preferences', 'profile_id'));
    }

    public function testUpIsIdempotent(): void
    {
        $this->insertNotification(20, self::PROFILE, 'kept', 'kept');
        $this->insertPreference(20, self::PROFILE);

        $this->runUp();
        $this->runUp();

        self::assertSame(1, $this->rowCount('notifications'), 'a re-run must not delete valid rows.');
        self::assertSame(1, $this->rowCount('user_notification_preferences'));
        self::assertSame(1, $this->foreignKeyCount('notifications', 'recipient_profile_id'),
            'a re-run must not leave the constraint declared twice.');
        self::assertSame(1, $this->foreignKeyCount('user_notification_preferences', 'profile_id'));
    }

    public function testDownRemovesTheConstraintAndUpPutsItBack(): void
    {
        $this->toPre105State();

        self::assertNull($this->foreignKeyOn('notifications', 'recipient_profile_id'));
        self::assertNull($this->foreignKeyOn('user_notification_preferences', 'profile_id'));

        $this->runUp();

        self::assertSame('CASCADE', $this->foreignKeyOn('notifications', 'recipient_profile_id'));
        self::assertSame('CASCADE', $this->foreignKeyOn('user_notification_preferences', 'profile_id'));
    }

    public function testDownIsIdempotentAndPreservesRows(): void
    {
        $this->insertNotification(30, self::PROFILE, 'kept', 'kept');
        $this->insertPreference(30, self::PROFILE);

        $this->toPre105State();
        $this->toPre105State();

        self::assertNull($this->foreignKeyOn('notifications', 'recipient_profile_id'));
        self::assertSame(1, $this->rowCount('notifications'));
        self::assertSame(1, $this->rowCount('user_notification_preferences'));
    }

    /**
     * The rebuild must not cost the table its indexes — the inbox listing, the
     * partial unread index, and the preference upsert's UNIQUE all live on
     * these two tables, and a rebuild that silently dropped them would turn a
     * schema fix into a performance regression and a lost constraint.
     */
    public function testTheIndexesSurviveTheMigration(): void
    {
        $this->toPre105State();
        $this->runUp();

        foreach ([
            'notifications' => ['idx_notifications_inbox', 'idx_notifications_unread', 'idx_notifications_tenant'],
            'user_notification_preferences' => [
                'uq_user_notification_preferences',
                'idx_user_notification_preferences_profile',
            ],
        ] as $table => $expected) {
            $present = $this->indexNames($table);
            foreach ($expected as $index) {
                self::assertContains($index, $present, "{$index} must survive migration 105.");
            }
        }

        // The UNIQUE has to still REFUSE, not merely exist.
        $this->insertPreference(40, self::PROFILE);
        $this->expectException(\PDOException::class);
        $this->insertPreference(41, self::PROFILE);
    }

    public function testTheChildCascadeFromNotificationsIsNotRewrittenByTheRebuild(): void
    {
        $this->toPre105State();
        $this->runUp();

        $this->insertNotification(50, self::PROFILE, 'kept', 'kept');
        $this->insertDelivery(600, 50);

        $this->pdo->exec('DELETE FROM profiles WHERE id = ' . self::PROFILE);

        self::assertSame(
            0,
            $this->rowCount('notification_deliveries'),
            'deleting the profile must take the notification and, through 070\'s own cascade, its '
            . 'deliveries — proving the SQLite rebuild did not re-point that child key at the '
            . 'scratch table.'
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Put the schema back the way it was before migration 105 ran, so up() can
     * be exercised against it.
     */
    private function toPre105State(): void
    {
        $this->silently(static function (Database $db): void {
            AddProfileFksToNotificationTables::down($db);
        });
    }

    private function runUp(): void
    {
        $this->silently(static function (Database $db): void {
            AddProfileFksToNotificationTables::up($db);
        });
    }

    /**
     * Run a migration entry point with its operator notices captured.
     *
     * up() prints the orphan counts it removed, and phpunit.xml sets
     * beStrictAboutOutputDuringTests — the loader wraps the whole migration run
     * in ob_start() for the same reason.
     *
     * @param callable(Database): void $work
     */
    private function silently(callable $work): void
    {
        ob_start();
        try {
            $work($this->db);
        } finally {
            ob_end_clean();
        }
    }

    private function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * The ON DELETE action of the foreign key from `$table.$column` to
     * `profiles`, or null when there is no such key.
     */
    private function foreignKeyOn(string $table, string $column): ?string
    {
        foreach ($this->profileForeignKeys($table, $column) as $action) {
            return $action;
        }

        return null;
    }

    private function foreignKeyCount(string $table, string $column): int
    {
        return count($this->profileForeignKeys($table, $column));
    }

    /**
     * Every foreign key from `$table.$column` to `profiles`, as its ON DELETE
     * action. A list rather than a single value so a duplicated constraint —
     * the way a non-idempotent re-run fails — is visible rather than hidden
     * behind "a key exists".
     *
     * @return list<string>
     */
    private function profileForeignKeys(string $table, string $column): array
    {
        if ($this->driver() === 'pgsql') {
            $stmt = $this->pdo->prepare(
                "SELECT rc.delete_rule
                   FROM information_schema.table_constraints tc
                   JOIN information_schema.key_column_usage kcu
                     ON kcu.constraint_name = tc.constraint_name
                    AND kcu.constraint_schema = tc.constraint_schema
                   JOIN information_schema.constraint_column_usage ccu
                     ON ccu.constraint_name = tc.constraint_name
                    AND ccu.constraint_schema = tc.constraint_schema
                   JOIN information_schema.referential_constraints rc
                     ON rc.constraint_name = tc.constraint_name
                    AND rc.constraint_schema = tc.constraint_schema
                  WHERE tc.constraint_type = 'FOREIGN KEY'
                    AND tc.table_schema = current_schema()
                    AND tc.table_name = :table
                    AND kcu.column_name = :column
                    AND ccu.table_name = 'profiles'"
            );
            $stmt->execute([':table' => $table, ':column' => $column]);

            return array_map('strtoupper', array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }

        $rows = $this->pdo->query('PRAGMA foreign_key_list(' . $table . ')');
        if ($rows === false) {
            return [];
        }

        $actions = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $fk) {
            if (
                strcasecmp((string) ($fk['table'] ?? ''), 'profiles') === 0
                && strcasecmp((string) ($fk['from'] ?? ''), $column) === 0
            ) {
                $actions[] = strtoupper((string) ($fk['on_delete'] ?? ''));
            }
        }

        return $actions;
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        if ($this->driver() === 'pgsql') {
            $stmt = $this->pdo->prepare(
                'SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = :t'
            );
            $stmt->execute([':t' => $table]);

            return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $stmt = $this->pdo->prepare(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = :t"
        );
        $stmt->execute([':t' => $table]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function insertNotification(int $id, ?int $profileId, string $subject, string $body): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (id, tenant_id, recipient_profile_id, type, subject, body)
             VALUES (:id, :tenant, :profile, :type, :subject, :body)'
        );
        $stmt->execute([
            ':id' => $id,
            ':tenant' => self::TENANT,
            ':profile' => $profileId,
            ':type' => 'test.event',
            ':subject' => $subject,
            ':body' => $body,
        ]);
    }

    private function insertDelivery(int $id, int $notificationId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notification_deliveries (id, tenant_id, notification_id, channel)
             VALUES (:id, :tenant, :notification, :channel)'
        );
        $stmt->execute([
            ':id' => $id,
            ':tenant' => self::TENANT,
            ':notification' => $notificationId,
            ':channel' => 'in_app',
        ]);
    }

    private function insertPreference(int $id, int $profileId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_notification_preferences (id, tenant_id, profile_id, type, channel, enabled)
             VALUES (:id, :tenant, :profile, :type, :channel, :enabled)'
        );
        $stmt->execute([
            ':id' => $id,
            ':tenant' => self::TENANT,
            ':profile' => $profileId,
            ':type' => '*',
            ':channel' => 'email',
            ':enabled' => $this->driver() === 'pgsql' ? 'true' : 1,
        ]);
    }

    private function rowCount(string $table): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM ' . $table);

        return $stmt === false ? -1 : (int) $stmt->fetchColumn();
    }

    private function countWhere(string $table, string $column, int $value): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :v");
        $stmt->execute([':v' => $value]);

        return (int) $stmt->fetchColumn();
    }

    private function subjectOf(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT subject FROM notifications WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return (string) $stmt->fetchColumn();
    }
}
