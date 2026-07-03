<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DelegationsApiHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Delegation\DelegationRepository;
use Whity\Core\Delegation\DelegationService;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Sdk\Http\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;

/**
 * Integration tests proving the WC-34 delegation endpoints are RBAC route-gated
 * AND that a live delegation actually grants access through a real
 * permission-gated route — driving the real {@see RbacMiddleware},
 * {@see RoleChecker}, {@see Router}, {@see DelegationService} and
 * {@see DelegationsApiHandler} together, exactly as the HTTP kernel does.
 *
 * Mirrors {@see PluginsApiRbacTest}: real in-memory SQLite store
 * (`PDO::ATTR_STRINGIFY_FETCHES` on, for Postgres parity), colon-notation
 * permissions, dispatch through the matched route's required permission.
 */
class DelegationsApiRbacTest extends TestCase
{
    private const SECRET = 'test-secret-key-padded-for-hs256-min-32-byte-key';
    private const TENANT = 1;

    private JwtParser $jwtParser;
    private PermissionRegistry $registry;
    private Router $router;
    private PDO $pdo;
    private Database $db;
    private RoleChecker $roleChecker;
    private RbacMiddleware $middleware;
    private DelegationsApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $this->jwtParser = new JwtParser(self::SECRET);
        $this->registry = new PermissionRegistry();
        $this->pdo = self::makeSchema();
        $this->db = self::wrapSqlite($this->pdo);

        $repo = new DelegationRepository($this->pdo);
        $baseChecker = new RoleChecker($this->db, $this->registry);
        $service = new DelegationService($repo, $baseChecker, $this->registry);

        // The enforcement checker IS delegation-aware (matches public/index.php).
        $this->roleChecker = new RoleChecker($this->db, $this->registry, null, $service);
        $this->middleware = new RbacMiddleware($this->jwtParser, $this->roleChecker);

        $this->handler = new DelegationsApiHandler($this->pdo, $service);

        $this->router = new Router('');
        $this->router->register('GET', '/api/delegations', [$this->handler, 'list'], null, null, CorePermissions::DELEGATION_MANAGE);
        $this->router->register('POST', '/api/delegations', [$this->handler, 'create'], null, null, CorePermissions::DELEGATION_MANAGE);
        $this->router->register('DELETE', '/api/delegations/{id}', [$this->handler, 'revoke'], null, null, CorePermissions::DELEGATION_MANAGE);
        // A real permission-gated probe route used to prove delegated access.
        $this->router->register('GET', '/api/probe', [$this, 'probeHandler'], null, null, 'users:read');
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    public function probeHandler(Request $request): Response
    {
        return Response::json(['ok' => true], 200);
    }

    public function testListWithoutDelegationManageIsForbidden(): void
    {
        $userId = $this->seedUserWithPermissions([CorePermissions::USERS_READ]);

        $response = $this->dispatch(new Request('GET', '/api/delegations', $this->auth($userId)));

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('delegation:manage', $body['required']);
    }

    public function testListWithoutTokenIsUnauthorized(): void
    {
        $this->assertSame(401, $this->dispatch(new Request('GET', '/api/delegations'))->getStatusCode());
    }

    public function testCreateWithDelegationManageReachesHandler(): void
    {
        $grantorId = $this->seedUserWithPermissions([CorePermissions::DELEGATION_MANAGE, CorePermissions::USERS_READ]);
        $granteeId = $this->seedPlainUser('grantee@example.com');

        $response = $this->dispatch(new Request(
            'POST',
            '/api/delegations',
            $this->auth($grantorId),
            (string) json_encode([
                'granteeType' => 'user',
                'granteeId' => $granteeId,
                'permissions' => ['users:read'],
            ])
        ));

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testDelegatedPermissionGrantsAccessToAGatedRouteEndToEnd(): void
    {
        // Grantor holds delegation:manage + users:read.
        $grantorId = $this->seedUserWithPermissions([CorePermissions::DELEGATION_MANAGE, CorePermissions::USERS_READ]);
        // Grantee is a plain user who lacks users:read.
        $granteeId = $this->seedPlainUser('grantee@example.com');

        // Grantee is initially denied the users:read-gated probe route.
        $denied = $this->dispatch(new Request('GET', '/api/probe', $this->auth($granteeId)));
        $this->assertSame(403, $denied->getStatusCode());

        // Grantor delegates users:read to the grantee.
        $create = $this->dispatch(new Request(
            'POST',
            '/api/delegations',
            $this->auth($grantorId),
            (string) json_encode([
                'granteeType' => 'user',
                'granteeId' => $granteeId,
                'permissions' => ['users:read'],
            ])
        ));
        $this->assertSame(201, $create->getStatusCode());
        RoleChecker::clearCache();

        // Now the grantee passes the gated route through the delegated permission.
        $allowed = $this->dispatch(new Request('GET', '/api/probe', $this->auth($granteeId)));
        $this->assertSame(200, $allowed->getStatusCode(), 'A live delegation must unlock the gated route for the grantee.');

        // Revoke, and access is removed again.
        $delegationId = (int) json_decode($create->getBody(), true)['data']['ids'][0];
        $revoke = $this->dispatch(new Request('DELETE', '/api/delegations/' . $delegationId, $this->auth($grantorId)));
        $this->assertSame(200, $revoke->getStatusCode());
        RoleChecker::clearCache();

        $reDenied = $this->dispatch(new Request('GET', '/api/probe', $this->auth($granteeId)));
        $this->assertSame(403, $reDenied->getStatusCode(), 'Revoking the delegation must re-deny the gated route.');
    }

    public function testCreateRejectsDelegationOfUnheldPermissionWith422(): void
    {
        // Grantor holds delegation:manage but NOT roles:read.
        $grantorId = $this->seedUserWithPermissions([CorePermissions::DELEGATION_MANAGE]);
        $granteeId = $this->seedPlainUser('grantee@example.com');

        $response = $this->dispatch(new Request(
            'POST',
            '/api/delegations',
            $this->auth($grantorId),
            (string) json_encode([
                'granteeType' => 'user',
                'granteeId' => $granteeId,
                'permissions' => ['roles:read'],
            ])
        ));

        $this->assertSame(422, $response->getStatusCode(), 'Delegating an unheld permission must be 422 even with delegation:manage.');
    }

    public function testGrantorCannotReDelegateAPermissionHeldOnlyViaDelegation(): void
    {
        // Grantor holds delegation:manage through their ROLE, but NOT users:read.
        $grantorId = $this->seedUserWithPermissions([CorePermissions::DELEGATION_MANAGE]);
        $granteeId = $this->seedPlainUser('grantee@example.com');

        // Someone ELSE delegates users:read directly TO the grantor, so the grantor
        // now "has" users:read ONLY via delegation — never through a role.
        // WC-bc07b6de: permission_delegations references grantor_profile_id/grantee
        // profile_id (profiles.id). Both ids below are real profile ids with active
        // memberships. grantee_id equals $grantorId so the grantor resolves the
        // delegated permission via its own profile-keyed delegation lookup.
        $externalDelegatorId = $this->seedPlainUser('external-delegator@example.com');
        $this->pdo->prepare(
            'INSERT INTO permission_delegations
                (tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, ou_id, granted_at)
             VALUES (?, ?, ?, ?, ?, NULL, NOW())'
        )->execute([self::TENANT, $externalDelegatorId, DelegationRepository::GRANTEE_PROFILE, $grantorId, 'users:read']);
        RoleChecker::clearCache();

        // The grantor attempts to RE-DELEGATE users:read to the grantee. This drives
        // the real worker ordering: RbacMiddleware first resolves the grantor's
        // delegation-INCLUSIVE set (to check delegation:manage), then the handler's
        // DelegationService bounds the grantor with the delegation-UNAWARE checker.
        // The two share a static cache; the bound MUST stay base-only.
        $response = $this->dispatch(new Request(
            'POST',
            '/api/delegations',
            $this->auth($grantorId),
            (string) json_encode([
                'granteeType' => 'user',
                'granteeId' => $granteeId,
                'permissions' => ['users:read'],
            ])
        ));

        // You may delegate only what RBAC grants you, never what was delegated TO
        // you — no transitive re-delegation escalation.
        $this->assertSame(
            422,
            $response->getStatusCode(),
            'A permission held only via delegation must NOT be re-delegable (no transitive escalation).'
        );

        // And nothing was written for the grantee.
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM permission_delegations WHERE grantee_id = ? AND permission = ?'
        );
        $stmt->execute([$granteeId, 'users:read']);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'No delegation row may be created on rejection.');
    }

    // ==================== Harness ====================

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request);
        if ($match === null) {
            return Response::error('Not Found', 404);
        }

        $handler = $match['handler'];
        $params = $match['params'];
        $next = static fn (Request $req): Response => $handler($req, $params);

        return $this->middleware->handle($request, $next, $match['requiredRole'], $match['requiredPermission']);
    }

    /**
     * Mint a JWT for a PROFILE (ADR 0005 shape). The grantor/grantee identities in
     * these tests are profiles with real memberships, matching the production
     * model after migration 035/037.
     *
     * @return array<string, string>
     */
    private function auth(int $profileId): array
    {
        $token = $this->jwtParser->create([
            'profile_id' => $profileId,
            'email' => "profile{$profileId}@example.com",
            'active_tenant_id' => self::TENANT,
            'tenant_id' => self::TENANT,
        ]);

        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Seed a PROFILE with an ACTIVE membership in the test tenant whose dedicated
     * role grants exactly the given permissions; returns the profile id.
     *
     * @param array<int, string> $permissions
     */
    private function seedUserWithPermissions(array $permissions): int
    {
        $this->pdo->prepare('INSERT INTO roles (name, created_at) VALUES (?, NOW())')
            ->execute(['role_' . uniqid('', true)]);
        $roleId = (int) $this->pdo->lastInsertId();

        foreach ($permissions as $permission) {
            $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')->execute([$permission]);
            $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
            $stmt->execute([$permission]);
            $permissionId = (int) $stmt->fetchColumn();
            $this->pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
                ->execute([$roleId, $permissionId]);
        }

        return $this->seedProfileWithMembership($roleId);
    }

    /**
     * Seed a plain PROFILE with an active membership carrying the base 'user'
     * role (id 2, grants nothing); returns the profile id.
     */
    private function seedPlainUser(string $email): int
    {
        return $this->seedProfileWithMembership(2);
    }

    /**
     * Create a profile + an ACTIVE membership binding it to the test tenant with
     * the given role. Returns the profile id (the identity used in JWT claims).
     */
    private function seedProfileWithMembership(int $roleId): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles
                 (display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version,
                  token_epoch, created_at, updated_at)
             VALUES ('', '', false, 0, 0, NOW(), NOW())"
        )->execute();
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, NULL, 'active', NOW())"
        )->execute([$profileId, self::TENANT, $roleId]);

        return $profileId;
    }

    private static function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    private static function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        // system tenant (id=0) and global roles (1=admin, 2=user) come from migrations.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0,'system'),(1,'tenant-a'),(2,'tenant-b')");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, created_at) VALUES (1,'admin',NOW()),(2,'user',NOW())");

        return $pdo;
    }
}
