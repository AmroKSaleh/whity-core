<?php

declare(strict_types=1);

namespace Tests\Security;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Sdk\Http\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;

/**
 * Cross-tenant RBAC denial & zero-leakage tests (WC-17, issue #13; updated for
 * WC-54 tenant-scoped, OU-aware authorization).
 *
 * Scope note: WC-22 owns the data-leakage / query-scoping angle of tenant
 * isolation. THIS file approaches cross-tenant access from the RBAC angle: it
 * proves that the authoritative {@see RoleChecker} authorizes against the
 * caller's OWN grants — so a user in tenant B whose role lacks `users:read` is
 * denied even though a tenant-A user's role grants it — and that every denial
 * response leaks ZERO internal data (no user id, role, tenant id, SQL, or query
 * result), satisfying AC2.
 *
 * Tenant-scoping nuance (WC-54): a user's DIRECT role grants are not themselves
 * tenant-partitioned in the schema, so cross-tenant denial for direct grants is
 * realised by the two tenants' users holding DIFFERENT roles. The OU-inherited
 * grant path IS tenant-partitioned and is covered exhaustively by
 * {@see \Tests\Auth\OuRoleInheritanceRealEngineTest}. The full real pipeline is
 * exercised here: real {@see JwtParser} → real {@see RbacMiddleware} → real
 * {@see RoleChecker} → in-memory SQLite seeded with the production schema, with
 * the resolved tenant locked into {@see TenantContext} ahead of RBAC.
 */
class RbacCrossTenantDenialTest extends TestCase
{
    private const SECRET = 'wc17-xtenant-secret-padded-for-hs256-min-32-byte-key';

    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $this->db = $this->wrapSqlite($this->pdo);
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    /**
     * Seed a profile + membership in a tenant whose dedicated role grants exactly $granted.
     *
     * Post-cutover (WC-idcut-E): RbacMiddleware reads profile_id from the JWT and
     * calls hasPermissionForProfile() which queries the memberships table. We seed
     * profiles + memberships instead of (or alongside) the legacy users table.
     *
     * @param array<int, string> $granted permissions granted to the profile's membership role
     */
    private function seedUser(int $userId, int $tenantId, array $granted): void
    {
        $roleName = 'role_' . $userId;
        $this->pdo->prepare('INSERT INTO roles (name, created_at) VALUES (?, NOW())')->execute([$roleName]);
        $roleId = (int) $this->pdo->lastInsertId();

        foreach ($granted as $permission) {
            $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')
                ->execute([$permission]);
            $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
            $stmt->execute([$permission]);
            $permissionId = (int) $stmt->fetchColumn();
            $this->pdo->prepare(
                'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())'
            )->execute([$roleId, $permissionId]);
        }

        // Seed a profile row (profiles table, migration 028).
        $this->pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, 'x', false, 0, 0, NOW(), NOW())"
        )->execute([$userId, "u{$userId}"]);

        // Seed an active membership so hasPermissionForProfile() resolves.
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', NOW())"
        )->execute([$userId, $tenantId, $roleId]);
    }

    private function roleChecker(): RoleChecker
    {
        return new RoleChecker($this->db, new PermissionRegistry());
    }

    /**
     * Dispatch a permission-protected GET /api/users through the real pipeline,
     * with the given resolved tenant locked into the context first.
     *
     * @param-out bool $handlerReached
     */
    private function dispatch(RbacMiddleware $middleware, int $tenantId, Request $request, ?bool &$handlerReached = null): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);

        $router = new Router('');
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
     * AC2: a caller from tenant B, whose own role does NOT grant users:read, is
     * denied 403 even though a tenant-A user's role grants the same permission.
     */
    public function testForeignTenantCallerIsDeniedDespiteSameNamedGrantInOtherTenant(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        // Tenant A user (100) has the grant; tenant B user (200) does not.
        $this->seedUser(100, 1, [CorePermissions::USERS_READ]);
        $this->seedUser(200, 2, []);
        $middleware = new RbacMiddleware($jwtParser, $this->roleChecker());

        $token = $jwtParser->create(['profile_id' => 200, 'active_tenant_id' => 2, 'email' => 'b@tenantb.example', 'role' => '', 'token_epoch' => 0]);
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = null;
        $response = $this->dispatch($middleware, 2, $request, $handlerReached);

        $this->assertFalse($handlerReached, 'Foreign-tenant caller must never reach the handler');
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Positive control: the tenant-A owner with the grant IS authorized, proving
     * the denial above is scoping and not a blanket deny.
     */
    public function testOwningTenantCallerIsAuthorized(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $this->seedUser(100, 1, [CorePermissions::USERS_READ]);
        $this->seedUser(200, 2, []);
        $middleware = new RbacMiddleware($jwtParser, $this->roleChecker());

        $token = $jwtParser->create(['profile_id' => 100, 'active_tenant_id' => 1, 'email' => 'a@tenanta.example', 'role' => '', 'token_epoch' => 0]);
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = null;
        $response = $this->dispatch($middleware, 1, $request, $handlerReached);

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
        $this->seedUser(200, 2, []);
        $middleware = new RbacMiddleware($jwtParser, $this->roleChecker());

        $token = $jwtParser->create(['profile_id' => 200, 'active_tenant_id' => 2, 'email' => 'b@tenantb.example', 'role' => '', 'token_epoch' => 0]);
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $response = $this->dispatch($middleware, 2, $request);

        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertSame(
            ['error' => 'Insufficient permissions', 'required' => CorePermissions::USERS_READ],
            $body,
            'Denial body must match the documented contract with no extra keys'
        );

        $raw = $response->getBody();
        foreach (['200', 'tenant', 'b@tenantb.example', 'sensitive', 'tenant-A-record', 'SELECT', 'role_id', 'user_id'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $raw,
                "Denial body must not leak '{$forbidden}'"
            );
        }
    }

    /**
     * AC2 (zero leakage): a 401 authentication failure likewise exposes only a
     * generic error message and no token or caller details.
     */
    public function testAuthenticationFailureLeaksNoTokenDetails(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        // Token signed by a different secret -> signature failure -> 401.
        $foreignToken = (new JwtParser('some-other-secret-padded-for-hs256-min-32-byte-key'))->create(['profile_id' => 200, 'active_tenant_id' => 2]);
        $middleware = new RbacMiddleware($jwtParser, $this->roleChecker());

        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$foreignToken}"]);
        $response = $this->dispatch($middleware, 2, $request);

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

    // ==================== Helpers ====================

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();

        // Seed the two test tenants so that users.tenant_id FK is satisfied on
        // both SQLite (no FK enforcement by default) and PostgreSQL (FK enforced).
        // Migration 010 seeds the system tenant (id=0); only the two test tenants
        // need inserting here.  INSERT OR IGNORE is translated to ON CONFLICT DO
        // NOTHING by the PG-path PDO wrapper in SchemaFromMigrations.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");

        return $pdo;
    }
}
