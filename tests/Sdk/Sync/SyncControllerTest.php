<?php

declare(strict_types=1);

namespace Tests\Sdk\Sync;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Sql\SequenceAllocator;
use Whity\Sdk\Sync\SyncableResource;
use Whity\Sdk\Sync\SyncController;

/**
 * Real-engine (in-memory SQLite) coverage for {@see SyncController} — the
 * generalized sync engine. Exercises the same lifecycle the DemoCatalog pilot
 * proved by hand (idempotent create, optimistic-concurrency 409, tombstones,
 * the incremental changes feed, tenant isolation, the system-tenant exception),
 * but through the reusable engine against an arbitrary syncable table.
 */
final class SyncControllerTest extends TestCase
{
    private PDO $pdo;
    private SyncController $sync;

    protected function setUp(): void
    {
        $_GET = [];
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);
        $this->pdo->exec(
            'CREATE TABLE sync_test_notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                body TEXT,
                status TEXT NOT NULL DEFAULT \'open\',
                version INTEGER NOT NULL DEFAULT 1,
                client_uuid VARCHAR(36),
                deleted_at TIMESTAMP NULL,
                updated_by INTEGER NULL,
                change_seq INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )'
        );
        $this->pdo->exec('CREATE UNIQUE INDEX ux_notes_tenant_client ON sync_test_notes(tenant_id, client_uuid)');

        $this->sync = new SyncController($this->pdo, new FakeSequenceAllocator(), new TestNoteResource());
    }

    // ---- create ----

    public function testCreateReturns201WithSyncAndDomainFields(): void
    {
        $resp = $this->sync->create($this->body(['title' => 'First', 'status' => 'open']), 1);

        $this->assertSame(201, $resp->getStatusCode());
        $item = $this->data($resp);
        $this->assertSame('First', $item['title']);
        $this->assertSame(1, $item['tenantId']);
        $this->assertSame(1, $item['version']);
        $this->assertNull($item['deletedAt']);
        $this->assertIsString($item['clientUuid']);
        $this->assertArrayHasKey('createdAt', $item);
    }

    public function testCreateIsIdempotentOnClientUuid(): void
    {
        $uuid = '11111111-1111-4111-8111-111111111111';
        $first = $this->sync->create($this->body(['title' => 'A', 'clientUuid' => $uuid]), 1);
        $second = $this->sync->create($this->body(['title' => 'A retried', 'clientUuid' => $uuid]), 1);

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode(), 'a retried create returns 200, not a duplicate');
        $this->assertSame($this->data($first)['id'], $this->data($second)['id']);
        $count = $this->pdo->query('SELECT COUNT(*) FROM sync_test_notes');
        $this->assertNotFalse($count);
        $this->assertSame(1, (int) $count->fetchColumn());
    }

    public function testCreateRejectedInSystemTenantAndWithoutTenant(): void
    {
        $this->assertSame(403, $this->sync->create($this->body(['title' => 'x']), 0)->getStatusCode());
        $this->assertSame(403, $this->sync->create($this->body(['title' => 'x']), null)->getStatusCode());
    }

    public function testCreateValidationFailureIs400(): void
    {
        $resp = $this->sync->create($this->body(['title' => '']), 1);
        $this->assertSame(400, $resp->getStatusCode());
    }

    // ---- update ----

    public function testUpdateBumpsVersion(): void
    {
        $id = $this->data($this->sync->create($this->body(['title' => 'A']), 1))['id'];

        $resp = $this->sync->update($this->body(['title' => 'B']), 1, $id);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('B', $this->data($resp)['title']);
        $this->assertSame(2, $this->data($resp)['version']);
    }

    public function testUpdateWithStaleBaseVersionReturns409WithServerItem(): void
    {
        $id = $this->data($this->sync->create($this->body(['title' => 'A']), 1))['id'];
        $this->sync->update($this->body(['title' => 'B']), 1, $id); // now version 2

        $resp = $this->sync->update($this->ifMatch(['title' => 'C'], 1), 1, $id); // stale base

        $this->assertSame(409, $resp->getStatusCode());
        $payload = json_decode($resp->getBody(), true);
        $this->assertSame('Version conflict', $payload['error']);
        $this->assertSame(2, $payload['serverItem']['version']);
        $this->assertSame('B', $payload['serverItem']['title'], 'the server value wins in the payload');
    }

    public function testUpdateMissingRowIs404(): void
    {
        $this->assertSame(404, $this->sync->update($this->body(['title' => 'x']), 1, 999)->getStatusCode());
    }

    // ---- delete ----

    public function testDeleteTombstonesAndIsIdempotent(): void
    {
        $id = $this->data($this->sync->create($this->body(['title' => 'A']), 1))['id'];

        $first = $this->sync->delete($this->body([]), 1, $id);
        $this->assertSame(200, $first->getStatusCode());
        $this->assertNotNull($this->data($first)['deletedAt']);

        $second = $this->sync->delete($this->body([]), 1, $id);
        $this->assertSame(200, $second->getStatusCode(), 'deleting an already-tombstoned row is idempotent');

        // The live list no longer shows it.
        $list = $this->data($this->sync->list($this->req('GET', '/notes'), 1));
        $this->assertCount(0, $list);
    }

    // ---- changes feed ----

    public function testChangesFeedIsIncrementalWithCursorAndHasMore(): void
    {
        foreach (['A', 'B', 'C'] as $t) {
            $this->sync->create($this->body(['title' => $t]), 1);
        }

        $page1 = $this->sync->list($this->req('GET', '/notes?updatedSince=0&limit=2'), 1);
        $body1 = json_decode($page1->getBody(), true);
        $this->assertCount(2, $body1['data']);
        $this->assertTrue($body1['hasMore']);

        $page2 = $this->sync->list($this->req('GET', '/notes?updatedSince=' . $body1['cursor'] . '&limit=2'), 1);
        $body2 = json_decode($page2->getBody(), true);
        $this->assertCount(1, $body2['data']);
        $this->assertFalse($body2['hasMore']);
        $this->assertSame('C', $body2['data'][0]['title']);
    }

    public function testChangesFeedPropagatesTombstones(): void
    {
        $id = $this->data($this->sync->create($this->body(['title' => 'A']), 1))['id'];
        $afterCreate = json_decode($this->sync->list($this->req('GET', '/notes?updatedSince=0'), 1)->getBody(), true);
        $cursor = $afterCreate['cursor'];

        $this->sync->delete($this->body([]), 1, $id);

        $feed = json_decode($this->sync->list($this->req('GET', '/notes?updatedSince=' . $cursor), 1)->getBody(), true);
        $this->assertCount(1, $feed['data'], 'the delete surfaces as a change');
        $this->assertNotNull($feed['data'][0]['deletedAt'], 'and it is a tombstone');
    }

    // ---- tenant isolation ----

    public function testTenantIsolationAndSystemTenantSeesAll(): void
    {
        $this->sync->create($this->body(['title' => 'T1']), 1);
        $this->sync->create($this->body(['title' => 'T2']), 2);

        $this->assertCount(1, $this->data($this->sync->list($this->req('GET', '/notes'), 1)));
        $this->assertCount(1, $this->data($this->sync->list($this->req('GET', '/notes'), 2)));
        $this->assertCount(2, $this->data($this->sync->list($this->req('GET', '/notes'), 0)), 'system tenant sees all');
    }

    public function testGetIsTenantScoped(): void
    {
        $id = $this->data($this->sync->create($this->body(['title' => 'T1']), 1))['id'];

        $this->assertSame(200, $this->sync->get($this->req('GET', '/x'), 1, $id)->getStatusCode());
        $this->assertSame(404, $this->sync->get($this->req('GET', '/x'), 2, $id)->getStatusCode(), 'other tenant cannot read it');
    }

    // ---- guards ----

    public function testConstructorRejectsUnsafeIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SyncController($this->pdo, new FakeSequenceAllocator(), new UnsafeResource());
    }

    // ---- helpers ----

    /** @param array<string, mixed> $fields */
    private function body(array $fields): Request
    {
        return new Request('POST', '/notes', [], json_encode($fields, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $fields */
    private function ifMatch(array $fields, int $version): Request
    {
        return new Request('PATCH', '/notes', ['If-Match' => (string) $version], json_encode($fields, JSON_THROW_ON_ERROR));
    }

    private function req(string $method, string $path): Request
    {
        return new Request($method, $path, [], '');
    }

    /** @return array<string, mixed> */
    private function data(\Whity\Sdk\Http\Response $resp): array
    {
        $decoded = json_decode($resp->getBody(), true);

        return $decoded['data'];
    }
}

/** A minimal syncable resource for the tests. */
class TestNoteResource implements SyncableResource
{
    public function table(): string
    {
        return 'sync_test_notes';
    }

    public function sequenceKey(): string
    {
        return 'test:notes:change_seq';
    }

    public function domainColumns(): array
    {
        return ['title', 'body', 'status'];
    }

    public function validate(array $body, bool $requireAll): array
    {
        $title = $body['title'] ?? null;
        if ($requireAll && (!is_string($title) || trim($title) === '')) {
            return ['ok' => false, 'error' => 'title is required'];
        }
        $status = $body['status'] ?? 'open';
        if (!in_array($status, ['open', 'done'], true)) {
            return ['ok' => false, 'error' => 'status must be open or done'];
        }

        return ['ok' => true, 'values' => [
            'title' => (string) $title,
            'body' => isset($body['body']) && is_string($body['body']) ? $body['body'] : null,
            'status' => $status,
        ]];
    }

    public function toPublicFields(array $row): array
    {
        return [
            'title' => (string) ($row['title'] ?? ''),
            'body' => isset($row['body']) && $row['body'] !== null ? (string) $row['body'] : null,
            'status' => (string) ($row['status'] ?? 'open'),
        ];
    }
}

/** A resource with an unsafe table identifier, to prove the constructor guard. */
final class UnsafeResource extends TestNoteResource
{
    public function table(): string
    {
        return 'notes; DROP TABLE users';
    }
}

/** In-memory monotonic allocator; only nextPlatformWide() is exercised. */
final class FakeSequenceAllocator implements SequenceAllocator
{
    /** @var array<string, int> */
    private array $counters = [];

    public function next(int $tenantId, string $name, int $step = 1): int
    {
        return $this->bump($tenantId . ':' . $name, $step);
    }

    public function nextBlock(int $tenantId, string $name, int $count): array
    {
        $last = $this->bump($tenantId . ':' . $name, $count);

        return ['first' => $last - $count + 1, 'last' => $last];
    }

    public function nextPlatformWide(string $name, int $step = 1): int
    {
        return $this->bump('*:' . $name, $step);
    }

    public function peek(int $tenantId, string $name): int
    {
        return $this->counters[$tenantId . ':' . $name] ?? 0;
    }

    private function bump(string $key, int $step): int
    {
        $this->counters[$key] = ($this->counters[$key] ?? 0) + $step;

        return $this->counters[$key];
    }
}
