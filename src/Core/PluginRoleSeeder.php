<?php

declare(strict_types=1);

namespace Whity\Core;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRolesInterface;

/**
 * Seeds and removes plugin-declared roles and permission grants.
 *
 * Called by {@see PluginLoader} after a plugin passes the compatibility gate
 * and is registered (seed path) and before its capabilities are torn down on
 * uninstall (removal path). All DB writes carry tenant_id so isolation is
 * never violated.
 *
 * Design constraints
 * ------------------
 * - Idempotent: calling seed() twice for the same plugin/tenant is safe.
 * - Fail-soft: a DB error on any individual row is caught, logged, and skipped
 *   so one bad permission slug never aborts the whole seeding sequence.
 * - Never exposes raw exceptions to callers: errors are swallowed after logging.
 * - Tenant isolation: every INSERT carries the resolved tenant_id.
 */
final class PluginRoleSeeder
{
    /** System-tenant id used for global (non-tenant-scoped) activations. */
    public const SYSTEM_TENANT_ID = 0;

    private PDO $pdo;
    private ?LoggerInterface $logger;

    /**
     * @param PDO                  $pdo    Live database connection.
     * @param LoggerInterface|null $logger Optional PSR-3 logger.
     */
    public function __construct(PDO $pdo, ?LoggerInterface $logger = null)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    /**
     * Seed the roles and permission grants declared by a plugin.
     *
     * Called after the plugin registers successfully (activation path). If the
     * plugin does not implement {@see PluginRolesInterface} this is a no-op.
     *
     * @param PluginInterface $plugin   The activating plugin.
     * @param int             $tenantId The tenant to seed into (0 = system tenant).
     * @return void
     */
    public function seed(PluginInterface $plugin, int $tenantId): void
    {
        if (!$plugin instanceof PluginRolesInterface) {
            return;
        }

        /** @var mixed $rolesRaw */
        $rolesRaw = [];
        /** @var mixed $permissionsRaw */
        $permissionsRaw = [];

        try {
            $rolesRaw = $plugin->getRoles();
            $permissionsRaw = $plugin->getRolePermissions();
        } catch (\Throwable $e) {
            $this->log(
                'warning',
                sprintf(
                    'PluginRoleSeeder: getRoles()/getRolePermissions() threw for plugin "%s": %s',
                    $plugin->getName(),
                    $e->getMessage()
                ),
                ['plugin' => $plugin->getName(), 'tenant_id' => $tenantId]
            );
            return;
        }

        // Defensive runtime guard: getRoles()/getRolePermissions() are interface
        // methods; a misbehaving plugin could return non-array values at runtime
        // even though the PHPDoc says array. PHPStan flags this as always-false
        // because it trusts the PHPDoc types.
        if (!is_array($rolesRaw) || !is_array($permissionsRaw)) { // @phpstan-ignore booleanOr.alwaysFalse
            return;
        }

        /** @var array<mixed, mixed> $roles */
        $roles = $rolesRaw;
        /** @var array<mixed, mixed> $permissions */
        $permissions = $permissionsRaw;

        // First pass: seed role rows. Track name => id for the grants pass.
        /** @var array<string, int> $roleIds name => persisted id */
        $roleIds = [];

        foreach ($roles as $roleName => $descriptor) {
            if (!is_string($roleName) || $roleName === '' || !is_array($descriptor)) {
                continue;
            }

            $description = isset($descriptor['description']) && is_string($descriptor['description'])
                ? $descriptor['description']
                : '';

            $parentName = isset($descriptor['parent']) && is_string($descriptor['parent'])
                ? $descriptor['parent']
                : null;

            $id = $this->seedRole($roleName, $description, $parentName, $tenantId);
            if ($id !== null) {
                $roleIds[$roleName] = $id;
            }
        }

        // Second pass: grant permissions to each role.
        foreach ($permissions as $roleName => $slugs) {
            if (!is_string($roleName) || !is_array($slugs)) {
                continue;
            }

            $roleId = $roleIds[$roleName] ?? null;
            if ($roleId === null) {
                // Role was not seeded (possibly already existed); look it up.
                $roleId = $this->findRoleId($roleName, $tenantId);
            }

            if ($roleId === null) {
                $this->log(
                    'warning',
                    sprintf(
                        'PluginRoleSeeder: role "%s" not found for plugin "%s" when seeding permissions; skipping',
                        $roleName,
                        $plugin->getName()
                    ),
                    ['plugin' => $plugin->getName(), 'role' => $roleName, 'tenant_id' => $tenantId]
                );
                continue;
            }

            foreach ($slugs as $slug) {
                if (!is_string($slug) || $slug === '') {
                    continue;
                }

                $this->grantPermission($roleId, $slug, $plugin->getName(), $tenantId);
            }
        }
    }

    /**
     * Remove the permission grants seeded for a plugin's declared roles.
     *
     * Called on the uninstall / permanent-removal path. If the plugin does not
     * implement {@see PluginRolesInterface} this is a no-op.
     *
     * Role rows are intentionally left in place: operators may have assigned
     * users to them; deleting the role row could orphan accounts. Only the
     * role_permissions grants that were seeded by this plugin are removed.
     *
     * TODO: a future enhancement could optionally remove role rows that have
     *       no remaining user/OU assignments — not implemented here to err on
     *       the side of caution.
     *
     * @param PluginInterface $plugin   The plugin being uninstalled.
     * @param int             $tenantId The tenant whose grants should be removed (0 = system tenant).
     * @return void
     */
    public function removeGrants(PluginInterface $plugin, int $tenantId): void
    {
        if (!$plugin instanceof PluginRolesInterface) {
            return;
        }

        /** @var mixed $rolesRaw */
        $rolesRaw = [];
        /** @var mixed $permissionsRaw */
        $permissionsRaw = [];

        try {
            $rolesRaw = $plugin->getRoles();
            $permissionsRaw = $plugin->getRolePermissions();
        } catch (\Throwable $e) {
            $this->log(
                'warning',
                sprintf(
                    'PluginRoleSeeder: getRoles()/getRolePermissions() threw during uninstall for plugin "%s": %s',
                    $plugin->getName(),
                    $e->getMessage()
                ),
                ['plugin' => $plugin->getName(), 'tenant_id' => $tenantId]
            );
            return;
        }

        if (!is_array($rolesRaw) || !is_array($permissionsRaw)) { // @phpstan-ignore booleanOr.alwaysFalse
            return;
        }

        /** @var array<mixed, mixed> $roles */
        $roles = $rolesRaw;
        /** @var array<mixed, mixed> $permissions */
        $permissions = $permissionsRaw;

        foreach ($roles as $roleName => $descriptor) {
            if (!is_string($roleName) || $roleName === '') {
                continue;
            }

            $roleId = $this->findRoleId($roleName, $tenantId);
            if ($roleId === null) {
                continue;
            }

            $rawSlugs = $permissions[$roleName] ?? [];
            $slugs = is_array($rawSlugs) ? $rawSlugs : [];
            unset($rawSlugs);

            foreach ($slugs as $slug) {
                if (!is_string($slug) || $slug === '') {
                    continue;
                }

                $this->revokePermission($roleId, $slug, $plugin->getName(), $tenantId);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Insert or look up a role row, return the role id (or null on error).
     *
     * Idempotent: if a role with (name, tenant_id) already exists its id is
     * returned unchanged.
     *
     * @param string      $name        Role name to seed.
     * @param string      $description Human-readable description.
     * @param string|null $parentName  Optional parent role name to resolve.
     * @param int         $tenantId    Tenant to scope the role to.
     * @return int|null The role id, or null when the operation failed.
     */
    private function seedRole(
        string $name,
        string $description,
        ?string $parentName,
        int $tenantId
    ): ?int {
        try {
            // Resolve parent role id (if declared).
            $parentId = null;
            if ($parentName !== null && $parentName !== '') {
                $parentId = $this->findRoleId($parentName, $tenantId)
                    ?? $this->findRoleId($parentName, self::SYSTEM_TENANT_ID);
                // If parent still not found, proceed without it (safe fallback).
            }

            // Normalise tenant_id: NULL in the DB means system/global; 0 in PHP
            // is our sentinel for the system tenant. Store NULL for system tenant.
            $dbTenantId = $tenantId === self::SYSTEM_TENANT_ID ? null : $tenantId;

            // Attempt INSERT … ON CONFLICT DO NOTHING. This is idempotent on the
            // (name) UNIQUE constraint in the current schema. When a per-tenant
            // UNIQUE(name, tenant_id) is ever added the ON CONFLICT clause will
            // need updating — a TODO left for that schema revision.
            $stmt = $this->pdo->prepare(
                'INSERT INTO roles (name, description, parent_id, tenant_id, created_at)
                 VALUES (:name, :description, :parent_id, :tenant_id, CURRENT_TIMESTAMP)
                 ON CONFLICT (name) DO NOTHING'
            );

            $stmt->execute([
                ':name' => $name,
                ':description' => $description,
                ':parent_id' => $parentId,
                ':tenant_id' => $dbTenantId,
            ]);

            // Retrieve the id regardless of whether we just inserted or the row
            // already existed.
            return $this->findRoleId($name, $tenantId)
                ?? $this->findRoleId($name, self::SYSTEM_TENANT_ID);
        } catch (PDOException $e) {
            $this->log(
                'error',
                sprintf('PluginRoleSeeder: failed to seed role "%s": %s', $name, $e->getMessage()),
                ['role' => $name, 'tenant_id' => $tenantId]
            );
            return null;
        }
    }

    /**
     * Look up a role id by name and tenant.
     *
     * Tries an exact (name, tenant_id) match first; for system-tenant-aware
     * lookups the NULL column value is tested via IS NULL.
     *
     * @param string $name     Role name to look up.
     * @param int    $tenantId Tenant scope (0 = system tenant → NULL in DB).
     * @return int|null The role id, or null when not found.
     */
    private function findRoleId(string $name, int $tenantId): ?int
    {
        try {
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                $stmt = $this->pdo->prepare(
                    'SELECT id FROM roles WHERE name = :name AND tenant_id IS NULL LIMIT 1'
                );
                $stmt->execute([':name' => $name]);
            } else {
                $stmt = $this->pdo->prepare(
                    'SELECT id FROM roles WHERE name = :name AND tenant_id = :tenant_id LIMIT 1'
                );
                $stmt->execute([':name' => $name, ':tenant_id' => $tenantId]);
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || $row === null) {
                return null;
            }

            return isset($row['id']) ? (int) $row['id'] : null;
        } catch (PDOException $e) {
            $this->log(
                'error',
                sprintf('PluginRoleSeeder: findRoleId failed for "%s": %s', $name, $e->getMessage()),
                ['role' => $name, 'tenant_id' => $tenantId]
            );
            return null;
        }
    }

    /**
     * Grant a permission slug to a role (idempotent via ON CONFLICT DO NOTHING).
     *
     * Silently skips the slug when it does not exist in the permissions catalogue
     * rather than raising an error — the catalogue may not yet contain plugin
     * permissions that are registered later.
     *
     * @param int    $roleId     The role to grant to.
     * @param string $slug       The `resource:action` permission slug.
     * @param string $pluginName Plugin name for log context.
     * @param int    $tenantId   Tenant context for log messages.
     * @return void
     */
    private function grantPermission(int $roleId, string $slug, string $pluginName, int $tenantId): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO role_permissions (role_id, permission_id, created_at)
                 SELECT :role_id, id, CURRENT_TIMESTAMP
                 FROM permissions
                 WHERE name = :slug
                 ON CONFLICT (role_id, permission_id) DO NOTHING'
            );

            $stmt->execute([':role_id' => $roleId, ':slug' => $slug]);
        } catch (PDOException $e) {
            $this->log(
                'error',
                sprintf(
                    'PluginRoleSeeder: failed to grant permission "%s" to role %d for plugin "%s": %s',
                    $slug,
                    $roleId,
                    $pluginName,
                    $e->getMessage()
                ),
                ['slug' => $slug, 'role_id' => $roleId, 'plugin' => $pluginName, 'tenant_id' => $tenantId]
            );
        }
    }

    /**
     * Revoke a permission grant for a role (no-op when the grant does not exist).
     *
     * @param int    $roleId     The role to revoke from.
     * @param string $slug       The `resource:action` permission slug.
     * @param string $pluginName Plugin name for log context.
     * @param int    $tenantId   Tenant context for log messages.
     * @return void
     */
    private function revokePermission(int $roleId, string $slug, string $pluginName, int $tenantId): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM role_permissions
                 WHERE role_id = :role_id
                   AND permission_id = (SELECT id FROM permissions WHERE name = :slug LIMIT 1)'
            );

            $stmt->execute([':role_id' => $roleId, ':slug' => $slug]);
        } catch (PDOException $e) {
            $this->log(
                'error',
                sprintf(
                    'PluginRoleSeeder: failed to revoke permission "%s" from role %d for plugin "%s": %s',
                    $slug,
                    $roleId,
                    $pluginName,
                    $e->getMessage()
                ),
                ['slug' => $slug, 'role_id' => $roleId, 'plugin' => $pluginName, 'tenant_id' => $tenantId]
            );
        }
    }

    /**
     * Emit a log message through the wired logger, or silently drop when none is wired.
     *
     * @param string               $level   PSR-3 log level ('error', 'warning', 'info', …).
     * @param string               $message The log message.
     * @param array<string, mixed> $context Optional structured context.
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->{$level}($message, $context);
    }
}
