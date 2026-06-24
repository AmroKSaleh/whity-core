<?php

declare(strict_types=1);

namespace Whity\Mcp\JsonRpc;

use Whity\Mcp\Transport\McpRequestHandlerInterface;

/**
 * Hand-rolled JSON-RPC 2.0 dispatcher (ADR-0007).
 *
 * Parses the raw body (single object or batch array), routes each request to a
 * registered MethodHandler by method name, and returns the raw JSON-RPC response
 * string. Notifications (requests with no "id" member) are processed but produce
 * no response; an empty string is returned in that case. Batch arrays return a
 * JSON array of the non-notification responses (empty string if all are
 * notifications). Exception messages from handlers are never forwarded to the
 * caller — INTERNAL_ERROR is returned with a fixed generic message.
 */
final class Dispatcher implements McpRequestHandlerInterface
{
    /** @param array<string, MethodHandler> $handlers Keyed by method name. */
    public function __construct(private readonly array $handlers) {}

    public function handle(string $rawBody, ?string $bearerToken): string
    {
        try {
            $decoded = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->encode($this->makeError(null, ErrorCode::PARSE_ERROR, 'Parse error'));
        }

        if (is_array($decoded)) {
            return $this->handleBatch($decoded, $bearerToken);
        }

        if (!$decoded instanceof \stdClass) {
            return $this->encode($this->makeError(null, ErrorCode::INVALID_REQUEST, 'Invalid Request'));
        }

        $response = $this->dispatch((array) $decoded, $bearerToken);
        return $response !== null ? $this->encode($response) : '';
    }

    // ── Batch ─────────────────────────────────────────────────────────────────

    /** @param mixed[] $items */
    private function handleBatch(array $items, ?string $bearerToken): string
    {
        if ($items === []) {
            return $this->encode($this->makeError(null, ErrorCode::INVALID_REQUEST, 'Invalid Request'));
        }

        $responses = [];
        foreach ($items as $item) {
            if (!$item instanceof \stdClass) {
                $responses[] = $this->makeError(null, ErrorCode::INVALID_REQUEST, 'Invalid Request');
                continue;
            }
            $response = $this->dispatch((array) $item, $bearerToken);
            if ($response !== null) {
                $responses[] = $response;
            }
        }

        if ($responses === []) {
            return '';
        }

        return (string) json_encode($responses, JSON_THROW_ON_ERROR);
    }

    // ── Single request ────────────────────────────────────────────────────────

    /**
     * Dispatch one JSON-RPC object. Returns the response data array, or null
     * for a notification (which produces no response).
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    private function dispatch(array $request, ?string $bearerToken): ?array
    {
        $isNotification = !array_key_exists('id', $request);
        $id             = $request['id'] ?? null;

        if (($request['jsonrpc'] ?? null) !== '2.0') {
            return $isNotification ? null : $this->makeError($id, ErrorCode::INVALID_REQUEST, 'Invalid Request');
        }

        $method = $request['method'] ?? null;
        if (!is_string($method) || $method === '') {
            return $isNotification ? null : $this->makeError($id, ErrorCode::INVALID_REQUEST, 'Invalid Request');
        }

        if (!isset($this->handlers[$method])) {
            return $isNotification ? null : $this->makeError($id, ErrorCode::METHOD_NOT_FOUND, 'Method not found');
        }

        $params = isset($request['params']) ? (array) $request['params'] : null;

        try {
            $result = ($this->handlers[$method])($params, $bearerToken);
        } catch (\Throwable) {
            return $isNotification ? null : $this->makeError($id, ErrorCode::INTERNAL_ERROR, 'Internal error');
        }

        return $isNotification ? null : $this->makeSuccess($id, $result);
    }

    // ── Response builders ─────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function makeSuccess(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeError(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return (string) json_encode($data, JSON_THROW_ON_ERROR);
    }
}
