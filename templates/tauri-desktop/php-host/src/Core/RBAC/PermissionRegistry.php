<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

/**
 * Sized-down port of production's Whity\Core\RBAC\PermissionRegistry: the
 * catalogue of every permission slug declared by a loaded plugin. Gates
 * DeviceRoleChecker exactly like production's RoleChecker — an unregistered
 * slug is never granted, even if a stale role_permissions row exists.
 *
 * No lazy "core permissions" registration here (this offline host has no
 * core permission set of its own — only plugins declare permissions) and no
 * HookManager dispatch on registration (nothing offline listens for
 * `permission.registered`).
 */
final class PermissionRegistry
{
    /** @var array<string, list<string>> permission source (plugin name) => permission slugs */
    private array $permissionsBySource = [];

    /**
     * @param list<string> $permissions
     * @throws \InvalidArgumentException On a malformed `resource:action` slug.
     */
    public function register(string $source, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (!self::isValid($permission)) {
                throw new \InvalidArgumentException("Plugin '{$source}' declared a malformed permission '{$permission}'");
            }
        }

        $this->permissionsBySource[$source] = array_values($permissions);
    }

    public function exists(string $permission): bool
    {
        foreach ($this->permissionsBySource as $permissions) {
            if (in_array($permission, $permissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> permission slug => source
     */
    public function getAll(): array
    {
        $all = [];
        foreach ($this->permissionsBySource as $source => $permissions) {
            foreach ($permissions as $permission) {
                $all[$permission] = $source;
            }
        }

        return $all;
    }

    private static function isValid(string $permission): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/', $permission) === 1;
    }
}
