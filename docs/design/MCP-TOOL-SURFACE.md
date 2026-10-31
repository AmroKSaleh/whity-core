# MCP tool surface: search and call, toolsets, splits and budgets

Status: **decided** (owner answers of 2026-10-31, §10). Nothing here is built yet; implementation starts when the owner says to build.
Related: ADR-0006 and its amendments (MCP server), ADR-0015 (OAuth; the authorization server is Whity ID), `00-decision-register.md` (agent model: Scope grant, 7.5a to 7.5c), `12-engineering-handoff.md` items 10 and 11, `docs/design/DOCUMENT-THEMES.md` §6 and §7, `docs/design/DOCUMENT-AUTOMATION.md` §3.10 (both on branch `design/document-themes`), `docs/design/WHITY-BUILDER.md` (branch `design/whity-builder`).

The owner's problem: "too many mcp tools in one create a disaster... and not all users have or get all the tools permissions yet get them in the context anyway in addition plugins add more and more tools... we need an sdk way to manage this correctly".

## 1. Summary

- **Access is already right; the size is not.** `tools/list` already shows a caller only the tools it may call (exposure, token scope, RBAC; §2.2). An admin token still sees 313 core tools, about 150 KB of JSON, and every plugin adds more.
- **One server, search and call.** In the next release the bare `/mcp` lists two tools: `search_operations` (finds what the caller can call, filtered by RBAC and the token) and `call_operation(operationId, arguments)`. They cost about 400 tokens however many plugins are installed. Every operation, from core or any plugin, is reached through them. There is no code-execution sandbox (no Code Mode `execute`).
- **About five always-on tools.** Next to search and call, five read-only tools for everyday requests stay listed directly: who am I, my inbox, get a document, list templates, and find documents. They add about 720 tokens. Every write goes through search and call.
- **`?tools=full` keeps the old list** for clients that want it. The switch is called out in the release notes.
- **Toolsets.** Every operation belongs to one toolset: `core.<area>` for core, `<pluginKey>[.<sub>]` for a plugin. Toolsets organise search results, token scope and splits. They never grant access.
- **Token toolsets are enforced.** A token whose scope names toolsets (`toolset:core.documents`) can only find and call operations in them. A call outside them is refused, not just hidden.
- **Plugins.** A plugin may ask for at most one always-on tool, and it shows only after a tenant admin enables it. Descriptions are capped at 500 characters. Everything else a plugin offers is reached through search. The next free SDK minor when it merges adds `toolset`, `visibility`, `agentPolicy` and `undo` to tool descriptors, and an optional `PluginMcpToolsetsInterface`.
- **Agents follow the register's model (§5.11).** An agent is an AI principal (`ai-agent`) acting for the person who granted it. Each permission is Off, Ask first or Allowed for it, never beyond that person's own RBAC. Ask first is real on the server: the call stores a proposal (operation, arguments, diff, permissions, undo window), and approval executes it for the granting person. Undo works for the operation's window. Every write records its actor: person, agent for person, or system. Routes can cap agents with `agentPolicy` (`propose`, `suggest-only`, `refuse`). A person's own client acts as that person.
- **Splits are optional.** `/mcp/<split>` serves one core area or one plugin as its own MCP server, also in search-and-call mode. It is for narrow clients and hard boundaries. The connect wizard recommends the single `/mcp`.

## 2. What exists today

### 2.1 Where tools come from

| Source | Code | Notes |
|---|---|---|
| Derived from routes | `src/Mcp/Tools/ToolDeriver.php` | One tool per route with a `schema`: core routes from `CoreApiSchemas::routes()` plus plugin routes read lazily from the `Router`. Name = `operationId` or `<method>_<path>`. No core route sets `operationId`, so every core name is path-derived (`get_api_users_id`), and the same name is the OpenAPI operationId. Annotations come from the HTTP method (`ToolAnnotations`, WHIT-706). Cached per worker in statics; `clearCache()` on plugin reload. |
| Exposure rule | `src/Mcp/McpExposure.php` (ADR-0006 §4, WHIT-695) | A derived tool is offered only when its route is gated (`requiredPermission` or `requiredRole`) or its schema says `'mcp' => true`. This keeps login, 2FA and webhook routes off the surface. |
| Authored by plugins | `sdk/src/PluginMcpToolsInterface.php` (SDK 1.43), `src/Mcp/Tools/AuthoredToolRegistry.php` | Descriptor: `name`, `description`, `inputSchema`, `handler`, plus `requiredPermission` / `requiredRole` / `open: true` (fail closed), `annotations` (1.45). The handler gets `McpToolContext` (tenant, profile; 1.46). Derived names win collisions. `suppressesDerivedMcpTools()` drops the plugin's own derived routes. |
| Discovery mode | `src/Mcp/Tools/Discovery/DiscoveryTools.php` (WHIT-707) | `/mcp?tools=discover` lists three tools: `search_tools`, `describe_tool` and `call_tool`. Search and describe read the same filtered list as `tools/list`; `call_tool` goes through the real `ToolsCallHandler`. A real tool called by its own name still works. This is the base the new default builds on. |

Plugins have no per-tenant switch today: a loaded plugin's tools exist for every tenant, and permissions alone decide who sees them. Tenant-scoped manifest plugins from the Whity-Builder design (WHIT-760) change that. Their tools join search and call per request, for one tenant only. This design treats the catalogue as per tenant wherever it matters (§5.6, §5.9, §5.12).

### 2.2 How access applies, list versus call

`ToolsListHandler::listFor()` and `ToolsCallHandler` apply the same chain, in this order:

1. **Method capability** (`McpScope::allowsMethod`, Dispatcher): the token needs `tools:call` for `tools/list` and `tools/call`.
2. **Exposure** (`ToolDeriver::isExposed`).
3. **Token permission narrowing** (`McpScope::allowsPermission`): if the token names permissions, only tools gated by one of them. Ungated and role-only tools drop out.
4. **RBAC** (`RoleChecker::hasPermissionForProfile` / `hasRoleForProfile`) against the tenant on the request.

Open tools (no gate) are listed for any authenticated caller, and the Dispatcher refuses unauthenticated `tools/list`. The connect wizard (`McpConnectHandler::preview`, WHIT-697) previews a scope by calling the same `listFor()`. `GET /api/v1/admin/mcp/tools` lists the unfiltered catalogue on purpose; it is admin-only and not MCP.

So "users get tools they have no permission for in their context" **does not happen today**. What does happen is that a broad role or an unnarrowed token sees everything it is allowed to call, and plugins grow that with no limit.

### 2.3 Measured size (develop at 0b3e2d4e)

Measured by running `ToolDeriver` over `CoreApiSchemas` (the script is in the session notes; tokens estimated as bytes ÷ 4):

| | Count |
|---|---|
| Core routes with a schema (derived tools) | 363 |
| Exposed to MCP | **313** (20 open, 131 read-only) |
| Full `tools/list` for a caller who may use all of them | 149,610 bytes, roughly 25k to 37k tokens depending on tokenizer |
| Median tool definition | 400 bytes (largest 1,923) |
| Longest tool name | 55 characters; none uses a dot |
| Today's three discovery tools | 1,582 bytes, about 400 tokens |
| The five proposed always-on tools (§5.2) | 2,885 bytes, about 720 tokens |

By permission resource, the biggest areas are documents 39, convening 25, plans 24, forms 20, admin-role-only 18, ous 15, settings 14 and tags 14.

**With plugins.** The plugins in this repo add 22 schema routes (HelloWorld 6, DemoCatalog 5, UiKitShowcase 11). The plugin store and theme creator in `whity-org/whity-plugins` add 13. Whity ID and Whity Pay were not reachable from here, so they aren't counted. A typical install would be about 330 to 360 tools as a full list. With search and call that is the same 1.1k tokens.

### 2.4 Change notification and caching

- Legacy clients (2025-03-26 to 2025-11-25): `listChanged: true`. Notifications ride on POST responses as SSE frames (`ListChangedNotifier`, #952), and the signature covers the unfiltered catalogue (`CatalogSignature`).
- 2026-07-28 clients: no `subscriptions/listen`. List results carry `ttlMs: 60000`, `cacheScope: private` (`McpProtocol`).

## 3. What the MCP spec gives us, and what it doesn't

Checked against the 2026-07-28 specification and the SEP index on 2026-10-31.

**Standard:**

- `tools/list` "MAY vary by the authorization presented on the request" and "MUST NOT vary per-connection or as a side effect of other requests on the connection" (2026-07-28, Tools). Per-token filtering is fine. A stateful `enable_toolset` tool is not.
- Deterministic order is a SHOULD, for client caching and prompt-cache hits.
- Tool names (SEP-986): 1 to 128 characters from `[A-Za-z0-9_.-]`, unique per server.
- `_meta` carries implementation data under a reverse-DNS prefix; `io.modelcontextprotocol/` is reserved.
- `ttlMs` / `cacheScope` (SEP-2549); Server Cards for pre-connection discovery (SEP-2127); the Skills extension (SEP-2640).

**Not standard:** tool groups, toolsets, namespaces, server-side tool search, per-client selection. These are ours, and we expose them only through standard surfaces: ordinary tools, URL paths and queries, OAuth scope strings and `_meta`. We add no protocol methods.

**Prior art the owner was shown:** Cloudflare's docs now recommend its single Code Mode API server, which exposes `search()` and `execute()`. It costs about 1k tokens for 2,594 endpoints, against about 244k to 1.17M as native tools. Its product-specific servers are kept only for distinct jobs (browser, containers, docs). This design takes the search half and leaves out `execute`: a model-written program needs a sandbox, and we call one operation at a time.

**Names stricter than the spec.** Several model APIs accept only `[a-zA-Z0-9_-]` in function names, and some cap them at 64. Tool names keep to `[A-Za-z0-9_-]{1,64}`, and namespacing uses an underscore prefix. Toolset ids never reach a model API as a function name, so they may use dots.

## 4. Design goals

1. A caller's context holds only what it can use. This is true today and must stay true.
2. The starting context is small and the same size whatever the plugin count.
3. Everything the caller may call stays reachable without reconnecting.
4. Selection is per request and per token, never per connection state.
5. Only token scope and RBAC change access. Toolsets and visibility never grant anything.
6. Plugin authors declare operations with permission, toolset, visibility and agent policy in one place, and the host enforces the budgets.
7. A client that wants the old full list can still have it.

## 5. Design

### 5.1 Toolsets and namespacing

| Field | Rule |
|---|---|
| `id` | Core: `core.<area>` or `core.<area>.<sub>` (for example `core.documents.themes`), each segment in `[a-z0-9_-]`. Plugin: `<pluginKey>` or `<pluginKey>.<sub>`, where `<pluginKey>` is the plugin's loader key lower-cased with other characters replaced by `-`. The first segment `core` is reserved. |
| `title` | English, at most 40 characters, with an Arabic label. |
| `description` | One sentence, at most 160 characters, shown by `search_operations` and the connect wizard. |
| `owner` | `core` or the plugin key. A plugin can only declare toolsets under its own key. |
| `requiresEntitlement` | Optional `EntitlementRegistry` key. Tenants without it don't see the toolset's operations anywhere (§5.12). For paid slices such as Whity-Builder. |
| `split` | Optional, plugins: whether a sub-toolset gets its own endpoint (§5.12). A root toolset always does. |

**Core toolsets** are a curated map in a new `src/Mcp/Tools/CoreToolsets.php`, merging the 40 OpenAPI tags into areas a person would name: `core.me`, `core.people` (users, roles, OUs, groups, memberships), `core.documents` (with `core.documents.themes` and `core.documents.automation`), `core.forms`, `core.convening`, `core.inbox` (inbox and notifications), `core.taxonomy`, `core.time`, `core.settings` (settings, branding, languages, translations), `core.billing` (plans, subscriptions, entitlements, licensing), `core.security` (auth providers, SSO, security, 2FA recovery, audit), `core.plugins` (plugin management and Whity-Builder's manifest versions, WHIT-764) and `core.platform` (platform-ops, tenants, instance). A derived core operation takes its toolset from its route's first tag through that map; a route in a core sub-toolset names it with `schema['mcp']['toolset']` (the document theme routes, tagged `documents`, name `core.documents.themes`). `RouteCatalogueCompletenessTest` checks that every exposed core route has one.

**Names.** Existing derived names don't change: they are the OpenAPI operationIds, published in `openapi.json`, `schema.d.ts` and the typed clients. New authored plugin tools must start with `<pluginSlug>_` (the plugin key with `-` replaced by `_`, for example `theme_creator_suggest_palette`). Older unprefixed names get a deprecation warning until SDK 2.0. Derived plugin names keep their path-derived names, which the Router already keeps unique.

### 5.2 Visibility

| Visibility | Bare `/mcp` (search and call) | `?tools=full` | Found by `search_operations` | Callable |
|---|---|---|---|---|
| `always-on` | listed directly | listed | yes | yes |
| `discoverable` (the default) | through search and call | listed | yes | yes |
| `off` | no | no | no | no (not exposed) |

**Core always-on tools (five, all read-only):**

| Everyday request | Operation | Bytes |
|---|---|---|
| Who am I | `get_api_me` | 271 |
| My inbox | `get_api_me_inbox` | 614 |
| Get a document | `get_api_documents_id` | 346 |
| List templates | `get_api_document_sources` (editor M4c replaced `get_api_document_templates`, 259) | not yet measured |
| Search records | `get_api_search` (`GET /api/search`, WHIT-783, toolset `core.search`) | about 900 |

Core has no cross-record search route today; nothing derived matches search, find or lookup. The owner decided to build `GET /api/search` (WHIT-783, §5.13), which serves this tool and the ⌘K command palette (register 2.1c). Until it ships, document listing stands in. Measured with document listing, the five cost 2,885 bytes, about 720 tokens. With search and call, the bare `/mcp` comes to about 1.1k tokens.

**Plugin always-on tools.** A plugin may mark **one** tool `always-on`. Any more are demoted to `discoverable` at load, with a warning. The one tool is listed only after a tenant admin enables it on the admin MCP page (stored per tenant as `mcp.always_on_plugin_tools`; the operator can block it instance-wide). It must be read-only (`readOnlyHint: true`), because every write goes through search and call. Its description is capped at 500 characters, like every other description.

Visibility never decides access. An always-on tool the caller can't use is filtered out by §2.2 like any other.

### 5.3 List modes

Read from the request every time. Nothing is remembered between requests.

| URL | `tools/list` returns | Notes |
|---|---|---|
| `/mcp` (next release) | always-on tools, then `search_operations` and `call_operation` | The new default. About 1.1k tokens for core; at most one more tool per admin-enabled plugin. |
| `/mcp?tools=full` | every operation the caller may call, as today | For clients that want the full list. Called out in the release notes. |
| `/mcp?tools=discover` | only `search_operations` and `call_operation` | The smallest list (about 400 tokens). Existing `discover` URLs keep working, with the new tool names. |
| `?toolsets=a,b.*` | combines with any mode: limits the list and search to those toolsets | Presentation only. Calling by operationId still works within the token's reach. |

Unknown `tools=` values give the default. The transport already picks a dispatcher per request (`McpTransportHandler::dispatcherFor`); this becomes a `ToolSurfaceRequest` value object (mode, toolsets, split), so no mode lives in worker state.

Calling an operation by its own name with `tools/call` keeps working in every mode, for clients that remember names. Access is the same as through `call_operation`.

### 5.4 Token toolsets (enforced)

`McpScope` gains a third kind of entry, alongside capabilities and permissions:

- `toolset:<id>`, or `toolset:<prefix>.*` (for example `toolset:core.*`, `toolset:acme.*`).
- A token that names any toolset reaches only operations in them, and the limit is **enforced**: in `tools/list`, in `search_operations`, and in `call_operation` and `tools/call`. A call outside them is refused with a tool error naming the token's toolsets, not answered "unknown".
- A token that names none keeps every toolset, so existing tokens are unchanged.
- `toolset:` becomes a reserved prefix, and `issuanceErrors()` rejects unknown ids.
- OAuth clients of Whity ID (the ADR-0015 authorization server) request it in the ordinary `scope` string.

Access order: capability, exposure, entitlement (§5.1), toolset narrowing, permission narrowing, RBAC (for an agent: the granting person's RBAC intersected with the scope grant), then the agent mode (§5.11: Off refuses, Ask first stores a proposal, Allowed runs). Presentation (mode, visibility, `?toolsets=`) applies only to the list and search.

### 5.5 `search_operations` and `call_operation`

Two tools replace `search_tools`, `describe_tool` and `call_tool`. Describing an operation is now a mode of search, so there are two tools rather than three.

**`search_operations`**

| Argument | Meaning |
|---|---|
| `query` | Words describing the task. Empty: returns the table of contents instead (below). |
| `toolset` | Optional: search one toolset (`core.forms`, `acme`). |
| `operationId` | Optional: return one operation in full, with its description, `inputSchema`, annotations, toolset and `agentPolicy`. This replaces `describe_tool`. |
| `limit` | Default 10, at most 50. |

- A match returns `operationId`, `title`, a summary of at most 200 characters, `toolset`, `readOnly`, `destructive` and `agentPolicy`. Schemas are left out, to keep results small; the model asks for one by `operationId`.
- An empty query returns the toolsets the caller can reach, with id, title, description, operation count and split URL. That is the table of contents a model reads first.
- Every result goes through the same `listFor()` filter as `tools/list`: exposure, entitlement, token toolsets and permissions, then RBAC. A caller can't find what it can't call. Unknown and forbidden read the same: "no such operation".
- **Search quality now matters most**, because most operations are reached only this way. Ranking indexes the operationId, title, summary, OpenAPI tags, parameter names, and the toolset titles in English and Arabic. Operation summaries are English only today, so Arabic queries match at toolset level until summaries get Arabic text (later). A CI evaluation (`McpSearchEvalTest`) keeps about 40 everyday requests mapped to the operation each should find in the top 3. A change that breaks the evaluation fails the build.

**`call_operation(operationId, arguments)`**

- Hands the call to `ToolsCallHandler`, which re-checks access, validates the arguments, enforces `agentPolicy`, audits and rate-limits it. The audit records `via: call_operation`.
- An Ask-first call returns a waiting proposal as a normal result (§5.11).
- An unknown or forbidden `operationId`, invalid arguments, or an agent refusal come back as tool errors (`isError: true`) the model can act on, not protocol errors (WHIT-710 rule).
- Annotations: `readOnlyHint: false`, `destructiveHint` left at the cautious default. The description tells the model to read the operation with `search_operations` first, because a write is real.

**Old names.** `search_tools`, `describe_tool` and `call_tool` stay callable, but unlisted, for one minor release, then go. The release notes say so.

No `enable_toolset` tool (§7).

### 5.6 Budgets and lints

| Budget | Limit | Enforced where |
|---|---|---|
| Operation name | `[A-Za-z0-9_-]{1,64}` | Authored: refused at registration. Derived: CI (all 313 pass; longest is 55). |
| Description | ≤ 500 characters | Authored: refused. Derived core: CI (none exceeds it today). Derived plugin: warning at load. |
| Search summary | ≤ 200 characters | Cut automatically in results. CI warns for core summaries over it. |
| `inputSchema` | ≤ 8 KB encoded | Warning at load; CI for core. |
| Core always-on tools | 5, about 3 KB | CI (`McpToolBudgetTest`). |
| Plugin always-on tools | 1 per plugin, read-only, admin-enabled | Loader demotes extras; the admin page enforces read-only. |
| Bare `/mcp` list | ≤ 12 tools and ≤ 10 KB encoded (about 2.5k tokens) | At runtime, per tenant: past the cap, admin-enabled plugin tools are dropped from the list (still searchable) in plugin-key order, with a warning. Config: `MCP_LIST_MAX_TOOLS`, `MCP_LIST_MAX_BYTES`. |
| Search and call tool definitions | ≤ 2 KB together | CI. |
| Toolset description | ≤ 160 characters | Refused at declaration. |
| `?tools=full` | no cap | Opt-in. The admin page shows the estimated size per role. |

### 5.7 Plugin SDK (next free minor, additive)

Several changes bump the SDK at once (Whity-Builder takes 1.47.0 and 1.48.0; editor M4c takes the next one). This change takes whichever minor is free on develop when it merges.

**Authored tool descriptor, new optional keys** (`PluginMcpToolsInterface`, unchanged interface):

```php
[
    'name'               => 'theme_creator_suggest_palette', // must start with the plugin slug
    'description'        => 'Suggest an accent colour pair for a tenant theme. Reads only.', // ≤ 500 characters
    'inputSchema'        => [...],
    'handler'            => fn (array $args, McpToolContext $ctx) => ...,
    'requiredPermission' => 'themes:read',   // or requiredRole, or open: true (fail closed, as today)
    'annotations'        => ['readOnlyHint' => true],
    'toolset'            => 'theme-creator.palettes', // NEW: under the plugin's own key; default '<pluginKey>'
    'visibility'         => 'discoverable',           // NEW: 'always-on' | 'discoverable'; default 'discoverable'
    'agentPolicy'        => 'allow',                  // NEW (§5.11): 'allow' | 'refuse' | 'suggest-only' | 'propose'
    'undo'               => ['windowSeconds' => 1800, 'operation' => 'acme_restore_thing'], // NEW (§5.11): optional; omitted = no undo, so Ask first for agents
]
```

A foreign `toolset`, an unknown `visibility` or `agentPolicy`, or an `always-on` tool that isn't read-only is refused at load. Only one `always-on` tool per plugin is kept, and it still needs a tenant admin to enable it (§5.2).

**New optional interface** `Whity\Sdk\PluginMcpToolsetsInterface`:

```php
interface PluginMcpToolsetsInterface
{
    /** @return list<array{id: string, title: string, titleAr?: string, description: string, split?: bool, requiresEntitlement?: string}> */
    public function getMcpToolsets(): array;
}
```

`id` is relative (`'palettes'`), or empty for the plugin's root toolset; the host prefixes the plugin key. A toolset used but never declared gets the plugin's name as its title, with a warning.

**Derived plugin routes** use `schema['mcp']` (§5.8). **Conformance**: the SDK conformance kit (WHIT-272) checks the name prefix, budgets, toolset ownership, visibility and agent policy, so authors find out at their desk.

### 5.8 Derived operations: opt in and opt out

`schema['mcp']` already accepts `true` (expose an ungated route). It now also accepts an array:

```php
'schema' => [
    'summary' => 'List the tenant\'s forms',
    'tags'    => ['forms'],
    'mcp'     => [
        'expose'      => true,           // optional; true = old 'mcp' => true; false = 'off' even when gated
        'visibility'  => 'discoverable', // optional; 'always-on' only for core picks and one per plugin
        'toolset'     => 'core.forms',   // optional; core default from the tag map; plugin default '<pluginKey>'
        'agentPolicy' => 'allow',        // optional (§5.11)
        'undo'        => ['windowSeconds' => 1800, 'operation' => 'post_api_things_id_restore'], // optional (§5.11)
    ],
],
```

`'expose' => false` takes a gated route off MCP, for example bulk-import or binary-upload routes a model can't use well. Core's always-on picks live in `CoreToolsets.php`, so the owner reviews one list. `suppressesDerivedMcpTools()` stays as the whole-plugin switch.

### 5.9 Wire format, notifications and caching

- Each tool object (in `?tools=full`, and in search results) carries `_meta` `dev.whity/toolset`, `dev.whity/visibility` and `dev.whity/agentPolicy`. Clients ignore keys they don't know.
- Order: always-on tools in `CoreToolsets.php` order, then admin-enabled plugin tools by plugin key, then `search_operations` and `call_operation`. In `?tools=full`, everything is ordered by toolset id and name. The order stays stable while the catalogue doesn't change.
- The bare list hardly changes when plugins are installed, so client prompt caches stay warm. Legacy clients are told once, through the existing `list_changed` path, when the upgrade changes their list.
- `CatalogSignature` hashes the unfiltered catalogue including toolset, visibility and agent policy. For tenant-scoped manifest plugins, it adds a hash of that tenant's plugin operations, so a publish in one tenant notifies only that tenant's clients.

### 5.10 Admin and connect wizard

- **Connect wizard** (WHIT-697): recommends the single `/mcp` (search and call). Optionally, "Which areas can this app use?" compiles to `toolset:` scope entries. Narrow clients can pick a split instead (§5.12).
- **Admin MCP page** (`McpToolsAdminHandler`): shows each operation's toolset, visibility and agent policy, and the estimated list size per mode. Tenant admins enable or disable each plugin's requested always-on tool here.

### 5.11 Agents: scope grant, proposals, undo and actor

The source of truth is the agent model in `00-decision-register.md`: the Scope grant row, 7.5a Proposal, 7.5b Approvals inbox and 7.5c Undo. The server side is engineering handoff items 10 and 11 (`12-engineering-handoff.md`); `IMPLEMENTATION-NOTES.md` §1 notes that neither exists yet. MCP is how agents reach Whity, so this design defines how every MCP call obeys that model. Where the register was silent, the owner decided on 2026-10-31 (§10, decisions 8 to 12).

**Who is an agent.** An agent is an **AI principal**: a credential of principal kind `ai-agent` (ADR-0006 §3 names this kind, but nothing mints it yet). It is issued by a person, the *granting person*, and always acts for them. The owner's "agent flag on the token" is this principal kind. A person's own client (their Claude, their Cursor) holds an ordinary `user` or `oauth` token and acts as that person, under that person's RBAC, with no grant and no proposals. Whity ID, the OIDC service and ADR-0015's authorization server, marks an OAuth client registration as an agent client so that its tokens map to `ai-agent`. Built in WHIT-825: such a token carries `whity_agent: true` (exactly the boolean) and names its client (`client_id`, or `azp`). Core believes the claim only from the configured issuer, after the signature, issuer and audience checks; any other value, or none, leaves the token the person's own client (`oauth`). The token carries no grant entries, so the default grant applies (reads Allowed, writes Ask first). The agent's principal id is stable per client and person (`oauth-agent:` and a hash), so its proposals stay its own across token refreshes.

**Scope grant (register: Scope grant; handoff item 11).** For each `resource:action` permission, the grant says **Off**, **Ask first** or **Allowed**. It is stored with the credential's scope, and every change to it is logged.

| Mode | Search and list | `call_operation` / `tools/call` / REST |
|---|---|---|
| Off | Not found, not listed. | Refused, the same as a permission the token doesn't hold. |
| Ask first | Found, marked `mode: ask`. | **Not executed.** A proposal is stored and returned (below). |
| Allowed | Found, marked `mode: allowed`. | Runs, as "agent for person". |

- **Never more than the granting person.** On every call the effective permission is the granting person's RBAC in that tenant, intersected with the grant. If the person loses a permission, the agent loses it at once, whatever the grant says.
- **Operations gated by a role, or open,** have no permission to set a mode on. For agents, their writes are Ask first and their reads Allowed (decided).
- **Defaults for a new grant (decided):** every read permission the person holds starts Allowed. Every other permission starts Ask first. Nothing is Allowed for writes until the person says so.

**Route ceiling (`agentPolicy`).** A route or authored tool can cap what any grant allows. This is the mechanism the document designs already use:

| `agentPolicy` | Effect on agents |
|---|---|
| `allow` (default) | The scope grant decides. |
| `propose` | At most Ask first: always a proposal, even when the grant says Allowed. Used for issuing numbered documents, binding slots, and publishing or activating a plugin manifest. |
| `suggest-only` | Only the suggestion variant runs (register E.4: agents write documents as suggestions). |
| `refuse` | Never for agents. A person must do it. |

The effective mode is the stricter of the grant and the ceiling. People's own clients ignore `agentPolicy`.

**Proposals (register 7.5a; handoff item 10).**

1. An agent's call in Ask first mode stores an `agent_proposals` row (the table is planned in `IMPLEMENTATION-NOTES.md`). The row holds: the AI principal, the granting person, the tenant, the operationId, the arguments, a diff, the permissions it would use (scope), the undo window, and the status (waiting · approved · declined · expired · undone).
2. `call_operation` returns a normal tool result, not an error. It carries `proposal: {id, status: "waiting", summary, permissions, undoWindowSeconds}`, so the model can tell the person what is waiting. Before storing it, core checks that the granting person could make the call (token narrowing and RBAC): a person is never asked to approve what could not run (WHIT-825).
   - **Following up (WHIT-825).** `get_proposal {id}` is listed for agents only, and is read-only. It returns the status and, once approved, the result of the calling agent's own proposal: same workspace, same person, same agent. Any other id reads as not found.
3. **The diff.** An operation can declare a preview: a read-only dry run that returns what would change. Core write routes get one where it is cheap, such as updates (before → after of the touched fields). An operation without one stores the arguments, and the card shows them as "Show all".
4. **Who decides.** The granting person approves, edits or declines in the approvals inbox (7.5b). Only the granting person may approve, edit or decline (decided), because approving executes as them.
5. **Approval executes it.** At approval the server re-checks everything for the granting person in the proposal's own tenant: exposure, entitlement, token toolsets, the grant and RBAC. It then runs the operation with actor "agent for person", and the approver recorded as the decider. The tenant comes from the row, never from ambient state. If the arguments were edited, they are validated again.
6. **Expiry (decided).** A waiting proposal expires after 7 days, or earlier if the operation says so, and shows "Ask again" (register proposal states).
7. A proposal is never executed twice. Approval is idempotent on the proposal id.

**Undo (register 7.5c).**

- An operation may declare an undo window and an inverse with the `undo` key: `windowSeconds`, plus `operation` (the operationId that reverses it) or, for authored tools, `handler` (a callable that reverses it from the stored result). Example: send email, 30 minutes. The same key is used on route schemas, authored descriptors and Whity-Builder manifests.
- After an agent's Allowed call, or an approved proposal, the result carries `undo: {token, until}`. The activity item shows Undo until then. Undo runs the inverse with actor "person" (the one who clicked) and marks the proposal `undone`.
- **No undo means Ask first (decided).** An operation with no undo window is always Ask first for agents, even when the grant says Allowed. The register keeps confirmation for what can't be undone.

**Rollout (decided).** No `ai-agent` credentials are issued until proposals exist (WHIT-784). People's own clients keep connecting as themselves, so nothing can skip Ask first in the meantime.

**Actor on every write.** Every write records its actor:

- **person** — a person's own client or session;
- **agent for person** — an AI principal, either Allowed or by an approved proposal;
- **system** — jobs and automations.

Written to the four actor columns (`actor_kind`, `actor_profile_id`, `agent_principal_id`, `on_behalf_of_profile_id`) that `IMPLEMENTATION-NOTES.md` adds to `audit_log` and `domain_events`. `ToolsCallHandler` sets the actor on the request context before the route handler runs, so handlers that write `updated_by` columns read the same actor. This also closes the ADR-0006 §6 gap: MCP audit rows will finally record `principal_kind`.

**RBAC and tenant isolation on every call.** This holds today for derived operations, which are dispatched through the real route with the tenant from the token. It must hold in three new places too:
- `call_operation`, which hands off to the same path;
- proposal execution, which uses the row's tenant and the granting person's RBAC;
- splits.

Search never reveals an operation that RBAC or the grant would refuse.

**Search shows the mode.** For an agent, each match carries its effective `mode` (`allowed` or `ask`) and whether it can be undone. The model knows ahead of time what will become a proposal.

### 5.12 Splits (optional)

A split serves one slice at its own URL, from the same server, with the same auth, RBAC, audit and rate limits. It is for narrow clients and hard boundaries; the single `/mcp` is the recommended connection.

- **Mode:** each split also runs in search-and-call mode. It lists its toolsets' always-on tools plus `search_operations` and `call_operation`, all scoped to the split. `?tools=full` on a split gives that split's full list.
- **Hard boundary:** operations outside the split don't exist there. Search can't find them, and calling one is "no such operation".
- **Which splits.** Core areas: `documents`, `forms`, `convening`, `people`, `inbox`, `admin`, `billing`, `security`, `platform`. Every plugin's root toolset gets one, and a sub-toolset gets one with `split: true`. Operators can define composite splits with `MCP_SPLITS`, for example `sales=core.forms,acme.*`.
- **URLs:**
  - Each split is served at `POST /mcp/<split>`. `GET` answers 405.
  - Core names are reserved. A plugin whose key collides with one is served at `/mcp/x-<pluginKey>`.
  - `serverInfo.name` is `whity-core/<split>`.
  - An unknown split, or one whose toolsets all need an entitlement the tenant lacks, answers 404. A tenant-scoped manifest plugin's split exists only in its own tenant.
- **Auth:**
  - Each split is its own RFC 8707 resource (Whity ID mints tokens for it), with Protected Resource Metadata at `/.well-known/oauth-protected-resource/mcp/<split>`.
  - An OAuth token whose `aud` is the split works only there. A token for `/mcp` works on every split. `OAuthAccessTokenVerifier` currently accepts one resource and changes to accept `/mcp` or the split being called.
  - Whity-issued tokens use matching `toolset:` scope entries.
  - Rate limits stay per principal across splits, and the audit trail records the split.
- **Discovery:**
  - `GET /api/mcp/splits` (authenticated) lists what the caller can reach.
  - `search_operations` with an empty query shows each toolset's split URL.
  - Each split has a Server Card at `/mcp/<split>/server-card` (SEP-2127).
  - `/.well-known/ai-catalog.json` lists **core splits only**. Plugin splits are never in the public catalog.
- **Plugins** get a split by declaring toolsets (§5.7). They have no route or handler to write.

### 5.13 Core search (`GET /api/search`)

Decided 2026-10-31 (WHIT-783). One permission-filtered search across records. It serves the "search records" always-on MCP tool (`get_api_search`) and the ⌘K command palette (register 2.1c: "searches records, pages and actions").

- **What it searches:** documents (titles, numbers, sources), forms (titles; submissions the caller can see), people (users and OUs the caller may list), and plugin records from plugins that opt in.
- **Filtered per record:** each result is checked against the caller's permissions and tenant, the same as the record's own list route. Nothing appears that its list route would hide. For agents, the scope grant applies (a type set to Off isn't searched).
- **Arabic-aware:** text is normalised before matching. Alef forms (أ إ آ → ا), taa marbuta and haa (ة/ه), alef maqsura and yaa (ى/ي), diacritics and tatweel are folded, Arabic-Indic digits become Western, and Latin text is case-folded. Results show the record's own language label.
- **Plugins opt in** through their data-type declaration (below). This works for code plugins and Whity-Builder manifest plugins alike.
- **Shape:** `GET /api/search?q=&types=&limit=` returns `{type, id, title, subtitle, url, matched}` grouped by type, plus pages and actions for ⌘K. Pages and actions come from the app map, not the database.
- **Engine:** Whity runs on PostgreSQL and SQLite only. Each searchable type keeps a normalised search column (Arabic folded as above, Latin lower-cased), kept up to date on write, and matching is `LIKE` on that column on both engines. This follows the existing `ListQuery` pattern and needs no full-text extension. Core has no Arabic normaliser today; one is added in `src/Core/Text`, with matching PHP and TypeScript fixtures. A dedicated engine can come later behind the same interface.
- **Plugin records:** plugins opt in by adding a `search` key to their data-type declaration (`PluginDataTypesInterface`): the title column, optional subtitle columns, and the read permission the declaration already carries. Core then searches the plugin's own table through the registry, scoped by its declared tenant column.

## 6. Back-compat and release notes

| Who | What changes in the next release |
|---|---|
| Client on bare `/mcp` | **Changes.** It gets about 7 tools (5 always-on, search and call) instead of the full list. Operations are reached through `search_operations` and `call_operation`. Calling an operation by its old name with `tools/call` still works. Legacy clients get `list_changed`. |
| Client that needs the full list | Use `/mcp?tools=full`. Same names, same behaviour as today. |
| Client on `/mcp?tools=discover` | Gets `search_operations` and `call_operation`. The old three names stay callable, unlisted, for one minor release. |
| Existing tokens | No `toolset:` entries means all toolsets; unchanged. Existing tokens are `user`, `session` or `oauth`, so they act as their person. Only newly minted `ai-agent` credentials follow the scope grant. |
| Plugins on SDK ≤ 1.46 | Their tools are `discoverable` in toolset `<pluginKey>`, reached by search, and listed with `?tools=full`. Unprefixed authored names get a deprecation warning. |
| `openapi.json`, typed clients | No change. |

**Release notes callout (required by the owner):** "MCP: `/mcp` now lists a handful of everyday tools plus `search_operations` and `call_operation`, instead of every operation. Your client finds and calls everything it could before. To keep the old full list, use `/mcp?tools=full`."

## 7. Alternatives considered

- **Keep the full list as the default and add a small opt-in mode.** This was the first draft of this design. The owner chose search and call as the default, which keeps the context the same size whatever the plugin count.
- **Code Mode (`search` + `execute` running model-written code).** Not now (owner decision). Running a model's program needs a sandbox, limits and a way to audit each call inside it. `call_operation` calls one operation at a time and audits each one.
- **`enable_toolset` / `disable_toolset` tools.** Rejected: 2026-07-28 says the list MUST NOT vary as a side effect of other requests, and we have no connection state.
- **Pagination as a size control.** Rejected: clients fetch every page.
- **A separate MCP server process per area or plugin.** Rejected: RBAC, audit and rate limits would be duplicated or bypassed. Splits give separate endpoints from one server, and a proxy can still move a split to its own deployment later.
- **Renaming derived operations.** Rejected: the names are published OpenAPI operationIds.
- **Toolsets as a permission concept.** Rejected: RBAC already decides who may do what. Toolsets only narrow tokens.
- **Dots in tool names.** Rejected: some model APIs refuse them.

## 8. Later, not in this design

- Sandboxed code execution (Code Mode) once search and call has usage data.
- Per-toolset guidance through the Skills extension (SEP-2640), as `skill://whity/<toolset>/SKILL.md`.
- Toolsets for prompts and resources.
- `outputSchema` on derived operations, blocked on WHIT-120.
- Tenant-level always-on choices for core tools, informed by the `mcp.tools.call` audit trail.

## 9. Coordination with the other designs

**Document themes and automation** (`DOCUMENT-THEMES.md`, `DOCUMENT-AUTOMATION.md`): what changes for them:
- Visibility values are now `always-on` · `discoverable` · `off`. `default` became `always-on`. Their tools stay `discoverable`, so they need no change beyond the word if they use `default` anywhere.
- Toolset ids are `core.documents.themes` and `core.documents.automation`.
- Agents now reach their tools through `search_operations` and `call_operation`, not as listed tools. Their descriptions and `agentPolicy` show in search results.
- Agents now follow the register's per-permission scope grant (§5.11): Off, Ask first or Allowed per permission, with real server-side proposals. Their `propose` and `suggest-only` declarations become ceilings on top of the grant. They are still needed, because a person could otherwise set issuing to Allowed. `allow` means the grant decides.
- Unchanged: `agentPolicy` values and route-level enforcement, and document plugins contributing data rather than tools.

**Whity-Builder** (`WHITY-BUILDER.md`): what changes for it:
- Builder-made plugins' operations are reached through search and call, not listed.
- A builder plugin may have one always-on tool, only read-only and only after a tenant admin enables it.
- The builder toolset (`whity-builder`, gated by `requiresEntitlement`) is searched like any other. Its split `/mcp/whity-builder` remains optional and also runs in search-and-call mode.
- Builder plugin splits never appear in the public AI catalog.
- `core.plugins` is unchanged.

## 10. Owner decisions (2026-10-31)

Decided:

1. **Shape:** one server in search-and-call mode, with `search_operations` and `call_operation`. No code-execution sandbox.
2. **Switch-over:** bare `/mcp` becomes search and call in the next release. `?tools=full` keeps the old list, and the release notes call it out.
3. **Token toolsets:** enforced on calls.
4. **Always-on tools:** about five, read-only, for everyday requests (about +1k tokens). All writes go through search and call. This replaces the earlier 15-tool default list.
5. **Plugins:** at most one always-on tool, shown only after an admin enables it. Descriptions are capped at 500 characters. Everything else is reached through search.
6. **Agents:** an `agent` flag on the token, refined the same day. Agents follow the register's model: a per-permission scope grant (Off, Ask first or Allowed) for an AI principal acting for its granting person. Ask first stores a real proposal that approval executes for that person. Every write records its actor. RBAC and tenant isolation apply on every MCP call (§5.11).
7. **Splits:** optional, for narrow clients and hard boundaries. Each also runs search and call. The core areas stay as listed. Plugin splits are not in the public AI catalog. The wizard recommends the single `/mcp`.
8. **Search records:** build `GET /api/search` across documents, forms, people and plugin records. It is permission-filtered and Arabic-aware, plugins opt in, and it serves both the always-on tool and the ⌘K palette (§5.13, WHIT-783).
9. **Grant defaults:** every read the person holds starts Allowed; every other permission starts Ask first. Writes gated only by a role, or not gated at all, are Ask first for agents.
10. **Proposals:** expire after 7 days. Only the granting person can approve, edit or decline.
11. **No undo window:** always Ask first for agents, even if the grant says Allowed.
12. **Rollout:** no `ai-agent` credentials until proposals exist. People's own clients still connect as themselves.
13. **Old names:** `search_tools`, `describe_tool` and `call_tool` stay as unlisted aliases for one minor release, then are removed. The release notes say so.

Nothing is left open. Implementation starts when the owner says to build.

## 11. Task breakdown

Taskaat, project WHIT, section "Plugins, SDK & API" (WHIT-755 is in "Docs & Ops", WHIT-784 in "Auth, RBAC & Security"). The design is decided; implementation starts when the owner says to build.

| Task | What | Depends on |
|---|---|---|
| WHIT-746 umbrella | Owner review; decisions recorded in §10 | none |
| WHIT-747 toolset model | `CoreToolsets.php` (map, titles, five always-on picks), toolset, visibility and agent policy on derived operations, `schema['mcp']` array form, `expose: false`, `requiresEntitlement`, `_meta`, stable order, `CatalogSignature` (per tenant for manifest plugins) | WHIT-746 |
| WHIT-748 list modes | `ToolSurfaceRequest`; bare `/mcp` = always-on plus search and call; `?tools=full`, `?tools=discover`, `?toolsets=`; runtime list cap; tenant-admin enabling of plugin always-on tools | WHIT-747 |
| WHIT-749 token toolsets | `toolset:` scope entries, enforced in list, search and call; ADR-0006 amendment | WHIT-747 |
| WHIT-750 search and call | `search_operations` (query, toolset, operationId describe, table of contents, Arabic), `call_operation`, old names as unlisted aliases, `McpSearchEvalTest` | WHIT-748, WHIT-749 |
| WHIT-751 budgets in CI | `McpToolBudgetTest`: names, descriptions, summaries, schema size, always-on and search-and-call sizes | WHIT-747 |
| WHIT-752 SDK (next free minor at merge) | descriptor `toolset` / `visibility` / `agentPolicy`, `PluginMcpToolsetsInterface`, name prefix, one always-on read-only tool, loader enforcement, docs, conformance checks | WHIT-747 |
| WHIT-753 AI principals and scope grant | `ai-agent` principal (Whity tokens and Whity ID agent clients), per-permission Off / Ask first / Allowed with the decided defaults (handoff item 11), never above the granting person, `agentPolicy` ceilings at the route, mode in search, actor columns and actor context on every write. No `ai-agent` credentials issued until WHIT-784 ships. | WHIT-746 |
| WHIT-784 agent proposals and undo | `agent_proposals` (handoff item 10): store on Ask first, preview diffs, approve, edit or decline (approval executes for the granting person in the row's tenant), expiry, idempotent approval; undo windows and inverses (7.5c) | WHIT-753 |
| WHIT-754 admin and wizard | admin MCP page (toolset, visibility, policy, sizes, enabling plugin always-on tools); wizard recommends `/mcp`, optional toolsets and splits | WHIT-748, WHIT-749 |
| WHIT-755 docs and release notes | MCP-Server and MCP-Client-Connection wiki pages, runbook, the changelog and release-notes callout for the switch | WHIT-747 to WHIT-752 |
| WHIT-765 splits | `/mcp/{split}` in search-and-call mode, split registry, hard boundary, per-split OAuth resource, `GET /api/mcp/splits`, Server Cards, core-only AI catalog | WHIT-748, WHIT-749, WHIT-750 |
| WHIT-783 core search | `GET /api/search` (§5.13): documents, forms, people, opted-in plugin records; per-record permission filter; Arabic normalisation; SDK opt-in; serves `get_api_search` and ⌘K | WHIT-752 (plugin opt-in) |
