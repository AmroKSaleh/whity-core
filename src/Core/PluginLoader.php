<?php

declare(strict_types=1);

namespace Whity\Core;

use ReflectionClass;
use ReflectionException;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Hooks\HookManager;
use Psr\Log\LoggerInterface;

/**
 * Plugin loader for dynamic discovery and registration
 *
 * Scans a directory for PHP files, uses reflection to check if they implement
 * PluginInterface, and registers their routes, permissions, and hooks.
 */
class PluginLoader
{
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
     * @var array<PluginInterface> Registered plugins
     */
    private array $plugins = [];

    /**
     * Registration bookkeeping per plugin, keyed by the plugin's original FQCN.
     *
     * Tracks what each plugin registered so it can be cleanly unregistered when
     * its file is modified or removed during a hot reload.
     *
     * @var array<string, array{namespacePrefix: string, hooks: array<array{event: string, callback: callable}>}>
     */
    private array $registeredPlugins = [];

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
     * @param string $pluginDir Directory path containing plugin files
     * @param Router $router Router instance to register plugins with
     * @param PermissionRegistry|null $permissionRegistry Optional permission registry
     * @param HookManager|null $hookManager Optional hook manager
     * @param LoggerInterface|null $logger Optional logger instance
     */
    public function __construct(
        string $pluginDir,
        Router $router,
        ?PermissionRegistry $permissionRegistry = null,
        ?HookManager $hookManager = null,
        ?LoggerInterface $logger = null
    ) {
        $this->pluginDir = $pluginDir;
        $this->router = $router;
        $this->permissionRegistry = $permissionRegistry;
        $this->hookManager = $hookManager;
        $this->logger = $logger;

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
        $discovered = $this->discover();
        foreach ($discovered as $fqcn => $filePath) {
            $this->loadPluginClass($fqcn, $filePath);
        }

        $this->fingerprint = $this->computeFingerprint();
        $this->loaded = true;
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
     *    actually runs (see loadPluginClass()).
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
        // uniformly handles additions, modifications, and removals.
        $this->unregisterAll();

        $discovered = $this->discover();
        foreach ($discovered as $fqcn => $filePath) {
            $this->loadPluginClass($fqcn, $filePath);
        }

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
    private function loadPluginClass(string $fqcn, string $filePath): void
    {
        $effectiveFqcn = $this->materializeClass($fqcn, $filePath);
        if ($effectiveFqcn === null) {
            return;
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
                return;
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
            return;
        }

        // Register the plugin under its original FQCN identity
        $this->registerPlugin($plugin, $namespacePrefix, $fqcn);
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
        // 1. Register routes with the router
        foreach ($plugin->getRoutes() as $route) {
            $method = $route['method'];
            $path = $route['path'];
            $handler = $route['handler'];
            $requiredRole = $route['requiredRole'] ?? null;

            if (is_callable($handler)) {
                $this->router->register(
                    $method,
                    $path,
                    $handler,
                    $requiredRole,
                    $namespacePrefix
                );
            }
        }

        // 2. Register permissions with the permission registry
        if ($this->permissionRegistry !== null) {
            $this->permissionRegistry->registerPermissions($plugin->getName(), $plugin->getPermissions());
        }

        // 3. Register hooks with the hook manager, tracking each subscription so
        //    it can be unsubscribed on a later reload/removal.
        $registeredHooks = [];
        if ($this->hookManager !== null) {
            foreach ($plugin->getHooks() as $eventName => $hookData) {
                foreach ($this->registerHook($eventName, $hookData) as $callback) {
                    $registeredHooks[] = ['event' => $eventName, 'callback' => $callback];
                }
            }
        }

        // Store the plugin instance and its registration bookkeeping
        $this->plugins[] = $plugin;
        $this->registeredPlugins[$pluginKey] = [
            'namespacePrefix' => $namespacePrefix,
            'hooks' => $registeredHooks,
        ];
    }

    /**
     * Helper to register a hook subscription
     *
     * @param string $eventName Event name
     * @param mixed $hookData Callback or structured configuration
     * @return array<callable> The callbacks that were registered
     */
    private function registerHook(string $eventName, mixed $hookData): array
    {
        if ($this->hookManager === null) {
            return [];
        }

        $registered = [];

        if (is_callable($hookData)) {
            $this->hookManager->listen($eventName, $hookData);
            $registered[] = $hookData;
        } elseif (is_array($hookData)) {
            // Check if it is a single structured subscription with a callback
            if (isset($hookData['callback']) && is_callable($hookData['callback'])) {
                $priority = $hookData['priority'] ?? 10;
                $this->hookManager->listen($eventName, $hookData['callback'], $priority);
                $registered[] = $hookData['callback'];
            } else {
                // Check if it is a list of callbacks/subscriptions
                foreach ($hookData as $sub) {
                    if (is_array($sub) && isset($sub['callback']) && is_callable($sub['callback'])) {
                        $priority = $sub['priority'] ?? 10;
                        $this->hookManager->listen($eventName, $sub['callback'], $priority);
                        $registered[] = $sub['callback'];
                    } elseif (is_callable($sub)) {
                        $this->hookManager->listen($eventName, $sub);
                        $registered[] = $sub;
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

