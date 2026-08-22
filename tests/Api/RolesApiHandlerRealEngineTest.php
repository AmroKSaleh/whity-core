<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\RolesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine (in-memory SQLite) tests for {@see RolesApiHandler} (WC-110).
 *
 * The original WC-16 roles tests use mocked PDO, which does not enforce real SQL
 * semantics. That masked two production defects against PostgreSQL:
 *
 *  1. {@see RolesApiHandler::resolvePermissionIds()} resolved the `permissions`
 *     payload ONLY by `permissions.name`, so the numeric permission ids the web
 *     UI actually sends linked zero permissions.
 *  2. Create inserted a `user_roles` provisioning row for the acting user, which
 *     the deletion guard then counted — making every API-created role
 *     undeletable.
 *
 * These tests drive the handler against a genuine SQL engine so the real
 * INSERT/SELECT/DELETE semantics are exercised. SQLite is used because CI has no
 * live PostgreSQL; the shared `:name` placeholder grammar and a registered
 * `NOW()` UDF make the handler's statements run unmodified.
 *
 * A later WC-110 regression is also covered here: migration 018 leaves the
 * seeded base roles (`admin`, `user`) with `tenant_id IS NULL`, and the strict
 * `WHERE tenant_id = ?` scoping introduced on this branch then hid those base
 * roles from every non-system tenant — emptying the Roles page. The
 * NULL-tenant-as-GLOBAL cases below assert a tenant sees global roles plus its
 * own (read) while never being able to mutate a global base role (write), and
 * fail on the pre-change strict scoping.
 */
final class RolesApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = self::makeSqliteSchema();
        // Seed tenant 1: migration 010 only seeds system tenant id=0.
        // On PostgreSQL (real-engine PG mode via PHPUNIT_PG_DSN) the FK on
        // roles.tenant_id is enforced, so any create under tenant 1 fails
        // unless the tenant row exists. INSERT OR IGNORE is a no-op on SQLite
        // and translates to ON CONFLICT DO NOTHING on PG via the SchemaFromMigrations
        // wrapper.
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'test-tenant', datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (2, 'test-tenant-b', datetime('now'))"
        );
        MockRequestFactory::setTestTenant(1);
        // Pagination is read from $_GET first and the path query second, so a
        // stray superglobal left by another test would silently re-page these.
        $_GET = [];
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $_GET = [];
    }

    // ==================== Defect 1: id | name resolution ====================

    public function testCreateWithNumericPermissionIdsLinksThePermissions(): void
    {
        $handler = $this->handler();

        $response = $handler->create($this->authedRequest('POST', '/api/roles', [
            'name' => 'Editor',
            // The web UI sends numeric permission ids from GET /api/permissions.
            'permissions' => [1, 3],
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(2, $data['permissionCount'], 'Numeric ids must link the matching permissions.');
        $this->assertSame([1, 3], $this->linkedPermissionIds((int) $data['id']));
    }

    public function testCreateWithPermissionNamesLinksThePermissions(): void
    {
        $handler = $this->handler();

        $response = $handler->create($this->authedRequest('POST', '/api/roles', [
            'name' => 'Viewer',
            'permissions' => ['users:read', 'roles:read'],
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(2, $data['permissionCount']);
        // Resolve the expected ids from the migrated permissions table (migrations seed
        // users:read first, then several users:* columns, then roles:read — so their ids
        // are not necessarily 1 and 2 in the production schema).
        $this->assertSame(
            [$this->permIdFor('users:read'), $this->permIdFor('roles:read')],
            $this->linkedPermissionIds((int) $data['id'])
        );
    }

    public function testCreateWithMixedIdsAndNamesLinksAllAndDeduplicates(): void
    {
        $handler = $this->handler();

        $response = $handler->create($this->authedRequest('POST', '/api/roles', [
            'name' => 'Mixed',
            // id 1 == users:read (duplicate when 'users:read' name also given),
            // 'roles:read' resolves by name to its migrated id, id 3 == users:update.
            'permissions' => [1, 'users:read', 'roles:read', 3],
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(3, $data['permissionCount'], 'Mixed array must de-duplicate id/name overlap.');
        $this->assertSame([1, 3, $this->permIdFor('roles:read')], $this->linkedPermissionIds((int) $data['id']));
    }

    public function testCreateDropsUnknownIdsAndNames(): void
    {
        $handler = $this->handler();

        $response = $handler->create($this->authedRequest('POST', '/api/roles', [
            'name' => 'Partial',
            // 999 / nope:perm do not exist and must be dropped, not fabricated.
            'permissions' => [1, 999, 'nope:perm', 'users:read'],
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(1, $data['permissionCount']);
        $this->assertSame([1], $this->linkedPermissionIds((int) $data['id']));
    }

    public function testUpdateWithNumericPermissionIdsReplacesPermissions(): void
    {
        $handler = $this->handler();

        $created = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', [
                'name' => 'ToEdit',
                'permissions' => ['users:read'],
            ]))->getBody(),
            true
        )['data'];
        $roleId = (int) $created['id'];

        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/roles/' . $roleId, ['permissions' => [2, 3]]),
            ['id' => (string) $roleId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([2, 3], $this->linkedPermissionIds($roleId));
    }

    // ==================== Defect 2: created roles are deletable ====================

    public function testFreshlyCreatedRoleIsDeletable(): void
    {
        $handler = $this->handler();

        $created = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', [
                'name' => 'Disposable',
                'permissions' => [1],
            ]))->getBody(),
            true
        )['data'];
        $roleId = (int) $created['id'];

        $response = $handler->delete(
            $this->authedRequest('DELETE', '/api/roles/' . $roleId),
            ['id' => (string) $roleId]
        );

        $this->assertSame(200, $response->getStatusCode(), 'A freshly created role must be deletable.');
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM roles WHERE id = ' . $roleId)->fetchColumn()
        );
    }

    public function testRoleWithGenuineUserAssignmentStillReturns409(): void
    {
        $handler = $this->handler();

        $created = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', [
                'name' => 'InUse',
            ]))->getBody(),
            true
        )['data'];
        $roleId = (int) $created['id'];

        // A genuine (other) member is assigned the role within the tenant via
        // memberships.role_id — the sole authoritative role-assignment signal now
        // that user_roles (migration 039) and users (migration 042) are dropped.
        // Seed tenant 1 first (migration 010 only seeds system tenant id=0;
        // on PostgreSQL FK enforcement requires the tenant row to exist).
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at)
             VALUES (1, 'test-tenant', datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (50, 'real', 'x', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (50, 1, {$roleId}, 'active', datetime('now'))"
        );

        $response = $handler->delete(
            $this->authedRequest('DELETE', '/api/roles/' . $roleId),
            ['id' => (string) $roleId]
        );

        $this->assertSame(409, $response->getStatusCode());
    }

    // ==================== AC3: tenant isolation ====================

    public function testRoleCreatedUnderTenantAIsInvisibleToTenantB(): void
    {
        // Tenant A creates a role.
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $created = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantAOnly']))->getBody(),
            true
        )['data'];
        $roleId = (int) $created['id'];

        // Tenant A sees it.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(1);
        $listA = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $namesA = array_column($listA, 'name');
        $this->assertContains('TenantAOnly', $namesA);

        // Tenant B does NOT.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(2);
        $listB = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $namesB = array_column($listB, 'name');
        $this->assertNotContains('TenantAOnly', $namesB, "Tenant B must not see tenant A's role.");

        // And cannot fetch/delete it.
        $this->assertSame(
            404,
            $handler->get(new Request('GET', '/api/roles/' . $roleId), ['id' => (string) $roleId])->getStatusCode()
        );
    }

    public function testSystemTenantSeesRolesAcrossTenants(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantAScoped']));

        TenantContext::reset();
        MockRequestFactory::setTestTenant(0); // SYSTEM tenant
        $list = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $names = array_column($list, 'name');

        $this->assertContains('TenantAScoped', $names);
    }

    // ============ WC-110 global-role regression: NULL tenant_id = global ============

    public function testGlobalRoleIsListedForRegularTenantAlongsideOwnRoles(): void
    {
        // Seeded base roles are global (NULL tenant_id) — the production state
        // after migration 018 with NO backfill. They must be visible to EVERY
        // tenant. This assertion FAILS on the pre-change strict `WHERE tenant_id = ?`.
        $this->seedGlobalRole(1, 'admin');
        $this->seedGlobalRole(2, 'user');

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantOneCustom']));

        $list = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $names = array_column($list, 'name');

        $this->assertContains('admin', $names, 'Global base role must be visible to tenant 1.');
        $this->assertContains('user', $names, 'Global base role must be visible to tenant 1.');
        $this->assertContains('TenantOneCustom', $names, "Tenant's own role must also be visible.");
    }

    public function testGlobalRoleIsGettableByRegularTenant(): void
    {
        $this->seedGlobalRole(1, 'admin');

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();

        $response = $handler->get(new Request('GET', '/api/roles/1'), ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode(), 'A global role must be gettable by any tenant.');
        $this->assertSame('admin', json_decode($response->getBody(), true)['data']['name']);
    }

    public function testRegularTenantCannotUpdateGlobalRole(): void
    {
        $this->seedGlobalRole(1, 'admin');

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();

        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/roles/1', ['description' => 'hijacked']),
            ['id' => '1']
        );

        $this->assertSame(404, $response->getStatusCode(), 'A tenant must not update a global base role.');
        // The role is untouched.
        $this->assertNotSame(
            'hijacked',
            $this->pdo->query('SELECT description FROM roles WHERE id = 1')->fetchColumn()
        );
    }

    public function testRegularTenantCannotDeleteGlobalRole(): void
    {
        $this->seedGlobalRole(1, 'admin');

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();

        $response = $handler->delete(new Request('DELETE', '/api/roles/1'), ['id' => '1']);

        $this->assertSame(404, $response->getStatusCode(), 'A tenant must not delete a global base role.');
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM roles WHERE id = 1')->fetchColumn(),
            'The global role must survive a tenant delete attempt.'
        );
    }

    public function testRegularTenantCanDeleteOwnRoleButNotGlobalOne(): void
    {
        $this->seedGlobalRole(1, 'admin');

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();

        // Its own freshly-created role IS deletable.
        $created = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'OwnRole']))->getBody(),
            true
        )['data'];
        $ownId = (int) $created['id'];

        $deleteOwn = $handler->delete(new Request('DELETE', '/api/roles/' . $ownId), ['id' => (string) $ownId]);
        $this->assertSame(200, $deleteOwn->getStatusCode(), "A tenant must be able to delete its own role.");

        // The global role is NOT.
        $deleteGlobal = $handler->delete(new Request('DELETE', '/api/roles/1'), ['id' => '1']);
        $this->assertSame(404, $deleteGlobal->getStatusCode());
    }

    public function testSystemTenantCanManageGlobalAndEveryTenantRole(): void
    {
        $this->seedGlobalRole(1, 'admin');

        // Tenant 1 owns a custom role.
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $tenantRole = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantOwned']))->getBody(),
            true
        )['data'];
        $tenantRoleId = (int) $tenantRole['id'];

        // SYSTEM tenant sees both the global and the tenant-owned role.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(0);
        $list = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $names = array_column($list, 'name');
        $this->assertContains('admin', $names);
        $this->assertContains('TenantOwned', $names);

        // SYSTEM can update the GLOBAL role.
        $updateGlobal = $handler->update(
            $this->authedRequest('PATCH', '/api/roles/1', ['description' => 'system-edited']),
            ['id' => '1']
        );
        $this->assertSame(200, $updateGlobal->getStatusCode(), 'SYSTEM may manage a global role.');

        // SYSTEM can delete the tenant-owned role.
        $deleteTenant = $handler->delete(
            new Request('DELETE', '/api/roles/' . $tenantRoleId),
            ['id' => (string) $tenantRoleId]
        );
        $this->assertSame(200, $deleteTenant->getStatusCode(), 'SYSTEM may manage any tenant role.');
    }

    // ============ WC-222: per-row manageability surfaced in list() ============

    public function testListMarksGlobalRoleNotManageableForRegularTenantButOwnRoleManageable(): void
    {
        // A global (NULL-tenant) base role plus a tenant-owned role.
        $this->seedGlobalRole(1, 'admin');

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantOneCustom']));

        $list = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $byName = [];
        foreach ($list as $role) {
            $byName[$role['name']] = $role;
        }

        $this->assertArrayHasKey('admin', $byName, 'Global role must still be visible to the tenant.');
        $this->assertArrayHasKey('TenantOneCustom', $byName);

        // Global NULL-tenant role: NOT manageable by a regular tenant (write 404).
        $this->assertArrayHasKey('manageable', $byName['admin']);
        $this->assertFalse(
            $byName['admin']['manageable'],
            'A regular tenant must see a global base role as not manageable.'
        );
        // The tenant's OWN role: manageable.
        $this->assertTrue(
            $byName['TenantOneCustom']['manageable'],
            "A tenant's own role must be manageable."
        );
    }

    public function testListMarksEveryRoleManageableForSystemTenant(): void
    {
        $this->seedGlobalRole(1, 'admin');

        // Tenant 1 owns a custom role.
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantOwned']));

        // SYSTEM tenant (id 0) sees and may manage every role.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(0);
        $list = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];

        $this->assertNotEmpty($list);
        foreach ($list as $role) {
            $this->assertArrayHasKey('manageable', $role);
            $this->assertTrue(
                $role['manageable'],
                "SYSTEM tenant must see every role as manageable (role: {$role['name']})."
            );
        }
    }

    public function testTenantBStillCannotSeeTenantAOwnedRoleEvenWithGlobalRolesPresent(): void
    {
        // A global role is present, but tenant isolation for OWNED roles still holds.
        $this->seedGlobalRole(1, 'admin');

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantAPrivate']));

        TenantContext::reset();
        MockRequestFactory::setTestTenant(2);
        $list = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $names = array_column($list, 'name');

        $this->assertContains('admin', $names, 'Global role visible to tenant B.');
        $this->assertNotContains('TenantAPrivate', $names, "Tenant B must not see tenant A's owned role.");
    }

    // ============ #712: role names are unique PER TENANT, not globally ============

    public function testTwoTenantsMayEachCreateARoleWithTheSameName(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $first = $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'Supervisor']));
        $this->assertSame(201, $first->getStatusCode());

        TenantContext::reset();
        MockRequestFactory::setTestTenant(2);
        $second = $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'Supervisor']));

        $this->assertSame(
            201,
            $second->getStatusCode(),
            "Tenant B must not be blocked by a role name tenant A happens to use."
        );

        // Two distinct roles, each owned by its own tenant.
        $this->assertNotSame(
            json_decode($first->getBody(), true)['data']['id'],
            json_decode($second->getBody(), true)['data']['id']
        );
    }

    public function testDuplicateNameWithinTheSameTenantIsStillRejected(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'Supervisor']));

        $again = $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'Supervisor']));

        $this->assertSame(409, $again->getStatusCode(), 'A tenant may still not name two of its roles alike.');
    }

    public function testTenantCannotShadowAGlobalRoleName(): void
    {
        // `admin` is a seeded GLOBAL role every tenant already sees in its list;
        // letting a tenant own a second `admin` would put two identically named
        // roles in that tenant's own picker.
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();

        $response = $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'admin']));

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testRenamingToANameAnotherTenantUsesIsAllowed(): void
    {
        // Tenant B parks the name first.
        MockRequestFactory::setTestTenant(2);
        $handler = $this->handler();
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'Supervisor']));

        // Tenant A renames one of its own roles to the same word.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(1);
        $created = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'Lead']))->getBody(),
            true
        )['data'];

        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/roles/' . $created['id'], ['name' => 'Supervisor']),
            ['id' => (string) $created['id']]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $this->countRolesNamed('Supervisor'));
    }

    public function testRenamingOntoAnotherOfTheSameTenantsRolesIsRejected(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'Supervisor']));
        $other = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'Lead']))->getBody(),
            true
        )['data'];

        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/roles/' . $other['id'], ['name' => 'Supervisor']),
            ['id' => (string) $other['id']]
        );

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testSystemTenantRenamingATenantRoleIsCheckedAgainstThatTenantsNamespace(): void
    {
        // Tenant 1 owns two roles. The SYSTEM tenant may rename either, but the
        // uniqueness question is "is this free in TENANT 1?", not "is it free
        // under tenant 0?" — the acting tenant is not the owning one here.
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'Supervisor']));
        $target = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'Lead']))->getBody(),
            true
        )['data'];

        TenantContext::reset();
        MockRequestFactory::setTestTenant(0);

        $clash = $handler->update(
            $this->authedRequest('PATCH', '/api/roles/' . $target['id'], ['name' => 'Supervisor']),
            ['id' => (string) $target['id']]
        );
        $this->assertSame(409, $clash->getStatusCode(), "The clash inside tenant 1 must be seen from tenant 0.");

        // A name only OTHER tenants use is free for tenant 1.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(2);
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'Auditor']));

        TenantContext::reset();
        MockRequestFactory::setTestTenant(0);
        $ok = $handler->update(
            $this->authedRequest('PATCH', '/api/roles/' . $target['id'], ['name' => 'Auditor']),
            ['id' => (string) $target['id']]
        );
        $this->assertSame(200, $ok->getStatusCode(), "Tenant 2's name must not block a rename inside tenant 1.");
    }

    // ============ #882: who holds this role (GET /roles/{id}/assignments) ============

    /**
     * The record page's two questions answered by ONE request: the headcount is
     * `pagination.total`, and page one — ordered by when the role was granted,
     * newest first — is the recent-assignment history.
     */
    public function testAssignmentsCountHoldersAndOrderNewestGrantFirst(): void
    {
        $roleId = $this->seedTenantRole(60, 'Support', 1);
        $this->seedHolder(701, 'Alice', 'alice@example.test', 1, $roleId, '2026-01-05 09:00:00');
        $this->seedHolder(702, 'Bob', 'bob@example.test', 1, $roleId, '2026-03-05 09:00:00');
        $this->seedHolder(703, 'Carol', 'carol@example.test', 1, $roleId, '2026-02-05 09:00:00');

        $response = $this->handler()->assignments(
            $this->authedRequest('GET', "/api/roles/{$roleId}/assignments"),
            ['id' => (string) $roleId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);

        $this->assertSame(3, $body['pagination']['total'], 'total IS the headcount the record page shows.');
        $this->assertSame(
            ['Bob', 'Carol', 'Alice'],
            array_column($body['data'], 'displayName'),
            'Newest grant first, so the first row is "who most recently got this role".'
        );
        $this->assertSame('bob@example.test', $body['data'][0]['email']);
        $this->assertSame(702, $body['data'][0]['profileId']);
        $this->assertSame(1, $body['data'][0]['tenantId']);
        $this->assertNotNull($body['data'][0]['assignedAt']);
    }

    /**
     * A GLOBAL base role is visible to every tenant, so this is the case where a
     * careless query leaks a headcount — and with it the existence and size of
     * other tenants. Memberships are tenant-owned; a regular tenant counts only
     * its own.
     */
    public function testAssignmentsNeverCountAnotherTenantsHolders(): void
    {
        $this->seedGlobalRole(61, 'global-base');
        $this->seedHolder(711, 'Ours', 'ours@example.test', 1, 61, '2026-01-01 00:00:00');
        $this->seedHolder(712, 'Theirs', 'theirs@example.test', 2, 61, '2026-01-02 00:00:00');

        $response = $this->handler()->assignments(
            $this->authedRequest('GET', '/api/roles/61/assignments'),
            ['id' => '61']
        );

        $body = json_decode($response->getBody(), true);
        $this->assertSame(1, $body['pagination']['total']);
        $this->assertSame('Ours', $body['data'][0]['displayName']);
        $this->assertNotContains('Theirs', array_column($body['data'], 'displayName'));
    }

    /** The SYSTEM tenant counts across tenants, and each row names its own. */
    public function testSystemTenantSeesHoldersAcrossTenants(): void
    {
        $this->seedGlobalRole(62, 'global-base-2');
        $this->seedHolder(721, 'InOne', 'inone@example.test', 1, 62, '2026-01-01 00:00:00');
        $this->seedHolder(722, 'InTwo', 'intwo@example.test', 2, 62, '2026-01-02 00:00:00');

        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->assignments(
            $this->systemRequest('GET', '/api/roles/62/assignments'),
            ['id' => '62']
        );

        $body = json_decode($response->getBody(), true);
        $this->assertSame(2, $body['pagination']['total']);
        $this->assertEqualsCanonicalizing([1, 2], array_column($body['data'], 'tenantId'));
    }

    /**
     * Same 404 as GET /api/roles/{id} for a role the tenant cannot see, so this
     * endpoint cannot become a way to probe another tenant's roles by id — a
     * headcount for a role you are told does not exist is still a disclosure.
     */
    public function testAssignmentsForAnInvisibleRoleIs404(): void
    {
        $this->seedTenantRole(63, 'OtherTenantsRole', 2);

        $response = $this->handler()->assignments(
            $this->authedRequest('GET', '/api/roles/63/assignments'),
            ['id' => '63']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * The page is a slice; the total is the whole headcount. This is the
     * property that makes "count without fetching every user" true — a client
     * asking for the five most recent still learns there are twelve.
     */
    public function testAssignmentsPaginateWhileTotalStaysTheFullHeadcount(): void
    {
        $roleId = $this->seedTenantRole(64, 'Wide', 1);
        for ($i = 0; $i < 7; $i++) {
            $this->seedHolder(
                730 + $i,
                'Holder' . $i,
                "holder{$i}@example.test",
                1,
                $roleId,
                sprintf('2026-01-%02d 00:00:00', $i + 1)
            );
        }

        $response = $this->handler()->assignments(
            $this->authedRequest('GET', "/api/roles/{$roleId}/assignments?per_page=2"),
            ['id' => (string) $roleId]
        );

        $body = json_decode($response->getBody(), true);
        $this->assertCount(2, $body['data']);
        $this->assertSame(7, $body['pagination']['total']);
        $this->assertSame(4, $body['pagination']['totalPages']);
    }

    /**
     * The primary-email row is LEFT JOINed on purpose: somebody with no primary
     * email still holds the role. An INNER JOIN would drop them from the list
     * AND from the count, which is the quiet kind of wrong.
     */
    public function testAssignmentsIncludeAHolderWithNoPrimaryEmail(): void
    {
        $roleId = $this->seedTenantRole(65, 'Emailless', 1);
        $this->seedHolder(741, 'NoMail', null, 1, $roleId, '2026-01-01 00:00:00');

        $response = $this->handler()->assignments(
            $this->authedRequest('GET', "/api/roles/{$roleId}/assignments"),
            ['id' => (string) $roleId]
        );

        $body = json_decode($response->getBody(), true);
        $this->assertSame(1, $body['pagination']['total']);
        $this->assertSame('NoMail', $body['data'][0]['displayName']);
        $this->assertNull($body['data'][0]['email']);
    }

    /** A role nobody holds answers zero rather than erroring or 404ing. */
    public function testAssignmentsForAnUnheldRoleIsAnEmptyList(): void
    {
        $roleId = $this->seedTenantRole(66, 'Unused', 1);

        $response = $this->handler()->assignments(
            $this->authedRequest('GET', "/api/roles/{$roleId}/assignments"),
            ['id' => (string) $roleId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame([], $body['data']);
        $this->assertSame(0, $body['pagination']['total']);
    }

    // ============ #882: GET /roles/{id} carries `manageable` ============

    /**
     * A record page reached by URL has no list row to read `manageable` from, so
     * the detail payload must carry it. A tenant-owned role is writable by its
     * owner.
     */
    public function testGetReportsAnOwnedRoleAsManageable(): void
    {
        $roleId = $this->seedTenantRole(67, 'Owned', 1);

        $response = $this->handler()->get(
            $this->authedRequest('GET', "/api/roles/{$roleId}"),
            ['id' => (string) $roleId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertTrue($data['manageable']);
    }

    /**
     * The case the flag exists for: a GLOBAL base role is VISIBLE to a tenant
     * (200, not 404) but not writable by it (WC-110), so the record page must
     * render read-only rather than a form whose save 404s.
     */
    public function testGetReportsAGlobalBaseRoleAsNotManageableByATenant(): void
    {
        $this->seedGlobalRole(68, 'global-base-3');

        $response = $this->handler()->get(
            $this->authedRequest('GET', '/api/roles/68'),
            ['id' => '68']
        );

        $this->assertSame(200, $response->getStatusCode(), 'Global base roles stay VISIBLE to a tenant.');
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertFalse($data['manageable']);
    }

    // ==================== Helpers ====================

    private function handler(): RolesApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        return new RolesApiHandler($this->pdo, $hooks);
    }

    /**
     * Request carrying an authenticated acting user (user id 99, tenant 1).
     *
     * @param array<string, mixed>|null $body
     */
    private function authedRequest(string $method, string $path, ?array $body = null): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['user_id' => 99, 'tenant_id' => 1];
        return $request;
    }

    /**
     * Seed a GLOBAL/system role (NULL tenant_id) — the production state of the
     * seeded base roles after migration 018 with no backfill.
     *
     * Uses INSERT OR IGNORE because migrations already seed admin (id 1) and
     * user (id 2); this is a no-op for those rows and still inserts any new ones.
     */
    private function seedGlobalRole(int $id, string $name): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at)
             VALUES (?, ?, '', NULL, datetime('now'))"
        );
        $stmt->execute([$id, $name]);
    }

    /**
     * Seed a role OWNED by a tenant, returning its id (#882 helpers).
     */
    private function seedTenantRole(int $id, string $name, int $tenantId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at)
             VALUES (?, ?, '', ?, datetime('now'))"
        );
        $stmt->execute([$id, $name, $tenantId]);

        return $id;
    }

    /**
     * Seed a profile (optionally with a primary email) holding $roleId in
     * $tenantId as of $assignedAt — i.e. one row of the assignment history.
     *
     * `created_at` is written explicitly rather than defaulted: the ordering
     * these tests assert is the whole point of the endpoint, and rows inserted
     * in the same second would order arbitrarily.
     */
    private function seedHolder(
        int $profileId,
        string $displayName,
        ?string $email,
        int $tenantId,
        int $roleId,
        string $assignedAt
    ): void {
        $profile = $this->pdo->prepare(
            "INSERT OR IGNORE INTO profiles (id, display_name, password_hash, created_at)
             VALUES (?, ?, 'x', datetime('now'))"
        );
        $profile->execute([$profileId, $displayName]);

        if ($email !== null) {
            $emailStmt = $this->pdo->prepare(
                'INSERT OR IGNORE INTO profile_emails (profile_id, email, is_primary, created_at)
                 VALUES (?, ?, true, datetime(\'now\'))'
            );
            $emailStmt->execute([$profileId, $email]);
        }

        $membership = $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', ?)"
        );
        $membership->execute([$profileId, $tenantId, $roleId, $assignedAt]);
    }

    /** Request carrying a SYSTEM-tenant (id 0) acting user. */
    private function systemRequest(string $method, string $path): Request
    {
        $request = new Request($method, $path, [], '');
        $request->user = (object) ['user_id' => 1, 'tenant_id' => 0];
        return $request;
    }

    /** How many roles carry this name across every tenant and the global namespace. */
    private function countRolesNamed(string $name): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM roles WHERE name = ' . $this->pdo->quote($name));
        if ($stmt === false) {
            throw new \RuntimeException('Failed to count roles named ' . $name);
        }

        return (int) $stmt->fetchColumn();
    }

    private function permIdFor(string $name): int
    {
        return (int) $this->pdo->query(
            'SELECT id FROM permissions WHERE name = ' . $this->pdo->quote($name)
        )->fetchColumn();
    }

    /**
     * @return array<int, int> Linked permission ids for a role, ascending.
     */
    private function linkedPermissionIds(int $roleId): array
    {
        $stmt = $this->pdo->query(
            'SELECT permission_id FROM role_permissions WHERE role_id = ' . $roleId . ' ORDER BY permission_id'
        );
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Build an in-memory SQLite connection seeded with the full migration schema.
     */
    private static function makeSqliteSchema(): PDO
    {
        return SchemaFromMigrations::make();
    }
}
