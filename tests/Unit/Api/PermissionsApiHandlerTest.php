<?php

namespace Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Whity\Api\PermissionsApiHandler;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use PDO;
use PDOStatement;

class PermissionsApiHandlerTest extends TestCase
{
    private PDO $mockDb;
    private PermissionsApiHandler $handler;

    protected function setUp(): void
    {
        $this->mockDb = $this->createMock(PDO::class);
        $this->handler = new PermissionsApiHandler($this->mockDb);
    }

    /**
     * Test successful retrieval of permissions
     */
    public function testListPermissionsReturns200(): void
    {
        $expectedPermissions = [
            ['id' => 1, 'name' => 'users:read', 'description' => 'Read users'],
            ['id' => 2, 'name' => 'users:create', 'description' => 'Create users']
        ];

        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        $mockStatement->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedPermissions);

        $this->mockDb->expects($this->once())
            ->method('prepare')
            ->willReturn($mockStatement);

        $request = new Request('GET', '/api/permissions');
        $response = $this->handler->list($request);

        $this->assertSame(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('data', $responseData);
        $this->assertSame($expectedPermissions, $responseData['data']);
    }

    /**
     * Test retrieval when no permissions exist returns 200 with empty array
     */
    public function testListPermissionsEmptyReturns200(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        $mockStatement->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]);

        $this->mockDb->expects($this->once())
            ->method('prepare')
            ->willReturn($mockStatement);

        $request = new Request('GET', '/api/permissions');
        $response = $this->handler->list($request);

        $this->assertSame(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('data', $responseData);
        $this->assertSame([], $responseData['data']);
    }

    /**
     * Test database error during retrieval returns 500
     */
    public function testListPermissionsDatabaseErrorReturns500(): void
    {
        $this->mockDb->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection failed'));

        $request = new Request('GET', '/api/permissions');
        $response = $this->handler->list($request);

        $this->assertSame(500, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('Failed to fetch permissions', $responseData['error']);
    }

    /**
     * Test that registry permissions absent from the database are merged in with source tags.
     */
    public function testListMergesRegistryPermissions(): void
    {
        $dbPermissions = [
            ['id' => 1, 'name' => 'users:read', 'description' => 'Read users'],
        ];

        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn($dbPermissions);
        $this->mockDb->method('prepare')->willReturn($mockStatement);

        $registry = new PermissionRegistry();
        // 'users:read' already exists in the DB result and must not be duplicated.
        $registry->register('core', ['users:read']);
        $registry->register('invoices', ['invoices:read', 'invoices:write']);

        $handler = new PermissionsApiHandler($this->mockDb, $registry);

        $request = new Request('GET', '/api/permissions');
        $response = $handler->list($request);

        $this->assertSame(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $names = array_column($responseData['data'], 'name');

        $this->assertContains('users:read', $names);
        $this->assertContains('invoices:read', $names);
        $this->assertContains('invoices:write', $names);
        // 'users:read' came from the DB row and must appear exactly once.
        $this->assertSame(1, count(array_keys($names, 'users:read', true)));

        // Registry-sourced rows carry their source tag.
        $invoiceRow = null;
        foreach ($responseData['data'] as $row) {
            if ($row['name'] === 'invoices:read') {
                $invoiceRow = $row;
                break;
            }
        }
        $this->assertNotNull($invoiceRow);
        $this->assertSame('invoices', $invoiceRow['source']);
    }

    /**
     * Test that without a registry the handler returns only database permissions (unchanged behaviour).
     */
    public function testListWithoutRegistryReturnsOnlyDatabasePermissions(): void
    {
        $dbPermissions = [
            ['id' => 1, 'name' => 'users:read', 'description' => 'Read users'],
        ];

        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn($dbPermissions);
        $this->mockDb->method('prepare')->willReturn($mockStatement);

        $handler = new PermissionsApiHandler($this->mockDb);

        $request = new Request('GET', '/api/permissions');
        $response = $handler->list($request);

        $responseData = json_decode($response->getBody(), true);
        $this->assertSame($dbPermissions, $responseData['data']);
    }
}
