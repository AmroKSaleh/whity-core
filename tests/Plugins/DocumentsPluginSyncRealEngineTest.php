<?php

declare(strict_types=1);

namespace Tests\Plugins;

use Documents\Api\DocumentBlocksApiHandler;
use Documents\Api\DocumentTemplatesApiHandler;
use Documents\Migrations\AugmentDocumentDesignerTables;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Sql\SequenceAllocator;

require_once dirname(__DIR__, 2) . '/plugins/Documents/Migrations/AugmentDocumentDesignerTables.php';
require_once dirname(__DIR__, 2) . '/plugins/Documents/Api/AbstractDocumentDesignerResource.php';
require_once dirname(__DIR__, 2) . '/plugins/Documents/Api/DocumentTemplateResource.php';
require_once dirname(__DIR__, 2) . '/plugins/Documents/Api/DocumentBlockResource.php';
require_once dirname(__DIR__, 2) . '/plugins/Documents/Api/DocumentTemplatesApiHandler.php';
require_once dirname(__DIR__, 2) . '/plugins/Documents/Api/DocumentBlocksApiHandler.php';

/**
 * Real-engine coverage for the Documents plugin`s Document Designer port: the
 * plugin migration ADOPTS the (core migration 059-created) `document_templates`
 * and `document_blocks` tables by adding sync columns, and the handlers serve
 * them as two-way-syncable resources through the shared
 * {@see \Whity\Sdk\Sync\SyncController}. The `data` client object round-trips
 * through the JSON-encoded (JSONB on server / TEXT offline) column, and
 * `is_system`/`scope` are exercised on the real engine.
 */
final class DocumentsPluginSyncRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private DocumentTemplatesApiHandler $templates;
    private DocumentBlocksApiHandler $blocks;

    protected function setUp(): void
    {
        // Core migration 059 creates both tables; SchemaFromMigrations runs it, so
        // the plugin migration below only augments them (CREATE IF NOT EXISTS
        // no-ops), exactly like the server adopt path.
        $this->pdo = SchemaFromMigrations::make();
        // document_templates/document_blocks.tenant_id carry a real FK to tenants
        // (migration 059), enforced on PostgreSQL -- seed the tenants first (SQLite
        // let the unseeded insert slide; the Relations port learned this the hard way).
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        (new AugmentDocumentDesignerTables())->up($this->pdo);

        $seq = new DocumentsFakeSequenceAllocator();
        $this->templates = new DocumentTemplatesApiHandler($this->pdo, $seq);
        $this->blocks = new DocumentBlocksApiHandler($this->pdo, $seq);
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_A);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testMigrationAddsSyncColumnsToBothTables(): void
    {
        foreach (['document_templates', 'document_blocks'] as $table) {
            $cols = $this->pdo->query("SELECT * FROM {$table} LIMIT 0");
            $this->assertNotFalse($cols);
            $names = [];
            for ($i = 0; $i < $cols->columnCount(); $i++) {
                $meta = $cols->getColumnMeta($i);
                if (is_array($meta)) {
                    $names[] = $meta['name'];
                }
            }
            foreach (['version', 'client_uuid', 'deleted_at', 'change_seq'] as $sync) {
                $this->assertContains($sync, $names, "{$table} must carry the sync column {$sync}");
            }
        }
    }

    public function testTemplateCreateThenSyncLifecycle(): void
    {
        $created = $this->templates->create($this->body([
            'name' => 'Invoice', 'scope' => 'tenant', 'isSystem' => true,
            'data' => ['version' => 2, 'pages' => [['id' => 'p1']]],
        ]));
        $this->assertSame(201, $created->getStatusCode());
        $tpl = $this->data($created);
        $this->assertSame('Invoice', $tpl['name']);
        $this->assertSame('tenant', $tpl['scope']);
        $this->assertTrue($tpl['isSystem']);
        // The verbatim client object round-trips through the JSON-encoded column.
        // assertEquals (not assertSame): PostgreSQL JSONB does not preserve object
        // key order, so compare order-independently.
        $this->assertEquals(['version' => 2, 'pages' => [['id' => 'p1']]], $tpl['data']);
        $this->assertSame(1, $tpl['version']);
        $id = $tpl['id'];

        // Idempotent replay on clientUuid -- no duplicate.
        $replay = $this->templates->create($this->body(['name' => 'ignored', 'scope' => 'personal', 'clientUuid' => $tpl['clientUuid']]));
        $this->assertSame(200, $replay->getStatusCode());
        $this->assertSame($id, $this->data($replay)['id']);

        // Update bumps version and rewrites every field.
        $updated = $this->templates->update(
            $this->body(['name' => 'Invoice v2', 'scope' => 'global', 'data' => ['version' => 3]]),
            ['id' => (string) $id]
        );
        $this->assertSame(200, $updated->getStatusCode());
        $this->assertSame(2, $this->data($updated)['version']);
        $this->assertSame('Invoice v2', $this->data($updated)['name']);
        $this->assertSame('global', $this->data($updated)['scope']);
        $this->assertEquals(['version' => 3], $this->data($updated)['data']);

        // Soft-delete tombstones; live list drops it.
        $deleted = $this->templates->delete($this->body([]), ['id' => (string) $id]);
        $this->assertSame(200, $deleted->getStatusCode());
        $this->assertNotNull($this->data($deleted)['deletedAt']);
        $this->assertCount(0, $this->data($this->templates->list($this->req('/api/document-templates'))));
    }

    public function testBlockCreateWithListDataAndDefaults(): void
    {
        $created = $this->blocks->create($this->body([
            'name' => 'Header', 'data' => [['type' => 'text', 'value' => 'hi']],
        ]));
        $this->assertSame(201, $created->getStatusCode());
        $blk = $this->data($created);
        $this->assertSame('Header', $blk['name']);
        // scope + is_system default when absent.
        $this->assertSame('personal', $blk['scope']);
        $this->assertFalse($blk['isSystem']);
        $this->assertEquals([['type' => 'text', 'value' => 'hi']], $blk['data']);
    }

    public function testFormCreateWithoutDataUsesEmptyPlaceholder(): void
    {
        // The block create form sends only name + scope; data defaults server-side.
        $tpl = $this->data($this->templates->create($this->body(['name' => 'Blank', 'scope' => 'personal'])));
        $this->assertSame([], $tpl['data']);
    }

    public function testRequiredPermissionRoundTripsAndBlankIsNull(): void
    {
        $gated = $this->data($this->templates->create($this->body([
            'name' => 'Contracts', 'scope' => 'tenant', 'requiredPermission' => 'documents:read',
        ])));
        $this->assertSame('documents:read', $gated['requiredPermission']);

        $open = $this->data($this->templates->create($this->body(['name' => 'Open', 'scope' => 'tenant'])));
        $this->assertNull($open['requiredPermission']);
    }

    public function testChangesFeedPropagatesTombstone(): void
    {
        $id = $this->data($this->blocks->create($this->body(['name' => 'Feed block', 'scope' => 'personal'])))['id'];
        $afterCreate = json_decode($this->blocks->list($this->req('/api/document-blocks?updatedSince=0'))->getBody(), true);
        $cursor = $afterCreate['cursor'];

        $this->blocks->delete($this->body([]), ['id' => (string) $id]);

        $feed = json_decode($this->blocks->list($this->req('/api/document-blocks?updatedSince=' . $cursor))->getBody(), true);
        $this->assertCount(1, $feed['data']);
        $this->assertNotNull($feed['data'][0]['deletedAt']);
    }

    public function testTenantIsolation(): void
    {
        $this->templates->create($this->body(['name' => 'A tpl', 'scope' => 'personal']));
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_B);
        $this->templates->create($this->body(['name' => 'B tpl', 'scope' => 'personal']));

        $this->assertCount(1, $this->data($this->templates->list($this->req('/api/document-templates'))));
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_A);
        $this->assertCount(1, $this->data($this->templates->list($this->req('/api/document-templates'))));
    }

    public function testValidationRejectsEmptyNameAndBadScope(): void
    {
        $this->assertSame(400, $this->templates->create($this->body(['name' => '  ', 'scope' => 'personal']))->getStatusCode());
        $this->assertSame(400, $this->templates->create($this->body(['name' => 'Valid', 'scope' => 'nonsense']))->getStatusCode());
        $this->assertSame(400, $this->blocks->create($this->body(['name' => 'Valid', 'scope' => 'nonsense']))->getStatusCode());
    }

    /** @param array<string, mixed> $fields */
    private function body(array $fields): Request
    {
        return new Request('POST', '/api/documents', [], json_encode($fields, JSON_THROW_ON_ERROR));
    }

    private function req(string $path): Request
    {
        return new Request('GET', $path, [], '');
    }

    /** @return array<string, mixed> */
    private function data(Response $resp): array
    {
        return json_decode($resp->getBody(), true)['data'];
    }
}

/** In-memory monotonic allocator; only nextPlatformWide() is exercised. */
final class DocumentsFakeSequenceAllocator implements SequenceAllocator
{
    /** @var array<string, int> */
    private array $counters = [];

    public function next(int $tenantId, string $name, int $step = 1): int
    {
        return $this->counters[$name] = ($this->counters[$name] ?? 0) + $step;
    }

    public function nextBlock(int $tenantId, string $name, int $count): array
    {
        $last = $this->next($tenantId, $name, $count);

        return ['first' => $last - $count + 1, 'last' => $last];
    }

    public function nextPlatformWide(string $name, int $step = 1): int
    {
        return $this->counters[$name] = ($this->counters[$name] ?? 0) + $step;
    }

    public function peek(int $tenantId, string $name): int
    {
        return $this->counters[$name] ?? 0;
    }
}
