<?php

declare(strict_types=1);

namespace Tests\Plugins;

use PDO;
use PHPUnit\Framework\TestCase;
use Taxonomy\Api\TagGroupsApiHandler;
use Taxonomy\Api\TagsApiHandler;
use Taxonomy\Migrations\CreateTaxonomyTables;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Sql\SequenceAllocator;

require_once dirname(__DIR__, 2) . '/plugins/Taxonomy/Migrations/CreateTaxonomyTables.php';
require_once dirname(__DIR__, 2) . '/plugins/Taxonomy/Api/TagGroupResource.php';
require_once dirname(__DIR__, 2) . '/plugins/Taxonomy/Api/TagResource.php';
require_once dirname(__DIR__, 2) . '/plugins/Taxonomy/Api/TagGroupsApiHandler.php';
require_once dirname(__DIR__, 2) . '/plugins/Taxonomy/Api/TagsApiHandler.php';

/**
 * Real-engine coverage for the Taxonomy plugin's tag_groups + tags slices: the
 * plugin migration ADOPTS the (core-created) tables by adding sync columns, and
 * the handlers serve them as two-way-syncable resources through the shared
 * {@see \Whity\Sdk\Sync\SyncController}. A tag references a real tag group.
 */
final class TaxonomyPluginSyncRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private TagGroupsApiHandler $groups;
    private TagsApiHandler $tags;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        // Core tag_groups/tags.tenant_id carry a real FK to tenants (migration
        // 063), enforced on PostgreSQL — seed the tenants first (SQLite let the
        // unseeded insert slide; the Relations port learned this the hard way).
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        (new CreateTaxonomyTables())->up($this->pdo);

        $seq = new TaxonomyFakeSequenceAllocator();
        $this->groups = new TagGroupsApiHandler($this->pdo, $seq);
        $this->tags = new TagsApiHandler($this->pdo, $seq);
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_A);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testMigrationAddsSyncColumnsToBothTables(): void
    {
        foreach (['tag_groups', 'tags'] as $table) {
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

    public function testTagGroupCreateThenSyncLifecycle(): void
    {
        $created = $this->groups->create($this->body(['groupKey' => 'colors', 'displayName' => ['en' => 'Colors']]));
        $this->assertSame(201, $created->getStatusCode());
        $group = $this->data($created);
        $this->assertSame('colors', $group['groupKey']);
        $this->assertSame(['en' => 'Colors'], (array) $group['displayName']);
        $this->assertSame(1, $group['version']);
        $id = $group['id'];

        // Idempotent replay on clientUuid — no duplicate.
        $replay = $this->groups->create($this->body(['groupKey' => 'colors2', 'clientUuid' => $group['clientUuid']]));
        $this->assertSame(200, $replay->getStatusCode());
        $this->assertSame($id, $this->data($replay)['id']);

        // Update bumps version.
        $updated = $this->groups->update($this->body(['groupKey' => 'colours', 'displayName' => ['en' => 'Colours']]), ['id' => (string) $id]);
        $this->assertSame(200, $updated->getStatusCode());
        $this->assertSame(2, $this->data($updated)['version']);
        $this->assertSame('colours', $this->data($updated)['groupKey']);

        // Soft-delete tombstones; live list drops it.
        $deleted = $this->groups->delete($this->body([]), ['id' => (string) $id]);
        $this->assertSame(200, $deleted->getStatusCode());
        $this->assertNotNull($this->data($deleted)['deletedAt']);
        $this->assertCount(0, $this->data($this->groups->list($this->req('/api/tag-groups'))));
    }

    public function testTagReferencesAGroupAndSyncs(): void
    {
        $groupId = $this->data($this->groups->create($this->body(['groupKey' => 'colors'])))['id'];

        $created = $this->tags->create($this->body(['groupId' => $groupId, 'name' => 'red']));
        $this->assertSame(201, $created->getStatusCode());
        $tag = $this->data($created);
        $this->assertSame('red', $tag['name']);
        $this->assertSame($groupId, $tag['groupId']);
        $this->assertSame(1, $tag['version']);

        // Rename bumps version.
        $updated = $this->tags->update($this->body(['groupId' => $groupId, 'name' => 'crimson']), ['id' => (string) $tag['id']]);
        $this->assertSame(200, $updated->getStatusCode());
        $this->assertSame('crimson', $this->data($updated)['name']);
    }

    public function testChangesFeedPropagatesTombstone(): void
    {
        $id = $this->data($this->groups->create($this->body(['groupKey' => 'sizes'])))['id'];
        $afterCreate = json_decode($this->groups->list($this->req('/api/tag-groups?updatedSince=0'))->getBody(), true);
        $cursor = $afterCreate['cursor'];

        $this->groups->delete($this->body([]), ['id' => (string) $id]);

        $feed = json_decode($this->groups->list($this->req('/api/tag-groups?updatedSince=' . $cursor))->getBody(), true);
        $this->assertCount(1, $feed['data']);
        $this->assertNotNull($feed['data'][0]['deletedAt']);
    }

    public function testTenantIsolation(): void
    {
        $this->groups->create($this->body(['groupKey' => 'a-group']));
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_B);
        $this->groups->create($this->body(['groupKey' => 'b-group']));

        $this->assertCount(1, $this->data($this->groups->list($this->req('/api/tag-groups'))));
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_A);
        $this->assertCount(1, $this->data($this->groups->list($this->req('/api/tag-groups'))));
    }

    public function testValidationRejectsEmptyKeyAndName(): void
    {
        $this->assertSame(400, $this->groups->create($this->body(['groupKey' => '  ']))->getStatusCode());
        $groupId = $this->data($this->groups->create($this->body(['groupKey' => 'g'])))['id'];
        $this->assertSame(400, $this->tags->create($this->body(['groupId' => $groupId, 'name' => '  ']))->getStatusCode());
    }

    /** @param array<string, mixed> $fields */
    private function body(array $fields): Request
    {
        return new Request('POST', '/api/taxonomy', [], json_encode($fields, JSON_THROW_ON_ERROR));
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
final class TaxonomyFakeSequenceAllocator implements SequenceAllocator
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
