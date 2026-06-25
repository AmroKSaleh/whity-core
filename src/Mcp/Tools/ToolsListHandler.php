<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

use Whity\Mcp\JsonRpc\MethodHandler;

/**
 * MCP tools/list handler (WC-001754c6).
 *
 * Returns the full catalogue of MCP tools derived from the platform's
 * registered routes. Each tool exposes a stable name (operationId), a
 * human-readable description, and a JSON-Schema inputSchema covering path
 * params, query params, and request-body properties.
 */
final class ToolsListHandler implements MethodHandler
{
    public function __construct(private readonly ToolDeriver $toolDeriver) {}

    /**
     * Handle the MCP tools/list request.
     *
     * The MCP spec requires the response to carry a top-level 'tools' array.
     * Pagination via a 'cursor' parameter is not yet implemented (tools/list
     * is expected to be small enough to return in one batch).
     *
     * @param array<string, mixed>|null $params     JSON-RPC params (unused).
     * @param string|null               $bearerToken Bearer token (unused here; auth
     *                                               is handled by the Dispatcher).
     */
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        return ['tools' => $this->toolDeriver->deriveTools()];
    }
}
