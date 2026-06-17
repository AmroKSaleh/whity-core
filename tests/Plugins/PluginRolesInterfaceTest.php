<?php

declare(strict_types=1);

namespace Tests\Plugins;

use HelloWorld\HelloWorldPlugin;
use PHPUnit\Framework\TestCase;
use Whity\Core\PluginLoader;
use Whity\Core\PluginRoleSeeder;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Sdk\PluginRolesInterface;

require_once dirname(__DIR__, 2) . '/plugins/HelloWorld/HelloWorldPlugin.php';

/**
 * Tests for the PluginRolesInterface SDK capability and its host-side seeding.
 *
 * Covers:
 *  - HelloWorldPlugin implements PluginRolesInterface.
 *  - getRoles() returns an array keyed by role name with valid descriptor shape.
 *  - getRolePermissions() returns an array keyed by role name with valid slug lists.
 *  - PluginRoleSeeder::seed() inserts role and grant rows (in-memory SQLite).
 *  - PluginRoleSeeder::removeGrants() removes those grants idempotently.
 *  - PluginLoader wires the seeder and calls it on registration.
 */
final class PluginRolesInterfaceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Unit: interface shape
    // -------------------------------------------------------------------------

    public function testHelloWorldPluginImplementsPluginRolesInterface(): void
    {
        $plugin = new HelloWorldPlugin();

        $this->assertInstanceOf(PluginRolesInterface::class, $plugin);
    }

    public function testGetRolesReturnsExpectedStructure(): void
    {
        $roles = (new HelloWorldPlugin())->getRoles();

        $this->assertIsArray($roles);
        $this->assertArrayHasKey('hello_viewer', $roles);

        $descriptor = $roles['hello_viewer'];
        $this->assertIsArray($descriptor);
        $this->assertArrayHasKey('description', $descriptor);
        /** @var array{description: string} $descriptor */
        $this->assertIsString($descriptor['description']);
        $this->assertNotEmpty($descriptor['description']);
    }

    public function testGetRolePermissionsReturnsExpectedStructure(): void
    {
        $permissions = (new HelloWorldPlugin())->getRolePermissions();

        $this->assertIsArray($permissions);
        $this->assertArrayHasKey('hello_viewer', $permissions);

        $slugs = $permissions['hello_viewer'];
        $this->assertIsArray($slugs);
        $this->assertNotEmpty($slugs);

        foreach ($slugs as $slug) {
            $this->assertIsString($slug);
            // Verify colon-notation (resource:action).
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/',
                $slug,
                "Permission slug '{$slug}' must match resource:action pattern"
            );
        }
    }

    public function testRolePermissionKeysMatchRoleKeys(): void
    {
        $plugin = new HelloWorldPlugin();
        $roleKeys = array_keys($plugin->getRoles());
        $permKeys = array_keys($plugin->getRolePermissions());

        // Every permission map key must correspond to a declared role.
        foreach ($permKeys as $key) {
            $this->assertContains(
                $key,
                $roleKeys,
                "getRolePermissions() key '{$key}' must be a key in getRoles()"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Integration: PluginRoleSeeder against an in-memory SQLite database
    // -------------------------------------------------------------------------

    /**
     * Build a minimal SQLite schema sufficient for the seeder tests.
     */
    private function buildSqlitePdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $pdo->exec('
            CREATE TABLE tenants (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT    NOT NULL UNIQUE
            )
        ');

        $pdo->exec('
            CREATE TABLE roles (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT    NOT NULL UNIQUE,
                description TEXT    NOT NULL DEFAULT \'\',
                parent_id   INTEGER NULL REFERENCES roles(id) ON DELETE SET NULL,
                tenant_id   INTEGER NULL REFERENCES tenants(id) ON DELETE CASCADE,
                created_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $pdo->exec('
            CREATE TABLE permissions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT    NOT NULL UNIQUE,
                description TEXT,
                created_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $pdo->exec('
            CREATE TABLE role_permissions (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                role_id       INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                permission_id INTEGER NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
                created_at    TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(role_id, permission_id)
            )
        ');

        // Seed the hello:view permission that HelloWorldPlugin references.
        $pdo->exec("INSERT INTO permissions (name, description) VALUES ('hello:view', 'View hello content')");

        return $pdo;
    }

    public function testSeedCreatesRoleAndGrantsPermission(): void
    {
        $pdo = $this->buildSqlitePdo();
        $seeder = new PluginRoleSeeder($pdo);

        $plugin = new HelloWorldPlugin();
        $seeder->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);

        // Role row must exist.
        $stmt = $pdo->query("SELECT id, name, description FROM roles WHERE name = 'hello_viewer'");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch();
        $this->assertIsArray($row);
        $this->assertSame('hello_viewer', $row['name']);

        $roleId = (int) $row['id'];

        // Permission grant must exist.
        $grantStmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :role_id AND p.name = 'hello:view'"
        );
        $grantStmt->execute([':role_id' => $roleId]);
        $grantRow = $grantStmt->fetch();
        $this->assertSame(1, (int) $grantRow['cnt']);
    }

    public function testSeedIsIdempotent(): void
    {
        $pdo = $this->buildSqlitePdo();
        $seeder = new PluginRoleSeeder($pdo);
        $plugin = new HelloWorldPlugin();

        // Calling seed() twice must not create duplicate rows or throw.
        $seeder->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);
        $seeder->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);

        $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM roles WHERE name = 'hello_viewer'");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch();
        $this->assertSame(1, (int) $row['cnt'], 'Role must appear exactly once after two seed() calls');
    }

    public function testRemoveGrantsRemovesOnlySeededPermissions(): void
    {
        $pdo = $this->buildSqlitePdo();
        $seeder = new PluginRoleSeeder($pdo);
        $plugin = new HelloWorldPlugin();

        $seeder->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);

        // Verify grant exists before removal.
        $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM role_permissions");
        $this->assertNotFalse($stmt);
        $this->assertSame(1, (int) $stmt->fetch()['cnt'], 'One grant expected after seed');

        $seeder->removeGrants($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);

        // Grant must be gone.
        $stmt2 = $pdo->query("SELECT COUNT(*) AS cnt FROM role_permissions");
        $this->assertNotFalse($stmt2);
        $this->assertSame(0, (int) $stmt2->fetch()['cnt'], 'No grants expected after removeGrants');

        // Role row itself must still exist (conservative — admins may have assigned users).
        $roleStmt = $pdo->query("SELECT COUNT(*) AS cnt FROM roles WHERE name = 'hello_viewer'");
        $this->assertNotFalse($roleStmt);
        $this->assertSame(1, (int) $roleStmt->fetch()['cnt'], 'Role row must survive removeGrants');
    }

    public function testRemoveGrantsIsIdempotent(): void
    {
        $pdo = $this->buildSqlitePdo();
        $seeder = new PluginRoleSeeder($pdo);
        $plugin = new HelloWorldPlugin();

        $seeder->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);
        $seeder->removeGrants($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);
        // Second removeGrants must not throw.
        $seeder->removeGrants($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);

        $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM role_permissions");
        $this->assertNotFalse($stmt);
        $this->assertSame(0, (int) $stmt->fetch()['cnt']);
    }

    public function testSeedSkipsUnknownPermissionSlugSilently(): void
    {
        $pdo = $this->buildSqlitePdo();
        $seeder = new PluginRoleSeeder($pdo);

        // Create a stub plugin that declares a slug not in the permissions table.
        $plugin = new class implements \Whity\Sdk\PluginInterface, PluginRolesInterface {
            public function getName(): string { return 'StubPlugin'; }
            public function getVersion(): string { return '1.0.0'; }
            /** @return array<array{method: string, path: string, handler: callable}> */
            public function getRoutes(): array { return []; }
            /** @return array<string> */
            public function getPermissions(): array { return []; }
            /** @return array<string, mixed> */
            public function getHooks(): array { return []; }
            /** @return array<string> */
            public function getMigrations(): array { return []; }
            /** @return array<string, array{description?: string, parent?: string}> */
            public function getRoles(): array { return ['stub_role' => ['description' => 'Stub']]; }
            /** @return array<string, list<string>> */
            public function getRolePermissions(): array { return ['stub_role' => ['nonexistent:permission']]; }
        };

        // Must not throw; the unknown slug is silently skipped.
        $seeder->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);

        $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM role_permissions");
        $this->assertNotFalse($stmt);
        $this->assertSame(0, (int) $stmt->fetch()['cnt'], 'No grants for unknown permission slugs');
    }

    // -------------------------------------------------------------------------
    // Integration: PluginLoader wires seeder and calls it on registerPlugin
    // -------------------------------------------------------------------------

    public function testPluginLoaderCallsSeederOnRegistration(): void
    {
        $pdo = $this->buildSqlitePdo();
        $seeder = new PluginRoleSeeder($pdo);

        $pluginDir = dirname(__DIR__, 2) . '/plugins';
        $router = new Router();
        $permissionRegistry = new PermissionRegistry();

        $loader = new PluginLoader(
            $pluginDir,
            $router,
            $permissionRegistry,
            null,
            null,
            $seeder
        );
        $loader->load();

        // After load, the hello_viewer role must be seeded.
        $stmt = $pdo->query("SELECT id FROM roles WHERE name = 'hello_viewer'");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch();
        $this->assertIsArray($row, 'hello_viewer role must be seeded by PluginLoader');
    }
}
