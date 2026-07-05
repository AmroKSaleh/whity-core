<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Tests\Support\SchemaFromMigrations;
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

    // ============ WC-49: tenant creation is gated to system administrators ============

    /**
     * A regular tenant's admin must not be able to create tenants. Creating a
     * tenant is a platform-level operation, so a non-system caller is refused
     * with 403 and NO row is written. This FAILS on pre-fix main, where the
     * handler creates the tenant for any caller behind the global `admin` role.
     */
    public function testNonSystemTenantAdminCannotCreateTenant(): void
    {
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();

        MockRequestFactory::setTestTenant(1);
        $response = $this->handler()->create(
            new Request('POST', '/api/tenants', [], (string) json_encode(['name' => 'Rogue Tenant']))
        );

        $this->assertSame(403, $response->getStatusCode(), 'A non-system tenant admin must not create tenants.');
        $error = json_decode($response->getBody(), true)['error'];
        $this->assertSame('Only system administrators may create tenants', $error);

        // No tenant was provisioned by the unauthorized caller.
        $after = (int) $this->pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
        $this->assertSame($before, $after, 'A denied create must not write a tenant row.');
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM tenants WHERE name = 'Rogue Tenant'")->fetchColumn()
        );
    }

    /**
     * A null/unresolved tenant context is not the system tenant and must also be
     * refused, so the gate can never be bypassed by an absent context.
     */
    public function testUnresolvedTenantContextCannotCreateTenant(): void
    {
        TenantContext::reset();
        $response = $this->handler()->create(
            new Request('POST', '/api/tenants', [], (string) json_encode(['name' => 'No Context Tenant']))
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM tenants WHERE name = 'No Context Tenant'")->fetchColumn()
        );
    }

    /**
     * The system user (tenant 0) retains platform authority and may still create
     * tenants — the gate must not regress the legitimate flow.
     */
    public function testSystemUserCanStillCreateTenant(): void
    {
        MockRequestFactory::setTestTenant(0);
        $response = $this->handler()->create(
            new Request('POST', '/api/tenants', [], (string) json_encode(['name' => 'New Tenant', 'slug' => 'new-tenant']))
        );

        $this->assertSame(201, $response->getStatusCode(), 'A system user must still be able to create tenants.');
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('New Tenant', $data['name']);
        $this->assertSame('new-tenant', $data['slug']);

        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM tenants WHERE name = 'New Tenant'")->fetchColumn(),
            'The system-created tenant must be persisted.'
        );
    }

    // ============ WC-d88de9fa: delete guard counts only ACTIVE members ============

    /**
     * The delete guard counts only ACTIVE memberships. A system user deleting a
     * tenant whose ONLY membership is suspended must succeed — the 409
     * "has N member(s)" block must not fire for a non-active occupant.
     */
    public function testDeleteSucceedsWhenOnlyMemberIsSuspended(): void
    {
        // Tenant 2 has exactly one membership, and it is suspended.
        $this->seedMembershipWithStatus(701, 2, 'suspended');

        MockRequestFactory::setTestTenant(0);
        $response = $this->handler()->delete(new Request('DELETE', '/api/tenants/2', []), ['id' => 2]);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'A tenant whose only membership is suspended must be deletable (active count is 0).'
        );
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM tenants WHERE id = 2')->fetchColumn(),
            'The tenant must be deleted.'
        );
    }

    /**
     * Conversely, an ACTIVE membership still blocks the delete with a 409 so the
     * guard is not simply disabled.
     */
    public function testDeleteBlockedWhenMemberIsActive(): void
    {
        $this->seedMembershipWithStatus(702, 2, 'active');

        MockRequestFactory::setTestTenant(0);
        $response = $this->handler()->delete(new Request('DELETE', '/api/tenants/2', []), ['id' => 2]);

        $this->assertSame(409, $response->getStatusCode(), 'An active member must block tenant deletion.');
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM tenants WHERE id = 2')->fetchColumn(),
            'The tenant must survive a blocked delete.'
        );
    }

    // ==================== Helpers ====================

    /**
     * Seed a single membership (with its own profile) at the given status so the
     * delete guard's active-only filter can be exercised.
     */
    private function seedMembershipWithStatus(int $id, int $tenantId, string $status): void
    {
        $pStmt = $this->pdo->prepare(
            "INSERT INTO profiles
                (id, display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, 'x', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $pStmt->execute([$id, "p{$id}"]);

        $mStmt = $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, 2, ?, datetime('now'))"
        );
        $mStmt->execute([$id, $tenantId, $status]);
    }

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
            "INSERT INTO users (id, tenant_id, role_id, email, password, created_at)
             VALUES (?, ?, 2, ?, 'x', datetime('now'))"
        );
        $stmt->execute([$id, $tenantId, $email]);

        // WC-d88de9fa: the tenants list now counts memberships (ADR 0005 §3).
        // Seed a profile and membership so userCount reflects the seeded data.
        $pStmt = $this->pdo->prepare(
            "INSERT INTO profiles
                (id, display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, 'x', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $pStmt->execute([$id, $email]);

        $mStmt = $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, 2, 'active', datetime('now'))"
        );
        $mStmt->execute([$id, $tenantId]);
    }

    /**
     * Build an in-memory SQLite connection seeded with the full migration schema.
     * Migration 010 seeds the system tenant (id 0). Tenants 1 and 2 are additional
     * test-data rows inserted here so the handler's LEFT JOIN sees them.
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES
            (1, 'Tenant A', datetime('now')),
            (2, 'Tenant B', datetime('now'))");
        // On real PostgreSQL, explicit-id inserts do NOT advance the SERIAL
        // sequence, so the handler's next auto-id would collide with id=1/2.
        // Resync the sequence to max(id) so create() gets a fresh id.
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $pdo->exec("SELECT setval(pg_get_serial_sequence('tenants', 'id'), (SELECT MAX(id) FROM tenants))");
        }
        return $pdo;
    }
}
