<?php

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * NormalizePermissionNotation migration
 *
 * Reconciles the permission naming scheme across the platform (issue #55).
 *
 * Historically the `permissions.name` column was seeded with DOT notation
 * (e.g. `users.read`, `ous.assign`) by migrations 002 and 007. The RBAC layer
 * — `PermissionRegistry` / `CorePermissions` (PR #86) — standardised on the
 * mandated `resource:action` COLON notation (e.g. `users:read`). Because
 * `RoleChecker::hasPermission()` validates a colon permission against the
 * registry and then looks it up in the database, the two notations could never
 * match end-to-end. This migration normalises the stored data so both sides
 * speak the same dialect.
 *
 * Why this is a safe, additive data normalisation (not a breaking change):
 *  - It only rewrites the SEPARATOR of existing permission strings (`.` -> `:`)
 *    while preserving the resource and action segments, so no permission is
 *    added, removed, or semantically altered.
 *  - It touches a single column (`permissions.name`); `role_permissions` grants
 *    reference permissions by `permission_id`, so renaming a permission keeps
 *    every existing role grant intact automatically.
 *  - Fresh databases already receive colon notation (migrations 002/007/010
 *    were updated at source), so this UPDATE simply no-ops on rows that are
 *    already normalised — making it fully idempotent and safe to re-run.
 *  - The conversion is restricted to the known core resources so that any
 *    plugin permission (which already uses colon notation) is never touched.
 *
 * The down() method restores dot notation for the same set of permissions so
 * the migration is fully reversible.
 */
class NormalizePermissionNotation
{
    /**
     * Core permission resources whose seeded names used dot notation.
     *
     * Limiting the rewrite to these prefixes guarantees plugin-provided
     * permissions (already `resource:action`) are never affected.
     *
     * @var array<int, string>
     */
    private const RESOURCES = ['users', 'roles', 'tenants', 'ous', 'permissions', 'plugins'];

    public static function up(Database $db): void
    {
        // Convert `<resource>.<action>` -> `<resource>:<action>` for the known
        // core resources only. Idempotent: rows already in colon notation no
        // longer match the `<resource>.` LIKE filter and are left untouched.
        foreach (self::RESOURCES as $resource) {
            $db->query(
                "UPDATE permissions
                 SET name = REPLACE(name, :dot, :colon)
                 WHERE name LIKE :pattern",
                [
                    ':dot' => $resource . '.',
                    ':colon' => $resource . ':',
                    ':pattern' => $resource . '.%',
                ]
            );
        }
    }

    public static function down(Database $db): void
    {
        // Reverse the normalisation: `<resource>:<action>` -> `<resource>.<action>`
        // for the same core resources. Idempotent in the same fashion as up().
        foreach (self::RESOURCES as $resource) {
            $db->query(
                "UPDATE permissions
                 SET name = REPLACE(name, :colon, :dot)
                 WHERE name LIKE :pattern",
                [
                    ':colon' => $resource . ':',
                    ':dot' => $resource . '.',
                    ':pattern' => $resource . ':%',
                ]
            );
        }
    }
}
