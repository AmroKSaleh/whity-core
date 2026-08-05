<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PermissionsApiHandler;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;

/**
 * Real-engine (in-memory SQLite) tests for {@see PermissionsApiHandler}.
 *
 * Migrated from the mocked-PDO tests/Unit/Api/PermissionsApiHandlerTest.php,
 * preserving the original intent/assertions: a `createMock(PDO)` returns
 * whatever a test stubs regardless of the SQL the handler actually issues, so
 * it never proved the real `SELECT id, name, description FROM permissions
 * ORDER BY name` behaved as asserted. `permissions` is a platform-global
 * catalogue (not tenant-owned, see {@see \Whity\Core\Tenant\TenantOwnedTables}),
 * so no tenant fixture is required here.
 */
final class PermissionsApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
    }

    /**
     * list() returns the seeded permissions rows, ordered by name, under the
     * `data` key.
     */
    public function testListPermissionsReturns200(): void
    {
        $this->pdo->exec('DELETE FROM permissions');
        $this->seedPermission('users:create', 'Create users');
        $this->seedPermission('users:read', 'Read users');

        $handler = new PermissionsApiHandler($this->pdo);
        $response = $handler->list(new Request('GET', '/api/permissions'));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(
            ['users:create', 'users:read'],
            array_column($data, 'name'),
            'Rows must come back ordered by name.'
        );
    }

    /**
     * An empty `permissions` table returns 200 with an empty data array (the
     * pagination-shape contract holds even with zero rows).
     */
    public function testListPermissionsEmptyReturns200(): void
    {
        $this->pdo->exec('DELETE FROM permissions');

        $handler = new PermissionsApiHandler($this->pdo);
        $response = $handler->list(new Request('GET', '/api/permissions'));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame([], $data['data']);
        $this->assertSame(0, $data['pagination']['total']);
    }

    /**
     * A genuine database failure (the `permissions` table dropped out from
     * under the query, simulating a real outage rather than a stubbed
     * exception) is caught and returns a clean 500 without leaking the raw
     * driver error to the client.
     */
    public function testListPermissionsDatabaseErrorReturns500(): void
    {
        $this->pdo->exec('DROP TABLE permissions CASCADE');

        $handler = new PermissionsApiHandler($this->pdo);
        $response = $handler->list(new Request('GET', '/api/permissions'));

        $this->assertSame(500, $response->getStatusCode());
        $error = json_decode($response->getBody(), true)['error'];
        $this->assertStringContainsString('Failed to fetch permissions', $error);
    }

    /**
     * Registry permissions absent from the database are merged in with their
     * source tag; a registry permission that already exists as a DB row is not
     * duplicated.
     */
    public function testListMergesRegistryPermissions(): void
    {
        $this->pdo->exec('DELETE FROM permissions');
        $this->seedPermission('users:read', 'Read users');

        $registry = new PermissionRegistry();
        // 'users:read' already exists in the DB result and must not be duplicated.
        $registry->register('core', ['users:read']);
        $registry->register('invoices', ['invoices:read', 'invoices:write']);

        $handler = new PermissionsApiHandler($this->pdo, $registry);
        $response = $handler->list(new Request('GET', '/api/permissions'));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $names = array_column($data, 'name');

        $this->assertContains('users:read', $names);
        $this->assertContains('invoices:read', $names);
        $this->assertContains('invoices:write', $names);
        // 'users:read' came from the DB row and must appear exactly once.
        $this->assertSame(1, count(array_keys($names, 'users:read', true)));

        // Registry-sourced rows carry their source tag.
        $invoiceRow = null;
        foreach ($data as $row) {
            if ($row['name'] === 'invoices:read') {
                $invoiceRow = $row;
                break;
            }
        }
        $this->assertNotNull($invoiceRow);
        $this->assertSame('invoices', $invoiceRow['source']);
    }

    /**
     * Without a registry the handler returns only database permissions
     * (unchanged behaviour).
     */
    public function testListWithoutRegistryReturnsOnlyDatabasePermissions(): void
    {
        $this->pdo->exec('DELETE FROM permissions');
        $this->seedPermission('users:read', 'Read users');

        $handler = new PermissionsApiHandler($this->pdo);
        $response = $handler->list(new Request('GET', '/api/permissions'));

        $data = json_decode($response->getBody(), true)['data'];
        $this->assertCount(1, $data, 'No registry means no permission is merged in.');
        $this->assertGreaterThan(0, (int) $data[0]['id']);
        $this->assertSame(['id', 'name', 'description'], array_keys($data[0]), 'No `source` key without a registry.');
        $this->assertSame('users:read', $data[0]['name']);
        $this->assertSame('Read users', $data[0]['description']);
    }

    // ==================== Helpers ====================

    private function seedPermission(string $name, string $description): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO permissions (name, description, created_at) VALUES (?, ?, NOW())'
        );
        $stmt->execute([$name, $description]);
    }
}
