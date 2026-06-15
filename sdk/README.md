# whity/plugin-sdk

The standalone plugin contract package for the Whity platform. A plugin
implements the SDK types — and depends on **nothing else**: this package
requires only PHP, never `whity-core`. That is what makes a plugin
distributable across Whity-based applications (KeyHub, Elmak, …) without
dragging a host framework along.

## Contract surface (v1.3.0)

| Type | Since | Purpose |
| --- | --- | --- |
| `Whity\Sdk\PluginInterface` | 1.0 | The plugin contract: name/version, routes, permissions, hooks, migrations. |
| `Whity\Sdk\MigrationInterface` | 1.0 | Plugin schema migrations: `up(\PDO)` / `down(\PDO)`, idempotent statements, tested rollback; executed by the host's `migrate run`. |
| `Whity\Sdk\Http\Request` | 1.0 | The request shape route handlers receive (headers, body, per-request attribute bag incl. `ATTR_JWT_CLAIMS`). |
| `Whity\Sdk\Http\Response` | 1.0 | The response shape handlers return; `Response::json()` / `Response::error()` factories. |
| `Whity\Sdk\Hooks\Events` | 1.0 | Catalogue of hook event names (`user.creating`, `tenant.deleted`, `worker.request.start`, …). |
| `Whity\Sdk\Sdk` | 1.1 | SDK identity: `Sdk::VERSION`, what hosts evaluate plugin SDK-constraints against. |
| `Whity\Sdk\PluginRequirementsInterface` | 1.1 | OPTIONAL declaration of a required SDK constraint + inter-plugin dependencies (composer constraint syntax). Unsatisfied plugins are quarantined (`PluginState::Failed` + reason); satisfied ones load in topological dependency order. |
| `Whity\Sdk\PluginFrontendInterface` | 1.2 | OPTIONAL declaration of the admin-UI screens a plugin contributes (frontend feature descriptors). UI metadata only — descriptors grant nothing; the host validates, permission-filters, and serves them via `GET /api/frontend/features`. |
| `Whity\Sdk\Tenant\TenantTableRegistry` | 1.3 | The portable, dependency-free model of a host's / plugin's tenant-owned and sanctioned-global tables, consumed by the scanner and linter. |
| `Whity\Sdk\Tenant\TenantPredicateScanner` | 1.3 | The tokenizer-based static scanner that flags any `SELECT`/`UPDATE`/`DELETE` on a tenant-owned table missing a `tenant_id` predicate (honours `@tenant-guard-ignore:` + the global allowlist). |
| `Whity\Sdk\Tenant\MigrationTenantColumnLinter` | 1.3 | Lints a plugin's `CREATE TABLE` migrations: every tenant table must declare a `tenant_id` column (or be a declared global / transitively-scoped exception). |
| `Whity\Sdk\Testing\TenantIsolationConformanceTestCase` | 1.3 | The shared PHPUnit base case a plugin extends to PROVE its tenant isolation: wires the linter + scanner + a RealEngine schema check. Requires `phpunit/phpunit` (dev-only `suggest`). |

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

Breaking contract changes require a new major. A plugin declares the range it
supports via `getSdkConstraint()` (e.g. `'^1.1'`) and the host refuses to load
it when its own `Sdk::VERSION` falls outside that range — with the reason
visible in the admin plugin list.

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
3. **RealEngine** — your migrations are applied to a real SQL engine (in-memory
   SQLite by default; override `makePdo()` to point at Postgres in CI) and each
   declared tenant table is asserted to physically carry `tenant_id`.

You can also run the linter / scanner directly (no PHPUnit) in a CI script —
see `scripts/ci-plugin-tenant-conformance.php` in whity-core for the pattern.
PHPUnit is a **dev-only** requirement (`suggest`); the runtime SDK still
depends on nothing but PHP.

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
