<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\RBAC\CorePermissions;
use Whity\Api\RolesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for `POST` / `DELETE /api/roles/{id}/permissions` (#712).
 *
 * The endpoints exist because the only way to change a role's permissions was
 * to send the WHOLE set through `POST /api/roles` or `PATCH /api/roles/{id}`,
 * which forces every caller into a read-modify-write. Two admins doing that
 * concurrently each compute a set from a view taken before the other's change,
 * and the second write silently erases the first — no error, no conflict, no
 * trace. {@see self::testConcurrentAdminsDoNotClobberEachOther()} is that exact
 * scenario, and is the test the whole feature is for.
 *
 * The rest pin the properties that make a delta endpoint usable: idempotence in
 * both directions (so a caller may assert an end state without first reading
 * the current one), and the same tenant boundary and write gate as the role
 * endpoints they supplement — a delta must not become a way around a check the
 * full replace enforces.
 */
final class RolePermissionDeltaRealEngineTest extends TestCase
{
    private PDO $pdo;

    /**
     * Three real permission ids, resolved by NAME rather than written down.
     *
     * These were the literals 1, 2, and 3 — "any three valid permission ids" —
     * which held until migration 112 removed the dead `create`/`update`
     * vocabulary and left holes at 2 and 3. The seeded id space is not a
     * contract, and a test that assumes it is fails the next time the catalogue
     * changes for a reason that has nothing to do with the test.
     *
     * `ous:read` rather than `roles:read` for the third: these tests also pass
     * 'roles:read' BY NAME to exercise id/name de-duplication, so reusing it here
     * would make the dedupe assertion pass for the wrong reason.
     *
     * Chosen so their ids ASCEND, because `linkedPermissionIds()` returns them
     * sorted: every `[$a, $b, $c]` assertion below therefore keeps the shape it
     * had when it said `[1, 2, 3]`.
     */
    private int $permA;
    private int $permB;
    private int $permC;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (2, 'tenant-b', datetime('now'))"
        );
        SchemaFromMigrations::syncSequences($this->pdo);
        MockRequestFactory::setTestTenant(1);
    
        $this->permA = $this->permIdFor('users:read');
        $this->permB = $this->permIdFor('users:delete');
        $this->permC = $this->permIdFor('ous:read');
}

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ── the race the endpoints exist to remove ──────────────────────────────

    public function testConcurrentAdminsDoNotClobberEachOther(): void
    {
        $handler = $this->handler();
        $roleId = $this->createRole($handler, 'Editor', [$this->permA]);

        // Two admins, each holding the same stale view of the role (permission 1),
        // each adding a DIFFERENT permission. Through the full-replace PATCH they
        // would send [1, 2] and [1, 3] and the later write would erase the other's
        // addition. Through the delta endpoint each sends only what it adds.
        $handler->grantPermissions($this->authedRequest('POST', "/api/roles/{$roleId}/permissions", [
            'permissions' => [$this->permB],
        ]), ['id' => (string) $roleId]);

        $handler->grantPermissions($this->authedRequest('POST', "/api/roles/{$roleId}/permissions", [
            'permissions' => [$this->permC],
        ]), ['id' => (string) $roleId]);

        $this->assertSame(
            [$this->permA, $this->permB, $this->permC],
            $this->linkedPermissionIds($roleId),
            'Both concurrent additions must survive; neither admin overwrites the other.'
        );
    }

    // ── grant ───────────────────────────────────────────────────────────────

    public function testGrantAddsWithoutDisturbingExistingGrants(): void
    {
        $handler = $this->handler();
        $roleId = $this->createRole($handler, 'Editor', [$this->permA, $this->permB]);

        $response = $handler->grantPermissions(
            $this->authedRequest('POST', "/api/roles/{$roleId}/permissions", ['permissions' => [$this->permC]]),
            ['id' => (string) $roleId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(1, $data['granted']);
        $this->assertSame([$this->permA, $this->permB, $this->permC], $this->linkedPermissionIds($roleId));
        // The response carries the resulting set so a caller need not re-GET.
        // (Ordered by permission NAME, as GET /api/roles/{id}/permissions is.)
        $returned = array_map('intval', array_column($data['permissions'], 'id'));
        sort($returned);
        $this->assertSame([$this->permA, $this->permB, $this->permC], $returned);
    }

    public function testGrantingAnAlreadyHeldPermissionSucceedsAndChangesNothing(): void
    {
        $handler = $this->handler();
        $roleId = $this->createRole($handler, 'Editor', [$this->permA, $this->permB]);

        $response = $handler->grantPermissions(
            $this->authedRequest('POST', "/api/roles/{$roleId}/permissions", ['permissions' => [$this->permA, $this->permB]]),
            ['id' => (string) $roleId]
        );

        $this->assertSame(200, $response->getStatusCode(), 'A re-grant is a success, not a duplicate-key error.');
        $this->assertSame(0, json_decode($response->getBody(), true)['data']['granted']);
        $this->assertSame([$this->permA, $this->permB], $this->linkedPermissionIds($roleId));
    }

    public function testGrantAcceptsPermissionNamesAsWellAsIds(): void
    {
        $handler = $this->handler();
        $roleId = $this->createRole($handler, 'Editor', []);

        $handler->grantPermissions(
            $this->authedRequest('POST', "/api/roles/{$roleId}/permissions", [
                'permissions' => ['users:read', $this->permC],
            ]),
            ['id' => (string) $roleId]
        );

        $this->assertSame(
            [$this->permIdFor('users:read'), $this->permC],
            $this->linkedPermissionIds($roleId)
        );
    }

    public function testGrantDropsUnknownReferencesRatherThanFabricatingThem(): void
    {
        $handler = $this->handler();
        $roleId = $this->createRole($handler, 'Editor', []);

        $response = $handler->grantPermissions(
            $this->authedRequest('POST', "/api/roles/{$roleId}/permissions", [
                'permissions' => [$this->permA, 99999, 'nope:perm'],
            ]),
            ['id' => (string) $roleId]
        );

        $this->assertSame(1, json_decode($response->getBody(), true)['data']['granted']);
        $this->assertSame([$this->permA], $this->linkedPermissionIds($roleId));
    }

    // ── revoke ──────────────────────────────────────────────────────────────

    /**
     * #1040: granting `documents:route` also grants `groups:read`.
     *
     * This is the case migration 137 cannot reach. It repaired the roles holding
     * `documents:route` at the moment it ran; a role granted the capability
     * afterwards — through this very endpoint — would otherwise compose a route
     * and then take a 403 listing the groups it needs to route to.
     */
    public function testGrantingDocumentsRouteAlsoGrantsGroupsRead(): void
    {
        $handler = $this->handler();
        $roleId = $this->createRole($handler, 'Router', []);

        $response = $handler->grantPermissions(
            $this->authedRequest('POST', "/api/roles/{$roleId}/permissions", [
                'permissions' => [CorePermissions::DOCUMENTS_ROUTE],
            ]),
            ['id' => (string) $roleId]
        );

        $this->assertSame(200, $response->getStatusCode());

        $held = $this->linkedPermissionIds($roleId);
        $this->assertContains($this->permIdFor(CorePermissions::DOCUMENTS_ROUTE), $held);
        $this->assertContains(
            $this->permIdFor(CorePermissions::GROUPS_READ),
            $held,
            'the companion must be a REAL grant row, visible in the picker and revocable — '
            . 'not an implication resolved at check time'
        );
    }

    /**
     * And revoking the primary leaves the companion alone.
     *
     * By then the grant is a fact an administrator can see and may rely on for
     * its own sake; retracting it as a side effect would be the same surprise
     * from the other direction. Taking it back is a deliberate second act.
     */
    public function testRevokingDocumentsRouteDoesNotTakeGroupsReadWithIt(): void
    {
        $handler = $this->handler();
        $roleId = $this->createRole($handler, 'Router', []);

        $handler->grantPermissions(
            $this->authedRequest('POST', "/api/roles/{$roleId}/permissions", [
                'permissions' => [CorePermissions::DOCUMENTS_ROUTE],
            ]),
            ['id' => (string) $roleId]
        );

        $handler->revokePermissions(
            $this->authedRequest('DELETE', "/api/roles/{$roleId}/permissions", [
                'permissions' => [CorePermissions::DOCUMENTS_ROUTE],
            ]),
            ['id' => (string) $roleId]
        );

        $held = $this->linkedPermissionIds($roleId);
        $this->assertNotContains($this->permIdFor(CorePermissions::DOCUMENTS_ROUTE), $held);
        $this->assertContains($this->permIdFor(CorePermissions::GROUPS_READ), $held);
    }

    public function testRevokeRemovesOnlyTheNamedGrants(): void
    {
        $handler = $this->handler();
        $roleId = $this->createRole($handler, 'Editor', [$this->permA, $this->permB, $this->permC]);

        $response = $handler->revokePermissions(
            $this->authedRequest('DELETE', "/api/roles/{$roleId}/permissions", ['permissions' => [$this->permB]]),
            ['id' => (string) $roleId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, json_decode($response->getBody(), true)['data']['revoked']);
        $this->assertSame([$this->permA, $this->permC], $this->linkedPermissionIds($roleId));
    }

    public function testRevokingAPermissionTheRoleDoesNotHoldSucceeds(): void
    {
        $handler = $this->handler();
        $roleId = $this->createRole($handler, 'Editor', [$this->permA]);

        $response = $handler->revokePermissions(
            $this->authedRequest('DELETE', "/api/roles/{$roleId}/permissions", ['permissions' => [$this->permB, $this->permC]]),
            ['id' => (string) $roleId]
        );

        $this->assertSame(200, $response->getStatusCode(), 'Revoking what is not held is a success.');
        $this->assertSame(0, json_decode($response->getBody(), true)['data']['revoked']);
        $this->assertSame([$this->permA], $this->linkedPermissionIds($roleId));
    }

    // ── validation ──────────────────────────────────────────────────────────

    public function testMissingPermissionsKeyIs400(): void
    {
        $handler = $this->handler();
        $roleId = $this->createRole($handler, 'Editor', [$this->permA]);

        foreach (['grantPermissions', 'revokePermissions'] as $method) {
            $response = $handler->$method(
                $this->authedRequest('POST', "/api/roles/{$roleId}/permissions", ['nope' => []]),
                ['id' => (string) $roleId]
            );
            $this->assertSame(400, $response->getStatusCode(), "{$method} must reject a body with no permissions key.");
        }
    }

    public function testEmptyPermissionListIsANoOpSuccess(): void
    {
        $handler = $this->handler();
        $roleId = $this->createRole($handler, 'Editor', [$this->permA]);

        $response = $handler->grantPermissions(
            $this->authedRequest('POST', "/api/roles/{$roleId}/permissions", ['permissions' => []]),
            ['id' => (string) $roleId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([$this->permA], $this->linkedPermissionIds($roleId));
    }

    // ── tenant boundary: identical to the endpoints these supplement ────────

    public function testTenantCannotGrantOnAnotherTenantsRole(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $roleId = $this->createRole($handler, 'TenantAOnly', [$this->permA]);

        TenantContext::reset();
        MockRequestFactory::setTestTenant(2);

        $grant = $handler->grantPermissions(
            $this->authedRequest('POST', "/api/roles/{$roleId}/permissions", ['permissions' => [$this->permB]]),
            ['id' => (string) $roleId]
        );
        $revoke = $handler->revokePermissions(
            $this->authedRequest('DELETE', "/api/roles/{$roleId}/permissions", ['permissions' => [$this->permA]]),
            ['id' => (string) $roleId]
        );

        $this->assertSame(404, $grant->getStatusCode(), "Tenant B must not grant on tenant A's role.");
        $this->assertSame(404, $revoke->getStatusCode(), "Tenant B must not revoke on tenant A's role.");
        $this->assertSame([$this->permA], $this->linkedPermissionIds($roleId), "Tenant A's grants are untouched.");
    }

    public function testTenantCannotChangeAGlobalRoleButSystemTenantCan(): void
    {
        // Role 1 is the seeded GLOBAL `admin` — writable only by the system tenant.
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();

        $denied = $handler->grantPermissions(
            $this->authedRequest('POST', '/api/roles/1/permissions', ['permissions' => [$this->permA]]),
            ['id' => '1']
        );
        $this->assertSame(404, $denied->getStatusCode(), 'A tenant must not alter a global base role.');

        TenantContext::reset();
        MockRequestFactory::setTestTenant(0);
        $allowed = $handler->grantPermissions(
            $this->authedRequest('POST', '/api/roles/1/permissions', ['permissions' => [$this->permA]]),
            ['id' => '1']
        );
        $this->assertSame(200, $allowed->getStatusCode(), 'The SYSTEM tenant may alter a global base role.');
    }

    public function testUnknownRoleIs404(): void
    {
        $handler = $this->handler();

        $response = $handler->grantPermissions(
            $this->authedRequest('POST', '/api/roles/424242/permissions', ['permissions' => [$this->permA]]),
            ['id' => '424242']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    // ── audit: the delta must look like the replace it substitutes for ──────

    public function testBothEndpointsEmitTheRoleUpdatedHookThatFeedsTheAuditLog(): void
    {
        // AuditLogger subscribes to `role.updated` and writes the `role.updated`
        // audit action from it. Emitting anything else here would make additive
        // permission changes invisible to every existing audit consumer.
        $events = [];
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnCallback(
            static function (string $event, array $data) use (&$events): array {
                $events[] = $event;
                return $data;
            }
        );
        $hooks->method('dispatchAsync');
        $handler = new RolesApiHandler($this->pdo, $hooks);

        $roleId = $this->createRole($handler, 'Editor', [$this->permA]);
        $events = [];

        $handler->grantPermissions(
            $this->authedRequest('POST', "/api/roles/{$roleId}/permissions", ['permissions' => [$this->permB]]),
            ['id' => (string) $roleId]
        );
        $handler->revokePermissions(
            $this->authedRequest('DELETE', "/api/roles/{$roleId}/permissions", ['permissions' => [$this->permB]]),
            ['id' => (string) $roleId]
        );

        $this->assertSame(
            ['role.updating', 'role.updated', 'role.updating', 'role.updated'],
            $events
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function handler(): RolesApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        return new RolesApiHandler($this->pdo, $hooks);
    }

    /**
     * @param array<int, int|string> $permissions
     */
    private function createRole(RolesApiHandler $handler, string $name, array $permissions): int
    {
        $response = $handler->create($this->authedRequest('POST', '/api/roles', [
            'name' => $name,
            'permissions' => $permissions,
        ]));

        return (int) json_decode($response->getBody(), true)['data']['id'];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function authedRequest(string $method, string $path, ?array $body = null): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['user_id' => 99, 'tenant_id' => 1];

        return $request;
    }

    private function permIdFor(string $name): int
    {
        return (int) $this->query(
            'SELECT id FROM permissions WHERE name = ' . $this->pdo->quote($name)
        )->fetchColumn();
    }

    /**
     * @return array<int, int>
     */
    private function linkedPermissionIds(int $roleId): array
    {
        $stmt = $this->query(
            'SELECT permission_id FROM role_permissions WHERE role_id = ' . $roleId . ' ORDER BY permission_id'
        );

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** PDO::query() returns false on failure; in a fixture that is a broken test, not a result. */
    private function query(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Fixture query failed: ' . $sql);
        }

        return $stmt;
    }
}
