<?php

declare(strict_types=1);

namespace Tests\Plugins;

use DemoCatalog\Api\DemoCatalogApiHandler;
use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Request;

require_once dirname(__DIR__, 2) . '/plugins/DemoCatalog/Api/DemoCatalogApiHandler.php';

/**
 * Real-engine (in-memory SQLite) tests for the DemoCatalog SYNC affordances
 * (WC-desktop-sync): idempotent create on clientUuid, optimistic-concurrency
 * update (409), soft-delete tombstones, and the incremental changes feed.
 *
 * Drives {@see DemoCatalogApiHandler} against a genuine SQL engine so the real
 * INSERT ... ON CONFLICT / conditional UPDATE / change_seq semantics run — the
 * same SQL the postgres-integration CI job exercises.
 */
final class DemoCatalogSyncRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = self::makeSchema();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testCreateIsIdempotentOnClientUuid(): void
    {
        TenantContext::setTenantId(self::TENANT_A);

        $first = $this->handler()->create($this->jsonRequest('POST', '/api/demo-catalog/items', [
            'name' => 'Widget', 'clientUuid' => 'uuid-1',
        ]));
        self::assertSame(201, $first->getStatusCode(), $first->getBody());
        $created = json_decode($first->getBody(), true)['data'];
        self::assertSame('uuid-1', $created['clientUuid']);
        self::assertSame(1, $created['version']);

        // Same clientUuid → idempotent replay (200), same row, no duplicate.
        $replay = $this->handler()->create($this->jsonRequest('POST', '/api/demo-catalog/items', [
            'name' => 'Widget (retried)', 'clientUuid' => 'uuid-1',
        ]));
        self::assertSame(200, $replay->getStatusCode(), $replay->getBody());
        self::assertSame($created['id'], json_decode($replay->getBody(), true)['data']['id']);

        $countStmt = $this->pdo->query("SELECT COUNT(*) FROM demo_catalog_items WHERE client_uuid='uuid-1'");
        $count = $countStmt === false ? -1 : (int) $countStmt->fetchColumn();
        self::assertSame(1, $count);

        // The SAME clientUuid in a DIFFERENT tenant is a distinct row.
        // (TenantContext locks after the first set within a request — reset to
        // simulate a fresh request as a different tenant.)
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_B);
        $other = $this->handler()->create($this->jsonRequest('POST', '/api/demo-catalog/items', [
            'name' => 'B widget', 'clientUuid' => 'uuid-1',
        ]));
        self::assertSame(201, $other->getStatusCode(), $other->getBody());
        self::assertNotSame($created['id'], json_decode($other->getBody(), true)['data']['id']);
    }

    public function testUpdateUsesOptimisticConcurrency(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $id = json_decode(
            $this->handler()->create($this->jsonRequest('POST', '/api/demo-catalog/items', [
                'name' => 'Doc', 'clientUuid' => 'u',
            ]))->getBody(),
            true
        )['data']['id'];

        // baseVersion 1 matches → 200, version bumps to 2.
        $ok = $this->handler()->update(
            $this->jsonRequest('PATCH', "/api/demo-catalog/items/{$id}", ['name' => 'Doc v2', 'baseVersion' => 1]),
            ['id' => (string) $id]
        );
        self::assertSame(200, $ok->getStatusCode(), $ok->getBody());
        self::assertSame(2, json_decode($ok->getBody(), true)['data']['version']);

        // Stale baseVersion 1 → 409 with the current serverItem (version 2).
        $conflict = $this->handler()->update(
            $this->jsonRequest('PATCH', "/api/demo-catalog/items/{$id}", ['name' => 'Doc stale', 'baseVersion' => 1]),
            ['id' => (string) $id]
        );
        self::assertSame(409, $conflict->getStatusCode(), $conflict->getBody());
        $server = json_decode($conflict->getBody(), true)['serverItem'];
        self::assertSame(2, $server['version']);
        self::assertSame('Doc v2', $server['name']);
    }

    public function testSoftDeleteHidesFromListButAppearsAsTombstoneInFeed(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $id = json_decode(
            $this->handler()->create($this->jsonRequest('POST', '/api/demo-catalog/items', [
                'name' => 'Temp', 'clientUuid' => 'u',
            ]))->getBody(),
            true
        )['data']['id'];

        $del = $this->handler()->delete($this->jsonRequest('DELETE', "/api/demo-catalog/items/{$id}", []), ['id' => (string) $id]);
        self::assertSame(200, $del->getStatusCode(), $del->getBody());
        self::assertNotNull(json_decode($del->getBody(), true)['data']['deletedAt']);

        // Default list excludes tombstones.
        $list = $this->handler()->list(new Request('GET', '/api/demo-catalog/items'));
        self::assertSame([], json_decode($list->getBody(), true)['data']);

        // The changes feed includes the tombstone (so deletions propagate).
        $feed = $this->handler()->list(new Request('GET', '/api/demo-catalog/items?updatedSince=0'));
        $rows = json_decode($feed->getBody(), true)['data'];
        self::assertCount(1, $rows);
        self::assertSame($id, $rows[0]['id']);
        self::assertNotNull($rows[0]['deletedAt']);

        // Idempotent re-delete returns the tombstone (200).
        $again = $this->handler()->delete($this->jsonRequest('DELETE', "/api/demo-catalog/items/{$id}", []), ['id' => (string) $id]);
        self::assertSame(200, $again->getStatusCode(), $again->getBody());
    }

    public function testChangesFeedPaginatesByCursor(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        foreach (['a', 'b', 'c'] as $u) {
            $this->handler()->create($this->jsonRequest('POST', '/api/demo-catalog/items', ['name' => $u, 'clientUuid' => $u]));
        }

        $page1 = json_decode(
            $this->handler()->list(new Request('GET', '/api/demo-catalog/items?updatedSince=0&limit=2'))->getBody(),
            true
        );
        self::assertCount(2, $page1['data']);
        self::assertTrue($page1['hasMore']);

        $page2 = json_decode(
            $this->handler()->list(new Request('GET', "/api/demo-catalog/items?updatedSince={$page1['cursor']}&limit=2"))->getBody(),
            true
        );
        self::assertCount(1, $page2['data']);
        self::assertFalse($page2['hasMore']);

        // The three rows are strictly change_seq-ordered across the two pages.
        $names = array_merge(
            array_column($page1['data'], 'name'),
            array_column($page2['data'], 'name')
        );
        self::assertSame(['a', 'b', 'c'], $names);
    }

    // ==================== helpers ====================

    private function handler(): DemoCatalogApiHandler
    {
        return new DemoCatalogApiHandler($this->pdo);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body): Request
    {
        return new Request($method, $path, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    /**
     * In-memory SQLite mirroring demo_catalog_items AFTER both migrations
     * (base + AddSyncColumnsToDemoCatalogItems), plus the global change_seq
     * counter. NOW() is provided so the handler's SQL runs unmodified.
     */
    private static function makeSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        $pdo->exec('
            CREATE TABLE demo_catalog_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                description TEXT,
                status TEXT NOT NULL DEFAULT \'active\',
                version INTEGER NOT NULL DEFAULT 1,
                client_uuid VARCHAR(36),
                deleted_at TIMESTAMP NULL,
                updated_by INTEGER,
                change_seq BIGINT NOT NULL DEFAULT 0,
                created_at TEXT,
                updated_at TEXT
            )
        ');
        $pdo->exec(
            'CREATE UNIQUE INDEX idx_demo_catalog_items_tenant_uuid ON demo_catalog_items(tenant_id, client_uuid)'
        );
        $pdo->exec('CREATE TABLE demo_catalog_change_seq (seq BIGINT NOT NULL)');
        $pdo->exec('INSERT INTO demo_catalog_change_seq (seq) VALUES (0)');

        return $pdo;
    }
}
