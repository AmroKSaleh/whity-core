<?php

declare(strict_types=1);

namespace Tests\Plugins;

use PDO;
use PHPUnit\Framework\TestCase;
use HelloWorld\Api\GreetingsApiHandler;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Request;

require_once dirname(__DIR__, 2) . '/plugins/HelloWorld/HelloWorldPlugin.php';
require_once dirname(__DIR__, 2) . '/plugins/HelloWorld/Api/GreetingsApiHandler.php';

/**
 * Real-engine (in-memory SQLite) tests for the HelloWorld reference plugin's
 * tenant-scoped greetings CRUD (WC-169).
 *
 * Drives {@see GreetingsApiHandler} against a genuine SQL engine so the real
 * INSERT/SELECT/UPDATE/DELETE semantics are exercised, not mocked-PDO
 * leniency. The connection mirrors PostgreSQL's string fetches via
 * PDO::ATTR_STRINGIFY_FETCHES and provides NOW() per the project rule.
 *
 * Acceptance focus:
 *  - CRUD happy paths with the documented camelCase payloads;
 *  - tenant A can never read/update/delete tenant B's greeting (404, row untouched);
 *  - the SYSTEM tenant (id 0) sees all tenants;
 *  - validation 400s (missing/empty/too-long/non-string message);
 *  - fail-closed 403 when the tenant context is unresolved;
 *  - the full production path: the route served through PluginLoader + Router
 *    with the PDO resolved from the host service container.
 */
final class HelloWorldGreetingsRealEngineTest extends TestCase
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
        $response = $this->handler()->list(new Request('GET', '/api/hello/greetings'));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);

        $this->assertSame(['a-newest', 'a-oldest'], array_column($body['data'], 'message'), 'Newest first, tenant-scoped');
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
        $response = $this->handler()->list(new Request('GET', '/api/hello/greetings'));

        $body = json_decode($response->getBody(), true);
        $this->assertEqualsCanonicalizing(['a-row', 'b-row'], array_column($body['data'], 'message'));
    }

    // ==================== create ====================

    public function testCreateStampsTheCallersTenantAndReturns201(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->create($this->jsonRequest('POST', '/api/hello/greetings', ['message' => 'Hello there']));

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);

        $this->assertSame('Hello there', $body['data']['message']);
        $this->assertSame(self::TENANT_A, $body['data']['tenantId']);
        $this->assertIsInt($body['data']['id']);
        $this->assertIsString($body['data']['createdAt']);

        $this->assertSame(
            ['tenant_id' => '1', 'message' => 'Hello there'],
            $this->pdo->query('SELECT tenant_id, message FROM hello_greetings')->fetch(PDO::FETCH_ASSOC),
            'The row must be stamped with the CALLER\'s tenant'
        );
    }

    public function testCreateValidates400s(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $handler = $this->handler();

        $cases = [
            'missing message' => [],
            'empty message' => ['message' => ''],
            'whitespace message' => ['message' => '   '],
            'non-string message' => ['message' => 42],
            'too-long message' => ['message' => str_repeat('x', 256)],
        ];

        foreach ($cases as $label => $payload) {
            $response = $handler->create($this->jsonRequest('POST', '/api/hello/greetings', $payload));
            $this->assertSame(400, $response->getStatusCode(), "{$label} must be a 400");
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM hello_greetings')->fetchColumn());
    }

    public function testCreateAccepts255CharacterMessage(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $message = str_repeat('y', 255);

        $response = $this->handler()->create($this->jsonRequest('POST', '/api/hello/greetings', ['message' => $message]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame($message, json_decode($response->getBody(), true)['data']['message']);
    }

    // ==================== update ====================

    public function testUpdateOwnGreetingReturnsUpdatedRow(): void
    {
        $id = $this->seed(self::TENANT_A, 'before', '2026-01-01 10:00:00');

        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->update(
            $this->jsonRequest('PATCH', "/api/hello/greetings/{$id}", ['message' => 'after']),
            ['id' => (string) $id]
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('after', $body['data']['message']);
        $this->assertSame($id, $body['data']['id']);
        $this->assertSame(self::TENANT_A, $body['data']['tenantId']);
    }

    public function testCrossTenantUpdateIs404AndRowIsUntouched(): void
    {
        $foreignId = $this->seed(self::TENANT_B, 'b-original', '2026-01-01 10:00:00');

        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->update(
            $this->jsonRequest('PATCH', "/api/hello/greetings/{$foreignId}", ['message' => 'hijacked']),
            ['id' => (string) $foreignId]
        );

        $this->assertSame(404, $response->getStatusCode(), 'Cross-tenant id probing must report not-found');
        $this->assertStringNotContainsString('b-original', $response->getBody(), 'The refusal must not leak the foreign row');
        $this->assertSame(
            'b-original',
            $this->pdo->query("SELECT message FROM hello_greetings WHERE id = {$foreignId}")->fetchColumn(),
            "Tenant B's row must be untouched after the rejected update"
        );
    }

    public function testUpdateMissingRowIs404(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->update(
            $this->jsonRequest('PATCH', '/api/hello/greetings/999', ['message' => 'x']),
            ['id' => '999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateValidates400(): void
    {
        $id = $this->seed(self::TENANT_A, 'keep', '2026-01-01 10:00:00');

        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->update(
            $this->jsonRequest('PATCH', "/api/hello/greetings/{$id}", ['message' => '']),
            ['id' => (string) $id]
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('keep', $this->pdo->query("SELECT message FROM hello_greetings WHERE id = {$id}")->fetchColumn());
    }

    // ==================== delete ====================

    public function testDeleteOwnGreetingReturnsConfirmation(): void
    {
        $id = $this->seed(self::TENANT_A, 'goodbye', '2026-01-01 10:00:00');

        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->delete(new Request('DELETE', "/api/hello/greetings/{$id}"), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['data' => ['id' => $id, 'message' => 'Greeting deleted']],
            json_decode($response->getBody(), true)
        );
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM hello_greetings')->fetchColumn());
    }

    public function testCrossTenantDeleteIs404AndRowSurvives(): void
    {
        $foreignId = $this->seed(self::TENANT_B, 'b-survivor', '2026-01-01 10:00:00');

        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler()->delete(
            new Request('DELETE', "/api/hello/greetings/{$foreignId}"),
            ['id' => (string) $foreignId]
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM hello_greetings WHERE id = {$foreignId}")->fetchColumn(),
            "Tenant B's greeting must survive a cross-tenant delete attempt"
        );
    }

    // ==================== fail-closed ====================

    public function testUnresolvedTenantContextFailsClosedOnEveryVerb(): void
    {
        // No TenantContext set.
        $handler = $this->handler();

        $this->assertSame(403, $handler->list(new Request('GET', '/api/hello/greetings'))->getStatusCode());
        $this->assertSame(
            403,
            $handler->create($this->jsonRequest('POST', '/api/hello/greetings', ['message' => 'x']))->getStatusCode()
        );
        $this->assertSame(
            403,
            $handler->update($this->jsonRequest('PATCH', '/api/hello/greetings/1', ['message' => 'x']), ['id' => '1'])->getStatusCode()
        );
        $this->assertSame(
            403,
            $handler->delete(new Request('DELETE', '/api/hello/greetings/1'), ['id' => '1'])->getStatusCode()
        );
    }

    // ==================== production path: loader + router + container ====================

    /**
     * The full wiring the live app uses: the HelloWorld plugin is discovered by
     * the PluginLoader, the greetings route is matched by the Router, and the
     * handler resolves its PDO from the host's \Whity service container.
     */
    public function testGreetingsRouteServesThroughThePluginLoaderAndContainer(): void
    {
        $this->seed(self::TENANT_A, 'via-the-router', '2026-01-01 10:00:00');

        // Register a SQLite-backed Database service exactly where the host
        // registers its real one (public/index.php).
        $db = Database::withFactory(fn (): PDO => $this->pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();
        \Whity\register_service(Database::class, $db);

        $router = new Router();
        $loader = new PluginLoader(dirname(__DIR__, 2) . '/plugins', $router, new PermissionRegistry(), new HookManager());
        $loader->load();

        $request = new Request('GET', '/api/hello/greetings');
        $match = $router->match($request);
        $this->assertNotNull($match, 'The greetings route must be registered by the plugin');
        $this->assertSame('hello:view', $match['requiredPermission'], 'Reads are gated on hello:view');

        TenantContext::setTenantId(self::TENANT_A);
        $response = ($match['handler'])($request, $match['params']);

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(['via-the-router'], array_column($body['data'], 'message'));
    }

    // ==================== helpers ====================

    private function handler(): GreetingsApiHandler
    {
        return new GreetingsApiHandler($this->pdo);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body): Request
    {
        return new Request($method, $path, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    private function seed(int $tenantId, string $message, string $createdAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hello_greetings (tenant_id, message, created_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$tenantId, $message, $createdAt]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * In-memory SQLite mirroring the plugin's hello_greetings migration.
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
            CREATE TABLE hello_greetings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                message TEXT NOT NULL,
                created_at TEXT NOT NULL
            )
        ');

        return $pdo;
    }
}
