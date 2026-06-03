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

        spl_autoload_register(function (string $class) {
            foreach (self::$psr4Mappings as $prefix => $baseDir) {
                $len = strlen($prefix);
                if (strncmp($prefix, $class, $len) === 0) {
                    $relativeClass = substr($class, $len);
                    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                        return true;
                    }
                }
            }
            return false;
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
     * @return void
     */
    public function load(): void
    {
        $discovered = $this->discover();
        foreach ($discovered as $fqcn => $filePath) {
            $this->loadPluginClass($fqcn, $filePath);
        }
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

        $parts = explode('/', $relative);
        if (count($parts) === 0) {
            return null;
        }

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
     * @param string $fqcn Fully qualified class name of the plugin
     * @param string $filePath File path of the plugin
     * @return void
     */
    private function loadPluginClass(string $fqcn, string $filePath): void
    {
        // Require the file
        require_once $filePath;

        // Check if class exists
        if (!class_exists($fqcn)) {
            return;
        }

        // Use reflection to validate and get instance
        try {
            $reflectionClass = new ReflectionClass($fqcn);
            if (!$reflectionClass->implementsInterface(PluginInterface::class)) {
                return;
            }

            /** @var PluginInterface $plugin */
            $plugin = new $fqcn();
        } catch (\Throwable) {
            return;
        }

        // Register the plugin
        $this->registerPlugin($plugin);
    }

    /**
     * Register a plugin with the core capabilities
     *
     * @param PluginInterface $plugin The plugin instance to register
     * @return void
     */
    private function registerPlugin(PluginInterface $plugin): void
    {
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
                    $requiredRole
                );
            }
        }

        // 2. Register permissions with the permission registry
        if ($this->permissionRegistry !== null) {
            $this->permissionRegistry->registerPermissions($plugin->getName(), $plugin->getPermissions());
        }

        // 3. Register hooks with the hook manager
        if ($this->hookManager !== null) {
            foreach ($plugin->getHooks() as $eventName => $hookData) {
                $this->registerHook($eventName, $hookData);
            }
        }

        // Store the plugin instance
        $this->plugins[] = $plugin;
    }

    /**
     * Helper to register a hook subscription
     *
     * @param string $eventName Event name
     * @param mixed $hookData Callback or structured configuration
     * @return void
     */
    private function registerHook(string $eventName, mixed $hookData): void
    {
        if ($this->hookManager === null) {
            return;
        }

        if (is_callable($hookData)) {
            $this->hookManager->listen($eventName, $hookData);
        } elseif (is_array($hookData)) {
            // Check if it is a single structured subscription with a callback
            if (isset($hookData['callback']) && is_callable($hookData['callback'])) {
                $priority = $hookData['priority'] ?? 10;
                $this->hookManager->listen($eventName, $hookData['callback'], $priority);
            } else {
                // Check if it is a list of callbacks/subscriptions
                foreach ($hookData as $sub) {
                    if (is_array($sub) && isset($sub['callback']) && is_callable($sub['callback'])) {
                        $priority = $sub['priority'] ?? 10;
                        $this->hookManager->listen($eventName, $sub['callback'], $priority);
                    } elseif (is_callable($sub)) {
                        $this->hookManager->listen($eventName, $sub);
                    }
                }
            }
        }
    }

    /**
     * Extract class name from file path
     *
     * Converts file names like "AdminStats.php" to "AdminStats"
     * and converts snake_case file names to PascalCase class names
     *
     * @param string $filePath File name (not full path)
     * @return string Class name
     */
    private function extractClassName(string $filePath): string
    {
        // Remove .php extension
        $nameWithoutExt = pathinfo($filePath, PATHINFO_FILENAME);

        // Convert snake_case to PascalCase
        $parts = explode('_', $nameWithoutExt);
        $parts = array_map('ucfirst', $parts);

        return implode('', $parts);
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

