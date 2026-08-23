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
 *   GET  /mcp  — 405: this server does not offer a standing SSE stream.
 *
 * Why no standing GET stream (#952): under FrankenPHP a held connection occupies
 * a worker for its whole life, and there are eight of them. Eight subscribed MCP
 * clients would leave the platform with nothing left to serve HTTP with. The MCP
 * spec's answer for a server that does not offer the stream is 405, and it also
 * allows the POST response itself to be an SSE stream carrying server-initiated
 * notifications ahead of the JSON-RPC response — so that is where
 * `notifications/*\/list_changed` is delivered. Those responses are complete
 * bodies written in one pass, not held connections, so no worker is pinned.
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

        $acceptsSse = str_contains($request->getHeader('Accept') ?? '', 'text/event-stream');

        // #952: server-initiated notifications are only drained when they can
        // actually be written, because draining CLAIMS them — a client is told
        // about a catalogue change once. Taking them from a dispatcher and then
        // finding nowhere to put them would lose the change permanently, so a
        // client that did not offer to read an event stream is simply left alone.
        $notifications = [];
        if ($acceptsSse && $this->dispatcher instanceof McpNotificationSource) {
            $notifications = $this->dispatcher->drainNotifications();
        }

        if ($notifications !== []) {
            return new Response(200, $this->frameEventStream($notifications, $rawResponse), [
                'Content-Type'      => 'text/event-stream',
                'Cache-Control'     => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $headers = ['Content-Type' => 'application/json'];

        // When the client signals it can consume an SSE stream (long-running
        // tool calls), disable reverse-proxy buffering now so the first event
        // frame is not held until the buffer fills (ADR-0006 §2, Caddy tuning).
        if ($acceptsSse) {
            $headers['X-Accel-Buffering'] = 'no';
        }

        return new Response(200, $rawResponse, $headers);
    }

    /**
     * Frame notifications and (when there is one) the JSON-RPC response as SSE.
     *
     * Order is fixed by the MCP spec: server-initiated messages come first, the
     * response to the client's request last. A client that applies them in order
     * therefore invalidates its cached list BEFORE reading a result derived from
     * it, rather than after.
     *
     * Single-line `data:` frames are safe because every payload here is
     * json_encode output, which escapes literal newlines.
     *
     * @param list<string> $notifications Encoded JSON-RPC notification objects.
     * @param string       $response      Encoded JSON-RPC response, or '' when the
     *                                    request was itself only a notification.
     */
    private function frameEventStream(array $notifications, string $response): string
    {
        $frames = $notifications;
        if ($response !== '') {
            $frames[] = $response;
        }

        $body = '';
        foreach ($frames as $frame) {
            $body .= "event: message\ndata: " . $frame . "\n\n";
        }

        return $body;
    }

    /**
     * Handle GET /mcp — the standing server-to-client SSE stream, which this
     * server does not offer.
     *
     * 405 is the MCP spec's defined answer for that (2025-03-26, Streamable
     * HTTP): a conformant client reads it as "notifications arrive on POST
     * responses" and stops trying. It used to be 501, which the spec does not
     * assign a meaning to — tolerable while the server advertised
     * `listChanged: false` and had nothing to push, but now that it advertises
     * `listChanged: true` (#952) a client has a reason to open this stream, and
     * an undefined status leaves it deciding for itself whether the server is
     * broken.
     *
     * @param array<string, mixed> $params Route path params (always empty for /mcp).
     */
    public function handleGet(Request $request, array $params = []): Response
    {
        if (!$this->enabled) {
            return new Response(503, '', []);
        }

        // Allow is required on a 405 and is the useful half of the answer: the
        // endpoint exists, POST is the way in.
        return Response::error('This server does not offer an SSE stream at /mcp', 405)
            ->withHeaders(['Allow' => 'POST']);
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
