<?php

declare(strict_types=1);

namespace Whity\Mcp\JsonRpc;

/**
 * Contract for individual MCP JSON-RPC method handlers (ADR-0007).
 *
 * Each registered method is implemented as an invokable class. The dispatcher
 * calls __invoke() with the decoded params and the bearer token forwarded from
 * the HTTP transport. Return value is serialised into the JSON-RPC result field;
 * throw any Throwable to produce an INTERNAL_ERROR response without leaking the
 * exception message to the caller.
 */
interface MethodHandler
{
    /**
     * @param array<string, mixed>|null $params     Decoded params from the JSON-RPC request (null when absent).
     * @param string|null               $bearerToken Bearer token from the Authorization header, or null.
     * @return mixed                                 Value to serialise into the JSON-RPC "result" field.
     */
    public function __invoke(?array $params, ?string $bearerToken): mixed;
}
