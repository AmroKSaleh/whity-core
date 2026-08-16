<?php

declare(strict_types=1);

namespace Tests\Plugins;

use PDO;
use PHPUnit\Framework\TestCase;
use Relations\Api\PersonsApiHandler;
use Relations\Migrations\CreatePersonsTable;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Request;
use Whity\Sdk\Sql\SequenceAllocator;

require_once dirname(__DIR__, 2) . '/plugins/Relations/Migrations/CreatePersonsTable.php';
require_once dirname(__DIR__, 2) . '/plugins/Relations/Api/PersonResource.php';
require_once dirname(__DIR__, 2) . '/plugins/Relations/Api/PersonsApiHandler.php';

/**
 * Real-engine coverage for the Relations plugin's persons slice: the plugin's
 * migration ADOPTS the (core-created) `persons` table by adding sync columns,
 * and {@see PersonsApiHandler} serves it as a two-way-syncable resource through
 * the shared {@see \Whity\Sdk\Sync\SyncController}.
 */
final class RelationsPluginPersonsSyncRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private PersonsApiHandler $handler;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        // Core's `persons.tenant_id` carries a real FK to `tenants` (migration
        // 018). On PostgreSQL that FK is enforced, so the tenants a person is
        // inserted under must exist first; SQLite let the unseeded insert slide.
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        // Adopt-and-augment: core already created `persons`; add the sync columns.
        (new CreatePersonsTable())->up($this->pdo);

        $this->handler = new PersonsApiHandler($this->pdo, new RelationsFakeSequenceAllocator());
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_A);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testMigrationAddsSyncColumnsToPersons(): void
    {
        $cols = $this->pdo->query('SELECT * FROM persons LIMIT 0');
        $this->assertNotFalse($cols);
        $names = [];
        for ($i = 0; $i < $cols->columnCount(); $i++) {
            $meta = $cols->getColumnMeta($i);
            if (is_array($meta)) {
                $names[] = $meta['name'];
            }
        }
        foreach (['version', 'client_uuid', 'deleted_at', 'change_seq'] as $sync) {
            $this->assertContains($sync, $names, "persons must carry the sync column {$sync}");
        }
    }

    public function testCreateThenSyncLifecycle(): void
    {
        $created = $this->handler->create($this->body(['displayName' => 'Ada Lovelace', 'deceased' => true]));
        $this->assertSame(201, $created->getStatusCode());
        $person = $this->data($created);
        $this->assertSame('Ada Lovelace', $person['displayName']);
        $this->assertTrue($person['deceased']);
        $this->assertSame(1, $person['version']);
        $this->assertIsString($person['clientUuid']);
        $id = $person['id'];

        // Idempotent create replays, no duplicate.
        $replay = $this->handler->create($this->body(['displayName' => 'Ada again', 'clientUuid' => $person['clientUuid']]));
        $this->assertSame(200, $replay->getStatusCode());
        $this->assertSame($id, $this->data($replay)['id']);

        // Update bumps version.
        $updated = $this->handler->update($this->body(['displayName' => 'Ada, Countess']), ['id' => (string) $id]);
        $this->assertSame(200, $updated->getStatusCode());
        $this->assertSame(2, $this->data($updated)['version']);
        $this->assertSame('Ada, Countess', $this->data($updated)['displayName']);

        // Soft-delete tombstones; live list drops it.
        $deleted = $this->handler->delete($this->body([]), ['id' => (string) $id]);
        $this->assertSame(200, $deleted->getStatusCode());
        $this->assertNotNull($this->data($deleted)['deletedAt']);
        $this->assertCount(0, $this->data($this->handler->list($this->req('/api/persons'))));
    }

    public function testChangesFeedPropagatesTombstone(): void
    {
        $id = $this->data($this->handler->create($this->body(['displayName' => 'Grace Hopper'])))['id'];
        $afterCreate = json_decode($this->handler->list($this->req('/api/persons?updatedSince=0'))->getBody(), true);
        $cursor = $afterCreate['cursor'];

        $this->handler->delete($this->body([]), ['id' => (string) $id]);

        $feed = json_decode($this->handler->list($this->req('/api/persons?updatedSince=' . $cursor))->getBody(), true);
        $this->assertCount(1, $feed['data']);
        $this->assertNotNull($feed['data'][0]['deletedAt']);
    }

    public function testTenantIsolation(): void
    {
        $this->handler->create($this->body(['displayName' => 'A-person']));
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_B);
        $this->handler->create($this->body(['displayName' => 'B-person']));

        $this->assertCount(1, $this->data($this->handler->list($this->req('/api/persons'))));
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_A);
        $this->assertCount(1, $this->data($this->handler->list($this->req('/api/persons'))));
    }

    public function testValidationRejectsEmptyName(): void
    {
        $this->assertSame(400, $this->handler->create($this->body(['displayName' => '  ']))->getStatusCode());
    }

    /** @param array<string, mixed> $fields */
    private function body(array $fields): Request
    {
        return new Request('POST', '/api/persons', [], json_encode($fields, JSON_THROW_ON_ERROR));
    }

    private function req(string $path): Request
    {
        return new Request('GET', $path, [], '');
    }

    /** @return array<string, mixed> */
    private function data(\Whity\Sdk\Http\Response $resp): array
    {
        return json_decode($resp->getBody(), true)['data'];
    }
}

/** In-memory monotonic allocator; only nextPlatformWide() is exercised. */
final class RelationsFakeSequenceAllocator implements SequenceAllocator
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
