<?php

declare(strict_types=1);

namespace Tests\Plugins;

use PDO;
use PHPUnit\Framework\TestCase;
use UiKitShowcase\Migrations\GrantUiKitViewToAdmin;

require_once dirname(__DIR__, 2) . '/plugins/UiKitShowcase/Migrations/GrantUiKitViewToAdmin.php';

/**
 * WC-228: the UiKitShowcase grant migration makes the static block screen
 * reachable out-of-the-box — it seeds the plugin's single permission
 * (`uikit:view`) into the persisted catalogue and grants it to every seeded
 * `admin` role, idempotently, with a precise down() that never orphans
 * another role's grant.
 *
 * Real-engine (in-memory SQLite) per the project rule: ON CONFLICT and the
 * delete-guard subqueries must run on a genuine SQL engine.
 */
final class UiKitShowcaseGrantMigrationRealEngineTest extends TestCase
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

    public function testUpSeedsThePermissionAndGrantsItToAdmin(): void
    {
        (new GrantUiKitViewToAdmin())->up($this->pdo);

        $this->assertSame(['uikit:view'], $this->permissionNames());
        $this->assertSame(['uikit:view'], $this->grantedTo('admin'));
        $this->assertSame([], $this->grantedTo('user'), 'Only admin receives the grant');
    }

    public function testUpIsIdempotent(): void
    {
        $migration = new GrantUiKitViewToAdmin();
        $migration->up($this->pdo);
        $migration->up($this->pdo);

        $this->assertSame(['uikit:view'], $this->permissionNames(), 'No duplicate catalogue row');
        $this->assertSame(['uikit:view'], $this->grantedTo('admin'), 'No duplicate grant');

        $rowCount = (int) $this->runQuery("SELECT COUNT(*) FROM permissions WHERE name = 'uikit:view'")
            ->fetchColumn();
        $this->assertSame(1, $rowCount);
    }

    public function testUpGrantsEveryAdminRoleNotJustTheFirst(): void
    {
        // Multi-tenant deployments seed one admin role per tenant.
        $this->pdo->exec("INSERT INTO roles (name) VALUES ('admin')");

        (new GrantUiKitViewToAdmin())->up($this->pdo);

        $count = (int) $this->runQuery(
            "SELECT COUNT(*) FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             WHERE r.name = 'admin'"
        )->fetchColumn();

        $this->assertSame(2, $count, 'One permission across two admin roles');
    }

    public function testDownRemovesTheGrantAndOwnedCatalogueRow(): void
    {
        $migration = new GrantUiKitViewToAdmin();
        $migration->up($this->pdo);
        $migration->down($this->pdo);

        $this->assertSame([], $this->permissionNames());
        $this->assertSame([], $this->grantedTo('admin'));
    }

    public function testDownKeepsThePermissionStillGrantedToAnotherRole(): void
    {
        $migration = new GrantUiKitViewToAdmin();
        $migration->up($this->pdo);

        // An operator manually granted uikit:view to the user role too.
        $this->pdo->exec(
            "INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r, permissions p
             WHERE r.name = 'user' AND p.name = 'uikit:view'"
        );

        $migration->down($this->pdo);

        $this->assertSame(['uikit:view'], $this->permissionNames(), 'A row still referenced by a foreign grant survives');
        $this->assertSame(['uikit:view'], $this->grantedTo('user'), 'The foreign grant itself is untouched');
        $this->assertSame([], $this->grantedTo('admin'));
    }

    public function testDownDoesNotDeleteAPreexistingCatalogueRowItDidNotCreate(): void
    {
        // uikit:view existed BEFORE the migration ran (e.g. operator-seeded).
        $this->pdo->exec("INSERT INTO permissions (name, description) VALUES ('uikit:view', 'operator seeded')");

        $migration = new GrantUiKitViewToAdmin();
        $migration->up($this->pdo);
        $migration->down($this->pdo);

        // Ungranted after down(), the operator row survives because the
        // migration did not own it (different description marker).
        $this->assertContains('uikit:view', $this->permissionNames());
    }

    /**
     * @return list<string>
     */
    private function permissionNames(): array
    {
        $rows = $this->runQuery("SELECT name FROM permissions WHERE name LIKE 'uikit:%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $rows);
    }

    /**
     * Run a query and return a guaranteed statement (SQLite never returns false
     * here in ERRMODE_EXCEPTION, but PHPStan needs the narrowing).
     */
    private function runQuery(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        $this->assertInstanceOf(\PDOStatement::class, $stmt);

        return $stmt;
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
