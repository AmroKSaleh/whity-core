# ADR 0006: MCP Server Architecture

- **Status:** Accepted
- **Date:** 2026-06-24
- **Task / Issue:** Phase C — MCP Server Support (WC-37abea17)
- **Deciders:** Amro Saleh

## Context

Whity Core needs to expose its multi-tenant RBAC API surface as an MCP (Model Context Protocol)
server so that AI agents (Claude, Cursor, etc.) can call platform operations with the same
permission guarantees as human users.

### Forces

- **FrankenPHP persistent workers** — request-scoped state must never live in statics or globals;
  any per-call data must be initialized and reset around each request in the worker loop.
- **Tenant isolation** — `TenantContext` is the sole resolver of the active tenant; MCP must
  bind a session to exactly one tenant and keep that binding for the lifetime of the call.
- **OpenAPI-first codebase** — every route already carries an optional `schema` declaration
  (`operationId`, `request` component, per-status responses) used to generate the OpenAPI spec.
  MCP tools should derive from the same source of truth, not a separate registry.
- **RBAC at the boundary** — `RoleChecker` enforces permissions on every HTTP request; the MCP
  layer must pass through that same gate, not bypass it.
- **`SharedStoreInterface` available** — cross-worker atomic counters landed in WC-91f2 and are
  the natural primitive for MCP rate-limit windows.
- **Phase B identity model (ADR-0005)** — the target identity anchor is `profiles.id`
  (`profile_id`) + `active_tenant_id`. Phase B is still in progress; the initial MCP token
  model uses the transitional `user_id` + `tenant_id` claims from the current `users` table
  and migrates when Phase B stabilises.

---

## Decision

### 1. Protocol version

We adopt **MCP 2025-03-26** (Streamable HTTP transport). This is the current stable version of
the Model Context Protocol specification. It supersedes the earlier HTTP+SSE transport.

### 2. Transport

**Streamable HTTP** on a single endpoint pair:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `POST /mcp` | client → server | All JSON-RPC 2.0 messages (requests, notifications, batches) |
| `GET /mcp` | server → client | Optional SSE stream for server-initiated notifications |

The `POST /mcp` response is:
- `Content-Type: application/json` for single, non-streaming responses.
- `Content-Type: text/event-stream` when the client signals `Accept: text/event-stream` and
  the handler produces a streaming response (long-running tool calls).

Caddy configuration: `X-Accel-Buffering: no` header on SSE responses; `flush_interval -1` in
the Caddyfile reverse-proxy block to prevent buffering.

The `GET /mcp` SSE endpoint is optional and initially unimplemented; it is reserved for future
server-push notifications (e.g. resource change events).

### 3. AI-principal authentication

A new HS256 JWT token type `mcp` is introduced alongside the existing `access`, `refresh`, and
`temp` types. The token is presented as a standard `Authorization: Bearer` header on every MCP
request.

**Claims carried by an `mcp` token:**

```
{
  "jti":            "<uuid>",          // revocation handle (revoked_tokens table)
  "type":           "mcp",             // type discriminator
  "iat":            <unix>,
  "exp":            <unix>,            // configurable; default 30 days for long-lived agents
  "aud":            "mcp",             // audience guard against token substitution
  "user_id":        <int>,             // transitional; migrates to profile_id in Phase B
  "tenant_id":      <int>,             // the active tenant for this token
  "principal_kind": "ai-agent"|"service",
  "scope":          "<space-sep list>" // optional; restricts to a permission subset
}
```

**Phase B migration path:** When `profiles` is stable, `user_id` becomes `profile_id` and
`tenant_id` becomes `active_tenant_id`. Existing `mcp` tokens issued before Phase B can be
revoked in bulk via epoch bump or jti revocation. A new token version claim (`tv: 2`) will
distinguish Phase-B tokens; Phase-A tokens (`tv` absent) continue to work via a transitional
validation path until revoked.

**Token lifecycle:**
- Issued by `POST /api/auth/mcp/token` (Phase C task — AI-principal issuance endpoint).
- Revoked by `DELETE /api/auth/mcp/token/{jti}` or on password/account change.
- Revocation uses the existing `revoked_tokens` global table (jti lookup on every call).
- `TokenValidator::validateMcpToken()` checks: signature, type=`mcp`, aud=`mcp`, expiry,
  jti revocation, and (Phase B) epoch currency.

### 4. Tool derivation

Tools are derived automatically from `Router::getRoutes()` at worker boot. Derivation is
**memoized per-worker** and invalidated on plugin hot-reload; it is never computed per-call.

**Derivation rules:**

| Router field | MCP tool field |
|---|---|
| `schema['operationId']` (if set) | `name` |
| `METHOD_normalized_path` (fallback) | `name` — e.g. `GET /api/v1/users/{id}` → `GET_users_id` |
| `schema['summary']` | `description` |
| `schema['request']` component → JSON Schema | `inputSchema.properties` (body args) |
| `{name:regex}` path params | `inputSchema.properties` (required string/integer) |
| `requiredPermission` | internal gate; not exposed in the tool definition |

Routes with no `requiredPermission` are **excluded** — they represent public or infrastructure
endpoints that AI agents should not call directly.

Routes with a `requiredPermission` but no `schema` declaration produce a tool with
`inputSchema: { type: "object", properties: {} }` (untyped). The SDK lint task (Phase C,
task `142`) will warn on these at generation time.

Plugin-contributed routes follow the same derivation via `PluginMcpInterface` (Phase C,
task `138`). Plugin tool names are namespaced: `{plugin_namespace}.{operationId}`.

### 5. Per-call execution contract

For every `tools/call` invocation:

```
1. Validate params.name is a known tool          → JSON-RPC -32601 (Method not found) if not
2. Validate params.arguments against inputSchema  → JSON-RPC -32602 (Invalid params) if invalid
3. Parse Authorization: Bearer {mcp-token}        → JSON-RPC -32001 (Unauthenticated) if missing/invalid
4. Set TenantContext(active_tenant_id)            → scopes all subsequent DB queries
5. RoleChecker::check(requiredPermission)         → JSON-RPC -32003 (Forbidden) if denied
                                                    (no permission name in error body — no leak)
6. Synthesize Request from tool args:
     path params → route match params
     remaining args → JSON body
7. Invoke route handler
8. Map HTTP response to MCP content:
     200-299 body → [{ type: "text", text: json_encode(body) }]
     4xx/5xx     → isError: true, content: [{ type: "text", text: error_message }]
9. AuditLogger::record('mcp.tool.call', ...)
10. Reset TenantContext (worker safety — must happen even on exception)
```

Error code mapping:

| Condition | JSON-RPC error code | message |
|---|---|---|
| Missing / invalid token | -32001 | "Unauthenticated" |
| Token correct but permission denied | -32003 | "Forbidden" |
| Rate limit exceeded | -32000 | "Too many requests" + `data.retry_after` |
| Unknown tool name | -32601 | "Method not found" |
| Invalid arguments | -32602 | "Invalid params" |
| Handler panic / internal error | -32603 | "Internal error" (details logged, not exposed) |

### 6. Audit logging

Every MCP invocation writes an `audit_log` row via `AuditLogger`. Action keys:

| Trigger | Action key |
|---|---|
| `tools/call` | `mcp.tool.call` |
| `resources/read` | `mcp.resource.read` |
| Token issuance | `mcp.token.issued` |
| Token revocation | `mcp.token.revoked` |

Metadata included per invocation: `principal_kind`, `principal_id` (user_id / profile_id),
`tenant_id`, `tool_name` / `resource_uri`, `args_keys` (argument key names only — never values,
to avoid logging secrets). IP from `X-Forwarded-For` / `X-Real-IP`.

### 7. Rate limiting

Uses `SharedStoreInterface` (WC-91f2, `DatabaseSharedStore` in production). Keys:

```
mcp:rate:tenant:{tenant_id}                   # total calls/min across all tools for this tenant
mcp:rate:tenant:{tenant_id}:tool:{tool_name}  # per-tool budget (for expensive tools)
```

Default limits (configurable via per-tenant settings in Phase E):
- **60 calls/min** per tenant (global)
- **10 calls/min** per tool per tenant

On limit: JSON-RPC error `-32000`, body `{ "data": { "retry_after": <seconds> } }`.
The `Retry-After` HTTP header is also set on the enclosing HTTP response.

### 8. Discovery surface

All lists are **RBAC-filtered** to the AI principal's effective permission set. An agent can
never enumerate tools it cannot call.

| MCP method | What it returns |
|---|---|
| `initialize` | `{ tools: {}, resources: {}, prompts: {} }` capabilities object |
| `tools/list` | All tools for the principal's permissions; cursor-paginated (50/page) |
| `resources/list` | GET routes with `schema.summary`, URI template from route path |
| `resources/read` | Resolves resource URI → invokes GET handler → returns content |
| `prompts/list` | Curated permission-aware templates (implemented in Phase C task 119) |
| `prompts/get` | Returns a specific prompt template by name |

`ping` and `notifications/cancelled` are handled per the MCP lifecycle spec.

---

## Alternatives Considered

- **Adopt the HTTP+SSE transport (MCP 2024-11-05)** — two separate endpoints (`POST /mcp` +
  `GET /mcp/sse`); more client support today. Rejected: deprecated in 2025-03-26; Streamable
  HTTP is simpler (one endpoint) and Caddy/FrankenPHP handle it identically.

- **Use the `users` table as the MCP identity anchor permanently** — avoids a Phase B
  dependency. Rejected: ADR-0005 explicitly targets `profiles` as the identity anchor;
  building MCP on `users` would require a second migration when Phase B lands. Transitional
  `user_id` claim with a clear migration path is the pragmatic middle ground.

- **Derive tools from the OpenAPI JSON file** — generate the spec, read it back. Rejected:
  introduces a file I/O step at boot that breaks the hot-reload story and diverges from the
  live Router state. Deriving directly from `Router::getRoutes()` is the single source of truth.

- **Expose every route (including public routes) as MCP tools** — simpler derivation. Rejected:
  public endpoints (health check, OpenAPI JSON, login) are inappropriate for agent invocation
  and would pollute `tools/list` with noise. `requiredPermission` is the natural filter.

---

## Consequences

### Positive

- AI agents can call any permission-gated platform operation with the same RBAC guarantees as
  human users — no parallel permission system to maintain.
- Tool list stays automatically up to date as routes and plugins are added; no manual MCP
  registry.
- Rate limiting reuses the already-deployed `SharedStoreInterface` — no new infrastructure.
- Audit trail covers all AI-initiated actions via the existing `AuditLogger`.

### Negative / Trade-offs

- Every route that should be MCP-callable needs an `operationId` in its `schema` declaration;
  routes without one get a synthesized (ugly) name. This is a documentation burden on future
  route authors.
- The transitional `user_id` / Phase B `profile_id` dual-path adds complexity to
  `validateMcpToken()` during the Phase B transition window.
- `tools/list` RBAC filtering requires a `RoleChecker` evaluation per-tool per-caller at list
  time; for tenants with many tools this is a non-trivial DB read. Mitigated by per-worker
  memoization of the base tool list and per-caller permission caching.

### Impact on existing conventions

- A new `src/Mcp/` namespace is introduced (PSR-4 autoloaded); no existing namespaces change.
- `TokenValidator` gains `validateMcpToken()`. Existing `validateAccessToken()` and
  `validateRefreshToken()` are unchanged.
- `Router` gains `getRoutes(): array` if not already public; no route registration changes.
- The Caddy/FrankenPHP configuration gains SSE-safe headers on the `/mcp` path.
- `SanctionedGlobalTables` does NOT need a new entry — `mcp_tokens` (if persisted) would be
  tenant-scoped; the `revoked_tokens` table already covers jti revocation.

---

## References

- [MCP specification 2025-03-26](https://spec.modelcontextprotocol.io/)
- [ADR-0005: Identity & tenant-membership model](0005-identity-tenant-membership-model.md)
- [ADR-0007: MCP JSON-RPC dependency decision](0007-mcp-jsonrpc-dependency.md)
- WC-91f2: SharedStoreInterface (cross-worker atomic counters)
- WC-185: Token epoch invalidation
- Phase C task board: AI-principal issuance, transport endpoint, JSON-RPC dispatcher,
  ToolDeriver, per-call authz, audit, rate-limit, worker-safety review
