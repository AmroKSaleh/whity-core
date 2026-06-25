<?php

declare(strict_types=1);

namespace Whity\Mcp\JsonRpc;

/**
 * Protocol-level MCP error that handlers may throw to return a specific
 * JSON-RPC error code (WC-2e6944d5).
 *
 * The Dispatcher catches McpException before the generic \Throwable catch and
 * propagates its error code directly into the JSON-RPC error response — unlike
 * arbitrary exceptions, which are always collapsed to INTERNAL_ERROR.
 *
 * Handlers should throw this for client-visible failures such as:
 *   - INVALID_PARAMS  (-32602): missing or malformed tool arguments
 *   - METHOD_NOT_FOUND (-32601): requested tool does not exist
 *   - FORBIDDEN       (-32003): principal lacks required permission or role
 */
final class McpException extends \RuntimeException
{
    /**
     * @param int    $errorCode JSON-RPC error code (see ErrorCode constants).
     * @param string $message   Human-readable message forwarded to the caller.
     */
    public function __construct(
        public readonly int $errorCode,
        string $message = '',
    ) {
        parent::__construct($message, $errorCode);
    }
}
