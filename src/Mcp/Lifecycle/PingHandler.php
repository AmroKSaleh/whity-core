<?php

declare(strict_types=1);

namespace Whity\Mcp\Lifecycle;

use Whity\Mcp\JsonRpc\MethodHandler;

/**
 * MCP ping method handler.
 *
 * Returns an empty JSON object so the client can verify the connection is alive.
 */
final class PingHandler implements MethodHandler
{
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        return new \stdClass();
    }
}
