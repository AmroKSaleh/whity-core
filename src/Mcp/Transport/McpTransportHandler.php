<?php

declare(strict_types=1);

namespace Whity\Mcp\Transport;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Mcp\McpFeatureDisabledException;
use Whity\Mcp\RateLimit\McpRateLimitException;

/**
 * HTTP transport handler for the MCP Streamable-HTTP endpoint (ADR-0006).
 *
 * Implements the two-endpoint MCP transport surface:
 *
 *   POST /mcp  — receive JSON-RPC 2.0 messages (requests, notifications, batches),
 *                delegate to the dispatcher, return JSON (or SSE stream for long-running
 *                tool calls when the client signals Accept: text/event-stream).
 *   GET  /mcp  — reserved for server-initiated SSE notifications; not yet implemented.
 *
 * This class is deliberately thin: it handles only HTTP-level concerns (content-type
 * gate, bearer extraction, response headers). JSON-RPC parsing, MCP auth, tenant
 * context, RBAC, and per-method execution are entirely inside McpRequestHandlerInterface.
 */
final class McpTransportHandler
{
    public function __construct(
        private readonly McpRequestHandlerInterface $dispatcher,
        private readonly bool $enabled = true,
    ) {}

    /**
     * Handle POST /mcp — JSON-RPC requests, notifications, and batch arrays.
     *
     * @param array<string, mixed> $params Route path params (always empty for /mcp).
     */
    public function handlePost(Request $request, array $params = []): Response
    {
        if (!$this->enabled) {
            return new Response(503, '', []);
        }

        $contentType = $request->getHeader('Content-Type') ?? '';
        if (!str_contains($contentType, 'application/json')) {
            return Response::error('Content-Type must be application/json', 415);
        }

        $bearer  = $this->extractBearer($request);
        $rawBody = $request->getBody();

        try {
            $rawResponse = $this->dispatcher->handle($rawBody, $bearer);
        } catch (McpRateLimitException $e) {
            return new Response(429, '', ['Retry-After' => (string) $e->getRetryAfterSeconds()]);
        } catch (McpFeatureDisabledException) {
            return new Response(403, '', []);
        }

        $headers = ['Content-Type' => 'application/json'];

        // When the client signals it can consume an SSE stream (long-running
        // tool calls), disable reverse-proxy buffering now so the first event
        // frame is not held until the buffer fills (ADR-0006 §2, Caddy tuning).
        if (str_contains($request->getHeader('Accept') ?? '', 'text/event-stream')) {
            $headers['X-Accel-Buffering'] = 'no';
        }

        return new Response(200, $rawResponse, $headers);
    }

    /**
     * Handle GET /mcp — server-initiated SSE notification stream.
     *
     * Reserved per MCP spec 2025-03-26; full SSE implementation deferred.
     *
     * @param array<string, mixed> $params Route path params (always empty for /mcp).
     */
    public function handleGet(Request $request, array $params = []): Response
    {
        if (!$this->enabled) {
            return new Response(503, '', []);
        }

        return Response::error('SSE stream not yet implemented', 501);
    }

    /**
     * Extract the Bearer token from the Authorization header.
     *
     * Returns null when the header is absent or does not follow the
     * `Bearer <token>` format. Auth validation is the dispatcher's responsibility.
     */
    private function extractBearer(Request $request): ?string
    {
        $header = $request->getHeader('Authorization');
        if ($header !== null && preg_match('/^Bearer\s+(\S+)$/', $header, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
