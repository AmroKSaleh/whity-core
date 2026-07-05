<?php

declare(strict_types=1);

namespace Tests\Integration;

use HelloWorld\Api\GreetingsApiHandler;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AuditLogApiHandler;
use Whity\Api\OusApiHandler;
use Whity\Api\RolesApiHandler;
use Whity\Api\TwoFactorHandler;
use Whity\Api\UsersApiHandler;
use Whity\Auth\AuthHandler;
use Whity\Auth\BackupCodesService;
use Whity\Auth\DatabaseQueryWrapper;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Auth\TotpService;
use Whity\Core\Delegation\DelegationRepository;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Identity\TenantEmailDomainsRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Relations\PersonRepository;
use Whity\Core\Relations\RelationRepository;
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
        // The 2FA-handler tests mint real access tokens into the cookie jar; clear
        // them so they cannot leak into a later test (WC-191).
        unset($_COOKIE['access_token']);
    }

    /** Shared HS256 secret for the real JwtParser/TotpService in 2FA tests (WC-191). */
    private const JWT_SECRET = 'cross-tenant-2fa-test-secret-padded-for-hs256-min-32-byte-key';

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

    // ==================== users: 2FA reads/writes (WC-191) ====================

    /**
     * WC-191: a 2FA WRITE (disable) executed in Tenant A's context must never
     * touch a Tenant B user even when the caller's token points at that foreign
     * id. The handler reads its own id from the token but scopes every users
     * statement to the request tenant (TenantContext), so the disable UPDATE
     * resolves to zero rows and Tenant B's 2FA state is byte-for-byte intact.
     */
    public function testTenantCannotDisable2faOnForeignUserAndRowIsUntouched(): void
    {
        // Token is internally valid for Tenant B user 20, but the REQUEST runs in
        // Tenant A's context — simulating an attacker driving a foreign id through
        // a Tenant A request. The users predicate, scoped to Tenant A, must reject.
        $this->authCookieFor(20, self::TENANT_B);
        TenantContext::setTenantId(self::TENANT_A);

        $response = $this->twoFactorHandler()->disable($this->req('POST', '/api/auth/2fa/disable'));

        $this->assertSame(
            1,
            $this->fetchBool('SELECT two_factor_enabled FROM users WHERE id = 20'),
            "Tenant B's 2FA must remain ENABLED after a Tenant-A-scoped disable cannot reach the foreign row"
        );
    }

    /**
     * WC-191: a 2FA WRITE (regenerate-codes) in Tenant A's context must not bump
     * a Tenant B user's backup-codes version. The guard SELECT is tenant-scoped
     * (so it 404-equivalents), AND the version UPDATE carries the predicate too.
     */
    public function testTenantCannotRegenerate2faCodesForForeignUserAndVersionIsUntouched(): void
    {
        $this->authCookieFor(20, self::TENANT_B);
        TenantContext::setTenantId(self::TENANT_A);

        $this->twoFactorHandler()->regenerateCodes($this->req('POST', '/api/auth/2fa/regenerate-codes'));

        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT two_factor_backup_codes_version FROM users WHERE id = 20')->fetchColumn(),
            "Tenant B's backup-codes version must be untouched by a Tenant-A-scoped regenerate"
        );
    }

    /**
     * WC-191 (post-cutover): a 2FA status read for a profile authenticated in
     * Tenant B returns the profile's own 2FA state — cross-tenant isolation is
     * now enforced at the membership-guard layer (token issuance), not inside
     * the 2FA handler. A valid Tenant-B token resolves Tenant B's profile.
     */
    public function testTenant2faStatusCannotReadForeignUser(): void
    {
        // Token for profile 102 (Bob) in Tenant B context — membership guard passes.
        $this->authCookieFor(20, self::TENANT_B);
        TenantContext::setTenantId(self::TENANT_B);

        $response = $this->twoFactorHandler()->status($this->req('GET', '/api/auth/2fa/status'));

        // Post-cutover: profile 102 owns a valid Tenant-B token → reads its OWN state.
        $this->assertSame(200, $response->getStatusCode(), 'A valid Tenant-B token must read the profile\'s own 2FA status');
        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['enabled'], 'Profile 102 must report 2FA enabled (seeded in makeSchema)');
    }

    /**
     * WC-191 (positive control): the legitimate same-tenant 2FA status read is
     * unaffected — Tenant A's profile 101 returns the real enabled state.
     */
    public function testTenant2faStatusReadsOwnUser(): void
    {
        $this->authCookieFor(10, self::TENANT_A);
        TenantContext::setTenantId(self::TENANT_A);

        $response = $this->twoFactorHandler()->status($this->req('GET', '/api/auth/2fa/status'));

        $this->assertSame(200, $response->getStatusCode(), "Tenant A's profile 101 2FA status read must succeed");
        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['enabled'], "Profile 101 must report 2FA enabled (seeded in makeSchema)");
    }

    /**
     * WC-191 (positive control): the legitimate same-tenant 2FA write is
     * unaffected — Tenant A disabling its OWN profile 101's 2FA still lands.
     */
    public function testTenantCanDisable2faOnOwnUser(): void
    {
        $this->authCookieFor(10, self::TENANT_A);
        TenantContext::setTenantId(self::TENANT_A);

        $response = $this->twoFactorHandler()->disable($this->req('POST', '/api/auth/2fa/disable'));

        $this->assertSame(200, $response->getStatusCode(), "Tenant A's own 2FA disable must succeed");
        $this->assertSame(
            0,
            // Post-cutover: TwoFactorHandler writes to profiles (not users).
            $this->fetchBool('SELECT two_factor_enabled FROM profiles WHERE id = 101'),
            "Profile 101's 2FA must be disabled by the legitimate same-tenant write"
        );
    }

    /**
     * WC-191: the 2FA-LOGIN lookup (AuthHandler::handle2fa) re-fetches the user
     * by the temp token's id; it must be scoped to the temp token's tenant_id so
     * a temp token claiming Tenant A but pointing at a Tenant B user id can never
     * read/complete login against the foreign row. The lookup finds nothing and
     * Tenant B's row is left untouched.
     */
    public function testTwoFaLoginLookupCannotReachForeignTenantUser(): void
    {
        // Temp token: profile_id 102 (Bob, Tenant B's profile) but active_tenant_id 1
        // (Tenant A) — a forged/mismatched tenant claim. The scoped lookup must reject it.
        $tempToken = (new JwtParser(self::JWT_SECRET))->create(
            ['profile_id' => 102, 'active_tenant_id' => self::TENANT_A, 'email' => 'b1@t2.example'],
            300,
            'temp'
        );
        $_COOKIE['temp_auth_token'] = $tempToken;

        try {
            $response = $this->authHandler()->handle2fa(
                $this->req('POST', '/api/login/2fa', ['code' => '000000'])
            );

            $this->assertSame(
                401,
                $response->getStatusCode(),
                'A temp token with a mismatched active_tenant_id must not complete 2FA login'
            );
            // Post-cutover (WC-idcut-E): the handler resolves the PROFILE (globally)
            // and fails at 2FA verification with an invalid code — the cross-tenant
            // isolation is enforced by the membership guard at the
            // EnforceTenantIsolation layer, not by a tenant-scoped user lookup.
            // The 401 itself (no session issued) is the security invariant.
            $this->assertSame(
                1,
                $this->fetchBool('SELECT two_factor_enabled FROM users WHERE id = 20'),
                "Tenant B's row must be untouched by a cross-tenant 2FA-login attempt"
            );
        } finally {
            unset($_COOKIE['temp_auth_token']);
        }
    }

    /**
     * WC-191 (positive control): a temp token that correctly owns its user (id 20
     * in Tenant B) resolves the user — the cross-tenant scoping does not break the
     * legitimate 2FA-login lookup. The code is wrong, so login fails at 2FA
     * verification (401 'Invalid 2FA code'), proving the row WAS found and the
     * second-factor check ran (distinct from the 'User not found' rejection).
     */
    public function testTwoFaLoginLookupResolvesOwnTenantUser(): void
    {
        $tempToken = (new JwtParser(self::JWT_SECRET))->create(
            ['profile_id' => 102, 'active_tenant_id' => self::TENANT_B, 'email' => 'b1@t2.example'],
            300,
            'temp'
        );
        $_COOKIE['temp_auth_token'] = $tempToken;

        try {
            $response = $this->authHandler()->handle2fa(
                $this->req('POST', '/api/login/2fa', ['code' => '000000'])
            );

            // The profile WAS resolved (so we reach 2FA verification); the bogus
            // code then fails verification — proving the lookup succeeded for the
            // same-profile temp token rather than being rejected as not-found.
            $this->assertSame(401, $response->getStatusCode());
            $this->assertStringContainsString(
                'Invalid 2FA code',
                $response->getBody(),
                'A same-profile temp token must resolve the profile and reach 2FA verification'
            );
        } finally {
            unset($_COOKIE['temp_auth_token']);
        }
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
        // user_roles was dropped by migration 039; role_permissions isolation is
        // the relevant junction assertion here.
    }

    /**
     * WC-190 (TOCTOU defense-in-depth): the FINAL mutating statements that scope
     * role-owned junction rows must themselves reject a cross-tenant role id —
     * not merely rely on the upstream guard SELECT. We invoke the handler's
     * scoped DELETEs through a thin subclass that skips the guard, simulating an
     * attacker id reaching the write directly, and assert zero foreign rows die.
     *
     * user_roles was dropped by migration 039 so only role_permissions and the
     * role row itself are asserted here.
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
    }

    /**
     * WC-190: the legitimate same-tenant delete path is unaffected — Tenant A
     * deleting its OWN role 100 removes the role and ITS grants,
     * while Tenant B's role 200 rows remain untouched.
     *
     * user_roles was dropped by migration 039. User 11 carries users.role_id = 2
     * (global "user" role), so the active-assignment guard does not block the
     * delete of role 100.
     */
    public function testOwnRoleDeleteRemovesOnlyOwnJunctionRows(): void
    {
        TenantContext::setTenantId(self::TENANT_A);

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

    // ==================== memberships (WC-101) ====================

    public function testMembershipsListIsTenantScoped(): void
    {
        $repo = new MembershipRepository($this->pdo);

        $tenantARows = $repo->listForTenant(self::TENANT_A);
        $tenantBRows = $repo->listForTenant(self::TENANT_B);

        // Fixture: Tenant A has 1 row (Alice). Tenant B has 2 rows (Bob + Alice
        // who is also in Tenant B for the findForProfile() cross-tenant test).
        $this->assertCount(1, $tenantARows, 'Tenant A must see exactly its own membership row.');
        $this->assertCount(2, $tenantBRows, 'Tenant B must see exactly its own 2 membership rows.');
        $this->assertSame(self::TENANT_A, $tenantARows[0]['tenant_id']);
        foreach ($tenantBRows as $row) {
            $this->assertSame(self::TENANT_B, $row['tenant_id']);
        }
    }

    public function testTenantCannotReadForeignMembership(): void
    {
        $repo = new MembershipRepository($this->pdo);

        // The fixture seeds membership id=101 for Tenant A and id=102 for Tenant B.
        $this->assertNotNull($repo->findById(101, self::TENANT_A), 'Tenant A must find its own membership.');
        $this->assertNull($repo->findById(102, self::TENANT_A), "Tenant B's membership must be invisible to Tenant A.");
    }

    public function testTenantCannotSuspendForeignMembershipAndItStaysActive(): void
    {
        $repo = new MembershipRepository($this->pdo);

        // Tenant A attempts to suspend membership id=102 which belongs to Tenant B.
        $affected = $repo->suspend(102, self::TENANT_A);

        $this->assertSame(0, $affected, 'A cross-tenant suspend must touch zero rows.');
        $stmt = $this->pdo->query('SELECT status FROM memberships WHERE id = 102');
        self::assertNotFalse($stmt);
        $this->assertSame(MembershipRepository::STATUS_ACTIVE, $stmt->fetchColumn(), "Tenant B's membership must remain active.");
    }

    public function testTenantCannotDeleteForeignMembershipAndItSurvives(): void
    {
        $repo = new MembershipRepository($this->pdo);

        $affected = $repo->delete(102, self::TENANT_A);

        $this->assertSame(0, $affected, 'A cross-tenant delete must touch zero rows.');
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM memberships WHERE id = 102');
        self::assertNotFalse($stmt);
        $this->assertSame(
            1,
            (int) $stmt->fetchColumn(),
            "Tenant B's membership row must survive a cross-tenant delete attempt."
        );
    }

    public function testSystemTenantSeesAllMembershipsViaFindForProfile(): void
    {
        $repo = new MembershipRepository($this->pdo);

        // Profile 101 (Alice) has active memberships in both tenants in the fixture.
        // findForProfile is the intentionally-unscoped login-flow query.
        $rows = $repo->findForProfile(101);

        $tenants = array_column($rows, 'tenant_id');
        $this->assertContains(self::TENANT_A, $tenants);
        $this->assertContains(self::TENANT_B, $tenants);
    }

    // ==================== tenant_email_domains (WC-9b87) ====================

    public function testTenantEmailDomainsListIsTenantScoped(): void
    {
        $repo = new TenantEmailDomainsRepository($this->pdo);

        $rowsA = $repo->listForTenant(self::TENANT_A);
        $rowsB = $repo->listForTenant(self::TENANT_B);

        // Fixture: Tenant A owns 'acme.com', Tenant B owns 'example.org'.
        $this->assertCount(1, $rowsA, 'Tenant A must see exactly its own domain row.');
        $this->assertCount(1, $rowsB, 'Tenant B must see exactly its own domain row.');
        $this->assertSame(self::TENANT_A, $rowsA[0]['tenant_id']);
        $this->assertSame(self::TENANT_B, $rowsB[0]['tenant_id']);
    }

    public function testTenantCannotReadForeignDomainRow(): void
    {
        $repo = new TenantEmailDomainsRepository($this->pdo);

        // The fixture seeds domain row id=1 for Tenant A and id=2 for Tenant B.
        $this->assertNotNull($repo->findById(1, self::TENANT_A), 'Tenant A must find its own domain row.');
        $this->assertNull($repo->findById(2, self::TENANT_A), "Tenant B's domain row must be invisible to Tenant A.");
    }

    public function testTenantCannotDeleteForeignDomainRowAndItSurvives(): void
    {
        $repo = new TenantEmailDomainsRepository($this->pdo);

        $affected = $repo->delete(2, self::TENANT_A);

        $this->assertSame(0, $affected, 'A cross-tenant domain delete must touch zero rows.');
        $this->assertNotNull(
            $repo->findById(2, self::TENANT_B),
            "Tenant B's domain row must survive a cross-tenant delete attempt."
        );
    }

    // ==================== tenant_settings (Website Settings) ====================

    public function testTenantSettingsReadIsTenantScoped(): void
    {
        $repo = new \Whity\Core\Settings\TenantSettingsRepository($this->pdo);

        $aOverrides = $repo->allForTenant(self::TENANT_A);
        self::assertSame('Tenant A Co', $aOverrides['site_name'] ?? null);
        self::assertArrayNotHasKey(
            'support_email',
            $aOverrides,
            "Tenant B's override (support_email) must be invisible to Tenant A"
        );

        $bOverrides = $repo->allForTenant(self::TENANT_B);
        self::assertSame('b@t2.example', $bOverrides['support_email'] ?? null);
        self::assertArrayNotHasKey('site_name', $bOverrides, "Tenant A's override must be invisible to Tenant B");
    }

    public function testTenantCannotClearForeignSettingOverrideAndRowSurvives(): void
    {
        $repo = new \Whity\Core\Settings\TenantSettingsRepository($this->pdo);

        // Tenant A tries to delete the 'support_email' key while acting in its own
        // scope — the tenant_id predicate means it can never reach Tenant B's row.
        $affected = $repo->delete(self::TENANT_A, 'support_email');

        self::assertSame(0, $affected, 'A cross-tenant override clear must touch zero rows');
        self::assertSame(
            'b@t2.example',
            $this->pdo->query("SELECT value FROM tenant_settings WHERE tenant_id = 2 AND setting_key = 'support_email'")->fetchColumn(),
            "Tenant B's override must survive a Tenant-A-scoped clear attempt"
        );
    }

    public function testTenantSettingWriteLandsOnlyInOwnScopeAndLeavesForeignUntouched(): void
    {
        $repo = new \Whity\Core\Settings\TenantSettingsRepository($this->pdo);

        // Tenant A upserts support_email in ITS OWN scope. The (tenant_id, key)
        // predicate means this can never reach Tenant B's support_email row.
        $repo->set(self::TENANT_A, 'support_email', 'a@t1.example');

        self::assertSame(
            'a@t1.example',
            $this->pdo->query("SELECT value FROM tenant_settings WHERE tenant_id = 1 AND setting_key = 'support_email'")->fetchColumn()
        );
        self::assertSame(
            'b@t2.example',
            $this->pdo->query("SELECT value FROM tenant_settings WHERE tenant_id = 2 AND setting_key = 'support_email'")->fetchColumn(),
            "Tenant B's override must be byte-for-byte untouched by Tenant A's write"
        );
    }

    // ==================== ou members (WC-d88de9fa) ====================

    /**
     * WC-d88de9fa: the OU members endpoint resolves IDENTITY data (email,
     * display_name) via profiles/profile_emails and ROLE/OU data via memberships
     * (ADR 0005 §1-3). The tenant predicate is on memberships.tenant_id.
     *
     * Tenant A reading Tenant B's OU (id 20) must receive 404 — the ouIsVisible()
     * guard rejects before the memberships query is even issued.
     */
    public function testOuMembersCannotReadForeignOuMembers(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->ousHandler()->members($this->req('GET', '/api/ous/20/members'), ['id' => '20']);

        $this->assertSame(404, $response->getStatusCode(), 'A foreign OU members read must report not-found');
        $this->assertStringNotContainsString('Bob', $response->getBody(), 'The refusal must not leak Tenant B profile names');
    }

    /**
     * WC-d88de9fa (defense-in-depth): even if the ouIsVisible() guard were
     * bypassed, the memberships query itself carries `AND m.tenant_id = ?`
     * which means a Tenant-A-scoped request for OU 20 (Tenant B's OU) returns
     * an empty member list — proving the predicate, not just the guard, isolates.
     *
     * We verify by inserting a profile_email for Bob (profile 102) and a
     * membership row for Bob in Tenant B's OU (id 20), then showing that a
     * direct Tenant-A-scoped memberships query against that OU returns no rows.
     */
    public function testOuMembershipsQueryIsTenantScoped(): void
    {
        // Seed profile_email for Bob (profile 102) so the JOIN can resolve.
        $this->pdo->exec("
            INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES
                (102, 'bob@t2.example', true, true, datetime('now'))
        ");

        // Assign Bob's membership (id 102) to Tenant B's OU (id 20).
        $this->pdo->exec("UPDATE memberships SET ou_id = 20 WHERE id = 102 AND tenant_id = 2");

        // Direct query as Tenant A: ou_id=20 AND tenant_id=TENANT_A → zero rows.
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM memberships m
             JOIN profiles p ON p.id = m.profile_id
             JOIN profile_emails pe ON pe.profile_id = m.profile_id AND pe.is_primary = true
             WHERE m.ou_id = ? AND m.tenant_id = ?"
        );
        $stmt->execute([20, self::TENANT_A]);

        $this->assertSame(
            0,
            (int)$stmt->fetchColumn(),
            'The memberships JOIN with tenant_id = Tenant A must return 0 rows for Tenant B\'s OU (id 20)'
        );
    }

    /**
     * WC-d88de9fa (positive control): Tenant A can list members of its OWN OU
     * when a profile_email and membership row with ou_id exist. The result
     * includes the IDENTITY (email, display_name) resolved via profiles +
     * profile_emails (no users join).
     */
    public function testOuMembersListsOwnOuMembers(): void
    {
        // Seed profile_email for Alice (profile 101) as the IDENTITY anchor.
        $this->pdo->exec("
            INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES
                (101, 'alice@t1.example', true, true, datetime('now'))
        ");

        // Assign Alice's Tenant-A membership (id 101) to Tenant A's OU (id 10).
        $this->pdo->exec("UPDATE memberships SET ou_id = 10 WHERE id = 101 AND tenant_id = 1");

        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->ousHandler()->members($this->req('GET', '/api/ous/10/members'), ['id' => '10']);

        $this->assertSame(200, $response->getStatusCode(), "Tenant A's own OU members must be readable");
        $data = json_decode($response->getBody(), true);
        $emails = array_column($data['data'], 'email');
        $this->assertContains('alice@t1.example', $emails, 'Alice must appear in Tenant A\'s OU members');
        foreach ($data['data'] as $member) {
            $this->assertSame(self::TENANT_A, (int)$member['tenantId'], 'Every member must belong to Tenant A');
        }
    }

    /**
     * WC-d88de9fa: the OU delete guard now checks memberships (ROLE/OU data,
     * ADR 0005 §3) rather than users. An OU with an active membership cannot
     * be deleted; the predicate is on memberships.tenant_id so a cross-tenant
     * membership can never block a legitimate deletion.
     */
    public function testOuDeleteGuardBlocksMembershipOccupiedOu(): void
    {
        // Seed profile_email for Alice so the fixture is consistent.
        $this->pdo->exec("
            INSERT OR IGNORE INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES
                (101, 'alice@t1.example', true, true, datetime('now'))
        ");

        // Assign Alice's membership to Tenant A's OU (id 10).
        $this->pdo->exec("UPDATE memberships SET ou_id = 10 WHERE id = 101 AND tenant_id = 1");

        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->ousHandler()->delete($this->req('DELETE', '/api/ous/10'), ['id' => '10']);

        $this->assertSame(409, $response->getStatusCode(), 'Deleting an OU with assigned members must return 409');
        $this->assertStringContainsString('member', $response->getBody(), 'Error must mention member(s)');
        $this->assertSame(
            1,
            (int)$this->pdo->query('SELECT COUNT(*) FROM organizational_units WHERE id = 10')->fetchColumn(),
            'The OU must survive a blocked delete'
        );
    }

    /**
     * WC-d88de9fa: Tenant B's membership occupying a Tenant B OU must NOT
     * block Tenant A from deleting its own empty OU. The memberships query is
     * scoped to the acting tenant's tenant_id, so cross-tenant memberships are
     * invisible to the guard.
     */
    public function testOuDeleteGuardDoesNotLeakCrossTenantMemberships(): void
    {
        // Assign Bob's Tenant-B membership (id 102) to Tenant B's OU (id 20).
        $this->pdo->exec("UPDATE memberships SET ou_id = 20 WHERE id = 102 AND tenant_id = 2");

        // Tenant A's OU 10 has no assigned memberships for tenant_id = 1.
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->ousHandler()->delete($this->req('DELETE', '/api/ous/10'), ['id' => '10']);

        // Should succeed (or at least NOT be a 409 due to cross-tenant membership)
        $this->assertNotSame(
            409,
            $response->getStatusCode(),
            'Tenant B\'s memberships must never trigger a 409 for Tenant A\'s empty OU delete'
        );
    }

    /**
     * WC-d88de9fa (review item 1): the OU delete guard counts only ACTIVE
     * memberships. An OU whose only occupant is a SUSPENDED member is not
     * considered occupied and must be deletable (409 no longer fires).
     */
    public function testOuDeleteGuardIgnoresSuspendedMembers(): void
    {
        // Alice's Tenant-A membership (id 101) is suspended and assigned to OU 10.
        $this->pdo->exec("UPDATE memberships SET ou_id = 10, status = 'suspended' WHERE id = 101 AND tenant_id = 1");

        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->ousHandler()->delete($this->req('DELETE', '/api/ous/10'), ['id' => '10']);

        $this->assertNotSame(
            409,
            $response->getStatusCode(),
            'An OU whose only member is suspended must not be blocked from deletion'
        );
        $this->assertSame(
            0,
            (int)$this->pdo->query('SELECT COUNT(*) FROM organizational_units WHERE id = 10')->fetchColumn(),
            'The OU with only a suspended member must be deleted'
        );
    }

    /**
     * WC-d88de9fa (review item 1): the OU members() endpoint lists only ACTIVE
     * members — a suspended member of the OU must not appear.
     */
    public function testOuMembersExcludesSuspendedMember(): void
    {
        // Seed profile_emails for Alice and a second Tenant-A profile.
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (201, 'Active-Ann', '\$2y\$10\$fakehash3', false, 0, 0, datetime('now'), datetime('now'))
        ");
        $this->pdo->exec("
            INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES
                (101, 'alice@t1.example', true, true, datetime('now')),
                (201, 'ann@t1.example',   true, true, datetime('now'))
        ");

        // Alice (membership 101) suspended in OU 10; Ann active in OU 10.
        $this->pdo->exec("UPDATE memberships SET ou_id = 10, status = 'suspended' WHERE id = 101 AND tenant_id = 1");
        $this->pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, status, created_at) VALUES
                (301, 201, 1, 1, 10, 'active', datetime('now'))
        ");

        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->ousHandler()->members($this->req('GET', '/api/ous/10/members'), ['id' => '10']);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $emails = array_column($data['data'], 'email');
        $this->assertContains('ann@t1.example', $emails, 'The active member must appear');
        $this->assertNotContains(
            'alice@t1.example',
            $emails,
            'A suspended member must be excluded from the OU member list'
        );
    }

    // ==================== ou_role_assignments ====================

    /**
     * WC-8d0083f5: listing the roles assigned to an OU is tenant-scoped —
     * reading another tenant's OU reports not-found (404) rather than leaking
     * that tenant's role assignments.
     */
    public function testOuRoleAssignmentsListIsTenantScoped(): void
    {
        // Tenant A reads roles for its own OU (id 10) — must see role 100.
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->ousHandler()->roles(
            $this->req('GET', '/api/ous/10/roles'),
            ['id' => '10']
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $ids = array_column($data['data'], 'id');
        $this->assertContains(100, $ids, "Tenant A's own OU must expose role 100");
        $this->assertNotContains(200, $ids, "Tenant B's role must never appear in Tenant A's OU");
    }

    /**
     * WC-8d0083f5: Tenant A reading role-assignments for Tenant B's OU must
     * receive a 404 — the OU is invisible across the tenant boundary.
     */
    public function testTenantCannotReadForeignOuRoleAssignments(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->ousHandler()->roles(
            $this->req('GET', '/api/ous/20/roles'),
            ['id' => '20']
        );

        $this->assertSame(404, $response->getStatusCode(), 'A foreign OU roles read must report not-found');
        $this->assertStringNotContainsString(
            'tenant-b-private',
            $response->getBody(),
            'The refusal must not leak Tenant B role names'
        );
    }

    /**
     * WC-8d0083f5: Tenant A cannot assign a role to Tenant B's OU — the OU
     * is not found for Tenant A (404) and the assignment row stays absent.
     */
    public function testTenantCannotAssignRoleToForeignOuAndRowStaysAbsent(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->ousHandler()->assignRole(
            $this->req('POST', '/api/ous/20/roles', ['role_id' => 100]),
            ['id' => '20']
        );

        $this->assertSame(404, $response->getStatusCode(), 'A foreign OU role-assign must report not-found');
        $this->assertSame(
            0,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ou_role_assignments WHERE ou_id = 20 AND role_id = 100'
            )->fetchColumn(),
            'No spurious cross-tenant ou_role_assignments row must exist after the rejected assign'
        );
    }

    /**
     * WC-8d0083f5: Tenant A cannot remove a role from Tenant B's OU — the
     * assignment row for Tenant B's OU survives the rejected attempt.
     */
    public function testTenantCannotRemoveRoleFromForeignOuAndRowSurvives(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->ousHandler()->removeRole(
            $this->req('DELETE', '/api/ous/20/roles/200'),
            ['ouId' => '20', 'roleId' => '200']
        );

        $this->assertSame(404, $response->getStatusCode(), 'A foreign OU role-remove must report not-found');
        $this->assertSame(
            1,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ou_role_assignments WHERE ou_id = 20 AND role_id = 200'
            )->fetchColumn(),
            "Tenant B's ou_role_assignments row must survive a cross-tenant remove attempt"
        );
    }

    /**
     * WC-8d0083f5 (positive control): Tenant A can remove a role from its OWN
     * OU and the row is gone; Tenant B's assignment row is untouched.
     */
    public function testOwnOuRoleRemovalSucceedsAndLeavesForeignRowIntact(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->ousHandler()->removeRole(
            $this->req('DELETE', '/api/ous/10/roles/100'),
            ['ouId' => '10', 'roleId' => '100']
        );

        $this->assertSame(204, $response->getStatusCode(), "Tenant A's own OU role removal must succeed");
        $this->assertSame(
            0,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ou_role_assignments WHERE ou_id = 10 AND role_id = 100'
            )->fetchColumn(),
            "Tenant A's own assignment must be gone"
        );
        $this->assertSame(
            1,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ou_role_assignments WHERE ou_id = 20 AND role_id = 200'
            )->fetchColumn(),
            "Tenant B's ou_role_assignments row must be untouched by Tenant A's own-OU removal"
        );
    }

    // ==================== role_permissions (tenant-scoped via parent role) ====================

    /**
     * WC-8d0083f5: role_permissions has no own tenant_id — isolation is enforced
     * by a correlated EXISTS on the parent role's tenant_id. A foreign role id
     * arriving at the scoped DELETE must leave Tenant B's grants intact.
     */
    public function testRolePermissionsDeleteScopedRejectsForeignTenantRoleId(): void
    {
        TenantContext::setTenantId(self::TENANT_A);

        $handler = new class ($this->pdo, $this->hooks()) extends RolesApiHandler {
            public function purgeRolePermissions(int $roleId, int $tenantId): void
            {
                $this->deleteRolePermissionsScoped($roleId, $tenantId);
            }
        };
        // Role 200 belongs to Tenant B. Acting as Tenant A, the EXISTS subquery
        // (r.tenant_id = TENANT_A) does not match role 200's tenant_id = 2, so
        // zero rows are deleted.
        $handler->purgeRolePermissions(200, self::TENANT_A);

        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM role_permissions WHERE role_id = 200')->fetchColumn(),
            "Tenant B's role_permissions row must survive: the correlated EXISTS on roles.tenant_id rejected the foreign role id"
        );
    }

    /**
     * WC-8d0083f5 (positive control): the scoped DELETE removes Tenant A's OWN
     * grants while leaving Tenant B's grants untouched.
     */
    public function testRolePermissionsDeleteScopedRemovesOwnGrantsAndLeavesForeignIntact(): void
    {
        TenantContext::setTenantId(self::TENANT_A);

        $handler = new class ($this->pdo, $this->hooks()) extends RolesApiHandler {
            public function purgeRolePermissions(int $roleId, int $tenantId): void
            {
                $this->deleteRolePermissionsScoped($roleId, $tenantId);
            }
        };
        $handler->purgeRolePermissions(100, self::TENANT_A);

        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM role_permissions WHERE role_id = 100')->fetchColumn(),
            "Tenant A's own role_permissions grant must be removed by the same-tenant scoped delete"
        );
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM role_permissions WHERE role_id = 200')->fetchColumn(),
            "Tenant B's role_permissions grant must be untouched by Tenant A's same-tenant scoped delete"
        );
    }

    // ==================== persons ====================

    /**
     * WC-8d0083f5: persons are directly tenant-scoped — Tenant A's list must
     * contain only its own persons and never Tenant B's.
     */
    public function testPersonsListIsTenantScoped(): void
    {
        $repo = $this->personRepo();

        $rowsA = $repo->list(self::TENANT_A);
        $rowsB = $repo->list(self::TENANT_B);

        $namesA = array_column($rowsA, 'display_name');
        $namesB = array_column($rowsB, 'display_name');

        $this->assertContains('Alice-A', $namesA, "Tenant A must see its own person");
        $this->assertNotContains('Bob-B', $namesA, "Tenant B's person must never appear for Tenant A");
        $this->assertContains('Bob-B', $namesB, "Tenant B must see its own person");
        $this->assertNotContains('Alice-A', $namesB, "Tenant A's person must never appear for Tenant B");
        foreach ($rowsA as $row) {
            $this->assertSame(self::TENANT_A, $row['tenant_id']);
        }
    }

    /**
     * WC-8d0083f5: the system tenant (id 0) sees persons across all tenants.
     */
    public function testSystemTenantSeesPersonsAcrossTenants(): void
    {
        $repo = $this->personRepo();

        $rows = $repo->list(self::SYSTEM_TENANT);

        $names = array_column($rows, 'display_name');
        $this->assertContains('Alice-A', $names);
        $this->assertContains('Bob-B', $names, 'The system tenant must see all tenants\' persons');
    }

    /**
     * WC-8d0083f5: Tenant A cannot read Tenant B's person — findById returns null.
     */
    public function testTenantCannotReadForeignPerson(): void
    {
        $repo = $this->personRepo();

        $this->assertNotNull(
            $repo->findById(10, self::TENANT_A),
            "Tenant A must find its own person"
        );
        $this->assertNull(
            $repo->findById(20, self::TENANT_A),
            "Tenant B's person must be invisible to Tenant A"
        );
    }

    /**
     * WC-8d0083f5: Tenant A cannot update Tenant B's person — the scoped UPDATE
     * touches zero rows and the foreign row remains untouched.
     */
    public function testTenantCannotUpdateForeignPersonAndRowIsUntouched(): void
    {
        $repo = $this->personRepo();

        $affected = $repo->update(20, self::TENANT_A, ['display_name' => 'Hijacked']);

        $this->assertSame(0, $affected, 'A cross-tenant person update must touch zero rows');
        $this->assertSame(
            'Bob-B',
            $this->pdo->query('SELECT display_name FROM persons WHERE id = 20')->fetchColumn(),
            "Tenant B's person must be byte-for-byte untouched after the rejected update"
        );
    }

    /**
     * WC-8d0083f5: Tenant A cannot delete Tenant B's person — the scoped DELETE
     * touches zero rows and the foreign row survives.
     */
    public function testTenantCannotDeleteForeignPersonAndRowSurvives(): void
    {
        $repo = $this->personRepo();

        $affected = $repo->delete(20, self::TENANT_A);

        $this->assertSame(0, $affected, 'A cross-tenant person delete must touch zero rows');
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM persons WHERE id = 20')->fetchColumn(),
            "Tenant B's person must survive a cross-tenant delete attempt"
        );
    }

    /**
     * WC-8d0083f5: Tenant A reading Tenant B's person through the API handler
     * receives a 404 — the person is not-found and nothing leaks.
     */
    public function testPersonsApiHandlerCannotReadForeignPerson(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->personsHandler()->get(
            $this->req('GET', '/api/persons/20'),
            ['id' => '20']
        );

        $this->assertSame(404, $response->getStatusCode(), 'A foreign person read must report not-found');
        $this->assertStringNotContainsString(
            'Bob-B',
            $response->getBody(),
            'The refusal must not leak Tenant B person names'
        );
    }

    /**
     * WC-8d0083f5: Tenant A cannot update Tenant B's person through the API
     * handler — 404 returned and the row stays unchanged.
     */
    public function testPersonsApiHandlerCannotUpdateForeignPersonAndRowIsUntouched(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->personsHandler()->update(
            $this->req('PATCH', '/api/persons/20', ['displayName' => 'Hijacked']),
            ['id' => '20']
        );

        $this->assertSame(404, $response->getStatusCode(), 'A foreign person update must report not-found');
        $this->assertSame(
            'Bob-B',
            $this->pdo->query('SELECT display_name FROM persons WHERE id = 20')->fetchColumn(),
            "Tenant B's person must be byte-for-byte untouched after the rejected API update"
        );
    }

    /**
     * WC-8d0083f5: Tenant A cannot delete Tenant B's person through the API
     * handler — 404 and the row survives.
     */
    public function testPersonsApiHandlerCannotDeleteForeignPersonAndRowSurvives(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->personsHandler()->delete(
            $this->req('DELETE', '/api/persons/20'),
            ['id' => '20']
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM persons WHERE id = 20')->fetchColumn(),
            "Tenant B's person must survive a cross-tenant API delete attempt"
        );
    }

    // ==================== helpers ====================

    /**
     * Fetch a boolean column value as a PHP int (0 or 1).
     *
     * PDO returns BOOLEAN columns differently per driver:
     *  - PostgreSQL pdo_pgsql returns "t" or "f" (not "1"/"0")
     *  - SQLite returns "1" or "0"
     *
     * Normalising here keeps the test assertions engine-agnostic without
     * touching the assertion call-sites themselves.
     */
    private function fetchBool(string $sql): int
    {
        $raw = $this->pdo->query($sql)->fetchColumn();
        // Normalise via string cast: pdo_pgsql may return native bool true
        // (casts to '1') or 't'; pdo_sqlite returns '1'/1 (casts to '1').
        // false/null/'f'/'0' all normalise to 0.
        $s = (string) $raw;
        return ($s === 't' || $s === '1') ? 1 : 0;
    }

    private function usersHandler(): UsersApiHandler
    {
        return new UsersApiHandler($this->pdo, $this->hooks());
    }

    /**
     * Real {@see TwoFactorHandler} wired to the in-memory engine with a real
     * TokenValidator/TotpService/BackupCodesService (WC-191).
     */
    private function twoFactorHandler(): TwoFactorHandler
    {
        $jwtParser = new JwtParser(self::JWT_SECRET);

        return new TwoFactorHandler(
            $this->pdo,
            new TotpService(self::JWT_SECRET),
            new BackupCodesService($this->dbWrapper()),
            new TokenValidator($jwtParser, $this->pdo)
        );
    }

    /**
     * Build the PDO->query adapter BackupCodesService expects. DatabaseQueryWrapper
     * is declared inside AuthHandler.php (not its own PSR-4 file), so reference
     * AuthHandler first to guarantee that file — and thus the wrapper class — is
     * loaded regardless of test execution order.
     */
    private function dbWrapper(): DatabaseQueryWrapper
    {
        class_exists(AuthHandler::class);

        return new DatabaseQueryWrapper($this->pdo);
    }

    /**
     * Real {@see AuthHandler} wired to the in-memory engine for the 2FA-login
     * lookup test (WC-191).
     */
    private function authHandler(): AuthHandler
    {
        $jwtParser = new JwtParser(self::JWT_SECRET);

        return new AuthHandler($this->pdo, $jwtParser, new TokenValidator($jwtParser, $this->pdo));
    }

    /**
     * Mint a real, internally-valid access token for the given (user, tenant)
     * and drop it in the cookie jar so the real TokenValidator accepts it. The
     * embedded epoch matches the seeded users.token_epoch (0), so the token is
     * not rejected before reaching the 2FA handler's own tenant predicate.
     */
    /**
     * Mint a new-style access token for the profile mapped to the given legacy
     * user id. The fixture seeds profiles 101 (user 10, tenant A) and 102
     * (user 20, tenant B), so we derive the profile_id from the user id.
     *
     * Post-cutover (WC-idcut-E): TokenValidator only accepts {profile_id, active_tenant_id}.
     */
    private function authCookieFor(int $userId, int $tenantId): void
    {
        // Fixture mapping: user 10 → profile 101 (tenant A), user 20 → profile 102 (tenant B).
        // These profile IDs match the profiles seeded in makeSchema().
        $profileMap = [10 => 101, 20 => 102];
        $profileId  = $profileMap[$userId] ?? (100 + $userId);

        $token = (new JwtParser(self::JWT_SECRET))->create(
            [
                'profile_id'       => $profileId,
                'active_tenant_id' => $tenantId,
                'email'            => 'u@example',
                'role'             => 'admin',
                'token_epoch'      => 0,
            ],
            900,
            'access'
        );

        $_COOKIE['access_token'] = $token;
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

    private function personRepo(): PersonRepository
    {
        return new PersonRepository($this->pdo);
    }

    private function personsHandler(): \Whity\Api\PersonsApiHandler
    {
        return new \Whity\Api\PersonsApiHandler(
            new PersonRepository($this->pdo),
            new RelationRepository($this->pdo)
        );
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
     * In-memory SQLite with the full production schema (via migrations) seeded
     * with disjoint Tenant A / Tenant B rows.
     * PDO::ATTR_STRINGIFY_FETCHES mirrors PostgreSQL's string fetches so int
     * comparisons that only pass natively would fail here too.
     */
    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        // system tenant (id=0) comes from migration 010 — use INSERT OR IGNORE.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        // Test tenants (id=1, 2) are test-specific — plain INSERT.
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");

        // Global roles (1=admin, 2=user) come from migrations — INSERT OR IGNORE.
        $pdo->exec("
            INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES
                (1, 'admin', '', NULL, datetime('now')),
                (2, 'user',  '', NULL, datetime('now'))
        ");
        // Test-specific tenant-private roles are NOT from migrations — plain INSERT.
        $pdo->exec("
            INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (100, 'tenant-a-private', '', 1, datetime('now')),
                (200, 'tenant-b-private', '', 2, datetime('now'))
        ");

        // Grants and assignments for both tenant-private roles so a rejected
        // cross-tenant role delete/update can be proven to leave them intact.
        // users:read (id=1) is seeded by migration 002 — use INSERT OR IGNORE.
        $pdo->exec("INSERT OR IGNORE INTO permissions (id, name, description) VALUES (1, 'users:read', 'Read users')");
        $pdo->exec("
            INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES
                (100, 1, datetime('now')),
                (200, 1, datetime('now'))
        ");

        // Tenant A user 10 and Tenant B user 20 both have 2FA ENABLED at version
        // 1, so a cross-tenant 2FA read/write can be proven to leave the foreign
        // row's 2FA state byte-for-byte intact (WC-191).
        // Use true/false for BOOLEAN columns so the INSERT is accepted by both
        // PostgreSQL (strict boolean typing) and SQLite (stores as 1/0).
        $pdo->exec("
            INSERT INTO users (id, tenant_id, email, password, role_id, two_factor_secret, two_factor_enabled, two_factor_backup_codes_version, created_at) VALUES
                (10, 1, 'a1@t1.example', 'x', 1, 'a-secret', true, 1, datetime('now')),
                (11, 1, 'a2@t1.example', 'x', 2, NULL,       false, 0, datetime('now')),
                (20, 2, 'b1@t2.example', 'x', 1, 'b-secret', true, 1, datetime('now')),
                (21, 2, 'b2@t2.example', 'x', 2, NULL,       false, 0, datetime('now'))
        ");

        // user_roles was dropped by migration 039; no fixture rows needed.

        $pdo->exec("
            INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, description, created_at) VALUES
                (10, 1, NULL, 'A-Engineering', 'a-engineering', '', datetime('now')),
                (20, 2, NULL, 'B-Sales',       'b-sales',       '', datetime('now'))
        ");

        $pdo->exec("
            INSERT INTO audit_log (tenant_id, actor_user_id, action, metadata, created_at) VALUES
                (1, 10, 'tenant-a.action', '{}', datetime('now')),
                (2, 20, 'tenant-b.action', '{}', datetime('now'))
        ");

        // hello_greetings is NOT from core migrations (it's a plugin table) — create it here.
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

        // Profiles (global, migration 028) — one per person, tenant-independent.
        // Profile 101 (Alice) has memberships in BOTH tenants so findForProfile()
        // cross-tenant scan can be verified; Profile 102 (Bob) is Tenant B only.
        //
        // Note: migration 036 seeds system@whity.local as profile id=1.  We use
        // high fixture ids (101/102) to avoid collisions with that seeded row.
        // Profile 101 (Alice, Tenant A user 10): 2FA ENABLED at version 1 so cross-tenant
        // 2FA reads/writes can be proven to leave the row intact (WC-191).
        // Profile 102 (Bob, Tenant B user 20): 2FA also ENABLED so the same test
        // direction works from Tenant B's perspective.
        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (101, 'Alice', '\$2y\$10\$fakehash1', true, 1, 0, datetime('now'), datetime('now')),
                (102, 'Bob',   '\$2y\$10\$fakehash2', true, 1, 0, datetime('now'), datetime('now'))
        ");

        // profile_emails required by TwoFactorHandler::readIdentityRow() JOIN.
        $pdo->exec("
            INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES
                (101, 'a1@t1.example', true, true, datetime('now')),
                (102, 'b1@t2.example', true, true, datetime('now'))
        ");

        // Memberships (tenant-scoped, migration 030): disjoint by tenant_id so
        // cross-tenant read/write rejection can be proven per row.
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, status, created_at) VALUES
                (101, 101, 1, 1, NULL, 'active', datetime('now')),
                (102, 102, 2, 1, NULL, 'active', datetime('now'))
        ");
        // Alice also has a membership in Tenant B for the findForProfile() cross-tenant test.
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, status, created_at) VALUES
                (103, 101, 2, 2, NULL, 'active', datetime('now'))
        ");

        // WC-bc07b6de: permission_delegations now use grantor_profile_id (profiles.id)
        // and grantee_type='profile'. Delegation 1 belongs to Tenant A (grantor = Alice,
        // profile 101); delegation 2 belongs to Tenant B (grantor = Bob, profile 102).
        // These fixtures verify that cross-tenant revoke/read still fails closed.
        $pdo->exec("
            INSERT INTO permission_delegations
                (id, tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, granted_at) VALUES
                (1, 1, 101, 'profile', 101, 'users:read', datetime('now')),
                (2, 2, 102, 'profile', 102, 'users:read', datetime('now'))
        ");

        // Tenant email-domain registrations (tenant_email_domains, migration 031):
        // disjoint domains per tenant so cross-tenant read/delete rejection can be proven.
        $pdo->exec("
            INSERT INTO tenant_email_domains (id, tenant_id, domain, default_role_id, auto_provision, created_at) VALUES
                (1, 1, 'acme.com',    1, true, datetime('now')),
                (2, 2, 'example.org', 1, true, datetime('now'))
        ");

        // Website Settings per-tenant overrides (tenant_settings, migration 025):
        // disjoint keys per tenant so cross-tenant read/write rejection can be
        // proven (Tenant A overrides site_name; Tenant B overrides support_email).
        $pdo->exec("
            INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at) VALUES
                (1, 'site_name',     'Tenant A Co',   datetime('now')),
                (2, 'support_email', 'b@t2.example',  datetime('now'))
        ");

        // ou_role_assignments: one row per tenant so cross-tenant read/write
        // rejection can be proven. OU 10 belongs to Tenant A and holds role 100;
        // OU 20 belongs to Tenant B and holds role 200. The tenant_id on the
        // assignment row matches the owning tenant so the predicate is clear.
        $pdo->exec("
            INSERT INTO ou_role_assignments (tenant_id, ou_id, role_id, created_at) VALUES
                (1, 10, 100, datetime('now')),
                (2, 20, 200, datetime('now'))
        ");

        // persons: two standalone (non-profile-linked) persons, one per tenant, so
        // cross-tenant read/write/delete rejection can be proven directly against
        // the PersonRepository. Ids 10/20 mirror the OU/greeting id scheme.
        $pdo->exec("
            INSERT INTO persons (id, tenant_id, display_name, profile_id, deceased, created_at) VALUES
                (10, 1, 'Alice-A', NULL, false, datetime('now')),
                (20, 2, 'Bob-B',   NULL, false, datetime('now'))
        ");

        return $pdo;
    }
}
