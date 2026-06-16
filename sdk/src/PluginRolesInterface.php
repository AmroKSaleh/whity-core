<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Optional role-seeding declaration for plugins.
 *
 * A plugin MAY implement this interface — in addition to
 * {@see PluginInterface} — to declare custom roles and default permission
 * mappings. The host automatically seeds those roles when the plugin first
 * activates and removes the permission grants when the plugin is uninstalled.
 *
 * Like {@see PluginRequirementsInterface} and {@see PluginFrontendInterface},
 * this is a capability side-interface: implementing it is optional and does not
 * affect plugin loading for plugins that omit it.
 *
 * Role names
 * ----------
 * Role names must be non-empty strings. They are scoped to the tenant the
 * plugin is activated for (or the system tenant when the activation is global).
 * The (name, tenant_id) pair is unique in the `roles` table; seeding is
 * idempotent — a role that already exists is left untouched.
 *
 * Parent roles
 * ------------
 * The optional `parent` key in a role descriptor names an EXISTING system role
 * or another role declared in this same array. The host resolves the parent by
 * name within the same tenant scope. If the named parent does not exist at seed
 * time, the role is created without a parent (no error is raised — idempotent
 * and forward-safe).
 *
 * Permission grants
 * -----------------
 * Keys in {@see getRolePermissions()} must match keys from {@see getRoles()}.
 * Permission slugs must already exist in the `permissions` catalogue; slugs
 * that are absent at seed time are silently skipped. Grants are idempotent.
 *
 * Uninstall
 * ---------
 * On uninstall the host removes the role_permissions entries seeded for each
 * declared role. The role rows themselves are intentionally retained — an
 * operator or another migration may have assigned users to them, so deleting
 * the role could orphan user accounts. Operators can remove the roles manually
 * if desired.
 *
 * TODO: a future option could remove roles with zero user/OU assignments.
 */
interface PluginRolesInterface
{
    /**
     * Custom roles to seed when this plugin activates.
     *
     * Keys are role names (e.g. 'instructor'); values are arrays with optional
     * keys: 'description' (string) and 'parent' (string — parent role name,
     * must be an existing system role or another role declared in this array).
     *
     * @return array<string, array{description?: string, parent?: string}>
     */
    public function getRoles(): array;

    /**
     * Default permission slugs to grant to each declared role on activation.
     *
     * Keys are role names matching {@see getRoles()}; values are lists of
     * permission slugs that must already exist in the permissions table.
     * Slugs not found in the catalogue are skipped silently.
     *
     * @return array<string, list<string>>
     */
    public function getRolePermissions(): array;
}
