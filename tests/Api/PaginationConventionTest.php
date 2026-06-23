<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\OusApiHandler;
use Whity\Api\RolesApiHandler;
use Whity\Api\UsersApiHandler;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TenantContext;

/**
 * Integration tests for the consistent pagination convention (WC-239).
 *
 * Verifies that every modified list endpoint returns the `{data, pagination}`
 * envelope (not bare `{data}`), that page/per_page params are respected, and
 * that clamping and offset calculation are correct.
 *
 * Three handlers are tested as representatives; the remaining handlers follow
 * the identical extracted PaginationParams path.
 */
final class PaginationConventionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = self::makeSchema();
        MockRequestFactory::setTestTenant(1);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ── UsersApiHandler ────────────────────────────────────────────────────

    public function testUsersListReturnsPaginationEnvelope(): void
    {
        $response = $this->usersHandler()->list($this->get('/api/users'));
        $body     = $this->json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('pagination', $body);
        $this->assertPaginationShape($body['pagination']);
    }

    public function testUsersListTotalMatchesSeedCount(): void
    {
        $body = $this->json($this->usersHandler()->list($this->get('/api/users')));

        $this->assertSame(3, $body['pagination']['total']);
    }

    public function testUsersListPageTwoReturnsCorrectOffset(): void
    {
        $body = $this->json(
            $this->usersHandler()->list($this->get('/api/users?page=2&per_page=2'))
        );

        $this->assertSame(2, $body['pagination']['page']);
        $this->assertSame(2, $body['pagination']['perPage']);
        $this->assertCount(1, $body['data'], 'Page 2 of 3 items with per_page=2 should have 1 item');
        $this->assertSame(2, $body['pagination']['totalPages']);
    }

    public function testUsersListPerPageClampedToMax(): void
    {
        $body = $this->json(
            $this->usersHandler()->list($this->get('/api/users?per_page=9999'))
        );

        $this->assertSame(100, $body['pagination']['perPage']);
    }

    public function testUsersListDefaultsToPageOne(): void
    {
        $body = $this->json($this->usersHandler()->list($this->get('/api/users')));

        $this->assertSame(1, $body['pagination']['page']);
        $this->assertSame(25, $body['pagination']['perPage']);
    }

    // ── RolesApiHandler ────────────────────────────────────────────────────

    public function testRolesListReturnsPaginationEnvelope(): void
    {
        $response = $this->rolesHandler()->list($this->get('/api/roles'));
        $body     = $this->json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('pagination', $body);
        $this->assertPaginationShape($body['pagination']);
    }

    public function testRolesListTotalIncludesGlobalAndTenantRoles(): void
    {
        $body = $this->json($this->rolesHandler()->list($this->get('/api/roles')));

        // Migrations seed 2 base roles (admin, user) with NULL tenant_id;
        // setUp seeds one tenant-1 role → tenant 1 sees 3.
        $this->assertSame(3, $body['pagination']['total']);
    }

    // ── OusApiHandler ──────────────────────────────────────────────────────

    public function testOusListReturnsPaginationEnvelope(): void
    {
        $response = $this->ousHandler()->list($this->get('/api/ous'));
        $body     = $this->json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('pagination', $body);
        $this->assertPaginationShape($body['pagination']);
    }

    public function testOusListTotalMatchesTenantOuCount(): void
    {
        $body = $this->json($this->ousHandler()->list($this->get('/api/ous')));

        // setUp seeds 3 OUs for tenant 1 (Engineering, Backend, Sales).
        $this->assertSame(3, $body['pagination']['total']);
    }

    public function testOusListPageTwoWithPerPageOne(): void
    {
        $body = $this->json(
            $this->ousHandler()->list($this->get('/api/ous?page=2&per_page=1'))
        );

        $this->assertSame(2, $body['pagination']['page']);
        $this->assertCount(1, $body['data']);
        $this->assertSame(3, $body['pagination']['totalPages']);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function get(string $path): \Whity\Core\Request
    {
        return MockRequestFactory::withBearerToken('GET', $path, [
            'user_id'   => 1,
            'tenant_id' => 1,
            'email'     => 'admin@example.com',
            'role'      => 'admin',
        ]);
    }

    /** @return array<string, mixed> */
    private function json(\Whity\Core\Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    /** @param array<string, mixed> $pagination */
    private function assertPaginationShape(array $pagination): void
    {
        $this->assertArrayHasKey('page', $pagination);
        $this->assertArrayHasKey('perPage', $pagination);
        $this->assertArrayHasKey('total', $pagination);
        $this->assertArrayHasKey('totalPages', $pagination);
        $this->assertIsInt($pagination['page']);
        $this->assertIsInt($pagination['perPage']);
        $this->assertIsInt($pagination['total']);
        $this->assertIsInt($pagination['totalPages']);
    }

    private function usersHandler(): UsersApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');
        return new UsersApiHandler($this->pdo, $hooks);
    }

    private function rolesHandler(): RolesApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');
        return new RolesApiHandler($this->pdo, $hooks);
    }

    private function ousHandler(): OusApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');
        return new OusApiHandler($this->pdo, $hooks);
    }

    private static function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'acme'), (2, 'other')");

        // Tenant-1 role (global admin/user roles come from migrations).
        $pdo->exec(
            "INSERT INTO roles (id, name, description, tenant_id, created_at)
             VALUES (100, 'editor', 'Editor role', 1, datetime('now'))"
        );

        // 3 users in tenant 1. Use IDs 100+ to avoid conflicting with the
        // system-admin user (ID 1) seeded by migration 010.
        $pdo->exec("
            INSERT INTO users (id, email, password, role_id, tenant_id, created_at) VALUES
                (100, 'alice@acme.com', 'hash', 1, 1, datetime('now')),
                (101, 'bob@acme.com',   'hash', 2, 1, datetime('now')),
                (102, 'carol@acme.com', 'hash', 1, 1, datetime('now'))
        ");

        // 3 OUs in tenant 1.
        $pdo->exec("
            INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, description, created_at) VALUES
                (10, 1, NULL, 'Engineering', 'engineering', '', datetime('now')),
                (11, 1, 10,   'Backend',     'backend',     '', datetime('now')),
                (12, 1, NULL, 'Sales',       'sales',       '', datetime('now'))
        ");

        return $pdo;
    }
}
