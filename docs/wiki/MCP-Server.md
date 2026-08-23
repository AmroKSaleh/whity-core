# MCP Server

Whity Core exposes a [Model Context Protocol](https://modelcontextprotocol.io/) endpoint that AI clients can use to call platform tools, read resources, and run prompts — all with the same tenant isolation and RBAC that govern the regular HTTP API. This document is the authoritative reference for the implementation; file paths are cited throughout so the source can be read alongside this text.

Related: [MCP-Client-Connection](MCP-Client-Connection.md) · [MCP-Operator-Runbook](MCP-Operator-Runbook.md) · [Plugin-Development](Plugin-Development.md) (§ MCP contribution point) · [TENANT_ISOLATION](TENANT_ISOLATION.md) · [PERMISSION_SYSTEM](PERMISSION_SYSTEM.md) · [AUDIT_TRAIL](AUDIT_TRAIL.md).

---

## Architecture

The MCP surface is not a separate process. It is served by the same FrankenPHP persistent worker that handles ordinary HTTP requests, via a dedicated route:

```text
POST /mcp   ← JSON-RPC 2.0 message (request, notification, or batch)
GET  /mcp   ← 405: this server offers no standing SSE stream
```

There is deliberately no standing server-to-client stream. Under FrankenPHP a held
connection occupies a worker for its whole life, and there are eight of them —
eight subscribed MCP clients would leave nothing to serve HTTP with. Server-initiated
notifications are instead framed onto the **POST response** as `text/event-stream`,
which the MCP spec allows and which writes a complete body in one pass rather than
pinning a worker. See [Change notifications](#change-notifications).

The call path is:

```text
POST /mcp
  └─ McpTransportHandler::handlePost()          src/Mcp/Transport/McpTransportHandler.php
       └─ Dispatcher::handle()                  src/Mcp/JsonRpc/Dispatcher.php
            ├─ TokenValidator::validateMcpToken()
            ├─ TenantContext::setTenantId()
            ├─ McpRateLimiter::checkAndRecord()
            └─ MethodHandler (by method name)
                 ├─ initialize          → InitializeHandler
                 ├─ tools/list          → ToolsListHandler
                 ├─ tools/call          → ToolsCallHandler
                 ├─ resources/list      → ResourcesListHandler
                 ├─ resources/read      → ResourcesReadHandler
                 ├─ prompts/list        → PromptsListHandler
                 ├─ prompts/get         → PromptsGetHandler
                 ├─ ping                → PingHandler
                 └─ notifications/cancelled → CancelledNotificationHandler
```

`McpTransportHandler` (`src/Mcp/Transport/McpTransportHandler.php`) is a thin HTTP layer: it gates on `Content-Type: application/json`, extracts the Bearer token from the `Authorization` header, delegates to the `Dispatcher`, and maps transport-level exceptions (`McpRateLimitException` → 429, `McpFeatureDisabledException` → 403) to HTTP status codes. All JSON-RPC parsing, auth, tenant context, and method routing live in the `Dispatcher`.

`NullMcpDispatcher` (`src/Mcp/Transport/NullMcpDispatcher.php`) is wired when MCP is disabled at the infrastructure level; it returns an empty string for every call so the transport layer can still return 503 cleanly.

---

## Tool derivation

`ToolDeriver` (`src/Mcp/Tools/ToolDeriver.php`) converts route declarations into MCP tool definitions at `tools/list` call time. It reads from two sources:

1. **Static declarations** — passed to the constructor as `$staticDeclarations` (the core API route list).
2. **Router-native routes** — the `Router` is queried at derivation time (not at construction), so plugin routes registered after `ToolDeriver` is built are picked up automatically.

Only declarations with a non-empty `schema` array are included. A route declaration with `schema: null` or `schema: []` is silently skipped.

### operationId (tool name)

The tool name is taken directly from `schema['operationId']` when that key is a non-empty string. When `operationId` is absent, a deterministic name is derived:

```text
name = strtolower(method) . '_' . slug(path)
```

where `slug(path)` replaces every run of non-alphanumeric characters with an underscore and strips leading/trailing underscores. Routing constraints in path segments (`{id:\d+}`) are stripped before slugging, so `/api/users/{id:\d+}` and `/api/users/{id}` both yield the same operationId.

This logic mirrors `SchemaGenerator::operationId()` so MCP tool names stay in sync with the OpenAPI spec.

**Examples:**

| HTTP | Derived operationId |
| --- | --- |
| `GET /api/users` | `get_api_users` |
| `POST /api/users` | `post_api_users` |
| `GET /api/users/{id:\d+}` | `get_api_users_{id}` |
| `schema['operationId'] = 'listUsers'` | `listUsers` |

### Tool description

The `description` field is taken from `schema['summary']` when present. When absent, a human-readable summary is generated from the HTTP method:

| Method | Generated prefix |
| --- | --- |
| `GET` | `Get` |
| `POST` | `Create` |
| `PUT` / `PATCH` | `Update` |
| `DELETE` | `Delete` |

The result is `"{prefix} {path}"`, e.g. `Get /api/users`.

### inputSchema construction

`buildInputSchema()` assembles a flat JSON Schema `object` by merging three sources in order:

1. **Path parameters** — extracted from `{name}` or `{name:constraint}` segments of the route path. Always required. Type is `integer` when the constraint is `\d+` or `[0-9]+`; `string` otherwise.

2. **Query parameters** — declared in `schema['parameters']` with `in: query`. Optional unless `required: true`. Each parameter's type and description come from its `schema` sub-key.

3. **Request body** — resolved from `schema['request']`, which may be:
   - a **string component name**: looked up in the global `$components` map first, then in `schema['components']` on the route declaration itself.
   - an **inline schema array**: merged directly (bodies with a `content` key are skipped as they represent multipart or custom content types that cannot be expressed as a flat object).

   The resolved body schema's `properties` and `required` arrays are merged into the accumulator.

The final result always has `"type": "object"`. A `properties` key is omitted when the tool has no declared parameters. A `required` array is omitted when no parameters are required.

**Lint warning**: when a `POST`, `PUT`, or `PATCH` route has no resolvable request body, `ToolDeriver` emits a warning via `error_log()` (or the injected `$warn` closure). This surfaces missing schema coverage at derivation time rather than silently producing a parameter-free tool.

### Worker-boot cache

`ToolDeriver` stores the merged declarations list, the derived tools list, and the RBAC access map in three static properties (`$declarationsCache`, `$toolsCache`, `$accessMapCache`). These are populated on the first call and reused for the lifetime of the FrankenPHP worker process (WC-951d99d3), so the `Router` is queried at most once per worker.

Call `ToolDeriver::clearCache()` after registering plugin routes to ensure the next `tools/list` or `tools/call` request picks up the freshly registered tools.

---

## Authentication

### Bearer tokens

Every call to `POST /mcp` must carry an MCP bearer token:

```http
Authorization: Bearer <mcp-token>
```

The `Dispatcher` checks auth before JSON parsing, so an unauthenticated caller learns nothing about the request shape (`UNAUTHENTICATED` error code `-32001` is returned immediately).

MCP tokens are 90-day HS256 JWTs with `aud: mcp` and `type: mcp`. They are issued, listed, and revoked through a dedicated REST sub-API:

| Method | Path | Action |
| --- | --- | --- |
| `POST` | `/api/mcp/tokens` | Issue a new MCP token |
| `GET` | `/api/mcp/tokens` | List active tokens for the current user |
| `DELETE` | `/api/mcp/tokens/{jti}` | Revoke a token by JTI |

These endpoints require a valid human-user access token (cookie or Bearer). The issued MCP tokens are then used as Bearer tokens on `POST /mcp` for machine-to-machine calls from AI clients. See `src/Mcp/Auth/McpTokenHandler.php` and `src/Mcp/Auth/McpTokenService.php`.

Token JTIs are tracked in the `mcp_tokens` table. Revocation inserts the JTI into the shared `revoked_tokens` table, consistent with access/refresh token revocation.

### McpPrincipal

`TokenValidator::validateMcpToken()` returns an `McpPrincipal` on success (`src/Mcp/Auth/McpPrincipal.php`):

| Field | Type | Description |
| --- | --- | --- |
| `userId` | `int` | User the token was issued to |
| `tenantId` | `int` | Tenant the token is scoped to |
| `principalKind` | `string` | Principal kind (`user` for current phase) |
| `scope` | `string[]` | Granted scopes, e.g. `['tools:call']` |
| `jti` | `string` | JWT ID — unique revocation handle |

`McpPrincipal` is immutable and carries no static/global state, making it worker-safe by construction.

---

## Tenant scoping

After token validation the `Dispatcher` calls `TenantContext::setTenantId($principal->tenantId)`, locking the worker thread's tenant context to the principal's tenant for the duration of the request. `TenantContext` is a request-scoped static holder (see `src/Core/Tenant/TenantContext.php`).

`TenantContext::reset()` is called in the `Dispatcher`'s `finally` block, guaranteeing that tenant state is cleared even when `McpRateLimitException` propagates out. This mirrors the pattern used by the HTTP kernel and the worker loop to prevent tenant bleed across FrankenPHP persistent-worker requests.

The per-tenant MCP opt-in closure (`$tenantMcpEnabled`) is evaluated inside the same `try` block, after `setTenantId()` and before rate limiting. When the closure returns `false`, `McpFeatureDisabledException` is thrown and caught by `McpTransportHandler`, which returns HTTP 403.

---

## RBAC

Route declarations carry `requiredRole` and `requiredPermission` keys. `ToolDeriver::buildAccessMap()` reads these from the merged declarations at derivation time and builds a map of `toolName → {requiredRole, requiredPermission}`.

### tools/list filtering

`ToolsListHandler` (`src/Mcp/Tools/ToolsListHandler.php`) filters the tool list so callers only see tools they are permitted to use:

- Tools with no `requiredRole` and no `requiredPermission` are visible to all callers including unauthenticated ones.
- Protected tools are hidden when the bearer token is absent or invalid, or when `RoleChecker` denies the required grant.

Filtering is **soft-auth**: a missing or invalid token never throws; it simply restricts the visible set to open tools. RBAC is still **hard-enforced** in `ToolsCallHandler` when a tool is invoked.

### tools/call enforcement

`ToolsCallHandler` (`src/Mcp/Tools/ToolsCallHandler.php`) re-validates the bearer token to obtain the principal, then enforces the matched route's access controls via `RoleChecker` — the same component the HTTP `RbacMiddleware` uses, so MCP RBAC can never diverge from HTTP authorization:

1. `requiredPermission` is checked first via `RoleChecker::hasPermission()`.
2. When only `requiredRole` is set, `RoleChecker::hasRole()` is checked.
3. On denial, `McpException(ErrorCode::FORBIDDEN)` is thrown.

The authorization check reads access controls from the live `matched` route (the result of `Router::match()`), not solely from the declaration cache, so a plugin that changes its route's access requirements after worker boot is covered on the next request after `clearCache()`.

---

## Rate limiting

`McpRateLimiter` (`src/Mcp/RateLimit/McpRateLimiter.php`) enforces two independent fixed-window budgets, checked after authentication and before JSON parsing:

| Counter | Key | Default limit | Window |
| --- | --- | --- | --- |
| Tenant | `mcp:rate:tenant:{tenantId}` | 300 calls | 60 seconds |
| Principal | `mcp:rate:principal:{userId}` | 60 calls | 60 seconds |

Both counters are incremented atomically via `SharedStoreInterface::increment()` (the same interface `LoginThrottleService` uses). The tenant counter is checked first. When either limit is exceeded `McpRateLimitException` is thrown with a `retryAfterSeconds` value; `McpTransportHandler` maps this to HTTP 429 with a `Retry-After` header.

Fixed-window semantics: the TTL is set once on the first call in the window and never extended. The window resets atomically when the TTL elapses — no manual cleanup pass is needed.

---

## Security model

### Exception message isolation

`Dispatcher::dispatch()` catches all `\Throwable` from method handlers and returns `ErrorCode::INTERNAL_ERROR` with the fixed message `"Internal error"` — handler exception messages are never forwarded to the caller. Only `McpException` (thrown explicitly by method handlers for protocol-level errors) carries a caller-visible message, and those messages are intentionally minimal.

### Tenant bleed prevention

`TenantContext::setTenantId()` locks the context after the first call. Any subsequent attempt to set a different tenant within the same request throws a `RuntimeException`. `TenantContext::reset()` in the `Dispatcher`'s `finally` block clears the lock, preventing tenant state from persisting across requests on a shared FrankenPHP worker.

### AuditContext

`ToolsCallHandler` calls `AuditContext::set($principal->userId, null)` before invoking the tool, routing the AI principal's identity into the audit context. Any hook-fired audit entries written by mutation tools (e.g. `user.created`) therefore record the MCP actor rather than null. `AuditContext` is reset between requests by `HttpKernel::resetRequestState()` (WC-181) and the worker loop's `finally` block.

### Audit logging of tool calls

`ToolsCallHandler` records an `mcp.tools.call` audit entry in a `finally` block that wraps the entire tool execution, so the entry is written whether the call succeeds, fails RBAC, or encounters an internal error:

```json
{
  "action":        "mcp.tools.call",
  "tenant_id":     42,
  "actor_user_id": 7,
  "target_type":   "tool",
  "metadata": {
    "tool": "post_api_users",
    "args": { "email": "alice@example.com" }
  }
}
```

Arguments are redacted before storage: any key whose name contains `password`, `secret`, `token`, `code`, `hash`, `backup_code`, or `two_factor_secret` is stripped. This matches the redaction logic in `AuditLogger` (defense-in-depth).

### Application error containment

When a route handler throws inside `ToolsCallHandler::executeResolved()`, the exception is caught and `isError: true` content with the message `"Internal error"` is returned as an MCP result — the caller knows the tool failed but learns nothing about the underlying cause.

---

## Resources

`ResourceDeriver` (`src/Mcp/Resources/ResourceDeriver.php`) derives MCP resources from `GET` route declarations:

- Routes **without** path parameters become static resources (listed under `resources`).
- Routes **with** path parameters become resource templates (listed under `resourceTemplates`).

URI scheme: `whity-api:///api/v1/path`. Routing constraints are stripped from URI templates to produce RFC 6570-compliant `{id}` placeholders.

`ResourceDeriver` is stateless — all computation is per-call on the stack, so there is no worker-boot cache to clear.

---

## Prompts

Built-in prompts are registered via `CorePrompts::register()` (`src/Mcp/Prompts/CorePrompts.php`) at worker boot and stored in `PromptRegistry`. Each prompt has an optional `requiredRole` or `requiredPermission`; `PromptsListHandler` filters accordingly. The four built-in prompts are:

| Name | Access | Purpose |
| --- | --- | --- |
| `onboarding-walkthrough` | open | Initial tenant setup guide |
| `role-audit` | `admin` role | RBAC configuration audit |
| `relation-query` | `relations:read` | Relation graph exploration |
| `permission-summary` | `users:read` | Per-user effective permissions |

---

## Protocol

The `initialize` response declares the server's capabilities and protocol version:

```json
{
  "protocolVersion": "2025-03-26",
  "capabilities": {
    "tools":     { "listChanged": true },
    "resources": { "subscribe": false, "listChanged": true },
    "prompts":   { "listChanged": true }
  },
  "serverInfo": { "name": "whity-core", "version": "1.0" }
}
```

Batch requests are supported: a JSON array of request objects returns a JSON array of responses. Notifications (objects without an `id` member) are processed but produce no response.

---

## Change notifications

MCP clients cache the discovery lists at connection time. A client that connected
before a plugin rebuild used to keep its stale tool definitions indefinitely — which
is how records were once written double-encoded against a server that had been
serving the corrected schema all along (#952).

The server now emits the spec's list-change notifications:

| Notification | Sent when |
| --- | --- |
| `notifications/tools/list_changed` | the derived tool catalogue differs from the one this client was last told about |
| `notifications/resources/list_changed` | likewise for the resource catalogue |
| `notifications/prompts/list_changed` | likewise for the prompt catalogue |

**How it is decided.** The signal is content-derived, not event-driven: each worker
hashes the catalogue it would actually serve (`src/Mcp/Notifications/CatalogSignature.php`)
and compares that against what the calling client has already been told. This is what
makes it safe across the worker pool — a worker that has not yet picked up a plugin
change computes the old signature and stays silent, so no client is ever told about a
catalogue the answering worker cannot then serve. The "already told" marker lives in
the shared store, so one change produces one notification per client rather than one
per worker.

**How it is delivered.** On the client's next `POST /mcp`, provided the client
advertised `Accept: text/event-stream` (the MCP spec requires conformant clients to).
The response becomes an SSE body carrying the notification frames first and the
JSON-RPC response last. A client that did not offer to read an event stream is left
alone and keeps receiving plain JSON.

**What it costs when nobody is listening.** Nothing. There are no connections to
push to and no work is done on the reload path; a reload that no client ever follows
up on produces no notification and no error.

**What fires it.** Any change to the content of a served list: a plugin install,
update, or uninstall; an administrative enable or disable; a `POST /api/plugins/reload`
that finds a disk change; the per-request hot reload in development; and a deploy
that changes core routes. Reverts are announced too — an install followed by an
uninstall announces twice, because a client told about the second catalogue has its
record of the first retired.

**What does not fire it.** A worker boot on an unchanged catalogue (otherwise eight
workers would announce eight times); a reload that finds no disk change; ordinary
requests; and a plugin tripping the runtime error boundary, which leaves its
declarations registered and short-circuited — its behaviour changed, its tool
definitions did not. A caller whose *permissions* changed also sees a different
`tools/list` without any notification: that is a grant change, not a registry
change.

**Bound.** The shared store is a counter store with no value semantics, so what is
recorded is "this client has been told about this catalogue", not "the catalogue I
last served". Retiring a client's stale records on the way past covers reverts
within what a worker has observed (`CatalogSignature::RECENT_LIMIT`, the last 8
catalogues per list); beyond that a record expires on its own after
`ListChangedNotifier::SEEN_TTL_SECONDS` (24h). The failure mode at expiry is one
redundant `tools/list` round trip, which is the direction a correctness fix should
fail in.

---

## Error codes

Defined in `src/Mcp/JsonRpc/ErrorCode.php`:

| Constant | Value | Meaning |
| --- | --- | --- |
| `PARSE_ERROR` | `-32700` | JSON-RPC 2.0: the body could not be parsed as JSON |
| `INVALID_REQUEST` | `-32600` | JSON-RPC 2.0: the request object is structurally invalid |
| `METHOD_NOT_FOUND` | `-32601` | JSON-RPC 2.0: unknown method name |
| `INVALID_PARAMS` | `-32602` | JSON-RPC 2.0: method-specific parameter validation failed |
| `INTERNAL_ERROR` | `-32603` | JSON-RPC 2.0: unhandled server-side error (no details exposed) |
| `RATE_LIMITED` | `-32000` | MCP: call budget exhausted (HTTP 429) |
| `UNAUTHENTICATED` | `-32001` | MCP: bearer token absent or invalid (HTTP 401) |
| `RESOURCE_NOT_FOUND` | `-32002` | MCP: referenced resource URI not found |
| `FORBIDDEN` | `-32003` | MCP: caller does not have the required role/permission |
