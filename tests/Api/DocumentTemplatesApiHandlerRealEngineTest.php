<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentTemplatesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Database\Database;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for {@see DocumentTemplatesApiHandler} (WC-docdesigner): the
 * server-side RBAC visibility filter (personal=owner, system=all, tenant-gated by
 * required_permission — a caller lacking the tag never receives the row), the
 * publish gate (documents:publish), CRUD, and 404-not-403 for hidden rows.
 *
 * Since migration 117 it also covers the WHERE dimension: a template FILED at
 * an organizational unit reaches only callers with standing there. The headline
 * case is {@see self::testTwoSecretariesHoldingTheSamePermissionSeeDifferentTemplateSets()},
 * which asserts the two callers' effective permission sets are IDENTICAL before
 * asserting their visible template sets differ — otherwise the test would prove
 * only that the policy runs, not that placement is what discriminates.
 */
final class DocumentTemplatesApiHandlerRealEngineTest extends TestCase
{
    private const TENANT = 1;

    // Seeded profiles.
    private const OWNER   = 10; // admin role → read/write/publish (migration 060), NOT the contracts tag
    private const VIEWER  = 11; // read only, no publish, no contracts tag
    private const WRITER  = 12; // read+write, NO publish
    private const MANAGER = 13; // read + documents:use:contracts (the gated tag), no publish

    // Two secretaries holding the SAME role, standing at different units.
    private const DEAN_SECRETARY = 14; // stands at the Faculty
    private const DEPT_SECRETARY = 15; // stands at Department A, beneath it

    // The organizational units. Faculty is the parent of both departments.
    private const OU_FACULTY = 501;
    private const OU_DEPT_A  = 502;
    private const OU_DEPT_B  = 503;

    private const ROLE_SECRETARY = 104;
    private const ROLE_MANAGER   = 103;

    private const CONTRACTS_PERM = 'documents:use:contracts';

    private PDO $pdo;
    private DocumentTemplatesApiHandler $handler;
    private ResourceRoleAssignmentRepository $grants;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapSqlite($this->pdo);
        $this->grants = new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry());
        $this->handler = new DocumentTemplatesApiHandler(
            new DocumentTemplateRepository($this->pdo),
            new DocumentAccessPolicy(),
            new RoleChecker($db, new PermissionRegistry()),
            new OuReachResolver($this->pdo, $this->grants),
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    // ── visibility ──────────────────────────────────────────────────────────

    public function testPersonalTemplateVisibleOnlyToItsCreator(): void
    {
        $id = $this->create(self::OWNER, ['name' => 'Mine', 'data' => ['version' => 2]]);
        self::assertSame(201, $id->getStatusCode(), $id->getBody());

        self::assertCount(1, $this->list(self::OWNER), 'owner sees own personal');
        self::assertCount(0, $this->list(self::VIEWER), 'another user does not see a personal template');
    }

    public function testSystemScopeVisibleToEveryone(): void
    {
        $this->create(self::OWNER, ['name' => 'Starter', 'data' => ['version' => 2], 'scope' => 'system']);
        self::assertCount(1, $this->list(self::VIEWER), 'system-scope templates are visible to all in the tenant');
    }

    public function testTenantScopeGatedByRequiredPermission(): void
    {
        // Owner (has publish) creates a tenant-wide template gated on the contracts tag.
        $res = $this->create(self::OWNER, [
            'name' => 'Contract', 'data' => ['version' => 2],
            'scope' => 'tenant', 'required_permission' => self::CONTRACTS_PERM,
        ]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());

        // The manager holds the tag → sees it; the viewer does not → never receives it.
        self::assertCount(1, $this->list(self::MANAGER), 'a holder of the required permission sees the gated template');
        self::assertCount(0, $this->list(self::VIEWER), 'a technician without the tag never receives the gated template');
    }

    public function testHiddenTemplateShowReturns404NotForbidden(): void
    {
        $id = $this->decodeId($this->create(self::OWNER, [
            'name' => 'Contract', 'data' => ['version' => 2],
            'scope' => 'tenant', 'required_permission' => self::CONTRACTS_PERM,
        ]));

        $res = $this->show(self::VIEWER, $id);
        self::assertSame(404, $res->getStatusCode(), 'a gated row must 404 (not 403) to a caller who may not see it');
    }

    // ── publish gate ──────────────────────────────────────────────────────────

    public function testPublishingSharedScopeRequiresPublishPermission(): void
    {
        // WRITER has documents:write (route) but NOT documents:publish → 403 on a shared scope.
        $res = $this->create(self::WRITER, ['name' => 'Shared', 'data' => ['version' => 2], 'scope' => 'tenant']);
        self::assertSame(403, $res->getStatusCode());

        // Personal scope is fine without publish.
        self::assertSame(201, $this->create(self::WRITER, ['name' => 'Mine', 'data' => ['version' => 2]])->getStatusCode());
    }

    public function testUpdatingAPersonalTemplateToSharedNeedsPublish(): void
    {
        $id = $this->decodeId($this->create(self::WRITER, ['name' => 'Mine', 'data' => ['version' => 2]]));
        $res = $this->patch(self::WRITER, $id, ['scope' => 'tenant']);
        self::assertSame(403, $res->getStatusCode(), 'promoting to a shared scope is a publish action');
    }

    // ── CRUD + validation ─────────────────────────────────────────────────────

    public function testCreateValidatesNameAndData(): void
    {
        self::assertSame(422, $this->create(self::OWNER, ['name' => '', 'data' => ['v' => 2]])->getStatusCode());
        self::assertSame(422, $this->create(self::OWNER, ['name' => 'x', 'data' => []])->getStatusCode());
        self::assertSame(422, $this->create(self::OWNER, ['name' => 'x', 'data' => ['v' => 2], 'scope' => 'nope'])->getStatusCode());
    }

    public function testOwnerUpdatesAndDeletes(): void
    {
        $id = $this->decodeId($this->create(self::OWNER, ['name' => 'A', 'data' => ['version' => 2]]));
        self::assertSame(200, $this->patch(self::OWNER, $id, ['name' => 'A2'])->getStatusCode());
        self::assertSame(204, $this->delete(self::OWNER, $id)->getStatusCode());
        self::assertSame(404, $this->show(self::OWNER, $id)->getStatusCode());
    }

    // ── the WHERE dimension: reach (migration 117) ───────────────────────────

    /**
     * THE REQUIREMENT, asserted directly: *a secretary for a dean might have
     * access to templates and design blocks more than a secretary of a
     * department head*.
     *
     * The first assertion is the one that makes the rest mean anything. Both
     * secretaries hold the SAME role and therefore the SAME effective permission
     * set, so nothing about WHAT KIND of person they are can separate them — if
     * their visible sets then differ, placement is the only thing that could
     * have done it.
     */
    public function testTwoSecretariesHoldingTheSamePermissionSeeDifferentTemplateSets(): void
    {
        $checker = new RoleChecker($this->wrapSqlite($this->pdo), new PermissionRegistry());
        $deanPerms = $checker->getEffectivePermissionsForProfile(self::DEAN_SECRETARY, self::TENANT);
        $deptPerms = $checker->getEffectivePermissionsForProfile(self::DEPT_SECRETARY, self::TENANT);
        sort($deanPerms);
        sort($deptPerms);
        self::assertSame(
            $deanPerms,
            $deptPerms,
            'the two secretaries must hold identical permissions, or this test proves nothing'
        );
        self::assertContains('documents:write', $deanPerms, 'both must actually hold the shared permission');

        // The admin (who has documents:publish) files one template per unit.
        $this->place('Faculty letterhead', self::OU_FACULTY);
        $this->place('Civil Eng form', self::OU_DEPT_A);
        $this->place('Materials form', self::OU_DEPT_B);

        $dean = $this->visibleNames(self::DEAN_SECRETARY);
        $dept = $this->visibleNames(self::DEPT_SECRETARY);

        self::assertSame(
            ['Civil Eng form', 'Faculty letterhead', 'Materials form'],
            $dean,
            "the dean's secretary stands at the faculty and reaches everything beneath it"
        );
        self::assertSame(
            ['Civil Eng form'],
            $dept,
            'a department head\'s secretary reaches only their own department'
        );

        // Spelled out as the relation the requirement actually states, so a
        // future change that equalises the two sets fails HERE with the reason.
        self::assertNotSame($dean, $dept, 'the two secretaries must not see the same set');
        self::assertSame(
            $dept,
            array_values(array_intersect($dean, $dept)),
            "the department secretary's set must be a strict subset of the dean's secretary's"
        );
        self::assertGreaterThan(count($dept), count($dean));
    }

    /**
     * The other direction, and the reason placement is not simply an ownership
     * column: reaching a unit is not the same as holding authority there.
     *
     * The dean's secretary reaches Civil Engineering (it is beneath her faculty)
     * and still does not see its contracts template, because she does not hold
     * the tag. The department secretary does see it — not from a tenant-wide
     * grant, which would have shown her every other unit's contracts too, but
     * from ONE `resource_role_assignments` row placing a role at that unit.
     */
    public function testAPermissionHeldOnlyAtOneUnitDoesNotFollowTheCallerElsewhere(): void
    {
        $this->place('Civil contracts', self::OU_DEPT_A, self::CONTRACTS_PERM);
        $this->place('Materials contracts', self::OU_DEPT_B, self::CONTRACTS_PERM);

        self::assertSame([], $this->visibleNames(self::DEPT_SECRETARY), 'no tag anywhere yet');
        self::assertSame(
            [],
            $this->visibleNames(self::DEAN_SECRETARY),
            'reaching a unit is not holding authority in it'
        );

        // ROLE_MANAGER carries the contracts tag. Granted at Civil Engineering
        // ONLY, addressed at this one profile.
        $this->grants->grant(
            self::TENANT,
            ResourceTypeRegistry::TYPE_OU,
            self::OU_DEPT_A,
            self::ROLE_MANAGER,
            self::DEPT_SECRETARY
        );
        RoleChecker::clearCache();

        self::assertSame(
            ['Civil contracts'],
            $this->visibleNames(self::DEPT_SECRETARY),
            'the grant unlocks the tag AT that unit and nowhere else'
        );
        self::assertSame(
            [],
            $this->visibleNames(self::DEAN_SECRETARY),
            'the grant names one profile, so it lends nothing to anybody else'
        );
    }

    /**
     * "The dean's secretary also covers Materials Science" — the fact that
     * cannot be derived from the tree, and the reason the grant table is the
     * access decision rather than the placement column.
     *
     * No membership is created and no unit is reparented; one row is written.
     */
    public function testAGrantAtAUnitExtendsReachWithoutAMembershipThere(): void
    {
        $this->place('Materials form', self::OU_DEPT_B);

        self::assertSame(
            [],
            $this->visibleNames(self::DEPT_SECRETARY),
            'a sibling department is not beneath this secretary'
        );

        $this->grants->grant(
            self::TENANT,
            ResourceTypeRegistry::TYPE_OU,
            self::OU_DEPT_B,
            self::ROLE_SECRETARY,
            self::DEPT_SECRETARY
        );
        RoleChecker::clearCache();

        self::assertSame(
            ['Materials form'],
            $this->visibleNames(self::DEPT_SECRETARY),
            'a role granted at a unit gives standing there'
        );
    }

    /**
     * An EVERYONE-grant (`profile_id IS NULL`) confers no standing.
     *
     * Migration 088 defines it as "everyone WITH ACCESS to this resource gets
     * role R here" — it modifies what already-reachable people may do and is not
     * itself access. Reading one as standing would hand the whole tenant
     * authority at any unit carrying a single such row.
     */
    public function testAnEveryoneGrantAtAUnitConfersNoStanding(): void
    {
        $this->place('Materials form', self::OU_DEPT_B);

        $this->grants->grant(
            self::TENANT,
            ResourceTypeRegistry::TYPE_OU,
            self::OU_DEPT_B,
            self::ROLE_SECRETARY,
            null
        );
        RoleChecker::clearCache();

        self::assertSame(
            [],
            $this->visibleNames(self::DEPT_SECRETARY),
            'an everyone-grant is not a grant of access'
        );
    }

    /**
     * The author is always within reach of their own row.
     *
     * Without this, publishing was a one-way door: the admin who files a
     * template typically holds no membership OU, so their reach is empty and the
     * row they just created disappears from their own list — as a 404, with
     * nothing on screen to say why.
     */
    public function testTheAuthorOfAPlacedRowStillSeesIt(): void
    {
        // OWNER (the admin) holds no membership OU at all, so reaches nothing.
        $id = $this->decodeId($this->place('Faculty letterhead', self::OU_FACULTY));

        self::assertSame(
            ['Faculty letterhead'],
            $this->visibleNames(self::OWNER),
            'the author of a placed row must not lose it'
        );
        self::assertSame(200, $this->show(self::OWNER, $id)->getStatusCode());

        // It is a statement about reach, not a hole in the permission gate.
        $gated = $this->decodeId($this->place('Gated', self::OU_DEPT_A, self::CONTRACTS_PERM));
        self::assertSame(
            404,
            $this->show(self::OWNER, $gated)->getStatusCode(),
            'authorship waives placement, never required_permission'
        );
    }

    /**
     * The migration is a pure addition: an UNPLACED row behaves exactly as it
     * did before 116, so no existing template changes audience.
     */
    public function testAnUnplacedTemplateStaysVisibleToEveryone(): void
    {
        $res = $this->create(self::OWNER, [
            'name' => 'Tenant-wide', 'data' => ['version' => 2], 'scope' => 'tenant',
        ]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());

        self::assertSame(['Tenant-wide'], $this->visibleNames(self::DEAN_SECRETARY));
        self::assertSame(['Tenant-wide'], $this->visibleNames(self::DEPT_SECRETARY));
        self::assertSame(['Tenant-wide'], $this->visibleNames(self::VIEWER), 'including a caller in no unit at all');
    }

    /** A caller with no unit and no grant reaches nothing, so placed rows are hidden. */
    public function testACallerWithNoStandingReachesNothing(): void
    {
        $this->place('Faculty letterhead', self::OU_FACULTY);

        self::assertSame(
            [],
            $this->visibleNames(self::VIEWER),
            'VIEWER holds no membership OU, so reach is empty and placed rows fail closed'
        );
    }

    /** Filing a row in the organisation is a publish action, not an ordinary write. */
    public function testPlacingATemplateRequiresPublishPermission(): void
    {
        // WRITER has documents:write but NOT documents:publish.
        $res = $this->create(self::WRITER, [
            'name' => 'Mine', 'data' => ['version' => 2], 'owner_ou_id' => self::OU_DEPT_A,
        ]);
        self::assertSame(403, $res->getStatusCode(), 'placement is a publish action even on a personal row');

        $id = $this->decodeId($this->create(self::WRITER, ['name' => 'Mine', 'data' => ['version' => 2]]));
        self::assertSame(
            403,
            $this->patch(self::WRITER, $id, ['owner_ou_id' => self::OU_DEPT_A])->getStatusCode(),
            'filing an existing row is a publish action too'
        );
    }

    /** A unit that is not this tenant's is refused explicitly, not filed and ignored. */
    public function testPlacementAtAUnitOutsideTheTenantIsRefused(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'b', 'b')");
        $this->pdo->exec(
            "INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at)
             VALUES (601, 2, NULL, 'Other Faculty', 'other', datetime('now'))"
        );

        $res = $this->create(self::OWNER, [
            'name' => 'Sneaky', 'data' => ['version' => 2], 'scope' => 'tenant', 'owner_ou_id' => 601,
        ]);
        self::assertSame(422, $res->getStatusCode(), $res->getBody());

        $res = $this->create(self::OWNER, [
            'name' => 'Bogus', 'data' => ['version' => 2], 'scope' => 'tenant', 'owner_ou_id' => 'not-a-number',
        ]);
        self::assertSame(422, $res->getStatusCode(), $res->getBody());
    }

    /** Placement round-trips, and null un-files a row. */
    public function testPlacementIsPersistedAndCanBeCleared(): void
    {
        $id = $this->decodeId($this->place('Faculty letterhead', self::OU_FACULTY));
        $row = json_decode($this->show(self::OWNER, $id)->getBody(), true);
        self::assertSame(self::OU_FACULTY, $row['data']['owner_ou_id']);

        $res = $this->patch(self::OWNER, $id, ['owner_ou_id' => null]);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertNull(json_decode($res->getBody(), true)['data']['owner_ou_id']);
        self::assertSame(
            ['Faculty letterhead'],
            $this->visibleNames(self::VIEWER),
            'un-filing returns the row to tenant-wide'
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * File a tenant-scoped template at a unit, as the admin (who holds publish).
     */
    private function place(string $name, int $ouId, ?string $requiredPermission = null): \Whity\Sdk\Http\Response
    {
        $body = [
            'name' => $name,
            'data' => ['version' => 2],
            'scope' => 'tenant',
            'owner_ou_id' => $ouId,
        ];
        if ($requiredPermission !== null) {
            $body['required_permission'] = $requiredPermission;
        }

        $res = $this->create(self::OWNER, $body);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());

        return $res;
    }

    /**
     * The names of the templates this caller actually receives, sorted so the
     * assertion is about the SET rather than about `updated_at` ordering.
     *
     * @return list<string>
     */
    private function visibleNames(int $userId): array
    {
        $names = array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->list($userId)
        );
        sort($names);

        return array_values($names);
    }

    private function actAs(int $userId): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $req = new Request('GET', '/api/document-templates', [], '');
        $req->user = (object) ['profile_id' => $userId, 'active_tenant_id' => self::TENANT];
        return $req;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function reqWithBody(int $userId, array $body): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $req = new Request('POST', '/api/document-templates', [], (string) json_encode($body));
        $req->user = (object) ['profile_id' => $userId, 'active_tenant_id' => self::TENANT];
        return $req;
    }

    /** @param array<string, mixed> $body */
    private function create(int $userId, array $body): \Whity\Sdk\Http\Response
    {
        return $this->handler->create($this->reqWithBody($userId, $body));
    }

    /** @return list<array<string,mixed>> */
    private function list(int $userId): array
    {
        $res = $this->handler->list($this->actAs($userId));
        $d = json_decode($res->getBody(), true);
        self::assertIsArray($d);
        return $d['data'] ?? [];
    }

    private function show(int $userId, int $id): \Whity\Sdk\Http\Response
    {
        return $this->handler->show($this->actAs($userId), ['id' => (string) $id]);
    }

    /** @param array<string, mixed> $body */
    private function patch(int $userId, int $id, array $body): \Whity\Sdk\Http\Response
    {
        return $this->handler->update($this->reqWithBody($userId, $body), ['id' => (string) $id]);
    }

    private function delete(int $userId, int $id): \Whity\Sdk\Http\Response
    {
        return $this->handler->delete($this->actAs($userId), ['id' => (string) $id]);
    }

    private function decodeId(\Whity\Sdk\Http\Response $res): int
    {
        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        $d = json_decode($res->getBody(), true);
        return (int) $d['data']['id'];
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");

        // admin role (1) is seeded + granted documents:* by migration 060. Custom
        // tenant roles: viewer (read), writer (read+write), manager (read+contracts tag).
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'viewer', '', 1, datetime('now')),
            (102, 'writer', '', 1, datetime('now')),
            (103, 'manager', '', 1, datetime('now')),
            (104, 'secretary', '', 1, datetime('now'))");

        $this->grant($pdo, 101, 'documents:read');
        $this->grant($pdo, 102, 'documents:read');
        $this->grant($pdo, 102, 'documents:write');
        $this->grant($pdo, 103, 'documents:read');
        $this->grant($pdo, 103, self::CONTRACTS_PERM); // the gated tag

        // The two secretaries share ONE role, so their permission sets are
        // identical by construction and cannot be what tells them apart.
        $this->grant($pdo, 104, 'documents:read');
        $this->grant($pdo, 104, 'documents:write');

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'owner',   'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'viewer',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'writer',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (13, 'manager', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (14, 'dean secretary', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (15, 'dept secretary', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        // Faculty of Engineering, with two departments beneath it. The dean's
        // secretary stands at the faculty, a department head's secretary at one
        // department — which is the whole of the difference between them.
        $pdo->exec("INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at) VALUES
            (501, 1, NULL, 'Faculty of Engineering', 'faculty-eng',  datetime('now')),
            (502, 1, 501,  'Civil Engineering',      'civil-eng',    datetime('now')),
            (503, 1, 501,  'Materials Science',      'materials',    datetime('now'))");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 1,   'active', datetime('now')),
                (1001, 11, 1, 101, 'active', datetime('now')),
                (1002, 12, 1, 102, 'active', datetime('now')),
                (1003, 13, 1, 103, 'active', datetime('now'))
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                (1004, 14, 1, 104, 501, true, 'active', datetime('now')),
                (1005, 15, 1, 104, 502, true, 'active', datetime('now'))
        ");
        return $pdo;
    }

    private function grant(PDO $pdo, int $roleId, string $permission): void
    {
        $pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')
            ->execute([$permission, '']);
        $sel = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $sel->execute([$permission]);
        $pid = (int) $sel->fetchColumn();
        $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, $pid]);
    }

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();
        return $db;
    }
}
