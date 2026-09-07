<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Document\DocumentTemplateRepository;

/**
 * Real-engine tests for the document-designer repositories (WC-docdesigner): the
 * tenant-isolation proof for `document_templates` + `document_blocks`, plus the
 * JSON `data` round-trip (the client object is the contract) and the governance
 * columns (scope / required_permission / is_system).
 */
final class DocumentRepositoryRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private DocumentTemplateRepository $templates;
    private DocumentBlockRepository $blocks;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $this->templates = new DocumentTemplateRepository($this->pdo);
        $this->blocks = new DocumentBlockRepository($this->pdo);
    }

    /**
     * A representative v2 DocTemplate — nested pages/elements — to prove the JSON
     * round-trips byte-faithfully through the jsonb column.
     *
     * @return array<string, mixed>
     */
    private function sampleTemplate(): array
    {
        return [
            'version' => 2,
            'name' => 'Invoice',
            'page' => ['widthMm' => 210, 'heightMm' => 297, 'marginMm' => 10, 'background' => '#ffffff'],
            'placeholders' => [['key' => 'customer', 'label' => 'Customer', 'sample' => 'Acme']],
            'pages' => [[
                'id' => 'p1',
                'elements' => [
                    ['id' => 'e1', 'type' => 'text', 'x' => 5, 'y' => 5, 'w' => 50, 'h' => 10, 'rotation' => 0, 'z' => 1, 'text' => 'Hello {{customer}}'],
                    ['id' => 'e2', 'type' => 'blockInstance', 'x' => 0, 'y' => 20, 'w' => 80, 'h' => 30, 'rotation' => 0, 'z' => 2, 'blockId' => 'blk-42'],
                ],
            ]],
        ];
    }

    // ── templates ───────────────────────────────────────────────────────────

    public function testTemplateRoundTripsJsonAndGovernanceColumns(): void
    {
        $id = $this->templates->create(self::TENANT_A, [
            'name' => 'Invoice',
            'data' => $this->sampleTemplate(),
            'scope' => 'tenant',
            'required_permission' => 'documents:use:finance',
            'is_system' => true,
            'created_by' => 77,
        ]);

        $row = $this->templates->findById($id, self::TENANT_A);
        self::assertNotNull($row);
        // assertEquals, not assertSame: Postgres jsonb round-trips by VALUE but
        // reorders object keys (SQLite preserves order — the classic mask). The
        // client re-parses JSON, so value-equality is the contract; jsonb is kept
        // for its containment queries (future block reference-integrity guard).
        self::assertEquals($this->sampleTemplate(), $row['data'], 'the DocTemplate JSON must round-trip by value');
        self::assertSame('tenant', $row['scope']);
        self::assertSame('documents:use:finance', $row['required_permission']);
        self::assertTrue($row['is_system']);
        self::assertSame(77, $row['created_by']);
    }

    public function testTemplateIsTenantIsolated(): void
    {
        $id = $this->templates->create(self::TENANT_A, ['name' => 'A', 'data' => $this->sampleTemplate()]);

        // Tenant B cannot read, update, or delete tenant A's template.
        self::assertNull($this->templates->findById($id, self::TENANT_B));
        self::assertSame(0, $this->templates->update($id, self::TENANT_B, ['name' => 'HIJACK']));
        self::assertSame(0, $this->templates->delete($id, self::TENANT_B));

        // The row is untouched under its real owner.
        $row = $this->templates->findById($id, self::TENANT_A);
        self::assertNotNull($row);
        self::assertSame('A', $row['name']);

        // And B's list never sees it.
        self::assertSame([], $this->blocks->listForTenant(self::TENANT_B));
        self::assertCount(1, $this->templates->listForTenant(self::TENANT_A));
        self::assertSame([], $this->templates->listForTenant(self::TENANT_B));
    }

    public function testTemplateOwnerCanUpdateAndDelete(): void
    {
        $id = $this->templates->create(self::TENANT_A, ['name' => 'A', 'data' => ['version' => 2]]);
        self::assertSame(1, $this->templates->update($id, self::TENANT_A, ['name' => 'A2', 'scope' => 'global']));
        $row = $this->templates->findById($id, self::TENANT_A);
        self::assertNotNull($row);
        self::assertSame('A2', $row['name']);
        self::assertSame('global', $row['scope']);

        self::assertSame(1, $this->templates->delete($id, self::TENANT_A));
        self::assertNull($this->templates->findById($id, self::TENANT_A));
    }

    public function testTemplateDefaultsScopePersonalAndNotSystem(): void
    {
        $id = $this->templates->create(self::TENANT_A, ['name' => 'A', 'data' => ['version' => 2]]);
        $row = $this->templates->findById($id, self::TENANT_A);
        self::assertNotNull($row);
        self::assertSame('personal', $row['scope']);
        self::assertFalse($row['is_system']);
        self::assertNull($row['required_permission']);
        self::assertNull($row['created_by']);
    }

    // ── blocks ──────────────────────────────────────────────────────────────

    public function testBlockRoundTripsElementFragmentAndIsIsolated(): void
    {
        $elements = [
            ['id' => 'b1', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 40, 'h' => 20, 'rotation' => 0, 'z' => 1, 'src' => 'logo'],
        ];
        $id = $this->blocks->create(self::TENANT_A, ['name' => 'Logo lockup', 'data' => $elements, 'scope' => 'tenant']);

        $row = $this->blocks->findById($id, self::TENANT_A);
        self::assertNotNull($row);
        self::assertEquals($elements, $row['data']); // value equality — jsonb reorders keys on PG
        self::assertSame('tenant', $row['scope']);

        // Isolation.
        self::assertNull($this->blocks->findById($id, self::TENANT_B));
        self::assertSame(0, $this->blocks->delete($id, self::TENANT_B));
        self::assertNotNull($this->blocks->findById($id, self::TENANT_A));
    }

    // ── block reference-integrity guard (WC-521) ──────────────────────────────

    /**
     * The delete-guard scan: a template holding a live `blockInstance` pointer
     * at the block id is detected — on Postgres via the jsonb_path_exists()
     * branch, on SQLite via the fetch+decode+scan fallback. Same answer either
     * engine (the DocumentBlocksApiHandlerRealEngineTest 409/204 tests exercise
     * this same method through the handler; this pins the repository method
     * directly, tenant-scoped).
     */
    public function testReferencesBlockDetectsALiveBlockInstancePointer(): void
    {
        $blockId = $this->blocks->create(self::TENANT_A, ['name' => 'Logo', 'data' => [['id' => 'e1', 'type' => 'image']]]);

        // No template references it yet.
        self::assertFalse($this->templates->referencesBlock($blockId, self::TENANT_A));

        $this->templates->create(self::TENANT_A, [
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

        self::assertTrue($this->templates->referencesBlock($blockId, self::TENANT_A), 'a live blockInstance pointer must be detected');

        // Tenant-scoped: the same block id is never "referenced" from another
        // tenant's point of view, even if that tenant happens to hold a
        // template pointing at the same numeric id (a different block, really).
        self::assertFalse($this->templates->referencesBlock($blockId, self::TENANT_B));
    }

    /**
     * `referencingTemplates()` answers the same question as `referencesBlock()`
     * but as a list — so the two must never be able to disagree. Asserted
     * together, on one fixture, because they carry SEPARATE jsonpath/PHP-walk
     * implementations and a drift between them would show up as a management
     * screen that says "nothing uses this" over a delete that then 409s.
     */
    public function testReferencingTemplatesNamesEveryTemplateHoldingAPointer(): void
    {
        $blockId = $this->blocks->create(self::TENANT_A, ['name' => 'Logo', 'data' => [['id' => 'e1', 'type' => 'image']]]);

        // assertCount, not assertSame([], …): PHPStan's PHPUnit extension takes an
        // assertSame against a literal `[]` as narrowing the METHOD's return type
        // to array{} for the rest of the scope, which then reports the honest
        // agreement check further down as a comparison that can never be true.
        self::assertCount(0, $this->templates->referencingTemplates($blockId, self::TENANT_A));
        self::assertFalse($this->templates->referencesBlock($blockId, self::TENANT_A));

        $this->templates->create(self::TENANT_A, ['name' => 'Invoice', 'data' => $this->treeReferencing($blockId)]);
        $this->templates->create(self::TENANT_A, ['name' => 'Works order', 'data' => $this->treeReferencing($blockId)]);
        // A template pointing elsewhere, and one with no pointer at all.
        $this->templates->create(self::TENANT_A, ['name' => 'Other block', 'data' => $this->treeReferencing($blockId + 999)]);
        $this->templates->create(self::TENANT_A, ['name' => 'Plain', 'data' => ['version' => 2, 'pages' => []]]);

        $rows = $this->templates->referencingTemplates($blockId, self::TENANT_A);
        $names = array_map(static fn (array $r): string => (string) $r['name'], $rows);
        sort($names);
        self::assertSame(['Invoice', 'Works order'], $names);

        // The list and the boolean guard must never disagree — they carry separate
        // jsonpath/PHP-walk implementations, and a drift shows up as a management
        // screen saying "nothing uses this" over a delete the server then refuses.
        self::assertSame(
            $rows !== [],
            $this->templates->referencesBlock($blockId, self::TENANT_A),
            'referencingTemplates() and referencesBlock() answered differently'
        );

        // Tenant-scoped, exactly like the boolean guard.
        self::assertCount(0, $this->templates->referencingTemplates($blockId, self::TENANT_B));
    }

    /**
     * The reference rows carry the governance columns the visibility policy reads
     * — otherwise the handler could not filter them — and carry NO template body.
     *
     * The empty `data` is asserted rather than merely documented: a later "reuse
     * normalizeRow, it is right there" refactor would silently start shipping N
     * full template trees to render N names, and nothing else in the suite would
     * notice.
     */
    public function testReferencingTemplatesCarryGovernanceColumnsButNotTheTemplateBody(): void
    {
        $blockId = $this->blocks->create(self::TENANT_A, ['name' => 'Logo', 'data' => [['id' => 'e1', 'type' => 'image']]]);
        $this->templates->create(self::TENANT_A, [
            'name'                => 'Gated contract',
            'data'                => $this->treeReferencing($blockId),
            'scope'               => 'tenant',
            'required_permission' => 'documents:use:contracts',
            'created_by'          => 77,
            'owner_ou_id'         => null,
        ]);

        $rows = $this->templates->referencingTemplates($blockId, self::TENANT_A);
        self::assertCount(1, $rows);
        $row = $rows[0];

        self::assertSame('Gated contract', $row['name']);
        self::assertSame('tenant', $row['scope']);
        self::assertSame('documents:use:contracts', $row['required_permission']);
        self::assertSame(77, $row['created_by']);
        self::assertNull($row['owner_ou_id']);
        self::assertFalse($row['is_system']);
        self::assertSame(self::TENANT_A, $row['tenant_id']);
        self::assertSame([], $row['data'], 'a usage listing must not haul every referencing template body across');
    }

    /**
     * A minimal v2 tree whose only element points at $blockId.
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

    public function testReferencesBlockIsFalseWhenNoTemplatePointsAtIt(): void
    {
        $blockId = $this->blocks->create(self::TENANT_A, ['name' => 'Unreferenced', 'data' => [['id' => 'e1', 'type' => 'image']]]);

        // A template exists, but its blockInstance points at a different id.
        $this->templates->create(self::TENANT_A, [
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

        self::assertFalse($this->templates->referencesBlock($blockId, self::TENANT_A));
    }

    /**
     * The `resource_type = 'document'` literal in
     * {@see \Whity\Core\Document\DocumentRepository}'s visibility predicate must
     * agree with the registry constant (#947 item 3).
     *
     * The clause is written as literal SQL because a nowdoc cannot interpolate a
     * class constant and concatenating around it would destroy exactly the
     * literal-SQL readability scripts/ci-tenant-predicate-guard.php depends on.
     * So the duplication is deliberate — and pinned here, because the failure it
     * would otherwise produce is silent: renaming the resource type would empty
     * the resource-role disjunct of every RESTRICTED document list, and a list
     * quietly returning fewer rows is the failure mode nobody notices.
     */
    public function testTheVisibilityPredicateNamesTheRegisteredDocumentResourceType(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Core/Document/DocumentRepository.php');
        self::assertIsString($source);

        self::assertStringContainsString(
            "rra.resource_type = '" . \Whity\Core\RBAC\ResourceTypeRegistry::TYPE_DOCUMENT . "'",
            $source,
            'DocumentRepository::VISIBLE_TO_CALLER must name ResourceTypeRegistry::TYPE_DOCUMENT. '
            . 'If the constant was renamed, the SQL literal has to move with it.'
        );
    }
}
