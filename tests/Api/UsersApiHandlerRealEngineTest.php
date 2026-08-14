<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\UsersApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Tests\Support\MockRequestFactory;

/**
 * Real-engine tests for {@see UsersApiHandler} under the identity hard cutover
 * (WC-f3660e68 — ADR 0005 step F-a).
 *
 * The handler no longer reads/writes the legacy `users` table: identity lives on
 * the GLOBAL `profiles` + `profile_emails` tables and role/OU/status live on the
 * per-tenant `memberships` row. The `id` in list rows / payloads and the id taken
 * by get/update/delete is the canonical `profile_id`.
 *
 * These tests drive the handler against a genuine SQL engine (in-memory SQLite by
 * default, or real PostgreSQL when PHPUNIT_PG_DSN is set) so the real
 * SELECT/INSERT/UPDATE/DELETE semantics are exercised and the persisted rows are
 * read back. SQLite has a registered NOW() UDF so the PostgreSQL-flavoured
 * statements run unmodified.
 */
final class UsersApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = self::makeSqliteSchema();
        MockRequestFactory::setTestTenant(1);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== create: find-or-create profile + active membership ====================

    /**
     * Creating a user by a chosen role NAME (as the Create form sends it) creates
     * a profile + verified primary email + an ACTIVE membership carrying THAT
     * role — not the `user` default.
     */
    public function testCreatePersistsChosenRoleByNameAsMembership(): void
    {
        $handler = $this->handler();
        $response = $handler->create($this->authedRequest('POST', '/api/users', [
            'name' => 'Ignored Name',
            'email' => 'create-admin@example.com',
            'password' => 'secret-123',
            'role' => 'admin',
            'tenantId' => 1,
        ]));

        $this->assertSame(201, $response->getStatusCode());

        // A profile + primary email now exist for the email.
        $profileId = (int) $this->pdo
            ->query("SELECT profile_id FROM profile_emails WHERE email = 'create-admin@example.com'")
            ->fetchColumn();
        $this->assertGreaterThan(0, $profileId, 'A profile_email must be created for the new user.');

        // The membership in tenant 1 carries the admin role id (1) and is active.
        $membership = $this->pdo
            ->query("SELECT role_id, status, tenant_id FROM memberships WHERE profile_id = {$profileId}")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $membership['role_id'], 'A user created as admin must get an admin membership.');
        $this->assertSame('active', (string) $membership['status']);
        $this->assertSame(1, (int) $membership['tenant_id']);

        // The response is the created user in the public shape (id = profile_id).
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('admin', $data['role']);
        $this->assertSame('create-admin@example.com', $data['email']);
        $this->assertSame(1, $data['tenantId']);
        $this->assertSame($profileId, $data['id'], 'The returned id must be the profile_id.');
        $this->assertArrayNotHasKey('password', $data, 'The password hash must never leak.');
    }

    /**
     * A numeric `role_id` is still accepted for API callers and resolves to the
     * same membership role.
     */
    public function testCreateAcceptsNumericRoleId(): void
    {
        $handler = $this->handler();
        $response = $handler->create($this->authedRequest('POST', '/api/users', [
            'email' => 'create-byid@example.com',
            'password' => 'secret-123',
            'role_id' => 1,
            'tenantId' => 1,
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT m.role_id FROM memberships m
                 JOIN profile_emails pe ON pe.profile_id = m.profile_id
                 WHERE pe.email = 'create-byid@example.com'"
            )->fetchColumn()
        );
    }

    /**
     * When no role is supplied the membership defaults to the global `user` role
     * (resolved by name, not a hard-coded id).
     */
    public function testCreateWithoutRoleDefaultsToUser(): void
    {
        $handler = $this->handler();
        $response = $handler->create($this->authedRequest('POST', '/api/users', [
            'email' => 'create-default@example.com',
            'password' => 'secret-123',
            'tenantId' => 1,
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(
            2,
            (int) $this->pdo->query(
                "SELECT m.role_id FROM memberships m
                 JOIN profile_emails pe ON pe.profile_id = m.profile_id
                 WHERE pe.email = 'create-default@example.com'"
            )->fetchColumn(),
            'Absent role must default to the global user role.'
        );

        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('user', $data['role']);
    }

    /**
     * An unresolvable role NAME is rejected with 404 and creates NOTHING (no
     * profile, no membership).
     */
    public function testCreateWithUnresolvableRoleReturns404AndCreatesNothing(): void
    {
        $handler = $this->handler();
        $response = $handler->create($this->authedRequest('POST', '/api/users', [
            'email' => 'create-ghost@example.com',
            'password' => 'secret-123',
            'role' => 'ghost-role',
            'tenantId' => 1,
        ]));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM profile_emails WHERE email = 'create-ghost@example.com'")->fetchColumn(),
            'An unresolvable role must not create an identity.'
        );
    }

    /**
     * A non-system tenant cannot use another tenant's PRIVATE role on create;
     * resolution is scoped to owned + global roles, so a foreign private role
     * resolves to nothing and the create is rejected (404).
     */
    public function testCreateCannotUseAnotherTenantsPrivateRole(): void
    {
        $this->seedRole(70, 'tenant2-private', 2);

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $response = $handler->create($this->authedRequest('POST', '/api/users', [
            'email' => 'create-foreign-role@example.com',
            'password' => 'secret-123',
            'role' => 'tenant2-private',
            'tenantId' => 1,
        ]));

        $this->assertSame(404, $response->getStatusCode(), "Tenant 1 must not assign tenant 2's private role on create.");
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM profile_emails WHERE email = 'create-foreign-role@example.com'")->fetchColumn()
        );
    }

    /**
     * Adding a person whose email ALREADY maps to a profile REUSES that profile
     * (no duplicate identity) and only adds the tenant membership.
     */
    public function testCreateReusesExistingProfileForKnownEmail(): void
    {
        // Seed profile 500 (email shared@example.com) with a membership in tenant 2 only.
        $this->seedProfile(500, 'shared@example.com');
        $this->seedMembership(500, 2, 2);

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $response = $handler->create($this->authedRequest('POST', '/api/users', [
            'email' => 'shared@example.com',
            'password' => 'secret-123',
            'role' => 'admin',
            'tenantId' => 1,
        ]));

        $this->assertSame(201, $response->getStatusCode());

        // No duplicate profile: still exactly one profile for the email.
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM profile_emails WHERE email = 'shared@example.com'")->fetchColumn(),
            'A known email must reuse its profile, never create a duplicate identity.'
        );
        // A new active membership in tenant 1 for the reused profile 500.
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM memberships WHERE profile_id = 500 AND tenant_id = 1 AND status = 'active'")->fetchColumn(),
            'The reused profile must gain an active membership in the new tenant.'
        );
    }

    /**
     * Adding a person who ALREADY has an ACTIVE membership in this tenant is
     * rejected (409) and no second membership row is created.
     */
    public function testCreateRejectsDuplicateActiveMembership(): void
    {
        $this->seedProfile(501, 'dupe@example.com');
        $this->seedMembership(501, 1, 2);

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $response = $handler->create($this->authedRequest('POST', '/api/users', [
            'email' => 'dupe@example.com',
            'password' => 'secret-123',
            'role' => 'admin',
            'tenantId' => 1,
        ]));

        $this->assertSame(409, $response->getStatusCode(), 'A duplicate active membership must be rejected.');
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM memberships WHERE profile_id = 501 AND tenant_id = 1")->fetchColumn(),
            'No second membership row may be created for a duplicate.'
        );
    }

    // ==================== create: optional ou_id placement ====================
    //
    // POST /api/users used to hard-code `ou_id = NULL`, so placing a new user in
    // an organizational unit needed a second PATCH. It now accepts an optional
    // `ou_id` and validates it through the SAME gate update() uses.

    /**
     * A valid, own-tenant `ou_id` is persisted on the membership by the CREATE
     * call itself — no follow-up PATCH — and is echoed in the response.
     */
    public function testCreateWithOwnTenantOuIdPersistsOnMembership(): void
    {
        $ouId = $this->seedOu(1, 'Provisioning');

        $handler = $this->handler();
        $response = $handler->create($this->authedRequest('POST', '/api/users', [
            'email' => 'create-with-ou@example.com',
            'password' => 'secret-123',
            'role' => 'admin',
            'ou_id' => $ouId,
        ]));

        $this->assertSame(201, $response->getStatusCode());

        $profileId = (int) $this->pdo
            ->query("SELECT profile_id FROM profile_emails WHERE email = 'create-with-ou@example.com'")
            ->fetchColumn();
        $this->assertSame(
            $ouId,
            (int) $this->pdo->query("SELECT ou_id FROM memberships WHERE profile_id = {$profileId} AND tenant_id = 1")->fetchColumn(),
            'The submitted ou_id must be persisted by the create call itself.'
        );

        // toPublicUser() already returned ou_id, so the response shape is unchanged.
        $this->assertSame($ouId, json_decode($response->getBody(), true)['data']['ou_id']);
    }

    /**
     * SECURITY: an `ou_id` owned by ANOTHER tenant is refused with 403 and
     * nothing is persisted — neither the membership nor the profile.
     */
    public function testCreateWithCrossTenantOuIdReturns403AndCreatesNothing(): void
    {
        $foreignOuId = $this->seedOu(2, 'Tenant2 Provisioning');

        $handler = $this->handler();
        $response = $handler->create($this->authedRequest('POST', '/api/users', [
            'email' => 'create-foreign-ou@example.com',
            'password' => 'secret-123',
            'role' => 'admin',
            'ou_id' => $foreignOuId,
        ]));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString(
            'OU does not belong to current tenant',
            json_decode($response->getBody(), true)['error']
        );
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM profile_emails WHERE email = 'create-foreign-ou@example.com'")->fetchColumn(),
            'A cross-tenant OU must be refused BEFORE any identity is written.'
        );
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM memberships WHERE ou_id = ' . $foreignOuId)->fetchColumn(),
            'No membership may be planted across the tenant boundary.'
        );
    }

    /**
     * Omitting `ou_id` behaves exactly as before the field existed: the
     * membership is created with a NULL ou_id, so no existing caller changes.
     */
    public function testCreateWithoutOuIdLeavesMembershipUnassigned(): void
    {
        $handler = $this->handler();
        $response = $handler->create($this->authedRequest('POST', '/api/users', [
            'email' => 'create-no-ou@example.com',
            'password' => 'secret-123',
            'role' => 'admin',
        ]));

        $this->assertSame(201, $response->getStatusCode());

        $profileId = (int) $this->pdo
            ->query("SELECT profile_id FROM profile_emails WHERE email = 'create-no-ou@example.com'")
            ->fetchColumn();
        $this->assertNull(
            $this->pdo->query("SELECT ou_id FROM memberships WHERE profile_id = {$profileId} AND tenant_id = 1")->fetchColumn() ?: null,
            'An absent ou_id must still produce a NULL membership.ou_id.'
        );
        $this->assertNull(json_decode($response->getBody(), true)['data']['ou_id']);
    }

    /**
     * An explicit `{"ou_id": null}` is the same as omitting it (no OU), not an
     * error — mirroring update()'s "null clears the assignment" contract.
     */
    public function testCreateWithNullOuIdIsTreatedAsUnassigned(): void
    {
        $handler = $this->handler();
        $response = $handler->create($this->authedRequest('POST', '/api/users', [
            'email' => 'create-null-ou@example.com',
            'password' => 'secret-123',
            'ou_id' => null,
        ]));

        $this->assertSame(201, $response->getStatusCode());

        $profileId = (int) $this->pdo
            ->query("SELECT profile_id FROM profile_emails WHERE email = 'create-null-ou@example.com'")
            ->fetchColumn();
        $this->assertNull(
            $this->pdo->query("SELECT ou_id FROM memberships WHERE profile_id = {$profileId} AND tenant_id = 1")->fetchColumn() ?: null
        );
    }

    /**
     * A non-numeric `ou_id` is a clean 400 rather than an opaque 500 from the
     * driver rejecting the comparison against an integer column.
     */
    public function testCreateWithNonNumericOuIdReturns400(): void
    {
        $handler = $this->handler();
        $response = $handler->create($this->authedRequest('POST', '/api/users', [
            'email' => 'create-bad-ou@example.com',
            'password' => 'secret-123',
            'ou_id' => 'engineering',
        ]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM profile_emails WHERE email = 'create-bad-ou@example.com'")->fetchColumn()
        );
    }

    /**
     * Re-adding a profile whose membership is non-active (invited/suspended)
     * PROMOTES the existing row; a submitted ou_id is applied to it too.
     */
    public function testCreatePromotesInactiveMembershipAndAppliesOuId(): void
    {
        $this->seedProfile(510, 'promote-with-ou@example.com');
        $this->seedMembership(510, 1, 2, 'invited');
        $ouId = $this->seedOu(1, 'Promotions');

        $handler = $this->handler();
        $response = $handler->create($this->authedRequest('POST', '/api/users', [
            'email' => 'promote-with-ou@example.com',
            'password' => 'secret-123',
            'role' => 'admin',
            'ou_id' => $ouId,
        ]));

        $this->assertSame(201, $response->getStatusCode());

        $membership = $this->pdo
            ->query('SELECT status, role_id, ou_id FROM memberships WHERE profile_id = 510 AND tenant_id = 1')
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('active', (string) $membership['status']);
        $this->assertSame(1, (int) $membership['role_id']);
        $this->assertSame($ouId, (int) $membership['ou_id'], 'The promote path must honour the submitted ou_id.');
    }

    /**
     * Promoting WITHOUT an `ou_id` must leave the OU the membership already had
     * alone — an omitted field is not a request to blank it.
     */
    public function testCreatePromotionWithoutOuIdKeepsExistingOu(): void
    {
        $ouId = $this->seedOu(1, 'Retained');
        $this->seedProfile(511, 'promote-keep-ou@example.com');
        $this->seedMembershipWithOu(511, 1, 2, $ouId, 'suspended');

        $handler = $this->handler();
        $response = $handler->create($this->authedRequest('POST', '/api/users', [
            'email' => 'promote-keep-ou@example.com',
            'password' => 'secret-123',
            'role' => 'admin',
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(
            $ouId,
            (int) $this->pdo->query('SELECT ou_id FROM memberships WHERE profile_id = 511 AND tenant_id = 1')->fetchColumn(),
            'An omitted ou_id must not clear an existing OU assignment.'
        );
    }

    // ==================== update: role via membership ====================

    /**
     * Changing a user's role by NAME persists the new role ON THE MEMBERSHIP and
     * the response reflects it.
     */
    public function testRoleUpdatePersistsOnMembership(): void
    {
        // Profile 10 (tenant 1) starts as 'user' (role id 2).
        $this->seedProfile(10, 'persist@example.com');
        $this->seedMembership(10, 1, 2);

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/10', ['role' => 'admin']),
            ['id' => '10']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT role_id FROM memberships WHERE profile_id = 10 AND tenant_id = 1')->fetchColumn(),
            'The new role must be written to memberships.role_id.'
        );

        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('admin', $data['role']);
        $this->assertSame(10, $data['id']);
        $this->assertArrayNotHasKey('password', $data, 'The password hash must never leak.');
    }

    /**
     * An email change is written to the PROFILE's primary profile_email, not to
     * any users row.
     */
    public function testEmailUpdatePersistsOnProfileEmail(): void
    {
        $this->seedProfile(13, 'old@example.com');
        $this->seedMembership(13, 1, 2);

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/13', ['email' => 'new@example.com']),
            ['id' => '13']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'new@example.com',
            (string) $this->pdo->query('SELECT email FROM profile_emails WHERE profile_id = 13 AND is_primary = true')->fetchColumn(),
            'The new email must be written to the profile primary email.'
        );
    }

    /**
     * `name` and `tenantId` in the body are ignored; only the role changes.
     */
    public function testNameAndTenantInBodyAreIgnoredButRolePersists(): void
    {
        $this->seedProfile(11, 'ignore@example.com');
        $this->seedMembership(11, 1, 2);

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/11', [
                'name' => 'Brand New Name',
                'tenantId' => 99,
                'role' => 'admin',
            ]),
            ['id' => '11']
        );

        $this->assertSame(200, $response->getStatusCode());

        $row = $this->pdo->query('SELECT tenant_id, role_id FROM memberships WHERE profile_id = 11')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $row['tenant_id'], 'tenantId in the body must NOT move the membership.');
        $this->assertSame(1, (int) $row['role_id'], 'role must still persist.');

        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('ignore', $data['name'], 'name remains derived from the email local-part.');
        $this->assertSame(1, $data['tenantId']);
    }

    /**
     * Re-assigning the SAME role is a genuine no-op: 200 with the unchanged record.
     */
    public function testNoopReturnsCurrentRecord(): void
    {
        $this->seedProfile(12, 'noop@example.com');
        $this->seedMembership(12, 1, 2);

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/12', [
                'name' => 'Whatever',
                'role' => 'user',
            ]),
            ['id' => '12']
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('user', $data['role']);
        $this->assertSame(2, (int) $this->pdo->query('SELECT role_id FROM memberships WHERE profile_id = 12')->fetchColumn());
    }

    // ==================== update/delete: tenant isolation ====================

    /**
     * A non-system tenant cannot edit a profile without a membership in its
     * tenant: reported as 404 and the foreign membership untouched.
     */
    public function testCannotEditUserOutsideTenantReturns404(): void
    {
        // Profile 20 has a membership only in tenant 2.
        $this->seedProfile(20, 'foreign@example.com');
        $this->seedMembership(20, 2, 2);

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/20', ['role' => 'admin']),
            ['id' => '20']
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            2,
            (int) $this->pdo->query('SELECT role_id FROM memberships WHERE profile_id = 20 AND tenant_id = 2')->fetchColumn(),
            "Tenant 1 must not be able to change tenant 2's membership role."
        );
    }

    /**
     * A tenant cannot assign a role OWNED by another tenant (404).
     */
    public function testCannotAssignAnotherTenantsPrivateRole(): void
    {
        $this->seedProfile(30, 'scoped@example.com');
        $this->seedMembership(30, 1, 2);
        $this->seedRole(50, 'tenant2-only', 2);

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/30', ['role' => 'tenant2-only']),
            ['id' => '30']
        );

        $this->assertSame(404, $response->getStatusCode(), "Tenant 1 must not assign tenant 2's private role.");
        $this->assertSame(
            2,
            (int) $this->pdo->query('SELECT role_id FROM memberships WHERE profile_id = 30 AND tenant_id = 1')->fetchColumn()
        );
    }

    /**
     * The SYSTEM tenant (id 0) may edit a membership in any tenant and assign any
     * role; the change persists.
     */
    public function testSystemTenantCanEditAcrossTenants(): void
    {
        $this->seedProfile(40, 'crosstenant@example.com');
        $this->seedMembership(40, 2, 2); // tenant 2 membership

        MockRequestFactory::setTestTenant(0);
        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/40', ['role' => 'admin'], 0),
            ['id' => '40']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT role_id FROM memberships WHERE profile_id = 40 AND tenant_id = 2')->fetchColumn(),
            'SYSTEM tenant must be able to change a cross-tenant membership role.'
        );
    }

    /**
     * Delete removes the caller-tenant MEMBERSHIP; the GLOBAL profile survives.
     */
    public function testDeleteRemovesMembershipButProfileSurvives(): void
    {
        $this->seedProfile(80, 'delete-me@example.com');
        $this->seedMembership(80, 1, 2);

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $response = $handler->delete($this->authedRequest('DELETE', '/api/users/80'), ['id' => '80']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM memberships WHERE profile_id = 80 AND tenant_id = 1')->fetchColumn(),
            'The tenant membership must be removed.'
        );
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM profiles WHERE id = 80')->fetchColumn(),
            'The global profile must survive a membership removal.'
        );
    }

    /**
     * Deleting a membership does not remove the profile's OTHER tenant
     * memberships.
     */
    public function testDeleteLeavesOtherTenantMembershipIntact(): void
    {
        $this->seedProfile(81, 'multi@example.com');
        $this->seedMembership(81, 1, 2);
        $this->seedMembership(81, 2, 2);

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->delete($this->authedRequest('DELETE', '/api/users/81'), ['id' => '81']);

        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM memberships WHERE profile_id = 81 AND tenant_id = 2')->fetchColumn(),
            "Removing the tenant-1 membership must leave the tenant-2 membership intact."
        );
    }

    // ==================== list / count reconciliation with stats ====================

    /**
     * The list headline total counts ACTIVE memberships in the tenant, matching
     * exactly the basis AdminApiHandler::stats() uses
     * (memberships WHERE tenant_id = :tid AND status = 'active').
     */
    public function testListCountReconcilesWithStatsActiveMembershipCount(): void
    {
        // Tenant 1: two active + one suspended membership.
        $this->seedProfile(90, 'p90@example.com');
        $this->seedProfile(91, 'p91@example.com');
        $this->seedProfile(92, 'p92@example.com');
        $this->seedMembership(90, 1, 2, 'active');
        $this->seedMembership(91, 1, 1, 'active');
        $this->seedMembership(92, 1, 2, 'suspended');

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $response = $handler->list($this->authedRequest('GET', '/api/users'));
        $this->assertSame(200, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);

        // The stats active-membership count for tenant 1.
        $statsActive = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM memberships WHERE tenant_id = 1 AND status = 'active'"
        )->fetchColumn();

        $this->assertSame($statsActive, $decoded['pagination']['total'], 'List total must equal stats active-membership count.');
        $this->assertSame(2, $decoded['pagination']['total'], 'Only the two active memberships are counted.');

        // The list rows themselves only carry active memberships.
        $ids = array_column($decoded['data'], 'id');
        $this->assertContains(90, $ids);
        $this->assertContains(91, $ids);
        $this->assertNotContains(92, $ids, 'A suspended membership must not appear in the list.');
    }

    // ==================== cache invalidation ====================

    /**
     * A role change invalidates the worker-level effective-permission cache.
     */
    public function testRoleChangeInvalidatesPermissionCache(): void
    {
        $this->seedProfile(60, 'cache@example.com');
        $this->seedMembership(60, 1, 2);

        $pdo = $this->pdo;
        $database = \Whity\Database\Database::withFactory(static fn (): PDO => $pdo);
        $checker = new RoleChecker(
            $database,
            $this->createMock(\Whity\Core\RBAC\PermissionRegistry::class)
        );
        $checker->getEffectivePermissionsForRole(2);
        $this->assertTrue($this->cacheIsWarm(), 'Pre-condition: the cache should be warm.');

        $handler = $this->handler();
        $handler->update(
            $this->authedRequest('PATCH', '/api/users/60', ['role' => 'admin']),
            ['id' => '60']
        );

        $this->assertFalse($this->cacheIsWarm(), 'A role change must clear the effective-permission cache.');
    }

    // ==================== account status (WC-user-status): deactivate/reactivate ====================

    /**
     * An admin can deactivate a user (profiles.status -> 'inactive') via PATCH,
     * and the change is visible both in the DB and in the response's
     * `accountStatus` field.
     */
    public function testUpdateCanDeactivateAccount(): void
    {
        $this->seedProfile(200, 'deactivate-me@example.com');
        $this->seedMembership(200, 1, 2);

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/200', ['accountStatus' => 'inactive']),
            ['id' => '200']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'inactive',
            (string) $this->pdo->query('SELECT status FROM profiles WHERE id = 200')->fetchColumn(),
            'profiles.status must be written to inactive.'
        );

        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('inactive', $data['accountStatus']);
    }

    /**
     * A deactivated account can be reactivated the same way (round-trip).
     */
    public function testUpdateCanReactivateAccount(): void
    {
        $this->seedProfile(201, 'reactivate-me@example.com', 'inactive');
        $this->seedMembership(201, 1, 2);

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/201', ['accountStatus' => 'active']),
            ['id' => '201']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'active',
            (string) $this->pdo->query('SELECT status FROM profiles WHERE id = 201')->fetchColumn()
        );
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('active', $data['accountStatus']);
    }

    /**
     * An unrecognised accountStatus value is rejected (400) and nothing is
     * written.
     */
    public function testUpdateRejectsInvalidAccountStatusValue(): void
    {
        $this->seedProfile(202, 'invalid-status@example.com');
        $this->seedMembership(202, 1, 2);

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/202', ['accountStatus' => 'banned']),
            ['id' => '202']
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'active',
            (string) $this->pdo->query('SELECT status FROM profiles WHERE id = 202')->fetchColumn(),
            'An invalid accountStatus must not be persisted.'
        );
    }

    /**
     * Re-submitting the SAME accountStatus is a genuine no-op: 200, nothing
     * changes, and the record is echoed back unchanged (mirrors the existing
     * role no-op contract, testNoopReturnsCurrentRecord).
     */
    public function testAccountStatusNoopReturnsCurrentRecord(): void
    {
        $this->seedProfile(203, 'status-noop@example.com');
        $this->seedMembership(203, 1, 2);

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/203', ['accountStatus' => 'active']),
            ['id' => '203']
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('active', $data['accountStatus']);
    }

    /**
     * Deactivating a profile is GLOBAL (ADR 0005 §1): it is not scoped to one
     * tenant's membership row. A profile with memberships in two tenants is
     * deactivated everywhere by a single tenant's admin action.
     */
    public function testDeactivateAffectsProfileAcrossAllItsTenantMemberships(): void
    {
        $this->seedProfile(204, 'multi-tenant@example.com');
        $this->seedMembership(204, 1, 2);
        $this->seedMembership(204, 2, 2);

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->update(
            $this->authedRequest('PATCH', '/api/users/204', ['accountStatus' => 'inactive']),
            ['id' => '204']
        );

        $this->assertSame(
            'inactive',
            (string) $this->pdo->query('SELECT status FROM profiles WHERE id = 204')->fetchColumn(),
            'The GLOBAL profile status must reflect the deactivation regardless of which tenant acted.'
        );
    }

    /**
     * A non-system tenant cannot deactivate a profile that has no membership
     * in ITS tenant (404, same as any other cross-tenant edit attempt) — the
     * account status is untouched.
     */
    public function testCannotDeactivateUserOutsideTenantReturns404(): void
    {
        $this->seedProfile(205, 'foreign-status@example.com');
        $this->seedMembership(205, 2, 2); // membership only in tenant 2

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/205', ['accountStatus' => 'inactive']),
            ['id' => '205']
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            'active',
            (string) $this->pdo->query('SELECT status FROM profiles WHERE id = 205')->fetchColumn(),
            "Tenant 1 must not be able to deactivate tenant 2's user."
        );
    }

    /**
     * The SYSTEM tenant (id 0) MAY deactivate a profile whose only membership
     * is in a different tenant.
     */
    public function testSystemTenantCanDeactivateAcrossTenants(): void
    {
        $this->seedProfile(206, 'system-deactivate@example.com');
        $this->seedMembership(206, 2, 2);

        MockRequestFactory::setTestTenant(0);
        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/206', ['accountStatus' => 'inactive'], 0),
            ['id' => '206']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'inactive',
            (string) $this->pdo->query('SELECT status FROM profiles WHERE id = 206')->fetchColumn(),
            'The SYSTEM tenant must be able to deactivate a cross-tenant profile.'
        );
    }

    // ==================== update: ou_id assignment (migrated from the mocked-PDO unit test) ====================
    //
    // Migrated from the mocked-PDO tests/Unit/Api/UsersApiHandlerTest.php onto
    // this real-engine fixture, preserving the original intent/assertions. The
    // mocked file predated the identity hard cutover and stubbed a legacy
    // `users` table read/write path that no longer exists (a `createMock(PDO)`
    // happily returns canned rows regardless of the actual SQL the handler
    // issues); the ou_id validation/assignment behaviour it exercised still
    // exists today on the membership row, so it is ported here against the
    // genuine profiles/profile_emails/memberships schema.

    /**
     * Assigning a valid, own-tenant ou_id persists it on the membership.
     */
    public function testUpdateWithValidOuIdPersists(): void
    {
        $this->seedProfile(70, 'ou-target@example.com');
        $this->seedMembership(70, 1, 2);
        $ouId = $this->seedOu(1, 'Engineering');

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/70', ['ou_id' => $ouId]),
            ['id' => '70']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            $ouId,
            (int) $this->pdo->query('SELECT ou_id FROM memberships WHERE profile_id = 70 AND tenant_id = 1')->fetchColumn()
        );
    }

    /**
     * ou_id = 0 clears the membership's OU assignment (treated as NULL).
     */
    public function testUpdateWithOuIdZeroClearsAssignment(): void
    {
        $this->seedProfile(71, 'ou-clear-zero@example.com');
        $ouId = $this->seedOu(1, 'Sales');
        $this->seedMembershipWithOu(71, 1, 2, $ouId);

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/71', ['ou_id' => 0]),
            ['id' => '71']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(
            $this->pdo->query('SELECT ou_id FROM memberships WHERE profile_id = 71 AND tenant_id = 1')->fetchColumn() ?: null
        );
    }

    /**
     * ou_id = null clears the membership's OU assignment.
     *
     * BUG FOUND BY THIS MIGRATION: the handler used to guard this branch with
     * `isset($body['ou_id'])`, which is FALSE for an explicit JSON `null` value
     * (PHP's isset() treats a null value as absent) — so a `{"ou_id": null}`
     * body silently fell through to the "nothing changed" no-op and never
     * cleared the membership's OU. The mocked-PDO version of this test
     * (`testUpdateUserWithOuIdNullReturns200`) never caught it because it only
     * asserted the response status, never that the DB write actually happened.
     * Fixed in {@see \Whity\Api\UsersApiHandler::update()} by switching to
     * `array_key_exists()`, mirroring the same isset-vs-null fix already
     * documented on {@see \Whity\Api\OusApiHandler::update()}'s parent_id
     * handling.
     */
    public function testUpdateWithOuIdNullClearsAssignment(): void
    {
        $this->seedProfile(72, 'ou-clear-null@example.com');
        $ouId = $this->seedOu(1, 'Marketing');
        $this->seedMembershipWithOu(72, 1, 2, $ouId);

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/72', ['ou_id' => null]),
            ['id' => '72']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(
            $this->pdo->query('SELECT ou_id FROM memberships WHERE profile_id = 72 AND tenant_id = 1')->fetchColumn() ?: null
        );
    }

    /**
     * A cross-tenant ou_id (belonging to a different tenant than the
     * membership's owning tenant) is rejected with 403 and the membership's
     * ou_id is left untouched.
     */
    public function testUpdateWithCrossTenantOuIdReturns403(): void
    {
        $this->seedProfile(73, 'ou-cross@example.com');
        $this->seedMembership(73, 1, 2);
        $foreignOuId = $this->seedOu(2, 'Tenant2 OU');

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/73', ['ou_id' => $foreignOuId]),
            ['id' => '73']
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString(
            'OU does not belong to current tenant',
            json_decode($response->getBody(), true)['error']
        );
        $this->assertNull(
            $this->pdo->query('SELECT ou_id FROM memberships WHERE profile_id = 73 AND tenant_id = 1')->fetchColumn() ?: null
        );
    }

    /**
     * A non-numeric `ou_id` is a clean 400 on UPDATE too — the shared gate used
     * to hand the raw value to the driver, which on PostgreSQL raised "invalid
     * input syntax for integer" and surfaced as an opaque 500.
     */
    public function testUpdateWithNonNumericOuIdReturns400(): void
    {
        $this->seedProfile(75, 'ou-nonnumeric@example.com');
        $this->seedMembership(75, 1, 2);

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/75', ['ou_id' => 'engineering']),
            ['id' => '75']
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull(
            $this->pdo->query('SELECT ou_id FROM memberships WHERE profile_id = 75 AND tenant_id = 1')->fetchColumn() ?: null
        );
    }

    /**
     * A role NAME that is not visible to the tenant is rejected with 404 on
     * UPDATE (mirroring create's resolution guard) and nothing is persisted.
     */
    public function testUpdateWithUnresolvableRoleReturns404(): void
    {
        $this->seedProfile(74, 'ghost-role@example.com');
        $this->seedMembership(74, 1, 2);

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/74', ['role' => 'ghost-role']),
            ['id' => '74']
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            2,
            (int) $this->pdo->query('SELECT role_id FROM memberships WHERE profile_id = 74 AND tenant_id = 1')->fetchColumn(),
            'An unresolvable role must not change the persisted role_id.'
        );
    }

    /**
     * Missing `id` route param on update returns 400.
     */
    public function testUpdateWithoutIdReturns400(): void
    {
        $handler = $this->handler();
        $response = $handler->update($this->authedRequest('PATCH', '/api/users', ['ou_id' => 10]), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    // ==================== Helpers ====================

    private function handler(): UsersApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        return new UsersApiHandler($this->pdo, $hooks);
    }

    /**
     * Request carrying an authenticated acting user.
     *
     * @param array<string, mixed>|null $body
     */
    private function authedRequest(string $method, string $path, ?array $body = null, int $tenantId = 1): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['profile_id' => 99, 'active_tenant_id' => $tenantId];
        return $request;
    }

    private function seedProfile(int $id, string $email, string $status = 'active'): void
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, status, created_at, updated_at)
             VALUES (?, ?, 'x', false, 0, 0, ?, datetime('now'), datetime('now'))"
        )->execute([$id, strstr($email, '@', true) ?: $email, $status]);

        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        )->execute([$id, $email]);
    }

    /**
     * A profile holding a SECOND membership in the same tenant must appear once.
     *
     * The list joins memberships, so without a predicate on the primary row a
     * two-role person becomes two rows — and because the join is paginated with
     * LIMIT/OFFSET, the page boundaries shift too. Nothing throws; the endpoint
     * just reports a tenant with more people in it than it has, which is why
     * this is pinned rather than left to review.
     *
     * `true`/`false` literals, not 1/0: SQLite accepts an integer for a boolean
     * column and PostgreSQL does not, and this suite runs on both.
     */
    public function testASecondMembershipDoesNotDuplicateTheUserInTheList(): void
    {
        $this->seedProfile(95, 'p95@example.com');
        $this->seedMembership(95, 1, 2, 'active');

        // The same person, a second tenant-wide role (migration 094 permits it).
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
             VALUES (?, ?, ?, NULL, false, 'active', datetime('now'))"
        )->execute([95, 1, 1]);

        MockRequestFactory::setTestTenant(1);
        $response = $this->handler()->list($this->authedRequest('GET', '/api/users'));
        $this->assertSame(200, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);

        $ids = array_column($decoded['data'], 'id');
        $this->assertSame(
            [95],
            $ids,
            'a profile with two memberships must appear exactly once in the list'
        );
        $this->assertSame(
            1,
            $decoded['pagination']['total'],
            'the paginated total must count people, not memberships — it drives LIMIT/OFFSET'
        );
    }

    private function seedMembership(int $profileId, int $tenantId, int $roleId, string $status = 'active'): void
    {
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, NULL, ?, datetime('now'))"
        )->execute([$profileId, $tenantId, $roleId, $status]);
    }

    private function seedRole(int $id, string $name, ?int $tenantId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO roles (id, name, description, tenant_id, created_at)
             VALUES (?, ?, '', ?, datetime('now'))"
        );
        $stmt->execute([$id, $name, $tenantId]);
    }

    /**
     * Read whether the RoleChecker worker cache currently holds any entry, using
     * reflection on its private static cache (it has no public getter).
     */
    private function cacheIsWarm(): bool
    {
        $ref = new \ReflectionClass(RoleChecker::class);
        $prop = $ref->getProperty('effectivePermissionCache');
        $prop->setAccessible(true);
        /** @var array<int, array<int, string>> $cache */
        $cache = $prop->getValue();
        return $cache !== [];
    }

    /**
     * Build an in-memory SQLite connection seeded with the full migration schema.
     * The seeded base roles `admin` (id 1) and `user` (id 2) come from migrations;
     * `moderator` (id 3) is test-only and is inserted here.
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (3, 'moderator', '', NULL, datetime('now'))");

        return $pdo;
    }


    /** Seed a membership with an explicit ou_id (rather than seedMembership()'s NULL). */
    private function seedMembershipWithOu(int $profileId, int $tenantId, int $roleId, int $ouId, string $status = 'active'): void
    {
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, ?, ?, datetime('now'))"
        )->execute([$profileId, $tenantId, $roleId, $ouId, $status]);
    }

    /** Seed an organizational unit for the given tenant and return its id. */
    private function seedOu(int $tenantId, string $name): int
    {
        $slug = strtolower(str_replace(' ', '-', $name)) . '-' . $tenantId;
        $stmt = $this->pdo->prepare(
            "INSERT INTO organizational_units (tenant_id, parent_id, name, slug, description, created_at)
             VALUES (?, NULL, ?, ?, '', datetime('now'))"
        );
        $stmt->execute([$tenantId, $name, $slug]);

        return (int) $this->pdo->lastInsertId();
    }
}
