<?php

declare(strict_types=1);

namespace Whity\Mcp\JsonRpc;

use Whity\Auth\TokenValidator;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\McpFeatureDisabledException;
use Whity\Mcp\Notifications\ListChangedNotifier;
use Whity\Mcp\RateLimit\McpRateLimiter;
use Whity\Mcp\Transport\McpNotificationSource;
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
 *
 * When a TokenValidator is provided, every call must carry a valid MCP bearer
 * token (WC-06a7133c). Auth is checked before JSON parsing so unauthenticated
 * callers learn nothing about the request shape. TenantContext is set to the
 * principal's tenant ID and reset in a finally block, guaranteeing no tenant
 * bleed across FrankenPHP persistent-worker requests.
 */
final class Dispatcher implements McpRequestHandlerInterface, McpNotificationSource
{
    /**
     * Client identity (token jti) of the request currently being handled, or
     * null when the request was unauthenticated or auth is disabled.
     *
     * Worker-persistent by necessity — the dispatcher is a worker singleton and
     * the transport reads this AFTER handle() returns — so it is cleared at the
     * top of every handle() before anything can throw. That ordering is what
     * stops one request's client identity from leaking into the next.
     */
    private ?string $notifyClientKey = null;

    /**
     * Whether the request currently being handled contained an `initialize`.
     *
     * Such a client is about to fetch every list anyway, so it is marked current
     * instead of being told the lists changed. Reset alongside the client key.
     */
    private bool $notifySuppressed = false;

    /**
     * @param array<string, MethodHandler>    $handlers          Keyed by method name.
     * @param TokenValidator|null             $tokenValidator    When provided, every request must carry
     *                                                            a valid MCP bearer token; null disables
     *                                                            auth (internal / test use only).
     * @param McpRateLimiter|null             $rateLimiter       When provided, per-tenant and per-principal
     *                                                            call budgets are enforced after auth; null
     *                                                            disables rate limiting (dev / test use).
     * @param (\Closure(int): bool)|null      $tenantMcpEnabled  When provided, called with the tenant id
     *                                                            after auth; a false return throws
     *                                                            McpFeatureDisabledException (HTTP 403).
     *                                                            Null disables the per-tenant check.
     * @param ListChangedNotifier|null        $listChangedNotifier When provided, the dispatcher records which
     *                                                            client made the call so the transport can
     *                                                            drain any owed `list_changed` notifications
     *                                                            (#952); null disables the signal entirely.
     */
    public function __construct(
        private readonly array $handlers,
        private readonly ?TokenValidator $tokenValidator    = null,
        private readonly ?McpRateLimiter $rateLimiter       = null,
        private readonly ?\Closure $tenantMcpEnabled        = null,
        private readonly ?ListChangedNotifier $listChangedNotifier = null,
    ) {}

    public function handle(string $rawBody, ?string $bearerToken): string
    {
        // Cleared first, before any path that can throw or return early: these
        // two survive the request on a worker singleton, and a stale client key
        // would push the previous caller's notifications to this one.
        $this->notifyClientKey  = null;
        $this->notifySuppressed = false;

        // Auth check: validated before JSON parsing so that an unauthenticated
        // caller learns nothing about the request shape.
        $principal = null;
        if ($this->tokenValidator !== null) {
            $principal = $bearerToken !== null
                ? $this->tokenValidator->validateBearerForMcp($bearerToken)
                : null;
            if ($principal === null) {
                return $this->encode($this->makeError(null, ErrorCode::UNAUTHENTICATED, 'Unauthenticated'));
            }
        }

        // TenantContext is set INSIDE the try block so TenantContext::reset()
        // in the finally fires even when McpRateLimitException propagates out.
        try {
            if ($principal !== null) {
                TenantContext::setTenantId($principal->tenantId);
                // Per-tenant MCP opt-in check. McpFeatureDisabledException propagates
                // to McpTransportHandler which returns HTTP 403.
                if ($this->tenantMcpEnabled !== null && !($this->tenantMcpEnabled)($principal->tenantId)) {
                    throw new McpFeatureDisabledException();
                }
                // Rate limit check after auth. McpRateLimitException is NOT caught
                // here — it propagates to McpTransportHandler which returns HTTP 429.
                $this->rateLimiter?->checkAndRecord($principal->tenantId, $principal->userId);

                // #952: the token's jti is the closest thing this transport has to
                // a client identity — there is no MCP session — and it is stable
                // for as long as the client keeps the connection its cached tool
                // list belongs to. Recorded only past auth, the tenant gate and the
                // rate limiter, so an unauthenticated caller cannot make the server
                // write notification bookkeeping on its behalf.
                $this->notifyClientKey = $principal->jti;
            }

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

            $response = $this->dispatch($this->toArrayDeep($decoded), $bearerToken);
            return $response !== null ? $this->encode($response) : '';
        } finally {
            TenantContext::reset();
        }
    }

    /**
     * Recursively convert a decoded JSON-RPC request object (stdClass, produced
     * by the non-associative json_decode used for batch-vs-single detection) into
     * a deep associative array. A shallow `(array)` cast leaves nested objects —
     * notably `params.arguments` — as stdClass, which downstream handlers reject
     * via `is_array()` checks, silently dropping object-valued tool arguments.
     *
     * @return array<string, mixed>
     */
    private function toArrayDeep(\stdClass $object): array
    {
        /** @var array<string, mixed> $array */
        $array = (array) json_decode((string) json_encode($object), true);
        return $array;
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
            $response = $this->dispatch($this->toArrayDeep($item), $bearerToken);
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

        if ($method === 'initialize') {
            $this->notifySuppressed = true;
        }

        if (!isset($this->handlers[$method])) {
            return $isNotification ? null : $this->makeError($id, ErrorCode::METHOD_NOT_FOUND, 'Method not found');
        }

        $params = isset($request['params']) ? (array) $request['params'] : null;

        try {
            $result = ($this->handlers[$method])($params, $bearerToken);
        } catch (McpException $e) {
            return $isNotification ? null : $this->makeError($id, $e->errorCode, $e->getMessage());
        } catch (\Throwable) {
            return $isNotification ? null : $this->makeError($id, ErrorCode::INTERNAL_ERROR, 'Internal error');
        }

        return $isNotification ? null : $this->makeSuccess($id, $result);
    }

    // ── Server-initiated notifications (#952) ─────────────────────────────────

    /**
     * Notifications owed to the client of the request just handled.
     *
     * Draining is one-shot: the client key is taken so a second call in the same
     * request cannot re-claim (and therefore re-send) the same notifications.
     *
     * @return list<string>
     */
    public function drainNotifications(): array
    {
        $clientKey             = $this->notifyClientKey;
        $this->notifyClientKey = null;

        if ($clientKey === null || $this->listChangedNotifier === null) {
            return [];
        }

        if ($this->notifySuppressed) {
            $this->listChangedNotifier->markSeen($clientKey);

            return [];
        }

        return $this->listChangedNotifier->drainFor($clientKey);
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
