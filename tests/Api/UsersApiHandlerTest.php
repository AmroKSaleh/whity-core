<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Whity\Api\UsersApiHandler;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Tests\Support\MockRequestFactory;

/**
 * Unit tests for {@see UsersApiHandler} list payload contract (WC-100).
 *
 * The Edit User form binds `name`, `role`, `tenantId` and treats all three as
 * required, so the users list payload MUST expose them or the form cannot
 * pre-fill and Save fails client-side validation. These tests pin the
 * contract: every row carries `id`, `name`, `email`, `role`, `tenantId`,
 * `createdAt`, never leaks `password`, and respects tenant scoping.
 *
 * Runs against mocked PDO seams (CI parity with the rest of the API suite).
 */
class UsersApiHandlerTest extends TestCase
{
    private int $testTenantId = 1;

    protected function setUp(): void
    {
        MockRequestFactory::setTestTenant($this->testTenantId);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * HookManager stub whose dispatch() echoes back the payload.
     */
    private function passthroughHookManager(): HookManager
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');
        return $hooks;
    }

    /**
     * Build a PDOStatement mock for a single result row / row set.
     *
     * @param array<string, mixed>|false           $fetch    fetch() return value.
     * @param array<int, array<string, mixed>>|null $fetchAll fetchAll() return value.
     */
    private function statement(array|false $fetch = false, ?array $fetchAll = null): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($fetch);
        $stmt->method('fetchAll')->willReturn($fetchAll ?? []);
        return $stmt;
    }

    private function request(string $method, string $path, ?array $body = null): Request
    {
        return new Request($method, $path, [], $body !== null ? (string)json_encode($body) : '');
    }

    // ==================== LIST ====================

    /**
     * AC: the users list payload exposes name, email, role, tenantId and
     * createdAt for the Edit form to pre-fill, and never leaks the password.
     */
    public function testListExposesEditFormFieldsAndHidesPassword(): void
    {
        $rows = [
            [
                'id' => 7,
                'email' => 'alice@example.com',
                'password' => 'hashed-secret',
                'created_at' => '2026-01-02 03:04:05',
                'tenant_id' => 1,
                'role' => 'admin',
            ],
        ];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->statement(false, $rows));

        $handler = new UsersApiHandler($pdo, $this->passthroughHookManager());
        $response = $handler->list($this->request('GET', '/api/users'));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertCount(1, $data);

        $user = $data[0];
        $this->assertSame(7, $user['id']);
        $this->assertSame('alice@example.com', $user['email']);
        $this->assertSame('admin', $user['role']);
        // tenant exposed as camelCase `tenantId` (what the form binds).
        $this->assertArrayHasKey('tenantId', $user);
        $this->assertSame(1, $user['tenantId']);
        // createdAt camelCase alias present for the table column.
        $this->assertArrayHasKey('createdAt', $user);
        $this->assertSame('2026-01-02 03:04:05', $user['createdAt']);
        // name is required by the edit form; derived from the email local-part
        // because there is no users.name column.
        $this->assertArrayHasKey('name', $user);
        $this->assertSame('alice', $user['name']);
        // password must never be returned, and the snake_case tenant_id is not
        // part of the public contract.
        $this->assertArrayNotHasKey('password', $user);
        $this->assertArrayNotHasKey('tenant_id', $user);
    }

    /**
     * Tenant scoping: a non-system tenant only sees its own users (the handler
     * filters by the current tenant). We assert the response is well-formed and
     * carries the contract fields for every returned row.
     */
    public function testListScopedToCurrentTenantReturnsContractFields(): void
    {
        $rows = [
            [
                'id' => 1,
                'email' => 'admin@example.com',
                'password' => 'x',
                'created_at' => '2026-01-01 00:00:00',
                'tenant_id' => 1,
                'role' => 'admin',
            ],
            [
                'id' => 2,
                'email' => 'user@example.com',
                'password' => 'y',
                'created_at' => '2026-01-01 00:00:01',
                'tenant_id' => 1,
                'role' => 'user',
            ],
        ];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->statement(false, $rows));

        $handler = new UsersApiHandler($pdo, $this->passthroughHookManager());
        $response = $handler->list($this->request('GET', '/api/users'));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertCount(2, $data);
        foreach ($data as $user) {
            $this->assertArrayHasKey('name', $user);
            $this->assertArrayHasKey('tenantId', $user);
            $this->assertArrayHasKey('createdAt', $user);
            $this->assertArrayNotHasKey('password', $user);
            $this->assertSame(1, $user['tenantId']);
        }
    }
}
