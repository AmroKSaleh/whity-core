<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;

/**
 * Real-engine tests for the repositories documents are issued through since
 * editor M4c: the editor's templates (`document_sources`, published versions
 * in `document_source_versions`) and the issued records (`documents`), which
 * now say which template and which VERSION they were issued from.
 *
 * The old designer's repositories (`document_templates`, `document_blocks`)
 * are gone with their tables (migration 165); what replaced their tests here
 * is the same two kinds of claim about the new tables — tenant isolation, and
 * that what is written is what is read back, on both engines.
 */
final class DocumentRepositoryRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private DocumentSourceRepository $sources;
    private DocumentSourceVersionRepository $versions;
    private DocumentRepository $documents;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Clinic', 'example-clinic')");
        $this->sources = new DocumentSourceRepository($this->pdo);
        $this->versions = new DocumentSourceVersionRepository($this->pdo);
        $this->documents = new DocumentRepository($this->pdo);
    }

    /** @param array{starter_key?: string, legacy_template_id?: int, own_fields?: list<array<string, string>>} $extra */
    private function template(int $tenantId, string $title, array $extra = []): int
    {
        return $this->sources->create($tenantId, $extra + [
            'kind' => 'template',
            'title' => $title,
            'lang' => 'en',
            'default_view' => 'page',
            'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $title]]]],
            'scope' => 'tenant',
            'created_by' => null,
        ]);
    }

    // ── templates ───────────────────────────────────────────────────────────

    /**
     * Where a template came from survives the round trip: Whity's starter
     * (`starter_key`, which the starter seeder reads to stay idempotent) and
     * the old designer template it was converted from (`legacy_template_id`,
     * which conversion reads for the same reason).
     */
    public function testATemplateCarriesItsStarterAndLegacyOrigin(): void
    {
        $starter = $this->template(self::TENANT_A, 'Payment reminder', ['starter_key' => 'payment-reminder']);
        $converted = $this->template(self::TENANT_A, 'Faculty circular', ['legacy_template_id' => 41]);
        $plain = $this->template(self::TENANT_A, 'Delivery note');

        $row = $this->sources->findById($starter, self::TENANT_A);
        self::assertNotNull($row);
        self::assertSame('payment-reminder', $row['starter_key']);
        self::assertNull($row['legacy_template_id']);

        $row = $this->sources->findById($converted, self::TENANT_A);
        self::assertNotNull($row);
        self::assertSame(41, $row['legacy_template_id']);
        self::assertNull($row['starter_key']);

        $row = $this->sources->findById($plain, self::TENANT_A);
        self::assertNotNull($row);
        self::assertNull($row['starter_key']);
        self::assertNull($row['legacy_template_id']);

        self::assertSame(['payment-reminder' => true], $this->sources->starterKeys(self::TENANT_A));
        self::assertSame([41 => true], $this->sources->convertedTemplateIds(self::TENANT_A));
        self::assertSame([], $this->sources->starterKeys(self::TENANT_B), 'per tenant');
    }

    public function testATemplateIsTenantIsolated(): void
    {
        $id = $this->template(self::TENANT_A, 'Payment reminder');

        self::assertNotNull($this->sources->findById($id, self::TENANT_A));
        self::assertNull($this->sources->findById($id, self::TENANT_B));
        self::assertNull($this->versions->publish($id, self::TENANT_B, 1, 1), 'another tenant cannot publish it');
        self::assertNull($this->versions->find($id, self::TENANT_B, 1));
    }

    /**
     * Publishing copies the draft as it was at the checked version, and a
     * stale version publishes nothing — so what was checked is what is issued.
     */
    public function testPublishingCopiesTheCheckedDraftOnly(): void
    {
        $id = $this->template(self::TENANT_A, 'Payment reminder', [
            'own_fields' => [['key' => 'reference', 'label' => 'Reference']],
        ]);

        self::assertSame(1, $this->versions->publish($id, self::TENANT_A, 1, 1));
        self::assertNull($this->versions->publish($id, self::TENANT_A, 7, 1), 'a draft version that is not the current one');

        $version = $this->versions->find($id, self::TENANT_A, 1);
        self::assertNotNull($version);
        self::assertSame('Payment reminder', $version['title']);
        self::assertSame('Payment reminder', $version['blocks'][0]['content'][0]['text']);
        self::assertSame([['key' => 'reference', 'label' => 'Reference']], $version['own_fields']);

        $row = $this->sources->findById($id, self::TENANT_A);
        self::assertNotNull($row);
        self::assertSame(1, $row['published_version']);
    }

    // ── issued documents ────────────────────────────────────────────────────

    /**
     * A document records the template and the version it was issued from —
     * what a correction re-renders — on the single read and on the list, and
     * is invisible from another tenant.
     */
    public function testADocumentRecordsItsTemplateAndVersion(): void
    {
        $source = $this->template(self::TENANT_A, 'Payment reminder');
        $id = $this->documents->create(self::TENANT_A, [
            'document_source_id' => $source,
            'document_source_version' => 3,
            'template_name' => 'Payment reminder',
            'title' => 'Payment reminder · Example Clinic',
            'variable_data' => [['template.reference' => 'INV-2026-0042']],
        ]);

        $row = $this->documents->findById($id, self::TENANT_A);
        self::assertNotNull($row);
        self::assertSame($source, $row['document_source_id']);
        self::assertSame(3, $row['document_source_version']);
        self::assertNull($row['document_template_id'], 'the old designer is not involved');
        self::assertSame([['template.reference' => 'INV-2026-0042']], $row['variable_data']);

        self::assertNull($this->documents->findById($id, self::TENANT_B));
    }

    /**
     * A document raised by a form or a plugin names no template, and says so
     * with nulls rather than zeros: a zero would read as "template 0".
     */
    public function testADocumentWithNoTemplateRecordsNulls(): void
    {
        $id = $this->documents->create(self::TENANT_A, ['template_name' => 'Leave request', 'title' => 'Leave request 12']);

        $row = $this->documents->findById($id, self::TENANT_A);
        self::assertNotNull($row);
        self::assertNull($row['document_source_id']);
        self::assertNull($row['document_source_version']);
        self::assertNull($row['variable_data'], 'nothing recorded is not the same as an empty row');
    }

    /**
     * Arabic values are stored as Arabic, not as `\u` escapes: they decode
     * either way, but the column has to stay readable to an operator querying
     * it, and Arabic content is a first-class requirement.
     */
    public function testArabicValuesAreStoredReadably(): void
    {
        $id = $this->documents->create(self::TENANT_A, [
            'template_name' => 'Payment reminder',
            'title' => 'تذكير بالدفع',
            'variable_data' => [['template.customer' => 'مخبز المثال']],
        ]);

        $stmt = $this->pdo->prepare('SELECT variable_data FROM documents WHERE id = ?');
        $stmt->execute([$id]);
        self::assertStringContainsString('مخبز المثال', (string) $stmt->fetchColumn());
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
