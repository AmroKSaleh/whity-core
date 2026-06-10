<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * The plugin contract for the Whity platform (SDK v1.0).
 *
 * Plugins implement this interface — and only this interface — to register
 * their capabilities (routes, permissions, hooks, and migrations) with a host
 * application. The SDK is a standalone package: implementing a plugin requires
 * `whity/plugin-sdk`, never the host framework itself, which is what makes a
 * plugin distributable across Whity-based applications.
 *
 * Route handlers receive a {@see \Whity\Sdk\Http\Request} and return a
 * {@see \Whity\Sdk\Http\Response}; hook names are catalogued as constants on
 * {@see \Whity\Sdk\Hooks\Events}; migrations implement
 * {@see \Whity\Sdk\MigrationInterface}.
 */
interface PluginInterface
{
    /**
     * Get the unique name of the plugin
     *
     * @return string Plugin name
     */
    public function getName(): string;

    /**
     * Get the version of the plugin
     *
     * @return string Plugin version (e.g., '1.0.0')
     */
    public function getVersion(): string;

    /**
     * Get the routes defined by the plugin
     *
     * Each route should be an associative array with:
     * - 'method' (string): HTTP method (e.g., 'GET', 'POST')
     * - 'path' (string): Request path (e.g., '/api/plugin/hello')
     * - 'handler' (callable): Handler callback:
     *   function(\Whity\Sdk\Http\Request $request): \Whity\Sdk\Http\Response
     * - 'requiredRole' (string|null, optional): Required role name or null
     *
     * @return array<array{method: string, path: string, handler: callable, requiredRole?: ?string}>
     */
    public function getRoutes(): array;

    /**
     * Get the permissions declared by the plugin
     *
     * Returns an array of permission strings in `resource:action` colon
     * notation (validated against /^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/).
     *
     * @return array<string>
     */
    public function getPermissions(): array;

    /**
     * Get the hook subscriptions defined by the plugin
     *
     * Returns an associative array mapping event names (see
     * {@see \Whity\Sdk\Hooks\Events}) to subscriptions. A subscription can be:
     * - A callable: function(array $data, array $context): array
     * - An array containing:
     *   - 'callback' (callable): The callback function
     *   - 'priority' (int, optional): Execution priority (default 10)
     * - An array of callables or arrays as described above
     *
     * @return array<string, callable|array{callback: callable, priority?: int}|array<callable|array{callback: callable, priority?: int}>>
     */
    public function getHooks(): array;

    /**
     * Get the migrations defined by the plugin
     *
     * Returns an array of migration class names (FQCNs). Each class implements
     * {@see \Whity\Sdk\MigrationInterface}.
     *
     * @return array<string>
     */
    public function getMigrations(): array;
}
