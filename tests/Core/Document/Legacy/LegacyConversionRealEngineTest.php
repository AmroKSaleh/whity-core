<?php

declare(strict_types=1);

namespace Tests\Core\Document\Legacy;

use Database\Migrations\IssueDocumentsFromSourcesAndDropDesigner;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceStarterSeeder;
use Whity\Core\Document\Legacy\ConversionLabels;
use Whity\Core\Document\Legacy\LegacyTemplateConverter;
use Whity\Core\Document\Print\DocumentPublishCheck;
use Whity\Core\Document\Print\WhityDocumentPrinter;
use Whity\Database\Database;

/**
 * The old Document designer's templates become editor documents (editor M4b),
 * and migration 165 does it for every tenant before dropping the old tables
 * (editor M4c). A converted document must be one the editor can publish once a
 * person has checked it: every field it uses is offered, and every block has a
 * print view.
 *
 * The old designer's own starters and its company header and footer blocks are
 * kept as a fixture (fixtures/old-designer-starters.json, dumped from the
 * seeder before it was deleted), so the conversion is still checked against
 * real templates.
 */
final class LegacyConversionRealEngineTest extends TestCase
{
    private const TENANT = 1;

    /** The fields every template is offered, whatever its record type. */
    private const SHARED = ['company.name' => true, 'company.address' => true, 'company.tax_number' => true, 'today' => true];

    private PDO $pdo;
    private Database $db;
    private DocumentSourceRepository $sources;

    /** @var array<string, mixed> templates and blocks, as fixtures/old-designer-starters.json has them */
    private array $old;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->db = Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Clinic', 'clinic')");
        $this->sources = new DocumentSourceRepository($this->pdo);
        $old = json_decode((string) file_get_contents(__DIR__ . '/fixtures/old-designer-starters.json'), true);
        self::assertIsArray($old);
        $this->old = $old;
    }

    // ── converter ───────────────────────────────────────────────────────────

    public function testACanvasBecomesBlocksInReadingOrderWithPlaceholdersAsOwnFields(): void
    {
        $doc = (new LegacyTemplateConverter())->convert('Delivery slip', [
            'version' => 2,
            'name' => 'Delivery slip',
            'page' => ['widthMm' => 210, 'heightMm' => 297, 'marginMm' => 10, 'background' => '#ffffff'],
            'placeholders' => [['key' => 'ship_to', 'label' => 'Ship to', 'sample' => 'Example Clinic']],
            'pages' => [['id' => 'p1', 'elements' => [
                // Out of order on purpose: the converter sorts by position.
                ['id' => 'e3', 'type' => 'barcode', 'x' => 10, 'y' => 80, 'w' => 60, 'h' => 20, 'rotation' => 0, 'z' => 3, 'symbology' => 'code128', 'value' => '{{tracking}}', 'showText' => true],
                ['id' => 'e1', 'type' => 'text', 'x' => 10, 'y' => 10, 'w' => 100, 'h' => 10, 'rotation' => 0, 'z' => 1, 'text' => 'Delivery slip', 'style' => ['fontSize' => 22, 'fontWeight' => 'bold']],
                ['id' => 'e2', 'type' => 'dynamicText', 'x' => 10, 'y' => 30, 'w' => 100, 'h' => 10, 'rotation' => 0, 'z' => 2, 'template' => "To: {{ship_to}}\nThanks", 'style' => ['fontSize' => 11, 'fontWeight' => 'normal']],
                ['id' => 'e4', 'type' => 'rect', 'x' => 5, 'y' => 5, 'w' => 200, 'h' => 100, 'rotation' => 0, 'z' => 0, 'fill' => '#eef2ff', 'stroke' => '#4f46e5', 'strokeWidth' => 0.3, 'radius' => 1],
                ['id' => 'e5', 'type' => 'line', 'x' => 10, 'y' => 60, 'w' => 190, 'h' => 0.5, 'rotation' => 0, 'z' => 4, 'stroke' => '#111111', 'strokeWidth' => 0.5],
                ['id' => 'e6', 'type' => 'blockInstance', 'x' => 10, 'y' => 200, 'w' => 190, 'h' => 30, 'rotation' => 0, 'z' => 5, 'blockId' => '7'],
                ['id' => 'e7', 'type' => 'math', 'x' => 10, 'y' => 120, 'w' => 40, 'h' => 14, 'rotation' => 0, 'z' => 6, 'expression' => 'x^2'],
            ]]],
        ]);

        self::assertSame('template', $doc['kind']);
        self::assertSame('A4', $doc['page']['size']);
        self::assertSame(
            ['callout', 'heading', 'paragraph', 'divider', 'barcode', 'math'],
            array_column($doc['blocks'], 'type'),
            'the note first, then top to bottom; the rectangle and the missing saved block are left out',
        );
        self::assertSame(['shape' => 1, 'savedBlock' => 1], $doc['dropped']);
        self::assertStringContainsString('rectangles and lines (1)', $doc['blocks'][0]['content'][0]['text']);
        self::assertStringContainsString('saved blocks that no longer exist (1)', $doc['blocks'][0]['content'][0]['text']);

        self::assertSame(1, $doc['blocks'][1]['level']);
        self::assertSame([
            ['type' => 'text', 'text' => 'To: '],
            ['type' => 'field', 'path' => 'template.ship_to', 'label' => 'Ship to'],
            ['type' => 'break'],
            ['type' => 'text', 'text' => 'Thanks'],
        ], $doc['blocks'][2]['content']);
        self::assertSame([['type' => 'field', 'path' => 'template.tracking', 'label' => 'Tracking']], $doc['blocks'][4]['value']);
        self::assertSame(
            [['key' => 'ship_to', 'label' => 'Ship to', 'sample' => 'Example Clinic'], ['key' => 'tracking', 'label' => 'Tracking']],
            $doc['own_fields'],
            'declared placeholders keep their label and sample; an undeclared one gets a name',
        );
    }

    /**
     * Saved blocks go with the old designer, so a template that used one gets
     * the block's elements where the instance sat, in reading order with the
     * rest of the page. A block inside a block is placed too; a block that
     * contains itself is placed once and the loop is cut.
     */
    public function testASavedBlocksElementsArePlacedWhereItsInstanceSat(): void
    {
        $text = static fn (string $id, float $x, float $y, string $text): array => [
            'id' => $id, 'type' => 'text', 'x' => $x, 'y' => $y, 'w' => 80, 'h' => 8, 'rotation' => 0, 'z' => 1,
            'text' => $text, 'style' => ['fontSize' => 11],
        ];
        $instance = static fn (string $id, float $x, float $y, string $blockId): array => [
            'id' => $id, 'type' => 'blockInstance', 'x' => $x, 'y' => $y, 'w' => 100, 'h' => 20, 'rotation' => 0, 'z' => 1, 'blockId' => $blockId,
        ];

        $doc = (new LegacyTemplateConverter())->convert('Letter', [
            'version' => 2, 'name' => 'Letter', 'placeholders' => [],
            'page' => ['widthMm' => 210, 'heightMm' => 297, 'marginMm' => 10, 'background' => '#fff'],
            'pages' => [['id' => 'p', 'elements' => [
                $text('body', 10, 100, 'Dear Example Clinic'),
                $instance('head', 10, 10, '1'),
                $instance('foot', 10, 270, '3'),
            ]]],
        ], [
            '1' => [$text('name', 0, 0, 'Example Bakery'), $instance('nested', 0, 20, '2')],
            '2' => [$text('address', 0, 0, 'Rainbow Street 21')],
            '3' => [$text('thanks', 0, 0, 'Thank you'), $instance('loop', 0, 10, '3')],
        ]);

        self::assertSame(
            ['Example Bakery', 'Rainbow Street 21', 'Dear Example Clinic', 'Thank you'],
            array_map(static fn (array $b): string => $b['content'][0]['text'], array_values(array_filter($doc['blocks'], static fn (array $b): bool => $b['type'] !== 'callout'))),
            'the header block and the block inside it at the top, the footer block at the end',
        );
        self::assertSame(['savedBlock' => 1], $doc['dropped'], 'the footer block that contains itself is cut once');
    }

    public function testLabelStockAndLabelSizedPagesBecomeLabelSheets(): void
    {
        $converter = new LegacyTemplateConverter();
        $text = ['id' => 't', 'type' => 'text', 'x' => 2, 'y' => 2, 'w' => 50, 'h' => 8, 'rotation' => 0, 'z' => 1, 'text' => 'SKU', 'style' => ['fontSize' => 9]];

        $sheet = $converter->convert('Shelf labels', [
            'version' => 2, 'name' => 'Shelf labels', 'placeholders' => [],
            'page' => ['widthMm' => 63.5, 'heightMm' => 38.1, 'marginMm' => 0, 'background' => '#fff'],
            'pages' => [['id' => 'p', 'elements' => [$text]]],
            'sheet' => ['enabled' => true, 'cols' => 3, 'rows' => 7, 'sheetWidthMm' => 210, 'sheetHeightMm' => 297, 'marginXMm' => 7, 'marginYMm' => 15, 'gutterXMm' => 2.5, 'gutterYMm' => 0],
        ]);
        self::assertSame('label', $sheet['kind']);
        self::assertSame(['columns' => 3, 'rows' => 7, 'size' => ['widthMm' => 63.5, 'heightMm' => 38.1], 'gapMm' => 2.5, 'gapYMm' => 0.0], $sheet['page']['labels']);

        $single = $converter->convert('Parcel label', [
            'version' => 2, 'name' => 'Parcel label', 'placeholders' => [],
            'page' => ['widthMm' => 100, 'heightMm' => 150, 'marginMm' => 0, 'background' => '#fff'],
            'pages' => [['id' => 'p', 'elements' => [$text]]],
        ]);
        self::assertSame('label', $single['kind'], 'a page the size of a label prints one per sheet');
        self::assertSame(['columns' => 1, 'rows' => 1, 'size' => ['widthMm' => 100.0, 'heightMm' => 150.0], 'gapMm' => 0], $single['page']['labels']);
    }

    public function testAFlowTemplateMapsBlockForBlock(): void
    {
        $doc = (new LegacyTemplateConverter())->convert('تقرير', [
            'version' => 2, 'name' => 'تقرير', 'placeholders' => [], 'mode' => 'flow',
            'page' => ['widthMm' => 210, 'heightMm' => 297, 'marginMm' => 20, 'background' => '#fff'],
            'pages' => [],
            'flow' => [
                'blocks' => [
                    ['type' => 'heading', 'level' => 5, 'text' => 'الملخص'],
                    ['type' => 'paragraph', 'runs' => [['text' => 'نص ', 'bold' => true], ['text' => 'هنا', 'href' => 'javascript:alert(1)']]],
                    ['type' => 'table', 'columns' => ['A', 'B'], 'rows' => [['1', '2'], ['3']]],
                    ['type' => 'pageBreak'],
                    ['type' => 'spacer', 'heightMm' => 5],
                ],
                'header' => ['start' => 'x'],
            ],
        ]);

        self::assertSame('ar', $doc['lang']);
        self::assertSame(['callout', 'heading', 'paragraph', 'table', 'pageBreak'], array_column($doc['blocks'], 'type'));
        self::assertSame(3, $doc['blocks'][1]['level'], 'levels deeper than 3 become 3');
        self::assertSame([['type' => 'text', 'text' => 'نص ', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => 'هنا']], $doc['blocks'][2]['content'], 'no unsafe link');
        self::assertTrue($doc['blocks'][3]['headerRow']);
        self::assertSame([[['type' => 'text', 'text' => '3']], []], $doc['blocks'][3]['rows'][2], 'short rows are padded');
    }

    public function testEveryOldStarterConvertsToADocumentTheEditorCanPublish(): void
    {
        $converter = new LegacyTemplateConverter();
        $blocks = array_map(static fn (array $b): array => $b['elements'], $this->old['blocks']);
        self::assertCount(4, $this->old['templates']);
        foreach ($this->old['templates'] as $starter) {
            $doc = $converter->convert($starter['name'], $starter['data'], $blocks);
            $content = array_values(array_filter($doc['blocks'], static fn (array $b): bool => $b['type'] !== 'callout'));
            self::assertNotSame([], $content, $starter['name']);
            $this->assertPublishable($doc['blocks'], $doc['own_fields'], $starter['name']);
        }
    }

    public function testTheConversionNoteIsInTheTemplatesLanguage(): void
    {
        $labels = ConversionLabels::fromCatalogue(dirname(__DIR__, 4) . '/database/i18n');
        $note = $labels->note(['shape' => 2], 'ar');
        self::assertStringContainsString('(2)', $note);
        self::assertStringNotContainsString('Converted from', $note, 'Arabic from the shipped catalogue');
        self::assertStringStartsWith('Converted from the old Document designer.', $labels->note(['shape' => 2], 'en'));
    }

    // ── migration 165 ───────────────────────────────────────────────────────

    /**
     * The upgrade in one step: every tenant gets the editor's starters, every
     * old template a person made or edited becomes an editor draft (once), a
     * document issued from one points at its copy with no version (it can't be
     * re-rendered), and the old tables are gone.
     */
    public function testTheMigrationConvertsEveryTenantLinksTheirDocumentsAndDropsTheOldTables(): void
    {
        $this->restoreOldTables();
        $header = $this->oldBlock(self::TENANT, $this->old['blocks']['sys-header']);
        $unedited = [];
        foreach ($this->old['templates'] as $key => $starter) {
            $unedited[$key] = $this->oldTemplate(self::TENANT, $starter['name'], $starter['data'], 'system', $key, false);
        }
        $edited = $this->oldTemplate(self::TENANT, 'Invoice', $this->old['templates']['invoice']['data'], 'system', 'invoice-edited', true);
        $mine = $this->oldTemplate(self::TENANT, 'Our letter', [
            'version' => 2, 'name' => 'Our letter', 'placeholders' => [],
            'page' => ['widthMm' => 210, 'heightMm' => 297, 'marginMm' => 10, 'background' => '#fff'],
            'pages' => [['id' => 'p', 'elements' => [
                ['id' => 'h', 'type' => 'blockInstance', 'x' => 10, 'y' => 10, 'w' => 190, 'h' => 30, 'rotation' => 0, 'z' => 1, 'blockId' => (string) $header],
                ['id' => 'a', 'type' => 'text', 'x' => 10, 'y' => 60, 'w' => 50, 'h' => 8, 'rotation' => 0, 'z' => 2, 'text' => 'Hello', 'style' => ['fontSize' => 11]],
            ]]],
        ], 'tenant', null, true);
        $fromMine = $this->oldDocument(self::TENANT, $mine, 'Our letter');
        $fromStarter = $this->oldDocument(self::TENANT, $unedited['shipping'], 'Shipping label');

        IssueDocumentsFromSourcesAndDropDesigner::up($this->db);

        $this->assertTemplatesGone();
        self::assertFalse($this->tableExists('document_blocks'));

        $converted = $this->sources->convertedTemplateIds(self::TENANT);
        self::assertEqualsCanonicalizing([$edited, $mine], array_keys($converted), 'the edited starter and the tenant\'s own; unedited starters are not copied');

        $titles = array_column($this->sources->listForTenant(self::TENANT), 'title');
        self::assertSame(
            ['Certificate', 'Exam sheet', 'Invoice', 'Invoice', 'Letter', 'Our letter', 'Production note', 'Shipping labels'],
            self::sorted($titles),
            'the six editor starters and the two copies',
        );
        self::assertSame(
            ['Certificate', 'Exam sheet', 'Invoice', 'Letter', 'Production note', 'Shipping labels'],
            self::sorted(array_column($this->sources->listForTenant(2), 'title')),
            'a tenant with nothing to convert still gets the starters',
        );

        $copy = $this->copyOf($mine);
        self::assertSame('tenant', $copy['scope'], 'who can see it carries over');
        self::assertNull($copy['published_version'], 'a draft until someone publishes it');
        $texts = array_map(
            static fn (array $b): string => (string) ($b['content'][0]['text'] ?? ''),
            array_values(array_filter($copy['blocks'], static fn (array $b): bool => $b['type'] !== 'callout')),
        );
        self::assertSame('Hello', end($texts), 'the letter\'s own text after the header');
        self::assertGreaterThan(1, count($texts), 'the saved header block was placed into the letter');

        $docs = $this->documents();
        self::assertSame((int) $copy['id'], $docs[$fromMine]['document_source_id'], 'a document from an old template points at its copy');
        self::assertNull($docs[$fromMine]['document_source_version'], 'with no version: it can\'t be re-rendered');
        self::assertNull($docs[$fromStarter]['document_source_id'], 'an unedited starter had no copy to point at');

        // Running it again (a re-run, or a later migrate) changes nothing.
        IssueDocumentsFromSourcesAndDropDesigner::up($this->db);
        self::assertCount(8, $this->sources->listForTenant(self::TENANT));
    }

    /**
     * If anything can't be converted, nothing is dropped: the old templates
     * stay for an operator to look at, and the upgrade says why.
     */
    public function testTheOldTablesStayWhenTheConversionFails(): void
    {
        $this->restoreOldTables();
        $this->oldTemplate(self::TENANT, 'Our letter', ['version' => 2, 'name' => 'Our letter', 'pages' => []], 'tenant', null, true);
        // The editor's table can't be written: every conversion fails.
        $this->pdo->exec('ALTER TABLE document_sources RENAME TO document_sources_gone');

        try {
            IssueDocumentsFromSourcesAndDropDesigner::up($this->db);
            self::fail('the migration should refuse');
        } catch (\Throwable) {
            // Expected.
        }
        $this->pdo->exec('ALTER TABLE document_sources_gone RENAME TO document_sources');

        self::assertTrue($this->tableExists('document_templates'));
        self::assertTrue($this->tableExists('document_blocks'));
    }

    public function testDownRestoresTheOldTablesEmptyAndUpIsRepeatable(): void
    {
        $this->restoreOldTables();
        self::assertTrue($this->tableExists('document_templates'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM document_templates'));
        self::assertFalse($this->hasColumn('documents', 'document_source_id'), 'down() removes the columns up() added');

        IssueDocumentsFromSourcesAndDropDesigner::up($this->db);
        self::assertTrue($this->hasColumn('documents', 'document_source_id'));
        self::assertTrue($this->hasColumn('documents', 'document_source_version'));
        $this->assertTemplatesGone();
    }

    // ── the editor's starters ───────────────────────────────────────────────

    public function testTheEditorsStartersAreSeededOnceAndArePublishable(): void
    {
        $seeder = new DocumentSourceStarterSeeder($this->sources);
        self::assertSame(6, $seeder->seedForTenant(self::TENANT));
        self::assertSame(0, $seeder->seedForTenant(self::TENANT), 'once per tenant');

        $rows = $this->sources->listForTenant(self::TENANT);
        self::assertSame(
            ['Certificate', 'Exam sheet', 'Invoice', 'Letter', 'Production note', 'Shipping labels'],
            self::sorted(array_column($rows, 'title')),
        );
        foreach ($rows as $row) {
            $doc = $this->sources->findById($row['id'], self::TENANT);
            self::assertNotNull($doc);
            self::assertSame('system', $doc['scope']);
            self::assertNull($doc['published_version']);
            self::assertNotNull($doc['starter_key']);
            $this->assertPublishable($doc['blocks'], $doc['own_fields'], $doc['title']);
            if ($doc['kind'] === 'label') {
                self::assertIsArray($doc['page']['labels'] ?? null, $doc['title'] . ' has its label grid');
            }
        }

        // A renamed starter keeps its identity: it isn't seeded again.
        $this->pdo->exec("UPDATE document_sources SET title = 'Our invoice' WHERE starter_key = 'editor.invoice'");
        self::assertSame(0, $seeder->seedForTenant(self::TENANT));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** The schema as it was before migration 165: the old tables, empty. */
    private function restoreOldTables(): void
    {
        IssueDocumentsFromSourcesAndDropDesigner::down($this->db);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function oldTemplate(int $tenantId, string $name, array $data, string $scope, ?string $starterKey, bool $edited): int
    {
        $created = '2026-01-10 09:00:00';
        $stmt = $this->pdo->prepare(
            'INSERT INTO document_templates (tenant_id, name, data, scope, is_system, starter_key, created_at, updated_at)
             VALUES (:tenant_id, :name, :data, :scope, :is_system, :starter_key, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':name' => $name,
            ':data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            ':scope' => $scope,
            ':is_system' => $scope === 'system' ? 1 : 0,
            ':starter_key' => $starterKey,
            ':created_at' => $created,
            // Someone edited it a day after it was seeded.
            ':updated_at' => $edited ? '2026-01-11 09:00:00' : $created,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{name: string, elements: list<array<string, mixed>>} $block
     */
    private function oldBlock(int $tenantId, array $block): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO document_blocks (tenant_id, name, data, scope) VALUES (:tenant_id, :name, :data, 'tenant')"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':name' => $block['name'], ':data' => json_encode($block['elements'], JSON_UNESCAPED_UNICODE)]);

        return (int) $this->pdo->lastInsertId();
    }

    private function oldDocument(int $tenantId, int $templateId, string $title): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO documents (tenant_id, document_template_id, template_name, title) VALUES (:tenant_id, :template, :name, :title)'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':template' => $templateId, ':name' => $title, ':title' => $title]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>
     */
    private function copyOf(int $templateId): array
    {
        foreach ($this->sources->listForTenant(self::TENANT) as $row) {
            if ($row['legacy_template_id'] === $templateId) {
                $copy = $this->sources->findById($row['id'], self::TENANT);
                self::assertNotNull($copy);

                return $copy;
            }
        }
        self::fail('no copy of template ' . $templateId);
    }

    /**
     * @return array<int, array{document_source_id: int|null, document_source_version: int|null}>
     */
    private function documents(): array
    {
        $out = [];
        foreach ($this->rows('SELECT id, document_source_id, document_source_version FROM documents') as $row) {
            $out[(int) $row['id']] = [
                'document_source_id' => $row['document_source_id'] !== null ? (int) $row['document_source_id'] : null,
                'document_source_version' => $row['document_source_version'] !== null ? (int) $row['document_source_version'] : null,
            ];
        }

        return $out;
    }

    /**
     * Dropped on PostgreSQL; on SQLite emptied, since `documents` still names
     * it in a foreign key SQLite can't drop (see migration 165).
     */
    private function assertTemplatesGone(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            self::assertFalse($this->tableExists('document_templates'));

            return;
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM document_templates'));
    }

    private function tableExists(string $table): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            return (int) $this->scalar("SELECT CASE WHEN to_regclass('" . $table . "') IS NULL THEN 0 ELSE 1 END") === 1;
        }

        return (int) $this->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '" . $table . "'") > 0;
    }

    private function hasColumn(string $table, string $column): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_name = :t AND column_name = :c AND table_schema = current_schema()');
            $stmt->execute([':t' => $table, ':c' => $column]);

            return (int) $stmt->fetchColumn() > 0;
        }

        return in_array($column, array_column($this->rows('PRAGMA table_info(' . $table . ')'), 'name'), true);
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<array<string, mixed>> $ownFields
     */
    private function assertPublishable(array $blocks, array $ownFields, string $name): void
    {
        $paths = self::SHARED;
        foreach ($ownFields as $f) {
            $paths['template.' . $f['key']] = true;
        }
        self::assertSame([], DocumentPublishCheck::blockers($blocks, $paths), $name . ': every field is offered and every block prints');
        foreach ($blocks as $b) {
            self::assertContains($b['type'], WhityDocumentPrinter::PRINTABLE, $name);
        }
        self::assertSame(count($blocks), count(array_unique(array_column($blocks, 'id'))), $name . ': ids are unique');
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
