<?php

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddRoleHierarchy migration
 *
 * Adds a self-referential parent_id column to the roles table so roles can be
 * organised into an inheritance hierarchy (e.g. super_admin -> admin -> editor
 * -> viewer). A higher role inherits every permission granted to the roles
 * beneath it by walking the parent chain (resolved in {@see \Whity\Auth\RoleChecker}).
 *
 * The column is nullable (a role with no parent is a hierarchy root) and uses
 * ON DELETE SET NULL so deleting a parent role detaches — rather than orphans
 * or cascade-deletes — its children. A self-reference guard (parent_id <> id)
 * prevents the most trivial one-node cycle at the storage layer; deeper cycles
 * are detected at resolution time by the application's cycle-detection logic.
 *
 * This migration is additive, idempotent (IF NOT EXISTS) and fully reversible
 * via down().
 */
class AddRoleHierarchy
{
    public static function up(Database $db): void
    {
        // Add the nullable self-referential parent_id column. ON DELETE SET NULL
        // detaches children when a parent role is removed.
        $db->exec('
            ALTER TABLE roles
            ADD COLUMN IF NOT EXISTS parent_id INTEGER NULL
                REFERENCES roles(id) ON DELETE SET NULL
        ');

        // Index the column to keep upward hierarchy traversal cheap.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_roles_parent_id ON roles(parent_id)');

        // Storage-level guard against a role being its own parent. Deeper cycles
        // (A -> B -> A) are handled by application cycle detection because a SQL
        // CHECK constraint cannot express transitive reachability.
        $db->exec("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'chk_roles_no_self_parent'
                ) THEN
                    ALTER TABLE roles
                        ADD CONSTRAINT chk_roles_no_self_parent CHECK (parent_id IS NULL OR parent_id <> id);
                END IF;
            END
            $$
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE roles DROP CONSTRAINT IF EXISTS chk_roles_no_self_parent');
        $db->exec('DROP INDEX IF EXISTS idx_roles_parent_id');
        $db->exec('ALTER TABLE roles DROP COLUMN IF EXISTS parent_id');
    }
}
