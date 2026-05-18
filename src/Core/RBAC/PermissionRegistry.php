<?php

namespace Whity\Core\RBAC;

use Whity\Core\Hooks\HookManager;

/**
 * PermissionRegistry holds all permissions registered by plugins
 *
 * Maintains an in-memory registry of permissions by plugin ID. Plugins are the
 * single source of truth - when a plugin is deleted, its permissions instantly
 * disappear from the system. This ensures no database drift or stale permissions.
 */
class PermissionRegistry
{
    /**
     * Permissions organized by plugin ID
     *
     * Structure: ['plugin_id' => ['permission1', 'permission2']]
     *
     * @var array<string, array<string>> Permissions keyed by plugin ID
     */
    protected array $pluginPermissions = [];

    /**
     * Optional HookManager for dispatching events
     */
    private ?HookManager $hookManager = null;

    /**
     * Constructor
     *
     * @param HookManager|null $hookManager Optional HookManager instance
     */
    public function __construct(?HookManager $hookManager = null)
    {
        $this->hookManager = $hookManager;
    }

    /**
     * Register permissions for a plugin
     *
     * Stores the permissions in the in-memory registry. If HookManager is set,
     * dispatches a 'permission.registered' hook for monitoring/logging.
     *
     * @param string $pluginId The plugin ID
     * @param array<string> $permissions Array of permission strings
     * @return void
     */
    public function registerPermissions(string $pluginId, array $permissions): void
    {
        $this->pluginPermissions[$pluginId] = $permissions;

        // Dispatch hook if HookManager is available
        if ($this->hookManager !== null) {
            $this->hookManager->dispatch('permission.registered', [
                'plugin_id' => $pluginId,
                'permissions' => $permissions,
            ]);
        }
    }

    /**
     * Check if a permission exists in the registry
     *
     * Loops through all plugins' permissions and checks if the given permission
     * is registered using strict type comparison.
     *
     * @param string $permission The permission to check
     * @return bool True if permission exists, false otherwise
     */
    public function permissionExists(string $permission): bool
    {
        foreach ($this->pluginPermissions as $permissions) {
            if (in_array($permission, $permissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get permissions for a specific plugin
     *
     * @param string $pluginId The plugin ID
     * @return array<string> The permissions array for this plugin, or empty array if not registered
     */
    public function getPluginPermissions(string $pluginId): array
    {
        return $this->pluginPermissions[$pluginId] ?? [];
    }

    /**
     * Get all active permissions from all registered plugins
     *
     * @return array<string, array<string>> Permissions keyed by plugin ID
     */
    public function getAllActivePermissions(): array
    {
        return $this->pluginPermissions;
    }

    /**
     * Get list of all registered plugin IDs
     *
     * @return array<string> Array of plugin IDs
     */
    public function getRegisteredPlugins(): array
    {
        return array_keys($this->pluginPermissions);
    }
}
