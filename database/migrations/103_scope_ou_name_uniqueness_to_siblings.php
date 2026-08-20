<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use RuntimeException;
use Whity\Database\Database;

/**
 * ScopeOuNameUniquenessToSiblings (#822) — makes a unit's name unique among its
 * SIBLINGS instead of across the whole tenant.
 *
 * WHY THIS EXISTS
 * ---------------
 * Migration 005 gave `organizational_units` a table-wide `UNIQUE(tenant_id, name)`.
 * That is the right rule for a flat list and the wrong rule for a tree. In the
 * normal shape of the thing being modelled — campus → faculty → department —
 * two faculties each have a *Computer Science* department and two campuses each
 * have an *Engineering* faculty, and the constraint refuses the second one with
 * a 409 that describes no real conflict. A deployment does not discover this
 * while its tree is small; it discovers it the first time the tree is realistic.
 *
 * WHY NOT SIMPLY `UNIQUE(tenant_id, parent_id, name)`
 * ---------------------------------------------------
 * Because it would not do what it says. In PostgreSQL NULLs are DISTINCT, so
 * every root unit — the ones with `parent_id IS NULL` — falls outside the
 * constraint entirely, and a tenant ends up able to create two roots both called
 * *Main Campus*. The tightening at the bottom of the tree would have loosened it
 * at the top, which is worse than leaving it alone, and it would not fail
 * loudly: it would simply stop constraining the rows nobody tests.
 *
 * So the rule is split across two PARTIAL unique indexes, the treatment
 * migration 094 gave `memberships` when it replaced a table-wide unique with one
 * over the primary rows only:
 *
 *     UNIQUE (tenant_id, parent_id, name) WHERE parent_id IS NOT NULL
 *     UNIQUE (tenant_id, name)            WHERE parent_id IS NULL
 *
 * Together they say "unique among siblings" for every row, with the roots
 * treated as one sibling set. Both engines support partial indexes; the
 * non-null one is a plain b-tree over real columns, so it also serves the
 * sibling-name lookup {@see \Whity\Api\OusApiHandler} does before every insert.
 *
 * A single `UNIQUE (tenant_id, COALESCE(parent_id, 0), name)` expression index
 * would express the same rule in one object, and was rejected: it is only
 * correct while no unit can have id 0, and an invariant that lives nowhere but
 * a comment is one an expression index should not be resting on.
 *
 * WHAT THIS DELIBERATELY DOES NOT TOUCH
 * -------------------------------------
 * `UNIQUE(tenant_id, slug)` stays tenant-global. A slug is URL identity: making
 * it sibling-scoped means `/ous/computer-science` no longer resolves to one unit
 * without the full ancestor path, so every stored link, every bookmark and every
 * route in the admin UI would have to carry the path instead of the slug. The
 * cost of keeping it is that two sibling-legal *Computer Science* departments
 * cannot both take `computer-science` — which the API resolves by
 * disambiguating the second one's slug rather than by refusing the unit.
 *
 * NO DATA MIGRATION IS NEEDED
 * ---------------------------
 * The old rule is strictly STRONGER than the new one, so every existing row
 * already satisfies both new indexes and the change cannot fail on live data.
 *
 * ENGINE NOTES
 * ------------
 * PostgreSQL names 005's table-level constraint
 * `organizational_units_tenant_id_name_key` and drops it with a catalogue edit.
 * SQLite (the test-schema engine — see {@see \Tests\Support\SchemaFromMigrations})
 * backs a table-level UNIQUE with an internal `sqlite_autoindex_…` that no
 * `DROP INDEX` can reach, so the table is rebuilt, exactly as migrations 093 and
 * 094 rebuild `roles` and `memberships`. `legacy_alter_table = ON` is essential
 * during the rename: with it off, SQLite rewrites the
 * `REFERENCES organizational_units(...)` clauses of `memberships`,
 * `ou_role_assignments` and the table's own self-reference to point at the
 * scratch table this migration then drops.
 */
class ScopeOuNameUniquenessToSiblings
{
    /** The partial unique index covering every NON-root unit. */
    private const IDX_SIBLING = 'uq_ou_sibling_name';

    /** The partial unique index covering the roots, which the other cannot reach. */
    private const IDX_ROOT = 'uq_ou_root_name';

    /** PostgreSQL's auto-generated name for migration 005's UNIQUE(tenant_id, name). */
    private const LEGACY_CONSTRAINT = 'organizational_units_tenant_id_name_key';

    /** Scratch name for the outgoing table on the SQLite rebuild path. */
    private const SQLITE_LEGACY_TABLE = 'organizational_units_pre_103';

    public static function up(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // 1. Drop the table-wide rule. A catalogue edit on PostgreSQL; on
            //    SQLite the whole table must be rebuilt.
            if ($driver === 'pgsql') {
                $db->exec(
                    'ALTER TABLE organizational_units DROP CONSTRAINT IF EXISTS ' . self::LEGACY_CONSTRAINT
                );
                // A hand-patched schema may carry it as a bare index instead.
                $db->exec('DROP INDEX IF EXISTS ' . self::LEGACY_CONSTRAINT);
            } else {
                self::rebuildWithoutNameUnique($db);
            }

            // 2. Install the replacement pair. Neither alone is the rule: the
            //    first cannot see the roots (NULLs are distinct), the second
            //    covers only them.
            $db->exec(
                'CREATE UNIQUE INDEX IF NOT EXISTS ' . self::IDX_SIBLING
                . ' ON organizational_units (tenant_id, parent_id, name) WHERE parent_id IS NOT NULL'
            );
            $db->exec(
                'CREATE UNIQUE INDEX IF NOT EXISTS ' . self::IDX_ROOT
                . ' ON organizational_units (tenant_id, name) WHERE parent_id IS NULL'
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
     * Restore the tenant-wide uniqueness rule.
     *
     * Only reversible while no tenant actually holds two units of the same name
     * — the very thing this migration exists to permit. Rather than deleting or
     * renaming somebody's *Computer Science* department to force the old
     * constraint back on, this refuses and names the collisions, so losing a
     * unit stays a decision an operator makes rather than a side effect of a
     * rollback. (Same contract as migration 094's down().)
     */
    public static function down(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $offenders = $db->query(
            'SELECT tenant_id, name, COUNT(*) AS n
               FROM organizational_units
              GROUP BY tenant_id, name
             HAVING COUNT(*) > 1
              ORDER BY tenant_id, name'
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($offenders !== []) {
            $pairs = implode(', ', array_map(
                static fn (array $r): string => "(tenant {$r['tenant_id']}, name '{$r['name']}')",
                array_slice($offenders, 0, 10)
            ));
            throw new RuntimeException(
                'Cannot roll back 103: ' . count($offenders) . ' tenant/name pair(s) are held by more '
                . 'than one organizational unit, which the restored constraint forbids: ' . $pairs
                . '. Rename or remove the duplicates first — this migration will not choose which '
                . 'unit loses its name.'
            );
        }

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $db->exec('DROP INDEX IF EXISTS ' . self::IDX_SIBLING);
            $db->exec('DROP INDEX IF EXISTS ' . self::IDX_ROOT);

            if ($driver === 'pgsql') {
                $db->exec(
                    'ALTER TABLE organizational_units ADD CONSTRAINT ' . self::LEGACY_CONSTRAINT
                    . ' UNIQUE (tenant_id, name)'
                );
            } else {
                self::rebuildWithoutNameUnique($db, withNameUnique: true);
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

    // ── SQLite rebuild ──────────────────────────────────────────────────────

    /**
     * Rebuild `organizational_units` so the table-level `UNIQUE(tenant_id, name)`
     * is dropped (or, for `down()`, restored). SQLite backs that constraint with
     * an internal autoindex no `DROP INDEX` can name.
     *
     * `ou_type_id` is carried across only when migration 102 has already added
     * it. In a forward run it always has — migrations are ordered — but a
     * partially-applied schema restored from a dump must not have the column
     * invented for it here.
     */
    private static function rebuildWithoutNameUnique(Database $db, bool $withNameUnique = false): void
    {
        $pdo = $db->getPdo();

        $hasOuType = self::hasOuTypeId($pdo);

        // With legacy_alter_table OFF, the RENAME below makes SQLite rewrite
        // every OTHER table's `REFERENCES organizational_units(...)` — and this
        // table's own self-reference — to point at the scratch table, which this
        // migration then drops.
        $pdo->exec('PRAGMA legacy_alter_table = ON');

        try {
            $pdo->exec('DROP TABLE IF EXISTS ' . self::SQLITE_LEGACY_TABLE);
            $pdo->exec('ALTER TABLE organizational_units RENAME TO ' . self::SQLITE_LEGACY_TABLE);

            $nameUnique = $withNameUnique ? ",\n                    UNIQUE (tenant_id, name)" : '';
            $ouTypeCol  = $hasOuType
                ? "\n                    ou_type_id  INTEGER      NULL REFERENCES ou_types(id) ON DELETE SET NULL,"
                : '';

            // `IF NOT EXISTS` cannot fire — the RENAME above just vacated the
            // name — but every table creation in this directory carries the
            // guard and a migration-wide idempotency gate enforces that
            // uniformly rather than reasoning case by case.
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS organizational_units (
                    id          INTEGER      PRIMARY KEY AUTOINCREMENT,
                    tenant_id   INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                    parent_id   INTEGER      NULL REFERENCES organizational_units(id) ON DELETE SET NULL,
                    name        TEXT         NOT NULL,
                    slug        TEXT         NOT NULL,
                    description TEXT         DEFAULT '',
                    created_at  TEXT         NOT NULL DEFAULT (datetime('now')),{$ouTypeCol}
                    UNIQUE (tenant_id, slug){$nameUnique}
                )
            ");

            $columns = 'id, tenant_id, parent_id, name, slug, description, created_at'
                . ($hasOuType ? ', ou_type_id' : '');

            $pdo->exec("
                INSERT INTO organizational_units ({$columns})
                SELECT {$columns} FROM " . self::SQLITE_LEGACY_TABLE
            );

            $pdo->exec('DROP TABLE ' . self::SQLITE_LEGACY_TABLE);

            // 005's two secondary indexes — and 102's — went with the old table.
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ou_tenant_id ON organizational_units(tenant_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ou_parent_id ON organizational_units(parent_id)');
            if ($hasOuType) {
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ou_type_id ON organizational_units(ou_type_id)');
                $pdo->exec(
                    'CREATE INDEX IF NOT EXISTS idx_ou_tenant_type
                        ON organizational_units(tenant_id, ou_type_id)'
                );
            }
        } finally {
            $pdo->exec('PRAGMA legacy_alter_table = OFF');
        }
    }

    /** Whether migration 102's pointer column is present on this schema. */
    private static function hasOuTypeId(PDO $pdo): bool
    {
        $stmt = $pdo->query('PRAGMA table_info(organizational_units)');
        if ($stmt === false) {
            return false;
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if (($col['name'] ?? '') === 'ou_type_id') {
                return true;
            }
        }

        return false;
    }
}
