<?php

declare(strict_types=1);

namespace Tests\Plugins;

use HelloWorld\Migrations\GrantGreetingsPermissionsToAdmin;
use PDO;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/plugins/HelloWorld/Migrations/GrantGreetingsPermissionsToAdmin.php';

/**
 * WC-169: HelloWorld's grant migration makes the reference frontend feature
 * work out-of-the-box — it seeds the plugin's permissions into the persisted
 * catalogue and grants them to every seeded `admin` role, idempotently, with
 * a precise down() that never orphans another role's grant.
 *
 * Real-engine (in-memory SQLite) per the project rule: ON CONFLICT and the
 * delete-guard subqueries must run on a genuine SQL engine.
 */
final class HelloWorldGrantMigrationRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $this->pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, description TEXT, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE role_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL, created_at TEXT, UNIQUE(role_id, permission_id))');

        $this->pdo->exec("INSERT INTO roles (name) VALUES ('admin'), ('user')");
    }

    public function testUpSeedsBothPermissionsAndGrantsThemToAdmin(): void
    {
        (new GrantGreetingsPermissionsToAdmin())->up($this->pdo);

        $this->assertSame(['hello:manage', 'hello:view'], $this->permissionNames());
        $this->assertSame(['hello:manage', 'hello:view'], $this->grantedTo('admin'));
        $this->assertSame([], $this->grantedTo('user'), 'Only admin receives the grant');
    }

    public function testUpIsIdempotent(): void
    {
        $migration = new GrantGreetingsPermissionsToAdmin();
        $migration->up($this->pdo);
        $migration->up($this->pdo);

        $this->assertSame(['hello:manage', 'hello:view'], $this->permissionNames());
        $this->assertSame(['hello:manage', 'hello:view'], $this->grantedTo('admin'));
    }

    public function testUpGrantsEveryAdminRoleNotJustTheFirst(): void
    {
        // Multi-tenant deployments seed one admin role per tenant.
        $this->pdo->exec("INSERT INTO roles (name) VALUES ('admin')");

        (new GrantGreetingsPermissionsToAdmin())->up($this->pdo);

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             WHERE r.name = 'admin'"
        )->fetchColumn();

        $this->assertSame(4, $count, 'Two permissions across two admin roles');
    }

    public function testDownRemovesGrantsAndOwnedCatalogueRows(): void
    {
        $migration = new GrantGreetingsPermissionsToAdmin();
        $migration->up($this->pdo);
        $migration->down($this->pdo);

        $this->assertSame([], $this->permissionNames());
        $this->assertSame([], $this->grantedTo('admin'));
    }

    public function testDownKeepsAPermissionStillGrantedToAnotherRole(): void
    {
        $migration = new GrantGreetingsPermissionsToAdmin();
        $migration->up($this->pdo);

        // An operator manually granted hello:view to the user role too.
        $this->pdo->exec(
            "INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r, permissions p
             WHERE r.name = 'user' AND p.name = 'hello:view'"
        );

        $migration->down($this->pdo);

        $this->assertSame(['hello:view'], $this->permissionNames(), 'A row still referenced by a foreign grant survives');
        $this->assertSame(['hello:view'], $this->grantedTo('user'), 'The foreign grant itself is untouched');
        $this->assertSame([], $this->grantedTo('admin'));
    }

    public function testDownDoesNotDeleteAPreexistingCatalogueRowItDidNotCreate(): void
    {
        // hello:view existed BEFORE the migration ran (e.g. operator-seeded).
        $this->pdo->exec("INSERT INTO permissions (name, description) VALUES ('hello:view', 'operator seeded')");

        $migration = new GrantGreetingsPermissionsToAdmin();
        $migration->up($this->pdo);
        $migration->down($this->pdo);

        // Ungranted after down(), the operator row is still deleted ONLY if the
        // migration owned it — it did not, so it must survive.
        $this->assertContains('hello:view', $this->permissionNames());
    }

    /**
     * @return list<string>
     */
    private function permissionNames(): array
    {
        $rows = $this->pdo
            ->query("SELECT name FROM permissions WHERE name LIKE 'hello:%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $rows);
    }

    /**
     * @return list<string>
     */
    private function grantedTo(string $roleName): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.name FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             JOIN roles r ON r.id = rp.role_id
             WHERE r.name = :role
             ORDER BY p.name'
        );
        $stmt->execute([':role' => $roleName]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
