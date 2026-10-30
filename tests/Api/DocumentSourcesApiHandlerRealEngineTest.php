<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentEmbedsApiHandler;
use Whity\Api\DocumentSourcesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentSourceFileStore;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Embed\IssuedDocumentRecordProvider;
use Whity\Core\Document\Embed\IssuedDocumentsByMonthChartSource;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Response;
use Whity\Storage\LocalStorageDriver;

/**
 * Real-engine tests for {@see DocumentSourcesApiHandler} (editor M2): the
 * block editor's documents. Runs on SQLite by default and on PostgreSQL when
 * PHPUNIT_PG_DSN is set (Tests\Support\SchemaFromMigrations).
 *
 * What matters most here is the draft save. The editor autosaves, so two
 * people (or one person in two tabs) saving the same document is the normal
 * case: a save made against an old version must be refused with 409 and the
 * current version, never quietly overwrite the newer work.
 *
 * M3 adds the files placed in a document (readable exactly when the
 * document is) and the live Record and Chart lookups, which answer for the
 * reader and never reveal a record the reader may not see.
 */
final class DocumentSourcesApiHandlerRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    private const OWNER  = 10; // admin role → read/write/publish (migration 060)
    private const VIEWER = 11; // read only
    private const WRITER = 12; // read + write, no publish
    private const FOREIGN = 20; // a writer in the other tenant

    private const PNG = "\x89PNG\r\n\x1a\n" . 'not really an image, but its signature is';

    private PDO $pdo;
    private DocumentSourcesApiHandler $handler;
    private DocumentEmbedsApiHandler $embeds;
    private string $storageRoot;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $this->storageRoot = sys_get_temp_dir() . '/docsources-' . bin2hex(random_bytes(6));
        mkdir($this->storageRoot, 0o777, true);
        $roleChecker = new RoleChecker($this->wrap($this->pdo), new PermissionRegistry());
        $resourceRoles = new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry());
        $this->handler = new DocumentSourcesApiHandler(
            new DocumentSourceRepository($this->pdo),
            new DocumentAccessPolicy(),
            $roleChecker,
            new OuReachResolver($this->pdo, $resourceRoles),
            new DocumentSourceFileStore(new LocalStorageDriver($this->storageRoot), $this->pdo),
        );

        $documents = new DocumentRepository($this->pdo);
        $visibility = new DocumentVisibilityPolicy(new RouteRecipientRepository($this->pdo), $resourceRoles);
        $registry = new DocumentEmbedRegistry();
        $registry->addRecordProvider(new IssuedDocumentRecordProvider($documents, $visibility));
        $registry->addChartSource(new IssuedDocumentsByMonthChartSource(
            $documents,
            $visibility,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-15 12:00:00', new \DateTimeZone('UTC')),
        ));
        $this->embeds = new DocumentEmbedsApiHandler($registry, $roleChecker);
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

    // ── files (M3) ──────────────────────────────────────────────────────────

    public function testAFileUploadedIntoADocumentReadsBackWithItsType(): void
    {
        $id = $this->idOf($this->create(self::WRITER, ['kind' => 'note', 'title' => 'With a logo']));

        $up = $this->upload(self::WRITER, $id, self::PNG, 'logo.png', 'image/png');
        self::assertSame(201, $up->getStatusCode(), $up->getBody());
        $file = $this->data($up);
        self::assertSame('image/png', $file['content_type']);
        self::assertSame('logo.png', $file['filename']);
        self::assertSame('/api/v1/document-sources/' . $id . '/files/' . $file['id'] . '/content', $file['url']);
        self::assertArrayNotHasKey('storage_key', $file, 'the storage key never leaves the server');

        $read = $this->fileContent(self::WRITER, $id, (int) $file['id']);
        self::assertSame(200, $read->getStatusCode());
        self::assertSame(self::PNG, $read->getBody());
        self::assertSame('image/png', self::header($read, 'Content-Type'));
        self::assertStringStartsWith('inline;', (string) self::header($read, 'Content-Disposition'));
        self::assertSame('nosniff', self::header($read, 'X-Content-Type-Options'));
    }

    public function testAFileIsReadableExactlyWhenItsDocumentIs(): void
    {
        $id = $this->idOf($this->create(self::WRITER, ['kind' => 'note', 'title' => 'Mine']));
        $fileId = (int) $this->data($this->upload(self::WRITER, $id, self::PNG, 'a.png', 'image/png'))['id'];

        self::assertSame(404, $this->fileContent(self::VIEWER, $id, $fileId)->getStatusCode(), 'a personal document hides its files');
        self::assertSame(404, $this->upload(self::OWNER, $id, self::PNG, 'b.png', 'image/png')->getStatusCode(), 'nor can others add to it');
        self::assertSame(404, $this->fileContent(self::FOREIGN, $id, $fileId, self::OTHER_TENANT)->getStatusCode());

        // A file id is bound to its document: naming it under another one finds nothing.
        $other = $this->idOf($this->create(self::WRITER, ['kind' => 'note', 'title' => 'Other']));
        self::assertSame(404, $this->fileContent(self::WRITER, $other, $fileId)->getStatusCode());
    }

    public function testOnlyCheckedFileTypesAreStored(): void
    {
        $id = $this->idOf($this->create(self::WRITER, ['kind' => 'note', 'title' => 'Files']));

        $svg = $this->upload(self::WRITER, $id, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.svg', 'image/svg+xml');
        self::assertSame(422, $svg->getStatusCode(), 'an SVG served from our origin would be a script');

        $relabelled = $this->upload(self::WRITER, $id, self::PNG, 'x.pdf', 'application/pdf');
        self::assertSame(422, $relabelled->getStatusCode(), 'the bytes and the declared type must agree');

        $pdf = $this->upload(self::WRITER, $id, "%PDF-1.7\n%fake", 'price-list.pdf', 'application/pdf');
        self::assertSame(201, $pdf->getStatusCode(), $pdf->getBody());
        $read = $this->fileContent(self::WRITER, $id, (int) $this->data($pdf)['id']);
        self::assertStringStartsWith('attachment;', (string) self::header($read, 'Content-Disposition'), 'anything but an image downloads');
    }

    // ── live records and charts (M3) ────────────────────────────────────────

    public function testARecordIsShownOnlyToReadersWhoCanSeeIt(): void
    {
        $mine = $this->issue(self::WRITER, 'Receipt R-17', '2026-10-02 09:00:00');

        $res = $this->record(self::WRITER, 'document', (string) $mine);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertSame(['type_label' => 'Issued document', 'title' => 'Receipt R-17', 'subtitle' => 'Receipt', 'href' => '/admin/document-library/' . $mine], $this->data($res));

        self::assertSame(404, $this->record(self::VIEWER, 'document', (string) $mine)->getStatusCode(), 'not theirs, not routed, not granted');
        self::assertSame(404, $this->record(self::FOREIGN, 'document', (string) $mine, self::OTHER_TENANT)->getStatusCode());
        self::assertSame(200, $this->record(self::OWNER, 'document', (string) $mine)->getStatusCode(), 'documents:read_all sees it');

        // Missing, malformed and unknown types are all the same 404 as hidden.
        self::assertSame(404, $this->record(self::WRITER, 'document', '99999')->getStatusCode());
        self::assertSame(404, $this->record(self::WRITER, 'document', 'INV-1')->getStatusCode());
        self::assertSame(404, $this->record(self::WRITER, 'invoice', (string) $mine)->getStatusCode());
    }

    public function testAChartCountsOnlyWhatTheReaderCanSee(): void
    {
        $this->issue(self::WRITER, 'A', '2026-08-03 10:00:00');
        $this->issue(self::WRITER, 'B', '2026-10-01 10:00:00');
        $this->issue(self::WRITER, 'C', '2026-10-09 10:00:00');
        $this->issue(self::OWNER, 'D', '2026-10-10 10:00:00');
        $this->issue(self::WRITER, 'Too old', '2026-04-30 23:00:00');

        $writer = $this->data($this->chart(self::WRITER, 'documents-by-month'));
        self::assertSame('Documents', $writer['value_label']);
        self::assertSame('Last 6 months', $writer['subtitle']);
        self::assertSame(
            [['label' => 'May 2026', 'value' => 0], ['label' => 'Jun 2026', 'value' => 0], ['label' => 'Jul 2026', 'value' => 0],
             ['label' => 'Aug 2026', 'value' => 1], ['label' => 'Sep 2026', 'value' => 0], ['label' => 'Oct 2026', 'value' => 2]],
            $writer['points'],
            'six months, oldest first, empty months as zero, only the writer\'s own documents',
        );

        $owner = $this->data($this->chart(self::OWNER, 'documents-by-month'));
        self::assertSame(3, $owner['points'][5]['value'], 'documents:read_all counts the whole tenant');

        $foreign = $this->data($this->chart(self::FOREIGN, 'documents-by-month', self::OTHER_TENANT));
        self::assertSame([0, 0, 0, 0, 0, 0], array_column($foreign['points'], 'value'), 'another tenant counts nothing of ours');

        self::assertSame(404, $this->chart(self::WRITER, 'revenue-by-month')->getStatusCode());
    }

    public function testTheCatalogueListsRecordTypesAndChartSources(): void
    {
        $res = $this->embeds->catalogue($this->request('GET', '/api/document-embeds', self::VIEWER));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame([
            'record_types' => [['key' => 'document', 'label' => 'Issued document']],
            'chart_sources' => [['key' => 'documents-by-month', 'label' => 'Documents issued per month']],
        ], $this->data($res));
    }

    // ── create and read ─────────────────────────────────────────────────────

    public function testCreateStoresTheContractBlocksAndReturnsVersionOne(): void
    {
        $blocks = [
            ['id' => 'b1', 'type' => 'heading', 'level' => 1, 'content' => [['type' => 'text', 'text' => 'Open invoices']]],
            ['id' => 'b2', 'type' => 'callout', 'kind' => 'important', 'content' => [['type' => 'text', 'text' => 'Fiscal fields are required.', 'marks' => [['type' => 'bold']]]]],
            ['id' => 'b3', 'type' => 'plugin', 'plugin' => 'com.example.delivery-tracker', 'blockType' => 'deliveryStatus', 'schemaVersion' => 2, 'data' => ['order' => 'A-17']],
        ];
        $res = $this->create(self::WRITER, ['kind' => 'note', 'title' => 'Payment reminder', 'blocks' => $blocks]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        self::assertSame('"1"', self::etagOf($res));

        $id = $this->idOf($res);
        $shown = $this->show(self::WRITER, $id);
        self::assertSame(200, $shown->getStatusCode());
        $row = $this->data($shown);
        // assertEquals, not assertSame: PostgreSQL's jsonb keeps an object's keys in its own
        // order. Lists keep their order, and the contract never depends on key order.
        self::assertEquals($blocks, $row['blocks'], 'blocks come back as sent');
        self::assertSame('note', $row['kind']);
        self::assertSame('continuous', $row['default_view'], 'notes open continuous (E.7)');
        self::assertSame(1, $row['draft_version']);
        self::assertNull($row['published_version']);
    }

    public function testTemplatesOpenInThePageView(): void
    {
        $row = $this->data($this->create(self::OWNER, ['kind' => 'template', 'title' => 'Tax invoice']));
        self::assertSame('page', $row['default_view'], 'templates open in the page view (E.7)');
    }

    public function testListOmitsBlocksAndFiltersByKind(): void
    {
        $this->create(self::WRITER, ['kind' => 'note', 'title' => 'A note', 'blocks' => [['id' => 'x', 'type' => 'paragraph', 'content' => []]]]);
        $this->create(self::WRITER, ['kind' => 'wiki', 'title' => 'A wiki page']);

        $all = $this->list(self::WRITER);
        self::assertCount(2, $all);
        self::assertArrayNotHasKey('blocks', $all[0], 'a list never carries the blocks');

        $notes = $this->list(self::WRITER, 'note');
        self::assertSame(['A note'], array_column($notes, 'title'));

        $bad = $this->handler->list($this->request('GET', '/api/document-sources?kind=poem', self::WRITER));
        self::assertSame(422, $bad->getStatusCode());
    }

    // ── visibility ──────────────────────────────────────────────────────────

    public function testAPersonalDocumentIsSeenOnlyByItsCreatorAndHiddenOnes404(): void
    {
        $id = $this->idOf($this->create(self::WRITER, ['kind' => 'note', 'title' => 'Mine']));

        self::assertCount(0, $this->list(self::VIEWER), 'another user does not see a personal document');
        self::assertSame(404, $this->show(self::VIEWER, $id)->getStatusCode(), 'hidden, so 404 rather than 403');
        self::assertSame(404, $this->save(self::OWNER, $id, '"1"', ['title' => 'Taken'])->getStatusCode());
    }

    public function testSharingADocumentNeedsPublish(): void
    {
        $denied = $this->create(self::WRITER, ['kind' => 'wiki', 'title' => 'Handbook', 'scope' => 'tenant']);
        self::assertSame(403, $denied->getStatusCode());

        $shared = $this->create(self::OWNER, ['kind' => 'wiki', 'title' => 'Handbook', 'scope' => 'tenant']);
        self::assertSame(201, $shared->getStatusCode(), $shared->getBody());
        self::assertCount(1, $this->list(self::VIEWER), 'a tenant-wide document is visible to readers');
    }

    public function testAnotherTenantCanNeitherSeeNorSaveTheDocument(): void
    {
        $id = $this->idOf($this->create(self::OWNER, ['kind' => 'wiki', 'title' => 'Handbook', 'scope' => 'tenant']));

        self::assertCount(0, $this->list(self::FOREIGN, null, self::OTHER_TENANT));
        self::assertSame(404, $this->show(self::FOREIGN, $id, self::OTHER_TENANT)->getStatusCode());
        self::assertSame(404, $this->save(self::FOREIGN, $id, '"1"', ['title' => 'Hijacked'], self::OTHER_TENANT)->getStatusCode());
        self::assertSame(404, $this->handler->delete($this->request('DELETE', '/api/document-sources/' . $id, self::FOREIGN, '', self::OTHER_TENANT), ['id' => (string) $id])->getStatusCode());

        self::assertSame('Handbook', $this->data($this->show(self::OWNER, $id))['title'], 'the row is untouched');
    }

    // ── saving the draft ────────────────────────────────────────────────────

    public function testASaveNeedsIfMatch(): void
    {
        $id = $this->idOf($this->create(self::WRITER, ['kind' => 'note']));

        self::assertSame(428, $this->save(self::WRITER, $id, null, ['title' => 'x'])->getStatusCode());
        self::assertSame(428, $this->save(self::WRITER, $id, 'abc', ['title' => 'x'])->getStatusCode());
    }

    public function testASaveBumpsTheVersionAndChangesOnlyWhatWasSent(): void
    {
        $id = $this->idOf($this->create(self::WRITER, ['kind' => 'note', 'title' => 'Draft', 'blocks' => [['id' => 'a', 'type' => 'paragraph', 'content' => []]]]));

        $res = $this->save(self::WRITER, $id, '"1"', ['title' => 'Payment reminder']);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertSame('"2"', self::etagOf($res));
        $row = $this->data($res);
        self::assertSame(2, $row['draft_version']);
        self::assertSame('Payment reminder', $row['title']);
        self::assertSame([['id' => 'a', 'type' => 'paragraph', 'content' => []]], $row['blocks'], 'blocks were not sent, so they are unchanged');
        self::assertSame(self::WRITER, $row['updated_by']);

        $weak = $this->save(self::WRITER, $id, 'W/"2"', ['blocks' => []]);
        self::assertSame(200, $weak->getStatusCode(), 'a weak ETag is accepted too');
        self::assertSame([], $this->data($weak)['blocks']);
    }

    public function testASaveMadeAgainstAnOldVersionIsRefusedAndOverwritesNothing(): void
    {
        $id = $this->idOf($this->create(self::OWNER, ['kind' => 'wiki', 'title' => 'Handbook', 'scope' => 'tenant']));

        // Two people open version 1; the first saves.
        self::assertSame(200, $this->save(self::OWNER, $id, '"1"', ['title' => 'Handbook, edited by Rana'])->getStatusCode());

        // The second saves against version 1, now stale.
        $stale = $this->save(self::OWNER, $id, '"1"', ['title' => 'Handbook, edited by Omar']);
        self::assertSame(409, $stale->getStatusCode());
        $body = json_decode($stale->getBody(), true);
        self::assertSame(2, $body['details']['draft_version'] ?? null, 'the 409 says which version is current');
        self::assertSame('"2"', self::etagOf($stale));

        self::assertSame('Handbook, edited by Rana', $this->data($this->show(self::OWNER, $id))['title'], 'the newer work survives');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badBlocks(): iterable
    {
        yield 'not a list' => [['id' => 'a', 'type' => 'paragraph']];
        yield 'a block without an id' => [[['type' => 'paragraph']]];
        yield 'a block without a type' => [[['id' => 'a']]];
        yield 'an empty id' => [[['id' => '', 'type' => 'paragraph']]];
        yield 'two blocks with one id' => [[['id' => 'a', 'type' => 'paragraph'], ['id' => 'a', 'type' => 'divider']]];
        yield 'a block that is not an object' => [['paragraph']];
        yield 'a string' => ['paragraph'];
    }

    /**
     * @dataProvider badBlocks
     */
    public function testBlocksThatBreakTheContractAreRefused(mixed $blocks): void
    {
        self::assertSame(422, $this->create(self::WRITER, ['kind' => 'note', 'blocks' => $blocks])->getStatusCode());

        $id = $this->idOf($this->create(self::WRITER, ['kind' => 'note']));
        self::assertSame(422, $this->save(self::WRITER, $id, '"1"', ['blocks' => $blocks])->getStatusCode());
        self::assertSame(1, $this->data($this->show(self::WRITER, $id))['draft_version'], 'a refused save changes nothing');
    }

    public function testOtherFieldsAreValidated(): void
    {
        self::assertSame(422, $this->create(self::WRITER, ['kind' => 'poem'])->getStatusCode());
        self::assertSame(422, $this->create(self::WRITER, ['kind' => 'note', 'lang' => 'fr'])->getStatusCode());
        self::assertSame(422, $this->create(self::WRITER, ['kind' => 'note', 'default_view' => 'slides'])->getStatusCode());
        self::assertSame(422, $this->create(self::WRITER, ['kind' => 'note', 'title' => str_repeat('a', 256)])->getStatusCode());

        $id = $this->idOf($this->create(self::WRITER, ['kind' => 'note']));
        self::assertSame(422, $this->save(self::WRITER, $id, '"1"', [])->getStatusCode(), 'a save with nothing in it');
        self::assertSame(422, $this->save(self::WRITER, $id, '"1"', ['full_width' => 'yes'])->getStatusCode());
    }

    public function testDelete(): void
    {
        $id = $this->idOf($this->create(self::WRITER, ['kind' => 'note']));
        $res = $this->handler->delete($this->request('DELETE', '/api/document-sources/' . $id, self::WRITER), ['id' => (string) $id]);
        self::assertSame(204, $res->getStatusCode());
        self::assertSame(404, $this->show(self::WRITER, $id)->getStatusCode());
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function upload(int $userId, int $id, string $bytes, string $filename, string $type, int $tenant = self::TENANT): Response
    {
        $boundary = '----WhityDocFile' . bin2hex(random_bytes(6));
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
            . "Content-Type: {$type}\r\n\r\n"
            . $bytes
            . "\r\n--{$boundary}--\r\n";
        $req = $this->request('POST', '/api/document-sources/' . $id . '/files', $userId, $body, $tenant, ['Content-Type' => "multipart/form-data; boundary={$boundary}"]);

        return $this->handler->uploadFile($req, ['id' => (string) $id]);
    }

    private function fileContent(int $userId, int $id, int $fileId, int $tenant = self::TENANT): Response
    {
        $path = '/api/document-sources/' . $id . '/files/' . $fileId . '/content';

        return $this->handler->fileContent($this->request('GET', $path, $userId, '', $tenant), ['id' => (string) $id, 'fileId' => (string) $fileId]);
    }

    private function record(int $userId, string $type, string $id, int $tenant = self::TENANT): Response
    {
        return $this->embeds->record($this->request('GET', "/api/document-embeds/records/{$type}/{$id}", $userId, '', $tenant), ['type' => $type, 'id' => $id]);
    }

    private function chart(int $userId, string $key, int $tenant = self::TENANT): Response
    {
        return $this->embeds->chart($this->request('GET', "/api/document-embeds/charts/{$key}", $userId, '', $tenant), ['key' => $key]);
    }

    /** An issued document raised by $userId, as the issuer would write it. */
    private function issue(int $userId, string $title, string $createdAt, int $tenant = self::TENANT): int
    {
        $this->pdo->prepare('INSERT INTO documents (tenant_id, template_name, title, created_by, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$tenant, 'Receipt', $title, $userId, $createdAt]);

        return (int) $this->pdo->lastInsertId();
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

    /** The ETag header, whatever case the response normalised its name to. */
    private static function etagOf(Response $res): ?string
    {
        foreach ($res->getHeaders() as $name => $value) {
            if (strcasecmp($name, 'ETag') === 0) {
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

    /** @param array<string, mixed> $body */
    private function create(int $userId, array $body, int $tenant = self::TENANT): Response
    {
        return $this->handler->create($this->request('POST', '/api/document-sources', $userId, (string) json_encode($body), $tenant));
    }

    private function show(int $userId, int $id, int $tenant = self::TENANT): Response
    {
        return $this->handler->show($this->request('GET', '/api/document-sources/' . $id, $userId, '', $tenant), ['id' => (string) $id]);
    }

    /** @param array<string, mixed> $body */
    private function save(int $userId, int $id, ?string $ifMatch, array $body, int $tenant = self::TENANT): Response
    {
        $headers = $ifMatch !== null ? ['If-Match' => $ifMatch] : [];

        return $this->handler->saveDraft(
            $this->request('PUT', '/api/document-sources/' . $id . '/draft', $userId, (string) json_encode((object) $body), $tenant, $headers),
            ['id' => (string) $id],
        );
    }

    /** @return list<array<string, mixed>> */
    private function list(int $userId, ?string $kind = null, int $tenant = self::TENANT): array
    {
        $path = '/api/document-sources' . ($kind !== null ? '?kind=' . $kind : '');
        $res = $this->handler->list($this->request('GET', $path, $userId, '', $tenant));
        self::assertSame(200, $res->getStatusCode(), $res->getBody());

        return json_decode($res->getBody(), true)['data'];
    }

    /** @return array<string, mixed> */
    private function data(Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded, $res->getBody());

        return $decoded['data'];
    }

    private function idOf(Response $res): int
    {
        self::assertSame(201, $res->getStatusCode(), $res->getBody());

        return (int) $this->data($res)['id'];
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
