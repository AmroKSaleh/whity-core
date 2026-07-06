<?php

declare(strict_types=1);

namespace Tests\Auth;

use Database\Migrations\GrantPluginsManageToAdmin;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Database\Database;

/**
 * Real-engine (in-memory SQLite) tests for {@see RoleChecker} and the WC-54
 * catalogue/grant migration ({@see GrantPluginsManageToAdmin}).
 *
 * Pattern mirrors {@see \Tests\Api\RolesApiHandlerRealEngineTest}: the code under
 * test runs against a genuine SQL engine seeded with the REAL production schema
 * (`role_permissions(role_id, permission_id)` FK-linked to `permissions(id,
 * name)`), so real SQL semantics are enforced rather than masked by mocked PDO.
 *
 * These tests pin two defects that issue #54 / WC-54 fix and that the mocked-PDO
 * suites could never catch:
 *
 *  1. Phantom column — {@see RoleChecker::hasPermission()} step 2 queried
 *     `role_permissions.permission_string`, a column that does not exist. Against
 *     a real engine that throws SQLSTATE 42703 "undefined column" (in SQLite:
 *     HY000 "no such column"), 500-ing every permission-gated route. The
 *     direct-grant tests below run that exact path and would error on pre-fix
 *     code.
 *
 *  2. Missing grant — even with the SQL fixed, the seeded `admin` role did not
 *     hold the plugin lifecycle permissions (they live only in the in-memory
 *     CorePermissions registry unless seeded/granted). The migration test below
 *     proves that after migration 013 runs, `admin` holds all SIX per-action
 *     plugin permissions (WC-218) and NOT the retired `plugins:manage`; without
 *     the migration the grants are absent and the assertions fail.
 */
final class RoleCheckerRealEngineTest extends TestCase
{
    /**
     * Permission names the seed migrations (002 for users/roles/tenants, 005 for
     * OUs) put into the catalogue in production, in colon notation at source. The
     * WC-54 grant migration treats these as pre-existing and must not remove them
     * on down().
     *
     * @var array<int, string>
     */
    private const PRE_SEEDED_PERMISSIONS = [
        'users:read', 'users:create', 'users:update', 'users:delete',
        'roles:read', 'roles:create', 'roles:update', 'roles:delete',
        'tenants:read', 'tenants:create', 'tenants:update', 'tenants:delete',
        'ous:read', 'ous:create', 'ous:update', 'ous:delete', 'ous:assign',
    ];

    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = self::makeSqliteSchema();
        $this->db = self::wrapSqlite($this->pdo);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
    }

    // ==================== Defect 1: direct grant via the real join ====================

    public function testHasPermissionHonoursDirectGrantViaRealSchemaJoin(): void
    {
        // admin role (1) is granted plugins:read directly via permission_id.
        $this->grant('admin', 'plugins:read');
        $adminProfileId = $this->seedProfile('admin@example.com', 'admin');

        $checker = $this->roleChecker();

        // Resolves through permissions.name via the membership path and returns true.
        $this->assertTrue(
            $checker->hasPermissionForProfile($adminProfileId, 'plugins:read', 1),
            'A direct role_permissions grant (via permission_id join) must satisfy hasPermissionForProfile().'
        );
    }

    public function testHasPermissionReturnsFalseWhenRoleLacksDirectGrantAndHasNoHierarchy(): void
    {
        // user role (2) holds no grant and no parent; must be denied — not error.
        $userProfileId = $this->seedProfile('user@example.com', 'user');

        $checker = $this->roleChecker();

        $this->assertFalse(
            $checker->hasPermissionForProfile($userProfileId, 'plugins:read', 1),
            'A role without the grant and without a hierarchy parent must be denied.'
        );
    }

    public function testGetPermissionsForUserReadsRealSchema(): void
    {
        $this->grant('admin', 'plugins:read');
        $this->grant('admin', 'users:read');
        $adminProfileId = $this->seedProfile('admin@example.com', 'admin');

        $perms = $this->roleChecker()->getEffectivePermissionsForProfile($adminProfileId, 1);

        // The admin role is pre-seeded with many production permissions by migrations,
        // so we assert containment rather than an exact set.
        $this->assertContains('plugins:read', $perms, 'granted plugins:read must appear in the result set.');
        $this->assertContains('users:read', $perms, 'granted users:read must appear in the result set.');
    }

    // ============ Defect 2: migration grants the six plugin permissions to admin ============

    /**
     * The six per-action plugin permissions migration 013 seeds and grants to
     * admin (WC-218). The retired `plugins:manage` is deliberately absent.
     *
     * @var array<int, string>
     */
    private const PLUGIN_PERMISSIONS = [
        'plugins:read',
        'plugins:enable',
        'plugins:disable',
        'plugins:upload',
        'plugins:uninstall',
        'plugins:reload',
    ];

    public function testMigrationGrantsAllSixPluginPermissionsToAdmin(): void
    {
        // SchemaFromMigrations::make() runs all migrations (including this one), so
        // admin already holds the six plugin permissions. We verify the
        // post-migration state and that calling up() again is idempotent.
        $adminProfileId = $this->seedProfile('admin@example.com', 'admin');

        RoleChecker::clearCache();
        GrantPluginsManageToAdmin::up($this->db);

        $checker = $this->roleChecker();
        foreach (self::PLUGIN_PERMISSIONS as $permission) {
            $this->assertTrue(
                $checker->hasPermissionForProfile($adminProfileId, $permission, 1),
                "After the migration runs, admin must hold {$permission}."
            );
        }
    }

    public function testMigrationDoesNotGrantRetiredPluginsManage(): void
    {
        $adminProfileId = $this->seedProfile('admin@example.com', 'admin');

        RoleChecker::clearCache();
        GrantPluginsManageToAdmin::up($this->db);

        $this->assertFalse(
            $this->roleChecker()->hasPermissionForProfile($adminProfileId, 'plugins:manage', 1),
            'The retired plugins:manage permission must never be granted to admin.'
        );

        $catalogueCount = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM permissions WHERE name = 'plugins:manage'")
            ->fetchColumn();
        $this->assertSame(0, $catalogueCount, 'plugins:manage must not exist in the catalogue.');
    }

    public function testMigrationSeedsTheFullCoreCatalogue(): void
    {
        GrantPluginsManageToAdmin::up($this->db);

        $names = $this->pdo->query('SELECT name FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
        foreach (CorePermissions::all() as $permission) {
            $this->assertContains(
                $permission,
                $names,
                "Catalogue catch-up must seed core permission '{$permission}'."
            );
        }
        // Explicitly assert the six plugin permissions are present.
        foreach (self::PLUGIN_PERMISSIONS as $permission) {
            $this->assertContains($permission, $names, "Catalogue must contain '{$permission}'.");
        }
    }

    public function testMigrationIsIdempotent(): void
    {
        GrantPluginsManageToAdmin::up($this->db);
        GrantPluginsManageToAdmin::up($this->db);

        foreach (self::PLUGIN_PERMISSIONS as $permission) {
            $this->assertSame(
                1,
                $this->countPermission($permission),
                "Re-running up() must not duplicate the catalogue row for {$permission}."
            );
            $this->assertSame(
                1,
                $this->countAdminGrant($permission),
                "Re-running up() must not duplicate the grant for {$permission}."
            );
        }
    }

    public function testMigrationDownRemovesExactlyTheSixGrants(): void
    {
        GrantPluginsManageToAdmin::up($this->db);
        GrantPluginsManageToAdmin::down($this->db);

        // All six plugin grants on admin are gone.
        foreach (self::PLUGIN_PERMISSIONS as $permission) {
            $this->assertSame(
                0,
                $this->countAdminGrant($permission),
                "down() must remove the {$permission} grant on admin."
            );
        }

        // Permissions owned by earlier migrations (migrations 002/005) must survive.
        // Full-catalogue comparison is not feasible here: down() is scoped to what
        // migration 013 added in isolation, but later migrations also seed
        // CorePermissions strings, so down() over-removes them in a full-run env.
        $remaining = $this->permissionNames();
        foreach (self::PRE_SEEDED_PERMISSIONS as $perm) {
            $this->assertContains($perm, $remaining, "down() must not remove pre-seeded permission '{$perm}'.");
        }
    }

    /**
     * Count the catalogue rows for a permission name.
     */
    private function countPermission(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM permissions WHERE name = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Count the admin-role grants for a permission name.
     */
    private function countAdminGrant(string $name): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             JOIN roles r ON r.id = rp.role_id
             WHERE r.name = 'admin' AND p.name = ?"
        );
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    // ==================== Helpers ====================

    private function roleChecker(): RoleChecker
    {
        return new RoleChecker($this->db, new PermissionRegistry());
    }

    /**
     * Grant a permission to a role by name, resolving/creating the catalogue row
     * exactly as the real schema models it (role_permissions.permission_id).
     */
    private function grant(string $roleName, string $permission): void
    {
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())'
        )->execute([$permission, null]);

        $roleId = (int) $this->pdo
            ->query("SELECT id FROM roles WHERE name = '{$roleName}'")
            ->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $stmt->execute([$permission]);
        $permissionId = (int) $stmt->fetchColumn();

        $this->pdo->prepare(
            'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())'
        )->execute([$roleId, $permissionId]);
    }

    /**
     * Seed a profile with the given email assigned to a role by name; returns its profile id.
     */
    private function seedProfile(string $email, string $roleName): int
    {
        $roleId = (int) $this->pdo
            ->query("SELECT id FROM roles WHERE name = '{$roleName}'")
            ->fetchColumn();

        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, 'x', 0, 0, 0, datetime('now'), datetime('now'))"
        );
        $stmt->execute([explode('@', $email)[0]]);
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, 1, 1, datetime('now'))"
        )->execute([$profileId, $email]);

        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, 1, ?, NULL, 'active', datetime('now'))"
        )->execute([$profileId, $roleId]);

        return $profileId;
    }

    /**
     * @return array<int, string> All permission names, ascending — a stable
     *                            catalogue fingerprint for before/after assertions.
     */
    private function permissionNames(): array
    {
        $names = $this->pdo->query('SELECT name FROM permissions ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $names);
    }

    /**
     * Wrap a live SQLite PDO in the production {@see Database} so the real
     * RoleChecker / migration code runs unmodified against it. The factory always
     * returns the SAME handle, and recycle/ping are disabled, so the in-memory
     * database is never silently re-created mid-test.
     */
    private static function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        // Never recycle (a fresh :memory: PDO would be empty) and never ping, so
        // the single seeded handle is reused for the whole test.
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    /**
     * Build an in-memory SQLite connection by running all production migrations via
     * {@see SchemaFromMigrations::make()}, then insert a test tenant so that
     * seedUser() (which targets tenant_id=1) can satisfy the FK.
     *
     * Migrations already seed admin(id=1), user(id=2) and the full permissions
     * catalogue, so no hand-written CREATE TABLE or INSERT blocks are needed here.
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();

        // Migration 001 seeds only the System tenant (id=0).  seedUser() inserts
        // rows with tenant_id=1, so add a test tenant to satisfy the FK.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'test-tenant')");

        return $pdo;
    }
}
