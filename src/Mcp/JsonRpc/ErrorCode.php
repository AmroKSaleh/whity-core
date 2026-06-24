<?php

declare(strict_types=1);

namespace Whity\Mcp\JsonRpc;

final class ErrorCode
{
    // JSON-RPC 2.0 reserved error codes (§5.1)
    public const int PARSE_ERROR     = -32700;
    public const int INVALID_REQUEST = -32600;
    public const int METHOD_NOT_FOUND = -32601;
    public const int INVALID_PARAMS  = -32602;
    public const int INTERNAL_ERROR  = -32603;

    // MCP-specific application error codes (ADR-0007)
    public const int RATE_LIMITED    = -32000;
    public const int UNAUTHENTICATED = -32001;
    public const int FORBIDDEN       = -32003;
}
