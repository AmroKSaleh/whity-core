<?php

declare(strict_types=1);

namespace Whity\Mcp\Transport;

/**
 * Contract between the HTTP transport layer and the JSON-RPC dispatcher.
 *
 * The transport reads the raw request body and the Bearer token from the
 * Authorization header, then delegates all JSON-RPC parsing, method routing,
 * auth validation, and response serialisation to an implementation of this
 * interface. This keeps the transport free of JSON-RPC knowledge.
 */
interface McpRequestHandlerInterface
{
    /**
     * Process a raw JSON-RPC request body and return the raw JSON-RPC response.
     *
     * The implementation is responsible for:
     *   - Parsing the JSON-RPC envelope (single request or batch array)
     *   - Validating the bearer token and mapping it to an AI principal
     *   - Routing each method to the correct MCP handler
     *   - Serialising the JSON-RPC response (or returning an empty string for
     *     notifications that produce no response)
     *
     * The transport never inspects the returned string; it forwards it verbatim.
     *
     * @param string      $rawBody     Raw UTF-8 request body from the HTTP layer.
     * @param string|null $bearerToken Bearer token from the Authorization header,
     *                                 or null when the header is absent/malformed.
     * @return string                  Raw JSON-RPC response string (UTF-8 JSON).
     */
    public function handle(string $rawBody, ?string $bearerToken): string;
}
