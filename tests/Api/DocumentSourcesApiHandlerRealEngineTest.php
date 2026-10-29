<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentSourcesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Response;

/**
 * Real-engine tests for {@see DocumentSourcesApiHandler} (editor M2): the
 * block editor's documents. Runs on SQLite by default and on PostgreSQL when
 * PHPUNIT_PG_DSN is set (Tests\Support\SchemaFromMigrations).
 *
 * What matters most here is the draft save. The editor autosaves, so two
 * people (or one person in two tabs) saving the same document is the normal
 * case: a save made against an old version must be refused with 409 and the
 * current version, never quietly overwrite the newer work.
 */
final class DocumentSourcesApiHandlerRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    private const OWNER  = 10; // admin role → read/write/publish (migration 060)
    private const VIEWER = 11; // read only
    private const WRITER = 12; // read + write, no publish
    private const FOREIGN = 20; // a writer in the other tenant

    private PDO $pdo;
    private DocumentSourcesApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $this->handler = new DocumentSourcesApiHandler(
            new DocumentSourceRepository($this->pdo),
            new DocumentAccessPolicy(),
            new RoleChecker($this->wrap($this->pdo), new PermissionRegistry()),
            new OuReachResolver($this->pdo, new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())),
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
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
