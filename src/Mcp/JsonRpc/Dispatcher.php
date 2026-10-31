<?php

declare(strict_types=1);

namespace Whity\Mcp\JsonRpc;

use Whity\Auth\TokenValidator;
use Whity\Mcp\Tools\ToolSurfaceRequest;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Auth\McpScope;
use Whity\Mcp\Auth\OAuth\OAuthAccessTokenVerifier;
use Whity\Mcp\Auth\OAuth\OAuthTokenRefusedException;
use Whity\Mcp\McpFeatureDisabledException;
use Whity\Mcp\Notifications\ListChangedNotifier;
use Whity\Mcp\Protocol\McpProtocol;
use Whity\Mcp\RateLimit\McpRateLimiter;
use Whity\Mcp\Transport\McpHttpStatusSource;
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
final class Dispatcher implements McpRequestHandlerInterface, McpNotificationSource, McpHttpStatusSource
{
    /**
     * HTTP status for the response being built (WHIT-708). Reset at the top of
     * every handle(), like the notification state below, because the
     * dispatcher outlives the request on a persistent worker.
     */
    private int $httpStatus = 200;

    /**
     * The request's MCP headers, lower-cased, for the 2026-07-28 checks.
     *
     * @var array<string, string>
     */
    private array $headers = [];

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
     * Internal header the transport uses to hand the request's tool-surface
     * selection to the dispatcher (WHIT-748). Set by
     * {@see \Whity\Mcp\Transport\McpTransportHandler} from the URL, after any
     * client header of the same name is discarded.
     */
    public const TOOL_SURFACE_HEADER = 'x-whity-tool-surface';

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
     * @param (\Closure(McpPrincipal): void)|null $recordTokenUse When provided, called with the principal of a
     *                                                            dedicated MCP token once it has passed every
     *                                                            gate, so "last used" can show an app is
     *                                                            connected (WHIT-697). Session bearers are
     *                                                            not recorded: they are not a connection.
     */
    public function __construct(
        private readonly array $handlers,
        private readonly ?TokenValidator $tokenValidator    = null,
        private readonly ?McpRateLimiter $rateLimiter       = null,
        private readonly ?\Closure $tenantMcpEnabled        = null,
        private readonly ?ListChangedNotifier $listChangedNotifier = null,
        private readonly ?\Closure $recordTokenUse          = null,
    ) {}

    public function handle(string $rawBody, ?string $bearerToken, array $headers = []): string
    {
        // Cleared first, before any path that can throw or return early: these
        // survive the request on a worker singleton, and a stale client key
        // would push the previous caller's notifications to this one.
        $this->notifyClientKey  = null;
        $this->notifySuppressed = false;
        $this->httpStatus       = 200;
        $this->headers          = array_change_key_case($headers, CASE_LOWER);

        // Auth check: validated before JSON parsing so that an unauthenticated
        // caller learns nothing about the request shape.
        $principal = null;
        if ($this->tokenValidator !== null) {
            try {
                $principal = $bearerToken !== null
                    ? $this->tokenValidator->validateBearerForMcp($bearerToken)
                    : null;
            } catch (OAuthTokenRefusedException $refused) {
                // A genuine sign-in that cannot be used here (no linked account,
                // no way to tell which workspace). 403, not 401: a 401 would send
                // the client to sign in again, which cannot fix it (WHIT-709).
                $this->httpStatus = 403;
                return $this->encode($this->makeError(null, ErrorCode::FORBIDDEN, $refused->getMessage()));
            }
            if ($principal === null) {
                // HTTP 401, as every MCP authorization revision requires: it is
                // what tells an OAuth client to go and sign in (WHIT-709).
                $this->httpStatus = 401;
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

                // WHIT-697: bookkeeping, so it must never cost the caller the call.
                // A signed-in agent (WHIT-825) has no MCP token row either.
                if ($this->recordTokenUse !== null && !in_array($principal->principalKind, ['session', 'oauth'], true)
                    && !str_starts_with($principal->jti, OAuthAccessTokenVerifier::AGENT_ID_PREFIX)) {
                    try {
                        ($this->recordTokenUse)($principal);
                    } catch (\Throwable $e) {
                        error_log('[mcp] recording token use failed (tenant ' . $principal->tenantId . '): ' . $e->getMessage());
                    }
                }
            }

            try {
                $decoded = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $this->encode($this->makeError(null, ErrorCode::PARSE_ERROR, 'Parse error'));
            }

            if (is_array($decoded)) {
                // Batching was removed in 2025-06-18. A client that negotiated
                // a later version and batches anyway gets a clear refusal
                // rather than an answer it cannot have expected.
                $declared = $this->headers['mcp-protocol-version'] ?? McpProtocol::ASSUMED;
                if (McpProtocol::atLeast($declared, '2025-06-18')) {
                    $this->httpStatus = 400;
                    return $this->encode($this->makeError(null, ErrorCode::INVALID_REQUEST, 'Batching is not part of protocol version ' . $declared));
                }
                return $this->handleBatch($decoded, $bearerToken, $principal);
            }

            if (!$decoded instanceof \stdClass) {
                return $this->encode($this->makeError(null, ErrorCode::INVALID_REQUEST, 'Invalid Request'));
            }

            $response = $this->dispatch($this->toArrayDeep($decoded), $bearerToken, $principal);
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
    private function handleBatch(array $items, ?string $bearerToken, ?McpPrincipal $principal): string
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
            $response = $this->dispatch($this->toArrayDeep($item), $bearerToken, $principal);
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
     * @param McpPrincipal|null    $principal The caller validated in handle(); null only
     *                                        when this dispatcher runs without auth.
     * @return array<string, mixed>|null
     */
    private function dispatch(array $request, ?string $bearerToken, ?McpPrincipal $principal): ?array
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

        $params = isset($request['params']) ? (array) $request['params'] : null;

        // WHIT-708: which protocol version this request speaks, read from the
        // request itself. Never remembered between requests.
        try {
            $version = $this->resolveVersion($method, $params);
        } catch (McpException $e) {
            $this->httpStatus = 400;
            return $isNotification ? null : $this->makeError($id, $e->errorCode, $e->getMessage(), $e->data);
        }
        $modern = McpProtocol::isModern($version);

        if ($method === 'initialize') {
            $this->notifySuppressed = true;
        }
        if ($modern) {
            // No standing notification stream for 2026 clients (see McpProtocol):
            // nothing is queued for them, and nothing they did is marked seen.
            $this->notifyClientKey = null;
        }

        // The handshake and the methods 2026-07-28 removed do not exist for a
        // modern request; it is told so the way that version expects (404).
        $removedForModern = $modern && in_array($method, ['initialize', 'ping', 'logging/setLevel'], true);
        if ($removedForModern || !isset($this->handlers[$method])) {
            if ($modern) {
                $this->httpStatus = 404;
            }
            return $isNotification ? null : $this->makeError($id, ErrorCode::METHOD_NOT_FOUND, 'Method not found');
        }

        // WHIT-695: the token's capability scopes decide which method families
        // it may use at all. Checked once here rather than in each handler, so
        // a new handler cannot forget it. Permission narrowing is per item and
        // lives in the handlers, next to the RBAC check it narrows.
        if ($principal !== null && !McpScope::of($principal)->allowsMethod($method)) {
            return $isNotification ? null : $this->makeError($id, ErrorCode::FORBIDDEN, 'Forbidden');
        }

        // Every handler sees the version in params._meta, where 2026 clients put
        // it themselves; earlier ones get it written there from the header.
        $params ??= [];
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $meta[McpProtocol::META_VERSION] = $version;
        // WHIT-748: which tool list the URL asked for, as the transport read
        // it. Always overwritten: a client cannot choose it through _meta.
        $surface = $this->headers[self::TOOL_SURFACE_HEADER] ?? null;
        $decoded = is_string($surface) ? json_decode($surface, true) : null;
        if (is_array($decoded)) {
            $meta[ToolSurfaceRequest::META_KEY] = $decoded;
        } else {
            unset($meta[ToolSurfaceRequest::META_KEY]);
        }
        $params['_meta'] = $meta;

        try {
            $result = ($this->handlers[$method])($params, $bearerToken);
        } catch (McpException $e) {
            // 2026-07-28 moved resource-not-found onto Invalid Params.
            $code = $modern && $e->errorCode === ErrorCode::RESOURCE_NOT_FOUND ? ErrorCode::INVALID_PARAMS : $e->errorCode;
            return $isNotification ? null : $this->makeError($id, $code, $e->getMessage(), $e->data);
        } catch (\Throwable) {
            return $isNotification ? null : $this->makeError($id, ErrorCode::INTERNAL_ERROR, 'Internal error');
        }

        return $isNotification ? null : $this->makeSuccess($id, McpProtocol::adaptResult($method, $result, $version));
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

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * The protocol version a request speaks (WHIT-708).
     *
     * A version in `params._meta` makes it a 2026-07-28 request, which must
     * name a version this server speaks and whose headers must agree with its
     * body: the spec requires both so that a gateway routing on the headers
     * and this server reading the body cannot be told two different things.
     * Otherwise the `MCP-Protocol-Version` header names a legacy version, or,
     * absent (2025-03-26 clients never send it), 2025-03-26 is assumed.
     *
     * @param array<string, mixed>|null $params
     * @throws McpException UNSUPPORTED_PROTOCOL_VERSION or HEADER_MISMATCH.
     */
    private function resolveVersion(string $method, ?array $params): string
    {
        $meta     = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $declared = $meta[McpProtocol::META_VERSION] ?? null;
        $header   = $this->headers['mcp-protocol-version'] ?? null;

        if ($declared === null) {
            if ($header === null) {
                return McpProtocol::ASSUMED;
            }
            if (in_array($header, McpProtocol::LEGACY, true)) {
                return $header;
            }
            if (McpProtocol::isModern($header)) {
                throw new McpException(
                    ErrorCode::HEADER_MISMATCH,
                    "Header mismatch: MCP-Protocol-Version is {$header} but the request carries no protocol version in _meta",
                );
            }
            throw self::unsupported($header);
        }

        if (!is_string($declared) || !McpProtocol::isModern($declared)) {
            // A legacy version is negotiated by initialize, never declared per
            // request, so anything but a modern version here is unsupported.
            throw self::unsupported(is_string($declared) ? $declared : '');
        }

        if ($header !== $declared) {
            throw self::mismatch('MCP-Protocol-Version', $header, $declared);
        }
        $headerMethod = $this->headers['mcp-method'] ?? null;
        if ($headerMethod !== $method) {
            throw self::mismatch('Mcp-Method', $headerMethod, $method);
        }
        $nameField = match ($method) {
            'tools/call', 'prompts/get' => 'name',
            'resources/read'            => 'uri',
            default                     => null,
        };
        if ($nameField !== null) {
            $expected   = is_string($params[$nameField] ?? null) ? $params[$nameField] : null;
            $headerName = self::decodeHeaderValue($this->headers['mcp-name'] ?? null);
            if ($expected === null || $headerName !== $expected) {
                throw self::mismatch('Mcp-Name', $headerName, $expected);
            }
        }

        return $declared;
    }

    private static function unsupported(string $requested): McpException
    {
        return new McpException(
            ErrorCode::UNSUPPORTED_PROTOCOL_VERSION,
            'Unsupported protocol version',
            ['supported' => McpProtocol::supported(), 'requested' => $requested],
        );
    }

    private static function mismatch(string $header, ?string $got, ?string $expected): McpException
    {
        return new McpException(
            ErrorCode::HEADER_MISMATCH,
            $got === null
                ? "Header mismatch: the {$header} header is required"
                : "Header mismatch: {$header} header value '{$got}' does not match body value '" . ($expected ?? '') . "'",
        );
    }

    /** A header value, with the spec's `=?base64?...?=` sentinel decoded. */
    private static function decodeHeaderValue(?string $value): ?string
    {
        if ($value !== null && preg_match('/^=\?base64\?(.*)\?=$/s', $value, $m) === 1) {
            $decoded = base64_decode($m[1], true);
            return $decoded === false ? null : $decoded;
        }
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function makeError(mixed $id, int $code, string $message, mixed $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return (string) json_encode($data, JSON_THROW_ON_ERROR);
    }
}
