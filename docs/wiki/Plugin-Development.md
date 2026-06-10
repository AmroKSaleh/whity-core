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
[`PluginInterface`](../../src/Core/PluginInterface.php).

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
capabilities and re-registers from disk — so adding, editing, or removing a
plugin is picked up without restarting the worker.

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
[`public/index.php`](../../public/index.php)). These endpoints require the
`admin` role and the `plugins:manage` permission
([`CorePermissions::PLUGINS_MANAGE`](../../src/Core/RBAC/CorePermissions.php)).

| Method & path                    | Action                                               |
| -------------------------------- | ---------------------------------------------------- |
| `GET  /api/plugins`              | List plugins with name, version, status, and counts. |
| `POST /api/plugins/{id}/enable`  | Re-enable a disabled plugin.                          |
| `POST /api/plugins/{id}/disable` | Disable a plugin (unregisters its routes & hooks).    |
| `POST /api/plugins/reload`       | Reload plugins from disk.                             |

List the plugins (the bearer token must belong to a role granted
`plugins:manage`):

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

---

## Checklist

- [ ] Directory `plugins/HelloWorld/` with namespace prefix `HelloWorld\`.
- [ ] Class implements [`PluginInterface`](../../src/Core/PluginInterface.php)
      exactly (`declare(strict_types=1)`, PSR-12, PHPDoc).
- [ ] Routes return `Response` objects; public route is `GET /api/hello`.
- [ ] Permissions use `resource:action` colon notation.
- [ ] Hooks subscribe only to events the core actually dispatches
      (e.g. `user.creating`) and return the payload.
- [ ] Migrations are idempotent and tenant-scoped.
- [ ] A test under `tests/` exercises the plugin; full suite + PHPStan are green.

See [Architecture.md](./Architecture.md) for how this all fits together.
