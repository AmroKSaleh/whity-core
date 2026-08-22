# Plugin UI Blocks (Server-Driven UI)

**Status:** shipped — SDK **v1.8**. SP1 (display) + SP2 (data-bound) + SP3 (interactive) are merged.

A plugin describes an admin screen ONCE as a platform-neutral tree of semantic **blocks**.
The host validates the tree and serves it; each platform has its own **renderer** that
maps the blocks to native widgets. The web renderer ships today; mobile (Flutter) and
desktop renderers consume the *same* contract unchanged. This is the "declare once,
render on every platform" model — a PHP-only plugin author never writes per-platform UI.

This page is the canonical reference for the block contract. The authoritative,
machine-enforced source of truth is the SDK:

- **`sdk/src/Frontend/Blocks/BlockContract.php`** — the block-type whitelist, each type's
  prop rules, and the structural caps.
- **`sdk/src/Frontend/Blocks/BlockValidator.php`** — the pure validator (`validate(array $tree): array{ok, errors}`).
- **`web/components/plugin/blocks/block-renderer.tsx`** + `form-context.tsx` +
  **`web/lib/use-plugin-data.ts`** — the reference web renderer (the model a new renderer follows).
- **`web/lib/plugin-features.ts`** — the TypeScript mirror of the contract.
- **`plugins/UiKitShowcase/`** — a sanctioned example plugin that renders EVERY block type
  live beside the PHP that declares it. As an admin, open `/admin/x/ui-kit-reference`.

---

## How a plugin declares a blocks screen

A plugin's `getFrontendFeatures()` returns one or more feature descriptors. A `screen: 'blocks'`
feature carries a `blocks` tree:

```php
public function getFrontendFeatures(): array
{
    return [[
        'id'                 => 'my-dashboard',     // kebab-case slug; also the /admin/x/{id} route
        'label'              => 'My Dashboard',     // nav + screen title
        'screen'             => 'blocks',           // selects the block renderer
        'requiredPermission' => 'myplugin:view',    // fail-closed nav/visibility gate
        'group'              => 'plugins',          // optional nav group
        'order'              => 100,                // optional sort order
        'icon'               => 'dashboard',        // optional Tabler icon name
        'blocks'             => [ /* tree of block nodes */ ],
    ]];
}
```

The host validates `blocks` against the SDK whitelist before serving; an invalid tree
drops the feature (logged, never a 500) — the same fail-closed posture as every other
screen kind.

## How blocks reach a renderer

- `GET /api/v1/frontend/features` → `{ "data": PluginFeature[] }`, **permission-filtered**
  for the caller. Each feature:
  `{ id, plugin, label, icon, group, order, screen, blocks?, resource?, action?, requiredPermission, capabilities }`.
  `blocks` is present when `screen === 'blocks'`.
- The host **re-validates** the tree at serve time (defence in depth) and **version-rewrites**
  every data-bound/interactive endpoint to its reachable `/api/v1/...` form (see below), so a
  renderer fetches/submits endpoints **verbatim**.
- `GET /api/v1/me/capabilities` → `{ "data": { "permissions": string[] } }` — the caller's
  permission slugs, used to gate interactive controls.

## The block node

```
{ "type": "<whitelisted type>", ...semanticProps, "children"?: Block[] }
```

- **Semantic props only.** `variant`, `level`, `tone`, `trend`, `align`, `columns` — never
  CSS classes, hex/RGB colors, or pixel values. The renderer maps semantics to its
  platform's design tokens/widgets.
- **Containers** carry `children`; **leaves** do not.
- **Structural caps:** max depth **32**, max total nodes **500**.

### Renderer rules (every platform must honor)

- **Defensive:** an unknown `type`, a missing required prop, or an out-of-set enum →
  a quiet inline "unsupported block" placeholder. A renderer NEVER throws on a malformed tree.
- **No injection:** every plugin-supplied string renders as TEXT only — never interpreted
  as markup/HTML/code.
- **Internal targets only:** `button.href` and all data/interactive endpoints are
  relative paths (`/...`); a non-relative target is inert.
- **Fail-closed RBAC:** permission gating is a UI hint; the server is always the authority.

---

## Display blocks (SP1) — static, inline data

**Containers** (carry `children`):

| type | props |
|---|---|
| `section` | `title?` |
| `card` | `title?`, `description?` |
| `grid` | `columns: 1\|2\|3\|4` (responsive) |
| `row` | `align?: start\|center\|end\|between` (horizontal) |
| `tabs` | — (children must be `tab`) |
| `tab` | `label` (only valid directly under `tabs`) |

**Leaves:**

| type | props |
|---|---|
| `divider` | — |
| `heading` | `level: 1\|2\|3\|4`, `text` |
| `text` | `value`, `tone?: default\|muted` |
| `alert` | `variant: info\|success\|warning\|danger`, `title?`, `body` |
| `badge` | `variant: neutral\|info\|success\|warning\|danger`, `label` |
| `stat` | `label`, `value`, `hint?`, `trend?: up\|down\|flat` |
| `keyValue` | `items: { label, value }[]` |
| `list` | `ordered?: bool`, `items: string[]` |
| `table` | `columns: { key, label }[]`, `rows: Record<string,string>[]` (static) |
| `button` | `label`, `href` (relative, starts `/`), `variant?: primary\|secondary\|outline\|ghost\|destructive` |
| `icon` | `name` (Tabler icon kebab-name), `tone?: default\|muted` |
| `code` | `language?`, `content` (monospace, non-executed) |

## Data-bound blocks (SP2) — fetch their own data

Each declares a `source`: a relative API path the host has **already version-rewritten** to
`/api/v1/...`. The renderer fetches it with the caller's session; the response envelope is
`{ "data": ... }`. Render the state machine: **loading → error (with retry) → empty (uses
`emptyText`) → ready**. Values are stringified and rendered as text.

| type | props | reads | renders |
|---|---|---|---|
| `dataTable` | `source`, `columns: { key, label }[]`, `emptyText?` | `{ data: Row[] }` | a table; cell = `String(row[col.key])` |
| `dataStat` | `source`, `label`, `valueField`, `hintField?`, `trendField?`, `emptyText?` | `{ data: Object }` | a metric tile; value = `String(obj[valueField])` |
| `dataList` | `source`, `itemField`, `ordered?`, `emptyText?` | `{ data: Row[] }` | a list; item = `String(row[itemField])` |

A non-ok response, a body that is not the `{data}` envelope, or a thrown fetch → **error**;
an empty collection / missing metric → **empty**.

**Host guarantee:** the host confirms every `source` is a `GET` route the *same plugin*
registered, then versions it. A plugin cannot bind a block to another plugin's or a core
endpoint (fail-closed); the route's own RBAC + tenant isolation still apply at request time.

## Interactive blocks (SP3) — forms + mutations

A `form` provides state keyed by each descendant input's `name`; a `submitButton` triggers
a POST/PUT of the collected values as JSON to the form's endpoint; an `actionButton` is a
standalone one-click mutation. Inputs and `submitButton` are valid **only inside a `form`**
(at any depth); input `name`s are unique within a form.

| type | props | notes |
|---|---|---|
| `form` | `submit: { method: POST\|PUT, endpoint }`, `requiredPermission?` | container |
| `textInput` | `name`, `label`, `placeholder?`, `required?`, `default?` | |
| `textArea` | `name`, `label`, `rows?`, `required?`, `default?` | |
| `numberInput` | `name`, `label`, `min?`, `max?`, `step?`, `required?`, `default?` | |
| `select` | `name`, `label`, `options: { value, label }[]`, `required?`, `default?` | |
| `checkbox` | `name`, `label`, `default?: bool` | boolean value |
| `slider` | `name`, `label`, `min`, `max`, `step?`, `default?` | |
| `dateInput` | `name`, `label`, `required?`, `default?` | |
| `fileInput` | `name`, `label`, `accept?`, `required?` | read as TEXT into the JSON property |
| `colorInput` | `name`, `label`, `default?` | |
| `submitButton` | `label`, `requiredPermission?`, `variant?` | the form's trigger; only inside a `form` |
| `actionButton` | `label`, `action: { method, endpoint }`, `requiredPermission?`, `confirm?`, `variant?` | standalone |

**Submission & feedback:** POST/PUT a JSON object `{ name: value, … }` to the (versioned)
endpoint. `2xx` → success; `422 { issues: [{ severity, message, item?, column? }] }` →
render the validation report; any other failure → error. (The existing `screen:'action'`
form uses the identical envelope.)

**Write-RBAC (hybrid):** the block declares a `requiredPermission`; the renderer gates the
trigger by the caller's capabilities (web: disabled+tooltip, or hidden for destructive),
AND the endpoint enforces RBAC server-side as the authority. The host additionally requires
the endpoint route's `requiredPermission` to **equal** the block's declared one, and that the
endpoint is a `POST`/`PUT` route the *same plugin* registered — else the feature is dropped
fail-closed. A renderer must never offer an action the backend will reject.

---

## Conditional visibility and caller access (#909)

**Every** block carries an optional `visibleWhen`. It names exactly one subject and how to
test it:

| subject | reads | test |
|---|---|---|
| `field` | a sibling input in the same `form` | `equals` or `in` |
| `from` | the master-detail context — `{recordId}.{field}`, or a bare `selector` name | `equals` or `in` |
| `access` | an `accessGate` id — the **host's** answer about the caller | `equals: true\|false` |

**Facts fail open, authority fails closed.** A `field`/`from` reference that does not resolve
leaves the block visible, so content is never permanently hidden by a missing context. An
`access` answer that has not arrived, or that names a gate nothing declared, **hides** the
block whichever polarity it asked for — a control drawn before its permission is known is a
control drawn for somebody who may not have it.

### `accessGate` — one question about the caller, two renderings

| type | props | notes |
|---|---|---|
| `accessGate` | `id`, `check: { method: GET\|POST\|PUT\|PATCH\|DELETE, endpoint }` | container with **two** child lists: `children` (permitted) and `otherwise` (refused); both optional |

```php
['type' => 'accessGate',
    'id'    => 'may-write',
    // A concrete REQUEST, never a permission slug.
    'check' => ['method' => 'PATCH', 'endpoint' => '/api/acme/roles/{record}'],
    'children'  => [ /* the editor */ ],
    'otherwise' => [ /* a <dl>, and a notice saying which gate refused */ ],
]
```

**The plugin does not declare which permission gates the region, and there is no prop with
which to say.** The host looks the `check`'s method + path up in the live route table and
evaluates that route's own gate for the caller — the same `RoleChecker` calls
`RbacMiddleware` makes, through `POST /api/v1/me/permitted-actions`. Re-gate the route and
the page follows without an edit. A restated slug would be a second answer to a question the
route table already answers, and nothing would compare the two.

`endpoint` is an owned API path that may carry `{token}` segments in the ordinary
master-detail addressing. The gate is **not asked** until every token resolves: a
half-substituted path names a different route with a different gate.

**Two slots, not two negated conditions.** The pair could be written as siblings with
opposite `visibleWhen` polarity; declared as one node they cannot drift, and when such a pair
does drift it is the editable half that ends up showing.

**hidden / read-only / editable** is two nested gates — the outer on the READ request with no
`otherwise` (refused ⇒ the region is absent), the inner on the WRITE request with both. An
outer gate that refuses never renders the inner one, so exactly one refusal is ever on screen.

**A gate's answer is a control, never a fact.** It is published into a namespace of its own
that `textFrom`/`valueFrom`/`labelFrom`/`hintFrom` — and `defaultFrom`/`params.from` — do not
resolve against. `visibleWhen.access` is the only prop in the contract that names a gate, and
all it can do is decide whether a subtree renders. A page can act on what the caller may do
and still cannot state it as a property of the record (#895).

---

## Writing a new renderer (web / mobile / desktop)

A renderer is a recursive function `render(block)` that switches on `block.type`, maps each
type to a native widget, and recurses into `children` for containers. Checklist:

1. **Mirror the contract** from `BlockContract.php` (do not invent types client-side — request
   additions in the SDK so every platform stays in sync).
2. **Map semantics → native tokens/widgets** — `variant`/`tone`/`trend`/`level`/`align`/`columns`
   to your platform's design system. Never hard-code colors/pixels.
3. **Be defensive** — unknown type / bad props → a quiet placeholder; never crash; strings as text only.
4. **Data-bound blocks:** fetch `source` (already versioned) with the session; honor the
   loading/error/empty/ready states and the `{data}` envelope.
5. **Interactive blocks:** manage form state by input `name`; submit JSON to the (versioned)
   endpoint; render success / `422 {issues}` / error; gate triggers by `requiredPermission`
   via the capabilities endpoint.
6. **Endpoints are pre-versioned** — fetch/submit them verbatim (no client-side `/v1` rewriting).
7. **Child lists are declared, not assumed** — descend through `BlockContract::childSlots($type)`,
   not through a hard-coded `children`. `accessGate` carries a second list.
8. **Resolve `accessGate` through the host** — collect every gate's `check` from the tree, ask
   `POST /api/v1/me/permitted-actions` once for the page, and fail closed: while loading, on
   error, and for a gate whose endpoint still has an unresolved token, render neither branch
   (a pending gate is not a refused one). Never answer the question locally.

See `web/components/plugin/blocks/block-renderer.tsx` for the reference mapping of all 33
types and the data-bound/interactive state machines.

## Versioning

The contract is SDK-owned and SemVer-versioned (`Whity\Sdk\Sdk::VERSION`). New block types
are additive minor bumps. Renderers should treat unknown (newer) types as the defensive
placeholder, so an older renderer degrades gracefully against a newer contract.
