<?php

declare(strict_types=1);

namespace Whity\Mcp\Lifecycle;

use Whity\Mcp\JsonRpc\MethodHandler;

/**
 * MCP notifications/cancelled handler.
 *
 * Clients send this when they abandon a pending request. No response is sent
 * (the Dispatcher suppresses the return value for notifications). This handler
 * is a no-op; request cancellation bookkeeping is added when the tool-execution
 * layer lands.
 */
final class CancelledNotificationHandler implements MethodHandler
{
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        return null;
    }
}
