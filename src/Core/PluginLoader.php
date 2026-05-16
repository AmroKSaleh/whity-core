<?php

namespace Whity\Core;

use ReflectionClass;
use ReflectionException;

/**
 * Plugin loader for dynamic discovery and registration
 *
 * Scans a directory for PHP files, uses reflection to check if they implement
 * PluginInterface, and registers them with the router.
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
     * @var array<PluginInterface> Registered plugins
     */
    private array $plugins = [];

    /**
     * Constructor
     *
     * @param string $pluginDir Directory path containing plugin files
     * @param Router $router Router instance to register plugins with
     */
    public function __construct(string $pluginDir, Router $router)
    {
        $this->pluginDir = $pluginDir;
        $this->router = $router;
    }

    /**
     * Load and register plugins from the plugin directory
     *
     * Scans the plugin directory for *.php files, requires each one,
     * extracts the class name, checks if it implements PluginInterface,
     * and registers it with the router.
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
        try {
            $reflectionClass = new ReflectionClass($fqcn);
        } catch (ReflectionException) {
            return;
        }

        // Check if the class implements PluginInterface
        if (!$reflectionClass->implementsInterface(PluginInterface::class)) {
            return;
        }

        // Instantiate the plugin
        try {
            $plugin = new $fqcn();
        } catch (\Throwable) {
            return;
        }

        // Register the plugin
        $this->registerPlugin($plugin);
    }

    /**
     * Register a plugin with the router
     *
     * @param PluginInterface $plugin The plugin instance to register
     * @return void
     */
    private function registerPlugin(PluginInterface $plugin): void
    {
        // Create a handler that calls the plugin's handle method
        $handler = function (Request $request) use ($plugin): Response {
            return $plugin->handle($request);
        };

        // Register with the router
        $this->router->register(
            $plugin->getMethod(),
            $plugin->getRoute(),
            $handler,
            $plugin->getRequiredRole()
        );

        // Store the plugin instance
        $this->plugins[] = $plugin;
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
