<?php

declare(strict_types=1);

namespace Tests\Integration;

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
 * Real-engine tests for role OWNERSHIP on create, and for the `global` flag the
 * admin UI reads to tell a shared base role from a tenant's own (#886, #888).
 *
 * Deliberately in `tests/Integration` rather than beside the other
 * `RolesApiHandler` real-engine tests: CI's PostgreSQL job runs only the
 * Integration and Security suites, and everything asserted here is exactly the
 * class of thing that diverges between engines — a NULL written into an integer
 * column, a bound PHP `null` in an `IS NULL` comparison, a non-numeric value
 * bound against `tenants.id` (shrugged off by SQLite, a hard 42804 on
 * PostgreSQL). The SQLite job runs the same file through the `default` suite, so
 * both engines are covered by one set of assertions.
 *
 * The defect being closed: `POST /api/roles` stamped
 * `TenantContext::getTenantId()` unconditionally. The platform is administered
 * FROM the system tenant (0), so every role an operator created through the
 * admin UI was stamped tenant 0 — a row OWNED by the system tenant and therefore
 * invisible to every other tenant — and there was no way to say "this role
 * belongs to tenant 7" nor, since only seeding wrote NULL rows, any way to
 * create a genuinely shared one either.
 */
final class RoleTenancyRealEngineTest extends TestCase
{
    private PDO $pdo;

    /** A tenant that exists. */
    private const TENANT_A = 1;
    /** A second tenant that exists, for the isolation assertions. */
    private const TENANT_B = 2;
    /** An id no tenant row carries. */
    private const ABSENT_TENANT = 4242;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make();

        // Migration 010 seeds only the system tenant (id 0). PostgreSQL enforces
        // the FK on roles.tenant_id, so any create for tenant 1/2 fails unless
        // the tenant row exists; on SQLite these are harmless no-ops.
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (2, 'tenant-b', datetime('now'))"
        );

        MockRequestFactory::setTestTenant(self::TENANT_A);
        // Pagination reads $_GET before the path query, so a superglobal left
        // behind by another test would silently re-page the list assertions.
        $_GET = [];
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $_GET = [];
    }

    // ============ #888: an operator can create a role FOR a tenant ============

    /**
     * The headline case. A tenant-0 operator names tenant 1 and gets a role that
     * tenant 1 both sees and may edit — which before this change was
     * unexpressible from the tenant the platform is actually administered from.
     */
    public function testSystemTenantCreatesARoleOwnedByANamedTenant(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->create($this->request('POST', '/api/roles', [
            'name' => 'Ward Supervisor',
            'description' => 'Runs a ward',
            'tenant_id' => self::TENANT_A,
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(self::TENANT_A, $data['tenantId'], 'The response echoes the resolved owner.');
        $this->assertFalse($data['global']);
        $this->assertSame(self::TENANT_A, $this->ownerOf((int) $data['id']));

        // Owned means owned: tenant A may write it, tenant B cannot even see it.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(self::TENANT_A);
        $forOwner = $this->handler()->get($this->request('GET', "/api/roles/{$data['id']}"), ['id' => (string) $data['id']]);
        $this->assertSame(200, $forOwner->getStatusCode());
        $ownerView = json_decode($forOwner->getBody(), true)['data'];
        $this->assertTrue($ownerView['manageable']);
        $this->assertFalse($ownerView['global']);

        TenantContext::reset();
        MockRequestFactory::setTestTenant(self::TENANT_B);
        $forStranger = $this->handler()->get($this->request('GET', "/api/roles/{$data['id']}"), ['id' => (string) $data['id']]);
        $this->assertSame(404, $forStranger->getStatusCode(), "Another tenant must not see tenant A's role.");
    }

    /**
     * A digit STRING is accepted, matching the membership parameter's grammar —
     * query-string-shaped clients send `"7"`, and refusing it would be a
     * gratuitous difference between two endpoints that look alike.
     */
    public function testSystemTenantMayNameTheTargetTenantAsADigitString(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->create($this->request('POST', '/api/roles', [
            'name' => 'String Target',
            'tenant_id' => (string) self::TENANT_B,
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(self::TENANT_B, $this->ownerOf((int) json_decode($response->getBody(), true)['data']['id']));
    }

    /**
     * The other half of the defect: before this, NO API call could produce a
     * NULL-tenant row, so the only shared roles on any installation were the ones
     * the seeder wrote. `global: true` is the explicit, unmistakable request.
     */
    public function testSystemTenantCreatesAGlobalRoleWithTheExplicitFlag(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->create($this->request('POST', '/api/roles', [
            'name' => 'Shared Base',
            'global' => true,
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertNull($data['tenantId'], 'A global role is owned by nobody.');
        $this->assertTrue($data['global']);
        $this->assertNull($this->ownerOf((int) $data['id']));

        // Global means every tenant sees it and none of them may write it.
        foreach ([self::TENANT_A, self::TENANT_B] as $tenantId) {
            TenantContext::reset();
            MockRequestFactory::setTestTenant($tenantId);
            $view = $this->handler()->get(
                $this->request('GET', "/api/roles/{$data['id']}"),
                ['id' => (string) $data['id']]
            );
            $this->assertSame(200, $view->getStatusCode(), "Tenant {$tenantId} must see a global role.");
            $body = json_decode($view->getBody(), true)['data'];
            $this->assertTrue($body['global']);
            $this->assertFalse($body['manageable'], "Tenant {$tenantId} must not be able to write a global role.");
        }
    }

    /**
     * Nothing existing changes. An unqualified create still stamps the caller's
     * own tenant — the whole point of making the fields optional.
     */
    public function testAnUnqualifiedCreateStillStampsTheCallersOwnTenant(): void
    {
        $response = $this->handler()->create($this->request('POST', '/api/roles', ['name' => 'Own Tenant Role']));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(self::TENANT_A, $data['tenantId']);
        $this->assertFalse($data['global']);
        $this->assertSame(self::TENANT_A, $this->ownerOf((int) $data['id']));
    }

    /**
     * Including for the system tenant: an unqualified system create still stamps
     * tenant 0, NOT NULL. Quietly reinterpreting it as "global" would have
     * changed the meaning of every role already created that way.
     */
    public function testAnUnqualifiedSystemCreateStillStampsTenantZeroAndNotGlobal(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->create($this->request('POST', '/api/roles', ['name' => 'System Owned']));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(0, $data['tenantId']);
        $this->assertFalse($data['global']);
        $this->assertSame(0, $this->ownerOf((int) $data['id']));
    }

    // ==================== #888: who may ask, and how ====================

    /**
     * REFUSED, not ignored. A field that is accepted and silently discarded
     * teaches the caller it worked — and here "it worked" would mean a role
     * created in the wrong tenant.
     */
    public function testARegularTenantNamingAnotherTenantIsRefusedAndWritesNothing(): void
    {
        $response = $this->handler()->create($this->request('POST', '/api/roles', [
            'name' => 'Sneaky',
            'tenant_id' => self::TENANT_B,
        ]));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $this->countRolesNamed('Sneaky'), 'A refused create must write no row.');
    }

    /** Same rule for the global form. */
    public function testARegularTenantAskingForAGlobalRoleIsRefusedAndWritesNothing(): void
    {
        $response = $this->handler()->create($this->request('POST', '/api/roles', [
            'name' => 'Sneaky Global',
            'global' => true,
        ]));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $this->countRolesNamed('Sneaky Global'));
    }

    /**
     * `global: false` asserts nothing, so it is not an escalation attempt and
     * must not be punished as one — a client that always serialises the field
     * still gets the ordinary own-tenant create.
     */
    public function testGlobalFalseIsTreatedAsAbsentEvenForARegularTenant(): void
    {
        $response = $this->handler()->create($this->request('POST', '/api/roles', [
            'name' => 'Explicitly Local',
            'global' => false,
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(self::TENANT_A, json_decode($response->getBody(), true)['data']['tenantId']);
    }

    /**
     * A precedence rule ("tenant_id wins") is a rule nobody reads until it has
     * already written the wrong row, so both together is a refusal.
     */
    public function testNamingATenantAndAskingForGlobalTogetherIsRejected(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->create($this->request('POST', '/api/roles', [
            'name' => 'Both',
            'tenant_id' => self::TENANT_A,
            'global' => true,
        ]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $this->countRolesNamed('Both'));
    }

    /**
     * The ambiguity this contract exists to avoid. `tenant_id: null` is NOT a
     * synonym for "omitted" — if it were, whether a role landed in the caller's
     * tenant or somewhere else would depend on how a client serialises an unset
     * optional field. It is a 400, and `global: true` is the way to say global.
     */
    public function testAnExplicitNullTenantIdIsRejectedRatherThanReadAsAbsentOrGlobal(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->create($this->request('POST', '/api/roles', [
            'name' => 'Null Target',
            'tenant_id' => null,
        ]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $this->countRolesNamed('Null Target'));
    }

    /**
     * A non-numeric target is a clean 400 rather than the opaque 500 an "invalid
     * input syntax for integer" driver error becomes on PostgreSQL.
     */
    public function testANonNumericTenantIdIsAFourHundred(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->create($this->request('POST', '/api/roles', [
            'name' => 'Bad Target',
            'tenant_id' => 'sales',
        ]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $this->countRolesNamed('Bad Target'));
    }

    /** A non-boolean `global` is refused for the same reason. */
    public function testANonBooleanGlobalIsAFourHundred(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->create($this->request('POST', '/api/roles', [
            'name' => 'Bad Global',
            'global' => 'yes',
        ]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $this->countRolesNamed('Bad Global'));
    }

    /**
     * A tenant that does not exist is a 404, not a 403 — the same choice the role
     * guards make for a role the caller may not see, so the status code never
     * becomes the thing that discloses existence.
     */
    public function testNamingATenantThatDoesNotExistIsAFourOhFour(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->create($this->request('POST', '/api/roles', [
            'name' => 'Orphan',
            'tenant_id' => self::ABSENT_TENANT,
        ]));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, $this->countRolesNamed('Orphan'));
    }

    // ============ #888: uniqueness is checked in the TARGET namespace ============

    /**
     * Migration 093 made role names unique PER TENANT, and the 409 is that rule
     * read from the caller's side. A cross-tenant create moves which side that
     * is: the namespace is the tenant being written INTO, not the acting one.
     */
    public function testTheNameCollisionIsCheckedAgainstTheTargetTenantNotTheCaller(): void
    {
        // Tenant A already has "Supervisor"; the system tenant does not.
        $this->seedRole('Supervisor', self::TENANT_A);

        MockRequestFactory::setTestTenant(0);

        $intoA = $this->handler()->create($this->request('POST', '/api/roles', [
            'name' => 'Supervisor',
            'tenant_id' => self::TENANT_A,
        ]));
        $this->assertSame(409, $intoA->getStatusCode(), "Tenant A's namespace already holds the name.");

        $intoB = $this->handler()->create($this->request('POST', '/api/roles', [
            'name' => 'Supervisor',
            'tenant_id' => self::TENANT_B,
        ]));
        $this->assertSame(201, $intoB->getStatusCode(), "Tenant B's namespace is independent (#712).");
        $this->assertSame(self::TENANT_B, $this->ownerOf((int) json_decode($intoB->getBody(), true)['data']['id']));
    }

    /**
     * And a GLOBAL create is checked against the global namespace ALONE, so a
     * tenant's private role name cannot stop the operator naming a base role.
     */
    public function testAGlobalCreateIsCheckedAgainstTheGlobalNamespaceOnly(): void
    {
        $this->seedRole('Auditor', self::TENANT_A);

        MockRequestFactory::setTestTenant(0);

        $first = $this->handler()->create($this->request('POST', '/api/roles', [
            'name' => 'Auditor',
            'global' => true,
        ]));
        $this->assertSame(201, $first->getStatusCode(), "A tenant's private name must not block a base role.");
        $this->assertNull($this->ownerOf((int) json_decode($first->getBody(), true)['data']['id']));

        $second = $this->handler()->create($this->request('POST', '/api/roles', [
            'name' => 'Auditor',
            'global' => true,
        ]));
        $this->assertSame(409, $second->getStatusCode(), 'The global namespace is still unique within itself.');
    }

    // ============ #886: the list says which roles are shared ============

    /**
     * The reported experience, in one assertion: for a tenant-0 operator every
     * role is `manageable`, so `manageable` alone cannot tell a deployment-wide
     * base role from an ordinary one. `global` can.
     */
    public function testTheListDistinguishesGlobalFromOwnedForTheSystemTenant(): void
    {
        $this->seedRole('Shared Everywhere', null);
        $this->seedRole('Just Tenant A', self::TENANT_A);

        MockRequestFactory::setTestTenant(0);

        $rows = $this->rowsByName($this->handler()->list($this->request('GET', '/api/roles?per_page=100')));

        $this->assertTrue($rows['Shared Everywhere']['global']);
        $this->assertTrue($rows['Shared Everywhere']['manageable'], 'The system tenant may still edit it…');
        $this->assertFalse($rows['Just Tenant A']['global'], '…which is exactly why `manageable` cannot stand in.');
        $this->assertTrue($rows['Just Tenant A']['manageable']);
    }

    /**
     * For a regular tenant the two flags agree, and the raw owner is still not
     * disclosed — a tenant learns that a role is shared, never who else has one.
     */
    public function testTheListMarksGlobalRowsForARegularTenantWithoutNamingOwners(): void
    {
        $this->seedRole('Shared Everywhere', null);
        $this->seedRole('Just Tenant A', self::TENANT_A);
        $this->seedRole('Just Tenant B', self::TENANT_B);

        $rows = $this->rowsByName($this->handler()->list($this->request('GET', '/api/roles?per_page=100')));

        $this->assertArrayNotHasKey('Just Tenant B', $rows, "Tenant B's role stays invisible.");
        $this->assertTrue($rows['Shared Everywhere']['global']);
        $this->assertFalse($rows['Shared Everywhere']['manageable']);
        $this->assertFalse($rows['Just Tenant A']['global']);
        $this->assertTrue($rows['Just Tenant A']['manageable']);

        foreach ($rows as $name => $row) {
            $this->assertArrayNotHasKey('tenant_id', $row, "Row {$name} must not carry a raw owning tenant.");
        }
    }

    /**
     * And the record payload carries it too, for the page reached by a pasted URL
     * that has no list row to read. This is the case the record page got WRONG:
     * it inferred "global" from `!manageable`, so for the system tenant a global
     * base role rendered as the operator's own.
     */
    public function testTheRecordPayloadReportsGlobalIndependentlyOfManageable(): void
    {
        $globalId = $this->seedRole('Base Role', null);

        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->get($this->request('GET', "/api/roles/{$globalId}"), ['id' => (string) $globalId]);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertTrue($data['global']);
        $this->assertTrue($data['manageable']);
        $this->assertArrayNotHasKey('tenant_id', $data);
    }

    /**
     * The write guard is untouched by any of this: a tenant still cannot edit a
     * global base role, and still gets a 404 rather than a 403 for it.
     */
    public function testATenantStillCannotWriteAGlobalRole(): void
    {
        $globalId = $this->seedRole('Untouchable', null);

        $update = $this->handler()->update(
            $this->request('PATCH', "/api/roles/{$globalId}", ['name' => 'Renamed']),
            ['id' => (string) $globalId]
        );
        $this->assertSame(404, $update->getStatusCode());

        $delete = $this->handler()->delete(
            $this->request('DELETE', "/api/roles/{$globalId}"),
            ['id' => (string) $globalId]
        );
        $this->assertSame(404, $delete->getStatusCode());
        $this->assertSame(1, $this->countRolesNamed('Untouchable'));
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
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $path, ?array $body = null): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['user_id' => 99, 'tenant_id' => TenantContext::getTenantId()];

        return $request;
    }

    /** Seed a role owned by $tenantId, or a GLOBAL one when it is null. */
    private function seedRole(string $name, ?int $tenantId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO roles (name, description, tenant_id, created_at) VALUES (?, '', ?, datetime('now'))"
        );
        $stmt->execute([$name, $tenantId]);

        return (int) $this->pdo->lastInsertId();
    }

    /** The owning tenant of a role row: an int, or null for a GLOBAL role. */
    private function ownerOf(int $roleId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT tenant_id FROM roles WHERE id = ?');
        $stmt->execute([$roleId]);
        $value = $stmt->fetchColumn();

        return $value === null || $value === false ? null : (int) $value;
    }

    /** How many roles carry this name, across every tenant and the global namespace. */
    private function countRolesNamed(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM roles WHERE name = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * A list response's rows keyed by role name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rowsByName(\Whity\Core\Response $response): array
    {
        $this->assertSame(200, $response->getStatusCode());
        /** @var array<int, array<string, mixed>> $rows */
        $rows = json_decode($response->getBody(), true)['data'];

        $byName = [];
        foreach ($rows as $row) {
            $byName[(string) $row['name']] = $row;
        }

        return $byName;
    }
}
