<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\ResourceRoleGrantsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Real-engine tests for the resource-scoped role GRANT write path (WC-712 §3).
 *
 * §2 shipped resolution — `PermissionResolver` can answer "does this profile
 * hold this role AT this record?" — but nothing could WRITE the row it reads.
 * A consumer could therefore ask the platform about authority while still
 * having to store that authority in its own table, which is two sources of
 * truth for one question and strictly worse than keeping one private table.
 * These tests pin the three routes that close that gap.
 *
 * Runs against a genuine SQL engine seeded from the REAL migrations (SQLite in
 * CI; real PostgreSQL when PHPUNIT_PG_DSN is set), because the things most
 * likely to break here are engine-level: the nullable `profile_id` semantics,
 * the two partial unique indexes behind idempotence, and the tenant predicates.
 *
 * What these pin:
 *  - BOTH grant shapes are creatable and distinguishable when listed
 *    (`profile_id` NULL = everyone here, non-null = one profile here);
 *  - a repeat grant is a SUCCESS, not a 409, matching POST /api/users/{id}/memberships;
 *  - an unregistered `resource_type` is rejected at the boundary;
 *  - a `resource_id` that is not the caller's tenant's is rejected, and no row
 *    is written;
 *  - another tenant's private role cannot be attached, and is reported as
 *    not-found so its existence is never disclosed;
 *  - a grant WIDENS authority at a resource and never substitutes for an active
 *    tenant membership.
 */
final class ResourceRoleGrantsApiHandlerRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    /** A plugin-declared type, deliberately NOT the built-in 'ou'. */
    private const TYPE_DOCUMENT = 'testplugin:document';
    private const DOC_ID = 4242;

    private PDO $pdo;
    private Database $db;
    private HookManager $hooks;

    /** The actor: holds roles:manage + roles:read in Tenant A. */
    private int $actorProfileId;

    /** A Tenant A member with no special authority — the grant's beneficiary. */
    private int $memberProfileId;

    private int $ouA;
    private int $ouB;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();

        $this->pdo = $this->makeSchema();
        $this->db = $this->wrapEngine($this->pdo);
        $this->hooks = new HookManager();

        $this->ouA = $this->seedOu('a-engineering', self::TENANT_A);
        $this->ouB = $this->seedOu('b-sales', self::TENANT_B);

        $this->grantPermissionToRole('admin', CorePermissions::ROLES_MANAGE);
        $this->grantPermissionToRole('admin', CorePermissions::ROLES_READ);

        $this->actorProfileId = $this->seedProfile('actor@t1.example', 'admin', self::TENANT_A);
        $this->memberProfileId = $this->seedProfile('member@t1.example', 'viewer', self::TENANT_A);

        TenantContext::setTenantId(self::TENANT_A);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== create: both grant shapes ====================

    /**
     * The everyone-grant: `profile_id` omitted means every member reaching this
     * resource holds the role here. This is what OU assignments always expressed.
     */
    public function testCreatesAnEveryoneGrantAtAnOu(): void
    {
        $response = $this->handler()->create($this->req('POST', '/api/resource-role-grants', [
            'resource_type' => ResourceTypeRegistry::TYPE_OU,
            'resource_id' => $this->ouA,
            'role_id' => $this->roleId('editor'),
        ]));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertTrue($data['created'], 'A first grant must report created=true');
        self::assertNull($data['profile_id'], 'An omitted profile_id must store NULL, not 0');
        self::assertGreaterThan(0, $data['id']);
    }

    /**
     * The profile-grant: this ONE profile holds the role at this resource.
     */
    public function testCreatesAProfileScopedGrant(): void
    {
        $response = $this->handler()->create($this->req('POST', '/api/resource-role-grants', [
            'resource_type' => ResourceTypeRegistry::TYPE_OU,
            'resource_id' => $this->ouA,
            'role_id' => $this->roleId('editor'),
            'profile_id' => $this->memberProfileId,
        ]));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertSame($this->memberProfileId, $data['profile_id']);
    }

    /**
     * The two shapes are DIFFERENT grants at the same resource, not one grant
     * written twice: the partial unique indexes admit both, and listing keeps
     * them apart.
     */
    public function testEveryoneAndProfileGrantsCoexistAndAreDistinguishable(): void
    {
        $roleId = $this->roleId('editor');
        $this->createGrant(ResourceTypeRegistry::TYPE_OU, $this->ouA, $roleId, null);
        $this->createGrant(ResourceTypeRegistry::TYPE_OU, $this->ouA, $roleId, $this->memberProfileId);

        $rows = $this->listGrants(ResourceTypeRegistry::TYPE_OU, $this->ouA);

        self::assertCount(2, $rows);
        $profileIds = array_column($rows, 'profile_id');
        self::assertContains(null, $profileIds, 'The everyone-grant must list with a NULL profile_id');
        self::assertContains($this->memberProfileId, $profileIds, 'The profile-grant must list with its profile_id');
    }

    // ==================== idempotence ====================

    /**
     * A repeat grant is a SUCCESS, mirroring POST /api/users/{id}/memberships:
     * a 409 would force every caller to treat "already true" as an error and
     * hand-roll a read-before-write race.
     */
    public function testRepeatGrantIsIdempotentAndNotAConflict(): void
    {
        $roleId = $this->roleId('editor');
        $first = $this->handler()->create($this->req('POST', '/api/resource-role-grants', [
            'resource_type' => ResourceTypeRegistry::TYPE_OU,
            'resource_id' => $this->ouA,
            'role_id' => $roleId,
        ]));
        self::assertSame(201, $first->getStatusCode());

        $second = $this->handler()->create($this->req('POST', '/api/resource-role-grants', [
            'resource_type' => ResourceTypeRegistry::TYPE_OU,
            'resource_id' => $this->ouA,
            'role_id' => $roleId,
        ]));

        self::assertSame(200, $second->getStatusCode(), 'A repeat grant must be 200, never 409');
        self::assertFalse($this->data($second)['created'], 'A repeat grant must report created=false');
        self::assertSame(
            $this->data($first)['id'],
            $this->data($second)['id'],
            'A repeat grant must name the SAME row, so the caller can revoke it by that id'
        );
        self::assertSame(1, $this->countGrants(), 'A repeat grant must not duplicate the row');
    }

    /**
     * Idempotence must respect the NULL: repeating an everyone-grant must not
     * be satisfied by an existing profile-grant for the same role, or the
     * caller silently gets narrower authority than it asked for.
     */
    public function testEveryoneGrantIsNotSatisfiedByAnExistingProfileGrant(): void
    {
        $roleId = $this->roleId('editor');
        $this->createGrant(ResourceTypeRegistry::TYPE_OU, $this->ouA, $roleId, $this->memberProfileId);

        $response = $this->handler()->create($this->req('POST', '/api/resource-role-grants', [
            'resource_type' => ResourceTypeRegistry::TYPE_OU,
            'resource_id' => $this->ouA,
            'role_id' => $roleId,
        ]));

        self::assertSame(201, $response->getStatusCode(), 'The everyone-grant is a distinct row and must be created');
        self::assertSame(2, $this->countGrants());
    }

    // ==================== boundary validation ====================

    public function testUnregisteredResourceTypeIsRejectedAndNoRowIsWritten(): void
    {
        $response = $this->handler()->create($this->req('POST', '/api/resource-role-grants', [
            'resource_type' => 'not_registered',
            'resource_id' => $this->ouA,
            'role_id' => $this->roleId('editor'),
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->countGrants(), 'An unregistered type must never reach the table');
    }

    /**
     * A plugin declaring `document` is registered as `testplugin:document`, so
     * granting on the BARE slug must fail — otherwise two plugins could collide
     * on one unnamespaced name.
     */
    public function testBarePluginSlugIsRejected(): void
    {
        $response = $this->handler()->create($this->req('POST', '/api/resource-role-grants', [
            'resource_type' => 'document',
            'resource_id' => self::DOC_ID,
            'role_id' => $this->roleId('editor'),
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->countGrants());
    }

    /**
     * `resource_id` carries no foreign key, so nothing but this check stops a
     * grant being addressed at another tenant's record.
     */
    public function testResourceIdFromAnotherTenantIsRejectedAndNoRowIsWritten(): void
    {
        $response = $this->handler()->create($this->req('POST', '/api/resource-role-grants', [
            'resource_type' => ResourceTypeRegistry::TYPE_OU,
            'resource_id' => $this->ouB,
            'role_id' => $this->roleId('editor'),
        ]));

        self::assertSame(404, $response->getStatusCode(), "Another tenant's resource must report not-found");
        self::assertSame(0, $this->countGrants(), 'No cross-tenant grant row may exist after the rejected create');
    }

    public function testUnknownResourceIdIsRejected(): void
    {
        $response = $this->handler()->create($this->req('POST', '/api/resource-role-grants', [
            'resource_type' => ResourceTypeRegistry::TYPE_OU,
            'resource_id' => 987654,
            'role_id' => $this->roleId('editor'),
        ]));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->countGrants());
    }

    /**
     * Another tenant's PRIVATE role must not become attachable through a
     * resource grant, and the refusal must not disclose that the role exists.
     */
    public function testForeignTenantPrivateRoleIsReportedNotFound(): void
    {
        $foreignRoleId = $this->seedTenantRole('tenant-b-private', self::TENANT_B);

        $response = $this->handler()->create($this->req('POST', '/api/resource-role-grants', [
            'resource_type' => ResourceTypeRegistry::TYPE_OU,
            'resource_id' => $this->ouA,
            'role_id' => $foreignRoleId,
        ]));

        self::assertSame(404, $response->getStatusCode(), 'A foreign role must be not-found, never forbidden');
        self::assertStringNotContainsString(
            'tenant-b-private',
            (string) $response->getBody(),
            'The refusal must not leak the foreign role name'
        );
        self::assertSame(0, $this->countGrants());
    }

    /** A GLOBAL role (NULL tenant_id) stays grantable — that is the shared vocabulary. */
    public function testGlobalRoleIsGrantable(): void
    {
        $response = $this->handler()->create($this->req('POST', '/api/resource-role-grants', [
            'resource_type' => ResourceTypeRegistry::TYPE_OU,
            'resource_id' => $this->ouA,
            'role_id' => $this->roleId('viewer'),
        ]));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * The named profile must belong to the caller's tenant. Without this the
     * table accumulates grants for people who are not in the tenant at all —
     * rows that resolution can never honour and an operator cannot explain.
     */
    public function testProfileFromAnotherTenantIsRejected(): void
    {
        $foreignProfileId = $this->seedProfile('outsider@t2.example', 'viewer', self::TENANT_B);

        $response = $this->handler()->create($this->req('POST', '/api/resource-role-grants', [
            'resource_type' => ResourceTypeRegistry::TYPE_OU,
            'resource_id' => $this->ouA,
            'role_id' => $this->roleId('editor'),
            'profile_id' => $foreignProfileId,
        ]));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->countGrants());
    }

    // ==================== plugin resource types ====================

    /**
     * Core can validate `ou` itself, but it cannot know which table a plugin
     * type lives in. The owner answers a filter hook; with NO owner answering,
     * the grant is REFUSED rather than written against an unvalidated integer.
     */
    public function testPluginResourceTypeIsRefusedWhenNoOwnerVouchesForTheId(): void
    {
        $response = $this->handler()->create($this->req('POST', '/api/resource-role-grants', [
            'resource_type' => self::TYPE_DOCUMENT,
            'resource_id' => self::DOC_ID,
            'role_id' => $this->roleId('editor'),
        ]));

        self::assertSame(404, $response->getStatusCode(), 'An unvouched plugin resource id must fail CLOSED');
        self::assertSame(0, $this->countGrants());
    }

    public function testPluginResourceTypeIsAcceptedWhenTheOwnerVouchesForTheId(): void
    {
        $this->vouchForDocument(self::DOC_ID, self::TENANT_A);

        $response = $this->handler()->create($this->req('POST', '/api/resource-role-grants', [
            'resource_type' => self::TYPE_DOCUMENT,
            'resource_id' => self::DOC_ID,
            'role_id' => $this->roleId('editor'),
        ]));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * The owner is asked about the CALLER's tenant, so vouching for a record in
     * Tenant B cannot be replayed to authorise a grant made from Tenant A.
     */
    public function testOwnerVouchingForAnotherTenantsRecordDoesNotAuthoriseTheGrant(): void
    {
        $this->vouchForDocument(self::DOC_ID, self::TENANT_B);

        $response = $this->handler()->create($this->req('POST', '/api/resource-role-grants', [
            'resource_type' => self::TYPE_DOCUMENT,
            'resource_id' => self::DOC_ID,
            'role_id' => $this->roleId('editor'),
        ]));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->countGrants());
    }

    // ==================== list ====================

    public function testListIsTenantScoped(): void
    {
        $roleId = $this->roleId('editor');
        $this->createGrant(ResourceTypeRegistry::TYPE_OU, $this->ouA, $roleId, null);

        // A grant written directly for Tenant B at the SAME resource id, which is
        // the shape a leak would take: same type, same id, different tenant.
        $this->pdo->prepare(
            'INSERT INTO resource_role_assignments
                 (tenant_id, resource_type, resource_id, role_id, profile_id, created_at)
             VALUES (?, ?, ?, ?, NULL, NOW())'
        )->execute([self::TENANT_B, ResourceTypeRegistry::TYPE_OU, $this->ouA, $this->roleId('viewer')]);

        $rows = $this->listGrants(ResourceTypeRegistry::TYPE_OU, $this->ouA);

        self::assertCount(1, $rows, "Tenant B's row at the same resource id must not appear");
        self::assertSame($roleId, $rows[0]['role_id']);
    }

    public function testListRequiresResourceTypeAndId(): void
    {
        $response = $this->handler()->list($this->req('GET', '/api/resource-role-grants'));

        self::assertSame(422, $response->getStatusCode());
    }

    // ==================== revoke ====================

    public function testRevokeByIdRemovesOnlyThatGrant(): void
    {
        $roleId = $this->roleId('editor');
        $everyoneId = $this->createGrant(ResourceTypeRegistry::TYPE_OU, $this->ouA, $roleId, null);
        $this->createGrant(ResourceTypeRegistry::TYPE_OU, $this->ouA, $roleId, $this->memberProfileId);

        $response = $this->handler()->revoke(
            $this->req('DELETE', '/api/resource-role-grants/' . $everyoneId),
            ['id' => (string) $everyoneId]
        );

        self::assertSame(204, $response->getStatusCode());
        $rows = $this->listGrants(ResourceTypeRegistry::TYPE_OU, $this->ouA);
        self::assertCount(1, $rows, 'Revoking the everyone-grant must leave the profile-grant intact');
        self::assertSame($this->memberProfileId, $rows[0]['profile_id']);
    }

    public function testRevokingAnotherTenantsGrantIsNotFoundAndTheRowSurvives(): void
    {
        $this->pdo->prepare(
            'INSERT INTO resource_role_assignments
                 (tenant_id, resource_type, resource_id, role_id, profile_id, created_at)
             VALUES (?, ?, ?, ?, NULL, NOW())'
        )->execute([self::TENANT_B, ResourceTypeRegistry::TYPE_OU, $this->ouB, $this->roleId('viewer')]);
        $foreignId = (int) $this->pdo->lastInsertId();

        $response = $this->handler()->revoke(
            $this->req('DELETE', '/api/resource-role-grants/' . $foreignId),
            ['id' => (string) $foreignId]
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->countGrants(), "Tenant B's grant must survive a cross-tenant revoke");
    }

    public function testRevokingAnUnknownGrantIsNotFound(): void
    {
        $response = $this->handler()->revoke(
            $this->req('DELETE', '/api/resource-role-grants/987654'),
            ['id' => '987654']
        );

        self::assertSame(404, $response->getStatusCode());
    }

    // ==================== the design rule: never a substitute for membership ====================

    /**
     * The rule the whole model rests on: a resource grant WIDENS authority at
     * one resource for someone already in the tenant. It must never be a back
     * door for someone with no ACTIVE membership there.
     *
     * The membership is seeded SUSPENDED, so the write-time "is this profile in
     * the tenant?" check passes and the read-time active-membership gate is the
     * only thing standing — which is exactly the condition under test.
     */
    public function testGrantDoesNotConferAuthorityWithoutAnActiveMembership(): void
    {
        $suspended = $this->seedProfile('suspended@t1.example', 'viewer', self::TENANT_A);
        $this->pdo->prepare("UPDATE memberships SET status = 'suspended' WHERE profile_id = ? AND tenant_id = ?")
            ->execute([$suspended, self::TENANT_A]);

        $this->grantPermissionToRole('editor', 'posts:write');
        $this->createGrant(ResourceTypeRegistry::TYPE_OU, $this->ouA, $this->roleId('editor'), $suspended);

        RoleChecker::clearCache();

        self::assertFalse(
            $this->checker()->hasPermissionForProfile(
                $suspended,
                'posts:write',
                self::TENANT_A,
                ResourceTypeRegistry::TYPE_OU,
                $this->ouA
            ),
            'A resource grant must never substitute for an active tenant membership'
        );
    }

    /**
     * The positive control for the rule above: the SAME grant, for a profile
     * that does hold an active membership, does confer the permission — so the
     * test above is proving the membership gate, not a broken write path.
     */
    public function testGrantConfersAuthorityForAnActiveMember(): void
    {
        $this->grantPermissionToRole('editor', 'posts:write');

        self::assertFalse(
            $this->checker()->hasPermissionForProfile(
                $this->memberProfileId,
                'posts:write',
                self::TENANT_A,
                ResourceTypeRegistry::TYPE_OU,
                $this->ouA
            ),
            'Precondition: the member must not already hold the permission'
        );

        $this->createGrant(ResourceTypeRegistry::TYPE_OU, $this->ouA, $this->roleId('editor'), $this->memberProfileId);
        RoleChecker::clearCache();

        self::assertTrue(
            $this->checker()->hasPermissionForProfile(
                $this->memberProfileId,
                'posts:write',
                self::TENANT_A,
                ResourceTypeRegistry::TYPE_OU,
                $this->ouA
            ),
            'A grant written through the API must be readable by the resolver — the whole point of the change'
        );
    }

    // ==================== gating ====================

    public function testWriteWithoutRolesManageIsForbidden(): void
    {
        $response = $this->handler()->create($this->req(
            'POST',
            '/api/resource-role-grants',
            [
                'resource_type' => ResourceTypeRegistry::TYPE_OU,
                'resource_id' => $this->ouA,
                'role_id' => $this->roleId('editor'),
            ],
            $this->memberProfileId
        ));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->countGrants());
    }

    public function testRevokeWithoutRolesManageIsForbiddenAndTheRowSurvives(): void
    {
        $grantId = $this->createGrant(ResourceTypeRegistry::TYPE_OU, $this->ouA, $this->roleId('editor'), null);

        $response = $this->handler()->revoke(
            $this->req('DELETE', '/api/resource-role-grants/' . $grantId, null, $this->memberProfileId),
            ['id' => (string) $grantId]
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(1, $this->countGrants());
    }

    public function testListWithoutRolesReadIsForbidden(): void
    {
        $response = $this->handler()->list($this->req(
            'GET',
            '/api/resource-role-grants?resource_type=ou&resource_id=' . $this->ouA,
            null,
            $this->memberProfileId
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    // ==================== helpers ====================

    private function handler(): ResourceRoleGrantsApiHandler
    {
        return new ResourceRoleGrantsApiHandler(
            $this->pdo,
            new ResourceRoleAssignmentRepository($this->pdo, $this->resourceTypes()),
            $this->resourceTypes(),
            $this->checker(),
            $this->hooks
        );
    }

    /**
     * Register a listener standing in for the plugin that owns `document`,
     * vouching for exactly one (id, tenant) pair.
     */
    private function vouchForDocument(int $documentId, int $tenantId): void
    {
        $this->hooks->listen(
            'rbac.resource_grant.verify_resource',
            static function (array $data) use ($documentId, $tenantId): array {
                if ($data['resource_type'] === self::TYPE_DOCUMENT
                    && $data['resource_id'] === $documentId
                    && $data['tenant_id'] === $tenantId
                ) {
                    $data['exists'] = true;
                }

                return $data;
            }
        );
    }

    /** Create a grant through the HANDLER and return its id. */
    private function createGrant(string $resourceType, int $resourceId, int $roleId, ?int $profileId): int
    {
        $body = [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'role_id' => $roleId,
        ];
        if ($profileId !== null) {
            $body['profile_id'] = $profileId;
        }

        $response = $this->handler()->create($this->req('POST', '/api/resource-role-grants', $body));
        self::assertContains(
            $response->getStatusCode(),
            [200, 201],
            'Fixture grant failed: ' . (string) $response->getBody()
        );

        return $this->data($response)['id'];
    }

    /**
     * @return list<array{id: int, role_id: int, profile_id: int|null}>
     */
    private function listGrants(string $resourceType, int $resourceId): array
    {
        $response = $this->handler()->list($this->req(
            'GET',
            '/api/resource-role-grants?resource_type=' . rawurlencode($resourceType) . '&resource_id=' . $resourceId
        ));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded, 'Response body must be JSON');
        self::assertArrayHasKey('data', $decoded);

        return $decoded['data'];
    }

    private function countGrants(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM resource_role_assignments');
        self::assertInstanceOf(\PDOStatement::class, $stmt);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function req(string $method, string $path, ?array $body = null, ?int $actor = null): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) [
            'profile_id' => $actor ?? $this->actorProfileId,
            'active_tenant_id' => TenantContext::getTenantId(),
        ];

        return $request;
    }

    private function checker(): RoleChecker
    {
        return new RoleChecker($this->db, $this->permissionRegistry());
    }

    private function permissionRegistry(): PermissionRegistry
    {
        $registry = new PermissionRegistry();
        $registry->register('test', ['posts:write', 'posts:read']);

        return $registry;
    }

    /**
     * A registry with one plugin-declared type. The plugin declares the BARE
     * slug and the registry namespaces it, so the canonical type is
     * `testplugin:document` — which is what {@see self::TYPE_DOCUMENT} holds.
     */
    private function resourceTypes(): ResourceTypeRegistry
    {
        $types = new ResourceTypeRegistry();
        $types->register('TestPlugin', ['document']);

        return $types;
    }

    private function grantPermissionToRole(string $roleName, string $permission): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')
            ->execute([$permission]);
        $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $stmt->execute([$permission]);
        $permissionId = (int) $stmt->fetchColumn();

        $this->pdo->prepare(
            'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())'
        )->execute([$this->roleId($roleName), $permissionId]);
    }

    private function seedOu(string $name, int $tenantId): int
    {
        $this->pdo->prepare(
            'INSERT INTO organizational_units (tenant_id, parent_id, name, slug, created_at)
             VALUES (?, NULL, ?, ?, NOW())'
        )->execute([$tenantId, $name, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedProfile(string $email, string $roleName, int $tenantId): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, 'x', false, 0, 0, NOW(), NOW())"
        )->execute([explode('@', $email)[0]]);
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, NOW())'
        )->execute([$profileId, $email]);

        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, NULL, 'active', NOW())"
        )->execute([$profileId, $tenantId, $this->roleId($roleName)]);

        return $profileId;
    }

    private function seedTenantRole(string $name, int $tenantId): int
    {
        $this->pdo->prepare('INSERT INTO roles (name, tenant_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$name, $tenantId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function roleId(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ? ORDER BY id LIMIT 1');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    private function wrapEngine(PDO $pdo): Database
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
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, created_at) VALUES
            (3, 'editor', NOW()), (4, 'viewer', NOW())");

        // Roles seeded at EXPLICIT ids, which PostgreSQL's sequence does not
        // notice — a later id-less insert is handed 3 again and dies on
        // roles_pkey. SQLite hides this because its counter reads the table.
        SchemaFromMigrations::syncSequences($pdo);

        return $pdo;
    }
}
