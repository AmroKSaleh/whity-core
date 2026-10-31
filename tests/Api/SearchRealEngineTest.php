<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Form\FormRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Search\CoreSearchSources;
use Whity\Core\Search\DocumentsSearchSource;
use Whity\Core\Search\SearchContext;
use Whity\Core\Search\SearchService;
use Whity\Core\Text\SearchText;

/**
 * `GET /api/search` on a real engine (WHIT-783): Arabic folding in SQL agrees
 * with the PHP fold, each type is searched only with its list permission, the
 * document rule matches the document list's, and no tenant leaks into another.
 */
final class SearchRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER  = 2;
    private const ME     = 101;
    private const PEER   = 202;
    private const ROLE   = 301;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->seed();
    }

    // ---- folding ----

    public function testTheSqlFoldAgreesWithThePhpFold(): void
    {
        $samples = ['أحمد', 'إبراهيم', 'آمنة', 'مدرسة', 'مستشفى', 'مُحَمَّد', 'العـــربية', 'ٱلله', 'Example Bakery', 'INVOICE'];
        $expr    = SearchText::sqlFold(':value');
        foreach ($samples as $sample) {
            $stmt = $this->pdo->prepare('SELECT ' . $expr . ' AS folded');
            $stmt->execute([':value' => $sample]);
            self::assertSame(SearchText::fold($sample), $stmt->fetchColumn(), $sample);
        }
    }

    public function testTheQueryFoldsDigitsAndSpaces(): void
    {
        self::assertSame('فاتوره 2026', SearchText::fold('  فاتورة   ٢٠٢٦ '));
        self::assertSame('احمد', SearchText::fold('أَحْمَد'));
    }

    // ---- what each caller sees ----

    public function testArabicVariantsFindEachOther(): void
    {
        $results = $this->search('مدرسه احمد', [CorePermissions::DOCUMENTS_READ, CorePermissions::DOCUMENTS_READ_ALL]);

        self::assertSame(['document'], array_column($results, 'type'));
        self::assertSame(['مدرسة أحمد الابتدائية'], array_column($results[0]['results'], 'title'));
    }

    public function testATypeIsSearchedOnlyWithItsListPermission(): void
    {
        $everything = [CorePermissions::DOCUMENTS_READ, CorePermissions::DOCUMENTS_READ_ALL, CorePermissions::FORMS_READ, CorePermissions::USERS_READ, CorePermissions::OUS_READ];

        self::assertSame(['document', 'form', 'person', 'ou'], array_column($this->search('example', $everything), 'type'));
        self::assertSame(['form'], array_column($this->search('example', [CorePermissions::FORMS_READ]), 'type'));
        self::assertSame([], $this->search('example', []));
    }

    public function testWithoutReadAllOnlyDocumentsTheCallerMaySeeAreFound(): void
    {
        $titles = array_column($this->search('example', [CorePermissions::DOCUMENTS_READ])[0]['results'] ?? [], 'title');

        // Mine, and the one granted to me; not the peer's private memo.
        self::assertEqualsCanonicalizing(['Example Bakery invoice', 'Example Bakery receipt'], $titles);
    }

    public function testNothingFromAnotherTenantIsFound(): void
    {
        $everything = [CorePermissions::DOCUMENTS_READ, CorePermissions::DOCUMENTS_READ_ALL, CorePermissions::FORMS_READ, CorePermissions::USERS_READ, CorePermissions::OUS_READ];

        self::assertSame([], $this->search('elsewhere', $everything));
    }

    public function testFormsMatchEitherLanguageAndPeopleMatchNameOrEmail(): void
    {
        $forms  = $this->search('طلب اجازه', [CorePermissions::FORMS_READ]);
        $people = $this->search('rana@example', [CorePermissions::USERS_READ]);

        self::assertSame('Leave request', $forms[0]['results'][0]['title']);
        self::assertSame(['Rana Example'], array_column($people[0]['results'], 'title'));
    }

    public function testShortQueriesAndWildcardsFindNothing(): void
    {
        $everything = [CorePermissions::DOCUMENTS_READ, CorePermissions::DOCUMENTS_READ_ALL];

        self::assertSame([], $this->search('a', $everything));
        self::assertSame([], $this->search('%%', $everything));
        self::assertSame([], $this->search('__', $everything));
    }

    public function testTheTypesFilterAndLimitApply(): void
    {
        $everything = [CorePermissions::DOCUMENTS_READ, CorePermissions::DOCUMENTS_READ_ALL, CorePermissions::FORMS_READ];
        $service    = new SearchService(fn (): array => CoreSearchSources::all($this->pdo));
        $context    = $this->context($everything);

        $only = $service->search($context, 'example', ['form']);
        self::assertSame(['form'], array_column($only, 'type'));

        $one = $service->search($context, 'example', ['document'], 1);
        self::assertCount(1, $one[0]['results']);
    }

    /**
     * The search's document rule restates DocumentRepository::VISIBLE_TO_CALLER;
     * this pins that the restatement and the original still agree.
     */
    public function testTheDocumentRuleStillMirrorsTheDocumentRepository(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Core/Document/DocumentRepository.php');
        self::assertIsString($source);
        foreach (['document_route_recipients r', 'r.document_id = documents.id', 'resource_role_assignments rra', "rra.resource_type = 'document'", 'rra.resource_id = documents.id'] as $clause) {
            self::assertStringContainsString($clause, $source, "DocumentRepository no longer has `{$clause}`; update DocumentsSearchSource to match");
        }
        self::assertSame('document', (new DocumentsSearchSource($this->pdo))->type());
    }

    /**
     * @param list<string> $permissions
     * @return list<array<string, mixed>>
     */
    private function search(string $q, array $permissions): array
    {
        return (new SearchService(fn (): array => CoreSearchSources::all($this->pdo)))
            ->search($this->context($permissions), $q);
    }

    /** @param list<string> $permissions */
    private function context(array $permissions): SearchContext
    {
        return new SearchContext(self::TENANT, self::ME, static fn (string $p): bool => in_array($p, $permissions, true));
    }

    private function seed(): void
    {
        $pdo = $this->pdo;
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Elsewhere', 'elsewhere')");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (" . self::ROLE . ", 'member', '', 0, CURRENT_TIMESTAMP)");
        $profiles = [self::ME => 'Rana Example', self::PEER => 'Omar Example', 303 => 'Elsewhere Person'];
        foreach ($profiles as $id => $name) {
            $pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                           VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$id, $name]);
        }
        $pdo->exec("INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES
                    (" . self::ME . ", 'rana@example.com', true, true, CURRENT_TIMESTAMP),
                    (" . self::PEER . ", 'omar@example.com', true, true, CURRENT_TIMESTAMP),
                    (303, 'someone@elsewhere.com', true, true, CURRENT_TIMESTAMP)");
        $membership = $pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, status, is_primary, created_at) VALUES (?, ?, ?, 'active', true, CURRENT_TIMESTAMP)");
        $membership->execute([self::ME, self::TENANT, self::ROLE]);
        $membership->execute([self::PEER, self::TENANT, self::ROLE]);
        $membership->execute([303, self::OTHER, self::ROLE]);

        $document = $pdo->prepare('INSERT INTO documents (tenant_id, template_name, title, created_by, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)');
        $document->execute([self::TENANT, 'Invoice', 'Example Bakery invoice', self::ME]);
        $document->execute([self::TENANT, 'Letter', 'مدرسة أحمد الابتدائية', self::PEER]);
        $document->execute([self::TENANT, 'Receipt', 'Example Bakery receipt', self::PEER]);
        $grantedTo = (int) $pdo->lastInsertId();
        $document->execute([self::TENANT, 'Memo', 'Example Bakery private memo', self::PEER]);
        $document->execute([self::OTHER, 'Invoice', 'Elsewhere invoice', 303]);
        $this->grantTo($grantedTo, self::ME);

        $forms = new FormRepository($pdo);
        $forms->create(self::TENANT, 'leave', ['en' => 'Leave request', 'ar' => 'طلب إجازة'], null, null, self::ME);
        $forms->create(self::TENANT, 'example-feedback', ['en' => 'Example feedback', 'ar' => 'ملاحظات'], null, null, self::ME);
        $forms->create(self::OTHER, 'elsewhere', ['en' => 'Elsewhere form', 'ar' => 'نموذج'], null, null, 303);

        $ou = $pdo->prepare('INSERT INTO organizational_units (tenant_id, parent_id, name, slug, created_at) VALUES (?, NULL, ?, ?, CURRENT_TIMESTAMP)');
        $ou->execute([self::TENANT, 'Example Bakery kitchen', 'kitchen']);
        $ou->execute([self::OTHER, 'Elsewhere office', 'office']);

        SchemaFromMigrations::syncSequences($pdo);
    }

    /** Grant $profileId a role on one document, as a resource role assignment. */
    private function grantTo(int $documentId, int $profileId): void
    {
        $this->pdo->prepare("INSERT INTO resource_role_assignments (tenant_id, resource_type, resource_id, profile_id, role_id, created_at) VALUES (?, 'document', ?, ?, ?, CURRENT_TIMESTAMP)")
            ->execute([self::TENANT, $documentId, $profileId, self::ROLE]);
    }
}
