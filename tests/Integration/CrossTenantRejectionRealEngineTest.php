<?php

declare(strict_types=1);

namespace Tests\Integration;

use HelloWorld\Api\GreetingsApiHandler;
use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Api\AuditLogApiHandler;
use Whity\Api\OusApiHandler;
use Whity\Api\RolesApiHandler;
use Whity\Api\UsersApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Delegation\DelegationRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

require_once dirname(__DIR__, 2) . '/plugins/HelloWorld/Api/GreetingsApiHandler.php';

/**
 * WC-161: per-table cross-tenant read/write rejection on a REAL SQL engine.
 *
 * The platform's tenant isolation is enforced by the explicit `tenant_id`
 * predicates the handlers/repositories write into every statement (plus the
 * HTTP-layer EnforceTenantIsolation middleware). There is NO query-rewriting
 * layer — the previously advertised ScopesToTenant trait never ran in
 * production and was removed by WC-161 — so the predicates themselves are the
 * security boundary and must be proven against a genuine engine, not mocked
 * PDO (a mock cannot catch a forgotten or mis-joined predicate).
 *
 * This suite drives the REAL handlers/repositories against in-memory SQLite
 * (PDO::ATTR_STRINGIFY_FETCHES on, mirroring PostgreSQL's string fetches) and
 * proves, per tenant-owned table: (1) list/read scoping, (2) cross-tenant
 * read rejection, (3) cross-tenant WRITE rejection with the row verified
 * untouched, and (4) the system tenant (id 0) seeing across tenants.
 * Persons/relations get the same proof in {@see \Tests\Core\Relations\RelationsRealEngineTest}
 * and {@see \Tests\Api\RelationsApiHandlerRealEngineTest}; the real-PostgreSQL
 * dimension is covered by the dev stack / postgres-integration CI job running
 * the same handler SQL.
 */
final class CrossTenantRejectionRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const SYSTEM_TENANT = 0;

    private PDO $pdo;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $this->pdo = $this->makeSchema();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== users ====================

    public function testUsersListIsTenantScoped(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $rows = $this->listData($this->usersHandler()->list($this->req('GET', '/api/users')));

        $emails = array_column($rows, 'email');
        $this->assertContains('a1@t1.example', $emails);
        $this->assertNotContains('b1@t2.example', $emails, "Tenant B's users must never appear for Tenant A");
        foreach ($rows as $row) {
            $this->assertSame(self::TENANT_A, (int) $row['tenantId']);
        }
    }

    public function testSystemTenantSeesUsersAcrossTenants(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $rows = $this->listData($this->usersHandler()->list($this->req('GET', '/api/users', null, self::SYSTEM_TENANT)));

        $emails = array_column($rows, 'email');
        $this->assertContains('a1@t1.example', $emails);
        $this->assertContains('b1@t2.example', $emails, 'The system tenant (id 0) must see all tenants');
    }

    public function testTenantCannotUpdateForeignUserAndRowIsUntouched(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->usersHandler()->update(
            $this->req('PATCH', '/api/users/20', ['email' => 'hijacked@evil.example']),
            ['id' => '20']
        );

        $this->assertSame(404, $response->getStatusCode(), 'A foreign user must be reported as not found');
        $this->assertSame(
            'b1@t2.example',
            $this->pdo->query('SELECT email FROM users WHERE id = 20')->fetchColumn(),
            "Tenant B's row must be byte-for-byte untouched after the rejected update"
        );
    }

    public function testTenantCannotDeleteForeignUserAndRowSurvives(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->usersHandler()->delete($this->req('DELETE', '/api/users/20'), ['id' => '20']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM users WHERE id = 20')->fetchColumn(),
            "Tenant B's user must survive a cross-tenant delete attempt"
        );
    }

    /**
     * WC-190: the legitimate same-tenant user update path is unaffected by the
     * added `AND tenant_id = ?` predicate on the UPDATE — Tenant A can still edit
     * its OWN user 10.
     */
    public function testTenantCanUpdateOwnUser(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->usersHandler()->update(
            $this->req('PATCH', '/api/users/10', ['email' => 'a1-renamed@t1.example']),
            ['id' => '10']
        );

        $this->assertSame(200, $response->getStatusCode(), "Tenant A's own user update must succeed");
        $this->assertSame(
            'a1-renamed@t1.example',
            $this->pdo->query('SELECT email FROM users WHERE id = 10')->fetchColumn(),
            "Tenant A's own user row must reflect the legitimate same-tenant update"
        );
    }

    /**
     * WC-190: the SYSTEM tenant (id 0) edits across tenants by design — the new
     * user-UPDATE predicate leaves the system path unscoped, so a system-tenant
     * edit of Tenant B's user still lands.
     */
    public function testSystemTenantCanUpdateForeignUser(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->usersHandler()->update(
            $this->req('PATCH', '/api/users/20', ['email' => 'b1-by-system@t2.example'], self::SYSTEM_TENANT),
            ['id' => '20']
        );

        $this->assertSame(200, $response->getStatusCode(), 'The system tenant must edit any tenant user');
        $this->assertSame(
            'b1-by-system@t2.example',
            $this->pdo->query('SELECT email FROM users WHERE id = 20')->fetchColumn(),
            'The system-tenant user UPDATE must remain unscoped and land on the foreign row'
        );
    }

    // ==================== roles ====================

    public function testRolesListShowsOwnAndGlobalButNeverForeignPrivate(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $rows = $this->listData($this->rolesHandler()->list($this->req('GET', '/api/roles')));

        $names = array_column($rows, 'name');
        $this->assertContains('admin', $names, 'Global roles are visible to every tenant');
        $this->assertContains('tenant-a-private', $names);
        $this->assertNotContains('tenant-b-private', $names, "Tenant B's private role must be invisible to Tenant A");
    }

    public function testTenantCannotReadForeignPrivateRole(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->rolesHandler()->get($this->req('GET', '/api/roles/200'), ['id' => '200']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTenantCannotUpdateForeignPrivateRoleAndRowIsUntouched(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->rolesHandler()->update(
            $this->req('PATCH', '/api/roles/200', ['name' => 'hijacked-role']),
            ['id' => '200']
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            'tenant-b-private',
            $this->pdo->query('SELECT name FROM roles WHERE id = 200')->fetchColumn(),
            "Tenant B's role must be untouched after the rejected update"
        );
    }

    public function testTenantCannotDeleteForeignPrivateRoleAndRowSurvives(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->rolesHandler()->delete($this->req('DELETE', '/api/roles/200'), ['id' => '200']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM roles WHERE id = 200')->fetchColumn()
        );
    }

    /**
     * WC-190: a rejected cross-tenant role delete must NOT collaterally remove
     * the foreign role's permission grants or user-role assignments. The guard
     * SELECT returns 404 before the writes here, but the writes are also
     * tenant-scoped so even if the guard were bypassed the junction rows for
     * Tenant B's role 200 stay intact.
     */
    public function testRejectedForeignRoleDeleteLeavesForeignJunctionRowsIntact(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $this->rolesHandler()->delete($this->req('DELETE', '/api/roles/200'), ['id' => '200']);

        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM role_permissions WHERE role_id = 200')->fetchColumn(),
            "Tenant B's role grants must survive a cross-tenant role delete attempt"
        );
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM user_roles WHERE role_id = 200')->fetchColumn(),
            "Tenant B's user-role assignments must survive a cross-tenant role delete attempt"
        );
    }

    /**
     * WC-190 (TOCTOU defense-in-depth): the FINAL mutating statements that scope
     * role-owned junction rows must themselves reject a cross-tenant role id —
     * not merely rely on the upstream guard SELECT. We invoke the handler's
     * scoped DELETEs through a thin subclass that skips the guard, simulating an
     * attacker id reaching the write directly, and assert zero foreign rows die.
     */
    public function testScopedJunctionDeletesRejectCrossTenantRoleIdAtTheWriteItself(): void
    {
        TenantContext::setTenantId(self::TENANT_A);

        // Tenant A drives the role-owned junction deletes against Tenant B's
        // role id 200 directly (bypassing the 404 guard). The predicate on the
        // statement — not the guard — must keep the count at the foreign rows.
        $handler = new class ($this->pdo, $this->hooks()) extends RolesApiHandler {
            public function purge(int $roleId, int $tenantId): void
            {
                $this->deleteRolePermissionsScoped($roleId, $tenantId);
                $this->deleteUserRolesScoped($roleId, $tenantId);
                $this->deleteRoleScoped($roleId, $tenantId);
            }
        };
        $handler->purge(200, self::TENANT_A);

        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM roles WHERE id = 200')->fetchColumn(),
            'The scoped role DELETE must not touch a foreign tenant role'
        );
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM role_permissions WHERE role_id = 200')->fetchColumn(),
            'The scoped role_permissions DELETE must not touch a foreign tenant role grants'
        );
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM user_roles WHERE role_id = 200')->fetchColumn(),
            'The scoped user_roles DELETE must not touch a foreign tenant assignments'
        );
    }

    /**
     * WC-190: the legitimate same-tenant delete path is unaffected — Tenant A
     * deleting its OWN role 100 removes the role and ITS grants/assignments,
     * while Tenant B's role 200 rows remain untouched.
     */
    public function testOwnRoleDeleteRemovesOnlyOwnJunctionRows(): void
    {
        TenantContext::setTenantId(self::TENANT_A);

        // Role 100 has a user assigned (user 11); detach it first so the
        // active-assignment guard does not 409 the legitimate delete.
        $this->pdo->exec('DELETE FROM user_roles WHERE role_id = 100');
        $response = $this->rolesHandler()->delete($this->req('DELETE', '/api/roles/100'), ['id' => '100']);

        $this->assertSame(200, $response->getStatusCode(), "Tenant A's own role delete must succeed");
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM roles WHERE id = 100')->fetchColumn(),
            "Tenant A's own role must be gone"
        );
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM role_permissions WHERE role_id = 100')->fetchColumn(),
            "Tenant A's own role grants must be gone"
        );
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM role_permissions WHERE role_id = 200')->fetchColumn(),
            "Tenant B's grants must be untouched by Tenant A's own-role delete"
        );
    }

    // ==================== organizational_units ====================

    public function testOusListIsTenantScoped(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $rows = $this->listData($this->ousHandler()->list($this->req('GET', '/api/ous')));

        $names = array_column($rows, 'name');
        $this->assertContains('A-Engineering', $names);
        $this->assertNotContains('B-Sales', $names, "Tenant B's OUs must never appear for Tenant A");
    }

    public function testTenantCannotReadForeignOu(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->ousHandler()->get($this->req('GET', '/api/ous/20'), ['id' => '20']);

        $this->assertSame(404, $response->getStatusCode(), 'A foreign OU read reports not-found');
        $this->assertStringNotContainsString(
            'B-Sales',
            $response->getBody(),
            'The refusal must not leak the foreign OU name'
        );
    }

    public function testTenantCannotUpdateForeignOuAndRowIsUntouched(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->ousHandler()->update(
            $this->req('PATCH', '/api/ous/20', ['name' => 'Hijacked']),
            ['id' => '20']
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'B-Sales',
            $this->pdo->query('SELECT name FROM organizational_units WHERE id = 20')->fetchColumn()
        );
    }

    public function testTenantCannotDeleteForeignOuAndRowSurvives(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->ousHandler()->delete($this->req('DELETE', '/api/ous/20'), ['id' => '20']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM organizational_units WHERE id = 20')->fetchColumn()
        );
    }

    /**
     * WC-190 (TOCTOU defense-in-depth): the OU UPDATE was previously scoped
     * only by `WHERE id = ?` and relied on a prior guard SELECT. Drive the
     * scoped UPDATE through a thin subclass that skips the guard with a foreign
     * id; the predicate ON THE UPDATE must keep Tenant B's name unchanged.
     */
    public function testScopedOuUpdateRejectsCrossTenantIdAtTheWriteItself(): void
    {
        TenantContext::setTenantId(self::TENANT_A);

        $handler = new class ($this->pdo, $this->hooks()) extends OusApiHandler {
            public function renameUnscopedGuard(int $ouId, string $name, int $tenantId): void
            {
                $this->updateOuScoped($ouId, ['name = ?'], [$name], $tenantId);
            }
        };
        $handler->renameUnscopedGuard(20, 'Hijacked', self::TENANT_A);

        $this->assertSame(
            'B-Sales',
            $this->pdo->query('SELECT name FROM organizational_units WHERE id = 20')->fetchColumn(),
            "Tenant B's OU name must be untouched: the UPDATE predicate, not the guard, rejects the foreign id"
        );
    }

    // ==================== audit_log ====================

    public function testAuditLogListIsTenantScoped(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $rows = $this->listData($this->auditHandler()->list($this->req('GET', '/api/audit-logs')));

        $actions = array_column($rows, 'action');
        $this->assertContains('tenant-a.action', $actions);
        $this->assertNotContains('tenant-b.action', $actions, "Tenant B's audit entries must never appear for Tenant A");
    }

    public function testSystemTenantSeesAuditAcrossTenants(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $rows = $this->listData($this->auditHandler()->list($this->req('GET', '/api/audit-logs', null, self::SYSTEM_TENANT)));

        $actions = array_column($rows, 'action');
        $this->assertContains('tenant-a.action', $actions);
        $this->assertContains('tenant-b.action', $actions);
    }

    // ==================== hello_greetings (HelloWorld plugin, WC-169) ====================

    public function testHelloGreetingsListIsTenantScoped(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $rows = $this->listData($this->greetingsHandler()->list($this->req('GET', '/api/hello/greetings')));

        $messages = array_column($rows, 'message');
        $this->assertContains('a-greeting', $messages);
        $this->assertNotContains('b-greeting', $messages, "Tenant B's greetings must never appear for Tenant A");
        foreach ($rows as $row) {
            $this->assertSame(self::TENANT_A, (int) $row['tenantId']);
        }
    }

    public function testSystemTenantSeesHelloGreetingsAcrossTenants(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $rows = $this->listData($this->greetingsHandler()->list($this->req('GET', '/api/hello/greetings', null, self::SYSTEM_TENANT)));

        $messages = array_column($rows, 'message');
        $this->assertContains('a-greeting', $messages);
        $this->assertContains('b-greeting', $messages, 'The system tenant (id 0) must see all tenants');
    }

    public function testTenantCannotUpdateForeignGreetingAndRowIsUntouched(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->greetingsHandler()->update(
            $this->req('PATCH', '/api/hello/greetings/20', ['message' => 'hijacked']),
            ['id' => '20']
        );

        $this->assertSame(404, $response->getStatusCode(), 'A foreign greeting must be reported as not found');
        $this->assertSame(
            'b-greeting',
            $this->pdo->query('SELECT message FROM hello_greetings WHERE id = 20')->fetchColumn(),
            "Tenant B's greeting must be byte-for-byte untouched after the rejected update"
        );
    }

    public function testTenantCannotDeleteForeignGreetingAndRowSurvives(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->greetingsHandler()->delete($this->req('DELETE', '/api/hello/greetings/20'), ['id' => '20']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM hello_greetings WHERE id = 20')->fetchColumn(),
            "Tenant B's greeting must survive a cross-tenant delete attempt"
        );
    }

    // ==================== permission_delegations ====================

    public function testDelegationLookupIsTenantScoped(): void
    {
        $repo = new DelegationRepository($this->pdo);

        $this->assertNotNull($repo->findById(1, self::TENANT_A), 'Tenant A must find its own delegation');
        $this->assertNull($repo->findById(2, self::TENANT_A), "Tenant B's delegation must be invisible to Tenant A");
    }

    public function testTenantCannotRevokeForeignDelegationAndItStaysActive(): void
    {
        $repo = new DelegationRepository($this->pdo);

        $affected = $repo->revoke(2, self::TENANT_A);

        $this->assertSame(0, $affected, 'A cross-tenant revoke must touch zero rows');
        $this->assertNull(
            $this->pdo->query('SELECT revoked_at FROM permission_delegations WHERE id = 2')->fetchColumn() ?: null,
            "Tenant B's delegation must remain active after the rejected revoke"
        );
    }

    // ==================== helpers ====================

    private function usersHandler(): UsersApiHandler
    {
        return new UsersApiHandler($this->pdo, $this->hooks());
    }

    private function rolesHandler(): RolesApiHandler
    {
        return new RolesApiHandler($this->pdo, $this->hooks());
    }

    private function ousHandler(): OusApiHandler
    {
        return new OusApiHandler($this->pdo, $this->hooks());
    }

    private function auditHandler(): AuditLogApiHandler
    {
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermission')->willReturn(true);

        return new AuditLogApiHandler($this->pdo, $roleChecker);
    }

    private function greetingsHandler(): GreetingsApiHandler
    {
        return new GreetingsApiHandler($this->pdo);
    }

    private function hooks(): HookManager
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);

        return $hooks;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function req(string $method, string $path, ?array $body = null, int $tenantId = self::TENANT_A): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['user_id' => 99, 'tenant_id' => $tenantId];

        return $request;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listData(\Whity\Sdk\Http\Response $response): array
    {
        $this->assertSame(200, $response->getStatusCode(), 'The scoped list itself must succeed');
        $decoded = json_decode($response->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('data', $decoded);

        return $decoded['data'];
    }

    /**
     * In-memory SQLite with the production-shaped schema for every table this
     * suite covers, seeded with disjoint Tenant A / Tenant B rows.
     * PDO::ATTR_STRINGIFY_FETCHES mirrors PostgreSQL's string fetches so int
     * comparisons that only pass natively would fail here too.
     */
    private function makeSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        // Let the handlers' PostgreSQL-flavoured NOW() run unmodified on SQLite,
        // matching the other RealEngine harnesses.
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        $pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (0, 'system'), (1, 'tenant-a'), (2, 'tenant-b')");

        $pdo->exec('
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT DEFAULT \'\',
                parent_id INTEGER,
                tenant_id INTEGER,
                created_at TEXT
            )
        ');
        $pdo->exec("
            INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (1,   'admin',            '', NULL, datetime('now')),
                (2,   'user',             '', NULL, datetime('now')),
                (100, 'tenant-a-private', '', 1,    datetime('now')),
                (200, 'tenant-b-private', '', 2,    datetime('now'))
        ");

        $pdo->exec('
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT
            )
        ');
        // role_permissions carries NO tenant_id column: a grant inherits its
        // tenant transitively from the owning role (roles.tenant_id). The WC-190
        // predicate must therefore scope the junction DELETE via the parent role.
        $pdo->exec('
            CREATE TABLE role_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                created_at TEXT,
                UNIQUE(role_id, permission_id)
            )
        ');
        // user_roles DOES carry tenant_id (migration 012); the WC-190 predicate
        // scopes its DELETE on that column directly.
        $pdo->exec('
            CREATE TABLE user_roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                tenant_id INTEGER,
                created_at TEXT
            )
        ');
        // Grants and assignments for Tenant B's PRIVATE role 200, so a rejected
        // cross-tenant role delete/update can be proven to leave them intact.
        $pdo->exec("
            INSERT INTO permissions (id, name, description) VALUES
                (1, 'users:read', 'Read users')
        ");
        $pdo->exec('
            INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES
                (100, 1, datetime(\'now\')),
                (200, 1, datetime(\'now\'))
        ');
        $pdo->exec('
            INSERT INTO user_roles (user_id, role_id, tenant_id, created_at) VALUES
                (11, 100, 1, datetime(\'now\')),
                (21, 200, 2, datetime(\'now\'))
        ');

        $pdo->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                password TEXT NOT NULL,
                role_id INTEGER,
                ou_id INTEGER,
                created_at TEXT
            )
        ');
        $pdo->exec("
            INSERT INTO users (id, tenant_id, email, password, role_id, created_at) VALUES
                (10, 1, 'a1@t1.example', 'x', 1, datetime('now')),
                (11, 1, 'a2@t1.example', 'x', 2, datetime('now')),
                (20, 2, 'b1@t2.example', 'x', 1, datetime('now')),
                (21, 2, 'b2@t2.example', 'x', 2, datetime('now'))
        ");

        $pdo->exec('
            CREATE TABLE organizational_units (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                parent_id INTEGER,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                description TEXT DEFAULT \'\',
                created_at TEXT
            )
        ');
        $pdo->exec("
            INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, description, created_at) VALUES
                (10, 1, NULL, 'A-Engineering', 'a-engineering', '', datetime('now')),
                (20, 2, NULL, 'B-Sales',       'b-sales',       '', datetime('now'))
        ");

        $pdo->exec('
            CREATE TABLE ou_role_assignments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                ou_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                created_at TEXT,
                UNIQUE(ou_id, role_id)
            )
        ');

        $pdo->exec('
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                actor_user_id INTEGER NULL,
                action TEXT NOT NULL,
                target_type TEXT NULL,
                target_id INTEGER NULL,
                metadata TEXT NOT NULL DEFAULT \'{}\',
                ip_address TEXT NULL,
                created_at TEXT NOT NULL
            )
        ');
        $pdo->exec("
            INSERT INTO audit_log (tenant_id, actor_user_id, action, metadata, created_at) VALUES
                (1, 10, 'tenant-a.action', '{}', datetime('now')),
                (2, 20, 'tenant-b.action', '{}', datetime('now'))
        ");

        $pdo->exec('
            CREATE TABLE hello_greetings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                message TEXT NOT NULL,
                created_at TEXT NOT NULL
            )
        ');
        $pdo->exec("
            INSERT INTO hello_greetings (id, tenant_id, message, created_at) VALUES
                (10, 1, 'a-greeting', datetime('now')),
                (20, 2, 'b-greeting', datetime('now'))
        ");

        $pdo->exec("
            CREATE TABLE permission_delegations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                grantor_user_id INTEGER NOT NULL,
                grantee_type TEXT NOT NULL,
                grantee_id INTEGER NOT NULL,
                permission TEXT NOT NULL,
                ou_id INTEGER,
                granted_at TEXT,
                revoked_at TEXT,
                CHECK (grantee_type IN ('role', 'user'))
            )
        ");
        $pdo->exec("
            INSERT INTO permission_delegations
                (id, tenant_id, grantor_user_id, grantee_type, grantee_id, permission, granted_at) VALUES
                (1, 1, 10, 'user', 11, 'users:read', datetime('now')),
                (2, 2, 20, 'user', 21, 'users:read', datetime('now'))
        ");

        return $pdo;
    }
}
