# whity/plugin-sdk

The standalone plugin contract package for the Whity platform. A plugin
implements the SDK types — and depends on **nothing else**: this package
requires only PHP, never `whity-core`. That is what makes a plugin
distributable across Whity-based applications without dragging a host
framework along.

## Contract surface (v1.43.0)

| Type | Since | Purpose |
| --- | --- | --- |
| `Whity\Sdk\PluginInterface` | 1.0 | The plugin contract: name/version, routes, permissions, hooks, migrations. |
| `Whity\Sdk\MigrationInterface` | 1.0 | Plugin schema migrations: `up(\PDO)` / `down(\PDO)`, idempotent statements, tested rollback; executed by the host's `migrate run`. |
| `Whity\Sdk\Http\Request` | 1.0 | The request shape route handlers receive (headers, body, per-request attribute bag incl. `ATTR_JWT_CLAIMS`). |
| `Whity\Sdk\Http\Response` | 1.0 | The response shape handlers return; `Response::json()` / `Response::error()` factories. |
| `Whity\Sdk\Hooks\Events` | 1.0 | Catalogue of hook event names (`user.creating`, `tenant.deleted`, `worker.request.start`, …). |
| `Whity\Sdk\Hooks\HookVetoException` | 1.15 | The only Throwable that crosses the host's per-plugin error boundary. Throw it from `*.deleting` / `*.deleted` to REFUSE a deletion (or report failed cleanup): the host rolls the delete back — those hooks run inside the DELETE's transaction — and answers `409 Conflict` with your `reason()`. Every other exception stays isolated. |
| `Whity\Sdk\Rbac\PermissionResolver` | 1.16 (resource scope: 1.17, roles 1.22) | READ-ONLY access to the host's authoritative permission resolution, resolved from the service container (`\Whity\app(PermissionResolver::class)`). Ask it for an authorization decision INSIDE a handler instead of re-deriving one in SQL: it is backed by the same delegation-aware resolver the host's RBAC middleware enforces with (active-membership gating, OU-ancestor inheritance, role hierarchy, live delegations, catalogue validation), so plugin and platform can never disagree about the same caller. All three methods take an optional `$resourceType`/`$resourceId` pair that narrows the question to ONE record — `hasPermission()`/`effectivePermissions()` since 1.17, `hasRole()` since 1.22 — so per-record authority needs no private grant table. Pass both or neither; a record grant only ever WIDENS the tenant-wide answer and never substitutes for tenant membership. Grants no authority — three question methods, no cache control, no database handle. |
| `Whity\Sdk\Sdk` | 1.1 | SDK identity: `Sdk::VERSION`, what hosts evaluate plugin SDK-constraints against. |
| `Whity\Sdk\PluginRequirementsInterface` | 1.1 (core constraint: 1.4) | OPTIONAL declaration of a required SDK constraint, a host CORE-version constraint (`getCoreConstraint()`, since 1.4), and inter-plugin dependencies (composer constraint syntax). Unsatisfied plugins are quarantined (`PluginState::Failed` + reason); satisfied ones load in topological dependency order. |
| `Whity\Sdk\PluginFrontendInterface` | 1.2 | OPTIONAL declaration of the admin-UI screens a plugin contributes (frontend feature descriptors). UI metadata only — descriptors grant nothing; the host validates, permission-filters, and serves them via `GET /api/frontend/features`. |
| `Whity\Sdk\Tenant\TenantTableRegistry` | 1.3 | The portable, dependency-free model of a host's / plugin's tenant-owned and sanctioned-global tables, consumed by the scanner and linter. |
| `Whity\Sdk\Tenant\TenantPredicateScanner` | 1.3 | The tokenizer-based static scanner that flags any `SELECT`/`UPDATE`/`DELETE` on a tenant-owned table missing a `tenant_id` predicate (honours `@tenant-guard-ignore:` + the global allowlist). |
| `Whity\Sdk\Tenant\MigrationTenantColumnLinter` | 1.3 | Lints a plugin's `CREATE TABLE` migrations: every tenant table must declare a `tenant_id` column (or be a declared global / transitively-scoped exception). |
| `Whity\Sdk\Testing\TenantIsolationConformanceTestCase` | 1.3 | The shared PHPUnit base case a plugin extends to PROVE its tenant isolation: wires the linter + scanner + a RealEngine schema check. Requires `phpunit/phpunit` (dev-only `suggest`). |
| `Whity\Sdk\Testing\OfflinePluginHostConformanceTestCase` | 1.27 | The shared PHPUnit base case a plugin extends to PROVE it boots and behaves correctly under an OFFLINE PHP plugin host (no server framework — the shape the Tauri desktop template's bundled FrankenPHP runs plugins under): migrations apply cleanly on the same narrow SQLite dialect shim, declared permissions are well-formed and match every route's `requiredPermission`, a role granted one permission holds exactly that one, and every declared hook runs cleanly on a synthetic payload. Requires `phpunit/phpunit` (dev-only `suggest`), same as the tenant-isolation kit. |
| `Whity\Sdk\Settings\PluginSettingsInterface` | 1.21 | OPTIONAL declaration of the CONFIGURATION KEYS a plugin owns — key => type (`string`/`bool`/`int`/`enum`), default, constraints, options, localized label, description. The host stores them in ITS OWN `app_settings` / `tenant_settings` tables and resolves them through ITS OWN chain (per-tenant override ?? global default ?? declared default), so a plugin stops rebuilding the settings layer as a private table with no declared keys and no validation. Keys are namespaced under the plugin name the loader supplies (`acme:sync_mode`), so two plugins cannot collide and none can shadow a core key; a declaration whose own `default` fails its own rules is refused at load. Publication on the host's settings screens is an explicit `admin => true` opt-in (those screens are gated on CORE settings permissions, not the plugin's). NOT for credentials: a secret-shaped declaration is refused, since settings are readable TEXT served to anyone holding `settings:read`. |
| `Whity\Sdk\Render\DocumentRenderer` | 1.41 | The RENDERING SEAM, RESOLVED rather than implemented: `\Whity\app(DocumentRenderer::class)` turns a plugin's structured content into a document. `render()` answers bytes and stores nothing; `issue()` answers a first-class platform document — an id, an immutable artifact, per-tenant storage routing — so routing, verification and the organizer apply without the plugin arranging any of it. `isAvailable()` is the cheap check to make before assembling something expensive. NO METHOD TAKES A TENANT ID: the host reads tenant and actor from its own request-scoped context, so a document built from one tenant's content and filed in another's storage is not expressible. Registered in the HTTP entry point only — see the interface's own note before calling it from a queue worker. |
| `Whity\Sdk\Render\FlowDocument` | 1.41 | The content a document is assembled from: headings, paragraphs, tables, figures, page breaks, plus generated contents / list-of-tables / list-of-figures front matter, in RTL and LTR. A mutable BUILDER (the one deliberate departure from this SDK's immutable value objects — a hundred-page submission is tens of thousands of blocks and copy-on-append would be quadratic). It refuses only what it must know to build a correct tree — a heading level outside 1-6, a remote figure source, a map row where a list belongs — and leaves everything else to the renderer, which names the offending field. NUMBERING IS NOT SETTABLE: tables, figures and heading numbers are assigned by the renderer in document order, because a caller that numbered its own would have to renumber on every insert and any disagreement with the generated lists would be invisible until somebody read the printed document. |
| `Whity\Sdk\Render\PageSpec` | 1.41 | Page geometry in MILLIMETRES: the four presets by name (`a4()`, `a5()`, `letter()`, `legal()`), an explicit `ofSize()`, and `withMargins()`. Presets send the NAME rather than the dimensions, so the millimetres stay decided in one place. |
| `Whity\Sdk\Render\RenderedDocument` | 1.41 | The bytes plus what only the renderer could know: `pageCount`, `frontMatterPages`, and the derived `bodyPageCount()`. A flowing document is defined by not knowing its own length in advance, so this is told rather than computed. |
| `Whity\Sdk\Render\IssuedDocument` | 1.41 | A rendered document the platform now OWNS: `documentId`, `contentUrl`, page counts, byte size. Deliberately carries NO bytes — they are already stored, and a second copy of a thing whose defining property is that there is one of it is a liability. |
| `Whity\Sdk\Render\RenderRejectedException` / `RenderUnavailableException` | 1.41 | The two failure modes, kept APART on purpose: the first will never succeed unchanged (a ceiling, a refused tree) and carries a `clientMessage` written to be shown; the second is an outage or a disabled instance, which is the DEFAULT state of a fresh install. A plugin that cannot tell them apart either retries a malformed document forever or gives up on a container that was restarting. |

## Versioning policy

Semantic and **additive**: new capabilities land in minor versions, each an
optional surface existing plugins can ignore —

- **1.0** — contract extraction (plugin/migration interfaces, HTTP shapes, events).
- **1.1** — requirements declaration + version gate.
- **1.2** — frontend feature descriptor (`PluginFrontendInterface`), plus the
  route-array `requiredPermission` key is now **enforced by the host**: the
  RBAC middleware denies callers without the permission (403 naming it), and
  a malformed value fails closed (the route is not registered).
- **1.3** — tenant-isolation conformance kit (`Whity\Sdk\Tenant\*` +
  `Whity\Sdk\Testing\TenantIsolationConformanceTestCase`): a reusable
  migration linter + tenant-predicate scanner + shared base test case a plugin
  runs in its OWN CI to prove its tenant tables and queries are scoped.
- **1.4** — host CORE-version constraint declaration
  (`PluginRequirementsInterface::getCoreConstraint()`): a plugin built for a
  specific or newer host core can be quarantined cleanly at load, gated against
  the host's core version independently of the SDK gate.
- **1.27** — offline-host conformance kit (`Whity\Sdk\Testing\OfflinePluginHostConformanceTestCase`):
  the same idea as the 1.3 tenant-isolation kit, but proving a plugin actually
  BOOTS under an offline PHP host with no server framework behind it — see
  [Proving offline-host compatibility](#proving-offline-host-compatibility-127)
  below.

The list above is illustrative, not a changelog. The authoritative,
version-by-version ledger is the docblock on
[`Sdk.php`](src/Sdk.php), which every release appends to.

Breaking contract changes require a new major. A plugin declares the SDK range
it supports via `getSdkConstraint()` (e.g. `'^1.1'`) and, optionally, the host
CORE range via `getCoreConstraint()` (e.g. `'^0.1'`); the host refuses to load
a plugin when its own `Sdk::VERSION` or `CoreVersion::VERSION` falls outside the
respective range — with the reason visible in the admin plugin list.

### What a MINOR version may and may not change

"Additive" is the promise; these are its terms. A plugin pinned `^1.x` and
loading today must still load on any later `1.y`.

**A minor MAY:**

- add a new optional interface a plugin can choose to implement
  (`PluginHealthProbesInterface`, `PluginMcpToolsInterface`, …);
- add an OPTIONAL parameter to an existing method, with a default;
- add a new value object, exception type, or testing kit;
- add a new optional key to a declared array shape (a route declaration, a
  block descriptor), where omitting it preserves the previous behaviour exactly;
- widen what an existing method accepts.

**A minor MAY NOT:**

- add a method to an existing interface — that breaks every implementer, which
  is why a new capability arrives as a NEW interface even when an obviously
  related one already exists;
- remove or rename anything public, or narrow a parameter or return type;
- change the meaning of an existing key. Adding `scope` to a descriptor is
  additive; changing what an ABSENT `scope` means is not, because it silently
  alters the behaviour of code nobody edited.

The last one is the rule that is easiest to break by accident, because the
diff looks like a default value rather than a contract change.

### Upgrading a deployment that carries third-party plugins

The gate is enforced at LOAD, per plugin, and it fails closed: a plugin whose
`getSdkConstraint()` excludes the host's `Sdk::VERSION` is **quarantined** —
moved to `failed` state with no routes, permissions, hooks, migrations or
frontend features registered. It does not partially load, and it does not take
the host down with it.

**After upgrading**, read the state of every installed plugin:

```bash
curl -s http://<host>/api/plugins | jq '.data[] | {name, version, state, last_error}'
```

A quarantined plugin is in `state: "failed"` with `last_error.type` of
`"quarantine"` and a message naming the mismatch:

```
requires plugin SDK '^2.0', but the host provides 1.43.0
```

The same message is written to the host's error log at load, so it is visible
without an authenticated call.

**Before upgrading there is no equivalent probe, and that is a real gap.** A
plugin's declared `getSdkConstraint()` is evaluated at load but is NOT
published in the plugin list, so the only way to know whether an installed
build will survive an upgrade is to read the plugin's own source or its
distribution metadata. Until that is exposed, the practical check is to stage
the upgrade somewhere disposable first and read the list above.

A plugin that declares NO constraint is never quarantined by this gate. That
is deliberate — the declaration is opt-in — but it means such a plugin can load
against an SDK it was never tested on and fail later, in its own code, on a
request. Declaring a constraint is how a plugin author converts that into a
clean refusal at boot.

**Recovering** is a plugin-side change, not a host-side one: publish a build
whose constraint admits the new SDK, install it, and the plugin loads on the
next boot. Nothing in the host needs rolling back, because a quarantined
plugin changed nothing when it failed to load.

### Declaring frontend features (1.2)

A plugin MAY implement `PluginFrontendInterface` to describe the screens the
host's admin UI should render for it. A descriptor is **UI metadata only** —
it grants nothing; data access stays enforced by the route-level RBAC of the
underlying API routes, and the host filters the descriptor listing per caller
server-side.

```php
use Whity\Sdk\PluginFrontendInterface;

final class MyPlugin implements PluginInterface, PluginFrontendInterface
{
    public function getFrontendFeatures(): array
    {
        return [[
            'id' => 'hello-greetings',          // REQUIRED kebab-case slug, unique across plugins
            'label' => 'Greetings',             // REQUIRED human title
            'screen' => 'crud',                 // REQUIRED: 'crud' | 'custom'
            'requiredPermission' => 'hello:view', // REQUIRED, must be one of THIS plugin's getPermissions()
            'resource' => [                     // REQUIRED when screen = 'crud'
                'basePath' => '/api/hello/greetings', // must be a GET route THIS plugin registers
                'titleField' => 'message',      // optional display-name field
            ],
            'icon' => 'message-circle',         // optional tabler icon
            'group' => 'plugins',               // optional nav group (default 'plugins')
            'order' => 10,                      // optional sort order (default 100)
        ]];
    }

    // ... PluginInterface methods ...
}
```

Validation is fail-closed and per descriptor: a descriptor with a malformed
id/screen/permission, a permission the plugin does not itself declare, or a
`crud` resource over a path the plugin does not serve is dropped with a logged
warning — the plugin still loads. Duplicate ids across plugins keep the first
(discovery order). The host exposes the surviving descriptors at
`GET /api/frontend/features`, returning each caller only the entries whose
`requiredPermission` they hold.

### Declaring requirements

```php
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;

final class MyPlugin implements PluginInterface, PluginRequirementsInterface
{
    public function getSdkConstraint(): string
    {
        return '^1.1';
    }

    /** Optional host CORE-version constraint (SDK 1.4); '' = no constraint. */
    public function getCoreConstraint(): string
    {
        return '^0.1';
    }

    /** @return array<string, string> plugin name => version constraint */
    public function getPluginDependencies(): array
    {
        return ['HelloWorld' => '^1.0'];
    }

    // ... PluginInterface methods ...
}
```

### Proving tenant isolation (1.3)

Whity is multi-tenant: a **tenant-owned** table carries a `tenant_id` column,
and every `SELECT`/`UPDATE`/`DELETE` on it must bind a `tenant_id` predicate.
The conformance kit lets a plugin prove — in its own CI, with nothing but this
SDK + PHPUnit — that its migrations and handlers uphold that invariant. Extend
the shared base case:

```php
use Whity\Sdk\Tenant\TenantTableRegistry;
use Whity\Sdk\Testing\TenantIsolationConformanceTestCase;
use MyPlugin\Migrations\CreateNotesTable;

final class MyPluginTenantConformanceTest extends TenantIsolationConformanceTestCase
{
    protected function tenantTableRegistry(): TenantTableRegistry
    {
        // Declare YOUR tenant tables, and merge in the host's so an unscoped
        // query against a core tenant table is flagged too. A standalone plugin
        // builds the host portion from a small published table list:
        return TenantTableRegistry::for([
            'notes' => 'MyPlugin notes; carries tenant_id (CreateNotesTable).',
        ])->merge(TenantTableRegistry::for(
            ['users' => 'host', 'roles' => 'host', /* … */],
            ['revoked_tokens' => 'host global', 'core_schema_migrations' => 'host global']
        ));
    }

    protected function migrationsDirectory(): string { return __DIR__ . '/../Migrations'; }

    /** @return list<string> */
    protected function handlerSourceDirectories(): array { return [__DIR__ . '/../Api']; }

    /** @return list<\Whity\Sdk\MigrationInterface> */
    protected function schemaMigrations(): array { return [new CreateNotesTable()]; }

    /** @return list<string> */
    protected function ownTenantTables(): array { return ['notes']; }
}
```

The case enforces three checks (each a separate test):

1. **Migration linter** — every `CREATE TABLE` declares a `tenant_id` column,
   or is declared global / transitively-scoped (with a reason) in the registry.
2. **Handler-scoping scanner** — every tenant-table query in your source binds
   a `tenant_id` predicate, honouring `// @tenant-guard-ignore: <reason>` for
   sanctioned exceptions (e.g. a system-tenant "sees all" branch).
3. **RealEngine** — your migrations are applied to a real SQL engine and each
   declared tenant table is asserted to physically carry `tenant_id`. Which
   engine is an **environment** decision, not a code one — see below.

You can also run the linter / scanner directly (no PHPUnit) in a CI script —
see `scripts/ci-plugin-tenant-conformance.php` in whity-core for the pattern.
PHPUnit is a **dev-only** requirement (`suggest`); the runtime SDK still
depends on nothing but PHP.

### Running your tests against real PostgreSQL

Your tests run SQLite. Your users run PostgreSQL. A whole class of defect lives
in that gap and cannot be found by adding assertions:

- `GROUP_CONCAT(x SEPARATOR ',')` parses on SQLite; PostgreSQL has no
  `GROUP_CONCAT` at all (`string_agg`).
- `WHERE varchar_col = 42` matches on SQLite; PostgreSQL refuses with
  `operator does not exist: character varying = integer`. Nothing about the
  statement is malformed — it is the engine's type semantics that differ, so no
  wrapper or query builder can catch it. Only running it on PostgreSQL can.

`Whity\Sdk\Testing\RealEnginePdo` is the harness. Set `PHPUNIT_PG_DSN` and the
same suite runs against real PostgreSQL; leave it unset for the fast local loop:

```bash
docker run -d --name plugin_pg -p 5432:5432 \
  -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=plugin_test postgres:15-alpine

vendor/bin/phpunit                                     # SQLite
PHPUNIT_PG_DSN="pgsql:host=127.0.0.1;port=5432;dbname=plugin_test" \
PHPUNIT_PG_USER=postgres PHPUNIT_PG_PASSWORD=postgres \
  vendor/bin/phpunit                                   # real PostgreSQL
```

`TenantIsolationConformanceTestCase::makePdo()` already delegates to it, so a
plugin extending the base case needs **no code change** — the variable is the
whole switch. Use it directly in your own data-layer tests too:

```php
use Whity\Sdk\Testing\RealEnginePdo;

$pdo = RealEnginePdo::make();
foreach ($this->migrations() as $migration) {
    $migration->up($pdo);
}
```

Each call gets its own auto-dropped schema in the target database, so parallel
runs never collide. Keep BOTH jobs in CI: the SQLite one for speed, the
PostgreSQL one for truth. Run the dual-engine job against a deliberately broken
query once (drop a `CAST`) to confirm it can actually fail — see
[Testing Against PostgreSQL](../docs/wiki/Testing-Against-PostgreSQL.md) for the
full trap catalogue and a copy-paste CI workflow.

### Proving offline-host compatibility (1.27)

`TenantIsolationConformanceTestCase` proves a DIFFERENT thing than the kit
below: that your queries stay tenant-scoped. It says nothing about whether
your plugin actually **boots** under a real offline PHP host that has no
server framework behind it at all — the shape the Tauri desktop template's
bundled FrankenPHP process runs plugins under (`templates/tauri-desktop/php-host/`
in whity-core): no JWT/memberships/OU hierarchy, a single fixed device role,
and a deliberately narrow SQLite dialect shim (only `SERIAL PRIMARY KEY` is
rewritten; `JSONB`/`gen_random_uuid()`/`RETURNING`-in-DDL are not).

Every real gap that host surfaced in practice — a migration using `SERIAL`
that SQLite silently mis-parses (leaving every inserted `id` `NULL`), an
un-seeded `admin` role that left existing grant migrations silently inert, a
route requiring a permission its plugin never declared in `getPermissions()`
— was found only by manually running the host and watching it fail. Extend
the shared base case to catch that class of defect in your own CI instead,
with no FrankenPHP process required:

```php
use Whity\Sdk\PluginInterface;
use Whity\Sdk\Testing\OfflinePluginHostConformanceTestCase;
use MyPlugin\MyPlugin;

final class MyPluginOfflineHostConformanceTest extends OfflinePluginHostConformanceTestCase
{
    protected function pluginUnderTest(): PluginInterface
    {
        return new MyPlugin();
    }
}
```

That's the whole test file — the base case supplies four checks (each a
separate test, run against an in-memory SQLite engine carrying only the
offline host's bare RBAC skeleton tables):

1. **Migrations apply cleanly** on the same narrow dialect shim the real host
   uses — catches a Postgres-only construct the shim doesn't rewrite, or a
   migration that assumes a table only a different plugin or core creates.
2. **Declared permissions are well-formed** (`resource:action`) — a malformed
   slug gets the WHOLE plugin quarantined by the offline host's loader, never
   silently ignored.
3. **Every route's `requiredPermission` is one of your declared permissions**,
   and a role granted exactly one permission can exercise only that one — an
   undeclared or typo'd permission slug is never grantable to anyone, offline
   or in production.
4. **Every declared hook runs cleanly on a synthetic empty payload.** A
   generic `Throwable` FAILS this test loudly, even though the real host's
   per-plugin error boundary would silently swallow it in production — the
   whole point is catching the bug in your CI instead of shipping it
   invisibly. `HookVetoException` is the one sanctioned exception and is not
   a failure.

## Minimal plugin

```php
use Whity\Sdk\PluginInterface;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Hooks\Events;

final class MyPlugin implements PluginInterface
{
    public function getName(): string { return 'MyPlugin'; }
    public function getVersion(): string { return '1.0.0'; }

    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/my/hello',
            'handler' => fn (Request $r): Response => Response::json(['ok' => true]),
            'requiredRole' => null,
        ]];
    }

    public function getPermissions(): array { return ['my:view']; }
    public function getHooks(): array { return [Events::USER_CREATING => fn (array $d, array $c): array => $d]; }
    public function getMigrations(): array { return []; }
}
```

## Packaging & distribution mechanism

This package lives in the `whity-core` monorepo under `sdk/` and is consumed
through a **composer path repository** — the exact mechanism a distributable
plugin (and the host apps that install it) reuses:

1. **Inside whity-core (this repo):** the root `composer.json` declares

   ```json
   "repositories": [{ "type": "path", "url": "sdk" }],
   "require": { "whity/plugin-sdk": "^1.0" }
   ```

   Composer links `sdk/` into `vendor/whity/plugin-sdk`, so `Whity\Sdk\…`
   autoloads exactly like any third-party package. The SDK owns the
   `Whity\Sdk\` autoload root — no other package may map that prefix.

2. **A distributable plugin package** requires the contract, not the host:

   ```json
   "require": { "whity/plugin-sdk": "^1.0" }
   ```

3. **A host application installing a plugin** adds the plugin (and
   transitively the SDK) through the same path-repository mechanism while the
   packages are co-developed:

   ```json
   "repositories": [
     { "type": "path", "url": "../whity-core/sdk" },
     { "type": "path", "url": "../whity-plugin-something" }
   ]
   ```

   Publishing to a private Composer repository (or extracting `sdk/` with
   `git subtree split`) later changes only the `repositories` entry — the
   `require` constraints and all plugin code stay identical. This is the same
   mechanism the extract-once/consume-twice pilot uses for distribution.

## License

MIT — deliberately permissive so plugin authors are not bound by the host
framework's license.
