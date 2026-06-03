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
    }

    /**
     * Load and register plugins from the plugin directory
     *
     * Scans the plugin directory for *.php files, requires each one,
     * extracts the class name, checks if it implements PluginInterface,
     * and registers all declared capabilities.
     *
     * @return void
     */
    public function load(): void
    {
        if (!is_dir($this->pluginDir)) {
            return;
        }

        $files = scandir($this->pluginDir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            // Skip directories and non-PHP files
            if ($file === '.' || $file === '..' || pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            $filePath = $this->pluginDir . '/' . $file;
            $this->loadPlugin($filePath);
        }
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
     * Load a single plugin file and register it if it's a valid plugin
     *
     * @param string $filePath Path to the plugin file
     * @return void
     */
    private function loadPlugin(string $filePath): void
    {
        // Require the file
        require_once $filePath;

        // Extract class name from file path
        $className = $this->extractClassName(basename($filePath));

        // Build fully qualified class name
        $fqcn = 'Whity\\Plugins\\' . $className;

        // Check if class exists
        if (!class_exists($fqcn)) {
            return;
        }

        // Use reflection to check if it implements PluginInterface
        $reflectionClass = new ReflectionClass($fqcn);

        // Check if the class implements PluginInterface
        if (!$reflectionClass->implementsInterface(PluginInterface::class)) {
            $warningMsg = "Plugin class {$fqcn} does not implement PluginInterface.";
            if ($this->logger !== null) {
                $this->logger->warning($warningMsg);
            } else {
                error_log($warningMsg);
            }
            return;
        }

        // Instantiate the plugin
        try {
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
}

