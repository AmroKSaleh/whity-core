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

    // Operator per-tenant entitlement management (WC-ent). Gates the operator API
    // that grants/limits a TARGET tenant's capabilities per subscription tier.
    // Necessary but not sufficient — the handler additionally requires the caller
    // to be acting in the system tenant (id 0), so a regular tenant admin (who
    // also holds this on the global admin role) can never edit another tenant's
    // entitlements. Mirrors REGISTRATIONS_APPROVE.
    public const ENTITLEMENTS_MANAGE = 'entitlements:manage';

    // Per-tenant storage backend management (WC-storage). Gates a tenant's
    // self-service CRUD of its own object-storage backend config. Tenant-scoped
    // (a tenant manages only its own). Necessary but not sufficient — the handler
    // additionally requires the storage.custom_backend ENTITLEMENT, so a tenant
    // may only configure a custom backend when its plan includes it.
    public const STORAGE_MANAGE = 'storage:manage';

    // Operator subscription-plan management (WC-plans, ADR 0010). Gates the
    // catalog CRUD of plans + their entitlement bundles, and applying a plan to a
    // target tenant. A PLATFORM capability: necessary but not sufficient — the
    // handler additionally requires the caller to be acting in the system tenant
    // (id 0). Mirrors ENTITLEMENTS_MANAGE.
    public const PLANS_MANAGE = 'plans:manage';

    // Operator subscription (billing-state) management (WC-billing). Gates setting
    // a TARGET tenant's subscription status / plan / enforcement mode — the point
    // where an out-of-band payment is reflected in-app, and where the system admin
    // sets their own tenant's tier in a sovereign deployment. PLATFORM capability:
    // the handler additionally requires acting in the system tenant (id 0). A
    // tenant admin's read-only view of its OWN subscription is gated separately on
    // settings:read (and is exempt from the payment wall so it stays reachable).
    public const SUBSCRIPTIONS_MANAGE = 'subscriptions:manage';

    // Document/label designer (WC-docdesigner). Tenant-scoped. read = view/list
    // templates & blocks (list/get are ADDITIONALLY row-filtered server-side by
    // scope + a row's required_permission, so a technician never receives a gated
    // contracts template); write = create/update/delete own; publish = set a
    // template/block tenant-wide/global or attach a required_permission tag;
    // render = produce server-side PDF/PNG (Track 2).
    public const DOCUMENTS_READ = 'documents:read';
    public const DOCUMENTS_WRITE = 'documents:write';
    public const DOCUMENTS_PUBLISH = 'documents:publish';
    public const DOCUMENTS_RENDER = 'documents:render';

    // Admin-enforced 2FA policy (WC-525): tenant/OU/user-scoped rows an admin
    // sets to require 2FA enrollment. Tenant-scoped.
    public const SECURITY_MANAGE = 'security:manage';

    // Native taxonomy/tagging (WC-621). A domain-neutral tagging primitive:
    // tag groups + tags + polymorphic entity<->tag associations. Tenant-scoped.
    // read = list/read groups & tags, read an entity's tags, and filter entities
    // by tag; manage = create/update/delete groups & tags and attach/detach tags
    // to entities.
    public const TAGS_READ = 'tags:read';
    public const TAGS_MANAGE = 'tags:manage';

    // Generic async-job API (WC-jobs-api). Tenant-scoped submission + status.
    // submit = POST /api/jobs (enqueue an allow-listed job name for this tenant)
    // and read its own jobs; read = GET /api/jobs/{id} status/progress/result.
    public const JOBS_SUBMIT = 'jobs:submit';
    public const JOBS_READ = 'jobs:read';

    // Notification administration (WC-notifications, #4b87abf0). The self-service
    // inbox + preferences are user-scoped and need NO permission; these gate the
    // ADMIN surfaces. notification_settings:manage governs a tenant's sender
    // configuration (from/reply-to, transport, encrypted creds — see
    // TenantNotificationSettingsApiHandler). notifications:manage governs
    // managing notifications themselves (e.g. templates administration / broadcast
    // surfaces as they land).
    public const NOTIFICATIONS_MANAGE = 'notifications:manage';
    public const NOTIFICATION_SETTINGS_MANAGE = 'notification_settings:manage';

    // Password-reset approval queue (WC-password-reset-2fa-recovery). Gates the
    // admin review of pending SELF-SERVICE password-reset requests staged for
    // approval (auth.password_reset_approval_required) — list/approve/reject.
    // Deliberately narrow and distinct from USERS_WRITE (too broad — grants
    // full user mutation, not just reviewing a staged credential change) and
    // from SECURITY_MANAGE (a different, unrelated policy surface). Unlike
    // REGISTRATIONS_APPROVE, this is NOT system-tenant-restricted: the
    // requesting user's account and its tenant already exist, so their OWN
    // tenant's admin (any tenant) reviews it — the handler scopes the queue to
    // tenants where the requester holds an active membership.
    public const PASSWORD_RESETS_APPROVE = 'password_resets:approve';

    // 2FA-recovery approval queue (WC-password-reset-2fa-recovery). Gates the
    // admin review of "I lost my 2FA device" recovery requests: list/approve/
    // reject, and the optional admin-direct-force fallback (no prior request).
    // Approving CLEARS the target profile's 2FA (mirrors TwoFactorHandler::
    // disable(), applied to the TARGET, not the caller) and issues a fresh
    // password-reset link — a genuinely account-takeover-adjacent capability,
    // so it is kept intentionally distinct and narrow rather than folded into
    // PASSWORD_RESETS_APPROVE or SECURITY_MANAGE. Tenant-scoped like
    // PASSWORD_RESETS_APPROVE above, not system-tenant-restricted.
    public const TWO_FACTOR_RECOVERY_APPROVE = 'two_factor_recovery:approve';

    // i18n admin management (WC-583): languages are a GLOBAL catalogue (no
    // tenant_id column at all), so create/update/enable/disable is a PLATFORM
    // capability — necessary but not sufficient, the handler additionally
    // requires the caller to be acting in the system tenant (id 0), mirroring
    // ENTITLEMENTS_MANAGE/PLANS_MANAGE (a regular tenant admin must never be
    // able to disable a language for the whole install). translations:manage
    // gates create/update/delete of a translation ROW; the row's scope
    // (system default, tenant_id NULL, vs a tenant override, tenant_id>0)
    // follows the caller — the system tenant writes/deletes only system
    // defaults, a regular tenant writes/deletes only its OWN override, the
    // same asymmetry as base roles (WC-110) and global settings (WC-224).
    public const LANGUAGES_MANAGE = 'languages:manage';
    public const TRANSLATIONS_MANAGE = 'translations:manage';

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
            self::ENTITLEMENTS_MANAGE,
            self::STORAGE_MANAGE,
            self::PLANS_MANAGE,
            self::SUBSCRIPTIONS_MANAGE,
            self::DOCUMENTS_READ,
            self::DOCUMENTS_WRITE,
            self::DOCUMENTS_PUBLISH,
            self::DOCUMENTS_RENDER,
            self::SECURITY_MANAGE,
            self::TAGS_READ,
            self::TAGS_MANAGE,
            self::JOBS_SUBMIT,
            self::JOBS_READ,
            self::NOTIFICATIONS_MANAGE,
            self::NOTIFICATION_SETTINGS_MANAGE,
            self::PASSWORD_RESETS_APPROVE,
            self::TWO_FACTOR_RECOVERY_APPROVE,
            self::LANGUAGES_MANAGE,
            self::TRANSLATIONS_MANAGE,
        ];
    }
}
