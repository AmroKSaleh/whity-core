<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\JsonRpc;

use PHPUnit\Framework\TestCase;
use Whity\Auth\TokenValidator;
use Whity\Core\Store\ArraySharedStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\JsonRpc\Dispatcher;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\MethodHandler;
use Whity\Mcp\Lifecycle\InitializeHandler;
use Whity\Mcp\Lifecycle\PingHandler;
use Whity\Mcp\McpFeatureDisabledException;
use Whity\Mcp\RateLimit\McpRateLimitException;
use Whity\Mcp\RateLimit\McpRateLimiter;
use Whity\Mcp\Transport\McpRequestHandlerInterface;

final class DispatcherTest extends TestCase
{
    // ── Interface compliance ──────────────────────────────────────────────────

    public function testDispatcherImplementsMcpRequestHandlerInterface(): void
    {
        self::assertInstanceOf(McpRequestHandlerInterface::class, new Dispatcher([]));
    }

    // ── Parse error (-32700) ──────────────────────────────────────────────────

    public function testHandleReturnsParseError_onInvalidJson(): void
    {
        $r = $this->decode((new Dispatcher([]))->handle('{not valid json}', null));

        self::assertSame('2.0', $r['jsonrpc']);
        self::assertNull($r['id']);
        self::assertSame(ErrorCode::PARSE_ERROR, $r['error']['code']);
    }

    // ── Invalid request (-32600) ──────────────────────────────────────────────

    public function testHandleReturnsInvalidRequest_onMissingJsonrpcVersion(): void
    {
        $r = $this->decode((new Dispatcher([]))->handle('{"method":"ping","id":1}', null));
        self::assertSame(ErrorCode::INVALID_REQUEST, $r['error']['code']);
    }

    public function testHandleReturnsInvalidRequest_onWrongJsonrpcVersion(): void
    {
        $r = $this->decode((new Dispatcher([]))->handle('{"jsonrpc":"1.0","method":"ping","id":1}', null));
        self::assertSame(ErrorCode::INVALID_REQUEST, $r['error']['code']);
    }

    public function testHandleReturnsInvalidRequest_onMissingMethod(): void
    {
        $r = $this->decode((new Dispatcher([]))->handle('{"jsonrpc":"2.0","id":1}', null));
        self::assertSame(ErrorCode::INVALID_REQUEST, $r['error']['code']);
    }

    public function testHandleReturnsInvalidRequest_onNonObjectJson(): void
    {
        // A JSON string is valid JSON but not a valid JSON-RPC request
        $r = $this->decode((new Dispatcher([]))->handle('"just a string"', null));
        self::assertSame(ErrorCode::INVALID_REQUEST, $r['error']['code']);
    }

    // ── Method not found (-32601) ─────────────────────────────────────────────

    public function testHandleReturnsMethodNotFound_onUnknownMethod(): void
    {
        $r = $this->decode((new Dispatcher([]))->handle('{"jsonrpc":"2.0","method":"unknown/method","id":7}', null));
        self::assertSame(ErrorCode::METHOD_NOT_FOUND, $r['error']['code']);
        self::assertSame(7, $r['id']);
    }

    // ── Internal error (-32603) — no raw message leakage ─────────────────────

    public function testHandleDoesNotLeakExceptionMessage_onHandlerThrow(): void
    {
        $throwingHandler = new class implements MethodHandler {
            public function __invoke(?array $params, ?string $bearerToken): mixed
            {
                throw new \RuntimeException('SENSITIVE: db-password=hunter2');
            }
        };

        $dispatcher = new Dispatcher(['explode' => $throwingHandler]);
        $raw        = $dispatcher->handle('{"jsonrpc":"2.0","method":"explode","id":99}', null);
        $r          = $this->decode($raw);

        self::assertSame(ErrorCode::INTERNAL_ERROR, $r['error']['code']);
        self::assertStringNotContainsString('hunter2', $raw);
        self::assertStringNotContainsString('SENSITIVE', $raw);
    }

    // ── Notifications (no id key → no response body) ─────────────────────────

    public function testHandleReturnsEmptyString_onNotification(): void
    {
        $dispatcher = new Dispatcher(['notifications/cancelled' => new class implements MethodHandler {
            public function __invoke(?array $params, ?string $bearerToken): mixed { return null; }
        }]);

        self::assertSame('', $dispatcher->handle(
            '{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":"req-1"}}',
            null
        ));
    }

    public function testHandleReturnsEmptyString_onUnknownNotification(): void
    {
        // Spec: unknown notification methods must be silently ignored
        self::assertSame(
            '',
            (new Dispatcher([]))->handle('{"jsonrpc":"2.0","method":"unknown/notification"}', null)
        );
    }

    public function testNotificationHandler_isStillInvoked(): void
    {
        $log = new \stdClass();
        $log->count = 0;

        $handler = new class($log) implements MethodHandler {
            public function __construct(private readonly \stdClass $log) {}
            public function __invoke(?array $params, ?string $bearerToken): mixed
            {
                $this->log->count++;
                return null;
            }
        };

        $dispatcher = new Dispatcher(['notifications/cancelled' => $handler]);
        $dispatcher->handle('{"jsonrpc":"2.0","method":"notifications/cancelled"}', null);

        self::assertSame(1, $log->count);
    }

    // ── Token + params propagation ────────────────────────────────────────────

    public function testHandlePassesBearerTokenAndParamsToHandler(): void
    {
        // Handler reflects its inputs back in the result so we can verify without references
        $echoHandler = new class implements MethodHandler {
            public function __invoke(?array $params, ?string $bearerToken): mixed
            {
                return ['bearer' => $bearerToken, 'params' => $params];
            }
        };

        $dispatcher = new Dispatcher(['echo' => $echoHandler]);
        $raw = '{"jsonrpc":"2.0","method":"echo","params":{"x":1},"id":5}';
        $r   = $this->decode($dispatcher->handle($raw, 'tok123'));

        self::assertSame('tok123', $r['result']['bearer']);
        self::assertSame(['x' => 1], $r['result']['params']);
    }

    public function testHandlePassesNullBearerWhenAbsent(): void
    {
        $echoHandler = new class implements MethodHandler {
            public function __invoke(?array $params, ?string $bearerToken): mixed
            {
                return ['bearer' => $bearerToken];
            }
        };

        $dispatcher = new Dispatcher(['echo' => $echoHandler]);
        $r = $this->decode($dispatcher->handle('{"jsonrpc":"2.0","method":"echo","id":1}', null));

        self::assertNull($r['result']['bearer']);
    }

    // ── Batch requests ────────────────────────────────────────────────────────

    public function testHandleBatch_returnsArrayOfResponses(): void
    {
        $dispatcher = new Dispatcher(['ping' => new PingHandler()]);
        $raw = '[{"jsonrpc":"2.0","method":"ping","id":1},{"jsonrpc":"2.0","method":"ping","id":2}]';

        $responses = json_decode($dispatcher->handle($raw, null), true);

        self::assertIsArray($responses);
        self::assertCount(2, $responses);
        self::assertSame(1, $responses[0]['id']);
        self::assertSame(2, $responses[1]['id']);
        self::assertArrayHasKey('result', $responses[0]);
        self::assertArrayHasKey('result', $responses[1]);
    }

    public function testHandleBatch_allNotifications_returnsEmptyString(): void
    {
        $handler = new class implements MethodHandler {
            public function __invoke(?array $params, ?string $bearerToken): mixed { return null; }
        };

        $dispatcher = new Dispatcher(['notifications/cancelled' => $handler]);
        $raw = '[{"jsonrpc":"2.0","method":"notifications/cancelled"},{"jsonrpc":"2.0","method":"notifications/cancelled"}]';

        self::assertSame('', $dispatcher->handle($raw, null));
    }

    public function testHandleBatch_mixed_omitsNotificationResponses(): void
    {
        $dispatcher = new Dispatcher([
            'ping'                   => new PingHandler(),
            'notifications/cancelled' => new class implements MethodHandler {
                public function __invoke(?array $params, ?string $bearerToken): mixed { return null; }
            },
        ]);

        $raw = '[
            {"jsonrpc":"2.0","method":"ping","id":5},
            {"jsonrpc":"2.0","method":"notifications/cancelled"},
            {"jsonrpc":"2.0","method":"ping","id":6}
        ]';

        $responses = json_decode($dispatcher->handle($raw, null), true);

        self::assertIsArray($responses);
        self::assertCount(2, $responses);
        self::assertSame(5, $responses[0]['id']);
        self::assertSame(6, $responses[1]['id']);
    }

    public function testHandleBatch_emptyArray_returnsInvalidRequest(): void
    {
        $r = $this->decode((new Dispatcher([]))->handle('[]', null));
        self::assertSame(ErrorCode::INVALID_REQUEST, $r['error']['code']);
    }

    // ── MCP lifecycle: ping ───────────────────────────────────────────────────

    public function testHandlePing_returnsEmptyResultObject(): void
    {
        $dispatcher = new Dispatcher(['ping' => new PingHandler()]);
        $r = $this->decode($dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":42}', null));

        self::assertSame('2.0', $r['jsonrpc']);
        self::assertSame(42, $r['id']);
        self::assertArrayHasKey('result', $r);
        // {} decodes to [] in PHP assoc mode
        self::assertSame([], $r['result']);
    }

    // ── MCP lifecycle: initialize ─────────────────────────────────────────────

    public function testHandleInitialize_returnsProtocolVersionAndCapabilities(): void
    {
        $dispatcher = new Dispatcher(['initialize' => new InitializeHandler()]);
        $raw = '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{}},"id":1}';
        $r   = $this->decode($dispatcher->handle($raw, null));

        self::assertSame('2.0', $r['jsonrpc']);
        self::assertSame(1, $r['id']);
        self::assertArrayHasKey('result', $r);

        $result = $r['result'];
        self::assertSame('2025-03-26', $result['protocolVersion']);
        self::assertArrayHasKey('capabilities', $result);
        self::assertArrayHasKey('tools', $result['capabilities']);
        self::assertArrayHasKey('serverInfo', $result);
        self::assertSame('whity-core', $result['serverInfo']['name']);
    }

    // ── Rate limiting (WC-a89ece0d) ───────────────────────────────────────────

    public function testHandle_throwsMcpRateLimitException_whenRateLimiterExhausted(): void
    {
        $principal = new McpPrincipal(7, 3, 'user', ['tools:call'], 'jti-rl');

        $tokenValidator = $this->createMock(TokenValidator::class);
        $tokenValidator->method('validateMcpToken')->willReturn($principal);

        // tenantLimit = 0 → increment() returns 1, 1 > 0 → throws immediately.
        $rateLimiter = new McpRateLimiter(new ArraySharedStore(), tenantLimit: 0, principalLimit: 100);

        $dispatcher = new Dispatcher(['ping' => new PingHandler()], $tokenValidator, $rateLimiter);

        $this->expectException(McpRateLimitException::class);
        $dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', 'bearer-tok');
    }

    public function testHandle_resetsTenantContext_whenRateLimitExceptionThrown(): void
    {
        $principal = new McpPrincipal(7, 3, 'user', [], 'jti-rl2');

        $tokenValidator = $this->createMock(TokenValidator::class);
        $tokenValidator->method('validateMcpToken')->willReturn($principal);

        $rateLimiter = new McpRateLimiter(new ArraySharedStore(), tenantLimit: 0, principalLimit: 100);

        $dispatcher = new Dispatcher([], $tokenValidator, $rateLimiter);

        try {
            $dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', 'bearer-tok');
        } catch (McpRateLimitException) {
            // Expected — verify TenantContext was cleaned up by the finally block.
        }

        self::assertNull(TenantContext::getTenantId(), 'TenantContext must be reset after rate-limit exception');
    }

    // ── Per-tenant MCP opt-in (WC-149b2fc9) ─────────────────────────────────

    public function testHandle_throwsMcpFeatureDisabledException_whenTenantMcpDisabled(): void
    {
        $principal = new McpPrincipal(7, 3, 'user', ['tools:call'], 'jti-fe');

        $tokenValidator = $this->createMock(TokenValidator::class);
        $tokenValidator->method('validateMcpToken')->willReturn($principal);

        $dispatcher = new Dispatcher(
            ['ping' => new PingHandler()],
            $tokenValidator,
            null,
            static fn(int $tenantId): bool => false,
        );

        $this->expectException(McpFeatureDisabledException::class);
        $dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', 'bearer-tok');
    }

    public function testHandle_resetsTenantContext_whenMcpFeatureDisabledExceptionThrown(): void
    {
        $principal = new McpPrincipal(7, 3, 'user', [], 'jti-fe2');

        $tokenValidator = $this->createMock(TokenValidator::class);
        $tokenValidator->method('validateMcpToken')->willReturn($principal);

        $dispatcher = new Dispatcher(
            [],
            $tokenValidator,
            null,
            static fn(int $tenantId): bool => false,
        );

        try {
            $dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', 'bearer-tok');
        } catch (McpFeatureDisabledException) {
            // Expected — verify TenantContext was cleaned up.
        }

        self::assertNull(TenantContext::getTenantId(), 'TenantContext must be reset after McpFeatureDisabledException');
    }

    public function testHandle_doesNotThrow_whenTenantMcpEnabled(): void
    {
        $principal = new McpPrincipal(7, 3, 'user', ['tools:call'], 'jti-fe3');

        $tokenValidator = $this->createMock(TokenValidator::class);
        $tokenValidator->method('validateMcpToken')->willReturn($principal);

        $dispatcher = new Dispatcher(
            ['ping' => new PingHandler()],
            $tokenValidator,
            null,
            static fn(int $tenantId): bool => true,
        );

        $r = json_decode($dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', 'bearer-tok'), true);
        self::assertArrayHasKey('result', $r);
    }

    // ── ErrorCode constants ───────────────────────────────────────────────────

    public function testErrorCodeConstants_matchJsonRpcSpec(): void
    {
        self::assertSame(-32700, ErrorCode::PARSE_ERROR);
        self::assertSame(-32600, ErrorCode::INVALID_REQUEST);
        self::assertSame(-32601, ErrorCode::METHOD_NOT_FOUND);
        self::assertSame(-32602, ErrorCode::INVALID_PARAMS);
        self::assertSame(-32603, ErrorCode::INTERNAL_ERROR);
        self::assertSame(-32001, ErrorCode::UNAUTHENTICATED);
        self::assertSame(-32003, ErrorCode::FORBIDDEN);
        self::assertSame(-32000, ErrorCode::RATE_LIMITED);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $data = json_decode($json, true);
        self::assertIsArray($data, "Expected JSON object response, got: {$json}");
        return $data;
    }
}
