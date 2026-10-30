<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeRenderServiceClient;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentPublishingApiHandler;
use Whity\Api\DocumentSourcesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentSourceFileStore;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Embed\IssuedDocumentRecordProvider;
use Whity\Core\Document\Fields\CompanyFieldProvider;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Fields\IssuedDocumentFieldProvider;
use Whity\Core\Document\Fields\TodayFieldProvider;
use Whity\Core\Document\Render\FlowDocumentRenderer;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Response;
use Whity\Storage\LocalStorageDriver;

/**
 * Real-engine tests for {@see DocumentPublishingApiHandler} (editor M4):
 * templates from draft to paper. Runs on SQLite by default and on PostgreSQL
 * when PHPUNIT_PG_DSN is set (Tests\Support\SchemaFromMigrations).
 *
 * What matters most:
 *
 * - Publishing is exactly what was checked: the check's ETag is the draft
 *   version, Publish needs it as If-Match, and a save in between is a 409.
 * - A template with a field its record type doesn't have, or a block with no
 *   print view, can't be published (E.37), even by calling Publish directly.
 * - Preview and render read the record for the person asking; a record they
 *   can't see is the same 404 as one that doesn't exist (E.12).
 * - A required field with no value refuses the render (E.27), and the PDF
 *   carries the values as they were at that moment (E.16).
 */
final class DocumentPublishingApiHandlerRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    private const OWNER  = 10; // admin role: read, write, publish, render
    private const VIEWER = 11; // read only
    private const WRITER = 12; // read + write, no publish
    private const FOREIGN = 20; // the other tenant

    private PDO $pdo;
    private DocumentSourcesApiHandler $sources;
    private DocumentPublishingApiHandler $handler;
    private FakeRenderServiceClient $render;
    private SettingsService $settings;
    private string $storageRoot;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $this->storageRoot = sys_get_temp_dir() . '/docpublish-' . bin2hex(random_bytes(6));
        mkdir($this->storageRoot, 0o777, true);
        $roleChecker = new RoleChecker($this->wrap($this->pdo), new PermissionRegistry());
        $resourceRoles = new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry());
        $files = new DocumentSourceFileStore(new LocalStorageDriver($this->storageRoot), $this->pdo);
        $this->sources = new DocumentSourcesApiHandler(
            new DocumentSourceRepository($this->pdo),
            new DocumentAccessPolicy(),
            $roleChecker,
            new OuReachResolver($this->pdo, $resourceRoles),
            $files,
        );

        $documents = new DocumentRepository($this->pdo);
        $visibility = new DocumentVisibilityPolicy(new RouteRecipientRepository($this->pdo), $resourceRoles);
        $embeds = new DocumentEmbedRegistry();
        $embeds->addRecordProvider(new IssuedDocumentRecordProvider($documents, $visibility));

        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');

        $fields = new DocumentFieldRegistry();
        $fields->add(new IssuedDocumentFieldProvider($documents, $visibility));
        $fields->add(new CompanyFieldProvider($this->settings));
        $fields->add(new TodayFieldProvider(null, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-29 10:00:00')));

        $this->render = new FakeRenderServiceClient();
        $this->handler = new DocumentPublishingApiHandler(
            $this->sources,
            new DocumentSourceVersionRepository($this->pdo),
            $fields,
            $embeds,
            $roleChecker,
            $this->settings,
            new FlowDocumentRenderer($this->settings, $this->render),
            new DocumentIssuer($this->pdo, $documents, new DocumentArtifactRepository($this->pdo), new DocumentArtifactStore(new LocalStorageDriver($this->storageRoot))),
            $files,
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
        if (is_dir($this->storageRoot)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->storageRoot);
        }
    }

    // ── fields ──────────────────────────────────────────────────────────────

    public function testFieldsListTheRecordTypeOwnGroupFirstThenCompanyAndToday(): void
    {
        $this->settings->setTenant(self::TENANT, SettingsRegistry::BILLING_SELLER_NAME, 'Example Bakery');
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_PRINT_COLOR, '#B74C05');

        $res = $this->handler->fields($this->request('GET', '/api/document-fields?recordType=document', self::VIEWER));
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $data = $this->data($res);
        self::assertSame([['key' => 'document', 'label' => 'Document']], $data['record_types']);
        self::assertSame(['Document', 'Company', 'Today'], array_column($data['groups'], 'group'));
        self::assertSame('Example Bakery', $data['groups'][1]['items'][0]['sample']);
        self::assertTrue($data['groups'][1]['items'][2]['required'], 'the company tax number is fiscal, so required');
        self::assertSame('29 October 2026', $data['groups'][2]['items'][0]['sample']);
        self::assertSame('Example Bakery', $data['letterhead']['name']);
        self::assertSame('#B74C05', $data['accent']);

        $none = $this->data($this->handler->fields($this->request('GET', '/api/document-fields', self::VIEWER)));
        self::assertSame(['Company', 'Today'], array_column($none['groups'], 'group'), 'no record type: only the shared groups');
    }

    public function testRecordsToPreviewWithAreOnlyTheOnesTheCallerMaySee(): void
    {
        $mine = $this->issue(self::WRITER, 'Payment reminder');
        $this->issue(self::OWNER, 'Owner only');

        $res = $this->handler->records($this->request('GET', '/api/document-fields/records?recordType=document', self::WRITER));
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertSame([['id' => (string) $mine, 'label' => '#' . $mine . ' · Payment reminder']], $this->data($res));

        $search = $this->data($this->handler->records($this->request('GET', '/api/document-fields/records?recordType=document&q=nothing', self::WRITER)));
        self::assertSame([], $search);
        self::assertSame(404, $this->handler->records($this->request('GET', '/api/document-fields/records?recordType=invoice', self::WRITER))->getStatusCode());
    }

    // ── publish ─────────────────────────────────────────────────────────────

    public function testPublishingNeedsTheCheckedDraftVersionAndKeepsEveryVersion(): void
    {
        $id = $this->template(self::OWNER, [$this->para('Dear ', $this->field('document.title'))]);

        $check = $this->check(self::OWNER, $id);
        self::assertSame(200, $check->getStatusCode(), $check->getBody());
        self::assertSame([], $this->data($check));
        $etag = (string) self::header($check, 'ETag');
        self::assertSame('"1"', $etag);

        self::assertSame(428, $this->publish(self::OWNER, $id, null)->getStatusCode(), 'publishing needs the checked version');

        $first = $this->publish(self::OWNER, $id, $etag);
        self::assertSame(201, $first->getStatusCode(), $first->getBody());
        self::assertSame(1, $this->data($first)['published_version']);

        // A save after the check means the checked version is gone: refused, not published unchecked.
        $this->save(self::OWNER, $id, '"1"', ['title' => 'Reminder, second edition']);
        self::assertSame(409, $this->publish(self::OWNER, $id, '"1"')->getStatusCode());

        self::assertSame(201, $this->publish(self::OWNER, $id, '"2"')->getStatusCode());
        $versions = $this->data($this->handler->history($this->request('GET', "/api/document-sources/{$id}/versions", self::OWNER), ['id' => (string) $id]));
        self::assertSame([2, 1], array_column($versions, 'version'));
        self::assertSame(['Reminder, second edition', 'Payment reminder'], array_column($versions, 'title'));

        $shown = json_decode($this->sources->show($this->request('GET', "/api/document-sources/{$id}", self::OWNER), ['id' => (string) $id])->getBody(), true)['data'];
        self::assertSame(2, $shown['published_version']);
    }

    public function testAnUnknownFieldOrABlockWithNoPrintViewBlocksPublishing(): void
    {
        $id = $this->template(self::OWNER, [
            $this->para('Dear ', $this->field('customer.vat')),
            ['id' => 'p1', 'type' => 'plugin', 'plugin' => 'com.example.maps', 'blockType' => 'route', 'data' => []],
            ['id' => 't1', 'type' => 'toggle', 'open' => true, 'summary' => [], 'children' => [
                ['id' => 'p2', 'type' => 'plugin', 'plugin' => 'com.example.maps', 'blockType' => 'route', 'data' => []],
            ]],
        ]);

        $blockers = $this->data($this->check(self::OWNER, $id));
        self::assertSame([
            ['kind' => 'unknown-field', 'path' => 'customer.vat'],
            ['kind' => 'block-without-print-view', 'blockId' => 'p1'],
            ['kind' => 'block-without-print-view', 'blockId' => 'p2'],
        ], $blockers);

        // Calling Publish directly runs the same check.
        $res = $this->publish(self::OWNER, $id, '"1"');
        self::assertSame(409, $res->getStatusCode());
        self::assertCount(3, json_decode($res->getBody(), true)['details']['blockers']);
    }

    public function testOnlyPrintedKindsHaveVersionsAndHiddenTemplatesAre404(): void
    {
        // documents:publish itself is the route gate's (public/index.php); here,
        // a template the caller can't see answers like one that doesn't exist.
        $id = $this->template(self::OWNER, [$this->para('Hello')]);
        self::assertSame(404, $this->publish(self::WRITER, $id, '"1"')->getStatusCode());
        self::assertSame(404, $this->check(self::WRITER, $id)->getStatusCode());

        $note = (int) $this->data($this->sources->create($this->request('POST', '/api/document-sources', self::OWNER, (string) json_encode(['kind' => 'note', 'title' => 'Notes']))))['id'];
        self::assertSame(409, $this->check(self::OWNER, $note)->getStatusCode(), 'notes save live; they are never published (E.9)');

        self::assertSame(404, $this->check(self::FOREIGN, $id, self::OTHER_TENANT)->getStatusCode());
    }

    // ── preview ─────────────────────────────────────────────────────────────

    public function testPreviewFillsTheFieldsForARecordTheCallerMaySee(): void
    {
        $this->settings->setTenant(self::TENANT, SettingsRegistry::BILLING_SELLER_NAME, 'Example Bakery');
        $id = $this->template(self::OWNER, [$this->para('Hi')], 'tenant');
        $doc = $this->issue(self::WRITER, 'Payment reminder');

        $res = $this->preview(self::WRITER, $id, 'document:' . $doc);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $data = $this->data($res);
        self::assertSame('#' . $doc . ' · Payment reminder', $data['label']);
        self::assertSame('Payment reminder', $data['values']['document.title']);
        self::assertSame('Example Bakery', $data['values']['company.name']);
        self::assertNull($data['values']['company.tax_number'], 'a missing value is null, for the error tint (E.27)');
        self::assertSame('29 October 2026', $data['values']['today']);

        $hidden = $this->issue(self::OWNER, 'Owner only');
        self::assertSame(404, $this->preview(self::WRITER, $id, 'document:' . $hidden)->getStatusCode(), 'hidden is the same as missing');
        self::assertSame(404, $this->preview(self::WRITER, $id, 'document:99999')->getStatusCode());
        self::assertSame(422, $this->preview(self::WRITER, $id, 'invoice:1')->getStatusCode(), 'the record must be of the template type');
    }

    // ── render ──────────────────────────────────────────────────────────────

    public function testRenderPrintsThePublishedVersionWithTheValuesFrozenIn(): void
    {
        $this->settings->setTenant(self::TENANT, SettingsRegistry::BILLING_SELLER_NAME, 'Example Bakery');
        $this->settings->setTenant(self::TENANT, SettingsRegistry::BILLING_SELLER_TAX_ID, '0123456');
        $id = $this->template(self::OWNER, [
            $this->para('Dear ', $this->field('document.title'), ', from ', $this->field('company.name')),
            ['id' => 'b2', 'type' => 'barcode', 'symbology' => 'code128', 'value' => [$this->field('document.number')], 'showText' => true],
            ['id' => 'b3', 'type' => 'bullet', 'level' => 0, 'content' => [['type' => 'text', 'text' => 'One']]],
            ['id' => 'b4', 'type' => 'bullet', 'level' => 0, 'content' => [['type' => 'text', 'text' => 'Two']]],
        ]);
        $doc = $this->issue(self::OWNER, 'Payment reminder');

        self::assertSame(409, $this->renderPdf(self::OWNER, $id, ['record' => 'document:' . $doc])->getStatusCode(), 'nothing published yet');
        $this->publish(self::OWNER, $id, '"1"');
        // The draft moves on; the PDF is still the published version.
        $this->save(self::OWNER, $id, '"1"', ['blocks' => [$this->para('A draft nobody published')]]);

        $res = $this->renderPdf(self::OWNER, $id, ['record' => 'document:' . $doc]);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertSame('application/pdf', self::header($res, 'Content-Type'));

        $payload = $this->render->flowCalls[0];
        self::assertSame('letterhead', $payload['content'][0]['type']);
        self::assertSame('Example Bakery', $payload['content'][0]['start']['name']);
        self::assertSame(['0123456'], $payload['content'][0]['start']['lines']);
        self::assertSame(2, $payload['header']['fromPage'], 'the running header starts on page 2');
        self::assertSame('Dear Payment reminder, from Example Bakery', $payload['content'][1]['text'], 'fields are values, never chips');
        self::assertSame(['type' => 'barcode', 'symbology' => 'code128', 'value' => (string) $doc, 'showText' => true], $payload['content'][2]);
        self::assertSame(['type' => 'list', 'ordered' => false, 'tone' => 'plain', 'items' => ['One', 'Two']], $payload['content'][3]);
        self::assertStringNotContainsString('A draft nobody published', (string) json_encode($payload));

        // The draft can be previewed as a PDF, never issued.
        self::assertSame(200, $this->renderPdf(self::OWNER, $id, ['record' => 'document:' . $doc, 'draft' => true])->getStatusCode());
        self::assertSame(422, $this->renderPdf(self::OWNER, $id, ['record' => 'document:' . $doc, 'draft' => true, 'persist' => true])->getStatusCode());
    }

    public function testARequiredFieldWithNoValueRefusesTheRender(): void
    {
        $id = $this->template(self::OWNER, [$this->para('Tax no. ', $this->field('company.tax_number'))]);
        $this->publish(self::OWNER, $id, '"1"');
        $doc = $this->issue(self::OWNER, 'Payment reminder');

        $res = $this->renderPdf(self::OWNER, $id, ['record' => 'document:' . $doc]);
        self::assertSame(422, $res->getStatusCode());
        self::assertSame(['company.tax_number'], json_decode($res->getBody(), true)['details']['missing']);
        self::assertSame([], $this->render->flowCalls, 'nothing was sent to Whity Render');
    }

    public function testPersistIssuesTheDocumentWithTheValuesItPrinted(): void
    {
        $this->settings->setTenant(self::TENANT, SettingsRegistry::BILLING_SELLER_TAX_ID, '0123456');
        $id = $this->template(self::OWNER, [$this->para('For ', $this->field('document.title'))]);
        $this->publish(self::OWNER, $id, '"1"');
        $doc = $this->issue(self::OWNER, 'Payment reminder');

        $res = $this->renderPdf(self::OWNER, $id, ['record' => 'document:' . $doc, 'persist' => true]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        $issued = $this->data($res);
        self::assertSame('Payment reminder · #' . $doc . ' · Payment reminder', $issued['title']);

        $row = (new DocumentRepository($this->pdo))->findById((int) $issued['id'], self::TENANT);
        self::assertNotNull($row);
        $rows = $row['variable_data'];
        self::assertSame('Payment reminder', $rows[0]['document.title']);
        self::assertSame('0123456', $rows[0]['company.tax_number']);
    }

    public function testRenderReadsTheRecordForTheCaller(): void
    {
        $id = $this->template(self::OWNER, [$this->para('Hi')], 'tenant');
        $this->publish(self::OWNER, $id, '"1"');
        $hidden = $this->issue(self::OWNER, 'Owner only');
        $this->grantRender(102);

        self::assertSame(404, $this->renderPdf(self::WRITER, $id, ['record' => 'document:' . $hidden])->getStatusCode());
        self::assertSame(422, $this->renderPdf(self::OWNER, $id, [])->getStatusCode(), 'a template with a record type needs a record');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function field(string $path): array
    {
        return ['type' => 'field', 'path' => $path, 'label' => $path];
    }

    /** @return array<string, mixed> */
    private function para(mixed ...$runs): array
    {
        static $n = 0;
        $n++;

        return ['id' => 'para' . $n, 'type' => 'paragraph', 'content' => array_map(
            static fn (mixed $r): array => is_string($r) ? ['type' => 'text', 'text' => $r] : $r,
            $runs,
        )];
    }

    /** @param list<array<string, mixed>> $blocks */
    private function template(int $userId, array $blocks, string $scope = 'personal'): int
    {
        $res = $this->sources->create($this->request('POST', '/api/document-sources', $userId, (string) json_encode([
            'kind' => 'template',
            'title' => 'Payment reminder',
            'record_type' => 'document',
            'scope' => $scope,
            'blocks' => $blocks,
        ])));
        self::assertSame(201, $res->getStatusCode(), $res->getBody());

        return (int) $this->data($res)['id'];
    }

    private function issue(int $userId, string $title): int
    {
        $this->pdo->prepare('INSERT INTO documents (tenant_id, template_name, title, created_by, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([self::TENANT, 'Letters', $title, $userId, '2026-10-20 09:00:00']);

        return (int) $this->pdo->lastInsertId();
    }

    private function check(int $userId, int $id, int $tenant = self::TENANT): Response
    {
        return $this->handler->publishCheck($this->request('GET', "/api/document-sources/{$id}/publish-check", $userId, '', $tenant), ['id' => (string) $id]);
    }

    private function publish(int $userId, int $id, ?string $ifMatch): Response
    {
        return $this->handler->publish(
            $this->request('POST', "/api/document-sources/{$id}/publish", $userId, '', self::TENANT, $ifMatch !== null ? ['If-Match' => $ifMatch] : []),
            ['id' => (string) $id],
        );
    }

    private function preview(int $userId, int $id, string $record): Response
    {
        return $this->handler->preview($this->request('GET', "/api/document-sources/{$id}/preview?record=" . rawurlencode($record), $userId), ['id' => (string) $id]);
    }

    /** @param array<string, mixed> $body */
    private function renderPdf(int $userId, int $id, array $body): Response
    {
        return $this->handler->render($this->request('POST', "/api/document-sources/{$id}/render", $userId, (string) json_encode((object) $body)), ['id' => (string) $id]);
    }

    /** @param array<string, mixed> $body */
    private function save(int $userId, int $id, string $ifMatch, array $body): void
    {
        $res = $this->sources->saveDraft(
            $this->request('PUT', "/api/document-sources/{$id}/draft", $userId, (string) json_encode($body), self::TENANT, ['If-Match' => $ifMatch]),
            ['id' => (string) $id],
        );
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
    }

    private function grantRender(int $roleId): void
    {
        $this->grant($this->pdo, $roleId, 'documents:render');
        RoleChecker::clearCache();
    }

    private static function header(Response $res, string $name): ?string
    {
        foreach ($res->getHeaders() as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $path, int $userId, string $body = '', int $tenant = self::TENANT, array $headers = []): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenant);
        $req = new Request($method, $path, $headers, $body);
        $req->user = (object) ['profile_id' => $userId, 'active_tenant_id' => $tenant];

        return $req;
    }

    /** @return array<string, mixed> */
    private function data(Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded, $res->getBody());

        return $decoded['data'];
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'viewer', '', 1, datetime('now')),
            (102, 'writer', '', 1, datetime('now')),
            (201, 'writer', '', 2, datetime('now'))");
        $this->grant($pdo, 1, 'documents:render');
        $this->grant($pdo, 101, 'documents:read');
        $this->grant($pdo, 102, 'documents:read');
        $this->grant($pdo, 102, 'documents:write');
        $this->grant($pdo, 201, 'documents:read');
        $this->grant($pdo, 201, 'documents:write');
        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'owner',   'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'viewer',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'writer',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'foreign', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 1,   'active', datetime('now')),
                (1001, 11, 1, 101, 'active', datetime('now')),
                (1002, 12, 1, 102, 'active', datetime('now')),
                (2000, 20, 2, 201, 'active', datetime('now'))
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

    private function wrap(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }
}
