<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Tests\Support\TaxonomyTestSeed;
use Whity\Api\RolesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\RecordSectionResolver;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Per-REGION authorization on the role record (#910), against a real engine.
 *
 * The operator's requirement is that "some parts have permissions, not always
 * everything is allowed", and the roles record is where the two questions it is
 * about are genuinely different: **who may see what a role grants**
 * (`permissions:read`) and **who may change it** (`roles:manage`). A caller can
 * hold either without the other, and the page must render three distinct
 * outcomes for one region.
 *
 * WHAT IS ASSERTED HERE THAT A CLIENT TEST CANNOT BE
 * --------------------------------------------------
 * That a hidden region is absent from the **payload**. Every DOM assertion in
 * the world is satisfied by a `display:none`, and a `display:none` still hands
 * the viewer the rows in the network tab. The properties below are about the
 * response body:
 *
 *  1. a caller without `permissions:read` receives NO `sections.permissions` key
 *     and NO `permissions` array — the same branch withholds both;
 *  2. that caller cannot recover the rows from the region's own route either;
 *  3. a read-only region is REPORTED, with a code, an audience-safe sentence and
 *     — only for a caller entitled to it — the permission slug that refused;
 *  4. the gate is ENFORCED on write, refusing rather than silently dropping,
 *     because a rendering decision is not a control.
 *
 * Real engine rather than mocked PDO because the whole chain is real: RoleChecker
 * resolving a membership's role through `role_permissions`, and the handler's own
 * `roleManageableByTenant()` ownership rule beside it.
 */
final class RoleRecordSectionsRealEngineTest extends TestCase
{
    private const TENANT = 1;

    /** Holds roles:write + roles:manage + permissions:read — everything editable. */
    private const PROFILE_FULL = 10;

    /** Holds roles:write + permissions:read, but NOT roles:manage — read-only region. */
    private const PROFILE_NO_MANAGE = 11;

    /** Holds roles:write + roles:manage, but NOT permissions:read — hidden region. */
    private const PROFILE_NO_CATALOGUE = 12;

    /** The tenant's OWN role, so `manageable` is true and only the slugs vary. */
    private const ROLE_ID = 300;

    private PDO $pdo;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make(true);

        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");

        // Three administrative roles differing ONLY in which of the two region
        // slugs they hold, so every difference below is attributable.
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'role-admin-full',      '', 1, datetime('now')),
            (102, 'role-admin-no-manage', '', 1, datetime('now')),
            (103, 'role-admin-no-cat',    '', 1, datetime('now'))");

        $this->grant(101, CorePermissions::ROLES_WRITE);
        $this->grant(101, CorePermissions::ROLES_MANAGE);
        $this->grant(101, CorePermissions::PERMISSIONS_READ);

        $this->grant(102, CorePermissions::ROLES_WRITE);
        $this->grant(102, CorePermissions::PERMISSIONS_READ);

        $this->grant(103, CorePermissions::ROLES_WRITE);
        $this->grant(103, CorePermissions::ROLES_MANAGE);

        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'full',       'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'no-manage',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'no-cat',     'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $this->pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 101, 'active', datetime('now')),
                (1001, 11, 1, 102, 'active', datetime('now')),
                (1002, 12, 1, 103, 'active', datetime('now'))
        ");

        // The record under test: the tenant's OWN role, holding one grant.
        $this->pdo->exec(
            "INSERT INTO roles (id, name, description, tenant_id, created_at)
             VALUES (300, 'support', 'Front-line support', 1, datetime('now'))"
        );
        $this->grant(self::ROLE_ID, CorePermissions::USERS_READ);

        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    // ---------------------------------------------------------------- editable

    public function testEveryRegionIsEditableForACallerHoldingBothSlugs(): void
    {
        $role = $this->getRole(self::PROFILE_FULL);

        $this->assertSame('editable', $role['sections']['details']['state']);
        $this->assertNull($role['sections']['details']['denial']);
        $this->assertSame('editable', $role['sections']['permissions']['state']);
        $this->assertNull($role['sections']['permissions']['denial']);

        // The region is visible, so its data travels with it.
        $this->assertArrayHasKey('permissions', $role);
        $this->assertSame(
            [CorePermissions::USERS_READ],
            array_column($role['permissions'], 'name')
        );
    }

    // --------------------------------------------------------------- read-only

    public function testThePermissionsRegionIsReadOnlyWithoutRolesManage(): void
    {
        $role = $this->getRole(self::PROFILE_NO_MANAGE);

        // The details region is untouched — that is the granularity working.
        $this->assertSame('editable', $role['sections']['details']['state']);

        $permissions = $role['sections']['permissions'];
        $this->assertSame('read-only', $permissions['state']);
        $this->assertSame('permission', $permissions['denial']['code']);
        // Audience-safe prose, and the client's i18n fallback: it must not name
        // the slug, which is what `detail` is for.
        $this->assertStringNotContainsString(
            CorePermissions::ROLES_MANAGE,
            $permissions['denial']['reason']
        );

        // Visible, so the role's own grants still travel: a read-only region has
        // something to render, unlike a hidden one.
        $this->assertArrayHasKey('permissions', $role);
    }

    public function testTheDetailNamesTheRefusingSlugForACallerWhoMayReadSlugs(): void
    {
        $role = $this->getRole(self::PROFILE_NO_MANAGE);

        // This caller holds `permissions:read` — the permission that governs
        // seeing permission slugs at all — so the operator-grade half is sent.
        $this->assertStringContainsString(
            CorePermissions::ROLES_MANAGE,
            (string) $role['sections']['permissions']['denial']['detail']
        );
    }

    public function testARecordRefusalIsReportedDistinctlyFromAPermissionRefusal(): void
    {
        // A GLOBAL base role: manageable only by the system tenant (WC-110), so
        // both regions are refused by the RECORD rather than by anything this
        // caller lacks. The two codes exist because the remedies differ — a
        // grant fixes one and cannot fix the other.
        $this->pdo->exec(
            "INSERT INTO roles (id, name, description, tenant_id, created_at)
             VALUES (400, 'global-base', 'Shared', NULL, datetime('now'))"
        );

        $role = $this->getRole(self::PROFILE_FULL, 400);

        $this->assertSame('read-only', $role['sections']['details']['state']);
        $this->assertSame('record', $role['sections']['details']['denial']['code']);
        $this->assertSame('record', $role['sections']['permissions']['denial']['code']);
        // Not fixable by a grant, so there is no slug that would help a reader.
        $this->assertNull($role['sections']['details']['denial']['detail']);
    }

    // ------------------------------------------------------------------ hidden

    public function testAHiddenRegionIsABSENTFromThePayload(): void
    {
        $role = $this->getRole(self::PROFILE_NO_CATALOGUE);

        // No verdict — absence is the only way this contract has of saying
        // "hidden". A `{"state": "hidden"}` entry would disclose the region it
        // was withholding.
        $this->assertArrayNotHasKey('permissions', $role['sections']);
        $this->assertArrayHasKey('details', $role['sections']);

        // And NO DATA. This is the property a client test cannot stand in for:
        // the same branch that withholds the verdict withholds the rows, so
        // there is nothing in the network tab to read.
        $this->assertArrayNotHasKey('permissions', $role);
        $this->assertStringNotContainsString(
            CorePermissions::USERS_READ,
            (string) json_encode($role)
        );
    }

    public function testTheRegionsOwnROUTEIsClosedToTheSameCaller(): void
    {
        // Withholding the region from the record while `GET /roles/{id}/permissions`
        // served the identical rows would be a gate with a bypass one path
        // segment away.
        $response = $this->handler()->getPermissions(
            $this->request('GET', '/api/v1/roles/300/permissions', self::PROFILE_NO_CATALOGUE),
            ['id' => (string) self::ROLE_ID]
        );

        // 404, not 403, for WC-110's reason: a distinct 403 would itself say
        // "this role has permissions you may not see".
        $this->assertSame(404, $response->getStatusCode());
    }

    // ----------------------------------------------------------- enforcement

    public function testAWriteToAReadOnlyRegionIsREFUSEDRatherThanDropped(): void
    {
        $response = $this->handler()->update(
            $this->request(
                'PATCH',
                '/api/v1/roles/300',
                self::PROFILE_NO_MANAGE,
                ['permissions' => [1]]
            ),
            ['id' => (string) self::ROLE_ID]
        );

        // Refused, deliberately, and #910 asks for the choice to be STATED: a
        // silently dropped field returns 200 to a request that did not do what
        // it said, and the operator's next move is to wonder whether it worked.
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('permissions', (string) $response->getBody());
    }

    public function testTheRefusalAppliesBEFOREAnyPartOfAMixedBodyIsWritten(): void
    {
        $this->handler()->update(
            $this->request(
                'PATCH',
                '/api/v1/roles/300',
                self::PROFILE_NO_MANAGE,
                ['name' => 'renamed', 'permissions' => [1]]
            ),
            ['id' => (string) self::ROLE_ID]
        );

        // A partial save is the silent-drop failure wearing a different status
        // code, so the permitted half must not land either.
        $stmt = $this->pdo->prepare('SELECT name FROM roles WHERE id = ?');
        $stmt->execute([self::ROLE_ID]);
        $this->assertSame('support', $stmt->fetchColumn());
    }

    public function testTheADDITIVEPermissionRoutesCarryTheSameGate(): void
    {
        // `POST`/`DELETE /roles/{id}/permissions` exist so a caller can change
        // one grant without sending the whole set. A gate that covered only the
        // full replace would be a gate with a documented way around it.
        $granted = $this->handler()->grantPermissions(
            $this->request(
                'POST',
                '/api/v1/roles/300/permissions',
                self::PROFILE_NO_MANAGE,
                ['permissions' => [CorePermissions::USERS_WRITE]]
            ),
            ['id' => (string) self::ROLE_ID]
        );
        $this->assertSame(403, $granted->getStatusCode());

        $revoked = $this->handler()->revokePermissions(
            $this->request(
                'DELETE',
                '/api/v1/roles/300/permissions',
                self::PROFILE_NO_MANAGE,
                ['permissions' => [CorePermissions::USERS_READ]]
            ),
            ['id' => (string) self::ROLE_ID]
        );
        $this->assertSame(403, $revoked->getStatusCode());
    }

    public function testAPermittedWriteStillGoesThrough(): void
    {
        // The gate must not be a wall. The same caller renaming the role — the
        // region they DO hold — succeeds, which is what makes the refusal above
        // a granular decision rather than a broken endpoint.
        $response = $this->handler()->update(
            $this->request('PATCH', '/api/v1/roles/300', self::PROFILE_NO_MANAGE, ['name' => 'renamed']),
            ['id' => (string) self::ROLE_ID]
        );

        $this->assertSame(200, $response->getStatusCode());
        $stmt = $this->pdo->prepare('SELECT name FROM roles WHERE id = ?');
        $stmt->execute([self::ROLE_ID]);
        $this->assertSame('renamed', $stmt->fetchColumn());
    }

    // ------------------------------------------------------- no resolver wired

    public function testAHostWithNoResolverReportsNoSectionsAndBehavesAsBefore(): void
    {
        // The pre-#910 configuration. Nothing is failing open: with no verdicts
        // there is no finer-grained claim being made, the route gate and
        // `roleManageableByTenant()` still stand, and the CLIENT fails closed on
        // the missing map rather than assuming everything is editable.
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $handler = new RolesApiHandler($this->pdo, $hooks);

        $response = $handler->get(
            $this->request('GET', '/api/v1/roles/300', self::PROFILE_FULL),
            ['id' => (string) self::ROLE_ID]
        );
        $role = json_decode((string) $response->getBody(), true)['data'];

        $this->assertArrayNotHasKey('sections', $role);
        $this->assertArrayHasKey('permissions', $role);
    }

    // ===================================================== helpers

    /**
     * The decoded `data` of `GET /api/roles/{id}` as one profile sees it.
     *
     * @return array<string, mixed>
     */
    private function getRole(int $profileId, int $roleId = self::ROLE_ID): array
    {
        $response = $this->handler()->get(
            $this->request('GET', "/api/v1/roles/{$roleId}", $profileId),
            ['id' => (string) $roleId]
        );
        $this->assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true)['data'];
        return $data;
    }

    private function handler(): RolesApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        return new RolesApiHandler(
            $this->pdo,
            $hooks,
            null,
            new RecordSectionResolver(
                new RoleChecker(TaxonomyTestSeed::wrap($this->pdo), new PermissionRegistry())
            )
        );
    }

    /**
     * A request carrying the acting PROFILE — never a body-supplied one, so a
     * granular gate can never be asked about somebody else.
     *
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $path, int $profileId, ?array $body = null): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) [
            'user_id' => $profileId,
            'profile_id' => $profileId,
            'tenant_id' => self::TENANT,
        ];
        return $request;
    }

    private function grant(int $roleId, string $permission): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')
            ->execute([$permission, '']);
        $sel = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $sel->execute([$permission]);
        $this->pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, (int) $sel->fetchColumn()]);
    }
}
