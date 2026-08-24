<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Transport;

use PHPUnit\Framework\TestCase;
use Whity\Core\Request;
use Whity\Mcp\McpFeatureDisabledException;
use Whity\Mcp\RateLimit\McpRateLimitException;
use Whity\Mcp\Transport\McpNotificationSource;
use Whity\Mcp\Transport\McpRequestHandlerInterface;
use Whity\Mcp\Transport\McpTransportHandler;

/**
 * A dispatcher that also has server-initiated notifications to hand over (#952).
 *
 * A hand-written double rather than a mock: these tests care about whether the
 * transport ASKED for the notifications at all, and a counter reads more plainly
 * than an expectation for a call that is legitimately allowed to happen zero or
 * one times.
 */
final class NotifyingDispatcherDouble implements McpRequestHandlerInterface, McpNotificationSource
{
    public int $drainCalls = 0;

    /** @param list<string> $notifications */
    public function __construct(
        private readonly string $response,
        private readonly array $notifications,
    ) {}

    public function handle(string $rawBody, ?string $bearerToken): string
    {
        return $this->response;
    }

    /** @return list<string> */
    public function drainNotifications(): array
    {
        $this->drainCalls++;

        return $this->notifications;
    }
}

/**
 * WC-d279a9b3: contract tests for McpTransportHandler.
 *
 * The transport layer is pure HTTP plumbing — content-type gate, bearer
 * extraction, and delegation. Auth and JSON-RPC logic live in the dispatcher.
 */
final class McpTransportHandlerTest extends TestCase
{
    private McpRequestHandlerInterface $dispatcher;
    private McpTransportHandler $handler;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(McpRequestHandlerInterface::class);
        $this->handler    = new McpTransportHandler($this->dispatcher);
    }

    // ── POST /mcp content-type gate ──────────────────────────────────────────

    public function testPostRejectsWrongContentType(): void
    {
        $this->dispatcher->expects(self::never())->method('handle');

        $request  = new Request('POST', '/mcp', ['Content-Type' => 'text/plain'], '{}');
        $response = $this->handler->handlePost($request);

        self::assertSame(415, $response->getStatusCode());
    }

    public function testPostAcceptsApplicationJsonContentType(): void
    {
        $this->dispatcher->method('handle')->willReturn('{}');

        $request  = new Request('POST', '/mcp', ['Content-Type' => 'application/json'], '{}');
        $response = $this->handler->handlePost($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPostAcceptsContentTypeWithCharset(): void
    {
        $this->dispatcher->method('handle')->willReturn('{}');

        $request  = new Request('POST', '/mcp', ['Content-Type' => 'application/json; charset=utf-8'], '{}');
        $response = $this->handler->handlePost($request);

        self::assertSame(200, $response->getStatusCode());
    }

    // ── POST /mcp bearer token extraction ────────────────────────────────────

    public function testPostPassesBearerTokenToDispatcher(): void
    {
        $body = '{"jsonrpc":"2.0","method":"ping","id":1}';

        $this->dispatcher->expects(self::once())
            ->method('handle')
            ->with($body, 'secret-mcp-token')
            ->willReturn('{"jsonrpc":"2.0","id":1,"result":{}}');

        $request = new Request('POST', '/mcp', [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer secret-mcp-token',
        ], $body);

        $this->handler->handlePost($request);
    }

    public function testPostPassesNullTokenWhenNoAuthorizationHeader(): void
    {
        $body = '{"jsonrpc":"2.0","method":"ping","id":1}';

        $this->dispatcher->expects(self::once())
            ->method('handle')
            ->with($body, null)
            ->willReturn('{"jsonrpc":"2.0","id":1,"error":{"code":-32001,"message":"Unauthenticated"}}');

        $request = new Request('POST', '/mcp', ['Content-Type' => 'application/json'], $body);

        $this->handler->handlePost($request);
    }

    public function testPostPassesNullTokenForMalformedAuthorizationHeader(): void
    {
        $this->dispatcher->expects(self::once())
            ->method('handle')
            ->with(self::anything(), null)
            ->willReturn('{}');

        $request = new Request('POST', '/mcp', [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Basic dXNlcjpwYXNz',
        ], '{}');

        $this->handler->handlePost($request);
    }

    // ── POST /mcp response construction ──────────────────────────────────────

    public function testPostReturnsDispatcherOutput(): void
    {
        $jsonRpcResponse = '{"jsonrpc":"2.0","id":42,"result":{"pong":true}}';

        $this->dispatcher->method('handle')->willReturn($jsonRpcResponse);

        $request = new Request('POST', '/mcp', [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer t',
        ], '{"jsonrpc":"2.0","method":"ping","id":42}');

        $response = $this->handler->handlePost($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($jsonRpcResponse, $response->getBody());
    }

    public function testPostResponseHasApplicationJsonContentType(): void
    {
        $this->dispatcher->method('handle')->willReturn('{}');

        $request = new Request('POST', '/mcp', [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer t',
        ], '{}');

        $response = $this->handler->handlePost($request);
        $headers  = $response->getHeaders();

        $contentType = $headers['content-type'] ?? '';
        self::assertStringContainsString('application/json', $contentType);
    }

    // ── POST /mcp SSE hint ────────────────────────────────────────────────────

    public function testPostSetsXAccelBufferingNoWhenClientAcceptsSse(): void
    {
        $this->dispatcher->method('handle')->willReturn('{}');

        $request = new Request('POST', '/mcp', [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer t',
            'Accept'        => 'text/event-stream',
        ], '{}');

        $response = $this->handler->handlePost($request);
        $headers  = $response->getHeaders();

        self::assertSame('no', $headers['x-accel-buffering'] ?? null);
    }

    public function testPostDoesNotSetXAccelBufferingForRegularJsonRequest(): void
    {
        $this->dispatcher->method('handle')->willReturn('{}');

        $request = new Request('POST', '/mcp', [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer t',
        ], '{}');

        $response = $this->handler->handlePost($request);
        $headers  = $response->getHeaders();

        self::assertArrayNotHasKey('x-accel-buffering', $headers);
    }

    // ── POST /mcp rate limiting (WC-a89ece0d) ────────────────────────────────

    public function testPostReturns429_whenDispatcherThrowsMcpRateLimitException(): void
    {
        $this->dispatcher->method('handle')
            ->willThrowException(new McpRateLimitException(60));

        $request  = new Request('POST', '/mcp', ['Content-Type' => 'application/json'], '{}');
        $response = $this->handler->handlePost($request);

        self::assertSame(429, $response->getStatusCode());
    }

    public function testPostSetsRetryAfterHeader_whenRateLimited(): void
    {
        $this->dispatcher->method('handle')
            ->willThrowException(new McpRateLimitException(60));

        $request  = new Request('POST', '/mcp', ['Content-Type' => 'application/json'], '{}');
        $response = $this->handler->handlePost($request);

        $headers = $response->getHeaders();
        // Header name may be stored lowercase depending on Response implementation.
        $retryAfter = $headers['Retry-After'] ?? $headers['retry-after'] ?? null;
        self::assertSame('60', $retryAfter);
    }

    public function testPostReturnsEmptyBody_whenRateLimited(): void
    {
        $this->dispatcher->method('handle')
            ->willThrowException(new McpRateLimitException(60));

        $request  = new Request('POST', '/mcp', ['Content-Type' => 'application/json'], '{}');
        $response = $this->handler->handlePost($request);

        self::assertSame('', $response->getBody());
    }

    public function testPostDoesNotReturnJsonContentType_whenRateLimited(): void
    {
        $this->dispatcher->method('handle')
            ->willThrowException(new McpRateLimitException(60));

        $request  = new Request('POST', '/mcp', ['Content-Type' => 'application/json'], '{}');
        $response = $this->handler->handlePost($request);

        $headers     = $response->getHeaders();
        $contentType = $headers['content-type'] ?? $headers['Content-Type'] ?? '';
        // A 429 with no body should not claim application/json
        self::assertStringNotContainsString('application/json', $contentType);
    }

    // ── POST /mcp server-initiated notifications (#952) ──────────────────────

    public function testPostFramesNotificationsAsAnEventStream_whenClientAcceptsSse(): void
    {
        $dispatcher = new NotifyingDispatcherDouble(
            '{"jsonrpc":"2.0","id":1,"result":{}}',
            ['{"jsonrpc":"2.0","method":"notifications/tools/list_changed"}'],
        );
        $handler = new McpTransportHandler($dispatcher);

        $response = $handler->handlePost($this->ssePost());

        self::assertStringContainsString(
            'text/event-stream',
            $response->getHeaders()['content-type'] ?? '',
        );
        self::assertSame(
            "event: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/tools/list_changed\"}\n\n"
            . "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{}}\n\n",
            $response->getBody(),
        );
    }

    public function testPostPutsNotificationsBeforeTheResponse(): void
    {
        $dispatcher = new NotifyingDispatcherDouble(
            '{"jsonrpc":"2.0","id":1,"result":{}}',
            ['{"jsonrpc":"2.0","method":"notifications/tools/list_changed"}'],
        );
        $handler = new McpTransportHandler($dispatcher);

        $body = $handler->handlePost($this->ssePost())->getBody();

        self::assertLessThan(
            strpos($body, '"result"'),
            strpos($body, 'list_changed'),
            'a client applying frames in order must invalidate its cache before reading a result',
        );
    }

    public function testPostFramesNotificationsAloneWhenTheRequestWasItselfANotification(): void
    {
        $dispatcher = new NotifyingDispatcherDouble(
            '',
            ['{"jsonrpc":"2.0","method":"notifications/prompts/list_changed"}'],
        );
        $handler = new McpTransportHandler($dispatcher);

        $body = $handler->handlePost($this->ssePost())->getBody();

        self::assertSame(
            "event: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/prompts/list_changed\"}\n\n",
            $body,
        );
    }

    public function testPostStaysJsonWhenNothingIsOwed(): void
    {
        $dispatcher = new NotifyingDispatcherDouble('{"jsonrpc":"2.0","id":1,"result":{}}', []);
        $handler    = new McpTransportHandler($dispatcher);

        $response = $handler->handlePost($this->ssePost());

        self::assertStringContainsString(
            'application/json',
            $response->getHeaders()['content-type'] ?? '',
        );
        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', $response->getBody());
    }

    /**
     * Draining CLAIMS the notifications, so the transport must not ask for them
     * when it has nowhere to put them — a client that cannot read an event
     * stream would otherwise consume the announcement and never see it.
     */
    public function testPostDoesNotDrainWhenTheClientCannotReadAnEventStream(): void
    {
        $dispatcher = new NotifyingDispatcherDouble(
            '{"jsonrpc":"2.0","id":1,"result":{}}',
            ['{"jsonrpc":"2.0","method":"notifications/tools/list_changed"}'],
        );
        $handler = new McpTransportHandler($dispatcher);

        $request = new Request('POST', '/mcp', [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer t',
            'Accept'        => 'application/json',
        ], '{}');

        $response = $handler->handlePost($request);

        self::assertSame(0, $dispatcher->drainCalls);
        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', $response->getBody());
    }

    public function testPostToleratesADispatcherWithNoNotificationsToGive(): void
    {
        // The bootstrap stub and any other plain McpRequestHandlerInterface must
        // keep working untouched.
        $this->dispatcher->method('handle')->willReturn('{"jsonrpc":"2.0","id":1,"result":{}}');

        $response = $this->handler->handlePost($this->ssePost());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', $response->getBody());
    }

    private function ssePost(): Request
    {
        return new Request('POST', '/mcp', [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer t',
            'Accept'        => 'application/json, text/event-stream',
        ], '{}');
    }

    // ── GET /mcp ─────────────────────────────────────────────────────────────

    /**
     * 405 is the MCP spec's answer for a server that offers no standing SSE
     * stream. Now that the server advertises listChanged (#952) a client has a
     * reason to try opening one, and it needs a defined answer rather than the
     * 501 that used to be here.
     */
    public function testGetReturns405(): void
    {
        $request  = new Request('GET', '/mcp', [], '');
        $response = $this->handler->handleGet($request);

        self::assertSame(405, $response->getStatusCode());
    }

    public function testGetDoesNotCallDispatcher(): void
    {
        $this->dispatcher->expects(self::never())->method('handle');

        $request = new Request('GET', '/mcp', [], '');
        $this->handler->handleGet($request);
    }

    // ── Global MCP_ENABLED flag (WC-149b2fc9) ────────────────────────────────

    public function testHandlePost_returns503_whenGloballyDisabled(): void
    {
        $this->dispatcher->expects(self::never())->method('handle');

        $handler  = new McpTransportHandler($this->dispatcher, enabled: false);
        $request  = new Request('POST', '/mcp', ['Content-Type' => 'application/json'], '{}');
        $response = $handler->handlePost($request);

        self::assertSame(503, $response->getStatusCode());
    }

    public function testHandleGet_returns503_whenGloballyDisabled(): void
    {
        $handler  = new McpTransportHandler($this->dispatcher, enabled: false);
        $request  = new Request('GET', '/mcp', [], '');
        $response = $handler->handleGet($request);

        self::assertSame(503, $response->getStatusCode());
    }

    // ── Per-tenant MCP opt-in (WC-149b2fc9) ─────────────────────────────────

    public function testHandlePost_returns403_whenTenantMcpDisabled(): void
    {
        $this->dispatcher->method('handle')
            ->willThrowException(new McpFeatureDisabledException());

        $request  = new Request('POST', '/mcp', ['Content-Type' => 'application/json'], '{}');
        $response = $this->handler->handlePost($request);

        self::assertSame(403, $response->getStatusCode());
    }
}
