<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\JsonRpc;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpTokenService;
use Whity\Mcp\JsonRpc\Dispatcher;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\MethodHandler;
use Whity\Mcp\Lifecycle\PingHandler;

/**
 * TDD tests for Dispatcher tenant-binding (WC-06a7133c).
 *
 * Verifies that when a TokenValidator is provided, every MCP call:
 *  - is rejected with UNAUTHENTICATED (-32001) if the bearer token is absent or invalid;
 *  - sets TenantContext to the principal's tenant ID before invoking the handler;
 *  - resets TenantContext in a finally block so FrankenPHP workers see no bleed.
 *
 * Uses SchemaFromMigrations + McpTokenService to issue real tokens so the full
 * validation path (jti lookup, revocation check) is exercised.
 */
final class DispatcherTenantBindingTest extends TestCase
{
    private const JWT_SECRET = 'dispatcher-tenant-test-secret-padded-32c';
    private const USER_ID    = 2;
    private const TENANT_ID  = 5;

    private JwtParser $jwtParser;
    private \PDO $pdo;
    private TokenValidator $validator;
    private McpTokenService $service;

    protected function setUp(): void
    {
        $this->jwtParser = new JwtParser(self::JWT_SECRET);
        $this->pdo       = SchemaFromMigrations::make();
        $this->seedFixtures();
        $this->validator = new TokenValidator($this->jwtParser, $this->pdo);
        $this->service   = new McpTokenService($this->pdo, $this->jwtParser);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ── Auth rejection ────────────────────────────────────────────────────────

    public function testHandle_returnsUnauthenticated_whenNoBearerToken(): void
    {
        $dispatcher = new Dispatcher(['ping' => new PingHandler()], $this->validator);
        $r = $this->decode($dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', null));

        self::assertSame(ErrorCode::UNAUTHENTICATED, $r['error']['code']);
        self::assertNull($r['id']);
    }

    public function testHandle_returnsUnauthenticated_whenInvalidBearerToken(): void
    {
        $dispatcher = new Dispatcher(['ping' => new PingHandler()], $this->validator);
        $r = $this->decode($dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', 'not.a.valid.token'));

        self::assertSame(ErrorCode::UNAUTHENTICATED, $r['error']['code']);
    }

    public function testHandle_acceptsSessionAccessToken_whenUserAndEpochValid(): void
    {
        // Post-cutover: session access tokens carry {profile_id, active_tenant_id}.
        // The fixture seeds profile id=USER_ID with an active membership in TENANT_ID.
        $accessToken = $this->jwtParser->create(
            ['profile_id' => self::USER_ID, 'active_tenant_id' => self::TENANT_ID, 'token_epoch' => 0],
            900,
            'access'
        );
        $dispatcher = new Dispatcher(['ping' => new PingHandler()], $this->validator);
        $r = $this->decode($dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', $accessToken));

        self::assertArrayHasKey('result', $r, 'Session access token must be accepted for MCP');
        self::assertSame(1, $r['id']);
    }

    public function testHandle_returnsUnauthenticated_whenAccessTokenUserNotInDb(): void
    {
        // Profile 9999 does not exist in the fixture DB — epoch check fails closed.
        $accessToken = $this->jwtParser->create(
            ['profile_id' => 9999, 'active_tenant_id' => self::TENANT_ID, 'token_epoch' => 0],
            900,
            'access'
        );
        $dispatcher = new Dispatcher(['ping' => new PingHandler()], $this->validator);
        $r = $this->decode($dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', $accessToken));

        self::assertSame(ErrorCode::UNAUTHENTICATED, $r['error']['code']);
    }

    // ── Tenant binding ────────────────────────────────────────────────────────

    public function testHandle_setsTenantContextBeforeHandlerRuns(): void
    {
        $captured = new \stdClass();
        $captured->tenantId = null;

        $captureHandler = new class ($captured) implements MethodHandler {
            public function __construct(private readonly \stdClass $captured) {}
            public function __invoke(?array $params, ?string $bearerToken): mixed
            {
                $this->captured->tenantId = TenantContext::getTenantId();
                return [];
            }
        };

        $token      = $this->service->issue(self::USER_ID, self::TENANT_ID, 'test', ['tools:call']);
        $dispatcher = new Dispatcher(['capture' => $captureHandler], $this->validator);
        $dispatcher->handle('{"jsonrpc":"2.0","method":"capture","id":1}', $token);

        self::assertSame(self::TENANT_ID, $captured->tenantId);
    }

    // ── Worker-safety: TenantContext reset in finally ─────────────────────────

    public function testHandle_resetsTenantContextAfterSuccessfulDispatch(): void
    {
        $token      = $this->service->issue(self::USER_ID, self::TENANT_ID, 'test', ['tools:call']);
        $dispatcher = new Dispatcher(['ping' => new PingHandler()], $this->validator);
        $dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', $token);

        self::assertNull(TenantContext::getTenantId(), 'TenantContext must be reset after dispatch');
    }

    public function testHandle_resetsTenantContextAfterHandlerThrows(): void
    {
        $throwingHandler = new class implements MethodHandler {
            public function __invoke(?array $params, ?string $bearerToken): mixed
            {
                throw new \RuntimeException('handler exploded');
            }
        };

        $token      = $this->service->issue(self::USER_ID, self::TENANT_ID, 'test', ['tools:call']);
        $dispatcher = new Dispatcher(['throw' => $throwingHandler], $this->validator);
        $dispatcher->handle('{"jsonrpc":"2.0","method":"throw","id":1}', $token);

        self::assertNull(TenantContext::getTenantId(), 'TenantContext must be reset even when handler throws');
    }

    public function testHandle_resetsTenantContextAfterParseError(): void
    {
        $token      = $this->service->issue(self::USER_ID, self::TENANT_ID, 'test', ['tools:call']);
        $dispatcher = new Dispatcher([], $this->validator);
        $dispatcher->handle('{invalid json}', $token);

        self::assertNull(TenantContext::getTenantId(), 'TenantContext must be reset even after parse error');
    }

    // ── Successful authenticated dispatch ─────────────────────────────────────

    public function testHandle_authenticatedRequest_returnsResult(): void
    {
        $token      = $this->service->issue(self::USER_ID, self::TENANT_ID, 'test', ['tools:call']);
        $dispatcher = new Dispatcher(['ping' => new PingHandler()], $this->validator);
        $r = $this->decode($dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":42}', $token));

        self::assertSame(42, $r['id']);
        self::assertArrayHasKey('result', $r);
    }

    public function testHandle_authenticatedBatch_dispatchesAll(): void
    {
        $token      = $this->service->issue(self::USER_ID, self::TENANT_ID, 'test', ['tools:call']);
        $dispatcher = new Dispatcher(['ping' => new PingHandler()], $this->validator);
        $raw = '[{"jsonrpc":"2.0","method":"ping","id":1},{"jsonrpc":"2.0","method":"ping","id":2}]';

        $responses = json_decode($dispatcher->handle($raw, $token), true);

        self::assertIsArray($responses);
        self::assertCount(2, $responses);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $data = json_decode($json, true);
        self::assertIsArray($data, "Expected JSON object response, got: {$json}");
        return $data;
    }

    private function seedFixtures(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (" . self::TENANT_ID . ", 'Tenant Five') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO roles (id, tenant_id, name) VALUES (1, " . self::TENANT_ID . ", 'admin') ON CONFLICT DO NOTHING");
        $hash = password_hash('pw', PASSWORD_BCRYPT);
        $this->pdo->prepare("INSERT INTO users (id, tenant_id, email, password, role_id, token_epoch) VALUES (?, ?, 'u@test.com', ?, 1, 0) ON CONFLICT DO NOTHING")
            ->execute([self::USER_ID, self::TENANT_ID, $hash]);

        // After migration 040, McpTokenService.issue() takes profile_id and emits
        // {profile_id, active_tenant_id} in JWT claims. The membership guard requires
        // an active membership for the profile in the declared tenant.
        // Seed profile id=USER_ID (matching) and a membership in TENANT_ID.
        $this->pdo->prepare("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (?, 'Test Profile', ?, false, 0, 0, datetime('now'), datetime('now'))
            ON CONFLICT DO NOTHING
        ")->execute([self::USER_ID, $hash]);

        $this->pdo->prepare("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
            VALUES (?, ?, 1, 'active', datetime('now'))
            ON CONFLICT DO NOTHING
        ")->execute([self::USER_ID, self::TENANT_ID]);
    }
}
