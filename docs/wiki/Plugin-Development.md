# Plugin Development

This is a step-by-step tutorial for building a complete Whity Core plugin from
scratch. By the end you will have a working plugin, `HelloWorld`, that:

- exposes a public endpoint `GET /api/hello`,
- exposes an admin-only endpoint `GET /api/hello/admin`,
- declares permissions in the mandated `resource:action` notation,
- runs custom logic **before a user is created** via the real `user.creating`
  hook, and
- ships a database migration.

The finished reference implementation lives in
[`plugins/HelloWorld/`](../../plugins/HelloWorld) and its test in
[`tests/Plugins/HelloWorldPluginTest.php`](../../tests/Plugins/HelloWorldPluginTest.php).
Every code sample below is copy-paste accurate against the real
[`PluginInterface`](../../sdk/src/PluginInterface.php).

For the bigger picture of how plugins fit into the framework (the "Plugins, Not
Forks" principle and the request runtime flow), see
[Architecture.md](./Architecture.md). For the hook event catalogue see
[HOOK_SYSTEM.md](./HOOK_SYSTEM.md); for permissions see
[PERMISSION_SYSTEM.md](./PERMISSION_SYSTEM.md).

---

## How plugins work (the 60-second version)

A plugin is a PHP class that implements
[`Whity\Sdk\PluginInterface`](../../sdk/src/PluginInterface.php) — the contract
from the standalone [`whity/plugin-sdk`](../../sdk/README.md) package (WC-162).
A plugin depends ONLY on the SDK, never on whity-core, which is what makes it
distributable across Whity-based applications. At startup (and on a hot
reload), [`PluginLoader`](../../src/Core/PluginLoader.php) scans the `plugins/`
directory, uses reflection to find every class that implements the interface,
instantiates it, and registers its capabilities:

- **routes** go into the `Router`,
- **permissions** go into the `PermissionRegistry`,
- **hooks** are subscribed on the `HookManager`,
- **migrations** are returned for the migration runner.

There is **no manual registration step** — dropping a well-formed plugin into
`plugins/` is enough for the loader to discover it.

A plugin MAY additionally implement
[`Whity\Sdk\PluginRequirementsInterface`](../../sdk/src/PluginRequirementsInterface.php)
(SDK 1.1, WC-165) to declare a required SDK constraint, a host CORE-version
constraint (`getCoreConstraint(): '^0.1'`, SDK 1.4 / WC-211), and inter-plugin
dependencies in composer constraint syntax (`getSdkConstraint(): '^1.1'`,
`getPluginDependencies(): ['HelloWorld' => '^1.0']`). The loader evaluates
these with composer/semver against `Whity\Sdk\Sdk::VERSION`,
`Whity\Core\CoreVersion::VERSION`, and the other plugins' versions: satisfied
plugins load in **topological dependency order**;
unsatisfied ones are **quarantined** (`failed` state, no routes/permissions/
hooks registered) with the reason visible in `GET /api/plugins`. Plugins that
declare nothing keep loading exactly as before. See the
[SDK README](../../sdk/README.md) for the versioning policy (1.0 → 1.1 → 1.2,
additive minors).

The interface is small and explicit. These are the exact signatures you must
implement:

```php
public function getName(): string;
public function getVersion(): string;
public function getRoutes(): array;
public function getPermissions(): array;
public function getHooks(): array;
public function getMigrations(): array;
```

---

## Step 1 — Scaffold the plugin directory and namespace

Plugins live under `plugins/`. There are two supported layouts:

1. **Single file** directly under `plugins/` (e.g. `plugins/ExamplePlugin.php`).
   The loader maps it to the `Whity\Plugins\` namespace.
2. **Directory** under `plugins/` (e.g. `plugins/HelloWorld/`). The loader maps
   the **directory name to the namespace prefix**. A file at
   `plugins/HelloWorld/HelloWorldPlugin.php` therefore declares the class
   `HelloWorld\HelloWorldPlugin`, and `plugins/HelloWorld/Migrations/Foo.php`
   declares `HelloWorld\Migrations\Foo`.

We will use the directory layout because real plugins usually need more than one
file. Create the structure:

```
plugins/HelloWorld/
├─ HelloWorldPlugin.php              ← implements PluginInterface
└─ Migrations/
   └─ CreateHelloGreetingsTable.php  ← optional schema migration
```

> **Namespace rule.** Because the loader derives the namespace from the
> directory name, the namespace of every class in `plugins/HelloWorld/` must
> start with `HelloWorld\`. This is how
> [`PluginLoader::resolveClassFromFile()`](../../src/Core/PluginLoader.php)
> resolves the fully-qualified class name, and how its dynamic PSR-4 autoloader
> finds the file.

---

## Step 2 — Implement `PluginInterface`

Create `plugins/HelloWorld/HelloWorldPlugin.php`:

```php
<?php

declare(strict_types=1);

namespace HelloWorld;

use HelloWorld\Migrations\CreateHelloGreetingsTable;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;

final class HelloWorldPlugin implements PluginInterface
{
    public function getName(): string
    {
        return 'HelloWorld';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/hello',
                'handler' => [$this, 'hello'],
                'requiredRole' => null,
            ],
            [
                'method' => 'GET',
                'path' => '/api/hello/admin',
                'handler' => [$this, 'adminHello'],
                'requiredRole' => 'admin',
            ],
        ];
    }

    public function getPermissions(): array
    {
        return [
            'hello:view',
            'hello:manage',
        ];
    }

    public function getHooks(): array
    {
        return [
            Events::USER_CREATING => [
                'callback' => [$this, 'onUserCreating'],
                'priority' => 10,
            ],
        ];
    }

    public function getMigrations(): array
    {
        return [
            CreateHelloGreetingsTable::class,
        ];
    }

    public function hello(Request $request): Response
    {
        return Response::json([
            'message' => 'Hello, World!',
            'plugin' => $this->getName(),
            'version' => $this->getVersion(),
        ]);
    }

    public function adminHello(Request $request): Response
    {
        return Response::json([
            'message' => 'Hello, administrator!',
            'plugin' => $this->getName(),
        ]);
    }

    public function onUserCreating(array $data, array $context): array
    {
        if (isset($data['email']) && is_string($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }

        $data['hello_world_greeted'] = true;

        return $data;
    }
}
```

The full reference file (with PHPDoc on every method) is
[`plugins/HelloWorld/HelloWorldPlugin.php`](../../plugins/HelloWorld/HelloWorldPlugin.php).

### Routes

`getRoutes()` returns a list of associative arrays. Each route has:

| Key            | Type      | Notes                                                                     |
| -------------- | --------- | ------------------------------------------------------------------------- |
| `method`       | `string`  | HTTP method, e.g. `GET`, `POST`.                                          |
| `path`         | `string`  | Request path, e.g. `/api/hello`.                                          |
| `handler`      | `callable`| `function(Request $request): Response`. A `[$this, 'method']` pair works. |
| `requiredRole` | `?string` | Optional. `null` = public; `'admin'` = only the `admin` role.             |

Handlers receive a [`Request`](../../src/Core/Request.php) and **must return** a
[`Response`](../../src/Core/Response.php). Use `Response::json($data)` for JSON
and `Response::error($message, $status)` for errors. A handler that throws, or
returns something other than a `Response`, is caught by the loader's per-plugin
error boundary and turned into a safe `500` — it cannot crash the host or other
plugins.

### Permissions (`resource:action` colon notation)

`getPermissions()` returns plain permission strings. Whity Core standardises on
**`resource:action` colon notation**, validated against
`/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/` (see
[`PermissionRegistry`](../../src/Core/RBAC/PermissionRegistry.php) and
[PERMISSION_SYSTEM.md](./PERMISSION_SYSTEM.md)). Our plugin declares:

```php
'hello:view'    // resource "hello", action "view"
'hello:manage'  // resource "hello", action "manage"
```

> **Do not use dot notation** (`hello.view`). Dots are the legacy core format
> that migration `016_normalize_permission_notation` reconciles to colons.
> Plugin permissions should be colon-notation from day one.

Declared permissions are recorded in the `PermissionRegistry` under your plugin
name as the source. An administrator then assigns them to roles
(see [PERMISSION_SYSTEM.md](./PERMISSION_SYSTEM.md) for the assignment flow).

#### Checking a permission INSIDE a handler (SDK 1.16)

A route's `requiredPermission` is a flat gate: one question, answered once,
before your handler runs. When you need a second decision *inside* the handler —
"may this caller see archived rows?", "may they act on **this** record?" — ask
the host. Do **not** re-derive it in SQL: real resolution gates on active
membership, walks the OU ancestor chain and the role hierarchy, unions live
delegations, and validates the slug against the registry, so any hand-rolled
version drifts from what is actually enforced and your plugin ends up
disagreeing with the platform about the same caller.

Resolve the host's read-only resolver from the service container:

```php
use Whity\Sdk\Rbac\PermissionResolver;

public function archive(Request $request, array $params = []): Response
{
    $tenantId = TenantContext::getTenantId();
    $actor    = $request->user;
    $profileId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
        ? $actor->profile_id
        : null;

    if ($tenantId === null || $profileId === null) {
        return Response::error('Authentication required', 403); // fail closed
    }

    $rbac = \Whity\app(PermissionResolver::class);
    if (!$rbac->hasPermission($profileId, $tenantId, 'hello:manage')) {
        return Response::error('Insufficient permissions', 403);
    }

    // …
}
```

`hasRole()` and `effectivePermissions()` are also available;
`effectivePermissions()` is exactly the set `hasPermission()` returns `true` for,
so filtering a result set in one pass can never disagree with a per-row check.

All three take an optional `$resourceType` / `$resourceId` pair, so the question
can be narrowed to **one record** instead of the whole tenant — `hasPermission()`
and `effectivePermissions()` since SDK 1.17, `hasRole()` since 1.22:

```php
if (!$rbac->hasRole($profileId, $tenantId, 'approver', 'hello:document', $docId)) {
    return Response::error('Insufficient permissions', 403);
}
```

Pass both or neither (a half-specified resource is not a resource, and collapses
to the tenant-wide answer), declare the type via `PluginResourceTypesInterface`,
and note that a record grant only ever **widens** authority — it is never a
substitute for tenant membership. Per-record role holding therefore needs no
parallel grant table of your own, and no change to core's `memberships`.
The contract is read-only — no cache invalidation, no database handle — and it
grants no authority your plugin does not already have. `\Whity\app()` throws a
`RuntimeException` if the host never registered a resolver, so an unwired host
fails closed rather than silently allowing.

The same holds for host state you resolve by class name. `\Whity\app()`
auto-instantiates only a concrete class that takes **no constructor parameters
at all** and has not declared itself
`Whity\Core\Container\HostWiredService`. Registries carry that marker, so a
missing host registration throws instead of handing you an empty catalogue —
worth knowing, because an empty registry answers every question with a
plausible "no" and your plugin would deny access for a permission it had itself
declared. If your own plugin ships a registry that is only meaningful once
filled at boot, implement the marker on it too.

### Hooks

`getHooks()` maps an **event name** to a subscription. A subscription may be:

- a bare callable: `'event' => [$this, 'method']`, or
- a structured array: `'event' => ['callback' => [$this, 'method'], 'priority' => 10]`, or
- a list of either of the above (to register several listeners on one event).

Lower `priority` numbers run first (default `10`). See the
[Hooks section](#step-4--add-a-hook-that-runs-before-user-creation) below for
the full walkthrough.

### Migrations

`getMigrations()` returns an array of migration **class names (FQCNs)**. See
[Step 5](#step-5--ship-a-migration).

---

## Step 3 — Discovery and auto-loading

You do not register the plugin anywhere. When the application boots,
[`PluginLoader::load()`](../../src/Core/PluginLoader.php) does the work:

1. **Namespace mapping.** For every direct subdirectory of `plugins/`, the
   loader registers a dynamic PSR-4 mapping (`HelloWorld\` → that directory) so
   classes resolve without touching `composer.json`.
2. **Discovery.** It scans recursively, `require`s each PHP file, and uses
   reflection (`ReflectionClass::implementsInterface(PluginInterface::class)`)
   to keep only real plugin classes. Anything that does not implement the
   interface is skipped with a logged warning.
3. **Registration.** Each plugin is instantiated and its routes, permissions,
   and hooks are wired into the core services.

Because resolution is directory-driven, the **only requirement** for discovery
is that your class lives at the right path with the matching namespace and
implements the interface. `HelloWorld\HelloWorldPlugin` at
`plugins/HelloWorld/HelloWorldPlugin.php` satisfies this.

> **Static analysis tip.** Plugins are autoloaded at runtime by the
> `PluginLoader`, not by Composer, so static tools cannot see them by default.
> This repo's [`phpstan.neon`](../../phpstan.neon) adds `scanDirectories:
> [plugins]` so PHPStan can resolve plugin symbols referenced from tests. You do
> not need to add anything to `composer.json` for the plugin to run.

### Hot reload

On FrankenPHP persistent workers a single `PluginLoader` survives many requests.
[`PluginLoader::reload()`](../../src/Core/PluginLoader.php) fingerprints the
plugin tree (mtime + size) and, when it changes, unregisters the old
capabilities and re-registers from disk. **Adding** or **removing** a plugin is
picked up in-process on the next reload without restarting the worker.

**Editing** an already-loaded plugin is different: a PHP class cannot be
redefined inside a live worker. So in development `reload()` detects the content
change, invalidates the file's opcache entry, and requests a **worker recycle**;
the worker finishes the current request on the old code, then breaks the loop so
FrankenPHP respawns a fresh worker that recompiles the new source (WC-212).
Outside development a changed-on-disk plugin never starts executing without a
deploy/restart.

---

## Step 4 — Add a hook that runs before user creation

Whity Core dispatches lifecycle hooks at well-defined points. The one that runs
**immediately before a user is inserted** is the real, currently-dispatched
event **`user.creating`**, fired by
[`UsersApiHandler`](../../src/Api/UsersApiHandler.php):

```php
// src/Api/UsersApiHandler.php (core)
$userData = $this->hookManager->dispatch('user.creating', [
    'email' => $email,
    'password' => $body['password'], // plaintext, pre-hash
    'role_id' => $roleId,
]);

// the core reads the (possibly modified) payload back out
$email    = $userData['email'];
$roleId   = $userData['role_id'];
$password = password_hash($userData['password'], PASSWORD_BCRYPT);
```

`user.creating` is a **synchronous filter** hook: every listener receives the
payload and the execution context, and must **return the (possibly modified)
array** so downstream listeners and the core see the change. Our plugin
subscribes to it:

```php
public function getHooks(): array
{
    return [
        'user.creating' => [
            'callback' => [$this, 'onUserCreating'],
            'priority' => 10,
        ],
    ];
}

public function onUserCreating(array $data, array $context): array
{
    // Normalise the email before the user is persisted.
    if (isset($data['email']) && is_string($data['email'])) {
        $data['email'] = strtolower(trim($data['email']));
    }

    // Stamp the payload so the effect is observable.
    $data['hello_world_greeted'] = true;

    return $data; // ALWAYS return the payload from a sync hook.
}
```

Key points (all enforced by the real
[`HookManager`](../../src/Core/Hooks/HookManager.php)):

- **Signature:** `function(array $data, array $context): array`. `$context`
  carries `tenant_id` and `timestamp`, injected automatically — use it for
  tenant-safe logic.
- **Return the array.** A sync hook that returns a non-array leaves the payload
  unchanged; returning the modified array is how you participate in the filter.
- **Priority** controls ordering when multiple listeners share an event (lower
  runs first; default `10`).
- **Payloads are scalar-only.** Pass strings/ints/bools, not objects.

> **Other lifecycle events.** `user.created` (sync, after insert),
> `user.created.async` (queued), and the equivalent `role.*`, `tenant.*`, and
> `ou.*` events are all dispatched by the core API handlers. The full list is in
> [HOOK_SYSTEM.md](./HOOK_SYSTEM.md#lifecycle-when-hooks-fire). If you need a
> hook point that the core does not yet dispatch, that dispatch call must be
> added in core first — do not subscribe to an event name that is never fired.

---

## Step 5 — Ship a migration

`getMigrations()` returns migration class FQCNs. A migration implements the
SDK contract [`Whity\Sdk\MigrationInterface`](../../sdk/src/MigrationInterface.php)
(WC-162): instance `up()` and `down()` methods that each receive a live `\PDO`
connection — so the migration, like the rest of the plugin, depends only on
the SDK.

> **Executed by the runner (WC-164):** `php public/index.php migrate run`
> collects every plugin's declared migrations and executes the pending ones
> after the core migrations — each inside an explicit transaction, recorded in
> `core_schema_migrations` under the per-plugin namespace
> `plugin:<PluginName>:<MigrationClass>`. `migrate status` lists them and
> `migrate rollback` runs your `down()`. Keep statements idempotent
> (`IF NOT EXISTS` / `IF EXISTS`): re-runs and adopting hand-created schema
> are then safe by construction.

Create `plugins/HelloWorld/Migrations/CreateHelloGreetingsTable.php`:

```php
<?php

declare(strict_types=1);

namespace HelloWorld\Migrations;

use Whity\Sdk\MigrationInterface;

final class CreateHelloGreetingsTable implements MigrationInterface
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS hello_greetings (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                message VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_hello_greetings_tenant_id ON hello_greetings(tenant_id)'
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS hello_greetings');
    }
}
```

Keep statements idempotent (`IF NOT EXISTS` / `IF EXISTS`) so the migration is
safe to re-run, and always scope tenant data with a `tenant_id` column.

### Adding a column later — don't hand-write the driver branch

`CREATE TABLE IF NOT EXISTS` and `CREATE INDEX IF NOT EXISTS` parse on both
PostgreSQL and SQLite. `ALTER TABLE … ADD COLUMN IF NOT EXISTS` does **not** —
it is a PostgreSQL extension SQLite rejects. So the first time you add a column
to a table you already shipped, the idempotency rule above stops being free and
you need an existence check, which is dialect-specific.

Do not write that check. Use the SDK's
[`Whity\Sdk\Schema\MigrationSchema`](../../sdk/src/Schema/MigrationSchema.php)
trait (SDK 1.23):

```php
use Whity\Sdk\MigrationInterface;
use Whity\Sdk\Schema\MigrationSchema;

final class AddArchivedAtToAcmeItems implements MigrationInterface
{
    use MigrationSchema;

    public function up(\PDO $pdo): void
    {
        $this->addColumnIfMissing($pdo, 'acme_items', 'archived_at', 'TIMESTAMP NULL');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropColumnIfExists($pdo, 'acme_items', 'archived_at');
    }
}
```

`addColumnIfMissing()` / `dropColumnIfExists()` state the shape you want and
leave no branch at the call site. The predicates behind them are available too,
with the same signatures the hand-written versions usually have, so adopting the
trait is deleting a private method and adding a `use` line:

| Method | Answers |
| --- | --- |
| `tableExists($pdo, $table)` | Is there a base table of this name? (Views are not tables.) |
| `columnExists($pdo, $table, $column)` | Does the table have this column? (`false` if the table is absent.) |
| `indexExists($pdo, $index)` | Is there an index of this name? |
| `tableColumns($pdo, $table)` | The table's columns, lowercased, in declaration order. |
| `addColumnIfMissing($pdo, $table, $column, $definition)` | Adds it if absent; returns whether it added. |
| `dropColumnIfExists($pdo, $table, $column)` | Drops it if present; returns whether it dropped. |

Not writing this yourself buys more than the keystrokes. The usual hand-written
PostgreSQL query filters on `table_name` alone, with no schema predicate, so it
answers for a same-named table in **any** schema; and `information_schema` is
privilege-filtered, so a table your role cannot see reads as absent and the
migration tries to create it again. The SDK version reads `pg_catalog` and
confines every lookup to the connection's own search path. Both engines answer
case-insensitively, so your constants' casing cannot change the answer per
engine.

If you are not inside a migration instance — a repair command, a test — call
[`Whity\Sdk\Schema\SchemaInspector`](../../sdk/src/Schema/SchemaInspector.php)
statically; the trait is a thin forward to it. Identifiers are validated
(`[A-Za-z_][A-Za-z0-9_]*`, ≤ 63 chars) because they cannot be bound as
parameters; `$definition` is raw DDL you author and is never a place for a
runtime value. An unsupported driver is refused loudly rather than guessed at.

---

## Step 5b — Writes: upserts and numbering

### Upserts — let the tenant key be added for you

`INSERT … ON CONFLICT … DO UPDATE … RETURNING` is the statement plugins write
most, and the one with the most expensive way to get it slightly wrong. Writing
`ON CONFLICT (client_uuid)` where you meant `ON CONFLICT (tenant_id,
client_uuid)` stops the upsert being a per-tenant operation: another tenant's
insert finds **your** row, takes the `DO UPDATE` branch, and overwrites it. The
unique index will not object if it does not lead with `tenant_id`, and the
tenant-predicate scanner will not either — the statement *does* mention
`tenant_id`, in the value list.

[`Whity\Sdk\Sql\Upsert`](../../sdk/src/Sql/Upsert.php) (SDK 1.24) takes the
tenant id as its own required argument, writes it into the inserted columns
**and** prepends it to the conflict target, so the unscoped form cannot be
expressed:

```php
use Whity\Sdk\Sql\Upsert;

$row = Upsert::tenantScoped(
    $pdo,
    'acme_items',
    $tenantId,
    ['client_uuid' => $uuid, 'name' => $name, 'status' => 'active'],
    ['client_uuid'],       // tenant_id is prepended for you
    ['name', 'status'],    // what a conflict overwrites; null = everything above
    ['id', 'version']      // RETURNING; ['*'] by default, [] to omit
);
```

- An **empty** update list means `DO NOTHING`. When the conflict then fires,
  both engines return **no row**, so `null` means "already there" — not
  "failed". Read that twice; it is the trap.
- For a table with no tenant column (a declared-global counter or catalogue),
  use `Upsert::unscoped()`. The name is the declaration, and unlike an omission
  a reviewer can grep for it.
- Nothing is hidden: `Upsert::buildSql()` returns the exact statement, so you can
  log it, assert on it, or paste it into `psql`.
- Your unique index must actually lead with the tenant column, or the engine
  will refuse the conflict target. That is the schema telling you the truth.

### Numbering — don't build a counter, ask for a number

Do **not** write this:

```php
$current = /* SELECT value FROM my_counters WHERE name = 'invoice' */;
/* UPDATE my_counters SET value = :next … */
return $current + 1;
```

Two clients read `3`. Two clients write `4`. Two documents whose entire purpose
is to be uniquely numbered come out numbered the same, and nothing errors.

The host allocates numbers for you, so there is no table to migrate and no SQL
to get wrong. Resolve
[`Whity\Sdk\Sql\SequenceAllocator`](../../sdk/src/Sql/SequenceAllocator.php)
from the container:

```php
$sequences = \Whity\app(\Whity\Sdk\Sql\SequenceAllocator::class);

$number = $sequences->next($tenantId, 'invoice');          // 1, then 2, then 3 …
$block  = $sequences->nextBlock($tenantId, 'import', 50);  // ['first' => 4, 'last' => 53]
$now    = $sequences->peek($tenantId, 'invoice');          // read without allocating
$cursor = $sequences->nextPlatformWide('acme:change_seq'); // one series for the whole instance
```

Counters are keyed per tenant **and** per name, and are created on first use.
Name them with your plugin's prefix (`acme:invoice`) if a collision with another
plugin's `invoice` would matter to you.

**Guaranteed:** no two successful calls for the same `(tenant, name)` ever
return the same number, under any concurrency, on PostgreSQL and on SQLite.

**Not guaranteed:** gaplessness. Allocation joins your transaction, so a
rollback releases the number — and a concurrent caller that already took the
next one leaves a hole. Unique, monotonic, may skip. If you need a legally
gapless series, that is a domain problem solved with a compensating record, not
an allocation problem.

`peek() + 1` is not a way to get the next number. That is the bug again.

---

## Step 6 — Test the plugin

Plugin tests live under `tests/` and run in the standard PHPUnit suite. Because
plugin classes are not in Composer's PSR-4 map, `require_once` the plugin file
at the top of the test so PHPUnit can instantiate the class directly.

A focused test that mirrors the reference test
([`tests/Plugins/HelloWorldPluginTest.php`](../../tests/Plugins/HelloWorldPluginTest.php)):

```php
<?php

declare(strict_types=1);

namespace Tests\Plugins;

use HelloWorld\HelloWorldPlugin;
use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Sdk\Http\Request;
use Whity\Sdk\PluginInterface;

require_once dirname(__DIR__, 2) . '/plugins/HelloWorld/HelloWorldPlugin.php';

final class HelloWorldPluginTest extends TestCase
{
    public function testImplementsInterfaceAndExposesPublicRoute(): void
    {
        $plugin = new HelloWorldPlugin();
        $this->assertInstanceOf(PluginInterface::class, $plugin);

        $routes = $plugin->getRoutes();
        $this->assertSame('GET', $routes[0]['method']);
        $this->assertSame('/api/hello', $routes[0]['path']);
    }

    public function testUserCreatingHookNormalisesEmail(): void
    {
        $result = (new HelloWorldPlugin())->onUserCreating(
            ['email' => '  Alice@Example.COM ', 'password' => 'secret', 'role_id' => 2],
            ['tenant_id' => 1, 'timestamp' => time()]
        );

        $this->assertSame('alice@example.com', $result['email']);
        $this->assertTrue($result['hello_world_greeted']);
    }

    public function testLoaderDiscoversPluginAndRegistersRoute(): void
    {
        // Point the loader at the real plugins/ dir; assert only on HelloWorld
        // so the test tolerates other plugins being present.
        $loader = new PluginLoader(
            dirname(__DIR__, 2) . '/plugins',
            $router = new Router(),
            $permissions = new PermissionRegistry(),
            $hooks = new HookManager()
        );
        $loader->load();

        $this->assertNotNull($router->match(new Request('GET', '/api/hello')));
        $this->assertTrue($permissions->exists('hello:view'));
        $this->assertNotEmpty($hooks->getListeners('user.creating'));
    }
}
```

Run the suite (Docker, no native PHP required):

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli php vendor/bin/phpunit
```

Static analysis:

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli \
    php -d memory_limit=512M vendor/bin/phpstan analyse src tests
```

The reference test verifies the interface contract, both routes, colon-notation
permissions, the migration registration, the `hello()` handler response, and
the `user.creating` hook both directly and via `HookManager::dispatch()`.

---

## Step 7 — Enable / disable via the plugin management API

Plugins are administered through the `/api/plugins` surface
([`PluginsApiHandler`](../../src/Api/PluginsApiHandler.php), wired in
[`public/index.php`](../../public/index.php)). Each endpoint is gated by its OWN
per-action permission (WC-218); the constants live on
[`CorePermissions`](../../src/Core/RBAC/CorePermissions.php). The seeded `admin`
role holds all six out of the box (migration 013).

| Method & path                       | Required permission  | Action                                               |
| ----------------------------------- | -------------------- | ---------------------------------------------------- |
| `GET  /api/plugins`                 | `plugins:read`       | List plugins with name, version, status, and counts. |
| `POST /api/plugins/{name}/enable`   | `plugins:enable`     | Enable a plugin by name.                             |
| `POST /api/plugins/{name}/disable`  | `plugins:disable`    | Disable a plugin (unregisters its routes & hooks).   |
| `POST /api/plugins/{id}/re-enable`  | `plugins:enable`     | Re-enable a disabled plugin by id.                   |
| `POST /api/plugins/{id}/uninstall`  | `plugins:uninstall`  | Uninstall a plugin (disable, roll back, remove).     |
| `POST /api/plugins/reload`          | `plugins:reload`     | Reload plugins from disk.                            |

> `plugins:upload` is also defined and seeded; its upload route lands in a later slice.

List the plugins (the bearer token must belong to a role granted
`plugins:read`):

```bash
curl -H "Authorization: Bearer <admin-token>" \
     http://localhost/api/plugins
```

Disable, then re-enable HelloWorld:

```bash
curl -X POST -H "Authorization: Bearer <admin-token>" \
     http://localhost/api/plugins/HelloWorld/disable

curl -X POST -H "Authorization: Bearer <admin-token>" \
     http://localhost/api/plugins/HelloWorld/enable
```

Disabling unregisters the plugin's routes (so `GET /api/hello` stops matching)
and removes its hook subscriptions; re-enabling restores them from the retained
instance without a disk reload. The loader also tracks a per-plugin lifecycle
(`Loaded` → `Active` → `Disabled`/`Failed`) that the list endpoint reports.

This lifecycle state is **per-worker**, but the disable/enable change is
**persisted to disk** (a `.php.disabled` rename for single-file plugins, a
`.disabled` sentinel file for directory plugins) so every FrankenPHP worker
converges on the same state on its next reload or restart. For the full
cross-worker propagation model and the operator restart contract, see
[Plugin-Operations.md](./Plugin-Operations.md).

---

## Step 8 — Contribute an admin screen (frontend feature descriptors, SDK 1.2)

Since SDK 1.2 (WC-169) a plugin can declare admin-UI screens the host renders
**with zero per-app frontend code**. Implement the optional sibling interface
`Whity\Sdk\PluginFrontendInterface` next to `PluginInterface`:

```php
use Whity\Sdk\PluginFrontendInterface;

final class HelloWorldPlugin implements PluginInterface, PluginRequirementsInterface, PluginFrontendInterface
{
    public function getFrontendFeatures(): array
    {
        return [[
            'id' => 'hello-greetings',          // unique kebab-case slug
            'label' => 'Greetings',             // menu / screen title
            'icon' => 'message-circle',         // tabler icon (optional)
            'group' => 'plugins',               // nav group (optional)
            'order' => 10,                      // nav order (optional)
            'screen' => 'crud',                 // 'crud' | 'custom'
            'resource' => [
                'basePath' => '/api/hello/greetings',
                'titleField' => 'message',      // names a row in confirmations
            ],
            'requiredPermission' => 'hello:view',
        ]];
    }
}
```

What the host does with it:

- **Navigation**: every validated descriptor gets a sidebar entry automatically
  (`/admin/x/{id}`) via the `navigation.register` chain — `navigation.register`
  itself remains available for bespoke links.
- **`screen: 'crud'`**: the host renders a schema-driven list/create/edit/delete
  screen for `resource.basePath`, derived at runtime from the published
  OpenAPI spec (declare route `schema`s — see Step 2 — or the screen has
  nothing to derive). Columns, form fields, required flags, enum selects and
  max lengths all come from your declared components.
- **`screen: 'custom'`**: the host app registers a bespoke component for your
  id in its UI registry (`web/lib/plugin-ui-registry.tsx`):
  `registerPluginScreen('my-feature', MyScreen)` in a single app-level file.
  A registered component also OVERRIDES a `crud` screen — that is the
  documented per-app override slot for bespoke UIs (e.g. graph views).

Security model (all fail-closed, validated at load; an invalid descriptor is
dropped with a logged warning and the plugin still loads):

- `requiredPermission` must be a permission the plugin genuinely OWNS: declared
  in its own `getPermissions()`, not a core permission name, and not a name an
  earlier-loaded plugin declared first. Descriptors are UI metadata — they
  grant nothing.
- `GET /api/frontend/features` is the host's descriptor surface and only
  returns features whose `requiredPermission` the **caller** holds
  (server-side `RoleChecker`, fail-closed on unresolved tenant).
- For `crud` screens, `resource.basePath` must be a GET route the plugin
  **actually registered** (a route refused for colliding with a core path
  does not count — first registration wins, plugins load after core), and
  that route's own `requiredPermission` must EQUAL the descriptor's, so the
  menu gate and the data gate can never diverge.
- Route-level `requiredPermission` on plugin routes is enforced by the host's
  RBAC middleware since SDK 1.2; a malformed declaration means the route is
  NOT registered (never served unprotected).
- Grants are persisted RBAC rows: ship a migration that seeds your permissions
  and grants them (see `plugins/HelloWorld/Migrations/GrantGreetingsPermissionsToAdmin.php`
  for the idempotent, reversible pattern).

The full working reference is the HelloWorld plugin: greetings CRUD routes with
typed schemas, the descriptor above, and both migrations.

---

## Step 9 — Expose plugin routes as MCP tools

Plugin routes become MCP tools automatically. No extra interface needs to be
implemented.

[`ToolDeriver`](../../src/Mcp/Tools/ToolDeriver.php) reads the live `Router`
at `tools/list` call time (not at construction), so any route your plugin
registers in `getRoutes()` is picked up without any additional work on your
part. The only requirement is that the route carries a non-empty `schema` array
— routes without a `schema` are silently skipped.

### Tool names (operationId)

The tool name presented to AI clients is taken from `schema['operationId']`
when that key is a non-empty string. When it is absent, a name is derived
automatically:

```text
toolName = strtolower(method) . '_' . slug(path)
```

where `slug` replaces non-alphanumeric characters with underscores. Set an
explicit `operationId` to give AI clients a stable, human-readable name:

```php
public function getRoutes(): array
{
    return [
        [
            'method'  => 'GET',
            'path'    => '/api/hello/greetings',
            'handler' => [$this, 'listGreetings'],
            'schema'  => [
                'operationId' => 'listGreetings',         // stable tool name
                'summary'     => 'List greetings',        // tool description
                'parameters'  => [
                    [
                        'name'        => 'page',
                        'in'          => 'query',
                        'required'    => false,
                        'description' => 'Page number',
                        'schema'      => ['type' => 'integer'],
                    ],
                ],
            ],
        ],
        [
            'method'  => 'POST',
            'path'    => '/api/hello/greetings',
            'handler' => [$this, 'createGreeting'],
            'schema'  => [
                'operationId' => 'createGreeting',
                'summary'     => 'Create a greeting',
                'request'     => 'GreetingCreateRequest',  // component name
                'components'  => [
                    'GreetingCreateRequest' => [
                        'type'       => 'object',
                        'properties' => [
                            'message' => ['type' => 'string', 'description' => 'Greeting text'],
                        ],
                        'required'   => ['message'],
                    ],
                ],
            ],
        ],
    ];
}
```

### inputSchema construction

`ToolDeriver` builds the `inputSchema` that AI clients receive by merging:

1. **Path parameters** — extracted automatically from `{name}` or
   `{name:constraint}` segments. Always required.
2. **Query parameters** — declared in `schema['parameters']` with
   `in: query`. Include `required`, `description`, and `schema` on each entry.
3. **Request body** — declared in `schema['request']` as either a component
   name (string, resolved from `schema['components']` on the route or from
   the global components map) or an inline schema array.

For `POST`, `PUT`, and `PATCH` routes, `ToolDeriver` emits a lint warning via
`error_log()` when no request body schema can be resolved. The tool is still
derived, but AI clients receive no parameter guidance for body fields.

### RBAC on MCP tools

`requiredRole` and `requiredPermission` on your route declaration flow through
to MCP automatically:

- **`tools/list` filtering** — protected tools are hidden from callers who
  lack the required grant.
- **`tools/call` enforcement** — `ToolsCallHandler` re-checks the grant via
  `RoleChecker` before invoking the handler, using the same component as the
  HTTP `RbacMiddleware`. MCP and HTTP authorization are always in sync.

```php
[
    'method'              => 'DELETE',
    'path'                => '/api/hello/greetings/{id:\d+}',
    'handler'             => [$this, 'deleteGreeting'],
    'requiredPermission'  => 'hello:manage',
    'schema'              => [
        'operationId' => 'deleteGreeting',
        'summary'     => 'Delete a greeting',
    ],
],
```

An AI client without `hello:manage` will not see `deleteGreeting` in
`tools/list` and will receive a `FORBIDDEN` error if it tries to call it
directly.

### Clearing the worker-boot cache

`ToolDeriver` caches the merged declarations list, tool list, and access map
in static properties for the lifetime of the FrankenPHP worker process
(WC-951d99d3). If your plugin registers routes **after** the cache has already
been populated (for example, during a hot reload), call
`ToolDeriver::clearCache()` to ensure the next `tools/list` or `tools/call`
request picks up the fresh tool list:

```php
use Whity\Mcp\Tools\ToolDeriver;

// After registering plugin routes:
ToolDeriver::clearCache();
```

`PluginLoader` calls this for you during `load()` and `reload()`, so most
plugins never need to call it directly. It is relevant when you register routes
programmatically outside the normal plugin lifecycle.

For the full MCP server architecture, authentication flow, and operator
guidance see [MCP-Server.md](./MCP-Server.md).

---

## Step 10 — Run work in the background (async jobs, SDK 1.28)

Implement [`PluginJobsInterface`](../../sdk/src/PluginJobsInterface.php) to
contribute [`JobInterface`](../../sdk/src/JobInterface.php) handlers to the
host's job registry. The host's own `queue:work` worker discovers them and runs
them beside the core handlers — you do **not** ship a worker of your own.

```php
final class AcmePlugin implements PluginInterface, PluginJobsInterface
{
    /** @return array<string, \Whity\Sdk\JobInterface> */
    public function getJobs(): array
    {
        return [
            'catalog.sync' => new CatalogSyncJob(fn (): \PDO => $this->resolvePdo()),
        ];
    }

    /** @return list<string> */
    public function getSubmittableJobs(): array
    {
        return ['catalog.sync'];
    }
}
```

### Names are namespaced by the host

You declare a **bare** name; the host stamps your plugin's prefix onto it. A
plugin called `Acme` declaring `catalog.sync` is registered — and must be
**enqueued** — as `acme:catalog.sync`. Use
`JobRegistry::canonicalName($this->getName(), 'catalog.sync')` rather than
concatenating it yourself.

This is the same rule the host already applies to resource types, health probes
and settings keys, and it exists for the same two reasons: two plugins can both
declare `sync` without either one running the other's work, and no declaration
can produce a `core.`-prefixed name and take over `core.notifications.deliver`.
The prefix comes from your plugin **name**, which the loader supplies — you can
declare any name you like, but you cannot declare who said it. A declared name
containing `:` is refused for that reason.

A valid declared name is lowercase, starts with a letter, and continues with
letters, digits, underscores or dots: `sync`, `catalog.sync`.

### Submittability fails closed

A declared job is runnable by the worker but **not** reachable from
`POST /api/jobs` unless you list its bare name in `getSubmittableJobs()`. That
mirrors core, where internal handlers (notification delivery, error alerting)
are registered without the flag. Submitting still requires the caller's
`jobs:submit` permission, and the job runs under the **submitting** tenant — so
treat a submittable job's payload as caller-supplied input and validate it.
Listing a name you do not ship is an error, not a no-op.

### Dependencies reach the handler because you build it

Nothing is injected into `handle()` beyond the payload — the host cannot know
what your handler needs. You construct the handler in `getJobs()`, so resolve
what it needs from the service container exactly as a route handler does
(see `resolvePdo()` in Step 2). Pass anything connection-shaped as a **closure**:
handlers are built once at load time and reused for every job a persistent
worker runs, so a PDO captured in the constructor would outlive the host's own
connection recycling.

### What the host guarantees, and what you owe it

[`JobInterface`](../../sdk/src/JobInterface.php) is the full contract; the two
obligations that bite hardest:

- **Be idempotent.** Delivery is at-least-once. A worker killed after your side
  effect but before the completion write re-runs your handler.
- **Signal failure by throwing.** A return value is the job's result; an
  exception is a retry (then a dead-letter).

The host restores the enqueuing tenant's `TenantContext` before calling, so
scope your queries to it — and note that the tenant-isolation conformance kit
scans your `Jobs/` directory alongside `Api/`, because an unscoped query in a
background handler is a cross-tenant read with no request behind it.

### One bad plugin cannot stop the worker

The worker that runs your job also delivers core's notifications and error
alerts. A `getJobs()` that throws, or a malformed declaration, is caught: your
plugin contributes **no** jobs (logged, and recorded against your plugin's
lifecycle) and every other plugin — and core — keeps running. The refusal is
whole-declaration rather than per entry, because a half-registered plugin
silently dead-letters the jobs that did not make it.

The working reference is
[`GreetingDigestJob`](../../plugins/HelloWorld/Jobs/GreetingDigestJob.php) in
the HelloWorld plugin, enqueued as `helloworld:greeting_digest`.

---

## Step 11 — Put your own events in the audit trail (SDK 1.29)

The host's audit writer subscribes to a hardcoded map of **core** event names,
so before SDK 1.29 nothing your plugin did appeared in the one screen an
operator opens to answer "who did what". Implement
[`PluginEventsInterface`](../../sdk/src/PluginEventsInterface.php) to declare
which of your own events belong there; the host records them through exactly the
path core's own rows go through.

```php
use Whity\Sdk\Hooks\Events;

final class AcmePlugin implements PluginInterface, PluginEventsInterface
{
    /** @return array<string, array{targetType: string, idKey: string|null}> */
    public function getAuditedEvents(): array
    {
        return [
            'task.completed' => ['targetType' => 'task', 'idKey' => 'task_id'],
        ];
    }
}

// …wherever the task is actually completed:
$hooks->dispatch(Events::forPlugin($this->getName(), 'task.completed'), [
    'task_id'   => $taskId,
    'tenant_id' => $tenantId,
    'title'     => $title,   // kept as sanitised audit metadata
]);
```

That writes an `audit_log` row with action `acme:task.completed`, target type
`acme:task`, target id `$taskId`, and the actor and IP the request already
resolved.

### Declare bare, dispatch namespaced

You declare a **bare** name and the host stamps your prefix on **both halves** of
the record — the action and the target type. A `task` target type sitting beside
core's `user` and `role` would read, to an operator filtering the trail, as
something core did to a core record.

The host listens on the **namespaced** name, so that is what you dispatch. Build
it with `Events::forPlugin($this->getName(), 'task.completed')` rather than by
hand: the prefix is a slug of your plugin name (`Acme\Widgets\Plugin` prefixes as
`plugin`), and a name that is nearly right matches no listener and reports
nothing.

Listening on the bare name was considered and rejected. The hook manager tells a
listener nothing about who dispatched, so with two plugins declaring
`task.completed` a single dispatch by one of them would have written an audit row
for both — and a trail that records an event which did not happen is worse than
one that records nothing. Namespacing the trigger also means two plugins can both
dispatch `item.created` without running each other's listeners.

A declared name containing `:` is refused: that would be you writing your own
prefix. A valid one is lowercase, starts with a letter, and continues with
letters, digits, underscores or dots.

### `idKey` is required, including when it is `null`

`targetType` and `idKey` are both mandatory. `idKey` names the payload key
holding the affected record's id and becomes the row's `target_id`; for an event
with no single target, declare `'idKey' => null` explicitly. There is no default,
because `id` is right for core's payloads and wrong for most plugin ones, and
getting it wrong produces a row that names an action and points at nothing while
the write still succeeds.

At runtime the payload is treated as data, not contract: a missing or
non-numeric id records a null `target_id` rather than failing. Auditing must
never break the action it records.

### What the trail does with your payload

`tenant_id` in the payload decides which tenant owns the row (then the hook
context, then the system tenant). Every other scalar is kept as metadata, minus
the id key (already `target_id`) and minus anything matching the writer's
secret/PII filter — the same filter core's rows go through, so a `password`,
`token` or `secret` in your payload never reaches the table.

### One bad declaration costs only you

A `getAuditedEvents()` that throws, or a malformed declaration, is caught: your
plugin's events are simply not audited (logged, and recorded against your
plugin's lifecycle), and core's own auditing and every other plugin's are
untouched. The refusal is whole-declaration rather than per entry, because a
plugin with half its events audited ships a trail that *looks* complete.

Subscriptions are registered with your other hooks, so disabling your plugin
removes them and re-enabling it restores them.

The working reference is `getAuditedEvents()` in
[`HelloWorldPlugin`](../../plugins/HelloWorld/HelloWorldPlugin.php), which
audits `helloworld:greeting.created` when a greeting is created.

---

## Checklist

- [ ] Directory `plugins/HelloWorld/` with namespace prefix `HelloWorld\`.
- [ ] Class implements [`PluginInterface`](../../sdk/src/PluginInterface.php)
      exactly (`declare(strict_types=1)`, PSR-12, PHPDoc).
- [ ] Routes return `Response` objects; public route is `GET /api/hello`.
- [ ] Permissions use `resource:action` colon notation.
- [ ] Hooks subscribe only to events the core actually dispatches
      (e.g. `user.creating`) and return the payload.
- [ ] Migrations are idempotent and tenant-scoped. Adding a column to a table
      you already shipped uses `MigrationSchema::addColumnIfMissing()`, not a
      hand-written driver branch (Step 5).
- [ ] Every reference is either enforced by a `FOREIGN KEY` or declared in the
      owning data type's `blocks_delete` / `cascade_delete` —
      `php scripts/ci-undeclared-reference-guard.php path/to/YourPlugin` is
      clean. (This does **not** ask you to add foreign keys; it asks you not to
      have relationships core cannot see.)
- [ ] A test under `tests/` exercises the plugin; full suite + PHPStan are green.
- [ ] (Optional) Frontend feature descriptors validate: own permission, own
      registered GET `basePath`, matching route permission (Step 8).
- [ ] (Optional) MCP-exposed routes carry `operationId`, `summary`, and a
      resolved request schema so AI clients receive full parameter guidance
      (Step 9).
- [ ] (Optional) Async jobs declare **bare** names, are idempotent, scope their
      queries to the restored tenant, and opt into `getSubmittableJobs()` only
      where a tenant may legitimately trigger the work on demand (Step 10).
- [ ] (Optional) Audited events declare **bare** names with an explicit
      `targetType` and `idKey`, and are dispatched via
      `Events::forPlugin($this->getName(), …)` rather than a hand-written
      prefix (Step 11).

See [Architecture.md](./Architecture.md) for how this all fits together.

## Distributing a real plugin

Example plugins live in core; **real plugins live in their own repositories**
and install by deploy-copy. The packaging template, install/uninstall steps,
and the deploy-time `generate:openapi` requirement are documented in
[Plugin-Distribution.md](./Plugin-Distribution.md) (established by the
`whity/plugin-announcements` pilot).
