<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Transport;

use PHPUnit\Framework\TestCase;
use Whity\Core\Request;
use Whity\Mcp\RateLimit\McpRateLimitException;
use Whity\Mcp\Transport\McpRequestHandlerInterface;
use Whity\Mcp\Transport\McpTransportHandler;

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

    // ── GET /mcp SSE stub ────────────────────────────────────────────────────

    public function testGetReturns501(): void
    {
        $request  = new Request('GET', '/mcp', [], '');
        $response = $this->handler->handleGet($request);

        self::assertSame(501, $response->getStatusCode());
    }

    public function testGetDoesNotCallDispatcher(): void
    {
        $this->dispatcher->expects(self::never())->method('handle');

        $request = new Request('GET', '/mcp', [], '');
        $this->handler->handleGet($request);
    }
}
