<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Whity\Api\TenantsApiHandler;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Hooks\HookManager;
use PDO;
use PDOStatement;

/**
 * Tests for TenantsApiHandler access control (WC-81)
 *
 * Verifies that:
 * - System users (tenant_id=0) may update/delete any other tenant
 * - The system tenant (id=0) can never be deleted
 * - Non-system users may not update/delete tenants other than their own
 */
class TenantsApiHandlerTest extends TestCase
{
    private PDO&\PHPUnit\Framework\MockObject\MockObject $mockDb;
    private HookManager&\PHPUnit\Framework\MockObject\MockObject $mockHookManager;
    private TenantsApiHandler $handler;

    protected function setUp(): void
    {
        $this->mockDb = $this->createMock(PDO::class);
        $this->mockHookManager = $this->createMock(HookManager::class);
        $this->handler = new TenantsApiHandler($this->mockDb, $this->mockHookManager);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * Build a mocked PDOStatement that returns the provided fetch value(s).
     *
     * @param mixed $fetchReturn Value(s) returned by fetch(); pass an array of
     *                           values to control consecutive calls.
     */
    private function mockStatement(mixed $fetchReturn = false): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        if (is_array($fetchReturn) && array_is_list($fetchReturn)) {
            $stmt->method('fetch')->willReturnOnConsecutiveCalls(...$fetchReturn);
        } else {
            $stmt->method('fetch')->willReturn($fetchReturn);
        }
        return $stmt;
    }

    // ==================== AC1: system user can manage other tenants ====================

    /**
     * AC1: A system user (tenant_id=0) updating another tenant succeeds (200).
     */
    public function testSystemUserCanUpdateAnotherTenant(): void
    {
        MockRequestFactory::setTestTenant(0);

        $targetTenant = ['id' => 5, 'name' => 'Acme', 'slug' => 'acme'];

        // 1. SELECT target tenant -> found
        $selectStmt = $this->mockStatement($targetTenant);
        // 2. name uniqueness check -> not taken
        $nameStmt = $this->mockStatement(false);
        // 3. UPDATE
        $updateStmt = $this->mockStatement();

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($selectStmt, $nameStmt, $updateStmt);

        $request = new Request('PATCH', '/api/tenants/5', [], json_encode(['name' => 'Acme Corp']));
        $response = $this->handler->update($request, ['id' => 5]);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame(5, $data['data']['id']);
    }

    /**
     * An over-long name (VARCHAR(255)) on update is rejected with a clean 422
     * before the write (input hardening). The target-tenant SELECT still runs;
     * the length guard short-circuits ahead of the name-uniqueness query.
     */
    public function testUpdateRejectsOverLongNameWith422(): void
    {
        MockRequestFactory::setTestTenant(0);

        $selectStmt = $this->mockStatement(['id' => 5, 'name' => 'Acme', 'slug' => 'acme']);
        $this->mockDb->method('prepare')->willReturn($selectStmt);

        $request = new Request('PATCH', '/api/tenants/5', [], (string) json_encode(['name' => str_repeat('a', 256)]));
        $response = $this->handler->update($request, ['id' => 5]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('name', json_decode($response->getBody(), true)['details']['field']);
    }

    /**
     * AC1: A system user (tenant_id=0) deleting another tenant succeeds (200).
     */
    public function testSystemUserCanDeleteAnotherTenant(): void
    {
        MockRequestFactory::setTestTenant(0);

        // 1. SELECT target tenant -> found
        $selectStmt = $this->mockStatement(['id' => 5]);
        // 2. user count check -> zero users
        $userCountStmt = $this->mockStatement(['count' => 0]);
        // 3. DELETE
        $deleteStmt = $this->mockStatement();

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($selectStmt, $userCountStmt, $deleteStmt);

        $request = new Request('DELETE', '/api/tenants/5', []);
        $response = $this->handler->delete($request, ['id' => 5]);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame(5, $data['data']['id']);
        $this->assertSame('Tenant deleted', $data['data']['message']);
    }

    // ==================== AC2: system tenant deletion guard ====================

    /**
     * AC2: Deleting tenant 0 returns 400 "Cannot delete system tenant".
     */
    public function testDeletingSystemTenantReturns400(): void
    {
        MockRequestFactory::setTestTenant(0);

        // The guard must trip before any DB access; prepare must never be called.
        $this->mockDb->expects($this->never())->method('prepare');

        $request = new Request('DELETE', '/api/tenants/0', []);
        $response = $this->handler->delete($request, ['id' => 0]);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('Cannot delete system tenant', $data['error']);
    }

    /**
     * AC2: The guard also applies when the id is provided as a string "0".
     */
    public function testDeletingSystemTenantStringIdReturns400(): void
    {
        MockRequestFactory::setTestTenant(0);

        $this->mockDb->expects($this->never())->method('prepare');

        $request = new Request('DELETE', '/api/tenants/0', []);
        $response = $this->handler->delete($request, ['id' => '0']);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('Cannot delete system tenant', $data['error']);
    }

    // ==================== AC3: non-system users are restricted ====================

    /**
     * AC3: A non-system user updating a tenant other than their own returns 403.
     */
    public function testNonSystemUserCannotUpdateAnotherTenant(): void
    {
        MockRequestFactory::setTestTenant(3);

        // Authorization must fail before any DB access.
        $this->mockDb->expects($this->never())->method('prepare');

        $request = new Request('PATCH', '/api/tenants/5', [], json_encode(['name' => 'Hijack']));
        $response = $this->handler->update($request, ['id' => 5]);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertStringContainsString('Cannot update other tenants', $data['error']);
    }

    /**
     * AC3: A non-system user deleting a tenant other than their own returns 403.
     */
    public function testNonSystemUserCannotDeleteAnotherTenant(): void
    {
        MockRequestFactory::setTestTenant(3);

        $this->mockDb->expects($this->never())->method('prepare');

        $request = new Request('DELETE', '/api/tenants/5', []);
        $response = $this->handler->delete($request, ['id' => 5]);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertStringContainsString('Cannot delete other tenants', $data['error']);
    }

    // ==================== Regression: own-tenant operations still work ====================

    /**
     * A non-system user updating their own tenant still succeeds (200).
     */
    public function testNonSystemUserCanUpdateOwnTenant(): void
    {
        MockRequestFactory::setTestTenant(3);

        $own = ['id' => 3, 'name' => 'Mine', 'slug' => 'mine'];
        $selectStmt = $this->mockStatement($own);
        $slugStmt = $this->mockStatement(false);
        $updateStmt = $this->mockStatement();

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($selectStmt, $slugStmt, $updateStmt);

        $request = new Request('PATCH', '/api/tenants/3', [], json_encode(['slug' => 'mine-new']));
        $response = $this->handler->update($request, ['id' => 3]);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * A non-system user deleting their own (empty) tenant still succeeds (200).
     */
    public function testNonSystemUserCanDeleteOwnTenant(): void
    {
        MockRequestFactory::setTestTenant(3);

        $selectStmt = $this->mockStatement(['id' => 3]);
        $userCountStmt = $this->mockStatement(['count' => 0]);
        $deleteStmt = $this->mockStatement();

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($selectStmt, $userCountStmt, $deleteStmt);

        $request = new Request('DELETE', '/api/tenants/3', []);
        $response = $this->handler->delete($request, ['id' => 3]);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * A system user updating a tenant that does not exist returns 404.
     */
    public function testSystemUserUpdatingMissingTenantReturns404(): void
    {
        MockRequestFactory::setTestTenant(0);

        $selectStmt = $this->mockStatement(false); // tenant not found
        $this->mockDb->method('prepare')->willReturn($selectStmt);

        $request = new Request('PATCH', '/api/tenants/99', [], json_encode(['name' => 'X']));
        $response = $this->handler->update($request, ['id' => 99]);

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Missing id on update returns 400.
     */
    public function testUpdateWithoutIdReturns400(): void
    {
        MockRequestFactory::setTestTenant(0);

        $request = new Request('PATCH', '/api/tenants', [], json_encode(['name' => 'X']));
        $response = $this->handler->update($request, []);

        $this->assertSame(400, $response->getStatusCode());
    }
}
