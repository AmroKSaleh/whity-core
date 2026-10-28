<?php

declare(strict_types=1);

namespace Whity\Mcp\Transport;

/**
 * A request handler that can say which HTTP status its last response needs
 * (WHIT-708).
 *
 * MCP 2026-07-28 moves some JSON-RPC errors onto the HTTP status as well: an
 * unsupported protocol version or a header that disagrees with the body is a
 * 400, an unknown method a 404. A dual-era client reads that status to decide
 * whether it is talking to a modern server, so it has to be right. Every other
 * response stays 200, as it always was.
 */
interface McpHttpStatusSource
{
    /** The HTTP status for the response the last handle() call produced. */
    public function httpStatus(): int;
}
