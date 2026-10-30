<?php

declare(strict_types=1);

namespace Tests\Core\Document\Legacy;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceStarterSeeder;
use Whity\Core\Document\DocumentStarterSeeder;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Document\Legacy\LegacyTemplateConverter;
use Whity\Core\Document\Legacy\LegacyTemplateMigrator;
use Whity\Core\Document\Print\DocumentPublishCheck;
use Whity\Core\Document\Print\WhityDocumentPrinter;

/**
 * Editor M4b: the old Document designer's templates become editor documents,
 * and the editor's own starters are seeded. A converted document must be one
 * the editor can publish once a person has checked it: every field it uses is
 * offered, and every block has a print view.
 */
final class LegacyConversionRealEngineTest extends TestCase
{
    private const TENANT = 1;

    /** The fields every template is offered, whatever its record type. */
    private const SHARED = ['company.name' => true, 'company.address' => true, 'company.tax_number' => true, 'today' => true];

    private PDO $pdo;
    private DocumentTemplateRepository $templates;
    private DocumentSourceRepository $sources;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Clinic', 'clinic')");
        $this->templates = new DocumentTemplateRepository($this->pdo);
        $this->sources = new DocumentSourceRepository($this->pdo);
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
            'the note first, then top to bottom; the rectangle and the saved block are left out',
        );
        self::assertSame(['shape' => 1, 'savedBlock' => 1], $doc['dropped']);
        self::assertStringContainsString('rectangles and lines (1)', $doc['blocks'][0]['content'][0]['text']);

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
        (new DocumentStarterSeeder($this->templates, new DocumentBlockRepository($this->pdo)))->seedForTenant(self::TENANT, 'Example Bakery');
        $converter = new LegacyTemplateConverter();

        $rows = $this->templates->listForTenant(self::TENANT);
        self::assertCount(4, $rows);
        foreach ($rows as $row) {
            $doc = $converter->convert($row['name'], $row['data']);
            $blocks = array_values(array_filter($doc['blocks'], static fn (array $b): bool => $b['type'] !== 'callout'));
            self::assertNotSame([], $blocks, $row['name']);
            $this->assertPublishable($doc['blocks'], $doc['own_fields'], $row['name']);
        }
    }

    // ── migrator ────────────────────────────────────────────────────────────

    public function testTheMigratorCopiesEachTemplateOnceAndSkipsUneditedStarters(): void
    {
        (new DocumentStarterSeeder($this->templates, new DocumentBlockRepository($this->pdo)))->seedForTenant(self::TENANT, 'Example Bakery');
        $starters = $this->templates->listForTenant(self::TENANT);
        $edited = $starters[0];
        $this->pdo->prepare("UPDATE document_templates SET updated_at = datetime(created_at, '+1 day') WHERE id = ?")->execute([$edited['id']]);
        $mine = $this->templates->create(self::TENANT, [
            'name' => 'Our letter',
            'data' => ['version' => 2, 'name' => 'Our letter', 'placeholders' => [], 'page' => ['widthMm' => 210, 'heightMm' => 297, 'marginMm' => 10, 'background' => '#fff'],
                'pages' => [['id' => 'p', 'elements' => [['id' => 'a', 'type' => 'text', 'x' => 1, 'y' => 1, 'w' => 50, 'h' => 8, 'rotation' => 0, 'z' => 1, 'text' => 'Hello', 'style' => ['fontSize' => 11]]]]]],
            'scope' => 'tenant',
            'created_by' => null,
        ]);

        $migrator = new LegacyTemplateMigrator($this->templates, $this->sources);
        self::assertSame(0, $migrator->migrateTenant(self::TENANT, true)['converted'] - 2, 'a dry run counts the same');
        self::assertCount(0, $this->sources->listForTenant(self::TENANT), 'and writes nothing');

        $summary = $migrator->migrateTenant(self::TENANT);
        self::assertSame(['converted' => 2, 'skipped' => 3, 'failed' => 0], array_intersect_key($summary, array_flip(['converted', 'skipped', 'failed'])));
        self::assertSame([$edited['id'] => true, $mine => true], $this->sources->convertedTemplateIds(self::TENANT) + [], 'the edited starter and the tenant\'s own');

        $copy = null;
        foreach ($this->sources->listForTenant(self::TENANT) as $row) {
            if ($row['title'] === 'Our letter') {
                $copy = $this->sources->findById($row['id'], self::TENANT);
            }
        }
        self::assertNotNull($copy);
        self::assertSame('tenant', $copy['scope'], 'who can see it carries over');
        self::assertNull($copy['published_version'], 'a draft until someone publishes it');
        self::assertSame('Hello', $copy['blocks'][0]['content'][0]['text']);

        self::assertSame(0, $migrator->migrateTenant(self::TENANT)['converted'], 'running again adds nothing');
        self::assertCount(5, $this->templates->listForTenant(self::TENANT), 'the old templates are untouched');
        self::assertSame([], $this->sources->listForTenant(2), 'another tenant is not touched');
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
            $this->assertPublishable($doc['blocks'], $doc['own_fields'], $doc['title']);
            if ($doc['kind'] === 'label') {
                self::assertIsArray($doc['page']['labels'] ?? null, $doc['title'] . ' has its label grid');
            }
        }

        // A renamed starter keeps its identity: it isn't seeded again.
        $this->pdo->exec("UPDATE document_sources SET title = 'Our invoice' WHERE starter_key = 'editor.invoice'");
        self::assertSame(0, $seeder->seedForTenant(self::TENANT));
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
