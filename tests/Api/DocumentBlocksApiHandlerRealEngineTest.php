<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentBlocksApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Database\Database;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for {@see DocumentBlocksApiHandler} (WC-521): mirrors
 * DocumentTemplatesApiHandlerRealEngineTest for the shared visibility/publish-
 * gate/CRUD behaviour, plus the block-specific reference-integrity delete
 * guard (a block that a template still references via a `blockInstance`
 * pointer cannot be deleted — 409, not a silent orphan).
 *
 * Runs on SQLite by default; when PHPUNIT_PG_DSN is set (see
 * Tests\Support\SchemaFromMigrations) the same test class runs against a real
 * PostgreSQL schema, exercising DocumentTemplateRepository::referencesBlock()'s
 * jsonb_path_exists() branch instead of the SQLite fetch+scan fallback.
 *
 * Since migration 117 it also mirrors the WHERE dimension. Blocks are named in
 * the requirement alongside templates — *a secretary for a dean might have
 * access to templates AND DESIGN BLOCKS more than a secretary of a department
 * head* — and they are pointer-referenced by templates, so a block that reached
 * further than the templates using it would be the wider hole of the two.
 */
final class DocumentBlocksApiHandlerRealEngineTest extends TestCase
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

    private const OU_FACULTY = 501;
    private const OU_DEPT_A  = 502;
    private const OU_DEPT_B  = 503;

    private const ROLE_SECRETARY = 104;

    private const CONTRACTS_PERM = 'documents:use:contracts';

    private PDO $pdo;
    private DocumentBlocksApiHandler $handler;
    private DocumentTemplateRepository $templates;
    private ResourceRoleAssignmentRepository $grants;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapSqlite($this->pdo);
        $this->templates = new DocumentTemplateRepository($this->pdo);
        $this->grants = new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry());
        $this->handler = new DocumentBlocksApiHandler(
            new DocumentBlockRepository($this->pdo),
            $this->templates,
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

    public function testPersonalBlockVisibleOnlyToItsCreator(): void
    {
        $id = $this->create(self::OWNER, ['name' => 'Mine', 'data' => [['id' => 'e1', 'type' => 'text']]]);
        self::assertSame(201, $id->getStatusCode(), $id->getBody());

        self::assertCount(1, $this->list(self::OWNER), 'owner sees own personal');
        self::assertCount(0, $this->list(self::VIEWER), 'another user does not see a personal block');
    }

    public function testSystemScopeVisibleToEveryone(): void
    {
        $this->create(self::OWNER, ['name' => 'Header', 'data' => [['id' => 'e1', 'type' => 'text']], 'scope' => 'system']);
        self::assertCount(1, $this->list(self::VIEWER), 'system-scope blocks are visible to all in the tenant');
    }

    public function testTenantScopeGatedByRequiredPermission(): void
    {
        // Owner (has publish) creates a tenant-wide block gated on the contracts tag.
        $res = $this->create(self::OWNER, [
            'name' => 'Contract clause', 'data' => [['id' => 'e1', 'type' => 'text']],
            'scope' => 'tenant', 'required_permission' => self::CONTRACTS_PERM,
        ]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());

        // The manager holds the tag → sees it; the viewer does not → never receives it.
        self::assertCount(1, $this->list(self::MANAGER), 'a holder of the required permission sees the gated block');
        self::assertCount(0, $this->list(self::VIEWER), 'a technician without the tag never receives the gated block');
    }

    public function testHiddenBlockShowReturns404NotForbidden(): void
    {
        $id = $this->decodeId($this->create(self::OWNER, [
            'name' => 'Contract clause', 'data' => [['id' => 'e1', 'type' => 'text']],
            'scope' => 'tenant', 'required_permission' => self::CONTRACTS_PERM,
        ]));

        $res = $this->show(self::VIEWER, $id);
        self::assertSame(404, $res->getStatusCode(), 'a gated row must 404 (not 403) to a caller who may not see it');
    }

    // ── publish gate ──────────────────────────────────────────────────────────

    public function testPublishingSharedScopeRequiresPublishPermission(): void
    {
        // WRITER has documents:write (route) but NOT documents:publish → 403 on a shared scope.
        $res = $this->create(self::WRITER, ['name' => 'Shared', 'data' => [['id' => 'e1', 'type' => 'text']], 'scope' => 'tenant']);
        self::assertSame(403, $res->getStatusCode());

        // Personal scope is fine without publish.
        self::assertSame(201, $this->create(self::WRITER, ['name' => 'Mine', 'data' => [['id' => 'e1', 'type' => 'text']]])->getStatusCode());
    }

    public function testUpdatingAPersonalBlockToSharedNeedsPublish(): void
    {
        $id = $this->decodeId($this->create(self::WRITER, ['name' => 'Mine', 'data' => [['id' => 'e1', 'type' => 'text']]]));
        $res = $this->patch(self::WRITER, $id, ['scope' => 'tenant']);
        self::assertSame(403, $res->getStatusCode(), 'promoting to a shared scope is a publish action');
    }

    // ── CRUD + validation ─────────────────────────────────────────────────────

    public function testCreateValidatesNameAndData(): void
    {
        self::assertSame(422, $this->create(self::OWNER, ['name' => '', 'data' => [['id' => 'e1']]])->getStatusCode());
        self::assertSame(422, $this->create(self::OWNER, ['name' => 'x', 'data' => []])->getStatusCode());
        self::assertSame(422, $this->create(self::OWNER, ['name' => 'x', 'data' => [['id' => 'e1']], 'scope' => 'nope'])->getStatusCode());
    }

    public function testOwnerUpdatesAndDeletes(): void
    {
        $id = $this->decodeId($this->create(self::OWNER, ['name' => 'A', 'data' => [['id' => 'e1', 'type' => 'text']]]));
        self::assertSame(200, $this->patch(self::OWNER, $id, ['name' => 'A2'])->getStatusCode());
        self::assertSame(204, $this->delete(self::OWNER, $id)->getStatusCode());
        self::assertSame(404, $this->show(self::OWNER, $id)->getStatusCode());
    }

    // ── reference-integrity delete guard (WC-521, the crux of this task) ─────

    public function testDeleteIsRefusedWhileATemplateHoldsALiveBlockInstance(): void
    {
        $blockId = $this->decodeId($this->create(self::OWNER, ['name' => 'Logo', 'data' => [['id' => 'e1', 'type' => 'image']]]));

        $this->templates->create(self::TENANT, [
            'name' => 'Invoice',
            'data' => [
                'version' => 2,
                'pages' => [[
                    'id' => 'p1',
                    'elements' => [
                        ['id' => 'e2', 'type' => 'blockInstance', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 0, 'z' => 1, 'blockId' => (string) $blockId],
                    ],
                ]],
            ],
        ]);

        $res = $this->delete(self::OWNER, $blockId);
        self::assertSame(409, $res->getStatusCode(), 'a block with a live template reference must not be deletable');

        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('error', $decoded);
        self::assertStringNotContainsString('SQLSTATE', (string) $decoded['error'], 'the guard error must never leak raw internals (WC-186)');

        // The block still exists — the guard did not silently orphan it either.
        self::assertSame(200, $this->show(self::OWNER, $blockId)->getStatusCode());
    }

    public function testDeleteSucceedsOnceNoTemplateReferencesTheBlock(): void
    {
        $blockId = $this->decodeId($this->create(self::OWNER, ['name' => 'Logo', 'data' => [['id' => 'e1', 'type' => 'image']]]));

        // A template exists but points at a DIFFERENT block id — no reference.
        $this->templates->create(self::TENANT, [
            'name' => 'Invoice',
            'data' => [
                'version' => 2,
                'pages' => [[
                    'id' => 'p1',
                    'elements' => [
                        ['id' => 'e2', 'type' => 'blockInstance', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 0, 'z' => 1, 'blockId' => (string) ($blockId + 999)],
                    ],
                ]],
            ],
        ]);

        self::assertSame(204, $this->delete(self::OWNER, $blockId)->getStatusCode());
        self::assertSame(404, $this->show(self::OWNER, $blockId)->getStatusCode());
    }

    public function testDeleteGuardIsTenantScoped(): void
    {
        $blockId = $this->decodeId($this->create(self::OWNER, ['name' => 'Logo', 'data' => [['id' => 'e1', 'type' => 'image']]]));

        // A template referencing the SAME numeric id, but under a DIFFERENT
        // tenant, must not block the delete (tenant isolation of the scan).
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'b', 'b')");
        $this->templates->create(2, [
            'name' => 'Other tenant doc',
            'data' => [
                'version' => 2,
                'pages' => [[
                    'id' => 'p1',
                    'elements' => [
                        ['id' => 'e2', 'type' => 'blockInstance', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 0, 'z' => 1, 'blockId' => (string) $blockId],
                    ],
                ]],
            ],
        ]);

        self::assertSame(204, $this->delete(self::OWNER, $blockId)->getStatusCode());
    }

    // ── usage: what would break if this block changed ────────────────────────

    /**
     * The usage answer names the templates that instance the block.
     */
    public function testUsageNamesTheReferencingTemplates(): void
    {
        $blockId = $this->decodeId($this->create(self::OWNER, [
            'name' => 'Header', 'data' => [['id' => 'e1', 'type' => 'text']], 'scope' => 'system',
        ]));

        $this->referencingTemplate('Invoice', $blockId);
        $this->referencingTemplate('Works order', $blockId);
        // A third template that points at a DIFFERENT block must not be counted.
        $this->referencingTemplate('Unrelated', $blockId + 999);

        $usage = $this->usage(self::VIEWER, $blockId);
        self::assertSame(2, $usage['total']);
        self::assertSame(0, $usage['hidden']);
        self::assertSame(
            ['Invoice', 'Works order'],
            $this->sortedNames($usage['templates'])
        );
    }

    public function testUsageIsZeroForAnUnreferencedBlock(): void
    {
        $blockId = $this->decodeId($this->create(self::OWNER, [
            'name' => 'Orphan', 'data' => [['id' => 'e1', 'type' => 'text']], 'scope' => 'system',
        ]));

        $usage = $this->usage(self::VIEWER, $blockId);
        self::assertSame(0, $usage['total']);
        self::assertSame(0, $usage['hidden']);
        self::assertSame([], $usage['templates']);
    }

    /**
     * THE POINT OF THE ENDPOINT, and the assertion that fails if `total` is ever
     * "simplified" into a count of the filtered list.
     *
     * A department secretary can see and edit an unplaced tenant-wide block. Two
     * templates instance it: one filed in her own department, one filed in a
     * department she does not reach. Editing the block rewrites BOTH — so telling
     * her "1 template uses this" would be a true statement about her visibility
     * and a false statement about her blast radius. She must be told 2, and told
     * that 1 of them is not hers to see.
     */
    public function testUsageTotalCountsTemplatesTheCallerCannotSeeAndReportsThemAsHidden(): void
    {
        $blockId = $this->decodeId($this->create(self::OWNER, [
            'name' => 'Shared footer', 'data' => [['id' => 'e1', 'type' => 'text']], 'scope' => 'tenant',
        ]));

        $this->referencingTemplate('Civil works order', $blockId, self::OU_DEPT_A);
        $this->referencingTemplate('Materials test report', $blockId, self::OU_DEPT_B);

        // The block itself is unplaced, so both secretaries can see (and edit) it.
        self::assertSame(200, $this->handler->show($this->actAs(self::DEPT_SECRETARY), ['id' => (string) $blockId])->getStatusCode());

        $dept = $this->usage(self::DEPT_SECRETARY, $blockId);
        self::assertSame(2, $dept['total'], 'the total is every referencing template in the tenant, not just the visible ones');
        self::assertSame(1, $dept['hidden'], 'the one template she cannot see is reported as hidden, not omitted');
        self::assertSame(['Civil works order'], $this->sortedNames($dept['templates']), 'she is never handed the identity of the template she may not see');

        // The dean's secretary reaches both departments, so nothing is hidden
        // from her — same block, same total, different visible set.
        $dean = $this->usage(self::DEAN_SECRETARY, $blockId);
        self::assertSame(2, $dean['total']);
        self::assertSame(0, $dean['hidden']);
        self::assertSame(['Civil works order', 'Materials test report'], $this->sortedNames($dean['templates']));
    }

    /**
     * You cannot ask about the usage of a block whose existence is withheld —
     * otherwise the endpoint would be a probe for gated rows, since a 404 and a
     * `total: 0` would be distinguishable.
     */
    public function testUsageOnAHiddenBlockReturns404(): void
    {
        $blockId = $this->decodeId($this->create(self::OWNER, [
            'name' => 'Contract clause', 'data' => [['id' => 'e1', 'type' => 'text']],
            'scope' => 'tenant', 'required_permission' => self::CONTRACTS_PERM,
        ]));
        $this->referencingTemplate('Contract', $blockId);

        $res = $this->handler->usage($this->actAs(self::VIEWER), ['id' => (string) $blockId]);
        self::assertSame(404, $res->getStatusCode());
    }

    /** Same tenant predicate as the delete guard: another tenant's pointer at the same numeric id is not usage. */
    public function testUsageIsTenantScoped(): void
    {
        $blockId = $this->decodeId($this->create(self::OWNER, [
            'name' => 'Header', 'data' => [['id' => 'e1', 'type' => 'text']], 'scope' => 'system',
        ]));

        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'b', 'b')");
        $this->templates->create(2, [
            'name' => 'Other tenant doc',
            'data' => $this->treeReferencing($blockId),
        ]);

        $usage = $this->usage(self::OWNER, $blockId);
        self::assertSame(0, $usage['total']);
        self::assertSame([], $usage['templates']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @return array{block_id: int, total: int, hidden: int, templates: list<array<string, mixed>>}
     */
    private function usage(int $userId, int $blockId): array
    {
        $res = $this->handler->usage($this->actAs($userId), ['id' => (string) $blockId]);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['data'] ?? null);

        /** @var array{block_id: int, total: int, hidden: int, templates: list<array<string, mixed>>} */
        return $decoded['data'];
    }

    /**
     * A minimal v2 template tree whose single element is a `blockInstance`
     * pointer at $blockId.
     *
     * @return array<string, mixed>
     */
    private function treeReferencing(int $blockId): array
    {
        return [
            'version' => 2,
            'pages' => [[
                'id' => 'p1',
                'elements' => [
                    ['id' => 'e2', 'type' => 'blockInstance', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 0, 'z' => 1, 'blockId' => (string) $blockId],
                ],
            ]],
        ];
    }

    /**
     * A tenant-scoped template that instances $blockId, optionally filed at a
     * unit. Written through the repository rather than the templates HANDLER so
     * this test class stays about blocks — and `created_by` is left null on
     * purpose, since an author always reaches their own row and would mask the
     * placement filter this is here to exercise.
     */
    private function referencingTemplate(string $name, int $blockId, ?int $ouId = null): int
    {
        return $this->templates->create(self::TENANT, [
            'name'        => $name,
            'data'        => $this->treeReferencing($blockId),
            'scope'       => 'tenant',
            'owner_ou_id' => $ouId,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function sortedNames(array $rows): array
    {
        $names = array_map(static fn (array $row): string => (string) $row['name'], $rows);
        sort($names);

        return array_values($names);
    }

    private function actAs(int $userId): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $req = new Request('GET', '/api/document-blocks', [], '');
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
        $req = new Request('POST', '/api/document-blocks', [], (string) json_encode($body));
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

    // ── the WHERE dimension: reach (migration 117) ───────────────────────────

    /**
     * The requirement, for BLOCKS: two secretaries holding the same permission
     * and standing at different units receive different block sets.
     *
     * The permission-parity assertion comes first for the same reason it does in
     * the templates test — without it this would only prove the policy runs.
     */
    public function testTwoSecretariesHoldingTheSamePermissionSeeDifferentBlockSets(): void
    {
        $checker = new RoleChecker($this->wrapSqlite($this->pdo), new PermissionRegistry());
        $dean = $checker->getEffectivePermissionsForProfile(self::DEAN_SECRETARY, self::TENANT);
        $dept = $checker->getEffectivePermissionsForProfile(self::DEPT_SECRETARY, self::TENANT);
        sort($dean);
        sort($dept);
        self::assertSame($dean, $dept, 'identical permissions, or this test proves nothing');

        $this->place('Faculty header', self::OU_FACULTY);
        $this->place('Civil footer', self::OU_DEPT_A);
        $this->place('Materials footer', self::OU_DEPT_B);

        self::assertSame(
            ['Civil footer', 'Faculty header', 'Materials footer'],
            $this->visibleNames(self::DEAN_SECRETARY),
            "the dean's secretary reaches the faculty and everything beneath it"
        );
        self::assertSame(
            ['Civil footer'],
            $this->visibleNames(self::DEPT_SECRETARY),
            'a department secretary reaches only their own department'
        );
    }

    /** A role granted at a unit gives standing there with no membership. */
    public function testAGrantAtAUnitExtendsBlockReach(): void
    {
        $this->place('Materials footer', self::OU_DEPT_B);
        self::assertSame([], $this->visibleNames(self::DEPT_SECRETARY));

        $this->grants->grant(
            self::TENANT,
            ResourceTypeRegistry::TYPE_OU,
            self::OU_DEPT_B,
            self::ROLE_SECRETARY,
            self::DEPT_SECRETARY
        );
        RoleChecker::clearCache();

        self::assertSame(['Materials footer'], $this->visibleNames(self::DEPT_SECRETARY));
    }

    /** Unplaced blocks are unaffected — the migration changes no existing audience. */
    public function testAnUnplacedBlockStaysVisibleToEveryone(): void
    {
        $res = $this->create(self::OWNER, [
            'name' => 'Tenant-wide', 'data' => [['type' => 'text']], 'scope' => 'tenant',
        ]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());

        self::assertSame(['Tenant-wide'], $this->visibleNames(self::DEPT_SECRETARY));
        self::assertSame(['Tenant-wide'], $this->visibleNames(self::VIEWER));
    }

    /** Filing a block in the organisation is a publish action. */
    public function testPlacingABlockRequiresPublishPermission(): void
    {
        $res = $this->create(self::WRITER, [
            'name' => 'Mine', 'data' => [['type' => 'text']], 'owner_ou_id' => self::OU_DEPT_A,
        ]);
        self::assertSame(403, $res->getStatusCode());
    }

    /**
     * File a tenant-scoped block at a unit, as the admin (who holds publish).
     */
    private function place(string $name, int $ouId): void
    {
        $res = $this->create(self::OWNER, [
            'name' => $name,
            'data' => [['type' => 'text']],
            'scope' => 'tenant',
            'owner_ou_id' => $ouId,
        ]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());
    }

    /**
     * The names of the blocks this caller actually receives, sorted so the
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

        // One shared role for both secretaries: identical permissions, so only
        // placement can tell them apart.
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
        $pdo->exec("INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at) VALUES
            (501, 1, NULL, 'Faculty of Engineering', 'faculty-eng', datetime('now')),
            (502, 1, 501,  'Civil Engineering',      'civil-eng',   datetime('now')),
            (503, 1, 501,  'Materials Science',      'materials',   datetime('now'))");
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
