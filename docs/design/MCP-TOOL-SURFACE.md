# MCP tool surface: toolsets, visibility, splits and budgets

Status: proposal for owner review (2026-10-31). Nothing here is built yet.
Related: ADR-0006 and its amendments (MCP server), ADR-0015 (OAuth), `docs/design/DOCUMENT-THEMES.md` §6 and §7, `docs/design/DOCUMENT-AUTOMATION.md` §3.10 (both on branch `design/document-themes`).

The owner's problem: "too many mcp tools in one create a disaster... and not all users have or get all the tools permissions yet get them in the context anyway in addition plugins add more and more tools... we need an sdk way to manage this correctly".

## 1. Summary

- **Access is already right; the size is not.** `tools/list` already shows a caller only the tools it may call (exposure, token scope, RBAC; §2.2). What is missing is any way to keep the list small. An admin token sees 313 core tools, about 150 KB of JSON, and every plugin adds more.
- **Toolsets.** Every tool belongs to exactly one toolset. Core toolsets are named `core.<area>` (`core.documents`, `core.forms`); a plugin's are `<pluginKey>` or `<pluginKey>.<sub>`. A toolset is a unit of selection and context, never of access.
- **Visibility.** Each tool has a visibility: `default` (in the small starting list), `discoverable` (reached through search, or by selecting its toolset) or `off` (not on MCP at all). Core curates about 15 `default` tools. Plugin tools are `discoverable` unless the plugin asks otherwise, and at most 3 per plugin can be `default`.
- **List modes, chosen per request.** `/mcp?tools=full` (today's behaviour), `?tools=default` (default-visibility tools plus `search_tools`, `describe_tool`, `call_tool`), `?tools=discover` (only those three, as today). `?toolsets=core.documents,acme` narrows any mode. Nothing is stateful: the MCP 2026-07-28 spec forbids a tool list that changes as a side effect of earlier calls, so there is no `enable_toolset` tool.
- **Per token.** A token's scope may carry `toolset:<id>` entries (and `toolset:<prefix>.*`). They narrow what the token reaches, in the list and in `tools/call`, the same way permission entries do today. A token with none keeps every toolset, so existing tokens behave as before.
- **Budgets with lints.** Tool names stay within `[A-Za-z0-9_-]{1,64}`. Authored descriptions are capped at 500 characters. The default list is capped at 30 tools and about 6k tokens. A CI test holds core to its budget, and the plugin loader enforces the per-plugin limits at load.
- **SDK 1.47, additive** (the next SDK minor; the document designs also add SDK interfaces, so the number goes to whichever ships first). Authored tool descriptors gain `toolset`, `visibility` and `agentPolicy`. A new optional `PluginMcpToolsetsInterface` declares a plugin's toolsets (title and one-line description). Derived routes opt in or out through the existing `schema['mcp']` key, which now also takes an array.
- **Agent policy.** Routes and authored tools can declare `agentPolicy` (`allow`, `refuse`, `suggest-only` or `propose`), enforced at the route so REST and MCP agree. The document designs already rely on it. It needs a way to tell an agent token from a person's own client, which Whity doesn't have yet (§5.12).
- **Splits ("MCP splitter").** `/mcp/<split>` serves one slice (a core area or a plugin) as its own MCP server, with its own URL, OAuth resource and Server Card and a hard boundary (tools outside the slice don't exist there), all from the same Whity server and RBAC. A plugin's root toolset gets a split automatically. Splits sit alongside in-server discovery and don't replace it (§5.13).
- **Back-compat.** Existing tool names don't change. `/mcp` with no query keeps returning the full permitted list until the owner decides to flip the default (§10, Q1). `?tools=discover` keeps working, and so do tokens without toolset entries and descriptors without the new keys.

## 2. What exists today

### 2.1 Where tools come from

| Source | Code | Notes |
|---|---|---|
| Derived from routes | `src/Mcp/Tools/ToolDeriver.php` | One tool per route with a `schema`: core routes from `CoreApiSchemas::routes()` plus plugin routes read lazily from the `Router`. Name = `operationId` or `<method>_<path>` (no core route sets `operationId`, so every core name is path-derived: `get_api_users_id`). Annotations come from the HTTP method (`ToolAnnotations`, WHIT-706). Cached per worker in statics; `clearCache()` on plugin reload. |
| Exposure rule | `src/Mcp/McpExposure.php` (ADR-0006 §4, WHIT-695) | A derived tool is offered only when its route is gated (`requiredPermission` or `requiredRole`) or its schema says `'mcp' => true`. This keeps login, 2FA and webhook routes off the list. |
| Authored by plugins | `sdk/src/PluginMcpToolsInterface.php` (SDK 1.43), `src/Mcp/Tools/AuthoredToolRegistry.php` | Descriptor: `name`, `description`, `inputSchema`, `handler`, plus `requiredPermission` / `requiredRole` / `open: true` (fail closed), `annotations` (1.45). The handler gets `McpToolContext` (tenant, profile; 1.46). Derived names win collisions. `suppressesDerivedMcpTools()` drops the plugin's own derived routes. |
| Discovery mode | `src/Mcp/Tools/Discovery/DiscoveryTools.php` (WHIT-707) | `/mcp?tools=discover` lists three tools: `search_tools`, `describe_tool` and `call_tool`. Search and describe read the same filtered list as `tools/list`; `call_tool` goes through the real `ToolsCallHandler`. A real tool called by its own name still works. |

Plugins have no per-tenant switch today. A loaded plugin's routes, and so its tools, exist for every tenant; permissions alone decide who sees them. That changes with tenant-scoped manifest plugins from the Whity-Builder design (`WHITY-BUILDER.md`, WHIT-760): their derived tools join `tools/list` and `tools/call` per request, for one tenant only. This design therefore treats the catalogue as per tenant wherever it matters (§5.6, §5.10, §5.13).

### 2.2 How access applies, list versus call

`ToolsListHandler::listFor()` and `ToolsCallHandler` apply the same chain, in this order:

1. **Method capability** (`McpScope::allowsMethod`, Dispatcher): the token needs `tools:call` for `tools/list` and `tools/call`.
2. **Exposure** (`ToolDeriver::isExposed`).
3. **Token permission narrowing** (`McpScope::allowsPermission`): if the token names permissions, only tools gated by one of them. Ungated and role-only tools drop out.
4. **RBAC** (`RoleChecker::hasPermissionForProfile` / `hasRoleForProfile`) against the tenant on the request.

Open tools (no gate) are listed for any authenticated caller. Unauthenticated `tools/list` is refused by the Dispatcher. The connect wizard (`McpConnectHandler::preview`, WHIT-697) previews a scope by calling the same `listFor()`.

So the first half of the owner's concern, "users get tools they have no permission for in their context", **does not happen for `tools/list` today**. What does happen is that a broad role (an admin) or an unnarrowed token sees everything it is allowed to call, which is most of the catalogue, and plugins grow that with no limit and no grouping.

One gap remains: `GET /api/v1/admin/mcp/tools` (`McpToolsAdminHandler`) lists the unfiltered catalogue on purpose, for planning. That is fine, since it is admin-only and not MCP.

### 2.3 Measured size (develop at 0b3e2d4e)

Measured by running `ToolDeriver` over `CoreApiSchemas` (the script is in the session notes):

| | Count |
|---|---|
| Core routes with a schema (derived tools) | 363 |
| Exposed to MCP | **313** |
| of which open (no gate) | 20 |
| of which read-only | 131 |
| `tools/list` JSON for a caller who may use all of them | 149,610 bytes, roughly 25k to 37k tokens depending on tokenizer (ADR-0006 amendment 2 quotes about 22k) |
| Median tool definition | 400 bytes (largest 1,923) |
| Longest tool name | 55 characters; none uses a dot |

By permission resource, the biggest areas are documents 39, convening 25, plans 24, forms 20, admin-role-only 18, ous 15, settings 14 and tags 14. The OpenAPI tags give a similar split, with 40 tags.

**With plugins.** Bundled in this repo: HelloWorld (6 schema routes), DemoCatalog (5) and UiKitShowcase (11), none with authored tools. In `whity-org/whity-plugins`: theme-creator (5 routes) and plugin-store (8). The paid services (Whity-Auth, Almolakhas Pay) were not reachable from here, so they are not counted. A typical install with the store, a theme plugin and two paid services would be about 330 to 360 tools. The trend matters more than the number: every plugin route with a schema and a gate becomes a tool, and nothing groups it.

### 2.4 Change notification and caching

- Legacy clients (2025-03-26 to 2025-11-25): `listChanged: true`. Notifications ride on POST responses as SSE frames (`ListChangedNotifier`, #952), and the signature covers the unfiltered catalogue (`CatalogSignature`).
- 2026-07-28 clients: no `subscriptions/listen` (it would pin a FrankenPHP worker). List results carry `ttlMs: 60000`, `cacheScope: private` (`McpProtocol`).
- A caller whose grants change sees a different list without any notification. That is acceptable with a 60-second TTL and is out of scope here.

## 3. What the MCP spec gives us, and what it doesn't

Checked against the 2026-07-28 specification and the SEP index on 2026-10-31.

**Standard, and we should use it:**

- `tools/list` "MAY vary by the authorization presented on the request", so per-token filtering is explicitly allowed. It "MUST NOT vary per-connection or as a side effect of other requests on the connection" (2026-07-28, Tools). This rules out a stateful `enable_toolset` tool for modern clients. The server is stateless anyway.
- Deterministic order is a SHOULD, for client caching and prompt-cache hits. Today's order is declaration order, which is stable. Keep it stable on purpose (§5.9).
- Tool names (SEP-986): 1 to 128 characters from `[A-Za-z0-9_.-]`, unique per server. Dots are allowed (`admin.tools.list`).
- `_meta` on a tool carries implementation data under a reverse-DNS prefix. `io.modelcontextprotocol/` is reserved.
- `ttlMs` / `cacheScope` on list results (SEP-2549), and pagination with `cursor`.
- Extensions (SEP-2133), and the Skills extension (SEP-2640): workflow instructions served as `skill://` resources with progressive disclosure. This is a later fit for per-toolset "how to use these tools" guidance (§8).

**Not standard. Nothing in the spec or the SEP index covers these:**

- Tool groups, toolsets, tags or namespaces. There is no field and no method.
- Server-side tool search or lazy loading. Some hosts defer MCP tool definitions and search them on the client side, but that is host behaviour, not protocol, and many clients load every tool up front.
- A per-client selection mechanism.

So toolsets, visibility levels and search are ours. We expose them through standard surfaces only: URL query parameters on our endpoint, OAuth scope strings, ordinary tools (`search_tools`) and a `_meta` key. We don't add protocol methods.

**Name constraint stricter than the spec.** Several model APIs accept only `[a-zA-Z0-9_-]` in function names, and some cap them at 64 characters. A tool name with a dot can be rejected by the client that turns MCP tools into model functions. So **tool names keep to `[A-Za-z0-9_-]{1,64}`** and namespacing uses an underscore prefix. Toolset ids, which never reach a model API as a function name, may use dots.

## 4. Design goals

1. A caller's context holds only tools it can call. This is true today and must stay true.
2. A new connection starts with a small, stable list, whatever the plugin count.
3. Everything the caller may call is still reachable, without reconnecting.
4. Selection (which toolsets) is per request and per token, never per connection state.
5. Toolsets and visibility levels change presentation. Only token scope and RBAC change access. A toolset never grants anything.
6. Plugin authors declare tools with permission, toolset and visibility in one place, and the host enforces the budgets.
7. Nothing an existing client relies on breaks.

## 5. Design

### 5.1 Toolsets and namespacing

A toolset has:

| Field | Rule |
|---|---|
| `id` | Core: `core.<area>` or `core.<area>.<sub>` (for example `core.documents.themes`), each segment in `[a-z0-9_-]`. Plugin: `<pluginKey>` or `<pluginKey>.<sub>`, where `<pluginKey>` is the plugin's loader key lower-cased with non-`[a-z0-9_-]` replaced by `-`. The first segment `core` is reserved. |
| `title` | English, at most 40 characters, with an Arabic label (register rule: every visible string). |
| `description` | One sentence, at most 160 characters. It is shown by `search_tools` and in the connect wizard. |
| `owner` | `core` or the plugin key. A plugin can only declare toolsets under its own key. |
| `requiresEntitlement` | Optional `EntitlementRegistry` key. Tenants without it don't see the toolset's tools, in any mode or split (§5.13). For paid slices such as Whity-Builder. |
| `split` | Optional, plugins: whether a sub-toolset gets its own endpoint (§5.13). A root toolset always does. |

**Core toolsets** are a curated map in a new `src/Mcp/Tools/CoreToolsets.php`, about 15 entries, merging the 40 OpenAPI tags into areas a person would name: `core.me`, `core.people` (users, roles, ous, groups, memberships), `core.documents` (documents, templates, blocks, collections, routes), `core.forms`, `core.convening`, `core.inbox` (inbox and notifications), `core.taxonomy` (tags, data types), `core.time`, `core.settings` (settings, branding, languages, translations), `core.billing` (plans, subscriptions, entitlements, licensing), `core.security` (auth providers, SSO, security, 2FA recovery, audit), `core.plugins` (plugin management and the manifest-version routes from the Whity-Builder design, WHIT-764), `core.platform` (platform-ops, tenants, instance). A derived core tool takes its toolset from its route's first tag through that map. `RouteCatalogueCompletenessTest` gains a check that every exposed core route maps to a toolset.

**Plugin toolsets.** A plugin's derived tools default to toolset `<pluginKey>`. Authored tools name one explicitly or get the same default.

**Tool names.** Existing derived names don't change: core names are published in `openapi.json`, `schema.d.ts` and the typed clients, and renaming them breaks every saved client prompt. New **authored** plugin tools must start with `<pluginSlug>_`, where `<pluginSlug>` is the plugin key with `-` replaced by `_` (for example `theme_creator_suggest_palette`). Authored names that predate this rule are accepted with a deprecation warning until SDK 2.0. A derived plugin tool keeps its path-derived name, which the Router already keeps unique.

### 5.2 Visibility

| Visibility | In `tools=full` | In `tools=default` | Found by `search_tools` | Callable |
|---|---|---|---|---|
| `default` | yes | yes | yes | yes |
| `discoverable` | yes | only if its toolset is selected with `?toolsets=` | yes | yes |
| `off` | no | no | no | no (not exposed) |

- `off` is the existing exposure rule plus an explicit opt-out: the same as an ungated route today.
- Core default visibility: about 15 read-mostly, high-frequency tools, listed in `CoreToolsets.php` and reviewed by the owner (Q3). Proposed: `get_api_me`, `get_api_me_capabilities`, `get_api_me_inbox_sources`, `get_api_me_inbox`, `get_api_me_notifications`, `get_api_users`, `get_api_users_id`, `get_api_ous`, `get_api_documents`, `get_api_documents_id`, `get_api_document_templates`, `get_api_forms`, `get_api_forms_id`, `get_api_meetings`, `get_api_tags`.
- Plugin tools default to `discoverable`. A plugin may mark up to **3** tools `default`. Extras are demoted to `discoverable` at load with a logged warning, and the admin MCP page shows the demotion.
- Visibility never decides access. A `default` tool the caller can't use is still filtered out by §2.2.

### 5.3 List modes and selection

Selection is read from the request every time. Nothing is remembered between requests, so this works the same for legacy and 2026 clients and on any of the eight workers.

| Query | `tools/list` returns | Notes |
|---|---|---|
| (none) | the same as `tools=full` in phase 1 | Back-compat default. Flipping it to `default` is Q1. |
| `tools=full` | every tool the caller may call | Today's behaviour. |
| `tools=default` | default-visibility tools, plus discoverable tools of toolsets named in `?toolsets=`, plus the three discovery tools | Recommended for new connections; the connect wizard issues this URL. |
| `tools=discover` | the three discovery tools | Unchanged (WHIT-707). |
| `toolsets=a,b.*` | combines with any mode: limits the list and `search_tools` to those toolsets | Presentation only, so a tool outside them can still be called by name. |

Unknown values fall back to `full` (today's rule for an unknown `tools=`). An unknown toolset id is ignored. The transport already chooses a dispatcher per request (`McpTransportHandler::dispatcherFor`). This generalises it into a `ToolSurfaceRequest` value object (mode, toolsets) that the transport builds and passes down, so no mode lives in worker state.

### 5.4 Token-level toolsets (scope)

`McpScope` gains a third kind of entry, alongside capabilities and permissions:

- `toolset:<id>`, or `toolset:<prefix>.*` (for example `toolset:core.*`, `toolset:acme.*`).
- If a token names any toolset entry, it reaches only tools in those toolsets: in `tools/list`, in `search_tools` and `describe_tool`, **and in `tools/call`**. This is authorization presented on the request, which the spec allows the list to vary by.
- A token that names none keeps every toolset, so existing tokens are unchanged.
- `toolset:` becomes a reserved prefix, like `tools:` (no permission may live under it). `issuanceErrors()` rejects unknown toolset ids.
- OAuth clients (ADR-0015) request it in the ordinary `scope` string, so no custom claim is needed.

Access order becomes: capability, then exposure, then **toolset narrowing**, then permission narrowing, then RBAC. Then presentation: mode, visibility, `?toolsets=` and budget. The first five apply to both list and call; the presentation steps only to the list.

### 5.5 Discovery tools, extended

Same three tools and names. Changes:

- `search_tools` gains an optional `toolset` argument. With an empty query, it returns the toolsets the caller can reach (id, title, description, tool count), not the first page of tools. That is the "table of contents" a model needs before searching.
- Each match carries its `toolset`.
- Results respect token toolsets and `?toolsets=` (§5.3, §5.4).
- In `tools=default` mode the three tools sit at the end of the list, and their descriptions say that more tools exist and how to find them.

No `enable_toolset` / `disable_toolset` tool (rejected, §7).

### 5.6 Budgets and lints

| Budget | Limit | Enforced where |
|---|---|---|
| Tool name | `[A-Za-z0-9_-]{1,64}` | Authored: refused at registration. Derived: CI test (all 313 pass today; longest is 55). |
| Description | ≤ 500 characters | Authored: refused over 500. Derived core: CI lint (none exceeds 500 today). Derived plugin: warning at load. |
| `inputSchema` | ≤ 8 KB encoded | Warning at load; CI for core. |
| Default-visibility tools per plugin | 3 | Loader demotes extras to `discoverable` and warns. |
| `tools=default` list | ≤ 30 tools and ≤ 24 KB encoded (about 6k tokens) | Core alone: CI test (`McpToolBudgetTest`, core default ≤ 20 tools). At runtime: if plugins push past the cap, plugin default-visibility tools are dropped from the list (they are still searchable), in plugin-key order, with one warning per worker. |
| Toolset description | ≤ 160 characters | Refused at declaration. |
| `tools=full` | no cap | Back-compat. The admin MCP page shows the estimated size per role. |

All runtime budgets are evaluated per request, for the caller's tenant, so a tenant-scoped manifest plugin counts against its own tenant's default list and nobody else's.

Operators can change the two list caps through config (`MCP_DEFAULT_LIST_MAX_TOOLS`, `MCP_DEFAULT_LIST_MAX_BYTES`). Plugins can't.

### 5.7 Plugin SDK (1.47, additive)

**Authored tool descriptor, new optional keys** (`PluginMcpToolsInterface`, unchanged interface):

```php
[
    'name'               => 'theme_creator_suggest_palette', // must start with the plugin slug
    'description'        => 'Suggest an accent colour pair for a tenant theme. Reads only.',
    'inputSchema'        => [...],
    'handler'            => fn (array $args, McpToolContext $ctx) => ...,
    'requiredPermission' => 'themes:read',   // or requiredRole, or open: true (fail closed, as today)
    'annotations'        => ['readOnlyHint' => true],
    'toolset'            => 'theme-creator.palettes', // NEW: under the plugin's own key; default '<pluginKey>'
    'visibility'         => 'discoverable',           // NEW: 'default' | 'discoverable'; default 'discoverable'
    'agentPolicy'        => 'allow',                  // NEW (§5.12): 'allow' | 'refuse' | 'suggest-only' | 'propose'
]
```

A `toolset` outside the plugin's own key is refused, and so is a `visibility` other than the two values. There is no `off` for authored tools: not registering the tool is how a plugin leaves it out.

**New optional interface** `Whity\Sdk\PluginMcpToolsetsInterface`:

```php
interface PluginMcpToolsetsInterface
{
    /** @return list<array{id: string, title: string, titleAr?: string, description: string, split?: bool, requiresEntitlement?: string}> */
    public function getMcpToolsets(): array;
}
```

`id` is relative (`'palettes'`) or empty for the plugin's root toolset. The host prefixes the plugin key, so a plugin can't declare a toolset under someone else's key. A toolset used by a tool but never declared gets a generated title (the plugin's name) and a warning. A declared toolset with no tools is dropped silently.

**Derived plugin routes** use the route schema (§5.8).

**Conformance.** The SDK conformance kit (WHIT-272) gains checks for name prefix, budgets, toolset ownership and visibility values, so a plugin author finds out at their desk rather than in a production log.

**No typed builder in 1.47.** The theme design proposes a typed builder for theme tokens. For tools, the array descriptor already exists and validation lives in the host, so a builder (`Whity\Sdk\Mcp\Tool::named(...)`) can come later without breaking arrays.

### 5.8 Derived tools: opt in and opt out

`schema['mcp']` already accepts `true` (expose an ungated route). It now also accepts an array:

```php
'schema' => [
    'summary' => 'List the tenant\'s forms',
    'tags'    => ['forms'],
    'mcp'     => [
        'expose'     => true,           // optional; true = old 'mcp' => true; false = visibility 'off' even when gated
        'visibility' => 'default',      // optional; core default 'discoverable'
        'toolset'    => 'core.forms',   // optional; core default from the tag map; plugin default '<pluginKey>'
    ],
],
```

- `'mcp' => true` keeps meaning `['expose' => true]`.
- `'expose' => false` is new. It takes a gated route off MCP, for example bulk-import or binary-upload routes that a model can't use well. `McpExposure::exposes()` checks it first.
- Core's default-visibility picks are set in `CoreToolsets.php`, not scattered through `CoreApiSchemas`, so the owner reviews one list. Plugins set `visibility` on the route.
- `suppressesDerivedMcpTools()` stays. It is the whole-plugin switch; `expose: false` is per route.

### 5.9 Wire format

Each tool object gains `_meta`:

```json
"_meta": { "dev.whity/toolset": "core.forms", "dev.whity/visibility": "default" }
```

Clients that don't know the keys ignore them, as the spec requires. The admin page and the connect wizard read them. Order: the `default` visibility first, in `CoreToolsets.php` order, then everything else by toolset id and tool name, with the discovery tools last. That order is stable across requests while the catalogue doesn't change, which helps client caches and prompt caching.

### 5.10 Change notification and caching

- No new notifications. Mode and toolsets are per request, so changing them changes the URL or token, not a cached list.
- `CatalogSignature` keeps hashing the unfiltered catalogue, now including each tool's toolset and visibility, so moving a tool between visibility levels announces a change to legacy clients.
- Tenant-scoped manifest plugins (WHIT-760) make the catalogue differ per tenant. The signature becomes the instance catalogue's signature plus a hash of that tenant's manifest-plugin tools, so publishing a manifest in one tenant notifies only that tenant's clients.
- **A side benefit:** a client in `tools=default` mode barely changes when a plugin is installed, because new plugin tools are `discoverable`. Its prompt cache stays warm.
- `ttlMs` stays at 60 seconds.

### 5.11 Admin, connect wizard and tenants

- **Connect wizard** (WHIT-697): adds "Which areas can this app use?" as toolset checkboxes (core areas and each plugin), compiled to `toolset:` scope entries next to the existing permission presets. It issues the URL with `?tools=default`. The preview shows the default list and the searchable count.
- **Admin MCP tools page** (`McpToolsAdminHandler`): adds toolset, visibility, demotions and an estimated list size per mode.
- **Tenant override (later, phase 3):** a tenant setting `mcp.default_toolsets` that promotes whole toolsets into the default list for that tenant, for example an Almolakhas-Pay-heavy tenant. It is presentation only and stays within the same caps.

### 5.12 Agent policy (shared with the document designs)

Register rule: agents never change anything directly. What they do is a suggestion or a proposal a person accepts. Today each handler would have to enforce that itself. Instead, a route schema or an authored tool descriptor declares it:

| `agentPolicy` | Meaning for an agent principal | People's own clients |
|---|---|---|
| `allow` (default for read-only routes) | Runs. | Runs. |
| `refuse` | Refused with a tool error that says a person must do it. | Runs. |
| `suggest-only` | Only the route's suggestion variant runs (a patch a person accepts in the editor). | Runs. |
| `propose` | Creates an `agent_proposals` row a person accepts. Until that table exists it behaves as `refuse`. | Runs. |

- It is enforced at the route (middleware), not only in `tools/call`. An agent calling REST directly gets the same answer as over MCP.
- `tools/list` keeps showing these tools to agents, with `_meta` `dev.whity/agentPolicy`, and their descriptions say what happens. Hiding them would leave a model unable to explain why it can't do something.
- Default for write routes without a declaration: `allow` in phase 1, so nothing changes. The owner decides whether writes default to `propose` once proposals exist (Q5).
- **Prerequisite: Whity has no "agent principal" today.** MCP principals are `user` (an MCP token) or `session`. A token held by a person's own Claude or Cursor and one held by an unattended agent look the same. `agentPolicy` needs that distinction first (Q5). This design proposes an `agent` flag on the token, set by the issuing screen and by OAuth client registration, shown in the audit trail.

### 5.13 MCP splits: one endpoint per slice (owner addition: "we also want mcp splitter")

Read here as: split Whity's single MCP server into several MCP endpoints, so a client connects only to the slice it needs, and each slice carries its own permission-filtered tool list. Other readings are in Q6.

**What a split is.** A split is a named, published selection of toolsets, served at its own URL by the same server: the same process, Dispatcher, auth, RBAC, audit and rate limits. To a client it is a separate MCP server, with its own URL, `serverInfo` and OAuth resource. To Whity it is a fixed `toolsets` selection plus one hard rule: **tools outside the split don't exist on that endpoint.** `tools/call` for them answers "unknown tool", as if the client had connected to a different server. That is the difference from `?toolsets=`, which only changes what is listed.

**How a split is defined.**

| Source | Splits | Rule |
|---|---|---|
| Core | A curated list in `CoreToolsets.php`, initially one per core area that is useful on its own: `documents`, `forms`, `convening`, `people` (users, roles, OUs, groups), `inbox`, `admin` (settings, branding, languages), `billing`, `security` (auth providers, SSO, audit), `platform` (platform operators only) | Each names one or more core toolsets. Owner reviews the list (Q7). |
| Plugin | The plugin's root toolset always gets a split. A sub-toolset gets one only if it declares `split: true` (§5.7). | Plugins can only split their own toolsets. |
| Operator | `MCP_SPLITS` config, for example `sales=core.forms,acme.*` | Composite splits for one deployment. No tenant-level splits in phase 1. |

A split's tools are its toolsets' tools, filtered by exposure, the token (scope and audience) and RBAC, exactly as in §2.2. Inside a split the default mode is `full`, because a split is meant to be small, but `?tools=default` and `?tools=discover` still work. A split over 40 tools or 10k tokens gets a CI warning (core) or a load warning (plugin). `core.documents` (39 tools now, more with themes and automation) is the first that may need a sub-split (`documents-templates`).

**URL scheme.**

- `POST /mcp/<split>`, where `<split>` is `[a-z0-9-]+` for core and `<pluginKey>` or `<pluginKey>-<sub>` for plugins. Core split names and plugin keys share one namespace. Core names are reserved, and a plugin whose key collides is served under `/mcp/x-<pluginKey>` with a load warning.
- `GET /mcp/<split>` answers 405, like `/mcp`.
- `/mcp/<split>/server-card` serves the split's MCP Server Card (SEP-2127).
- `/mcp` itself stays the whole server: every split's tools, in the modes of §5.3.
- `serverInfo.name` is `whity-core/<split>`, and `instructions` names the split and the sibling splits, so a client showing several Whity servers can tell them apart.
- One router entry (`/mcp/{split}`) resolves the split per request into the same `ToolSurfaceRequest` as §5.3, so no per-split worker state is needed. An unknown or unavailable split answers 404.

**Auth and token scoping per split.**

- **OAuth tokens (ADR-0015).** Each split is its own RFC 8707 resource, `https://<host>/mcp/<split>`, with Protected Resource Metadata at `/.well-known/oauth-protected-resource/mcp/<split>` and a 401 challenge naming it. A token whose `aud` is the split URL works only on that split. A token whose `aud` is `/mcp` works on `/mcp` and on every split, so existing tokens keep working. `OAuthAccessTokenVerifier` accepts exactly one resource today; it changes to accept `/mcp` or the URL of the split being called.
- **Whity-issued MCP tokens.** These have no `aud`. The connect wizard issues them with `toolset:` scope entries matching the split (§5.4), so a token made for `/mcp/documents` can't be used for the people tools on `/mcp` either.
- **Rate limits** stay per principal and per tenant across all splits. Splitting must not multiply a client's budget.
- **Audit:** `mcp.tools.call` records the split.
- **Entitlements:** a toolset may declare `requiresEntitlement` (an `EntitlementRegistry` key). A split whose toolsets all need an entitlement the tenant lacks answers 404 for that tenant and is left out of discovery. The tools themselves are filtered the same way on `/mcp`. This is how a paid service's slice, such as the Whity-Builder toolset, stays invisible to tenants without it. It is checked per request through `FeatureService` / `EntitlementService`, as the MCP feature itself already is (`mcp.access`).

**Discovery of splits.**

- `GET /api/mcp/splits` (authenticated): the splits the caller can reach in their tenant, with URL, title, description, tool count and estimated size. The connect wizard and admin page read it. This is the main path, because what a split contains depends on the caller.
- `search_tools` with an empty query on `/mcp` (§5.5) includes each toolset's split URL, so an agent can tell a person "connect `/mcp/billing` for this".
- `/.well-known/ai-catalog.json` lists the core splits' Server Cards and is public, as SEP-2127 intends. Plugin splits are listed there only when the operator turns it on (`MCP_PUBLIC_PLUGIN_SPLITS`), because a public list of installed plugins is information an operator may not want to publish. Server Cards never list tools (SEP-2127 excludes primitives), so nothing per-user leaks.

A tenant-scoped manifest plugin's split exists only for its tenant and answers 404 for every other tenant.

**How plugins register a split.** Through `PluginMcpToolsetsInterface` (§5.7). The root toolset is a split automatically; a sub-toolset adds `'split' => true`; either may add `'requiresEntitlement' => '<key>'`. A plugin has no route to register and no handler to write: the host serves the endpoint. Builder-made plugins (the Whity-Builder design) get their models' and actions' tools under their own plugin key, and a split, through the same path.

**Change notification per split.** `CatalogSignature` is computed per split as well as for `/mcp`, so installing plugin B doesn't send `list_changed` to a client connected only to `/mcp/documents`.

**How splits relate to in-server lazy discovery.** They are complementary; neither replaces the other.

| Need | Use |
|---|---|
| One connection that reaches everything, with a small context | `/mcp?tools=default` (default list plus search) |
| A client that does one job (a documents assistant, a billing bot) | `/mcp/<split>`: a small full list, a hard boundary, its own token |
| A person who wants areas as separate servers they can switch on and off in their client | Several splits. Most clients already let people switch whole servers on and off. |
| A very large plugin | `/mcp/<plugin>?tools=discover` |

The connect wizard offers both: "One connection for everything (recommended)" or "Separate connections per area", the second issuing one URL and token per chosen split. With splits, most people won't need `?toolsets=`. It stays for advanced clients and tests.

**What splits don't do.** They don't run in separate processes. Because a split is a path, a reverse proxy can later route `/mcp/<split>` to a separate deployment if one slice ever needs isolation or its own scaling, but that is not proposed now (Q6a).

## 6. Back-compat

| Who | What they see after phase 1 |
|---|---|
| Client on `/mcp` | The same full permitted list, the same names, the same order rule. Tools gain `_meta`, which clients ignore. |
| Client on `/mcp?tools=discover` | The same three tools. `search_tools` gains an optional argument and returns a toolset field. |
| Existing tokens | No `toolset:` entries, so all toolsets. Unchanged. |
| Existing plugins (SDK ≤ 1.46) | Their tools land in toolset `<pluginKey>`, visibility `discoverable`. Visible exactly as before in `full` mode, searchable in `default` mode. An authored name without the plugin prefix gets a deprecation warning, not a refusal. |
| `openapi.json`, typed clients | No change: names come from the same derivation. |

Phase 2 flips the default only after the owner decides (Q1), with a changelog entry and an MCP runbook note.

## 7. Alternatives considered

- **`enable_toolset` / `disable_toolset` tools that change `tools/list`.** Some MCP servers do this. Rejected: 2026-07-28 says the list MUST NOT vary as a side effect of other requests. Our server keeps no connection state to hang it on. And 2026 clients get no `list_changed` from us (no `subscriptions/listen`), so they would never see the change. Search plus `call_tool` reaches the same tools statelessly.
- **Pagination as a size control.** Rejected: clients fetch every page before using the list, so context is the same.
- **A separate MCP server process per area or plugin.** Rejected for now: core's RBAC, audit and rate limits would be duplicated or bypassed, and each deployment would need its own token handling. Splits (§5.13) give clients separate endpoints from one server, and a proxy can still move one split to its own deployment later.
- **Renaming derived tools to `core_documents_list`.** Better names for a model, but every name is published in `openapi.json` and the typed clients, and saved prompts use them. Possible later as aliases, not in this design.
- **Toolsets as a permission concept** (a role grants toolsets). Rejected: RBAC already says who may do what, and a second grant system would drift from it. Toolsets narrow tokens, and that is all.
- **Dots in tool names for namespacing** (`acme.palettes.suggest`). Allowed by the spec, but rejected because some model APIs refuse dots in function names (§3).

## 8. Later, not in this design

- Per-toolset guidance served through the MCP Skills extension (SEP-2640) as `skill://whity/<toolset>/SKILL.md`, so a model loads "how to use the documents tools" only when it needs it.
- Toolsets for prompts and resources (the same `_meta` key and URL filter).
- `outputSchema` on derived tools, blocked on WHIT-120 (response schemas checked against handlers).
- Usage-informed default visibility: promote tools that most clients call, from the `mcp.tools.call` audit trail.

## 9. Coordination with the document designs

Agreed with the document-theme and document-automation designs (`DOCUMENT-THEMES.md` §6 and §7, `DOCUMENT-AUTOMATION.md` §3.10, commit 5f03c532 on `design/document-themes`):

- **One vocabulary.** Both use `toolset`, `visibility` (`default` · `discoverable` · `off`) and `agentPolicy` as defined here.
- **Toolset ids.** Theme tools go in `core.documents.themes` and document automation in `core.documents.automation`, both `discoverable`. The document designs currently write `documents.themes` and `documents.automation`; they gain the `core.` prefix (§5.1).
- **No per-plugin document tools.** Theme plugins (`PluginDocumentThemesInterface`) and document plugins (`PluginDocumentsInterface`) contribute data; core's own tools expose it. This keeps the tool count flat as document plugins are added.
- **Agent writes.** Theme writes are `refuse` now, and setting a document's theme becomes `suggest-only` with M6 (`document_suggest_theme`). Issuing documents, batches and slot binding are `propose`, which behaves as `refuse` until `agent_proposals` exists. All of it uses `agentPolicy` (§5.12), set on the route schema, so REST and MCP give the same answer.

## 10. Open questions for the owner

1. **Default mode.** Keep `/mcp` (no query) on the full list and use `?tools=default` only for new wizard connections (recommended for phase 1)? Or flip the bare endpoint to `default` in phase 2, and if so, when?
2. **Token toolsets enforced on `tools/call`.** Recommended yes: they are part of the token, like permission narrowing. The alternative is presentation only.
3. **Core default visibility.** Is the 15-tool list in §5.2 right? It is read-only on purpose; there are no writes in the default list.
4. **Plugin limits.** 3 default-visibility tools per plugin, a 30-tool and about 6k-token default list, and 500-character descriptions. Are these right?
5. **Agent principals.** Is an `agent` flag on the token the right way to tell an unattended agent from a person's own MCP client (§5.12)? And once `agent_proposals` exists, should undeclared write routes default to `propose` for agents?
6. **What "MCP splitter" means.** This design reads it as several MCP endpoints served by one server (§5.13). If something else was meant, which: (a) separate deployments or processes per slice, for isolation or scaling (possible later behind a proxy, not proposed now); (b) splitting by tenant or role rather than by area; (c) splitting large tools into smaller ones, or large tool results into pages; (d) a gateway that splits third-party MCP servers for Whity users?
7. **Core split list.** Are `documents`, `forms`, `convening`, `people`, `inbox`, `admin`, `billing`, `security` and `platform` the right slices? Should plugin splits be listed in the public AI catalog by default (proposed: no)?

## 11. Task breakdown

Taskaat, project WHIT, section "Plugins, SDK & API" (WHIT-755 is in "Docs & Ops"). Owner approval of this document comes first; nothing below starts before it.

| Task | What | Depends on |
|---|---|---|
| WHIT-746 umbrella | Owner review of this design, answers to §10 | none |
| WHIT-747 toolset model | `CoreToolsets.php` (map, titles, default visibility list), toolset and visibility on derived tools, `schema['mcp']` array form with `expose: false`, `_meta` on tools, stable order, `CatalogSignature` covering toolset and visibility | WHIT-746 |
| WHIT-748 list modes | `ToolSurfaceRequest`, `tools=default` and `full`, `?toolsets=`, discovery tools appended, budget cap at runtime | WHIT-747 |
| WHIT-749 token toolsets | `toolset:` scope entries in `McpScope` (reserved prefix, issuance validation), enforcement in list, search, describe and call; ADR-0006 amendment | WHIT-747 |
| WHIT-750 discovery tools | `search_tools` toolset argument, empty-query table of contents, toolset in matches | WHIT-748 |
| WHIT-751 budgets in CI | `McpToolBudgetTest` (names, descriptions, schema size, core default list), toolset completeness in `RouteCatalogueCompletenessTest` | WHIT-747 |
| WHIT-752 SDK 1.47 | descriptor `toolset` / `visibility`, `PluginMcpToolsetsInterface`, name prefix with deprecation, loader enforcement and demotion, SDK README and Plugin-Development wiki, conformance checks | WHIT-747 |
| WHIT-754 admin and wizard | admin MCP page columns and size estimate; connect wizard toolset step and `?tools=default` URL | WHIT-748, WHIT-749 |
| WHIT-753 agent policy | `agent` flag on MCP tokens (issuing screen, OAuth registration, audit), `agentPolicy` on route schemas and authored descriptors, route middleware, `_meta`; ADR-0006 amendment | WHIT-746 (Q5) |
| WHIT-755 docs | MCP-Server and MCP-Client-Connection wiki pages, runbook, changelog | WHIT-747 to WHIT-752 |
| WHIT-765 splits | `/mcp/{split}` route, split registry (core list, plugin root and `split: true`, `MCP_SPLITS`), toolset `requiresEntitlement`, hard boundary on call, per-split OAuth resource and metadata, verifier accepting `/mcp` or the split, per-split `CatalogSignature`, `GET /api/mcp/splits`, Server Cards and AI catalog, connect wizard "separate connections" option | WHIT-747, WHIT-748, WHIT-749 (Q6, Q7) |
| Phase 2 (later) | flip the bare endpoint default (if Q1 says so); tenant `mcp.default_toolsets` | WHIT-746 decision |
