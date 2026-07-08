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

    // Plugin lifecycle management (WC-218). The single coarse `plugins:manage`
    // permission was replaced by six per-action permissions so each plugin
    // operation can be delegated independently. Each constant maps to exactly
    // one lifecycle route (see public/index.php), except PLUGINS_ENABLE which
    // gates both enable-by-name and re-enable-by-id, and PLUGINS_UPLOAD whose
    // route is introduced in a later slice but whose permission is defined and
    // seeded now so it can be granted ahead of the feature landing.
    public const PLUGINS_READ = 'plugins:read';
    public const PLUGINS_ENABLE = 'plugins:enable';
    public const PLUGINS_DISABLE = 'plugins:disable';
    public const PLUGINS_UPLOAD = 'plugins:upload';
    public const PLUGINS_UNINSTALL = 'plugins:uninstall';
    public const PLUGINS_RELOAD = 'plugins:reload';

    // Permission delegation management (WC-34). Gates the delegation API; the
    // runtime subset-of-own-permissions invariant is enforced independently in
    // the delegation service so holding this permission never lets a grantor
    // delegate beyond what they themselves hold.
    public const DELEGATION_MANAGE = 'delegation:manage';

    // Family relations management (WC-65). RELATIONS_READ gates the read surface
    // (relationship-type vocabulary, persons, and a node's relations); the broader
    // RELATIONS_MANAGE gates every write (create/edit/delete a person, add/remove a
    // relation edge). Both are seeded and granted to the admin role by migration
    // 020_create_relations.
    public const RELATIONS_READ = 'relations:read';
    public const RELATIONS_MANAGE = 'relations:manage';

    // Website settings (Website Settings feature). SETTINGS_READ gates viewing
    // the effective/editable set; SETTINGS_WRITE gates editing the CURRENT
    // tenant's overrides; SETTINGS_MANAGE gates editing the GLOBAL platform
    // defaults. All three are seeded and granted to the admin role by the
    // settings-permissions seeding migration.
    public const SETTINGS_READ = 'settings:read';
    public const SETTINGS_WRITE = 'settings:write';
    public const SETTINGS_MANAGE = 'settings:manage';

    // MCP token management (WC-149b2fc9). Gates the mint and revoke operations
    // for MCP credentials so an admin can control which users are allowed to
    // authenticate AI clients to the MCP endpoint.
    public const MCP_TOKENS_MANAGE = 'mcp:tokens:manage';

    // Self-service registration approval (WC-235). Gates the system-tenant-only
    // review of pending registrations: list, approve (invited → active) and
    // reject (invited → suspended). Necessary but not sufficient — the handler
    // additionally requires the caller to be acting in the system tenant (id 0),
    // since a freshly-registered tenant's only member is the pending owner.
    public const REGISTRATIONS_APPROVE = 'registrations:approve';

    // Federated-auth provider management (WC-e6287d12). Gates the per-tenant CRUD
    // of identity-provider (SSO/OIDC) configurations — client id/secret, issuer,
    // scopes, domain binding. Tenant-scoped: an admin manages only their own
    // tenant's providers.
    public const AUTH_PROVIDERS_MANAGE = 'auth_providers:manage';

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
            self::PLUGINS_READ,
            self::PLUGINS_ENABLE,
            self::PLUGINS_DISABLE,
            self::PLUGINS_UPLOAD,
            self::PLUGINS_UNINSTALL,
            self::PLUGINS_RELOAD,
            self::DELEGATION_MANAGE,
            self::RELATIONS_READ,
            self::RELATIONS_MANAGE,
            self::SETTINGS_READ,
            self::SETTINGS_WRITE,
            self::SETTINGS_MANAGE,
            self::MCP_TOKENS_MANAGE,
            self::REGISTRATIONS_APPROVE,
            self::AUTH_PROVIDERS_MANAGE,
        ];
    }
}
