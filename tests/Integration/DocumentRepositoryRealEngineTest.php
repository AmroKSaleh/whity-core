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
        self::assertSame($this->sampleTemplate(), $row['data'], 'the DocTemplate JSON must round-trip faithfully');
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
        self::assertSame($elements, $row['data']);
        self::assertSame('tenant', $row['scope']);

        // Isolation.
        self::assertNull($this->blocks->findById($id, self::TENANT_B));
        self::assertSame(0, $this->blocks->delete($id, self::TENANT_B));
        self::assertNotNull($this->blocks->findById($id, self::TENANT_A));
    }
}
