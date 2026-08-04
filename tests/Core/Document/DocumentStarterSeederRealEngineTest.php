<?php

declare(strict_types=1);

namespace Tests\Core\Document;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Document\DocumentStarterSeeder;
use Whity\Core\Document\DocumentTemplateRepository;

/**
 * Real-engine tests for {@see DocumentStarterSeeder} (WC-515 REMAINING #3):
 * per-tenant starter document/label seeding at tenant creation.
 *
 * Covers: the right rows get created (count, is_system/scope), the
 * company_name placeholder is pre-filled with the tenant's real name, tenant
 * isolation, idempotency (no duplicates on a re-run), upgrade-safety (a user's
 * edit to a system starter survives a re-seed; a starter missing for a tenant
 * — simulating one added in a later release — gets backfilled without
 * touching the others), and that a seeding failure never throws (so it can
 * never turn a successful tenant-creation request into a 500).
 */
final class DocumentStarterSeederRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private DocumentTemplateRepository $templates;
    private DocumentBlockRepository $blocks;
    private DocumentStarterSeeder $seeder;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Acme Rentals', 'acme'), (2, 'Other Co', 'other')");
        $this->templates = new DocumentTemplateRepository($this->pdo);
        $this->blocks = new DocumentBlockRepository($this->pdo);
        $this->seeder = new DocumentStarterSeeder($this->templates, $this->blocks);
    }

    public function testSeedsFourSystemTemplatesAndTwoSystemBlocks(): void
    {
        $this->seeder->seedForTenant(self::TENANT_A, 'Acme Rentals');

        $templates = $this->templates->listForTenant(self::TENANT_A);
        $blocks = $this->blocks->listForTenant(self::TENANT_A);

        self::assertCount(4, $templates, 'Invoice/Exam sheet/Production note/Shipping label');
        self::assertCount(2, $blocks, 'Company header/footer');

        foreach ($templates as $row) {
            self::assertTrue($row['is_system'], $row['name'] . ' must be is_system');
            self::assertSame('system', $row['scope']);
            self::assertNull($row['created_by']);
        }
        foreach ($blocks as $row) {
            self::assertTrue($row['is_system']);
            self::assertSame('system', $row['scope']);
        }

        self::assertSame(
            ['Exam sheet', 'Invoice', 'Production note', 'Shipping label'],
            self::sortedNames($templates)
        );
        self::assertSame(['Company footer', 'Company header'], self::sortedNames($blocks));
    }

    public function testCompanyNamePlaceholderSampleIsPreFilledWithTheTenantsRealName(): void
    {
        $this->seeder->seedForTenant(self::TENANT_A, 'Acme Rentals');

        $byName = self::indexByName($this->templates->listForTenant(self::TENANT_A));

        foreach (['Invoice', 'Production note', 'Shipping label'] as $name) {
            $sample = self::placeholderSample($byName[$name]['data'], 'company_name');
            self::assertSame('Acme Rentals', $sample, "{$name}'s company_name sample must be the tenant's real name");
        }

        // The invoice's company_contact sample also carries the real name
        // (it originally hardcoded a fixed generic company name alongside the
        // contact details in starters.ts).
        $contactSample = self::placeholderSample($byName['Invoice']['data'], 'company_contact');
        self::assertStringContainsString('Acme Rentals', $contactSample);

        // The exam sheet has no company_name placeholder at all — nothing to
        // pre-fill, and it must not gain one.
        self::assertNull(self::findPlaceholder($byName['Exam sheet']['data'], 'company_name'));
    }

    public function testBlocksCarryNoPlaceholderSampleData(): void
    {
        // Blocks have no placeholders/sample list of their own (only the
        // containing template does) — the {{company_name}} token in the
        // header/footer elements stays a literal token, resolved later by
        // whatever document uses the block instance.
        $this->seeder->seedForTenant(self::TENANT_A, 'Acme Rentals');

        $byName = self::indexByName($this->blocks->listForTenant(self::TENANT_A));
        $headerJson = json_encode($byName['Company header']['data']);
        self::assertIsString($headerJson);
        self::assertStringContainsString('{{company_name}}', $headerJson);
    }

    public function testSeedingIsTenantIsolated(): void
    {
        $this->seeder->seedForTenant(self::TENANT_A, 'Acme Rentals');

        self::assertCount(0, $this->templates->listForTenant(self::TENANT_B));
        self::assertCount(0, $this->blocks->listForTenant(self::TENANT_B));
    }

    public function testReseedingDoesNotDuplicate(): void
    {
        $this->seeder->seedForTenant(self::TENANT_A, 'Acme Rentals');
        $this->seeder->seedForTenant(self::TENANT_A, 'Acme Rentals');

        self::assertCount(4, $this->templates->listForTenant(self::TENANT_A));
        self::assertCount(2, $this->blocks->listForTenant(self::TENANT_A));
    }

    public function testReseedingDoesNotClobberAUsersEditToASystemStarter(): void
    {
        $this->seeder->seedForTenant(self::TENANT_A, 'Acme Rentals');

        $byName = self::indexByName($this->templates->listForTenant(self::TENANT_A));
        $invoiceId = $byName['Invoice']['id'];

        // The user renames their copy and edits its content — a system
        // starter is fully editable (WC-521/WC-515: "never an empty
        // document", not "never an editable one").
        $editedData = $byName['Invoice']['data'];
        $editedData['name'] = 'Invoice (custom layout)';
        $this->templates->update($invoiceId, self::TENANT_A, [
            'name' => 'My custom invoice',
            'data' => $editedData,
        ]);

        // Re-run seeding (e.g. a retried tenant.created dispatch).
        $this->seeder->seedForTenant(self::TENANT_A, 'Acme Rentals');

        $after = $this->templates->listForTenant(self::TENANT_A);
        self::assertCount(4, $after, 'no duplicate Invoice must be created for the renamed row');

        $row = $this->templates->findById($invoiceId, self::TENANT_A);
        self::assertNotNull($row);
        self::assertSame('My custom invoice', $row['name'], "the user's rename must survive a re-seed");
        self::assertSame('Invoice (custom layout)', $row['data']['name'], "the user's content edit must survive a re-seed");
    }

    public function testReseedingBackfillsOnlyAMissingStarterWithoutTouchingTheOthers(): void
    {
        // Simulate the "upgrade adds/needs a starter" scenario: a tenant that
        // is missing one starter (as if seeded by an earlier release, or the
        // row was removed) — re-seeding must recreate ONLY the missing one and
        // leave the rest exactly as they are (same row ids).
        $this->seeder->seedForTenant(self::TENANT_A, 'Acme Rentals');
        $before = self::indexByName($this->templates->listForTenant(self::TENANT_A));

        $this->templates->delete($before['Shipping label']['id'], self::TENANT_A);
        self::assertCount(3, $this->templates->listForTenant(self::TENANT_A));

        $this->seeder->seedForTenant(self::TENANT_A, 'Acme Rentals');

        $after = self::indexByName($this->templates->listForTenant(self::TENANT_A));
        self::assertCount(4, $after);
        self::assertArrayHasKey('Shipping label', $after, 'the missing starter must be backfilled');

        // The untouched starters keep their original row id (proof they were
        // never deleted/recreated by the re-seed).
        foreach (['Invoice', 'Exam sheet', 'Production note'] as $name) {
            self::assertSame($before[$name]['id'], $after[$name]['id'], "{$name} must not be recreated");
        }
    }

    public function testStarterKeyColumnIsSetAndDistinctPerStarter(): void
    {
        $this->seeder->seedForTenant(self::TENANT_A, 'Acme Rentals');

        $stmt = $this->pdo->prepare('SELECT starter_key FROM document_templates WHERE tenant_id = :t');
        $stmt->execute([':t' => self::TENANT_A]);
        $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);

        self::assertCount(4, array_unique($keys), 'every seeded template must have a distinct, non-null starter_key');
        self::assertNotContains(null, $keys);

        $stmt = $this->pdo->prepare('SELECT starter_key FROM document_blocks WHERE tenant_id = :t');
        $stmt->execute([':t' => self::TENANT_A]);
        $blockKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, array_unique($blockKeys));
    }

    public function testASeedingFailureIsSwallowedAndNeverThrows(): void
    {
        // A PDO with none of the production migrations applied: every query
        // the seeder issues (starterKeysForTenant, create) fails against a
        // missing table. seedForTenant() must swallow that (logged, not
        // rethrown) — a seeding problem must never be capable of turning a
        // successful tenant-creation request into a 500.
        $brokenPdo = new PDO('sqlite::memory:');
        $brokenPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $seeder = new DocumentStarterSeeder(
            new DocumentTemplateRepository($brokenPdo),
            new DocumentBlockRepository($brokenPdo)
        );

        $seeder->seedForTenant(999, 'Doomed Co');
        $this->addToAssertionCount(1); // reaching this line means it did not throw
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private static function indexByName(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['name']] = $row;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function sortedNames(array $rows): array
    {
        $names = array_map(static fn (array $r): string => (string) $r['name'], $rows);
        sort($names);

        return $names;
    }

    /**
     * @param array<string, mixed> $templateData
     * @return array<string, mixed>|null
     */
    private static function findPlaceholder(array $templateData, string $key): ?array
    {
        foreach ($templateData['placeholders'] ?? [] as $p) {
            if (($p['key'] ?? null) === $key) {
                return $p;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $templateData
     */
    private static function placeholderSample(array $templateData, string $key): string
    {
        $p = self::findPlaceholder($templateData, $key);
        self::assertNotNull($p, "placeholder '{$key}' must exist");

        return (string) $p['sample'];
    }
}
