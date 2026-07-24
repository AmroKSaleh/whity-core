<?php

declare(strict_types=1);

namespace Tests\Plugins;

use DemoCatalog\Api\DemoCatalogApiHandler;
use DemoCatalog\DemoCatalogPlugin;
use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Request;

require_once dirname(__DIR__, 2) . '/plugins/DemoCatalog/DemoCatalogPlugin.php';
require_once dirname(__DIR__, 2) . '/plugins/DemoCatalog/Api/DemoCatalogApiHandler.php';

/**
 * Real-engine (in-memory SQLite) tests for the DemoCatalog pilot plugin's
 * tenant-scoped items CRUD (mirrors HelloWorldGreetingsRealEngineTest).
 *
 * Drives {@see DemoCatalogApiHandler} against a genuine SQL engine so the real
 * INSERT/SELECT/UPDATE semantics are exercised, not mocked-PDO leniency.
 *
 * Acceptance focus:
 *  - CRUD happy paths with the documented camelCase payloads;
 *  - tenant A can never read/update tenant B's item (404, row untouched);
 *  - the SYSTEM tenant (id 0) sees all tenants;
 *  - validation 400s (missing/empty/too-long/non-string name, invalid status);
 *  - fail-closed 403 when the tenant context is unresolved;
 *  - the full production path: the route served through PluginLoader + Router
 *    with the PDO resolved from the host service container.
 */
final class DemoCatalogItemsRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const SYSTEM_TENANT = 0;

    private PDO $pdo;

    /** @var array<string, mixed> Saved service-container state to restore. */
    private array $savedServices = [];

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = self::makeSchema();
        $this->savedServices = $GLOBALS['whity_services'] ?? [];
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        $GLOBALS['whity_services'] = $this->savedServices;
    }

    // ==================== list ====================

    public function testListIsTenantScopedAndNewestFirst(): void
    {
        $this->seed(self::TENANT_A, 'a-oldest', '2026-01-01 10:00:00');
        $this->seed(self::TENANT_A, 'a-newest', '2026-06-01 10:00:00');
        $this->seed(self::TENANT_B, 'b-only', '2026-03-01 10:00:00');

        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->list(new Request('GET', '/api/demo-catalog/items'));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);

        $this->assertSame(['a-newest', 'a-oldest'], array_column($body['data'], 'name'), 'Newest first, tenant-scoped');
        foreach ($body['data'] as $row) {
            $this->assertSame(self::TENANT_A, $row['tenantId'], "Tenant B's rows must never appear");
            $this->assertIsInt($row['id']);
            $this->assertIsString($row['createdAt']);
        }
    }

    public function testSystemTenantSeesAllTenants(): void
    {
        $this->seed(self::TENANT_A, 'a-row', '2026-01-01 10:00:00');
        $this->seed(self::TENANT_B, 'b-row', '2026-02-01 10:00:00');

        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler()->list(new Request('GET', '/api/demo-catalog/items'));

        $body = json_decode($response->getBody(), true);
        $this->assertEqualsCanonicalizing(['a-row', 'b-row'], array_column($body['data'], 'name'));
    }

    // ==================== get ====================

    public function testGetOwnItemReturns200(): void
    {
        $id = $this->seed(self::TENANT_A, 'gettable', '2026-01-01 10:00:00');

        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->get(new Request('GET', "/api/demo-catalog/items/{$id}"), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('gettable', $body['data']['name']);
        $this->assertSame('active', $body['data']['status']);
    }

    public function testCrossTenantGetIs404(): void
    {
        $foreignId = $this->seed(self::TENANT_B, 'b-secret', '2026-01-01 10:00:00');

        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->get(new Request('GET', "/api/demo-catalog/items/{$foreignId}"), ['id' => (string) $foreignId]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('b-secret', $response->getBody(), 'The refusal must not leak the foreign row');
    }

    // ==================== create ====================

    public function testCreateStampsTheCallersTenantAndReturns201(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->create($this->jsonRequest('POST', '/api/demo-catalog/items', ['name' => 'Widget']));

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);

        $this->assertSame('Widget', $body['data']['name']);
        $this->assertSame(self::TENANT_A, $body['data']['tenantId']);
        $this->assertSame('active', $body['data']['status'], 'status defaults to active');
        $this->assertIsInt($body['data']['id']);
        $this->assertIsString($body['data']['createdAt']);

        $this->assertSame(
            ['tenant_id' => '1', 'name' => 'Widget'],
            $this->pdo->query('SELECT tenant_id, name FROM demo_catalog_items')->fetch(PDO::FETCH_ASSOC),
            'The row must be stamped with the CALLER\'s tenant'
        );
    }

    public function testCreateAcceptsDescriptionAndArchivedStatus(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->create($this->jsonRequest('POST', '/api/demo-catalog/items', [
            'name' => 'Gadget',
            'description' => 'A useful gadget',
            'status' => 'archived',
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('A useful gadget', $body['data']['description']);
        $this->assertSame('archived', $body['data']['status']);
    }

    public function testCreateValidates400s(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $handler = $this->handler();

        $cases = [
            'missing name' => [],
            'empty name' => ['name' => ''],
            'whitespace name' => ['name' => '   '],
            'non-string name' => ['name' => 42],
            'too-long name' => ['name' => str_repeat('x', 256)],
            'invalid status' => ['name' => 'ok', 'status' => 'deleted'],
        ];

        foreach ($cases as $label => $payload) {
            $response = $handler->create($this->jsonRequest('POST', '/api/demo-catalog/items', $payload));
            $this->assertSame(400, $response->getStatusCode(), "{$label} must be a 400");
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM demo_catalog_items')->fetchColumn());
    }

    public function testCreateAccepts255CharacterName(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $name = str_repeat('y', 255);

        $response = $this->handler()->create($this->jsonRequest('POST', '/api/demo-catalog/items', ['name' => $name]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame($name, json_decode($response->getBody(), true)['data']['name']);
    }

    // ==================== update ====================

    public function testUpdateOwnItemReturnsUpdatedRow(): void
    {
        $id = $this->seed(self::TENANT_A, 'before', '2026-01-01 10:00:00');

        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->update(
            $this->jsonRequest('PATCH', "/api/demo-catalog/items/{$id}", ['name' => 'after', 'status' => 'archived']),
            ['id' => (string) $id]
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('after', $body['data']['name']);
        $this->assertSame('archived', $body['data']['status']);
        $this->assertSame($id, $body['data']['id']);
        $this->assertSame(self::TENANT_A, $body['data']['tenantId']);
    }

    public function testCrossTenantUpdateIs404AndRowIsUntouched(): void
    {
        $foreignId = $this->seed(self::TENANT_B, 'b-original', '2026-01-01 10:00:00');

        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->update(
            $this->jsonRequest('PATCH', "/api/demo-catalog/items/{$foreignId}", ['name' => 'hijacked']),
            ['id' => (string) $foreignId]
        );

        $this->assertSame(404, $response->getStatusCode(), 'Cross-tenant id probing must report not-found');
        $this->assertStringNotContainsString('b-original', $response->getBody(), 'The refusal must not leak the foreign row');
        $this->assertSame(
            'b-original',
            $this->pdo->query("SELECT name FROM demo_catalog_items WHERE id = {$foreignId}")->fetchColumn(),
            "Tenant B's row must be untouched after the rejected update"
        );
    }

    public function testUpdateMissingRowIs404(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->update(
            $this->jsonRequest('PATCH', '/api/demo-catalog/items/999', ['name' => 'x']),
            ['id' => '999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateValidates400(): void
    {
        $id = $this->seed(self::TENANT_A, 'keep', '2026-01-01 10:00:00');

        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->update(
            $this->jsonRequest('PATCH', "/api/demo-catalog/items/{$id}", ['name' => '']),
            ['id' => (string) $id]
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('keep', $this->pdo->query("SELECT name FROM demo_catalog_items WHERE id = {$id}")->fetchColumn());
    }

    // ==================== fail-closed ====================

    public function testUnresolvedTenantContextFailsClosedOnEveryVerb(): void
    {
        // No TenantContext set.
        $handler = $this->handler();

        $this->assertSame(403, $handler->list(new Request('GET', '/api/demo-catalog/items'))->getStatusCode());
        $this->assertSame(403, $handler->get(new Request('GET', '/api/demo-catalog/items/1'), ['id' => '1'])->getStatusCode());
        $this->assertSame(
            403,
            $handler->create($this->jsonRequest('POST', '/api/demo-catalog/items', ['name' => 'x']))->getStatusCode()
        );
        $this->assertSame(
            403,
            $handler->update($this->jsonRequest('PATCH', '/api/demo-catalog/items/1', ['name' => 'x']), ['id' => '1'])->getStatusCode()
        );
    }

    // ==================== production path: loader + router + container ====================

    /**
     * The full wiring the live app uses: the DemoCatalog plugin is discovered
     * by the PluginLoader, the items route is matched by the Router, and the
     * handler resolves its PDO from the host's \Whity service container.
     */
    public function testItemsRouteServesThroughThePluginLoaderAndContainer(): void
    {
        $this->seed(self::TENANT_A, 'via-the-router', '2026-01-01 10:00:00');

        // Register a SQLite-backed Database service exactly where the host
        // registers its real one (public/index.php).
        $db = Database::withFactory(fn (): PDO => $this->pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();
        \Whity\register_service(Database::class, $db);

        $router = new Router('');
        $loader = new PluginLoader(dirname(__DIR__, 2) . '/plugins', $router, new PermissionRegistry(), new HookManager());
        $loader->load();

        $request = new Request('GET', '/api/demo-catalog/items');
        $match = $router->match($request);
        $this->assertNotNull($match, 'The items route must be registered by the plugin');
        $this->assertSame('demo_catalog:view', $match['requiredPermission']);

        TenantContext::setTenantId(self::TENANT_A);
        $response = ($match['handler'])($request, $match['params']);

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(['via-the-router'], array_column($body['data'], 'name'));
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

    private function seed(int $tenantId, string $name, string $createdAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO demo_catalog_items (tenant_id, name, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$tenantId, $name, 'active', $createdAt, $createdAt]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * In-memory SQLite mirroring the plugin's demo_catalog_items migration.
     * STRINGIFY_FETCHES mirrors PostgreSQL string fetches; NOW() is provided
     * so the handler's PostgreSQL-flavoured SQL runs unmodified.
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
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ');

        return $pdo;
    }
}
