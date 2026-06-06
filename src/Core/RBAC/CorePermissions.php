<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

/**
 * CorePermissions defines the canonical set of permissions enforced by the core
 * system, expressed using the `resource:action` naming pattern.
 *
 * These constants are the single source of truth for built-in permissions and
 * are registered into the {@see PermissionRegistry} under the `core` source so
 * that the RBAC layer can validate them (see issue #55, where core permissions
 * were previously never registered and therefore always failed validation).
 *
 * NOTE: historical database seeds (migrations 002 and 007) use a dot-notation
 * variant (e.g. `users.read`). The `resource:action` pattern defined here is the
 * pattern mandated by the RBAC permission model going forward. Aligning the
 * seeds is tracked separately and is intentionally out of scope for this class.
 */
final class CorePermissions
{
    /**
     * Source tag applied to all core permissions in the registry.
     */
    public const SOURCE = 'core';

    // User management
    public const USERS_READ = 'users:read';
    public const USERS_WRITE = 'users:write';
    public const USERS_DELETE = 'users:delete';

    // Role management
    public const ROLES_READ = 'roles:read';
    public const ROLES_WRITE = 'roles:write';
    public const ROLES_DELETE = 'roles:delete';
    public const ROLES_MANAGE = 'roles:manage';

    // Tenant management
    public const TENANTS_READ = 'tenants:read';
    public const TENANTS_WRITE = 'tenants:write';
    public const TENANTS_DELETE = 'tenants:delete';

    // Organizational unit management
    public const OUS_READ = 'ous:read';
    public const OUS_WRITE = 'ous:write';
    public const OUS_DELETE = 'ous:delete';
    public const OUS_ASSIGN = 'ous:assign';

    // Permission catalogue
    public const PERMISSIONS_READ = 'permissions:read';

    // Audit trail (WC-34): read-only access to the security audit log.
    public const AUDIT_READ = 'audit:read';

    // Plugin lifecycle management
    public const PLUGINS_MANAGE = 'plugins:manage';

    // Permission delegation management (WC-34). Gates the delegation API; the
    // runtime subset-of-own-permissions invariant is enforced independently in
    // the delegation service so holding this permission never lets a grantor
    // delegate beyond what they themselves hold.
    public const DELEGATION_MANAGE = 'delegation:manage';

    // Family relations management (WC-65). RELATIONS_READ gates the read surface
    // (relationship-type vocabulary, persons, and a node's relations); the broader
    // RELATIONS_MANAGE gates every write (create/edit/delete a person, add/remove a
    // relation edge). Both are seeded and granted to the admin role by migration
    // 019_create_relations.
    public const RELATIONS_READ = 'relations:read';
    public const RELATIONS_MANAGE = 'relations:manage';

    /**
     * Return the full list of core permission strings.
     *
     * @return array<int, string> Ordered list of `resource:action` permissions.
     */
    public static function all(): array
    {
        return [
            self::USERS_READ,
            self::USERS_WRITE,
            self::USERS_DELETE,
            self::ROLES_READ,
            self::ROLES_WRITE,
            self::ROLES_DELETE,
            self::ROLES_MANAGE,
            self::TENANTS_READ,
            self::TENANTS_WRITE,
            self::TENANTS_DELETE,
            self::OUS_READ,
            self::OUS_WRITE,
            self::OUS_DELETE,
            self::OUS_ASSIGN,
            self::PERMISSIONS_READ,
            self::AUDIT_READ,
            self::PLUGINS_MANAGE,
            self::DELEGATION_MANAGE,
            self::RELATIONS_READ,
            self::RELATIONS_MANAGE,
        ];
    }
}
