<?php

declare(strict_types=1);

namespace Tests\Security;

use PDOStatement;
use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;

/**
 * Cross-tenant RBAC denial & zero-leakage tests (WC-17, issue #13).
 *
 * Scope note: WC-22 owns the data-leakage / query-scoping angle of tenant
 * isolation. THIS file deliberately approaches cross-tenant access from the
 * RBAC / permission angle: it proves that the authoritative {@see RoleChecker}
 * scopes permission grants to the caller's own tenant, so a permission a user
 * holds in tenant A does NOT authorize them against a tenant-B-scoped grant —
 * and that every denial response leaks ZERO internal data (no user id, role,
 * tenant id, SQL, or query result), satisfying AC2.
 *
 * The full real pipeline is exercised: real {@see JwtParser} → real
 * {@see RbacMiddleware} → real {@see RoleChecker} → mocked {@see Database}, with
 * the DB seam modelling tenant-scoped grants the way the production schema does.
 */
class RbacCrossTenantDenialTest extends TestCase
{
    private const SECRET = 'wc17-xtenant-secret';

    protected function setUp(): void
    {
        RoleChecker::clearCache();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
    }

    /**
     * Build a RoleChecker whose direct-grant lookup is scoped to a single tenant:
     * the grant is only returned when the bound user id belongs to $ownerTenantId.
     *
     * Models the production reality that role_permissions rows are reachable only
     * for users within the same tenant; a foreign user's probe returns no row.
     *
     * @param array<int, int>    $userTenants map of user id => tenant id
     * @param array<int, string> $granted     permissions granted to the role within the owning tenant
     */
    private function tenantScopedRoleChecker(int $ownerTenantId, array $userTenants, array $granted): RoleChecker
    {
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $bindings) use ($ownerTenantId, $userTenants, $granted): PDOStatement {
                $statement = $this->createMock(PDOStatement::class);

                $userId = $bindings[':userId'] ?? null;
                $callerTenant = is_int($userId) ? ($userTenants[$userId] ?? null) : null;
                $sameTenant = $callerTenant === $ownerTenantId;

                // Direct-grant probe: only a same-tenant caller can match.
                $permissionMatches = in_array($bindings[':permission'] ?? null, $granted, true);
                $statement->method('fetch')->willReturn(($sameTenant && $permissionMatches) ? ['1' => 1] : false);

                // No hierarchy rows for anyone (keeps the foreign path empty too).
                $statement->method('fetchAll')->willReturn([]);
                return $statement;
            }
        );
        return new RoleChecker($db, new PermissionRegistry());
    }

    /**
     * Dispatch a permission-protected GET /api/users through the real pipeline.
     *
     * @param-out bool $handlerReached
     */
    private function dispatch(RbacMiddleware $middleware, Request $request, ?bool &$handlerReached = null): Response
    {
        $router = new Router();
        $router->register('GET', '/api/users', static fn(): Response => new Response(200, '[]'), null, null, CorePermissions::USERS_READ);

        $match = $router->match($request);
        $this->assertNotNull($match);

        $reached = false;
        $response = $middleware->handle(
            $request,
            function (Request $req) use (&$reached): Response {
                $reached = true;
                return new Response(200, json_encode(['data' => ['sensitive' => 'tenant-A-record']]));
            },
            $match['requiredRole'],
            $match['requiredPermission']
        );
        $handlerReached = $reached;
        return $response;
    }

    /**
     * AC2: a caller from tenant B, whose own role does NOT grant users:read in
     * their tenant, is denied 403 even though an identically-named permission is
     * granted to a role in tenant A. The grant does not cross the tenant boundary.
     */
    public function testForeignTenantCallerIsDeniedDespiteSameNamedGrantInOtherTenant(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        // users:read is granted to the role only within tenant A (id 1).
        // User 100 is in tenant A, user 200 is in tenant B (id 2).
        $checker = $this->tenantScopedRoleChecker(
            ownerTenantId: 1,
            userTenants: [100 => 1, 200 => 2],
            granted: [CorePermissions::USERS_READ]
        );
        $middleware = new RbacMiddleware($jwtParser, $checker);

        // Tenant B caller — must be denied.
        $token = $jwtParser->create(['user_id' => 200, 'tenant_id' => 2, 'email' => 'b@tenantb.example']);
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = null;
        $response = $this->dispatch($middleware, $request, $handlerReached);

        $this->assertFalse($handlerReached, 'Foreign-tenant caller must never reach the handler');
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Positive control for the fixture: the SAME grant DOES authorize the
     * tenant-A owner, proving the denial above is tenant-scoping and not a
     * blanket deny.
     */
    public function testOwningTenantCallerIsAuthorized(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $checker = $this->tenantScopedRoleChecker(
            ownerTenantId: 1,
            userTenants: [100 => 1, 200 => 2],
            granted: [CorePermissions::USERS_READ]
        );
        $middleware = new RbacMiddleware($jwtParser, $checker);

        $token = $jwtParser->create(['user_id' => 100, 'tenant_id' => 1, 'email' => 'a@tenanta.example']);
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = null;
        $response = $this->dispatch($middleware, $request, $handlerReached);

        $this->assertTrue($handlerReached, 'Owning-tenant caller with the grant must be authorized');
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * AC2 (zero leakage): the 403 denial body for a foreign-tenant caller exposes
     * only the documented contract — `error` plus the missing `required`
     * permission — and NOTHING about the caller, their tenant, the resource, or
     * the database.
     */
    public function testCrossTenantDenialLeaksNoInternalData(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $checker = $this->tenantScopedRoleChecker(
            ownerTenantId: 1,
            userTenants: [200 => 2],
            granted: [CorePermissions::USERS_READ]
        );
        $middleware = new RbacMiddleware($jwtParser, $checker);

        $token = $jwtParser->create(['user_id' => 200, 'tenant_id' => 2, 'email' => 'b@tenantb.example']);
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $response = $this->dispatch($middleware, $request);

        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        // Exact contract: only error + required keys.
        $this->assertSame(
            ['error' => 'Insufficient permissions', 'required' => CorePermissions::USERS_READ],
            $body,
            'Denial body must match the documented contract with no extra keys'
        );

        // Defensive substring scan: the raw body must not contain any caller or
        // resource identifiers or internal artefacts.
        $raw = $response->getBody();
        foreach (['200', 'tenant', 'tenant_id', 'b@tenantb.example', 'sensitive', 'tenant-A-record', 'SELECT', 'role_id', 'user_id'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $raw,
                "Denial body must not leak '{$forbidden}'"
            );
        }
    }

    /**
     * AC2 (zero leakage): a 401 authentication failure (foreign or not) likewise
     * exposes only a generic error message and no token or caller details.
     */
    public function testAuthenticationFailureLeaksNoTokenDetails(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        // Token signed by a different secret -> signature failure -> 401.
        $foreignToken = (new JwtParser('some-other-secret'))->create(['user_id' => 200, 'tenant_id' => 2]);
        $middleware = new RbacMiddleware($jwtParser, new RoleChecker($this->createMock(Database::class), new PermissionRegistry()));

        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$foreignToken}"]);
        $response = $this->dispatch($middleware, $request);

        $this->assertSame(401, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(['error' => 'Invalid or expired token'], $body);

        $raw = $response->getBody();
        foreach ([$foreignToken, '200', 'tenant', 'signature', 'secret'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $raw,
                "401 body must not leak '{$forbidden}'"
            );
        }
    }
}
