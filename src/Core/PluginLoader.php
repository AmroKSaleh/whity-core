<?php

declare(strict_types=1);

namespace Whity\Core;

use ReflectionClass;
use Throwable;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\InvalidPermissionException;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TenantContext;
use Psr\Log\LoggerInterface;
use Composer\Semver\Semver;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;
use Whity\Sdk\PluginRolesInterface;
use Whity\Sdk\Sdk;

/**
 * Plugin loader for dynamic discovery and registration
 *
 * Scans a directory for PHP files, uses reflection to check if they implement
 * the SDK plugin contract ({@see \Whity\Sdk\PluginInterface}, WC-162 — the
 * deprecated {@see \Whity\Core\PluginInterface} alias extends it, so pre-SDK
 * plugins keep loading), and registers their routes, permissions, and hooks.
 */
class PluginLoader
{
    /**
     * The mandated `resource:action` permission pattern (WC-13/WC-169).
     *
     * Mirrors {@see \Whity\Core\RBAC\PermissionRegistry}'s validation so a
     * route-level `requiredPermission` and a frontend descriptor's
     * `requiredPermission` are held to the same contract.
     */
    private const PERMISSION_PATTERN = '/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/';

    /**
     * The kebab-case slug pattern for frontend feature descriptor ids (WC-169).
     */
    private const FEATURE_ID_PATTERN = '/^[a-z][a-z0-9-]*$/';

    /**
     * @var string Directory containing plugin files
     */
    private string $pluginDir;

    /**
     * @var Router Router instance for registering plugins
     */
    private Router $router;

    /**
     * @var PermissionRegistry|null Permission registry instance
     */
    private ?PermissionRegistry $permissionRegistry;

    /**
     * @var HookManager|null Hook manager instance
     */
    private ?HookManager $hookManager;

    /**
     * @var LoggerInterface|null Logger instance
     */
    private ?LoggerInterface $logger;

    /**
     * Optional seeder for plugin-declared roles and permission grants.
     *
     * When wired, it is invoked after a plugin registers successfully so that
     * custom roles and grants are persisted to the database. On reload/unregister
     * it removes the grants (but retains the role rows — conservative approach).
     */
    private ?PluginRoleSeeder $roleSeeder;

    /**
     * @var array<PluginInterface> Registered plugins
     */
    private array $plugins = [];

    /**
     * Registration bookkeeping per plugin, keyed by the plugin's original FQCN.
     *
     * Tracks what each plugin registered so it can be cleanly unregistered when
     * its file is modified or removed during a hot reload, or administratively
     * disabled via the admin API. The plugin instance is retained so a disabled
     * plugin can be re-registered (re-enabled) without re-reading it from disk.
     *
     * @var array<string, array{plugin: PluginInterface, namespacePrefix: string, hooks: array<array{event: string, callback: callable}>, frontendFeatures: list<array<string, mixed>>}>
     */
    private array $registeredPlugins = [];

    /**
     * Frontend feature descriptor ids claimed so far, id => plugin key (WC-169).
     *
     * Descriptor ids are unique across ALL plugins: the first claimant
     * (discovery order) wins; a later duplicate is dropped with a warning. A
     * plugin re-claiming its own id (re-enable after an administrative
     * disable) is idempotent.
     *
     * @var array<string, string>
     */
    private array $claimedFeatureIds = [];

    /**
     * Permission name => owning plugin key (original FQCN), first declarant
     * (discovery order) wins. Frontend descriptors may only be gated on a
     * permission the declaring plugin actually OWNS — a later plugin
     * re-declaring the same name cannot gate screens on it, and core
     * permission names are never plugin-ownable (see
     * {@see validateFrontendFeature()}).
     *
     * @var array<string, string>
     */
    private array $claimedPermissions = [];

    /**
     * Plugin keys (original FQCNs) whose routes/hooks were torn down by an
     * administrative {@see disablePlugin()} call.
     *
     * Distinguishes an administratively disabled plugin (capabilities removed and
     * needing re-registration on re-enable) from an auto-failed plugin (whose
     * capabilities remain registered, short-circuited by the error boundary).
     *
     * @var array<string, true>
     */
    private array $administrativelyDisabled = [];

    /**
     * Per-plugin lifecycle state machines, keyed by the plugin's original FQCN.
     *
     * Extends (does not duplicate) the registeredPlugins bookkeeping: while
     * registeredPlugins tracks what each plugin registered so it can be cleanly
     * unregistered, this tracks each plugin's runtime health (state + error
     * counters) so the error boundary can fail a misbehaving plugin and the
     * admin API can report and re-enable it. Worker-level state by design.
     *
     * @var array<string, PluginLifecycle>
     */
    private array $lifecycles = [];

    /**
     * Content hash of the source most recently registered for each plugin FQCN.
     *
     * Survives unregister cycles because it reflects what is actually compiled
     * into this PHP process. Used to detect when a plugin file's contents
     * changed between reloads so the new code can be re-evaluated under a fresh
     * versioned namespace.
     *
     * @var array<string, string>
     */
    private array $loadedContentHashes = [];

    /**
     * Snapshot of the plugin-tree fingerprint captured at the last load/reload.
     *
     * Maps each plugin PHP file path to a "mtime:size" signature. Comparing this
     * against a freshly computed fingerprint tells us whether anything on disk
     * changed since the worker last loaded plugins.
     *
     * @var array<string, string>
     */
    private array $fingerprint = [];

    /**
     * @var bool Whether plugins have been loaded at least once in this process
     */
    private bool $loaded = false;

    /**
     * @var string|null Cache file path for the manifest
     */
    private ?string $cacheFile = null;

    /**
     * @var array<string, string> PSR-4 namespace mappings (prefix => path)
     */
    private static array $psr4Mappings = [];

    /**
     * @var bool Whether the autoloader has been registered
     */
    private static bool $autoloaderRegistered = false;

    /**
     * Constructor
     *
     * @param string               $pluginDir          Directory path containing plugin files
     * @param Router               $router             Router instance to register plugins with
     * @param PermissionRegistry|null $permissionRegistry Optional permission registry
     * @param HookManager|null     $hookManager        Optional hook manager
     * @param LoggerInterface|null $logger             Optional logger instance
     * @param PluginRoleSeeder|null $roleSeeder         Optional seeder for plugin-declared roles
     */
    public function __construct(
        string $pluginDir,
        Router $router,
        ?PermissionRegistry $permissionRegistry = null,
        ?HookManager $hookManager = null,
        ?LoggerInterface $logger = null,
        ?PluginRoleSeeder $roleSeeder = null
    ) {
        $this->pluginDir = $pluginDir;
        $this->router = $router;
        $this->permissionRegistry = $permissionRegistry;
        $this->hookManager = $hookManager;
        $this->logger = $logger;
        $this->roleSeeder = $roleSeeder;

        $this->registerAutoloader();
    }

    /**
     * Enable caching with an optional custom cache file path
     *
     * @param string|null $cacheFile Custom cache file path
     * @return void
     */
    public function enableCache(?string $cacheFile = null): void
    {
        $this->cacheFile = $cacheFile ?? ($this->pluginDir . '/plugin_manifest.json');
    }

    /**
     * Clear the manifest cache file
     *
     * @return void
     */
    public function clearCache(): void
    {
        if ($this->cacheFile && file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    /**
     * Register dynamic PSR-4 autoloader for plugin subdirectories
     *
     * @return void
     */
    private function registerAutoloader(): void
    {
        if (self::$autoloaderRegistered) {
            return;
        }

        spl_autoload_register(function (string $class): void {
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

    /**
     * Register PSR-4 namespace mappings for direct subdirectories of the plugins directory
     *
     * @return void
     */
    private function registerPluginNamespaces(): void
    {
        if (!is_dir($this->pluginDir)) {
            return;
        }

        $items = scandir($this->pluginDir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $dirPath = $this->pluginDir . '/' . $item;
            if (is_dir($dirPath)) {
                $prefix = $item . '\\';
                self::$psr4Mappings[$prefix] = rtrim(str_replace('\\', '/', (string)realpath($dirPath)), '/') . '/';
            }
        }
    }

    /**
     * Load and register plugins from the plugin directory
     *
     * Records the current plugin-tree fingerprint so that subsequent reload()
     * calls can cheaply detect whether anything changed on disk.
     *
     * @return void
     */
    public function load(): void
    {
        $this->loadDiscovered($this->discover());

        $this->fingerprint = $this->computeFingerprint();
        $this->loaded = true;
    }

    /**
     * Instantiate, gate, order, and register a discovered plugin set (WC-165).
     *
     * Two-phase loading: every plugin is instantiated first (no capability
     * registration), then the SDK-constraint and inter-plugin dependency
     * gates run via composer/semver, satisfied plugins are topologically
     * ordered by dependency, and only then registered. Unsatisfied plugins
     * are quarantined: PluginState::Failed with an admin-visible reason and
     * ZERO capabilities registered.
     *
     * @param array<string, string> $discovered Map of FQCN to file path.
     * @return void
     */
    private function loadDiscovered(array $discovered): void
    {
        $candidates = [];
        foreach ($discovered as $fqcn => $filePath) {
            $candidate = $this->instantiatePlugin($fqcn, $filePath);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        [$ordered, $quarantined] = $this->gateAndOrder($candidates);

        foreach ($ordered as $candidate) {
            $this->registerPlugin($candidate['plugin'], $candidate['namespacePrefix'], $candidate['fqcn']);
        }

        foreach ($quarantined as $entry) {
            $this->quarantinePlugin($entry['candidate'], $entry['reason']);
        }
    }

    /**
     * Reload plugins if the plugin directory changed since the last load
     *
     * Designed for FrankenPHP persistent workers: a single PluginLoader instance
     * survives across many requests, so this method is called at the start of a
     * request to pick up plugins that were added, modified, or removed on disk
     * without restarting the worker.
     *
     * Behaviour:
     *  - Added plugins are discovered and registered.
     *  - Removed plugins have their routes and hooks unregistered.
     *  - Modified plugins are re-registered from their updated source. Because a
     *    PHP class cannot be redefined within a live process, modified classes
     *    are re-evaluated under a content-versioned namespace so the new code
     *    actually runs (see materializeClass()).
     *
     * @return bool True if a change was detected and applied, false if nothing changed
     */
    public function reload(): bool
    {
        if (!$this->loaded) {
            $this->load();
            return true;
        }

        $current = $this->computeFingerprint();

        if ($current === $this->fingerprint) {
            return false;
        }

        // The set of plugin files changed. Drop the stale manifest cache so the
        // next discover() performs a full filesystem scan instead of trusting
        // outdated FQCN -> path mappings.
        $this->clearCache();

        // Unregister everything currently loaded, then rebuild from disk. This
        // uniformly handles additions, modifications, and removals — and runs
        // the same WC-165 gate/ordering as the initial load.
        $this->unregisterAll();

        $this->loadDiscovered($this->discover());

        $this->fingerprint = $current;

        return true;
    }

    /**
     * Get a freshly computed fingerprint of the plugin tree on disk
     *
     * The fingerprint maps each plugin PHP file currently on disk to a
     * "mtime:size" signature. Callers can compare successive fingerprints to
     * decide whether a reload is warranted.
     *
     * @return array<string, string>
     */
    public function getFingerprint(): array
    {
        return $this->computeFingerprint();
    }

    /**
     * Compute a fingerprint of every PHP file under the plugin directory
     *
     * @return array<string, string> Map of file path => "mtime:size" signature
     */
    private function computeFingerprint(): array
    {
        if (!is_dir($this->pluginDir)) {
            return [];
        }

        $fingerprint = [];
        try {
            $directory = new \RecursiveDirectoryIterator(
                $this->pluginDir,
                \RecursiveDirectoryIterator::SKIP_DOTS
            );
            $iterator = new \RecursiveIteratorIterator($directory);
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                    $path = str_replace('\\', '/', (string) $fileInfo->getRealPath());
                    $fingerprint[$path] = $fileInfo->getMTime() . ':' . $fileInfo->getSize();
                }
            }
        } catch (\Throwable) {
            // Treat an unreadable tree as empty rather than crashing the request.
            return [];
        }

        ksort($fingerprint);

        return $fingerprint;
    }

    /**
     * Unregister all currently loaded plugins (routes, hooks, instances)
     *
     * @return void
     */
    private function unregisterAll(): void
    {
        foreach ($this->registeredPlugins as $info) {
            $this->router->unregisterByNamespace($info['namespacePrefix']);

            if ($this->hookManager !== null) {
                foreach ($info['hooks'] as $hook) {
                    $this->hookManager->removeListener($hook['event'], $hook['callback']);
                }
            }
        }

        $this->registeredPlugins = [];
        $this->plugins = [];
        $this->administrativelyDisabled = [];
        $this->claimedFeatureIds = [];
        $this->claimedPermissions = [];

        // Reset lifecycle state machines. A reload re-registers every plugin
        // from disk, so each plugin (including a previously failed one whose
        // file changed) gets a fresh lifecycle and a clean error counter.
        $this->lifecycles = [];
    }

    /**
     * Discover all valid plugin classes in the plugin directory
     *
     * Scans `/plugins/` recursively. For each subdirectory under `/plugins/`,
     * maps its directory name to a PSR-4 namespace prefix. It validates
     * that discovered classes implement PluginInterface.
     *
     * @return array<string, string> Array mapping FQCN to file path
     */
    public function discover(): array
    {
        if (!is_dir($this->pluginDir)) {
            return [];
        }

        // 1. Initialize namespaces for all direct subdirectories of pluginDir
        $this->registerPluginNamespaces();

        // 2. Try loading from manifest cache if enabled
        $cachedPlugins = $this->loadManifest();
        if ($cachedPlugins !== null) {
            $validDiscovered = [];
            $cacheValid = true;
            foreach ($cachedPlugins as $fqcn => $filePath) {
                if (file_exists($filePath)) {
                    require_once $filePath;
                    // Check if the class is actually a plugin (triggers autoloading if needed)
                    if (class_exists($fqcn)) {
                        try {
                            $reflection = new ReflectionClass($fqcn);
                            if ($reflection->implementsInterface(PluginInterface::class)) {
                                $validDiscovered[$fqcn] = $filePath;
                                continue;
                            }
                        } catch (\Throwable) {
                            // Fall through to cache invalidation
                        }
                    }
                }
                $cacheValid = false;
                break;
            }
            if ($cacheValid) {
                return $validDiscovered;
            }
        }

        // 3. Scan the plugins directory
        $discovered = [];

        // Scan direct items in the pluginDir
        $items = scandir($this->pluginDir);
        if ($items === false) {
            return [];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $this->pluginDir . '/' . $item;

            if (is_dir($itemPath)) {
                // This is a plugin directory (e.g. plugins/MyPlugin)
                // Find all PHP files recursively inside it
                $phpFiles = $this->findPhpFilesRecursively($itemPath);
                $foundValidPluginInDir = false;

                foreach ($phpFiles as $filePath) {
                    $fqcn = $this->resolveClassFromFile($filePath);
                    if ($fqcn === null) {
                        continue;
                    }

                    // Require the file first so the class is defined
                    require_once $filePath;

                    // Attempt to load and inspect class
                    if (class_exists($fqcn)) {
                        try {
                            $reflection = new ReflectionClass($fqcn);
                            if ($reflection->implementsInterface(PluginInterface::class)) {
                                $discovered[$fqcn] = $filePath;
                                $foundValidPluginInDir = true;
                            }
                        } catch (\Throwable) {
                            // Ignore
                        }
                    }
                }

                if (!$foundValidPluginInDir) {
                    // No valid plugin class was found in this folder
                    $warningMsg = "No valid plugin class found in directory {$itemPath}.";
                    if ($this->logger !== null) {
                        $this->logger->warning($warningMsg);
                    } else {
                        error_log($warningMsg);
                    }
                }
            } else {
                // This is a file directly under plugins/
                if (pathinfo($item, PATHINFO_EXTENSION) === 'php') {
                    $fqcn = $this->resolveClassFromFile($itemPath);
                    if ($fqcn !== null) {
                        require_once $itemPath;
                        if (class_exists($fqcn)) {
                            try {
                                $reflection = new ReflectionClass($fqcn);
                                if ($reflection->implementsInterface(PluginInterface::class)) {
                                    $discovered[$fqcn] = $itemPath;
                                } else {
                                    $warningMsg = "Plugin class {$fqcn} does not implement PluginInterface.";
                                    if ($this->logger !== null) {
                                        $this->logger->warning($warningMsg);
                                    } else {
                                        error_log($warningMsg);
                                    }
                                }
                            } catch (\Throwable) {
                                // Ignore
                            }
                        }
                    }
                }
            }
        }

        // 4. Save to manifest cache if enabled
        $this->saveManifest($discovered);

        return $discovered;
    }

    /**
     * Find all PHP files in a directory recursively
     *
     * @param string $dir Path to directory
     * @return array<string> List of absolute file paths
     */
    private function findPhpFilesRecursively(string $dir): array
    {
        $phpFiles = [];
        try {
            $directory = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
            $iterator = new \RecursiveIteratorIterator($directory);
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                    $phpFiles[] = str_replace('\\', '/', $fileInfo->getRealPath());
                }
            }
        } catch (\Throwable) {
            // Ignore
        }
        return $phpFiles;
    }

    /**
     * Resolve the fully qualified class name for a given plugin file path
     *
     * @param string $filePath Absolute or relative file path
     * @return string|null Fully qualified class name, or null if cannot resolve
     */
    private function resolveClassFromFile(string $filePath): ?string
    {
        $realPath = realpath($filePath);
        $realPluginDir = realpath($this->pluginDir);
        if ($realPath === false || $realPluginDir === false) {
            return null;
        }

        // Normalize paths to forward slashes for cross-platform matching
        $realPath = str_replace('\\', '/', $realPath);
        $realPluginDir = str_replace('\\', '/', $realPluginDir);

        if (strncmp($realPluginDir, $realPath, strlen($realPluginDir)) !== 0) {
            return null;
        }

        // Get relative path within the plugins directory
        $relative = substr($realPath, strlen($realPluginDir));
        $relative = ltrim($relative, '/');

        if ($relative === '') {
            return null;
        }
        $parts = explode('/', $relative);

        if (count($parts) === 1) {
            // File directly in the plugins directory (e.g. plugins/ExamplePlugin.php)
            $className = pathinfo($parts[0], PATHINFO_FILENAME);
            return 'Whity\\Plugins\\' . $className;
        }

        // File inside a subdirectory (e.g. plugins/MyPlugin/Plugin.php)
        $subDir = $parts[0];
        $classParts = array_slice($parts, 1);
        $classPartsStr = implode('\\', $classParts);
        $className = pathinfo($classPartsStr, PATHINFO_FILENAME);

        return $subDir . '\\' . $className;
    }

    /**
     * Get all registered plugins
     *
     * @return array<PluginInterface> Array of registered plugin instances
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    /**
     * Get the lifecycle state machine for a plugin, keyed by its original FQCN.
     *
     * @param string $pluginKey The plugin's stable identity (original FQCN).
     * @return PluginLifecycle|null The lifecycle, or null if the plugin is unknown.
     */
    public function getLifecycle(string $pluginKey): ?PluginLifecycle
    {
        return $this->lifecycles[$pluginKey] ?? null;
    }

    /**
     * Get all plugin lifecycle state machines, keyed by original FQCN.
     *
     * @return array<string, PluginLifecycle>
     */
    public function getLifecycles(): array
    {
        return $this->lifecycles;
    }

    /**
     * Get a serialisable status snapshot of every loaded plugin.
     *
     * Intended for the admin plugins API. Each entry exposes the plugin's
     * lifecycle state, consecutive-error count, and last error details.
     *
     * @return array<int, array{id: string, name: string, state: string, consecutive_errors: int, last_error: array{message: string, type: string, trace: string, at: int}|null}>
     */
    public function getPluginStatuses(): array
    {
        $statuses = [];
        foreach ($this->lifecycles as $lifecycle) {
            $statuses[] = $lifecycle->toArray();
        }

        return $statuses;
    }

    /**
     * Get admin-facing metadata for every registered plugin.
     *
     * Combines each plugin's static descriptor (name, version, declared route
     * and permission counts) with its live lifecycle status, producing the shape
     * the plugins admin API lists. Plugins keep their bookkeeping (and therefore
     * appear here) even while administratively disabled, so the status reflects
     * the current lifecycle state rather than disappearing from the listing.
     *
     * @return array<int, array{id: string, name: string, version: string, status: string, routes_count: int, permissions_count: int}>
     */
    public function getPluginMetadata(): array
    {
        $metadata = [];
        foreach ($this->registeredPlugins as $pluginKey => $info) {
            $plugin = $info['plugin'];
            $lifecycle = $this->lifecycles[$pluginKey] ?? null;

            $metadata[] = [
                'id' => $pluginKey,
                'name' => $plugin->getName(),
                'version' => $plugin->getVersion(),
                'status' => $lifecycle?->getState()->value ?? PluginState::Loaded->value,
                'routes_count' => count($plugin->getRoutes()),
                'permissions_count' => count($plugin->getPermissions()),
            ];
        }

        return $metadata;
    }

    /**
     * Manually re-enable a failed or administratively disabled plugin.
     *
     * Returns the plugin to the active state with a clean error counter so it can
     * serve requests again. When the plugin had been administratively disabled
     * via {@see disablePlugin()} (its routes and hooks unregistered), those
     * capabilities are re-registered from the retained instance so it serves
     * traffic again. Used by the admin plugins API.
     *
     * @param string $pluginKey The plugin's stable identity (original FQCN).
     * @return bool True if the plugin existed and was re-enabled, false if unknown.
     */
    public function reEnablePlugin(string $pluginKey): bool
    {
        $lifecycle = $this->lifecycles[$pluginKey] ?? null;
        if ($lifecycle === null) {
            return false;
        }

        // A QUARANTINED plugin (WC-165: unsatisfied SDK/dependency
        // requirements) cannot be re-enabled by an admin action: nothing about
        // its requirements changed, no capabilities were ever registered, and
        // reEnable() would destroy the only record of WHY it was refused.
        // Only a disk change (re-gated by reload()) can clear the condition.
        if ($lifecycle->isQuarantined()) {
            return false;
        }

        // A plugin disabled via disablePlugin() had its routes and hooks
        // unregistered but its bookkeeping retains the instance. Re-register its
        // capabilities so it can serve traffic once active again. Auto-failed
        // plugins keep their capabilities registered (short-circuited by the
        // error boundary) and so must not be re-registered.
        $info = $this->registeredPlugins[$pluginKey] ?? null;
        $reRegister = isset($this->administrativelyDisabled[$pluginKey]) && $info !== null;

        $lifecycle->reEnable();

        if ($reRegister && $info !== null) {
            $registered = $this->registerCapabilities(
                $info['plugin'],
                $info['namespacePrefix'],
                $pluginKey
            );
            $this->registeredPlugins[$pluginKey]['hooks'] = $registered['hooks'];
            $this->registeredPlugins[$pluginKey]['frontendFeatures'] = $registered['frontendFeatures'];
            unset($this->administrativelyDisabled[$pluginKey]);
        }

        return true;
    }

    /**
     * Administratively disable an active (or failed) plugin at runtime.
     *
     * Transitions the plugin's lifecycle to {@see PluginState::Disabled} and
     * removes its registered capabilities: routes are dropped from the router via
     * {@see Router::unregisterByNamespace()} (WC-8) and hook subscriptions are
     * removed from the hook manager. The plugin instance and namespace prefix are
     * retained in bookkeeping so {@see reEnablePlugin()} can restore it without a
     * disk reload. Worker-level state by design.
     *
     * @param string $pluginKey The plugin's stable identity (original FQCN).
     * @return bool True if the plugin existed and was disabled, false if unknown.
     */
    public function disablePlugin(string $pluginKey): bool
    {
        $lifecycle = $this->lifecycles[$pluginKey] ?? null;
        $info = $this->registeredPlugins[$pluginKey] ?? null;
        if ($lifecycle === null || $info === null) {
            return false;
        }

        // Drop the plugin's routes and hook subscriptions so it stops serving.
        $this->router->unregisterByNamespace($info['namespacePrefix']);

        if ($this->hookManager !== null) {
            foreach ($info['hooks'] as $hook) {
                $this->hookManager->removeListener($hook['event'], $hook['callback']);
            }
        }

        // Hooks are now unregistered; clear the recorded subscriptions so a later
        // re-enable does not attempt to remove stale callbacks. Mark the plugin
        // as administratively disabled so re-enable knows to re-register it.
        $this->registeredPlugins[$pluginKey]['hooks'] = [];
        $this->administrativelyDisabled[$pluginKey] = true;

        // Remove seeded role_permissions grants for the plugin (uninstall path).
        // Role rows themselves are retained — conservative approach documented on
        // PluginRoleSeeder::removeGrants(). Errors are swallowed by the seeder.
        if ($this->roleSeeder !== null && $info['plugin'] instanceof PluginRolesInterface) {
            $tenantId = TenantContext::getTenantId() ?? PluginRoleSeeder::SYSTEM_TENANT_ID;
            $this->roleSeeder->removeGrants($info['plugin'], $tenantId);
        }

        $lifecycle->disable();

        return true;
    }

    /**
     * Load a single plugin class and register it
     *
     * When the same plugin file has already been required earlier in this
     * process with DIFFERENT contents (a hot-reload of a modified plugin), the
     * original class is already locked into memory and cannot be redefined.
     * In that case the source is re-evaluated under a content-versioned
     * namespace so the updated code actually runs. Brand-new plugins are loaded
     * directly. See the class docblock / PR notes for the tradeoff.
     *
     * @param string $fqcn Fully qualified class name of the plugin
     * @param string $filePath File path of the plugin
     * @return void
     */
    /**
     * Materialize and instantiate a plugin class WITHOUT registering it.
     *
     * @param string $fqcn Original fully qualified class name
     * @param string $filePath Plugin file path
     * @return array{fqcn: string, plugin: PluginInterface, namespacePrefix: string}|null
     */
    private function instantiatePlugin(string $fqcn, string $filePath): ?array
    {
        $effectiveFqcn = $this->materializeClass($fqcn, $filePath);
        if ($effectiveFqcn === null) {
            return null;
        }

        // Use reflection to validate and get instance
        try {
            $reflectionClass = new ReflectionClass($effectiveFqcn);
            if (!$reflectionClass->implementsInterface(PluginInterface::class)) {
                $warningMsg = "Plugin class {$fqcn} does not implement PluginInterface.";
                if ($this->logger !== null) {
                    $this->logger->warning($warningMsg);
                } else {
                    error_log($warningMsg);
                }
                return null;
            }

            // Extract namespace prefix
            $namespacePrefix = $reflectionClass->getNamespaceName();

            /** @var PluginInterface $plugin */
            $plugin = new $effectiveFqcn();
        } catch (\Throwable $e) {
            $errorMsg = "Failed to load plugin {$fqcn}: " . $e->getMessage();
            if ($this->logger !== null) {
                $this->logger->error($errorMsg);
            } else {
                error_log($errorMsg);
            }
            return null;
        }

        return ['fqcn' => $fqcn, 'plugin' => $plugin, 'namespacePrefix' => $namespacePrefix];
    }

    /**
     * Evaluate the WC-165 compatibility gates and order plugins by dependency.
     *
     * Gates, evaluated with composer/semver:
     *  - duplicate plugin names (the later discovery is quarantined);
     *  - the SDK constraint ({@see PluginRequirementsInterface::getSdkConstraint()})
     *    against {@see Sdk::VERSION};
     *  - inter-plugin dependencies (existence + version range), iterated to a
     *    fixpoint so quarantine CASCADES to dependents of failed plugins;
     *  - dependency cycles (every member quarantined).
     *
     * Plugins without a {@see PluginRequirementsInterface} declaration load
     * unconditionally (backward compatible). Ordering is Kahn's algorithm,
     * stable with respect to discovery order among unconstrained peers.
     *
     * @param list<array{fqcn: string, plugin: PluginInterface, namespacePrefix: string}> $candidates
     * @return array{
     *   0: list<array{fqcn: string, plugin: PluginInterface, namespacePrefix: string}>,
     *   1: list<array{candidate: array{fqcn: string, plugin: PluginInterface, namespacePrefix: string}, reason: string}>
     * }
     */
    private function gateAndOrder(array $candidates): array
    {
        $quarantined = [];

        // Index by declared plugin name; duplicates are quarantined. The name
        // and requirements are captured ONCE here — every later phase works
        // from these snapshots, so a plugin whose accessors return varying
        // values cannot end up half-registered, half-quarantined.
        /** @var array<string, array{fqcn: string, plugin: PluginInterface, namespacePrefix: string, sdkConstraint: string, deps: array<string, string>}> $byName */
        $byName = [];
        foreach ($candidates as $candidate) {
            $name = $candidate['plugin']->getName();
            if (isset($byName[$name])) {
                $quarantined[] = [
                    'candidate' => $candidate,
                    'reason' => "duplicate plugin name '{$name}' (already provided by {$byName[$name]['fqcn']})",
                ];
                continue;
            }

            // A throwing requirements declaration fails CLOSED: it is a
            // stronger incompatibility signal than an unparseable string
            // (e.g. plugin code referencing SDK symbols this host lacks).
            try {
                $candidate['sdkConstraint'] = $this->sdkConstraintOf($candidate['plugin']);
                $candidate['deps'] = $this->dependenciesOf($candidate['plugin']);
            } catch (\Throwable $e) {
                $quarantined[] = [
                    'candidate' => $candidate,
                    'reason' => 'requirements declaration threw ' . get_class($e) . ': ' . $e->getMessage(),
                ];
                continue;
            }

            $byName[$name] = $candidate;
        }

        // SDK-constraint gate.
        foreach ($byName as $name => $candidate) {
            $constraint = $candidate['sdkConstraint'];
            if ($constraint === '') {
                continue;
            }

            try {
                $satisfied = Semver::satisfies(Sdk::VERSION, $constraint);
            } catch (\UnexpectedValueException) {
                $quarantined[] = [
                    'candidate' => $candidate,
                    'reason' => "declares an unparseable SDK constraint '{$constraint}'",
                ];
                unset($byName[$name]);
                continue;
            }

            if (!$satisfied) {
                $quarantined[] = [
                    'candidate' => $candidate,
                    'reason' => "requires plugin SDK '{$constraint}', but the host provides " . Sdk::VERSION,
                ];
                unset($byName[$name]);
            }
        }

        // Dependency gate, iterated to a fixpoint so removal cascades.
        do {
            $removed = false;
            foreach ($byName as $name => $candidate) {
                foreach ($candidate['deps'] as $depName => $depConstraint) {
                    if (!isset($byName[$depName])) {
                        $quarantined[] = [
                            'candidate' => $candidate,
                            'reason' => "depends on plugin '{$depName}' ({$depConstraint}), which is missing or failed",
                        ];
                        unset($byName[$name]);
                        $removed = true;
                        break;
                    }

                    $depVersion = $byName[$depName]['plugin']->getVersion();
                    try {
                        $satisfied = Semver::satisfies($depVersion, $depConstraint);
                    } catch (\UnexpectedValueException) {
                        $quarantined[] = [
                            'candidate' => $candidate,
                            'reason' => "dependency on '{$depName}' is unevaluable (constraint '{$depConstraint}', found version '{$depVersion}')",
                        ];
                        unset($byName[$name]);
                        $removed = true;
                        break;
                    }

                    if (!$satisfied) {
                        $quarantined[] = [
                            'candidate' => $candidate,
                            'reason' => "requires plugin '{$depName}' {$depConstraint}, found {$depVersion}",
                        ];
                        unset($byName[$name]);
                        $removed = true;
                        break;
                    }
                }
            }
        } while ($removed);

        // Topological sort (Kahn), stable by discovery order, tracked by the
        // byName KEYS so unstable getName() implementations cannot desync it.
        $inDegree = [];
        $dependents = [];
        foreach ($byName as $name => $candidate) {
            $inDegree[$name] = 0;
        }
        foreach ($byName as $name => $candidate) {
            foreach (array_keys($candidate['deps']) as $depName) {
                $inDegree[$name]++;
                $dependents[$depName][] = $name;
            }
        }

        $queue = [];
        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $queue[] = $name;
            }
        }

        $ordered = [];
        $orderedKeys = [];
        while ($queue !== []) {
            $name = array_shift($queue);
            $ordered[] = $byName[$name];
            $orderedKeys[$name] = true;
            foreach ($dependents[$name] ?? [] as $dependent) {
                if (isset($inDegree[$dependent]) && --$inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        // Anything not ordered sits on a dependency cycle.
        if (count($ordered) < count($byName)) {
            foreach ($byName as $name => $candidate) {
                if (!isset($orderedKeys[$name])) {
                    $quarantined[] = [
                        'candidate' => $candidate,
                        'reason' => "is part of a plugin dependency cycle and cannot be ordered",
                    ];
                }
            }
        }

        return [$ordered, $quarantined];
    }

    /**
     * The plugin's declared SDK constraint, or '' when undeclared.
     *
     * Deliberately does NOT catch: a throwing declaration is handled
     * fail-closed by the caller (quarantine with the exception named).
     */
    private function sdkConstraintOf(PluginInterface $plugin): string
    {
        if (!$plugin instanceof PluginRequirementsInterface) {
            return '';
        }

        return $plugin->getSdkConstraint();
    }

    /**
     * The plugin's declared inter-plugin dependencies, or [] when undeclared.
     *
     * Deliberately does NOT catch: a throwing declaration is handled
     * fail-closed by the caller (quarantine with the exception named).
     *
     * @return array<string, string>
     */
    private function dependenciesOf(PluginInterface $plugin): array
    {
        if (!$plugin instanceof PluginRequirementsInterface) {
            return [];
        }

        $valid = [];
        foreach ($plugin->getPluginDependencies() as $name => $constraint) {
            if (is_string($name) && is_string($constraint)) {
                $valid[$name] = $constraint;
            }
        }

        return $valid;
    }

    /**
     * Quarantine a gated plugin: Failed lifecycle with an admin-visible
     * reason, no capabilities registered.
     *
     * @param array{fqcn: string, plugin: PluginInterface, namespacePrefix: string} $candidate
     * @param string $reason Why the plugin was refused.
     */
    private function quarantinePlugin(array $candidate, string $reason): void
    {
        $name = $candidate['plugin']->getName();
        $message = "Plugin {$name} quarantined: {$reason}";
        if ($this->logger !== null) {
            $this->logger->warning($message);
        } else {
            error_log($message);
        }

        $lifecycle = new PluginLifecycle($candidate['fqcn'], $name);
        $lifecycle->markLoaded();
        $lifecycle->quarantine($reason);
        $this->lifecycles[$candidate['fqcn']] = $lifecycle;
    }

    /**
     * Ensure the plugin class is defined and return the concrete FQCN to use
     *
     * Returns the original FQCN when the file can simply be required, or a
     * versioned FQCN when the file was modified after an earlier version was
     * already loaded in this process. Returns null when no usable class exists.
     *
     * @param string $fqcn Original fully qualified class name
     * @param string $filePath Plugin file path
     * @return string|null FQCN to instantiate, or null on failure
     */
    private function materializeClass(string $fqcn, string $filePath): ?string
    {
        $source = @file_get_contents($filePath);

        // If we cannot read the source, fall back to a plain require.
        if ($source === false) {
            require_once $filePath;
            return class_exists($fqcn) ? $fqcn : null;
        }

        $contentHash = substr(hash('xxh128', $source), 0, 12);
        $previousHash = $this->loadedContentHashes[$fqcn] ?? null;

        // First time this loader registers this class in the process: require
        // the file as-is. (discover() may already have required it, but no prior
        // version has been registered, so the original definition is correct.)
        if ($previousHash === null) {
            require_once $filePath;
            if (!class_exists($fqcn)) {
                return null;
            }
            $this->loadedContentHashes[$fqcn] = $contentHash;
            return $fqcn;
        }

        // Previously registered with identical content: reuse the live class.
        if ($previousHash === $contentHash) {
            return $fqcn;
        }

        // WC-160: the eval() below re-executes MODIFIED plugin source in-place
        // and is hard-gated to development; in any other (or unset) APP_ENV the
        // already-loaded definition keeps serving. Note this gate alone does not
        // cover brand-new files (first load uses require_once above) — that
        // runtime vector is closed by gating the per-request reload() loop to
        // development in public/index.php; boot-time load() and explicit admin
        // actions still load plugins in every env.
        if (($_ENV['APP_ENV'] ?? 'production') !== 'development') {
            $gateMsg = "Plugin {$fqcn} changed on disk, but eval-based hot-reload is "
                . "development-only (WC-160); keeping the loaded version.";
            if ($this->logger !== null) {
                $this->logger->warning($gateMsg);
            } else {
                error_log($gateMsg);
            }
            return class_exists($fqcn) ? $fqcn : null;
        }

        // Content changed since the last registration. The original class is
        // locked into memory, so re-evaluate the source under a fresh,
        // content-addressed namespace so the updated code runs.
        $namespacePos = strrpos($fqcn, '\\');
        $namespace = $namespacePos === false ? '' : substr($fqcn, 0, $namespacePos);
        $shortName = $namespacePos === false ? $fqcn : substr($fqcn, $namespacePos + 1);

        $versionedNamespace = ($namespace === '' ? '' : $namespace . '\\')
            . '_Whity_Reload_' . $contentHash;
        $versionedFqcn = $versionedNamespace . '\\' . $shortName;

        // Re-evaluating identical content would just reuse the same versioned
        // class, so short-circuit once it exists (e.g. reverting to a prior
        // version whose namespace was already materialized).
        if (class_exists($versionedFqcn, false)) {
            $this->loadedContentHashes[$fqcn] = $contentHash;
            return $versionedFqcn;
        }

        // Rewrite the namespace declaration so the class can be redefined under
        // a fresh, content-addressed namespace and the updated code runs.
        $rewritten = $this->rewriteNamespace($source, $namespace, $versionedNamespace);
        if ($rewritten === null) {
            // Could not safely rewrite: keep the already-loaded definition.
            return $fqcn;
        }

        try {
            eval('?>' . $rewritten);
        } catch (\Throwable $e) {
            $errorMsg = "Failed to hot-reload plugin {$fqcn}: " . $e->getMessage();
            if ($this->logger !== null) {
                $this->logger->error($errorMsg);
            } else {
                error_log($errorMsg);
            }
            return class_exists($fqcn) ? $fqcn : null;
        }

        if (class_exists($versionedFqcn, false)) {
            $this->loadedContentHashes[$fqcn] = $contentHash;
            return $versionedFqcn;
        }

        return class_exists($fqcn) ? $fqcn : null;
    }

    /**
     * Rewrite the top-level namespace declaration of a plugin's source
     *
     * @param string $source Original PHP source
     * @param string $oldNamespace The namespace currently declared (may be empty)
     * @param string $newNamespace The replacement namespace
     * @return string|null Rewritten source, or null if it could not be rewritten safely
     */
    private function rewriteNamespace(string $source, string $oldNamespace, string $newNamespace): ?string
    {
        if ($oldNamespace === '') {
            // Rewriting global-namespace plugins would require injecting a
            // namespace wrapper around use-statements; not supported.
            return null;
        }

        $pattern = '/\bnamespace\s+' . preg_quote($oldNamespace, '/') . '\s*;/';
        $replacement = 'namespace ' . $newNamespace . ';';

        $rewritten = preg_replace($pattern, $replacement, $source, 1, $count);

        if ($rewritten === null || $count !== 1) {
            return null;
        }

        return $rewritten;
    }

    /**
     * Register a plugin with the core capabilities
     *
     * @param PluginInterface $plugin The plugin instance to register
     * @param string $namespacePrefix The plugin namespace prefix
     * @param string $pluginKey Stable identity (original FQCN) for bookkeeping
     * @return void
     */
    private function registerPlugin(
        PluginInterface $plugin,
        string $namespacePrefix,
        string $pluginKey
    ): void {
        // Establish the plugin's lifecycle: discovered -> loaded. It becomes
        // active once its capabilities are registered below.
        $lifecycle = new PluginLifecycle($pluginKey, $plugin->getName());
        $lifecycle->markLoaded();
        $this->lifecycles[$pluginKey] = $lifecycle;

        $registered = $this->registerCapabilities($plugin, $namespacePrefix, $pluginKey);

        // Seed plugin-declared roles and permission grants into the database.
        // The seeder is optional (not wired in tests that lack a DB); errors
        // are swallowed internally so a seeding failure never prevents the
        // plugin from becoming active.
        if ($this->roleSeeder !== null && $plugin instanceof PluginRolesInterface) {
            $tenantId = TenantContext::getTenantId() ?? PluginRoleSeeder::SYSTEM_TENANT_ID;
            $this->roleSeeder->seed($plugin, $tenantId);
        }

        // Store the plugin instance and its registration bookkeeping
        $this->plugins[] = $plugin;
        $this->registeredPlugins[$pluginKey] = [
            'plugin' => $plugin,
            'namespacePrefix' => $namespacePrefix,
            'hooks' => $registered['hooks'],
            'frontendFeatures' => $registered['frontendFeatures'],
        ];

        // The plugin is now fully registered and ready to serve.
        $lifecycle->markActive();
    }

    /**
     * Register a plugin's routes, permissions, and hooks with the core services.
     *
     * Shared by initial registration and by re-enable, so a plugin that was
     * administratively disabled (its routes/hooks removed) can be brought back
     * online without re-reading or re-instantiating it from disk. Returns the
     * hook subscriptions actually registered (so the caller can record them
     * for later unsubscription) and the validated frontend feature descriptors
     * (WC-169).
     *
     * @param PluginInterface $plugin The plugin instance to register.
     * @param string $namespacePrefix The plugin namespace prefix.
     * @param string $pluginKey Stable identity (original FQCN) for bookkeeping.
     * @return array{hooks: array<array{event: string, callback: callable}>, frontendFeatures: list<array<string, mixed>>}
     */
    private function registerCapabilities(
        PluginInterface $plugin,
        string $namespacePrefix,
        string $pluginKey
    ): array {
        // Snapshot the declared routes ONCE so registration and the frontend
        // descriptor validation below judge the same declaration set.
        $routes = $plugin->getRoutes();

        // GET routes the router ACTUALLY accepted for this plugin, mapped to
        // their requiredPermission. Frontend descriptor ownership (rule c) is
        // judged against this — what registered, not what was declared — so a
        // route refused for colliding with core can never back a screen.
        $registeredGetRoutes = [];
        // POST/PUT routes the router ACTUALLY accepted, mapped to their
        // requiredPermission — the ownership basis for an 'action' frontend
        // screen, exactly as $registeredGetRoutes backs a 'crud' screen.
        $registeredActionRoutes = [];

        // 1. Register routes with the router, each wrapped in an error boundary
        //    so a throwing handler cannot crash the host or other plugins.
        foreach ($routes as $route) {
            $method = $route['method'];
            $path = $route['path'];
            $handler = $route['handler'];
            $requiredRole = $route['requiredRole'] ?? null;

            // Route-level requiredPermission (SDK 1.2, WC-169): validated and
            // passed through to the router so RbacMiddleware enforces it. A
            // malformed declaration fails CLOSED — the route is NOT registered,
            // because registering it without the permission would silently
            // serve a route its author believed protected.
            $requiredPermission = $route['requiredPermission'] ?? null;
            if (
                $requiredPermission !== null
                && (!is_string($requiredPermission) || preg_match(self::PERMISSION_PATTERN, $requiredPermission) !== 1)
            ) {
                $this->logWarning(
                    "Plugin {$pluginKey} route {$method} {$path} declares an invalid requiredPermission; "
                    . 'the route was NOT registered (fail-closed).'
                );
                continue;
            }

            if (is_callable($handler)) {
                // The optional 'schema' key (WC-166) carries the route's typed
                // OpenAPI declaration through to the router/generator.
                $schema = isset($route['schema']) && is_array($route['schema']) ? $route['schema'] : null;

                $accepted = $this->router->register(
                    $method,
                    $path,
                    $this->wrapHandler($pluginKey, $handler),
                    $requiredRole,
                    $namespacePrefix,
                    $requiredPermission,
                    $schema
                );

                // First registration wins (core routes register before plugins
                // since WC-169): a colliding plugin route is refused so it can
                // never shadow an existing handler. The refusal is logged and
                // the path is NOT recorded as plugin-owned, so a frontend
                // descriptor over it fails ownership validation below.
                if (!$accepted) {
                    $this->logWarning(
                        "Plugin {$pluginKey} route {$method} {$path} collides with an already-registered "
                        . 'route and was NOT registered (first registration wins).'
                    );
                    continue;
                }

                $upperMethod = strtoupper((string) $method);
                if ($upperMethod === 'GET') {
                    $registeredGetRoutes[$path] = $requiredPermission;
                } elseif ($upperMethod === 'POST' || $upperMethod === 'PUT') {
                    $registeredActionRoutes["{$upperMethod} {$path}"] = $requiredPermission;
                }
            }
        }

        // 2. Register permissions with the permission registry. Permissions are
        //    validated against the `resource:action` pattern; a plugin declaring a
        //    malformed permission is rejected with a logged warning rather than
        //    crashing the host (per-plugin error boundary, same as routes/hooks).
        if ($this->permissionRegistry !== null) {
            try {
                $this->permissionRegistry->register($plugin->getName(), $plugin->getPermissions());
            } catch (InvalidPermissionException $e) {
                $warningMsg = "Plugin {$pluginKey} declares an invalid permission: " . $e->getMessage();
                if ($this->logger !== null) {
                    $this->logger->warning($warningMsg);
                } else {
                    error_log($warningMsg);
                }
            }
        }

        // 2b. Claim permission OWNERSHIP for descriptor gating (WC-169 review
        //     hardening): the first plugin (discovery order) to declare a name
        //     owns it; re-claiming one's own name (re-enable) is idempotent.
        //     Ownership only affects frontend descriptors — route-level RBAC
        //     and the registry are untouched.
        try {
            foreach ($plugin->getPermissions() as $permission) {
                if (is_string($permission) && !isset($this->claimedPermissions[$permission])) {
                    $this->claimedPermissions[$permission] = $pluginKey;
                }
            }
        } catch (Throwable $e) {
            $this->handlePluginThrowable($pluginKey, $e, 'permission declaration');
        }

        // 3. Register hooks with the hook manager, tracking each subscription so
        //    it can be unsubscribed on a later reload/removal/disable. Hook
        //    callbacks are wrapped in the same error boundary as route handlers.
        $registeredHooks = [];
        if ($this->hookManager !== null) {
            foreach ($plugin->getHooks() as $eventName => $hookData) {
                foreach ($this->registerHook($pluginKey, $eventName, $hookData) as $callback) {
                    $registeredHooks[] = ['event' => $eventName, 'callback' => $callback];
                }
            }
        }

        // 4. Collect and validate the plugin's frontend feature descriptors
        //    (WC-169). Inside the same per-plugin error boundary philosophy:
        //    invalid descriptors are dropped with a warning, a throwing
        //    declaration contributes nothing, and the plugin keeps loading.
        $frontendFeatures = $this->collectFrontendFeatures(
            $plugin,
            $pluginKey,
            $registeredGetRoutes,
            $registeredActionRoutes
        );

        return ['hooks' => $registeredHooks, 'frontendFeatures' => $frontendFeatures];
    }

    /**
     * Collect, validate, and normalize a plugin's frontend feature descriptors.
     *
     * Validation is fail-closed and PER DESCRIPTOR (an invalid descriptor is
     * dropped with a logged warning naming the plugin, the descriptor id when
     * present, and the exact reason — the plugin itself keeps loading):
     *
     *  (a) shape — id/label/screen/requiredPermission present and well-typed;
     *      id matches the kebab-case slug pattern; screen ∈ {crud, custom, action};
     *  (b) requiredPermission matches the `resource:action` pattern, is one of
     *      the permissions THIS plugin declares via getPermissions(), is NOT a
     *      core permission name (self-declaring 'users:read' does not make it
     *      ownable), and is OWNED by this plugin (first declarant across all
     *      plugins wins) — a descriptor gated on a foreign permission cannot
     *      expose a screen over someone else's resource;
     *  (c) screen='crud' requires resource.basePath (string starting '/api/');
     *      the plugin must have ACTUALLY REGISTERED a GET route at exactly
     *      basePath (a declaration the router refused — e.g. a collision with
     *      a core route — does not count), and that route's requiredPermission
     *      must EQUAL the descriptor's (a menu gated on X over a data route
     *      gated on Y, or unprotected, fails closed);
     *  (c2) screen='action' requires an action.{method,path} pointing at a
     *      POST/PUT route THIS plugin actually registered, whose
     *      requiredPermission EQUALS the descriptor's — same ownership +
     *      gate-alignment rule as (c), for a route rather than a collection.
     *      action.fields (optional) declare the generic form's inputs;
     *  (d) ids are unique across all plugins (first claimant wins).
     *
     * Surviving descriptors are normalized: defaults filled (group 'plugins',
     * order 100, icon null, titleField null, resource null for resource-less
     * custom/action screens, action null for non-action screens) and the owning
     * plugin name attached under 'plugin'.
     *
     * @param PluginInterface $plugin The plugin being registered.
     * @param string $pluginKey Stable identity (original FQCN) for bookkeeping.
     * @param array<string, string|null> $registeredGetRoutes GET path => requiredPermission, ACTUALLY registered for this plugin.
     * @param array<string, string|null> $registeredActionRoutes "METHOD /path" => requiredPermission, POST/PUT routes ACTUALLY registered for this plugin.
     * @return list<array<string, mixed>> The validated, normalized descriptors.
     */
    private function collectFrontendFeatures(
        PluginInterface $plugin,
        string $pluginKey,
        array $registeredGetRoutes,
        array $registeredActionRoutes = []
    ): array {
        if (!$plugin instanceof PluginFrontendInterface) {
            return [];
        }

        // A throwing declaration must not break the plugin (or its peers):
        // like the other capability failures it is logged and contributes
        // nothing. getPermissions() is snapshotted under the same boundary
        // because rule (b) depends on it.
        try {
            $declared = $plugin->getFrontendFeatures();
            $ownPermissions = $plugin->getPermissions();
        } catch (Throwable $e) {
            $this->handlePluginThrowable($pluginKey, $e, 'frontend feature declaration');
            return [];
        }

        $validated = [];
        foreach ($declared as $descriptor) {
            $normalized = $this->validateFrontendFeature(
                $descriptor,
                $plugin->getName(),
                $pluginKey,
                $ownPermissions,
                $registeredGetRoutes,
                $registeredActionRoutes
            );
            if ($normalized !== null) {
                $validated[] = $normalized;
            }
        }

        return $validated;
    }

    /**
     * Validate and normalize a single frontend feature descriptor.
     *
     * See {@see collectFrontendFeatures()} for the rule catalogue. Returns the
     * normalized descriptor, or null when it was dropped (the exact reason is
     * logged as a warning).
     *
     * @param mixed $descriptor The raw descriptor as declared by the plugin.
     * @param string $pluginName The plugin's declared name (attached as 'plugin').
     * @param string $pluginKey Stable identity (original FQCN) for log messages.
     * @param array<int|string, mixed> $ownPermissions The plugin's own declared permissions.
     * @param array<string, string|null> $registeredGetRoutes GET path => requiredPermission, ACTUALLY registered for this plugin.
     * @param array<string, string|null> $registeredActionRoutes "METHOD /path" => requiredPermission, POST/PUT routes ACTUALLY registered for this plugin.
     * @return array<string, mixed>|null The normalized descriptor, or null when dropped.
     */
    private function validateFrontendFeature(
        mixed $descriptor,
        string $pluginName,
        string $pluginKey,
        array $ownPermissions,
        array $registeredGetRoutes,
        array $registeredActionRoutes = []
    ): ?array {
        $drop = function (string $reason, ?string $id) use ($pluginKey): null {
            $idLabel = $id !== null ? "'{$id}'" : '(no id)';
            $this->logWarning(
                "Plugin {$pluginKey} frontend feature {$idLabel} dropped: {$reason}"
            );
            return null;
        };

        if (!is_array($descriptor)) {
            return $drop('descriptor must be an associative array', null);
        }

        // (a) shape: id / label / screen.
        $id = $descriptor['id'] ?? null;
        if (!is_string($id) || preg_match(self::FEATURE_ID_PATTERN, $id) !== 1) {
            return $drop('id must be a kebab-case slug matching ' . self::FEATURE_ID_PATTERN, is_string($id) ? $id : null);
        }

        $label = $descriptor['label'] ?? null;
        if (!is_string($label) || $label === '') {
            return $drop('label must be a non-empty string', $id);
        }

        $screen = $descriptor['screen'] ?? null;
        if ($screen !== 'crud' && $screen !== 'custom' && $screen !== 'action') {
            return $drop("screen must be 'crud', 'custom', or 'action'", $id);
        }

        // (b) requiredPermission: well-formed AND owned by THIS plugin. A
        // descriptor gated on a core permission or another plugin's permission
        // is rejected — it could otherwise expose a screen over a resource the
        // plugin does not own. Fail-closed: no permissionless screens.
        $permission = $descriptor['requiredPermission'] ?? null;
        if (!is_string($permission) || preg_match(self::PERMISSION_PATTERN, $permission) !== 1) {
            return $drop('requiredPermission must be a resource:action permission string', $id);
        }
        if (!in_array($permission, $ownPermissions, true)) {
            return $drop(
                "requiredPermission '{$permission}' is not declared by this plugin's getPermissions()",
                $id
            );
        }
        // Ownership is NOT self-asserted (review hardening): a core permission
        // name is never plugin-ownable even when self-declared, and across
        // plugins the FIRST declarant (discovery order) owns the name.
        if (in_array($permission, CorePermissions::all(), true)) {
            return $drop(
                "requiredPermission '{$permission}' collides with a core permission — core names are not plugin-ownable",
                $id
            );
        }
        $permissionOwner = $this->claimedPermissions[$permission] ?? null;
        if ($permissionOwner !== $pluginKey) {
            return $drop(
                "requiredPermission '{$permission}' is owned by plugin "
                . ($permissionOwner ?? '(unclaimed)') . ' (first declarant wins)',
                $id
            );
        }

        // (c) resource: REQUIRED for crud screens; when present (any screen)
        // it must point at the plugin's OWN REST collection — an exact-path
        // GET route in its own route declarations.
        $resource = null;
        $rawResource = $descriptor['resource'] ?? null;
        if ($screen === 'crud' && !is_array($rawResource)) {
            return $drop("screen 'crud' requires a resource array with basePath", $id);
        }
        if (is_array($rawResource)) {
            $basePath = $rawResource['basePath'] ?? null;
            if (!is_string($basePath) || !str_starts_with($basePath, '/api/')) {
                return $drop("resource.basePath must be a string starting with '/api/'", $id);
            }
            // Ownership is judged by what the router ACTUALLY registered for
            // this plugin — a declared route the router refused (e.g. a
            // collision with a core route) can never back a screen.
            if (!array_key_exists($basePath, $registeredGetRoutes)) {
                return $drop(
                    "resource.basePath '{$basePath}' is not a GET route this plugin registered",
                    $id
                );
            }
            // The menu gate and the data gate must be the SAME permission: a
            // screen gated on X over a route gated on Y (or unprotected) is a
            // misalignment that fails closed.
            if ($registeredGetRoutes[$basePath] !== $permission) {
                return $drop(
                    "resource.basePath '{$basePath}' route requiredPermission '"
                    . ($registeredGetRoutes[$basePath] ?? 'none')
                    . "' does not match the descriptor's '{$permission}'",
                    $id
                );
            }

            $titleField = $rawResource['titleField'] ?? null;
            if ($titleField !== null && !is_string($titleField)) {
                return $drop('resource.titleField must be a string when present', $id);
            }

            // The plugin declared the unversioned path (e.g. /api/hello/greetings).
            // Rewrite it to the versioned URL the browser must actually call so the
            // normalized descriptor is ready to use without further transformation.
            $vp = $this->router->getVersionPrefix();
            if ($vp !== '') {
                $pos = strpos($basePath, '/', 1);
                $basePath = $pos === false
                    ? $basePath . $vp
                    : substr($basePath, 0, $pos) . $vp . substr($basePath, $pos);
            }

            $resource = ['basePath' => $basePath, 'titleField' => $titleField];
        }

        // (c2) action: REQUIRED for action screens. Declares the POST/PUT route
        // the host's generic action form submits to (a JSON body) and the
        // optional input fields it renders. Ownership is judged exactly like
        // crud's basePath — the plugin must have ACTUALLY REGISTERED that
        // POST/PUT route and its requiredPermission must EQUAL the descriptor's.
        $action = null;
        if ($screen === 'action') {
            $rawAction = $descriptor['action'] ?? null;
            if (!is_array($rawAction)) {
                return $drop("screen 'action' requires an action array with method/path", $id);
            }

            $method = $rawAction['method'] ?? null;
            if (!is_string($method) || !in_array(strtoupper($method), ['POST', 'PUT'], true)) {
                return $drop("action.method must be 'POST' or 'PUT'", $id);
            }
            $method = strtoupper($method);

            $path = $rawAction['path'] ?? null;
            if (!is_string($path) || !str_starts_with($path, '/api/')) {
                return $drop("action.path must be a string starting with '/api/'", $id);
            }
            $routeKey = "{$method} {$path}";
            if (!array_key_exists($routeKey, $registeredActionRoutes)) {
                return $drop("action.path '{$path}' is not a {$method} route this plugin registered", $id);
            }
            if ($registeredActionRoutes[$routeKey] !== $permission) {
                return $drop(
                    "action.path '{$path}' route requiredPermission '"
                    . ($registeredActionRoutes[$routeKey] ?? 'none')
                    . "' does not match the descriptor's '{$permission}'",
                    $id
                );
            }

            // Input fields the generic form renders (optional). A 'file' field
            // is read client-side as TEXT into the named JSON property (the host
            // is a JSON API); binary uploads are out of scope here.
            $fields = [];
            $rawFields = $rawAction['fields'] ?? [];
            if (!is_array($rawFields)) {
                return $drop('action.fields must be a list when present', $id);
            }
            foreach ($rawFields as $rawField) {
                if (!is_array($rawField)) {
                    return $drop('each action.fields entry must be an array', $id);
                }
                $fieldName = $rawField['name'] ?? null;
                if (!is_string($fieldName) || $fieldName === '') {
                    return $drop('action.fields[].name must be a non-empty string', $id);
                }
                $fieldLabel = $rawField['label'] ?? $fieldName;
                if (!is_string($fieldLabel) || $fieldLabel === '') {
                    return $drop("action.fields '{$fieldName}' label must be a non-empty string", $id);
                }
                $fieldKind = $rawField['kind'] ?? 'text';
                if (!in_array($fieldKind, ['text', 'textarea', 'file'], true)) {
                    return $drop("action.fields '{$fieldName}' kind must be text, textarea, or file", $id);
                }
                $fieldAccept = $rawField['accept'] ?? null;
                if ($fieldAccept !== null && !is_string($fieldAccept)) {
                    return $drop("action.fields '{$fieldName}' accept must be a string when present", $id);
                }
                $fieldRequired = $rawField['required'] ?? false;
                if (!is_bool($fieldRequired)) {
                    return $drop("action.fields '{$fieldName}' required must be a boolean when present", $id);
                }
                $fields[] = [
                    'name' => $fieldName,
                    'label' => $fieldLabel,
                    'kind' => $fieldKind,
                    'accept' => $fieldAccept,
                    'required' => $fieldRequired,
                ];
            }

            $submitLabel = $rawAction['submitLabel'] ?? null;
            if ($submitLabel !== null && !is_string($submitLabel)) {
                return $drop('action.submitLabel must be a string when present', $id);
            }

            // Same versioning rewrite as for resource.basePath above.
            $vp = $this->router->getVersionPrefix();
            if ($vp !== '') {
                $pos = strpos($path, '/', 1);
                $path = $pos === false
                    ? $path . $vp
                    : substr($path, 0, $pos) . $vp . substr($path, $pos);
            }

            $action = [
                'method' => $method,
                'path' => $path,
                'submitLabel' => $submitLabel,
                'fields' => $fields,
            ];
        }

        // Optional presentation fields: type-checked fail-closed (a mistyped
        // declaration is a bug worth surfacing, not silently defaulting).
        $icon = $descriptor['icon'] ?? null;
        if ($icon !== null && !is_string($icon)) {
            return $drop('icon must be a string when present', $id);
        }
        $group = $descriptor['group'] ?? 'plugins';
        if (!is_string($group) || $group === '') {
            return $drop('group must be a non-empty string when present', $id);
        }
        $order = $descriptor['order'] ?? 100;
        if (!is_int($order)) {
            return $drop('order must be an integer when present', $id);
        }

        // (d) cross-plugin id uniqueness: first claimant (discovery order)
        // wins; re-claiming one's own id (re-enable) is idempotent.
        $claimant = $this->claimedFeatureIds[$id] ?? null;
        if ($claimant !== null && $claimant !== $pluginKey) {
            return $drop("id '{$id}' is already claimed by plugin {$claimant} (first wins)", $id);
        }
        $this->claimedFeatureIds[$id] = $pluginKey;

        return [
            'id' => $id,
            'plugin' => $pluginName,
            'label' => $label,
            'icon' => $icon,
            'group' => $group,
            'order' => $order,
            'screen' => $screen,
            'resource' => $resource,
            'action' => $action,
            'requiredPermission' => $permission,
        ];
    }

    /**
     * Get every active plugin's validated frontend feature descriptors (WC-169).
     *
     * Flat list across plugins, each entry carrying its owning plugin's name
     * under 'plugin'. Plugins that are administratively disabled, auto-failed,
     * or otherwise not in the active lifecycle state contribute NOTHING — the
     * features reappear when the plugin is re-enabled.
     *
     * @return list<array<string, mixed>> The exposed descriptors.
     */
    public function getFrontendFeatures(): array
    {
        $features = [];
        foreach ($this->registeredPlugins as $pluginKey => $info) {
            if (isset($this->administrativelyDisabled[$pluginKey])) {
                continue;
            }

            $lifecycle = $this->lifecycles[$pluginKey] ?? null;
            if ($lifecycle === null || !$lifecycle->isActive()) {
                continue;
            }

            foreach ($info['frontendFeatures'] as $feature) {
                $features[] = $feature;
            }
        }

        return $features;
    }

    /**
     * Log a warning through the wired logger, falling back to error_log.
     *
     * @param string $message The warning message.
     * @return void
     */
    private function logWarning(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);
        } else {
            error_log($message);
        }
    }

    /**
     * Wrap a plugin route handler in a per-plugin error boundary.
     *
     * The returned closure has the same calling convention the kernel uses
     * (Request, params) so it can be registered transparently in place of the
     * raw handler. It:
     *  - short-circuits with a safe 503 when the plugin is already failed;
     *  - catches any Throwable, logs it (structured, with stack trace and
     *    tenant_id), records the error against the plugin's lifecycle, and
     *    returns a safe 500 without leaking the exception to the client;
     *  - resets the consecutive-error counter on a successful invocation.
     *
     * @param string $pluginKey The plugin's stable identity (original FQCN).
     * @param callable $handler The raw plugin route handler.
     * @return callable(Request, array<string, string>): Response
     */
    private function wrapHandler(string $pluginKey, callable $handler): callable
    {
        return function (Request $request, array $params = []) use ($pluginKey, $handler): Response {
            $lifecycle = $this->lifecycles[$pluginKey] ?? null;

            if ($lifecycle !== null && $lifecycle->isFailed()) {
                return Response::error('Plugin temporarily unavailable', 503);
            }

            try {
                $result = $handler($request, $params);

                // A plugin that does not return a Response is misbehaving; treat
                // it as a failure rather than letting a bad value escape.
                if (!$result instanceof Response) {
                    throw new \UnexpectedValueException(
                        'Plugin handler did not return a Response instance'
                    );
                }

                $lifecycle?->recordSuccess();

                return $result;
            } catch (Throwable $e) {
                $this->handlePluginThrowable($pluginKey, $e, 'route handler');
                return Response::error('Internal plugin error', 500);
            }
        };
    }

    /**
     * Wrap a plugin hook callback in a per-plugin error boundary.
     *
     * A throwing hook callback is isolated so the surrounding dispatch loop
     * continues. On error the original data is returned unchanged so the failing
     * listener cannot corrupt the pipeline.
     *
     * @param string $pluginKey The plugin's stable identity (original FQCN).
     * @param callable $callback The raw hook callback.
     * @return callable(array<mixed>, array<mixed>): array<mixed>
     */
    private function wrapHookCallback(string $pluginKey, callable $callback): callable
    {
        return function (array $data, array $context = []) use ($pluginKey, $callback): array {
            $lifecycle = $this->lifecycles[$pluginKey] ?? null;

            if ($lifecycle !== null && $lifecycle->isFailed()) {
                return $data;
            }

            try {
                $result = $callback($data, $context);
                $lifecycle?->recordSuccess();
                return is_array($result) ? $result : $data;
            } catch (Throwable $e) {
                $this->handlePluginThrowable($pluginKey, $e, 'hook callback');
                return $data;
            }
        };
    }

    /**
     * Log and record a Throwable raised by a plugin invocation.
     *
     * @param string $pluginKey The plugin's stable identity (original FQCN).
     * @param Throwable $e The throwable raised by the plugin.
     * @param string $boundary Human-readable description of the boundary that caught it.
     * @return void
     */
    private function handlePluginThrowable(string $pluginKey, Throwable $e, string $boundary): void
    {
        $lifecycle = $this->lifecycles[$pluginKey] ?? null;
        $lifecycle?->recordError($e);

        $context = [
            'plugin' => $pluginKey,
            'boundary' => $boundary,
            'tenant_id' => TenantContext::getTenantId(),
            'exception' => $e::class,
            'trace' => $e->getTraceAsString(),
            'consecutive_errors' => $lifecycle?->getConsecutiveErrors() ?? 0,
            'state' => $lifecycle?->getState()->value,
        ];

        $message = sprintf(
            'Plugin "%s" threw in %s: %s',
            $pluginKey,
            $boundary,
            $e->getMessage()
        );

        if ($this->logger !== null) {
            $this->logger->error($message, $context);
        } else {
            error_log($message . ' ' . $e->getTraceAsString());
        }
    }

    /**
     * Helper to register a hook subscription
     *
     * Each plugin callback is wrapped in a per-plugin error boundary before being
     * handed to the HookManager. The returned callables are the wrapped versions,
     * so the registration bookkeeping records exactly what was subscribed and can
     * unsubscribe it cleanly on a later reload/removal.
     *
     * @param string $pluginKey The plugin's stable identity (original FQCN)
     * @param string $eventName Event name
     * @param mixed $hookData Callback or structured configuration
     * @return array<callable> The callbacks that were registered
     */
    private function registerHook(string $pluginKey, string $eventName, mixed $hookData): array
    {
        if ($this->hookManager === null) {
            return [];
        }

        $registered = [];

        if (is_callable($hookData)) {
            $wrapped = $this->wrapHookCallback($pluginKey, $hookData);
            $this->hookManager->listen($eventName, $wrapped);
            $registered[] = $wrapped;
        } elseif (is_array($hookData)) {
            // Check if it is a single structured subscription with a callback
            if (isset($hookData['callback']) && is_callable($hookData['callback'])) {
                $priority = $hookData['priority'] ?? 10;
                $wrapped = $this->wrapHookCallback($pluginKey, $hookData['callback']);
                $this->hookManager->listen($eventName, $wrapped, $priority);
                $registered[] = $wrapped;
            } else {
                // Check if it is a list of callbacks/subscriptions
                foreach ($hookData as $sub) {
                    if (is_array($sub) && isset($sub['callback']) && is_callable($sub['callback'])) {
                        $priority = $sub['priority'] ?? 10;
                        $wrapped = $this->wrapHookCallback($pluginKey, $sub['callback']);
                        $this->hookManager->listen($eventName, $wrapped, $priority);
                        $registered[] = $wrapped;
                    } elseif (is_callable($sub)) {
                        $wrapped = $this->wrapHookCallback($pluginKey, $sub);
                        $this->hookManager->listen($eventName, $wrapped);
                        $registered[] = $wrapped;
                    }
                }
            }
        }

        return $registered;
    }


    /**
     * Load plugin manifest from cache file
     *
     * @return array<string, string>|null List of plugin classes and files, or null if cache miss/disabled
     */
    private function loadManifest(): ?array
    {
        if ($this->cacheFile && file_exists($this->cacheFile)) {
            try {
                $content = file_get_contents($this->cacheFile);
                if ($content !== false) {
                    $manifest = json_decode($content, true);
                    if (is_array($manifest) && isset($manifest['plugins']) && is_array($manifest['plugins'])) {
                        return $manifest['plugins'];
                    }
                }
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->warning("Failed to load plugin manifest: " . $e->getMessage());
                }
            }
        }
        return null;
    }

    /**
     * Save plugin manifest to cache file
     *
     * @param array<string, string> $pluginsData List of plugin classes and files
     * @return void
     */
    private function saveManifest(array $pluginsData): void
    {
        if ($this->cacheFile) {
            try {
                $manifest = [
                    'scanned_at' => time(),
                    'plugins' => $pluginsData
                ];
                $content = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($content !== false) {
                    // Ensure directory exists
                    $dir = dirname($this->cacheFile);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    file_put_contents($this->cacheFile, $content);
                }
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->warning("Failed to save plugin manifest: " . $e->getMessage());
                }
            }
        }
    }
}

