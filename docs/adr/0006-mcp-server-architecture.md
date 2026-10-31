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

> **Amended (WHIT-695):** scope is enforced, and holds capabilities as well as
> permissions. See [Amendment 1](#amendment-1-2026-10-28-whit-695).

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

> **Amended (WHIT-695):** a `requiredRole` also counts as a gate, and an ungated route may opt
> in with `schema['mcp'] = true`. See [Amendment 1](#amendment-1-2026-10-28-whit-695).

Routes with a `requiredPermission` but no `schema` declaration produce a tool with
`inputSchema: { type: "object", properties: {} }` (untyped). The SDK lint task (Phase C,
task `142`) will warn on these at generation time.

Plugin-contributed routes follow the same derivation via `PluginMcpInterface` (Phase C,
task `138`). Plugin tool names are namespaced: `{plugin_namespace}.{operationId}`.

> **Amended (WHIT-713):** tool names are one flat namespace, not prefixed. See
> [Amendment 4](#amendment-4-2026-10-29-whit-713).

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

> **Amended (WHIT-713):** the limits are 300/min per tenant and 60/min per principal, with no
> per-tool counter. See [Amendment 4](#amendment-4-2026-10-29-whit-713).

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

## Amendment 1 (2026-10-28, WHIT-695)

Two parts of this decision were not what the code did. Headless deployments put MCP in front of
teams that build products on it, so the code now does what this ADR promised, and the ADR says
precisely what that is.

### Scopes are enforced

The `scope` claim was stored and displayed but never checked. It now holds two kinds of entry
(`src/Mcp/Auth/McpScope.php`):

| Entry | Meaning |
|---|---|
| `tools:call`, `resources:read`, `prompts:read` | **Capabilities.** Each opens one family of MCP methods: `tools/*`, `resources/*`, `prompts/*`. The Dispatcher refuses a method outside them with `-32003`. |
| A permission name (`users:read`) or a whole resource (`users:*`) | **Narrowing.** The token reaches only tools, resources and permission-gated prompts gated by one of them. Ungated and role-only items are out of reach. |

Rules:

- **Scopes narrow RBAC and never widen it.** Every scope check runs in addition to `RoleChecker`.
  A scope naming `users:delete` does nothing for a principal whose role lacks it.
- **No permission entries means the principal's full RBAC.**
- **No capability means every family.** This keeps tokens issued before enforcement working.
  New ones cannot be issued that way: `POST /api/mcp/tokens` requires at least one capability
  and refuses unknown permission names.
- **The `tools:`, `resources:` and `prompts:` prefixes are reserved.** Any entry under one means
  that family's capability. Earlier tokens carry spellings such as `tools:list`, and folding them
  in keeps them from being read as permission names that would narrow the token to nothing.
- **Session bearers** (a human's access token used for MCP) hold every capability and no
  narrowing.

### Which routes agents see

§4 said routes without a `requiredPermission` are excluded. The code listed them anyway. It was
not an open door: the Dispatcher requires a valid bearer and those handlers authenticate the
caller. But a model reads `tools/list` to decide what it may attempt, and the list included
login, refresh, two-factor disable, password change and payment webhooks.

The rule is now (`src/Mcp/McpExposure.php`): a route is offered to agents, as a tool, a listed
resource, or a `resources/read` target, when **either**

- a `requiredPermission` or `requiredRole` gates it (on its declaration or on the live Router
  route, which is the gate `tools/call` enforces), **or**
- its schema sets `'mcp' => true`.

Opt in only when the handler authorizes the caller itself, because the flag takes the missing
route-level gate on trust. Core's opt-ins are listed, with that reasoning, in
`CoreApiSchemas::AGENT_CALLABLE_UNGATED`: the caller's own notifications, inbox and audit
trail, the data-type lifecycle (per-type permissions), and document-route actions (only a
recipient may act). Everything else that is ungated stays off: session and credential
machinery, public endpoints and webhooks. A hidden tool called by name answers "Unknown tool",
the same as one that does not exist.

Plugin routes follow the same rule. A plugin route with no gate is no longer a tool unless its
schema opts in.

## Amendment 2 (2026-10-28, WHIT-706, WHIT-707, WHIT-708)

Three changes to how agents meet the server. None of them changes who may do what.

**Tool annotations (WHIT-706).** Derived tools carry MCP annotations from their HTTP method.
GET is read-only, and PUT and DELETE are destructive and idempotent. POST and PATCH leave
`destructiveHint` unset, the spec's cautious default, because many POSTs here are actions. A
route may override them with `mcp_annotations`.

**Discovery mode (WHIT-707).** `/mcp?tools=discover` offers three tools (`search_tools`,
`describe_tool`, `call_tool`) in place of the catalogue, for clients that cannot hold hundreds
of tools. The transport selects a second Dispatcher with the same handlers except tools/list and
tools/call. Those two read through the same visibility filter and call handler, so §4's rules
and the per-call checks apply unchanged.

**Protocol versions (WHIT-708).** §2's single version becomes 2025-03-26 through 2026-07-28 on
one endpoint. Each request's version is read from that request: from `_meta` (2026, with
header-to-body checks), else from the `MCP-Protocol-Version` header, else 2025-03-26. It is never
held between requests, which keeps the no-session design §2 chose.
- `subscriptions/listen` is deliberately not implemented. It pins a worker for the life of a
  stream, the same reason GET is 405.
- 2026 clients rely on `ttlMs` freshness hints instead of change notifications.
- No `outputSchema` is declared until route response schemas are verified against real responses
  (WHIT-120). Results still carry `structuredContent`.

## Amendment 3 (2026-10-28, WHIT-710)

§5 step 2 and the "Invalid arguments" row change. Arguments that fail the inputSchema are now a
tool execution error, `isError: true` with a text such as `Invalid arguments for post_api_users:
Missing required argument: email. Expected: email (string, required), name (string).`, not
JSON-RPC -32602. The spec puts input validation errors there so the model can correct itself:
clients pass tool errors to the model, while they may drop protocol errors. The route handler
still never runs, and the call is still audited.

An unknown tool stays -32601 at the top level, as the spec says. Under discovery mode,
`call_tool` with an unknown name is a tool error that points at `search_tools`, because there the
name is an argument the model chose.

## Amendment 4 (2026-10-29, WHIT-713)

Three corrections, raised by the first plugin to author MCP tools (#1419).

**Authored tools are told their tenant.** A handler registered through
`PluginMcpToolsInterface` is called as `fn(array $arguments, McpToolContext $context)` (SDK
1.46). The context carries the tenant the call was authorized in, the one the tool's
`requiredRole` / `requiredPermission` gate was checked against, and the calling profile. Before
this an authored tool received only its arguments, so a multi-tenant plugin could scope its
queries only by reading `TenantContext`, a core internal with no compatibility promise. A route
handler has always had this through its request; the two paths now match.

**§4: tool names are not namespaced.** There is one flat namespace. A derived tool is named by
its route's `operationId` (or a name generated from the method and path), whichever plugin
contributed it. An authored tool keeps the name it declares. On a collision the derived tool
wins, and among authored tools the first registration wins; the duplicate is dropped with a
logged warning. A plugin that wants its names to be unique should prefix them itself.

**§7: the rate limits are per tenant and per principal.** `McpRateLimiter` keeps two fixed
one-minute windows, `mcp:rate:tenant:{tenant_id}` (300 calls by default, `MCP_RATE_TENANT_LIMIT`)
and `mcp:rate:principal:{profile_id}` (60, `MCP_RATE_PRINCIPAL_LIMIT`). There is no per-tool
counter. The response on a limit is unchanged.

## Amendment 5 (2026-10-31, WHIT-747, WHIT-749)

Decided by the owner with `docs/design/MCP-TOOL-SURFACE.md`.

**Toolsets (WHIT-747).** Every tool belongs to one toolset, carried in its `_meta` as `dev.whity/toolset`, with `dev.whity/visibility` and `dev.whity/agentPolicy` beside it. Core tools map to the `core.<area>` toolsets through their OpenAPI tag (`CoreToolsets`), and a plugin's tools land in a toolset named after its namespace. A route schema may also say `'mcp' => ['expose' => false]`, which keeps even a gated route off the agent surface.

**Token toolsets are a third kind of scope entry (WHIT-749).** Besides capabilities and permissions, a scope may name `toolset:<id>` or `toolset:<prefix>.*`. A token naming any reaches only tools in those toolsets: they are filtered out of `tools/list` (and so out of discovery search) and refused on `tools/call` with `-32003`. The message names the token's toolsets, because the tool exists and the caller may hold its permission; it is the token that can't reach it. A token naming none is unchanged. `toolset:` is a reserved prefix, and issuance refuses an unknown `core.` toolset or a malformed id. A plugin toolset can only be checked for shape, since a token may be issued before the plugin is installed. Toolsets narrow and never widen, exactly like permission entries; RBAC still applies.

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
