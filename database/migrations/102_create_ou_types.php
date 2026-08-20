<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * CreateOuTypes migration (#822) — gives an organizational unit a KIND.
 *
 * WHY THIS EXISTS
 * ---------------
 * `organizational_units` is `(id, tenant_id, parent_id, name, slug, description)`.
 * Nothing on it says whether a unit is a campus, a faculty or a department, so
 * the only thing a consumer can filter on is DEPTH — and depth answers a
 * different question than the one being asked:
 *
 *  - it is not comparable ACROSS installations: a single-campus institution has
 *    its faculties at depth 0 while a multi-campus one has them at depth 1, so
 *    "every unit of level 1" returns faculties on one install and departments on
 *    the next, and a rule written once cannot be shipped to both;
 *  - it is not stable WITHIN one installation: inserting a parent above an
 *    existing unit renumbers every depth beneath it, silently, with no signal to
 *    anything that cached the old numbers.
 *
 * The observed workaround is a parallel unit-id → kind map in the consumer's own
 * schema, which drifts the moment a unit is reparented through the API — a drift
 * the consumer has no way to detect. Putting the kind on the row removes the
 * second copy that can drift.
 *
 * WHY A PER-TENANT TABLE AND NOT A CORE ENUM
 * ------------------------------------------
 * One institution's *faculty* is another's *school* or *college*, and a
 * non-academic tenant has *region → branch → team* with no academic level at
 * all. A multi-tenant deployment holds both side by side. So the VOCABULARY is
 * tenant data, exactly as `relationship_types` (migration 019) is a
 * type vocabulary with its own ordering rather than an enum in code.
 *
 * What is NOT tenant data is the KEY. `type_key` is the stable, code-referenceable
 * identifier a routing rule binds to, governed by
 * {@see \Whity\Core\Ou\OuTypeRegistry}: bare for core and for a tenant's own
 * vocabulary, `plugin:slug` for a type a plugin contributes, with the prefix
 * stamped by the loader from the plugin NAME so a plugin can neither collide
 * with another nor mint a bare key. `label` is the tenant's rendering of that
 * key — "School" here, "Kulliyyah" there.
 *
 * WHY NOT THE TAXONOMY SUBSYSTEM
 * ------------------------------
 * `Core\Taxonomy` already ships tag groups and polymorphic entity tags, and a
 * type could be forced into that shape. Three things say it should not be: a
 * type is SINGLE-VALUED (nothing in a tag group stops a unit being both a
 * faculty and a department), it is STRUCTURAL rather than descriptive (every
 * consumer filters and joins on it, and routing that through the polymorphic tag
 * tables makes each such query a multi-join), and it is ORDERED — a campus
 * outranks a faculty outranks a department, which `sort_order` expresses and a
 * tag group cannot. Taxonomy remains right for cross-cutting labels on a unit;
 * this is a different axis.
 *
 * OPTIONAL BY CONSTRUCTION
 * ------------------------
 * `organizational_units.ou_type_id` is NULLABLE and every existing row is left
 * untyped. Typing an existing tree is a migration the operator performs against
 * their own data, not one imposed here — a deployment with forty-eight units has
 * a considered opinion about which is a faculty, and this migration does not.
 *
 * Additive, idempotent (IF NOT EXISTS + column probe) and reversible via down().
 */
class CreateOuTypes
{
    public static function up(Database $db): void
    {
        // The tenant's own vocabulary. `type_key` (not `key`) dodges the reserved
        // word across the PostgreSQL and SQLite-test engines, the same dodge
        // `tag_groups.group_key` makes for the same reason.
        //
        // `source` records PROVENANCE: `tenant` for a key an administrator
        // authored, `core`, or the plugin slug a declared key came from. Without
        // it an operator deciding what is safe to rename cannot tell a key their
        // own team invented from one a plugin's code binds to.
        $db->exec("
            CREATE TABLE IF NOT EXISTS ou_types (
                id         BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id  INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                type_key   VARCHAR(128)  NOT NULL,
                label      VARCHAR(255)  NOT NULL,
                sort_order INTEGER       NOT NULL DEFAULT 0,
                source     VARCHAR(64)   NOT NULL DEFAULT 'tenant',
                created_at TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, type_key)
            )
        ");

        // The vocabulary is always read whole, in rank order, to render a picker
        // or resolve a key; both reads start from tenant_id.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ou_types_tenant_id ON ou_types(tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ou_types_tenant_order ON ou_types(tenant_id, sort_order)');

        // The pointer itself. NULLABLE — see the class docblock: existing units
        // stay untyped and keep working. ON DELETE SET NULL is a BACKSTOP only;
        // {@see \Whity\Core\Ou\OuTypeRepository::delete()} untypes explicitly,
        // because SQLite honours FK actions only under `PRAGMA foreign_keys = ON`
        // and would otherwise leave the column pointing at a deleted row.
        if (!self::hasOuTypeId($db)) {
            $db->exec(
                'ALTER TABLE organizational_units
                    ADD COLUMN ou_type_id BIGINT NULL REFERENCES ou_types(id) ON DELETE SET NULL'
            );
        }

        // `?type=` filters the tenant's units by type, so the composite leading
        // with tenant_id is the one that serves it; the bare column index serves
        // the "is this type still in use?" delete guard from the other direction.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ou_type_id ON organizational_units(ou_type_id)');
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_ou_tenant_type
                ON organizational_units(tenant_id, ou_type_id)'
        );
    }

    public static function down(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $db->exec('DROP INDEX IF EXISTS idx_ou_tenant_type');
        $db->exec('DROP INDEX IF EXISTS idx_ou_type_id');

        // PostgreSQL drops the column outright. SQLite has supported
        // `ALTER TABLE ... DROP COLUMN` since 3.35 and the runtime targets are
        // well past it; the column carries no constraint that would block it.
        if (self::hasOuTypeId($db)) {
            if ($driver === 'pgsql') {
                $db->exec('ALTER TABLE organizational_units DROP COLUMN IF EXISTS ou_type_id');
            } else {
                $db->exec('ALTER TABLE organizational_units DROP COLUMN ou_type_id');
            }
        }

        $db->exec('DROP INDEX IF EXISTS idx_ou_types_tenant_order');
        $db->exec('DROP INDEX IF EXISTS idx_ou_types_tenant_id');
        $db->exec('DROP TABLE IF EXISTS ou_types CASCADE');
    }

    /**
     * Whether the pointer column is already present (a re-run, or a restored dump).
     */
    private static function hasOuTypeId(Database $db): bool
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            return $db->query(
                "SELECT 1 FROM information_schema.columns
                  WHERE table_name = 'organizational_units' AND column_name = 'ou_type_id'"
            )->fetchColumn() !== false;
        }

        foreach ($db->query('PRAGMA table_info(organizational_units)')->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if (($col['name'] ?? '') === 'ou_type_id') {
                return true;
            }
        }

        return false;
    }
}
