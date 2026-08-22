<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * AddProfileFksToNotificationTables (#751) — gives the two notification tables
 * the profile foreign key they were created without, so deleting a person
 * actually deletes what was written ABOUT them.
 *
 * WHY THIS EXISTS
 * ---------------
 * `notifications.recipient_profile_id` (migration 070) and
 * `user_notification_preferences.profile_id` (migration 071) name a profile and
 * carry no constraint. Neither table opted out of referential integrity — the
 * `tenant_id` column immediately above each of them declares
 * `REFERENCES tenants(id) ON DELETE CASCADE`, and every other core table that
 * points at a profile declares it too (029, 030, 044, and the 037/038/040/041
 * rekeys). One column in each was simply missed.
 *
 * Nothing errors when a profile goes away. The inbox reads
 * `WHERE tenant_id = ? AND recipient_profile_id = ?`, so the surviving rows are
 * not corrupt, they are INVISIBLE — and permanent. That matters here more than
 * it would for a join table, because `notifications.subject` and
 * `notifications.body` are free text ABOUT A SPECIFIC PERSON. On a sovereign
 * deployment, "delete the profile" leaving their notification bodies in the
 * database is a data-protection failure, not an untidy table. The preference
 * rows are the smaller half of the same gap: one row per (profile, type,
 * channel), and nothing ever reclaimed them.
 *
 * ON DELETE: CASCADE ON BOTH, AND WHY NOT `SET NULL`
 * --------------------------------------------------
 * `user_notification_preferences.profile_id` is NOT NULL, so CASCADE is the
 * only available answer and also the right one: a channel toggle belonging to
 * nobody is not a record of anything.
 *
 * `notifications.recipient_profile_id` IS nullable, so `SET NULL` is available
 * — and it is the wrong choice, precisely because it is the tidier-looking one.
 * It keeps the row, which means it keeps the subject and the body: the erasure
 * argument above is about that TEXT, and `SET NULL` preserves every word of it
 * while removing only the pointer that would let anyone find it again. It is
 * also actively misleading. A NULL recipient is already a legal, meaningful
 * state in this schema (a notification with no addressed profile), so nulling
 * the column makes an erased person's private message indistinguishable from an
 * unaddressed one. CASCADE matches what `tenant_id` on the same table has
 * always done, and takes the per-channel `notification_deliveries` rows with it
 * through 070's existing cascade.
 *
 * ORPHANS ARE DELETED, NOT RE-POINTED
 * -----------------------------------
 * Any deployment that has been running (staging included) can hold rows the new
 * constraint rejects, and PostgreSQL validates existing rows when the
 * constraint is added — so an unprepared migration does not warn, it FAILS
 * halfway through a release. The orphans are therefore removed first.
 *
 * They cannot be adopted. Unlike migration 037, which had 035's
 * `migration_035_profile_ids` mapping to re-point through, there is no record
 * of who a deleted profile was; re-pointing would mean handing one person's
 * notification body to another, which is a worse version of the very problem
 * this migration exists to fix. Deleting them is also the outcome the new
 * constraint would have produced had it been there from the start, so this is
 * back-filling an erasure that was supposed to have happened already, not
 * discarding live data. The counts are printed so an operator sees what a
 * release removed.
 *
 * Orphaned `notification_deliveries` are cleaned up in the same step. On
 * PostgreSQL 070's cascade handles them; SQLite only honours ON DELETE under
 * `PRAGMA foreign_keys = ON`, which is off by default, so the sweep is explicit
 * and both engines end up in the same state.
 *
 * ENGINE NOTES
 * ------------
 * PostgreSQL attaches the constraint with `ALTER TABLE … ADD CONSTRAINT`.
 * `ADD CONSTRAINT` has no `IF NOT EXISTS`, so — as in migration 104 — it is
 * preceded by `DROP CONSTRAINT IF EXISTS`, which makes a re-run a no-op instead
 * of a duplicate-object error.
 *
 * SQLite (the test-schema engine — see {@see \Tests\Support\SchemaFromMigrations})
 * cannot attach a constraint to an existing table at all, so the table is
 * rebuilt, exactly as migrations 093/094 rebuild `roles` and `memberships`. The
 * replacement DDL is READ BACK from `sqlite_master` and edited rather than
 * retyped: a hand-copied column list is a second definition of the table that
 * drifts from 070 silently, and the whole point of running the migrations to
 * build the test schema is that there is only one. `legacy_alter_table = ON` is
 * essential during the rename — with it off, SQLite helpfully rewrites
 * `notification_deliveries`' `REFERENCES notifications(id)` to point at the
 * scratch table this migration then drops. That pragma is NOT sufficient on its
 * own, though: SQLite rewrites those clauses anyway whenever foreign keys are
 * enforced, so enforcement is suspended around the rebuild as well — outside
 * the transaction, where the pragma is not a no-op. See
 * {@see suspendSqliteForeignKeys()}.
 *
 * Idempotent on both engines, and reversible via down().
 */
class AddProfileFksToNotificationTables
{
    /** The tables fixed here, as table => the column that names a profile. */
    private const PROFILE_COLUMNS = [
        'notifications' => 'recipient_profile_id',
        'user_notification_preferences' => 'profile_id',
    ];

    /** Scratch name suffix for the outgoing table on the SQLite rebuild path. */
    private const SQLITE_SCRATCH_SUFFIX = '_pre_105';

    public static function up(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // MUST happen before the transaction opens — see the method's own note.
        $foreignKeysWereOn = self::suspendSqliteForeignKeys($pdo, $driver);
        $ownTransaction    = !$pdo->inTransaction();

        try {
            if ($ownTransaction) {
                $pdo->beginTransaction();
            }

            // 1. Remove what the constraint would reject. MUST precede it:
            //    PostgreSQL validates existing rows as it attaches the key.
            self::deleteOrphans($db);

            // 2. Attach the constraint. Spelled out per table rather than built
            //    from PROFILE_COLUMNS, because scripts/ci-undeclared-reference-guard.php
            //    reads migration SOURCE: an `ALTER TABLE {$interpolated}` installs a
            //    foreign key the guard cannot see, and being unable to see this exact
            //    edge is what #751 is about. Drop-then-add because `ADD CONSTRAINT`
            //    has no `IF NOT EXISTS` and a re-run must be a no-op rather than a
            //    duplicate-object error.
            if ($driver === 'pgsql') {
                $db->exec(
                    'ALTER TABLE notifications
                        DROP CONSTRAINT IF EXISTS notifications_recipient_profile_id_fkey'
                );
                $db->exec(
                    'ALTER TABLE notifications
                        ADD CONSTRAINT notifications_recipient_profile_id_fkey
                        FOREIGN KEY (recipient_profile_id) REFERENCES profiles(id) ON DELETE CASCADE'
                );

                $db->exec(
                    'ALTER TABLE user_notification_preferences
                        DROP CONSTRAINT IF EXISTS user_notification_preferences_profile_id_fkey'
                );
                $db->exec(
                    'ALTER TABLE user_notification_preferences
                        ADD CONSTRAINT user_notification_preferences_profile_id_fkey
                        FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE'
                );
            } else {
                foreach (self::PROFILE_COLUMNS as $table => $column) {
                    self::rebuildSqliteTable($db, $table, $column, withForeignKey: true);
                }
            }

            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            self::restoreSqliteForeignKeys($pdo, $driver, $foreignKeysWereOn);
        }
    }

    /**
     * Detach the constraint again.
     *
     * WARNING: the rows up() deleted are NOT restored. The profiles they named
     * are gone, so there is nothing to restore them to — down() takes the
     * schema back; it cannot take back an erasure.
     */
    public static function down(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $foreignKeysWereOn = self::suspendSqliteForeignKeys($pdo, $driver);
        $ownTransaction    = !$pdo->inTransaction();

        try {
            if ($ownTransaction) {
                $pdo->beginTransaction();
            }

            if ($driver === 'pgsql') {
                $db->exec(
                    'ALTER TABLE notifications
                        DROP CONSTRAINT IF EXISTS notifications_recipient_profile_id_fkey'
                );
                $db->exec(
                    'ALTER TABLE user_notification_preferences
                        DROP CONSTRAINT IF EXISTS user_notification_preferences_profile_id_fkey'
                );
            } else {
                foreach (self::PROFILE_COLUMNS as $table => $column) {
                    self::rebuildSqliteTable($db, $table, $column, withForeignKey: false);
                }
            }

            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            self::restoreSqliteForeignKeys($pdo, $driver, $foreignKeysWereOn);
        }
    }

    // ── SQLite foreign-key enforcement ──────────────────────────────────────

    /**
     * Turn SQLite's foreign-key enforcement off for the duration of the
     * rebuild, reporting whether it had been on.
     *
     * This is not belt-and-braces, it is required, and the reason is a genuine
     * trap. `PRAGMA legacy_alter_table = ON` stops `ALTER TABLE … RENAME` from
     * rewriting other objects to follow the rename — EXCEPT that SQLite always
     * updates other tables' `REFERENCES` clauses when foreign keys are enabled,
     * legacy mode or not. With enforcement on, renaming `notifications` out of
     * the way therefore re-points `notification_deliveries` at the scratch
     * table, which this migration then drops, and the child is left naming a
     * table that does not exist. It does not fail at the rename: it fails later,
     * on the next statement that touches the child.
     *
     * And it MUST be done before the transaction opens. `PRAGMA foreign_keys`
     * is documented as a no-op inside one, so a suspension attempted alongside
     * the rebuild silently does nothing at all — the shape of this bug that is
     * hardest to see, because the pragma statement executes without complaint.
     */
    private static function suspendSqliteForeignKeys(PDO $pdo, string $driver): bool
    {
        if ($driver === 'pgsql') {
            return false;
        }

        $statement = $pdo->query('PRAGMA foreign_keys');
        $wasOn = $statement !== false && (int) $statement->fetchColumn() === 1;

        if ($wasOn) {
            $pdo->exec('PRAGMA foreign_keys = OFF');
        }

        return $wasOn;
    }

    /** Restore what {@see suspendSqliteForeignKeys()} turned off. */
    private static function restoreSqliteForeignKeys(PDO $pdo, string $driver, bool $wasOn): void
    {
        if ($driver !== 'pgsql' && $wasOn) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    // ── Orphan cleanup ──────────────────────────────────────────────────────

    /**
     * Delete every row naming a profile that no longer exists.
     *
     * A correlated NOT EXISTS rather than `NOT IN (SELECT id FROM profiles)`:
     * the two differ the moment the subquery can yield a NULL, and a `NOT IN`
     * that quietly matches nothing is exactly the kind of silence this whole
     * issue is about.
     */
    private static function deleteOrphans(Database $db): void
    {
        foreach (self::PROFILE_COLUMNS as $table => $column) {
            // `notifications.recipient_profile_id` is nullable and NULL is a
            // legal value (an unaddressed notification), so it is not an orphan.
            $removed = $db->exec(
                "DELETE FROM {$table}
                  WHERE {$column} IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM profiles p WHERE p.id = {$table}.{$column})"
            );

            if ($removed > 0) {
                self::log("deleted {$removed} orphaned row(s) from {$table} naming a profile that no longer exists.");
            }
        }

        // Deliveries of a notification that has just gone. PostgreSQL cascaded
        // them via 070's foreign key; SQLite honours ON DELETE only under
        // `PRAGMA foreign_keys = ON`, so make it explicit and let both engines
        // finish in the same state.
        $strays = $db->exec(
            'DELETE FROM notification_deliveries
              WHERE NOT EXISTS (
                    SELECT 1 FROM notifications n WHERE n.id = notification_deliveries.notification_id
              )'
        );

        if ($strays > 0) {
            self::log("deleted {$strays} orphaned notification_deliveries row(s).");
        }
    }

    // ── SQLite rebuild ──────────────────────────────────────────────────────

    /**
     * Rebuild one table with (or without) the profile foreign key.
     *
     * The replacement DDL is the table's OWN `sqlite_master` definition with a
     * table-level `FOREIGN KEY` clause added or removed, so this cannot drift
     * from the migration that created the table. Both directions strip the
     * clause first, which is what makes a re-run of either up() or down() a
     * no-op rather than a table with the constraint declared twice.
     */
    private static function rebuildSqliteTable(
        Database $db,
        string $table,
        string $column,
        bool $withForeignKey
    ): void {
        $pdo = $db->getPdo();

        $ddl = $db->query(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name",
            [':name' => $table]
        )->fetchColumn();

        if (!is_string($ddl) || $ddl === '') {
            return; // The table is not there; nothing to rebuild.
        }

        $target = self::sqliteDdl($ddl, $column, $withForeignKey);
        if ($target === $ddl) {
            return; // Already in the requested shape.
        }

        // Captured BEFORE the rename: dropping the outgoing table takes its
        // indexes with it, and these have to be put back by hand. `sql IS NULL`
        // is an internal autoindex backing a UNIQUE/PK, which comes back with
        // the table definition itself.
        $indexes = $db->query(
            "SELECT sql FROM sqlite_master
              WHERE type = 'index' AND tbl_name = :name AND sql IS NOT NULL",
            [':name' => $table]
        )->fetchAll(PDO::FETCH_COLUMN);

        $columns = implode(', ', array_map(
            static fn (array $c): string => (string) $c['name'],
            $db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC)
        ));

        $scratch = $table . self::SQLITE_SCRATCH_SUFFIX;

        // With legacy_alter_table OFF, the RENAME makes SQLite rewrite every
        // OTHER table's `REFERENCES {$table}(…)` to name the scratch table —
        // which this migration then drops.
        $pdo->exec('PRAGMA legacy_alter_table = ON');

        try {
            $pdo->exec('DROP TABLE IF EXISTS ' . $scratch);
            $pdo->exec('ALTER TABLE ' . $table . ' RENAME TO ' . $scratch);

            $pdo->exec($target);
            $pdo->exec("INSERT INTO {$table} ({$columns}) SELECT {$columns} FROM {$scratch}");
            $pdo->exec('DROP TABLE ' . $scratch);

            foreach ($indexes as $indexDdl) {
                $pdo->exec((string) $indexDdl);
            }
        } finally {
            $pdo->exec('PRAGMA legacy_alter_table = OFF');
        }
    }

    /**
     * The table's DDL with the profile foreign key present or absent.
     *
     * The clause is always stripped before it is (re-)added, so the function is
     * idempotent in both directions. It is inserted before the definition's
     * final `)`, which is the table's own closer whatever the last column looks
     * like — `DEFAULT (datetime('now'))` included, since a nested paren is
     * always closed before it.
     */
    private static function sqliteDdl(string $ddl, string $column, bool $withForeignKey): string
    {
        $clause = "FOREIGN KEY ({$column}) REFERENCES profiles(id) ON DELETE CASCADE";

        $stripped = preg_replace(
            '/,\s*' . preg_quote($clause, '/') . '/i',
            '',
            $ddl
        ) ?? $ddl;

        if (!$withForeignKey) {
            return $stripped;
        }

        $close = strrpos($stripped, ')');
        if ($close === false) {
            return $stripped; // Not a shape this can edit; leave it alone.
        }

        return substr($stripped, 0, $close) . ",\n    " . $clause . "\n" . substr($stripped, $close);
    }

    /**
     * Emit an operator-facing migration notice.
     *
     * STDOUT via echo, the convention of migrations 010/035/037; the test
     * harness silences it with ob_start().
     */
    private static function log(string $message): void
    {
        echo '[migration 105] ' . $message . "\n";
    }
}
