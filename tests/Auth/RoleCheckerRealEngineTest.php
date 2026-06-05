<?php

declare(strict_types=1);

namespace Tests\Auth;

use Database\Migrations\GrantPluginsManageToAdmin;
use PDO;
use PHPUnit\Framework\TestCase;
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
 *     hold `plugins:manage` (it lived only in the in-memory CorePermissions
 *     registry, never seeded/granted). The migration test below proves that
 *     after the migration runs, `hasPermission(admin, 'plugins:manage')` is true;
 *     without the migration the grant is absent and the assertion fails.
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

    public static function setUpBeforeClass(): void
    {
        // Migration files live under database/migrations and are loaded at runtime
        // by MigrationsCommand (not via Composer PSR-4), so load it explicitly to
        // exercise the real migration class.
        require_once dirname(__DIR__, 2) . '/database/migrations/013_grant_plugins_manage_to_admin.php';
    }

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
        // admin role (1) is granted plugins:manage directly via permission_id.
        $this->grant('admin', 'plugins:manage');
        $adminUserId = $this->seedUser('admin@example.com', 'admin');

        $checker = $this->roleChecker();

        // On pre-fix code the step-2 probe selects rp.permission_string and the
        // engine raises "no such column", surfacing as a 500. After the fix it
        // resolves through permissions.name and returns true.
        $this->assertTrue(
            $checker->hasPermission($adminUserId, 'plugins:manage', 1),
            'A direct role_permissions grant (via permission_id join) must satisfy hasPermission().'
        );
    }

    public function testHasPermissionReturnsFalseWhenRoleLacksDirectGrantAndHasNoHierarchy(): void
    {
        // user role (2) holds no grant and no parent; must be denied — not error.
        $userId = $this->seedUser('user@example.com', 'user');

        $checker = $this->roleChecker();

        $this->assertFalse(
            $checker->hasPermission($userId, 'plugins:manage', 1),
            'A role without the grant and without a hierarchy parent must be denied.'
        );
    }

    public function testGetPermissionsForUserReadsRealSchema(): void
    {
        $this->grant('admin', 'plugins:manage');
        $this->grant('admin', 'users:read');
        $adminUserId = $this->seedUser('admin@example.com', 'admin');

        $perms = $this->roleChecker()->getPermissionsForUser($adminUserId);

        sort($perms);
        $this->assertSame(['plugins:manage', 'users:read'], $perms);
    }

    // ==================== Defect 2: migration grants plugins:manage to admin ====================

    public function testMigrationGrantsPluginsManageToAdminSoHasPermissionReturnsTrue(): void
    {
        // Pre-condition: no grant exists yet (the production state on main).
        $adminUserId = $this->seedUser('admin@example.com', 'admin');
        $this->assertFalse(
            $this->roleChecker()->hasPermission($adminUserId, 'plugins:manage', 1),
            'Sanity: before the migration the admin must NOT hold plugins:manage.'
        );

        // Run the actual migration under test against the real engine.
        RoleChecker::clearCache();
        GrantPluginsManageToAdmin::up($this->db);

        $this->assertTrue(
            $this->roleChecker()->hasPermission($adminUserId, 'plugins:manage', 1),
            'After the migration grant, the admin must hold plugins:manage.'
        );
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
    }

    public function testMigrationIsIdempotent(): void
    {
        GrantPluginsManageToAdmin::up($this->db);
        GrantPluginsManageToAdmin::up($this->db);

        $catalogueCount = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM permissions WHERE name = 'plugins:manage'")
            ->fetchColumn();
        $grantCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             JOIN roles r ON r.id = rp.role_id
             WHERE r.name = 'admin' AND p.name = 'plugins:manage'"
        )->fetchColumn();

        $this->assertSame(1, $catalogueCount, 'Re-running up() must not duplicate the catalogue row.');
        $this->assertSame(1, $grantCount, 'Re-running up() must not duplicate the grant.');
    }

    public function testMigrationDownRemovesExactlyWhatItAdded(): void
    {
        // A pre-seeded permission that predates this migration and is granted to
        // user: it must survive a rollback (down() owns only what it added).
        $this->grant('user', 'users:read');

        $before = $this->permissionNames();

        GrantPluginsManageToAdmin::up($this->db);
        GrantPluginsManageToAdmin::down($this->db);

        // The grant is gone.
        $grantCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             JOIN roles r ON r.id = rp.role_id
             WHERE r.name = 'admin' AND p.name = 'plugins:manage'"
        )->fetchColumn();
        $this->assertSame(0, $grantCount, 'down() must remove the plugins:manage grant.');

        // The catalogue is back to its pre-migration state exactly.
        $this->assertSame(
            $before,
            $this->permissionNames(),
            'down() must restore the catalogue to its exact pre-migration contents.'
        );
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
     * Seed a user with the given email assigned to a role by name; returns its id.
     */
    private function seedUser(string $email, string $roleName): int
    {
        $roleId = (int) $this->pdo
            ->query("SELECT id FROM roles WHERE name = '{$roleName}'")
            ->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (tenant_id, email, password, role_id, created_at)
             VALUES (1, ?, ?, ?, NOW())'
        );
        $stmt->execute([$email, 'x', $roleId]);

        return (int) $this->pdo->lastInsertId();
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
     * Build an in-memory SQLite connection seeded with a roles/permissions schema
     * that mirrors the production migrations: permissions(id, name) and
     * role_permissions(role_id, permission_id) FK-linked, plus seeded base roles.
     *
     * A NOW() UDF is registered because the production SQL uses PostgreSQL's
     * NOW(); SQLite has no such function natively.
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        $pdo->exec('
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                parent_id INTEGER,
                created_at TEXT
            )
        ');
        // Seeded base roles, mirroring migration 001 (admin=1, user=2).
        $pdo->exec("INSERT INTO roles (id, name, created_at) VALUES (1, 'admin', NOW()), (2, 'user', NOW())");

        $pdo->exec('
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT,
                created_at TEXT
            )
        ');
        // Pre-seed the catalogue exactly as migrations 002/007/010/016 do in
        // production, so the "before" state mirrors a real database. The WC-54
        // migration's down() must leave precisely these rows untouched.
        foreach (self::PRE_SEEDED_PERMISSIONS as $permission) {
            $pdo->exec("INSERT INTO permissions (name, created_at) VALUES ('{$permission}', NOW())");
        }

        $pdo->exec('
            CREATE TABLE role_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                role_id INTEGER NOT NULL REFERENCES roles(id),
                permission_id INTEGER NOT NULL REFERENCES permissions(id),
                created_at TEXT,
                UNIQUE(role_id, permission_id)
            )
        ');

        $pdo->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                password TEXT NOT NULL,
                role_id INTEGER,
                ou_id INTEGER,
                created_at TEXT
            )
        ');

        return $pdo;
    }
}
