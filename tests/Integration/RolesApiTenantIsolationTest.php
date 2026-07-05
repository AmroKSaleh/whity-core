<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\RolesApiHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;

/**
 * Integration tests for the Roles API (WC-16, issue #9).
 *
 * Two concerns are exercised end-to-end against mocked database seams (CI has no
 * live PostgreSQL):
 *
 *  1. Tenant isolation — a role created by Tenant A never appears when Tenant B
 *     lists roles, because every read is filtered through the tenant-scoped
 *     `user_roles` junction (AC2).
 *  2. RBAC route protection — the real {@see Router} + {@see RbacMiddleware} +
 *     {@see RoleChecker} pipeline gates /api/roles writes behind a roles
 *     permission, so an under-privileged caller is denied before the handler
 *     runs.
 */
class RolesApiTenantIsolationTest extends TestCase
{
    private const SECRET = 'test-secret-key-padded-for-hs256-min-32-byte-key';

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    /**
     * Build a PDO whose role-list query returns $rows only for $ownerTenantId and
     * an empty set for every other tenant, simulating tenant-scoped pagination.
     *
     * Pagination adds a COUNT query (fetch) before the SELECT (fetchAll). Both
     * queries bind :tenant_id via bindValue; the mock captures that value so the
     * fetchAll callback can scope results to the correct tenant.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function tenantScopedListPdo(int $ownerTenantId, array $rows): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($ownerTenantId, $rows): PDOStatement {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('execute')->willReturn(true);
                // The tenant-scoped queries bind :tenant_id via bindValue (not execute params).
                $stmt->method('bindValue')->willReturnCallback(
                    function (mixed $param, mixed $value): bool {
                        if ($param === ':tenant_id') {
                            $this->boundTenantId = (int) $value;
                        }
                        return true;
                    }
                );
                $stmt->method('fetchAll')->willReturnCallback(
                    fn(): array => ($this->boundTenantId === $ownerTenantId) ? $rows : []
                );
                $stmt->method('fetch')->willReturn(['cnt' => count($rows)]);
                return $stmt;
            }
        );
        return $pdo;
    }

    /** @var int|null Captured tenant id bound to the most recent scoped query. */
    private ?int $boundTenantId = null;

    /**
     * AC2: Tenant A's 'Custom Admin' role does not appear for Tenant B.
     */
    public function testRoleCreatedByOneTenantIsInvisibleToAnother(): void
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        $customAdmin = [
            'id' => 1,
            'name' => 'Custom Admin',
            'description' => 'Tenant A only',
            'parent_id' => null,
            'created_at' => 'now',
            'permission_count' => 4,
        ];

        $pdo = $this->tenantScopedListPdo(1, [$customAdmin]);
        $handler = new RolesApiHandler($pdo, $hooks);

        // Tenant A (id 1) sees its role.
        MockRequestFactory::setTestTenant(1);
        $responseA = $handler->list(new Request('GET', '/api/roles'));
        $dataA = json_decode($responseA->getBody(), true)['data'];
        $this->assertCount(1, $dataA);
        $this->assertSame('Custom Admin', $dataA[0]['name']);

        // Tenant B (id 2) must NOT see Tenant A's role.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(2);
        $responseB = $handler->list(new Request('GET', '/api/roles'));
        $dataB = json_decode($responseB->getBody(), true)['data'];
        $this->assertSame([], $dataB, "Tenant B must not see Tenant A's Custom Admin role");
    }

    /**
     * AC2 (write path): Tenant B cannot read or mutate a role scoped to Tenant A;
     * the handler returns 404 rather than leaking the other tenant's role.
     */
    public function testCrossTenantRoleAccessReturns404(): void
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        // Visibility probe finds no user_roles link for the requesting tenant.
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (): PDOStatement {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('execute')->willReturn(true);
                $stmt->method('fetch')->willReturn(false);
                $stmt->method('fetchAll')->willReturn([]);
                return $stmt;
            }
        );
        $handler = new RolesApiHandler($pdo, $hooks);

        MockRequestFactory::setTestTenant(2);

        $this->assertSame(404, $handler->get(new Request('GET', '/api/roles/1'), ['id' => '1'])->getStatusCode());
        $this->assertSame(
            404,
            $handler->update(new Request('PATCH', '/api/roles/1', [], '{"name":"x"}'), ['id' => '1'])->getStatusCode()
        );
        $this->assertSame(404, $handler->delete(new Request('DELETE', '/api/roles/1'), ['id' => '1'])->getStatusCode());
    }

    /**
     * RBAC route protection: a caller without a roles-write permission is denied
     * by the middleware before the create handler runs.
     */
    public function testRolesWriteRouteDeniesUnderprivilegedCaller(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $registry = new PermissionRegistry(); // core perms register lazily

        // Mirror production: the tenant is resolved/locked before RBAC runs. The
        // caller (user 7) holds a role granting only an unrelated read permission.
        TenantContext::setTenantId(1);
        $db = $this->roleCheckerDbGranting(7, [CorePermissions::USERS_READ]);

        $roleChecker = new RoleChecker($db, $registry);
        $middleware = new RbacMiddleware($jwtParser, $roleChecker);
        $router = new Router('');
        $router->register(
            'POST',
            '/api/roles',
            static fn(): Response => new Response(201, '{}'),
            null,
            null,
            CorePermissions::ROLES_WRITE
        );

        $token = $jwtParser->create(['profile_id' => 7, 'email' => 'limited@example.com', 'active_tenant_id' => 1, 'token_epoch' => 0]);
        $request = new Request('POST', '/api/roles', ['Authorization' => "Bearer {$token}"], '{"name":"X"}');

        $match = $router->match($request);
        $this->assertNotNull($match);

        $handlerReached = false;
        $response = $middleware->handle(
            $request,
            function (Request $req) use (&$handlerReached): Response {
                $handlerReached = true;
                return new Response(201, '{}');
            },
            $match['requiredRole'],
            $match['requiredPermission']
        );

        $this->assertFalse($handlerReached, 'Create handler must not run without roles:write');
        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(CorePermissions::ROLES_WRITE, $body['required']);
    }

    /**
     * RBAC route protection: a caller WITH the roles-write permission reaches the
     * create handler.
     */
    public function testRolesWriteRouteAllowsPrivilegedCaller(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $registry = new PermissionRegistry();

        // Mirror production: tenant resolved/locked before RBAC. The caller
        // (user 8) holds a role granting roles:write.
        TenantContext::setTenantId(1);
        $db = $this->roleCheckerDbGranting(8, [CorePermissions::ROLES_WRITE]);

        $roleChecker = new RoleChecker($db, $registry);
        $middleware = new RbacMiddleware($jwtParser, $roleChecker);
        $router = new Router('');
        $router->register(
            'POST',
            '/api/roles',
            static fn(): Response => new Response(201, '{}'),
            null,
            null,
            CorePermissions::ROLES_WRITE
        );

        $token = $jwtParser->create(['profile_id' => 8, 'email' => 'admin@example.com', 'active_tenant_id' => 1, 'token_epoch' => 0]);
        $request = new Request('POST', '/api/roles', ['Authorization' => "Bearer {$token}"], '{"name":"X"}');

        $match = $router->match($request);
        $this->assertNotNull($match);

        $handlerReached = false;
        $response = $middleware->handle(
            $request,
            function (Request $req) use (&$handlerReached): Response {
                $handlerReached = true;
                return new Response(201, json_encode(['data' => ['id' => 1]]));
            },
            $match['requiredRole'],
            $match['requiredPermission']
        );

        $this->assertTrue($handlerReached, 'Create handler should run with roles:write');
        $this->assertSame(201, $response->getStatusCode());
    }

    /**
     * Build a {@see Database} over a real in-memory SQLite engine in which the
     * given user (tenant 1, no OU) holds a dedicated role granting exactly
     * $granted — used to drive the RoleChecker honestly through its multi-query
     * resolution flow rather than a single stubbed query shape.
     *
     * @param array<int, string> $granted Permissions the user's role grants.
     */
    private function roleCheckerDbGranting(int $userId, array $granted): Database
    {
        $pdo = SchemaFromMigrations::make();

        // Tenant 1 hosts the seeded caller; seed it so users.tenant_id FK is
        // satisfied on PostgreSQL (SQLite does not enforce FKs by default).
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a')");

        $pdo->prepare('INSERT INTO roles (name, created_at) VALUES (?, NOW())')->execute(['role_' . $userId]);
        $roleId = (int) $pdo->lastInsertId();
        foreach ($granted as $permission) {
            $pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')->execute([$permission]);
            $stmt = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
            $stmt->execute([$permission]);
            $permissionId = (int) $stmt->fetchColumn();
            $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
                ->execute([$roleId, $permissionId]);
        }
        $pdo->prepare(
            "INSERT OR IGNORE INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        )->execute([$userId, "u{$userId}"]);

        $pdo->prepare(
            "INSERT OR IGNORE INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, 1, ?, 'active', datetime('now'))"
        )->execute([$userId, $roleId]);

        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }
}
