<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use RuntimeException;
use Whity\Database\Database;

/**
 * AddIsPrimaryToMemberships — names the ONE membership row that answers "what is this profile here?", and
 * moves the uniqueness rule onto it.
 *
 * WHY THIS EXISTS
 * ---------------
 * `memberships` carries `UNIQUE(profile_id, tenant_id)` (migration 030), so a
 * profile has at most one row per tenant and every single-row read is
 * unambiguous by construction. #712 §1 wants a profile to hold more than one
 * tenant-wide role, which means more than one row — and the moment that is
 * possible, this becomes undefined:
 *
 *     SELECT role_id, ou_id, status FROM memberships
 *      WHERE profile_id = :p AND tenant_id = :t
 *      LIMIT 1                    -- no ORDER BY
 *
 * ({@see \Whity\Auth\RoleChecker::getMembershipRow()}.) With one row that is
 * correct. With two it returns whichever the query plan reaches first, so "what
 * is my role here?" and "which OU am I in?" answer differently between runs,
 * silently, across every read path that consumes them.
 *
 * WHAT THIS MIGRATION DOES, AND DELIBERATELY DOES NOT DO
 * -----------------------------------------------------
 * It adds `is_primary` and replaces the table-wide unique constraint with a
 * PARTIAL unique index over the primary rows only:
 *
 *     UNIQUE (profile_id, tenant_id) WHERE is_primary
 *
 * Every existing row is backfilled `true`, and nothing in the codebase writes a
 * non-primary row yet. So after this migration the database still permits
 * exactly one membership per (profile, tenant) — the rule is unchanged in
 * effect, only relocated onto a column that says which row wins.
 *
 * That is the entire point of splitting it out. Allowing a second row is not a
 * schema change but a reporting change: 22 of the 56 membership queries in
 * `src/` count or join memberships as if they were people. `AdminApiHandler`'s
 * user totals, the per-tenant counts, and the admin user LIST (which has no
 * DISTINCT and paginates with LIMIT/OFFSET over the join) would each start
 * double-counting a two-role user — returning wrong numbers rather than
 * failing. Those are fixed before the constraint is relaxed, not after.
 *
 * ENGINE NOTES
 * ------------
 * PostgreSQL names 030's table-level constraint `memberships_profile_id_tenant_id_key`
 * and drops it with a catalogue edit. SQLite (the test-schema engine — see
 * {@see \Tests\Support\SchemaFromMigrations}) backs a table-level UNIQUE with an
 * internal `sqlite_autoindex_…` that no `DROP INDEX` can reach, so the table is
 * rebuilt, exactly as migration 093 rebuilds `roles`. `legacy_alter_table = ON`
 * is essential during the rename: with it off, SQLite rewrites the
 * `REFERENCES memberships(...)` clauses of other tables to point at the scratch
 * table this migration then drops.
 */
class AddIsPrimaryToMemberships
{
    /** The partial unique index that replaces the table-wide constraint. */
    private const IDX_PRIMARY = 'uq_memberships_primary';

    /** PostgreSQL's auto-generated name for migration 030's UNIQUE. */
    private const LEGACY_CONSTRAINT = 'memberships_profile_id_tenant_id_key';

    /** Scratch name for the outgoing table on the SQLite rebuild path. */
    private const SQLITE_LEGACY_TABLE = 'memberships_pre_094';

    public static function up(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // 1. The column, defaulted true so every existing row is primary.
            //    A restored dump that already carries it is left alone.
            if (!self::hasIsPrimary($db, $driver)) {
                if ($driver === 'pgsql') {
                    $db->exec(
                        'ALTER TABLE memberships
                            ADD COLUMN IF NOT EXISTS is_primary BOOLEAN NOT NULL DEFAULT TRUE'
                    );
                } else {
                    $db->exec(
                        "ALTER TABLE memberships
                            ADD COLUMN is_primary INTEGER NOT NULL DEFAULT 1"
                    );
                }
            }

            // 2. Belt and braces: a dump could carry the column with NULLs.
            $db->exec('UPDATE memberships SET is_primary = ' . self::trueLiteral($driver)
                . ' WHERE is_primary IS NULL');

            // 3. Drop the table-wide rule. On PostgreSQL a catalogue edit; on
            //    SQLite the whole table must be rebuilt.
            if ($driver === 'pgsql') {
                $db->exec('ALTER TABLE memberships DROP CONSTRAINT IF EXISTS ' . self::LEGACY_CONSTRAINT);
                // A hand-patched schema may carry it as a bare index instead.
                $db->exec('DROP INDEX IF EXISTS ' . self::LEGACY_CONSTRAINT);
            } else {
                self::rebuildMembershipsWithoutPairUnique($db);
            }

            // 4. Install the replacement, scoped to the primary rows. Until
            //    something writes a non-primary row this is exactly as strict
            //    as what it replaced.
            $db->exec(
                'CREATE UNIQUE INDEX IF NOT EXISTS ' . self::IDX_PRIMARY
                . ' ON memberships (profile_id, tenant_id) WHERE is_primary'
            );

            // 5. Secondary rows will be read by profile+tenant constantly once
            //    they exist; 030's single-column indexes do not cover the pair.
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_memberships_profile_tenant
                    ON memberships (profile_id, tenant_id)'
            );

            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Restore the table-wide uniqueness rule.
     *
     * Only reversible while no profile actually holds a second membership in a
     * tenant — the very thing this migration exists to make possible. Rather
     * than deleting a live grant to force the old constraint back on, this
     * refuses and names the profiles involved, so removing someone's second
     * role stays a decision an operator makes rather than a side effect of a
     * rollback.
     */
    public static function down(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $offenders = $db->query(
            'SELECT profile_id, tenant_id, COUNT(*) AS n
               FROM memberships
              GROUP BY profile_id, tenant_id
             HAVING COUNT(*) > 1
              ORDER BY profile_id, tenant_id'
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($offenders !== []) {
            $pairs = implode(', ', array_map(
                static fn (array $r): string => "(profile {$r['profile_id']}, tenant {$r['tenant_id']})",
                array_slice($offenders, 0, 10)
            ));
            throw new RuntimeException(
                'Cannot roll back 094: ' . count($offenders) . ' profile/tenant pair(s) hold more than '
                . 'one membership, which the restored constraint forbids: ' . $pairs
                . '. Remove the extra memberships first — this migration will not choose which '
                . 'role somebody loses.'
            );
        }

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $db->exec('DROP INDEX IF EXISTS ' . self::IDX_PRIMARY);
            $db->exec('DROP INDEX IF EXISTS idx_memberships_profile_tenant');

            if ($driver === 'pgsql') {
                $db->exec(
                    'ALTER TABLE memberships ADD CONSTRAINT ' . self::LEGACY_CONSTRAINT
                    . ' UNIQUE (profile_id, tenant_id)'
                );
                $db->exec('ALTER TABLE memberships DROP COLUMN IF EXISTS is_primary');
            } else {
                self::rebuildMembershipsWithoutPairUnique($db, withPairUnique: true);
            }

            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Whether the column is already present (a re-run, or a restored dump). */
    private static function hasIsPrimary(Database $db, string $driver): bool
    {
        if ($driver === 'pgsql') {
            $row = $db->query(
                "SELECT 1 FROM information_schema.columns
                  WHERE table_name = 'memberships' AND column_name = 'is_primary'"
            )->fetchColumn();

            return $row !== false;
        }

        foreach ($db->query('PRAGMA table_info(memberships)')->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if (($col['name'] ?? '') === 'is_primary') {
                return true;
            }
        }

        return false;
    }

    /** SQLite has no boolean type; the rest of this schema stores 1/0. */
    private static function trueLiteral(string $driver): string
    {
        return $driver === 'pgsql' ? 'TRUE' : '1';
    }

    // ── SQLite rebuild ──────────────────────────────────────────────────────

    /**
     * Rebuild `memberships` so the table-level `UNIQUE(profile_id, tenant_id)`
     * is dropped (or, for `down()`, restored). SQLite backs that constraint
     * with an internal autoindex no `DROP INDEX` can name.
     */
    private static function rebuildMembershipsWithoutPairUnique(
        Database $db,
        bool $withPairUnique = false
    ): void {
        $pdo = $db->getPdo();

        // With legacy_alter_table OFF, the RENAME below makes SQLite rewrite
        // every OTHER table's `REFERENCES memberships(...)` to point at the
        // scratch table — which this migration then drops.
        $pdo->exec('PRAGMA legacy_alter_table = ON');

        try {
            $pdo->exec('DROP TABLE IF EXISTS ' . self::SQLITE_LEGACY_TABLE);
            $pdo->exec('ALTER TABLE memberships RENAME TO ' . self::SQLITE_LEGACY_TABLE);

            // `IF NOT EXISTS` cannot fire — the RENAME above just vacated the
            // name — but every table creation in this directory carries the
            // guard and a migration-wide idempotency gate enforces that
            // uniformly rather than reasoning case by case.
            $pairUnique = $withPairUnique ? ",\n                    UNIQUE (profile_id, tenant_id)" : '';
            $isPrimary  = $withPairUnique ? '' : "\n                    is_primary INTEGER NOT NULL DEFAULT 1,";

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS memberships (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    profile_id INTEGER     NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                    tenant_id  INTEGER     NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                    role_id    INTEGER     NOT NULL REFERENCES roles(id)    ON DELETE CASCADE,
                    ou_id      INTEGER              REFERENCES organizational_units(id) ON DELETE SET NULL,{$isPrimary}
                    status     TEXT        NOT NULL DEFAULT 'active',
                    created_at TEXT        NOT NULL DEFAULT (datetime('now')){$pairUnique}
                )
            ");

            $columns = $withPairUnique
                ? 'id, profile_id, tenant_id, role_id, ou_id, status, created_at'
                : 'id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at';

            $pdo->exec("
                INSERT INTO memberships ({$columns})
                SELECT {$columns} FROM " . self::SQLITE_LEGACY_TABLE
            );

            $pdo->exec('DROP TABLE ' . self::SQLITE_LEGACY_TABLE);

            // 030's two secondary indexes went with the old table.
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_memberships_profile_id ON memberships(profile_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_memberships_tenant_id  ON memberships(tenant_id)');
        } finally {
            $pdo->exec('PRAGMA legacy_alter_table = OFF');
        }
    }
}
