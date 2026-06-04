<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Whity\Api\TenantsApiHandler;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine (in-memory SQLite) tests for {@see TenantsApiHandler::list()} (WC-122).
 *
 * The delete-tenant dialog reads `tenant.userCount`, but the list endpoint's
 * `LEFT JOIN ... COUNT(u.id) as userCount` aggregate comes back from MySQL with
 * the alias folded to lowercase (`usercount`), so the camelCase key the frontend
 * binds was never present and the "N associated users" warning never rendered.
 *
 * The fix shapes each row through `toPublicTenant()`, pinning the public contract
 * to camelCase regardless of how the engine folds the alias. These tests drive the
 * handler against a genuine SQL engine and assert the response payload carries
 * `userCount` (and `createdAt`) with the real user-count value.
 *
 * SQLite is used because CI has no live MySQL/PostgreSQL; the handler's SELECTs run
 * unmodified, matching {@see UsersApiHandlerRealEngineTest}.
 */
final class TenantsApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = self::makeSqliteSchema();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * A regular tenant's own listing exposes the associated-user count under the
     * camelCase `userCount` key the frontend reads — never the lowercase
     * `usercount` MySQL would otherwise produce.
     */
    public function testListExposesUserCountForOwnTenant(): void
    {
        // Tenant 1 has two users.
        $this->seedUser(101, 1, 'a@example.com');
        $this->seedUser(102, 1, 'b@example.com');

        MockRequestFactory::setTestTenant(1);
        $response = $this->handler()->list(new Request('GET', '/api/tenants', []));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertCount(1, $data);

        $tenant = $data[0];
        $this->assertArrayHasKey('userCount', $tenant, 'The payload must carry the camelCase userCount key.');
        $this->assertArrayNotHasKey('usercount', $tenant, 'The lowercase alias must not leak into the contract.');
        $this->assertSame(2, $tenant['userCount']);
        $this->assertSame(1, $tenant['id']);
        $this->assertSame('Tenant A', $tenant['name']);
    }

    /**
     * A tenant with no users reports `userCount` as 0 (the warning branch must
     * not fire), exercising the LEFT JOIN's zero-count path.
     */
    public function testListReportsZeroUserCountForEmptyTenant(): void
    {
        // Tenant 2 exists with no users.
        MockRequestFactory::setTestTenant(2);
        $response = $this->handler()->list(new Request('GET', '/api/tenants', []));

        $this->assertSame(200, $response->getStatusCode());
        $tenant = json_decode($response->getBody(), true)['data'][0];
        $this->assertSame(0, $tenant['userCount']);
        $this->assertIsInt($tenant['userCount'], 'userCount must be a real integer, not a string.');
    }

    /**
     * The system tenant (id 0) sees every non-system tenant, each carrying its own
     * `userCount`. This is the path the admin actually hits before opening the
     * delete-tenant dialog.
     */
    public function testSystemUserListingExposesUserCountPerTenant(): void
    {
        $this->seedUser(201, 1, 'one@example.com');
        $this->seedUser(202, 2, 'two@example.com');
        $this->seedUser(203, 2, 'three@example.com');

        MockRequestFactory::setTestTenant(0);
        $response = $this->handler()->list(new Request('GET', '/api/tenants', []));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];

        $byId = [];
        foreach ($data as $row) {
            $this->assertArrayHasKey('userCount', $row);
            $byId[$row['id']] = $row['userCount'];
        }

        // The system tenant (id 0) is excluded; tenants 1 and 2 are listed.
        $this->assertArrayNotHasKey(0, $byId, 'The system tenant must be excluded from the listing.');
        $this->assertSame(1, $byId[1]);
        $this->assertSame(2, $byId[2]);
    }

    /**
     * The public contract normalises `created_at` to `createdAt` so the list
     * payload matches the frontend `Tenant` type (WC-100/WC-113 casing alignment).
     */
    public function testListAliasesCreatedAtToCamelCase(): void
    {
        MockRequestFactory::setTestTenant(1);
        $response = $this->handler()->list(new Request('GET', '/api/tenants', []));

        $tenant = json_decode($response->getBody(), true)['data'][0];
        $this->assertArrayHasKey('createdAt', $tenant);
        $this->assertArrayNotHasKey('created_at', $tenant);
        $this->assertNotNull($tenant['createdAt']);
    }

    // ==================== Helpers ====================

    private function handler(): TenantsApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        return new TenantsApiHandler($this->pdo, $hooks);
    }

    private function seedUser(int $id, int $tenantId, string $email): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password, created_at)
             VALUES (?, ?, ?, 'x', datetime('now'))"
        );
        $stmt->execute([$id, $tenantId, $email]);
    }

    /**
     * Build an in-memory SQLite connection seeded with a tenants/users schema
     * close enough to production to exercise the handler's real list SQL (the
     * LEFT JOIN + COUNT aggregate). Two non-system tenants are seeded plus the
     * reserved system tenant (id 0), which the listing must exclude.
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('
            CREATE TABLE tenants (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                slug TEXT,
                created_at TEXT
            )
        ');
        $pdo->exec("
            INSERT INTO tenants (id, name, slug, created_at) VALUES
                (0, 'System', 'system', datetime('now')),
                (1, 'Tenant A', 'tenant-a', datetime('now')),
                (2, 'Tenant B', 'tenant-b', datetime('now'))
        ");

        $pdo->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                password TEXT NOT NULL,
                created_at TEXT
            )
        ');

        return $pdo;
    }
}
