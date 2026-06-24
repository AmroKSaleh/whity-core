<?php

declare(strict_types=1);

namespace Whity\Mcp\Transport;

/**
 * Bootstrap-phase dispatcher stub wired before the real JSON-RPC Dispatcher lands.
 *
 * Returns a JSON-RPC Internal Error for every request so the /mcp endpoint is
 * live (valid JSON-RPC responses, not an HTTP 500) while the dispatcher is being
 * built. Replaced by the real Dispatcher once task c10b292e is implemented.
 */
final class NullMcpDispatcher implements McpRequestHandlerInterface
{
    public function handle(string $rawBody, ?string $bearerToken): string
    {
        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => [
                'code'    => -32603,
                'message' => 'MCP dispatcher not yet initialised',
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
