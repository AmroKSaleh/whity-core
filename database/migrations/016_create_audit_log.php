<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * CreateAuditLog migration (WC-34)
 *
 * Creates the `audit_log` table — the append-only security audit trail that
 * records who did what, in which tenant, and when. Security-relevant actions
 * (logins, 2FA changes, role/permission/tenant/user/OU mutations) are written
 * here by the {@see \Whity\Core\Audit\AuditLogger} so administrators have a
 * queryable, tenant-scoped history for incident response and compliance.
 *
 * Schema notes
 * ------------
 *  - `tenant_id` is NOT NULL and FK-cascades with the tenant (consistent with
 *    the other tenant-scoped tables). The SYSTEM tenant (id 0) owns cross-tenant
 *    and system-level records.
 *  - `actor_user_id` is NULLABLE on purpose: failed logins (no authenticated
 *    user yet) and system-originated actions have no actor. It is intentionally
 *    NOT a foreign key — an audit record must survive the deletion of the user
 *    it refers to (deleting evidence with the subject would defeat the trail),
 *    and ON DELETE SET NULL would erase the very actor an investigation needs.
 *  - `action` is a stable string key (e.g. `auth.login.success`,
 *    `role.created`); `target_type` / `target_id` identify the affected entity
 *    (`target_id` nullable for actions with no single target, e.g. a login).
 *  - `metadata` is JSON for action-specific context. The writer NEVER stores
 *    secrets or PII (no password hashes, no TOTP secrets/codes); this is
 *    enforced in {@see \Whity\Core\Audit\AuditLogger}.
 *  - `ip_address` is nullable (not always available; e.g. CLI/system actions).
 *
 * The composite index `(tenant_id, created_at DESC, id DESC)` backs the primary
 * access pattern: a tenant's most-recent-first audit listing. `id DESC` is the
 * tie-breaker so rows sharing a `created_at` (same second) still order stably.
 * A secondary index on `(tenant_id, action)` keeps the action filter cheap.
 *
 * This migration is additive, idempotent (IF NOT EXISTS) and fully reversible
 * via down(), which drops the table, the granted permission and (only) the
 * catalogue row it introduced.
 */
class CreateAuditLog
{
    public static function up(Database $db): void
    {
        // The append-only security audit trail.
        $db->exec("
            CREATE TABLE IF NOT EXISTS audit_log (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                actor_user_id INTEGER NULL,
                action VARCHAR(100) NOT NULL,
                target_type VARCHAR(100) NULL,
                target_id INTEGER NULL,
                metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                ip_address VARCHAR(45) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        // Primary access pattern: a tenant's newest-first audit listing.
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_audit_log_tenant_created
            ON audit_log (tenant_id, created_at DESC, id DESC)
        ');

        // Action filter (e.g. show only login failures) within a tenant.
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_audit_log_tenant_action
            ON audit_log (tenant_id, action)
        ');

        // Seed the audit:read permission so the RBAC catalogue matches the
        // in-memory CorePermissions registry, then grant it to the seeded admin
        // role so administrators can read the trail out of the box. Both steps
        // are idempotent.
        $db->query(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, NOW())
             ON CONFLICT (name) DO NOTHING',
            [
                ':name' => CorePermissions::AUDIT_READ,
                ':description' => 'Read the security audit log',
            ]
        );

        $adminRoleId = self::adminRoleId($db);
        $permissionId = self::permissionId($db, CorePermissions::AUDIT_READ);

        if ($adminRoleId === null || $permissionId === null) {
            // Partially-seeded database: skip the grant rather than error. The
            // permission still exists for a later grant.
            return;
        }

        $db->query(
            'INSERT INTO role_permissions (role_id, permission_id, created_at)
             VALUES (:role_id, :permission_id, NOW())
             ON CONFLICT (role_id, permission_id) DO NOTHING',
            [':role_id' => $adminRoleId, ':permission_id' => $permissionId]
        );
    }

    public static function down(Database $db): void
    {
        // Reverse the grant first so the catalogue row is no longer referenced.
        $adminRoleId = self::adminRoleId($db);
        $permissionId = self::permissionId($db, CorePermissions::AUDIT_READ);

        if ($adminRoleId !== null && $permissionId !== null) {
            $db->query(
                'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                [':role_id' => $adminRoleId, ':permission_id' => $permissionId]
            );
        }

        // Remove the audit:read catalogue row this migration introduced, but only
        // when no grant still references it (defensive: never orphan a grant).
        $db->query(
            'DELETE FROM permissions
             WHERE name = :name
               AND NOT EXISTS (
                   SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id
               )',
            [':name' => CorePermissions::AUDIT_READ]
        );

        // Drop the table (CASCADE removes its indexes).
        $db->exec('DROP TABLE IF EXISTS audit_log CASCADE');
    }

    /**
     * Resolve the seeded admin role id, or null when it is absent.
     */
    private static function adminRoleId(Database $db): ?int
    {
        $result = $db->query('SELECT id FROM roles WHERE name = :name', [':name' => 'admin'])->fetch();

        return $result === false ? null : (int) $result['id'];
    }

    /**
     * Resolve a permission id by name, or null when it is absent.
     */
    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
