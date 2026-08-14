<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * ScopeRoleNamesPerTenant — role names become unique PER TENANT instead of
 * platform-wide (#712, "Secondary / smaller").
 *
 * The defect
 * ----------
 * `roles.name` was created `VARCHAR(255) NOT NULL UNIQUE` in migration 001, back
 * when `roles` had no owning tenant at all. Migration 001 later folded in the
 * `tenant_id` column but left the uniqueness rule where it was, so the name
 * space stayed global while ownership became per-tenant. The consequence is a
 * plain multi-tenancy defect: the first tenant to create a role called
 * "Supervisor" permanently denies that word to every other tenant on the
 * install — and the 409 they get back cannot even say why, because naming the
 * conflicting role would leak another tenant's data.
 *
 * What replaces it — TWO partial unique indexes, not one composite
 * ---------------------------------------------------------------
 * The naive fix, `UNIQUE(tenant_id, name)`, is wrong here, and quietly so.
 * `roles.tenant_id` is NULLABLE and that NULL is meaningful: it marks a
 * GLOBAL/system role visible to every tenant (the seeded `admin` and `user` base
 * roles are exactly this — see the RolesApiHandler class docblock). In
 * PostgreSQL a UNIQUE index treats NULLs as DISTINCT, so `UNIQUE(tenant_id,
 * name)` would constrain nothing at all for global roles: a second global role
 * named `admin` would be accepted, and `RoleChecker`/`Seeder` lookups that
 * resolve a base role BY NAME would then pick one of two arbitrarily. Fixing a
 * tenant-isolation bug by opening an ambiguity in the base roles every tenant
 * inherits is a bad trade.
 *
 * PostgreSQL 15's `UNIQUE NULLS NOT DISTINCT` would express it in one index, but
 * it pins the minimum server version for a constraint that is expressible today,
 * on both engines, as two partial indexes:
 *
 *   uq_roles_tenant_name  UNIQUE (tenant_id, name) WHERE tenant_id IS NOT NULL
 *   uq_roles_global_name  UNIQUE (name)            WHERE tenant_id IS NULL
 *
 * Read together: a TENANT-OWNED role name is unique within its tenant and says
 * nothing about any other tenant; a GLOBAL role name is unique among global
 * roles, exactly as strongly as it was before this migration. This is the same
 * shape migration 047 uses for `external_identities` (a global-trust namespace
 * and a per-provider one), so it is a pattern already in the schema rather than
 * a new idea.
 *
 * What is deliberately NOT enforced here: a tenant may own a role whose name
 * matches a GLOBAL role's. No single index can express "unique within my tenant
 * AND against the globals" without an exclusion constraint, and the honest place
 * for that rule is the application, where a duplicate can be reported as a 409
 * with a message. `RolesApiHandler::create()/update()` therefore reject a name
 * already taken within the caller's VISIBILITY scope (own roles + globals) —
 * i.e. the DB enforces the hard invariant, the API enforces the friendly one.
 *
 * Existing data — why this cannot fail halfway
 * --------------------------------------------
 * The new rule is strictly WEAKER than `roles_name_key`: every row set that
 * satisfied a global UNIQUE(name) also satisfies both indexes above. On any
 * install where 001's constraint was actually in force, a collision is
 * impossible by construction and the pre-flight below finds nothing.
 *
 * It still runs, because "impossible by construction" assumes the construction
 * survived — a database restored from a `pg_dump --data-only` into a
 * hand-made table, or a schema someone patched by hand, can carry duplicates
 * the constraint would have refused. In that case creating the index is what
 * fails, and a migration that dies on `CREATE UNIQUE INDEX` after having
 * dropped the old constraint would leave `roles` with NO uniqueness rule at
 * all. So: duplicates are resolved FIRST (deterministically, non-destructively
 * — lowest id keeps the name, the rest are suffixed with their own id, which is
 * unique by definition), the new indexes are created SECOND, and the old
 * constraint is dropped LAST — the whole sequence inside one transaction, so
 * the outcome is either the new rule or the old one, never neither.
 *
 * Engine notes
 * ------------
 * PostgreSQL: `roles_name_key` is the auto-named constraint behind the inline
 * `UNIQUE`, dropped with ALTER TABLE. (A stray plain unique INDEX of the same
 * name — the shape a hand-patched schema tends to have — is dropped too.)
 *
 * SQLite (the test-schema engine — {@see \Tests\Support\SchemaFromMigrations}):
 * an inline column UNIQUE is backed by an internal `sqlite_autoindex_…` that
 * cannot be dropped, so the table is rebuilt with the rename-recreate idiom
 * migration 041 already uses. `legacy_alter_table` is ON for the rename so
 * SQLite does NOT rewrite the FK clauses of the many tables pointing at
 * `roles` (memberships, ou_role_assignments, role_permissions, …) to follow the
 * renamed table — that rewrite is the entire hazard of this idiom on modern
 * SQLite, and the pragma is the documented way off it.
 */
class ScopeRoleNamesPerTenant
{
    /** Partial unique index over tenant-OWNED role names. */
    private const IDX_TENANT = 'uq_roles_tenant_name';

    /** Partial unique index over GLOBAL (NULL-tenant) role names. */
    private const IDX_GLOBAL = 'uq_roles_global_name';

    /** The auto-generated name of migration 001's inline `UNIQUE` on roles.name. */
    private const LEGACY_CONSTRAINT = 'roles_name_key';

    /** Scratch name for the outgoing table on the SQLite rebuild path. */
    private const SQLITE_LEGACY_TABLE = 'roles_pre_093';

    public static function up(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // 1. Make the data satisfy the new rule BEFORE any DDL depends on it.
            self::resolveDuplicateNames($db);

            // 2. Remove the global constraint. On PostgreSQL this is a cheap
            //    catalogue edit; on SQLite it means rebuilding the table.
            if ($driver === 'pgsql') {
                $db->exec('ALTER TABLE roles DROP CONSTRAINT IF EXISTS ' . self::LEGACY_CONSTRAINT);
                // A hand-patched schema may carry it as a bare index instead.
                $db->exec('DROP INDEX IF EXISTS ' . self::LEGACY_CONSTRAINT);
            } else {
                self::rebuildRolesWithoutGlobalUnique($db);
            }

            // 3. Install the replacement rule.
            self::createScopedIndexes($db);

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
     * Restore the platform-global uniqueness rule.
     *
     * Only reversible when the data still satisfies it — which it will not once
     * two tenants have used the same role name, the very thing up() exists to
     * permit. Rather than silently renaming a live tenant's role to force the
     * old constraint back on, this refuses with the offending names, so the
     * operator decides. Rolling back is a decision, not a cleanup.
     */
    public static function down(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $clashes = self::globallyDuplicatedNames($db);
        if ($clashes !== []) {
            throw new \RuntimeException(
                'Migration 093 cannot be rolled back: ' . count($clashes) . ' role name(s) are now '
                . 'held by more than one tenant, which the global UNIQUE(name) constraint being '
                . 'restored forbids — ' . implode(', ', array_map(
                    static fn (string $n): string => '"' . $n . '"',
                    array_slice($clashes, 0, 10)
                ))
                . '. Rename or delete the duplicates, then retry the rollback.'
            );
        }

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $db->exec('DROP INDEX IF EXISTS ' . self::IDX_TENANT);
            $db->exec('DROP INDEX IF EXISTS ' . self::IDX_GLOBAL);

            if ($driver === 'pgsql') {
                $db->exec(
                    'ALTER TABLE roles ADD CONSTRAINT ' . self::LEGACY_CONSTRAINT . ' UNIQUE (name)'
                );
            } else {
                self::rebuildRolesWithoutGlobalUnique($db, withGlobalUnique: true);
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

    // ── Pre-flight ──────────────────────────────────────────────────────────

    /**
     * Make `roles` satisfy the two new partial indexes, non-destructively.
     *
     * A no-op on every install whose `roles_name_key` was in force (it is
     * impossible to violate a weaker rule than the one already enforced). Where
     * duplicates DO exist, the row with the LOWEST id keeps the name — it is the
     * oldest, so it is the one existing references and seeds resolved to — and
     * each later row is suffixed with its own id, which cannot itself collide
     * because ids are unique.
     */
    private static function resolveDuplicateNames(Database $db): void
    {
        foreach (self::duplicateGroups($db) as $group) {
            // Keep the first (lowest id); rename the rest.
            foreach (array_slice($group, 1) as $roleId) {
                // @tenant-guard-ignore: schema-repair rename addressed by primary key; runs before the tenant-scoped index exists
                $db->query(
                    'UPDATE roles SET name = name || :suffix WHERE id = :id',
                    [':suffix' => ' #' . $roleId, ':id' => $roleId]
                );
            }
        }
    }

    /**
     * Groups of role ids that would collide under the NEW rule, each ordered by
     * id ascending. Empty on any healthy install.
     *
     * `COALESCE(tenant_id, -1)` collapses the two namespaces into one grouping
     * key: NULL-tenant rows group with each other (the global namespace) and
     * never with a real tenant's, and -1 is safe because `tenant_id` is a FK to
     * `tenants.id`, a positive SERIAL.
     *
     * @return list<list<int>>
     */
    private static function duplicateGroups(Database $db): array
    {
        // @tenant-guard-ignore: schema-repair pre-flight; deliberately scans every tenant to find cross-row name collisions
        $stmt = $db->query(
            'SELECT id, name, COALESCE(tenant_id, -1) AS scope
             FROM roles
             ORDER BY id ASC'
        );

        /** @var array<string, list<int>> $byScope */
        $byScope = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) $row['scope'] . "\0" . (string) $row['name'];
            $byScope[$key][] = (int) $row['id'];
        }

        return array_values(array_filter(
            $byScope,
            static fn (array $ids): bool => count($ids) > 1
        ));
    }

    /**
     * Role names held by more than one row REGARDLESS of tenant — what the
     * restored global constraint would reject. Used by down() only.
     *
     * @return list<string>
     */
    private static function globallyDuplicatedNames(Database $db): array
    {
        // @tenant-guard-ignore: rollback pre-flight; the restored constraint is platform-global, so the check must be too
        $stmt = $db->query(
            'SELECT name FROM roles GROUP BY name HAVING COUNT(*) > 1 ORDER BY name'
        );

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ── Shared DDL ──────────────────────────────────────────────────────────

    private static function createScopedIndexes(Database $db): void
    {
        $db->exec('
            CREATE UNIQUE INDEX IF NOT EXISTS ' . self::IDX_TENANT . '
                ON roles (tenant_id, name)
                WHERE tenant_id IS NOT NULL
        ');

        $db->exec('
            CREATE UNIQUE INDEX IF NOT EXISTS ' . self::IDX_GLOBAL . '
                ON roles (name)
                WHERE tenant_id IS NULL
        ');
    }

    // ── SQLite rebuild ──────────────────────────────────────────────────────

    /**
     * Rebuild `roles` on SQLite so the inline `UNIQUE` on `name` is dropped
     * (up) or restored (down).
     *
     * The column list is written out rather than introspected because it is
     * fixed: migration 001 is the only statement that creates this table and no
     * migration alters it, so there is nothing to discover — and a literal DDL
     * is a schema the reader can check against 001 by eye.
     */
    private static function rebuildRolesWithoutGlobalUnique(Database $db, bool $withGlobalUnique = false): void
    {
        $pdo = $db->getPdo();

        // Renaming with legacy_alter_table OFF makes SQLite rewrite every OTHER
        // table's `REFERENCES roles(id)` to point at the renamed table — which
        // would silently re-target the FKs of memberships, ou_role_assignments,
        // role_permissions and friends at a table this migration then drops.
        $pdo->exec('PRAGMA legacy_alter_table = ON');

        try {
            $pdo->exec('DROP TABLE IF EXISTS ' . self::SQLITE_LEGACY_TABLE);
            $pdo->exec('ALTER TABLE roles RENAME TO ' . self::SQLITE_LEGACY_TABLE);

            // `IF NOT EXISTS` can never actually fire here — the RENAME directly
            // above has just vacated the name, and if it had failed the
            // transaction would already have aborted. It is written anyway
            // because every table creation in this directory carries the guard,
            // and a migration-wide idempotency gate enforces that uniformly
            // rather than reasoning case by case about which are reachable twice.
            $nameUnique = $withGlobalUnique ? ' UNIQUE' : '';
            $pdo->exec('
                CREATE TABLE IF NOT EXISTS roles (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL' . $nameUnique . ',
                    created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                    description TEXT DEFAULT \'\',
                    parent_id INTEGER NULL REFERENCES roles(id) ON DELETE SET NULL,
                    tenant_id INTEGER NULL REFERENCES tenants(id) ON DELETE CASCADE,
                    CONSTRAINT chk_roles_no_self_parent CHECK (parent_id IS NULL OR parent_id <> id)
                )
            ');

            $pdo->exec('
                INSERT INTO roles (id, name, created_at, description, parent_id, tenant_id)
                SELECT id, name, created_at, description, parent_id, tenant_id
                FROM ' . self::SQLITE_LEGACY_TABLE . '
            ');

            $pdo->exec('DROP TABLE ' . self::SQLITE_LEGACY_TABLE);

            // 001's two secondary indexes went with the old table.
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_roles_parent_id ON roles(parent_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_roles_tenant_id ON roles(tenant_id)');
        } finally {
            $pdo->exec('PRAGMA legacy_alter_table = OFF');
        }
    }
}
