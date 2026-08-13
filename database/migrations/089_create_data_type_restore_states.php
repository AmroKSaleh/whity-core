<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateDataTypeRestoreStates — where a trashed record's PRIOR state is kept so
 * a restore can return it there.
 *
 * The defect this closes
 * ----------------------
 * `DataTypeLifecycleService::restore()` wrote the type's `default_state` — a
 * constant — over whatever the record actually was before it was trashed. Two
 * records both `approved`, both trashed, both restored, came back `draft`. For
 * any type carrying an approval gate that is a governance failure dressed as an
 * undo, and the 200 that reports it is indistinguishable from a correct one.
 *
 * The value was never unknown: `trash()` already read the prior state in order
 * to publish it as the transition hook's `from`. It was read and discarded.
 *
 * Why a CORE-OWNED SIDE TABLE rather than a column on the record
 * -------------------------------------------------------------
 * The obvious storage is a `restore_to` column beside the plugin's `status`.
 * Core does not own plugin tables, and requiring every adopter of every
 * trashable type to ship a schema migration to fix a bug in core is the wrong
 * trade — it converts one core deploy into N plugin deploys, and a plugin that
 * skips it keeps the bug silently.
 *
 * One row per trashed record, keyed exactly the way this platform already keys
 * polymorphic per-record facts — `(tenant_id, resource_type, resource_id)` in
 * `resource_role_assignments` (migration 088), `(tenant_id, entity_type,
 * entity_id)` in `entity_tags` (migration 063). Nothing about a plugin's schema
 * has to change for its records to be restored correctly.
 *
 * Shape, and the two consequences of it
 * -------------------------------------
 *   (tenant_id, data_type, record_id) → state
 *
 * `record_id` is VARCHAR, not INTEGER: a declared data type names its own key
 * column, the lifecycle surface takes `int|string`, and the HTTP path always
 * arrives as a string. Storing the id in its published form is what makes the
 * key and the lookup the same value.
 *
 * `record_id` also carries NO foreign key, and cannot: the table it points into
 * varies by `data_type`, so no single FK can express it. That is the same
 * deliberate limitation `resource_role_assignments.resource_id` carries, and it
 * has the same consequence — CLEANUP IS THE OWNER'S JOB.
 * {@see \Whity\Core\DataType\LifecycleStateMemory} does it: a hard delete
 * forgets the row, and so does a spent restore. Without that, a later record
 * landing on a reused primary key would inherit a dead record's state — the
 * id-reuse hazard the taxonomy delete guard already had to answer for.
 *
 * `tenant_id` is a real column with a real cascade, not a scope inferred
 * through the record: the tenant-predicate scanner can see it, dropping a
 * tenant takes its memories with it, and a lookup cannot be tricked across the
 * boundary by supplying another tenant's record id.
 *
 * Rows here are DERIVED, never authoritative. Nothing reads this table except a
 * restore, and a restore with nothing to read falls back to the declared
 * default — which is exactly the behaviour every record trashed before this
 * table existed gets, by construction.
 */
class CreateDataTypeRestoreStates
{
    public static function up(Database $db): void
    {
        // The triple IS the identity of the fact, so it is the primary key
        // rather than a uniqueness rule bolted onto a surrogate: there is
        // exactly one prior state per trashed record, and a second one would be
        // a contradiction rather than a duplicate.
        $db->exec('
            CREATE TABLE IF NOT EXISTS data_type_restore_states (
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                data_type VARCHAR(128) NOT NULL,
                record_id VARCHAR(64) NOT NULL,
                state VARCHAR(64) NOT NULL,
                remembered_at TIMESTAMP NOT NULL DEFAULT NOW(),
                PRIMARY KEY (tenant_id, data_type, record_id)
            )
        ');

        // Every read binds the full key, which the primary key already covers.
        // This second index serves the other access pattern: forgetting a whole
        // tenant's memories for one data type when a plugin is uninstalled.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_dtrs_type
             ON data_type_restore_states(tenant_id, data_type)'
        );
    }

    public static function down(Database $db): void
    {
        // Nothing to preserve: the table holds only derived rows, and dropping
        // it returns restores to the declared-default fallback they already use
        // whenever nothing was remembered.
        $db->exec('DROP TABLE IF EXISTS data_type_restore_states CASCADE');
    }
}
