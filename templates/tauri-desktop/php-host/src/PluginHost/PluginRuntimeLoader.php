<?php

declare(strict_types=1);

namespace Whity\PluginHost;

use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Sdk\PluginInterface;

/**
 * Loader for the offline host's plugin set — either an explicit FQCN list
 * (WHITY_PLUGINS) or, when that's unset, real auto-discovery via
 * {@see PluginDiscovery}. Both paths converge on the same
 * instantiate-then-gate pipeline: {@see PluginRequirementsGate} evaluates
 * each candidate's optional PluginRequirementsInterface declaration
 * (SDK/core-version constraints, inter-plugin dependencies) and topologically
 * orders the survivors — a sized-down port of production's
 * Whity\Core\PluginLoader::gateAndOrder() (~3500 lines total; this host still
 * skips hot-reload fingerprinting, install/disable/uninstall lifecycle, and
 * quarantine RE-ordering at runtime, since gating happens once at boot).
 *
 * What IS reused, in miniature: the one load-bearing primitive, a dynamic
 * per-directory PSR-4 autoloader — copied verbatim in technique from
 * PluginLoader::registerPluginNamespaces()/registerAutoloader() — because
 * plugin directories sit outside this app's own autoload root.
 *
 * A plugin whose constructor throws, or that fails the requirements gate, is
 * QUARANTINED (excluded, with a logged reason) rather than crashing the whole
 * boot — see getQuarantined().
 *
 * MULTI-ROOT (WC-desktop-plugins): takes a LIST of plugin roots, bundled first
 * — the read-only `plugins/` shipped with the installer, then (when set) the
 * writable `WHITY_DOWNLOADED_PLUGINS_ROOT` a device fetches new plugins into
 * at runtime (see the Rust `plugins` module). Every root is discovered and
 * PSR-4-registered, then ALL candidates across every root are gated together
 * in one `instantiateAndGate()` call — a plugin with the same declared name in
 * two roots is quarantined as a duplicate by {@see PluginRequirementsGate}
 * (first-registered wins), which is why roots are always iterated bundled
 * FIRST: a downloaded plugin can never shadow a bundled one.
 */
final class PluginRuntimeLoader
{
    private static bool $autoloaderRegistered = false;

    /** @var array<string, string> PSR-4 prefix ("Foo\\") => base directory */
    private static array $psr4Mappings = [];

    /** @var list<LoadedPlugin> */
    private array $loaded = [];

    /** @var list<array{fqcn: string, name: string, reason: string}> */
    private array $quarantined = [];

    /** @param list<string> $pluginsRoots Bundled root first, then any writable roots. */
    public function __construct(private readonly array $pluginsRoots)
    {
    }

    /**
     * Load an explicit, caller-supplied FQCN list (WHITY_PLUGINS set). A
     * missing class or one that doesn't implement PluginInterface is an
     * operator/config error and throws hard — unlike discovery, an explicit
     * list can never produce such a candidate by construction, so there is
     * nothing to quarantine it as.
     *
     * @param list<class-string<PluginInterface>> $fqcns
     */
    public function load(array $fqcns): void
    {
        $this->registerPluginNamespaces();
        $this->registerAutoloader();

        $candidates = [];
        foreach ($fqcns as $fqcn) {
            if (!class_exists($fqcn)) {
                $roots = implode(', ', $this->pluginsRoots);
                throw new \RuntimeException("Plugin class {$fqcn} could not be autoloaded from [{$roots}]");
            }
            if (!is_subclass_of($fqcn, PluginInterface::class) && !in_array(PluginInterface::class, class_implements($fqcn) ?: [], true)) {
                throw new \RuntimeException("{$fqcn} does not implement " . PluginInterface::class);
            }

            $candidates[] = $fqcn;
        }

        $this->instantiateAndGate($candidates);
    }

    /**
     * Load whatever plugins {@see PluginDiscovery} finds across every plugins
     * root (WHITY_PLUGINS unset) — the "arbitrary plugin" default. Each root is
     * discovered independently, then every candidate is merged into ONE
     * `instantiateAndGate()` call so cross-root duplicate names are caught by
     * the existing gate rather than needing bespoke merge logic here.
     *
     * A directory name already seen in an earlier (bundled-first) root is
     * passed to PluginDiscovery::discover() to skip entirely — same
     * first-root-wins rule registerPluginNamespaces() already applies to the
     * PSR-4 mapping, extended here to the require_once step itself. Without
     * this, two roots that legitimately ship a same-named plugin (e.g. a
     * bundled copy and that same plugin also present in the connected
     * server's catalog) would both get require_once'd and PHP would fatal
     * with "Cannot redeclare class" instead of quarantining the shadowed one.
     */
    public function loadDiscovered(): void
    {
        $this->registerPluginNamespaces();
        $this->registerAutoloader();

        $fqcns = [];
        $claimedDirectoryNames = [];
        foreach ($this->pluginsRoots as $root) {
            array_push($fqcns, ...PluginDiscovery::discover($root, $claimedDirectoryNames));
            array_push($claimedDirectoryNames, ...self::directoryNamesUnder($root));
        }

        $this->instantiateAndGate($fqcns);
    }

    /**
     * @return list<string>
     */
    private static function directoryNamesUnder(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $items = scandir($root);
        if ($items === false) {
            return [];
        }

        $names = [];
        foreach ($items as $item) {
            if (str_starts_with($item, '.')) {
                continue;
            }
            if (is_dir($root . '/' . $item)) {
                $names[] = $item;
            }
        }

        return $names;
    }

    /**
     * Instantiate every candidate FQCN (a constructor throw quarantines just
     * that one plugin, in both load() and loadDiscovered()), then run the
     * survivors through the requirements gate.
     *
     * @param list<class-string<PluginInterface>> $fqcns
     */
    private function instantiateAndGate(array $fqcns): void
    {
        $candidates = [];
        foreach ($fqcns as $fqcn) {
            try {
                /** @var PluginInterface $plugin */
                $plugin = new $fqcn();
            } catch (\Throwable $e) {
                $this->quarantined[] = [
                    'fqcn' => $fqcn,
                    'name' => $fqcn,
                    'reason' => 'constructor threw ' . get_class($e) . ': ' . $e->getMessage(),
                ];
                continue;
            }

            $candidates[] = ['fqcn' => $fqcn, 'plugin' => $plugin];
        }

        [$ordered, $quarantined] = PluginRequirementsGate::gateAndOrder($candidates);

        foreach ($quarantined as $q) {
            error_log("[php-host] plugin quarantined: {$q['name']} ({$q['fqcn']}) — {$q['reason']}");
        }
        array_push($this->quarantined, ...$quarantined);

        foreach ($ordered as $survivor) {
            $this->loaded[] = new LoadedPlugin($survivor['plugin'], $survivor['fqcn']);
        }
    }

    /**
     * @return list<LoadedPlugin>
     */
    public function getLoadedPlugins(): array
    {
        return $this->loaded;
    }

    /**
     * @return list<array{fqcn: string, name: string, reason: string}>
     */
    public function getQuarantined(): array
    {
        return $this->quarantined;
    }

    /**
     * Register every loaded plugin's getHooks() onto the given HookManager,
     * via HookRegistrar's per-plugin error boundary.
     */
    public function registerHooks(HookManager $hookManager): void
    {
        foreach ($this->loaded as $loadedPlugin) {
            HookRegistrar::registerAll($hookManager, $loadedPlugin->plugin->getName(), $loadedPlugin->plugin);
        }
    }

    /**
     * Build the permission catalogue from every loaded plugin's
     * getPermissions(), quarantining outright any plugin whose declaration
     * throws or contains a malformed `resource:action` slug — removed from
     * getLoadedPlugins() before its migrations, roles, hooks, or routes are
     * ever registered, mirroring production's "malformed declaration
     * disqualifies the whole plugin" contract.
     *
     * Must run BEFORE migrations/role-seeding/route/hook registration.
     */
    public function buildPermissionRegistry(): PermissionRegistry
    {
        $registry = new PermissionRegistry();
        $survivors = [];

        foreach ($this->loaded as $loadedPlugin) {
            try {
                $registry->register($loadedPlugin->plugin->getName(), $loadedPlugin->plugin->getPermissions());
            } catch (\Throwable $e) {
                $reason = 'getPermissions() declaration invalid: ' . $e->getMessage();
                $this->quarantined[] = [
                    'fqcn' => $loadedPlugin->fqcn,
                    'name' => $loadedPlugin->plugin->getName(),
                    'reason' => $reason,
                ];
                error_log("[php-host] plugin quarantined: {$loadedPlugin->plugin->getName()} ({$loadedPlugin->fqcn}) — {$reason}");
                continue;
            }

            $survivors[] = $loadedPlugin;
        }

        $this->loaded = $survivors;

        return $registry;
    }

    /**
     * Register every loaded plugin's getRoutes() on the router. Fails closed
     * on a malformed requiredPermission, same as production's Router — the
     * shape is validated even though nothing enforces it at dispatch time in
     * this offline host (see the plan doc's Risk R2).
     */
    public function registerRoutes(Router $router): void
    {
        foreach ($this->loaded as $loadedPlugin) {
            foreach ($loadedPlugin->plugin->getRoutes() as $route) {
                $permission = $route['requiredPermission'] ?? null;
                if ($permission !== null && preg_match('/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/', $permission) !== 1) {
                    throw new \RuntimeException(
                        "Malformed requiredPermission '{$permission}' on a route from {$loadedPlugin->fqcn}"
                    );
                }

                $router->register(
                    $route['method'],
                    $route['path'],
                    $route['handler'],
                    $route['requiredRole'] ?? null,
                    null,
                    $permission,
                    $route['schema'] ?? null,
                );
            }
        }
    }

    /**
     * Scan direct subdirectories of EVERY plugins root (bundled first) and map
     * each one's name to a PSR-4 namespace prefix — identical technique to
     * production's PluginLoader::registerPluginNamespaces(), extended to loop
     * roots. On a basename collision across roots, the FIRST registration wins
     * (bundled iterated first, so a downloaded directory can never shadow a
     * bundled one) — later duplicates are skipped with a logged reason.
     */
    private function registerPluginNamespaces(): void
    {
        foreach ($this->pluginsRoots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $items = scandir($root);
            if ($items === false) {
                continue;
            }

            foreach ($items as $item) {
                if (str_starts_with($item, '.')) {
                    continue;
                }

                $dirPath = $root . '/' . $item;
                if (!is_dir($dirPath)) {
                    continue;
                }

                $prefix = $item . '\\';
                if (isset(self::$psr4Mappings[$prefix])) {
                    error_log("[php-host] plugin directory '{$item}' in '{$root}' skipped — a plugin with the same directory name is already registered from an earlier root");
                    continue;
                }

                self::$psr4Mappings[$prefix] = rtrim(str_replace('\\', '/', (string) realpath($dirPath)), '/') . '/';
            }
        }
    }

    /**
     * Identical technique to production's PluginLoader::registerAutoloader():
     * one spl_autoload_register callback walking the PSR-4 mapping table.
     */
    private function registerAutoloader(): void
    {
        if (self::$autoloaderRegistered) {
            return;
        }

        spl_autoload_register(static function (string $class): void {
            foreach (self::$psr4Mappings as $prefix => $baseDir) {
                $len = strlen($prefix);
                if (strncmp($prefix, $class, $len) === 0) {
                    $relativeClass = substr($class, $len);
                    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
                    if (file_exists($file)) {
                        require_once $file;

                        return;
                    }
                }
            }
        });

        self::$autoloaderRegistered = true;
    }
}
