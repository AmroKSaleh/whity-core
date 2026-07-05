<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AuditLogApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine (in-memory SQLite) tests for {@see AuditLogApiHandler} (WC-34).
 *
 * Drives the handler against a genuine SQL engine so the real INSERT/SELECT
 * semantics — filters, pagination, ordering and tenant scoping — are exercised,
 * not the forgiving behaviour of mocked PDO. The connection mirrors PostgreSQL's
 * "integers come back as strings" behaviour via PDO::ATTR_STRINGIFY_FETCHES so a
 * comparison that only passes for native ints would fail here too.
 *
 * Acceptance focus:
 *  - Tenant data isolation: tenant A sees only A's rows, tenant B only B's, the
 *    SYSTEM tenant (id 0) sees all (the WC-34 isolation criterion).
 *  - Filtering by action / actor / target_type / date range.
 *  - Newest-first ordering and pagination metadata.
 *  - Fail-closed when the tenant context is unresolved.
 *  - Defence-in-depth permission re-check (denied -> 403).
 */
final class AuditLogApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = self::makeSqliteSchema();
        $_GET = [];
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        TenantContext::reset();
    }

    /**
     * WC-167 review BLOCKER regression: empty metadata (JSONB '{}') must
     * encode as a JSON OBJECT in the response — PHP's empty array would emit
     * [], breaking every strictly-typed consumer of the published
     * AuditLogEntry.metadata: object contract.
     */
    public function testEmptyMetadataEncodesAsJsonObject(): void
    {
        $this->seedRow(1, 10, 'auth.login', null, null);

        TenantContext::setTenantId(1);
        $response = $this->handler()->list($this->authedRequest('/api/audit-logs', 11));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"metadata":{}', $response->getBody());
        $this->assertStringNotContainsString('"metadata":[]', $response->getBody());
    }

    // ==================== Tenant data isolation (AC) ====================

    public function testTenantAdminSeesOnlyItsOwnTenantRows(): void
    {
        $this->seedRow(1, 10, 'role.created', 'role', 100);
        $this->seedRow(1, 10, 'user.created', 'user', 200);
        $this->seedRow(2, 20, 'role.deleted', 'role', 300);

        TenantContext::setTenantId(1);
        $response = $this->handler()->list($this->authedRequest('/api/audit-logs', 11));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $actions = array_column($body['data'], 'action');

        $this->assertEqualsCanonicalizing(['role.created', 'user.created'], $actions);
        $this->assertSame(2, $body['pagination']['total']);
        // Tenant B's row must never appear.
        $this->assertNotContains('role.deleted', $actions);
    }

    public function testOtherTenantSeesOnlyItsRows(): void
    {
        $this->seedRow(1, 10, 'role.created', 'role', 100);
        $this->seedRow(2, 20, 'tenant.updated', 'tenant', 2);

        TenantContext::setTenantId(2);
        $response = $this->handler()->list($this->authedRequest('/api/audit-logs', 20));

        $body = json_decode($response->getBody(), true);
        $this->assertSame(1, $body['pagination']['total']);
        $this->assertSame('tenant.updated', $body['data'][0]['action']);
        $this->assertSame(2, $body['data'][0]['tenantId']);
    }

    public function testSystemTenantSeesAllTenants(): void
    {
        $this->seedRow(1, 10, 'role.created', 'role', 100);
        $this->seedRow(2, 20, 'role.deleted', 'role', 300);
        $this->seedRow(0, null, 'auth.login.failure', 'user', null);

        TenantContext::setTenantId(0);
        $response = $this->handler()->list($this->authedRequest('/api/audit-logs', 1));

        $body = json_decode($response->getBody(), true);
        $this->assertSame(3, $body['pagination']['total'], 'SYSTEM tenant must see every tenant\'s rows.');
        $actions = array_column($body['data'], 'action');
        $this->assertContains('auth.login.failure', $actions);
        $this->assertContains('role.created', $actions);
        $this->assertContains('role.deleted', $actions);
    }

    // ==================== Filters ====================

    public function testFilterByAction(): void
    {
        $this->seedRow(1, 10, 'role.created', 'role', 100);
        $this->seedRow(1, 10, 'role.deleted', 'role', 100);
        $this->seedRow(1, 10, 'user.created', 'user', 200);

        $_GET = ['action' => 'role.deleted'];
        TenantContext::setTenantId(1);
        $response = $this->handler()->list($this->authedRequest('/api/audit-logs', 10));

        $body = json_decode($response->getBody(), true);
        $this->assertSame(1, $body['pagination']['total']);
        $this->assertSame('role.deleted', $body['data'][0]['action']);
    }

    public function testFilterByActorAndTargetType(): void
    {
        $this->seedRow(1, 10, 'role.created', 'role', 100);
        $this->seedRow(1, 99, 'role.created', 'role', 101);
        $this->seedRow(1, 99, 'user.created', 'user', 201);

        $_GET = ['actor' => '99', 'target_type' => 'role'];
        TenantContext::setTenantId(1);
        $response = $this->handler()->list($this->authedRequest('/api/audit-logs', 10));

        $body = json_decode($response->getBody(), true);
        $this->assertSame(1, $body['pagination']['total']);
        $this->assertSame(99, $body['data'][0]['actorUserId']);
        $this->assertSame('role', $body['data'][0]['targetType']);
    }

    public function testFilterByDateRange(): void
    {
        $this->seedRowAt(1, 10, 'role.created', '2026-01-01 10:00:00');
        $this->seedRowAt(1, 10, 'role.updated', '2026-03-15 10:00:00');
        $this->seedRowAt(1, 10, 'role.deleted', '2026-06-01 10:00:00');

        $_GET = ['from' => '2026-02-01', 'to' => '2026-05-01'];
        TenantContext::setTenantId(1);
        $response = $this->handler()->list($this->authedRequest('/api/audit-logs', 10));

        $body = json_decode($response->getBody(), true);
        $this->assertSame(1, $body['pagination']['total']);
        $this->assertSame('role.updated', $body['data'][0]['action']);
    }

    // ==================== Ordering & pagination ====================

    public function testResultsAreNewestFirst(): void
    {
        $this->seedRowAt(1, 10, 'oldest', '2026-01-01 00:00:00');
        $this->seedRowAt(1, 10, 'newest', '2026-06-01 00:00:00');
        $this->seedRowAt(1, 10, 'middle', '2026-03-01 00:00:00');

        TenantContext::setTenantId(1);
        $response = $this->handler()->list($this->authedRequest('/api/audit-logs', 10));

        $body = json_decode($response->getBody(), true);
        $this->assertSame(['newest', 'middle', 'oldest'], array_column($body['data'], 'action'));
    }

    public function testPaginationSlicesAndReportsMetadata(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->seedRow(1, 10, 'role.created', 'role', $i);
        }

        $_GET = ['per_page' => '10', 'page' => '2'];
        TenantContext::setTenantId(1);
        $response = $this->handler()->list($this->authedRequest('/api/audit-logs', 10));

        $body = json_decode($response->getBody(), true);
        $this->assertCount(10, $body['data']);
        $this->assertSame(2, $body['pagination']['page']);
        $this->assertSame(10, $body['pagination']['perPage']);
        $this->assertSame(30, $body['pagination']['total']);
        $this->assertSame(3, $body['pagination']['totalPages']);
    }

    public function testMetadataIsReturnedAsDecodedObject(): void
    {
        $this->pdo->prepare(
            "INSERT INTO audit_log (tenant_id, actor_user_id, action, target_type, target_id, metadata, ip_address, created_at)
             VALUES (1, 10, 'role.created', 'role', 5, :meta, '203.0.113.7', '2026-06-01 00:00:00')"
        )->execute([':meta' => json_encode(['name' => 'editor'])]);

        TenantContext::setTenantId(1);
        $response = $this->handler()->list($this->authedRequest('/api/audit-logs', 10));

        $body = json_decode($response->getBody(), true);
        $this->assertSame(['name' => 'editor'], $body['data'][0]['metadata']);
        $this->assertSame('203.0.113.7', $body['data'][0]['ipAddress']);
    }

    // ==================== Fail-closed & RBAC ====================

    public function testUnresolvedTenantContextFailsClosed(): void
    {
        // No TenantContext set.
        $response = $this->handler()->list($this->authedRequest('/api/audit-logs', 10));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testPermissionDeniedReturns403(): void
    {
        $this->seedRow(1, 10, 'role.created', 'role', 100);

        TenantContext::setTenantId(1);
        $response = $this->handler(false)->list($this->authedRequest('/api/audit-logs', 10));

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('audit:read', $body['details']['required']);
    }

    // ==================== Helpers ====================

    /**
     * Build the handler with a RoleChecker stub that grants (or denies) audit:read.
     */
    private function handler(bool $grant = true): AuditLogApiHandler
    {
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')->willReturn($grant);

        return new AuditLogApiHandler($this->pdo, $roleChecker);
    }

    /**
     * Request carrying an authenticated acting user.
     */
    private function authedRequest(string $path, int $userId): Request
    {
        $request = new Request('GET', $path);
        $request->user = (object) ['profile_id' => $userId];
        return $request;
    }

    private function seedRow(int $tenantId, ?int $actorId, string $action, ?string $targetType, ?int $targetId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO audit_log (tenant_id, actor_user_id, action, target_type, target_id, metadata, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, '{}', NULL, datetime('now'))"
        );
        $stmt->execute([$tenantId, $actorId, $action, $targetType, $targetId]);
    }

    private function seedRowAt(int $tenantId, int $actorId, string $action, string $createdAt): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO audit_log (tenant_id, actor_user_id, action, target_type, target_id, metadata, ip_address, created_at)
             VALUES (?, ?, ?, 'role', 1, '{}', NULL, ?)"
        );
        $stmt->execute([$tenantId, $actorId, $action, $createdAt]);
    }

    /**
     * In-memory SQLite seeded with the full migration schema. STRINGIFY_FETCHES
     * mirrors PostgreSQL so int-vs-string comparison bugs surface (per the project rule).
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        // Seed the tenants referenced by seeded audit_log rows' tenant_id FK
        // (real PG enforces the constraint; SQLite does not).
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        return $pdo;
    }
}
