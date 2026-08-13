<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

use Whity\PluginHost\LoadedPlugin;
use Whity\Sdk\PluginRolesInterface;

/**
 * Seeds roles/role_permissions from loaded plugins' optional
 * PluginRolesInterface declarations, plus the offline host's own default:
 * every declared permission granted to the `admin` role unconditionally,
 * regardless of whether a plugin ships its own Grant*ToAdmin migration —
 * that's what makes "the default device role can do everything" true for an
 * arbitrary plugin, not just the ones in this repo that happen to follow the
 * `admin`-role convention.
 *
 * A role's optional `parent` key (PluginRolesInterface::getRoles()) is
 * accepted but deliberately ignored — no role hierarchy in this single-
 * tenant, single-user offline host. Per the interface's own documented
 * contract ("If the named parent does not exist at seed time, the role is
 * created without a parent — no error is raised"), omitting it entirely is
 * within contract, not a violation of it.
 */
final class RoleSeeder
{
    /**
     * @param list<LoadedPlugin> $loadedPlugins
     */
    public static function seedPluginRoles(\PDO $pdo, PermissionRegistry $registry, array $loadedPlugins): void
    {
        foreach ($loadedPlugins as $loadedPlugin) {
            if (!$loadedPlugin->plugin instanceof PluginRolesInterface) {
                continue;
            }

            foreach (array_keys($loadedPlugin->plugin->getRoles()) as $roleName) {
                if (!is_string($roleName) || $roleName === '') {
                    continue;
                }

                self::ensureRole($pdo, $roleName);
            }

            foreach ($loadedPlugin->plugin->getRolePermissions() as $roleName => $permissions) {
                if (!is_string($roleName) || !is_array($permissions)) {
                    continue;
                }

                foreach ($permissions as $permission) {
                    // Slugs not (yet) in the catalogue are skipped silently,
                    // per PluginRolesInterface's own documented contract.
                    if (is_string($permission) && $registry->exists($permission)) {
                        self::grant($pdo, $roleName, $permission);
                    }
                }
            }
        }
    }

    /**
     * Unconditionally grant every declared permission to `admin`, every boot.
     */
    public static function grantAllToAdminRole(\PDO $pdo, PermissionRegistry $registry): void
    {
        self::ensureRole($pdo, 'admin');

        foreach (array_keys($registry->getAll()) as $permission) {
            self::grant($pdo, 'admin', $permission);
        }
    }

    private static function ensureRole(\PDO $pdo, string $roleName): void
    {
        $stmt = $pdo->prepare('INSERT INTO roles (name) VALUES (:name) ON CONFLICT(name) DO NOTHING');
        $stmt->execute([':name' => $roleName]);
    }

    private static function grant(\PDO $pdo, string $roleName, string $permission): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r, permissions p
             WHERE r.name = :role AND p.name = :permission
             ON CONFLICT (role_id, permission_id) DO NOTHING'
        );
        $stmt->execute([':role' => $roleName, ':permission' => $permission]);
    }
}
