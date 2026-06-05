<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

use Whity\Core\Hooks\HookManager;

/**
 * PermissionRegistry is the centralized catalogue of every permission known to
 * the platform, collected from both the core system and loaded plugins.
 *
 * Permissions follow a `resource:action` naming pattern (e.g. `users:read`,
 * `roles:manage`, `plugins:manage`) and are tagged with their source: the
 * literal `core` for built-in permissions, or the plugin name for plugin
 * permissions.
 *
 * Core permissions are registered lazily on first read so that the RBAC layer
 * can validate them even when no explicit bootstrap wiring runs (issue #55).
 * Plugins remain the single source of truth for their own permissions: when a
 * plugin is unloaded its permissions instantly disappear, preventing stale
 * permissions or database drift.
 *
 * This registry holds worker-level state only. It is safe to share across
 * requests on FrankenPHP persistent workers because it never stores anything
 * request-specific.
 */
class PermissionRegistry
{
    /**
     * Permissions organized by source (plugin ID or {@see CorePermissions::SOURCE}).
     *
     * Structure: ['source' => ['permission1', 'permission2']]
     *
     * @var array<string, array<int, string>> Permissions keyed by source.
     */
    protected array $permissionsBySource = [];

    /**
     * Whether the core permission set has already been registered.
     */
    private bool $coreRegistered = false;

    /**
     * Optional HookManager for dispatching events.
     */
    private ?HookManager $hookManager = null;

    /**
     * Constructor.
     *
     * @param HookManager|null $hookManager Optional HookManager instance.
     */
    public function __construct(?HookManager $hookManager = null)
    {
        $this->hookManager = $hookManager;
    }

    /**
     * Register a set of permissions under a given source.
     *
     * The single registration entry point for both core and plugin permissions.
     * Every permission is validated against the `resource:action` pattern before
     * being stored; a malformed permission aborts the whole registration.
     *
     * @param string $source The permission source (`core` or a plugin name).
     * @param array<int, string> $permissions Array of `resource:action` strings.
     * @return void
     *
     * @throws InvalidPermissionException If any permission is malformed.
     */
    public function register(string $source, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (!self::isValidPermission($permission)) {
                throw InvalidPermissionException::forPermission($permission);
            }
        }

        $this->storeAndDispatch($source, array_values($permissions));
    }

    /**
     * Register the canonical core permission set (issue #55).
     *
     * Idempotent: repeated calls leave the `core` source unchanged. This method
     * is bootstrap-safe and may be called from anywhere (CLI bootstrap, HTTP
     * kernel, or implicitly via the first registry read).
     *
     * @return void
     */
    public function registerCorePermissions(): void
    {
        if ($this->coreRegistered) {
            return;
        }

        // Mark as registered first so the storeAndDispatch hook cannot recurse
        // back into lazy core registration.
        $this->coreRegistered = true;
        $this->storeAndDispatch(CorePermissions::SOURCE, CorePermissions::all());
    }

    /**
     * Check whether a permission exists in the registry (any source).
     *
     * @param string $permission The permission to check.
     * @return bool True if the permission is registered, false otherwise.
     */
    public function exists(string $permission): bool
    {
        $this->ensureCoreRegistered();

        foreach ($this->permissionsBySource as $permissions) {
            if (in_array($permission, $permissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get every registered permission mapped to its source.
     *
     * @return array<string, string> Map of `permission => source`.
     */
    public function getAll(): array
    {
        $this->ensureCoreRegistered();

        $all = [];
        foreach ($this->permissionsBySource as $source => $permissions) {
            foreach ($permissions as $permission) {
                $all[$permission] = $source;
            }
        }

        return $all;
    }

    /**
     * Get the permissions registered under a specific source.
     *
     * @param string $source The source (`core` or a plugin name).
     * @return array<int, string> The permissions for this source, or an empty array.
     */
    public function getBySource(string $source): array
    {
        $this->ensureCoreRegistered();

        return $this->permissionsBySource[$source] ?? [];
    }

    /**
     * Store permissions for a source and dispatch the registration hook.
     *
     * @param string $source The permission source.
     * @param array<int, string> $permissions The permissions to store.
     * @return void
     */
    private function storeAndDispatch(string $source, array $permissions): void
    {
        // Explicitly registering the core source means the caller is supplying
        // the core permission set, so lazy auto-population must not later clobber
        // or duplicate it (issue #55).
        if ($source === CorePermissions::SOURCE) {
            $this->coreRegistered = true;
        }

        $this->permissionsBySource[$source] = $permissions;

        if ($this->hookManager !== null) {
            $this->hookManager->dispatch('permission.registered', [
                'plugin_id' => $source,
                'source' => $source,
                'permissions' => $permissions,
            ]);
        }
    }

    /**
     * Lazily register the core permission set on first read (issue #55).
     *
     * @return void
     */
    private function ensureCoreRegistered(): void
    {
        if (!$this->coreRegistered) {
            $this->registerCorePermissions();
        }
    }

    /**
     * Validate that a permission string matches the `resource:action` pattern.
     *
     * @param string $permission The permission string to validate.
     * @return bool True if the permission is well-formed.
     */
    private static function isValidPermission(string $permission): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/', $permission) === 1;
    }
}
