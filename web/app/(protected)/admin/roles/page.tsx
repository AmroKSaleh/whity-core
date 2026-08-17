'use client';

/**
 * Roles admin page — a thin client wrapper around the extracted, data-source-
 * agnostic `RolesScreen` (@amroksaleh/features/roles, Path B pilot). This file
 * owns only web's provider seams: the cookie-authenticated `webRolesAdapter`,
 * the capability check, the translator, and the toast notifier. The desktop
 * client mounts the same `RolesScreen` with its own adapter/can/t/onNotify.
 */

import { useCallback } from 'react';
import { RolesScreen } from '@amroksaleh/features/roles';
import { webRolesAdapter } from '@/lib/roles-adapter';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useToast } from '@/lib/toast-context';

/**
 * Sentinel used to detect a "this domain has no translation for the key" miss.
 * `getTranslation` resolves as `value || fallback || key`, so passing this as
 * the fallback returns it verbatim when the `admin` domain lacks the key. It
 * carries no `{placeholder}` tokens, so interpolation leaves it byte-for-byte
 * intact and the equality check below stays reliable.
 */
const I18N_MISS = '__ROLES_I18N_MISS__';

/**
 * i18n catalogue source for the Roles screen. Its t() call sites live in
 * @amroksaleh/features/roles behind a prop-injected translator, so the
 * extractor cannot read them from the package; this page also forwards keys to
 * the admin/common domains through the dynamic composite t() below. This block
 * is therefore where the admin-domain English catalogue for Roles is declared,
 * and it marks those dynamic t() calls as intentional. It is a projection of
 * the t() fallbacks in the package — regenerate both together.
 *
 * @i18n-keys admin
 *   roles.action.clone = Clone
 *   roles.action.delete = Delete
 *   roles.action.delete.disabled = Global base roles can only be deleted by the system tenant.
 *   roles.action.edit = Edit
 *   roles.action.edit.disabled = Global base roles can only be edited by the system tenant.
 *   roles.action.viewPermissions = View Permissions
 *   roles.clone.error = Failed to clone role
 *   roles.clone.name = {name} (copy)
 *   roles.create.cancel = Cancel
 *   roles.create.description.label = Description
 *   roles.create.description.placeholder = Role description
 *   roles.create.error = Failed to create role
 *   roles.create.name.label = Role Name
 *   roles.create.name.placeholder = e.g., Editor
 *   roles.create.permissions.label = Permissions
 *   roles.create.permissions.loading = Loading permissions...
 *   roles.create.permissionsError = Failed to fetch permissions
 *   roles.create.submit = Create Role
 *   roles.create.submitting = Creating...
 *   roles.create.subtitle = Add a new role to your system with permissions.
 *   roles.create.success = Role created successfully
 *   roles.create.title = Create New Role
 *   roles.create.validation.descriptionRequired = Description is required
 *   roles.create.validation.nameRequired = Name is required
 *   roles.delete.cancel = Cancel
 *   roles.delete.description = Are you sure you want to delete this role? This action cannot be undone.
 *   roles.delete.error = Failed to delete role
 *   roles.delete.notManageable = This role can't be modified by your tenant — global base roles are managed by the system tenant.
 *   roles.delete.permissionCount = Permissions: {count}
 *   roles.delete.submit = Delete Role
 *   roles.delete.submitting = Deleting...
 *   roles.delete.success = Role deleted successfully
 *   roles.delete.title = Delete Role
 *   roles.delete.warning = If this role is assigned to users, they will lose the permissions associated with this role.
 *   roles.description = Manage roles and their permissions
 *   roles.edit.cancel = Cancel
 *   roles.edit.description.label = Description
 *   roles.edit.description.placeholder = Role description
 *   roles.edit.error = Failed to update role
 *   roles.edit.loading = Loading role details...
 *   roles.edit.name.label = Role Name
 *   roles.edit.name.placeholder = e.g., Editor
 *   roles.edit.notManageable = This role can't be modified by your tenant — global base roles are managed by the system tenant.
 *   roles.edit.permissions.label = Permissions
 *   roles.edit.roleDetailsError = Failed to fetch role details
 *   roles.edit.submit = Save Changes
 *   roles.edit.submitting = Saving...
 *   roles.edit.subtitle = Update role information and permissions.
 *   roles.edit.success = Role updated successfully
 *   roles.edit.title = Edit Role
 *   roles.edit.validation.descriptionRequired = Description is required
 *   roles.edit.validation.nameRequired = Name is required
 *   roles.error.load = Failed to fetch roles
 *   roles.header.create = Create Role
 *   roles.permissions.deselectAll = Deselect All
 *   roles.permissions.filterPlaceholder = Filter permissions…
 *   roles.permissions.noMatch = No permissions match.
 *   roles.permissions.placeholder = Select permissions...
 *   roles.permissions.selectAll = Select All
 *   roles.permissions.selectAllGroup = Select all {group} permissions
 *   roles.permissions.selected.one = 1 permission selected
 *   roles.permissions.selected.other = {count} permissions selected
 *   roles.permissions.summary = {selected} of {total} selected
 *   roles.permissionsPanel.close = Close
 *   roles.permissionsPanel.empty = No permissions assigned to this role.
 *   roles.permissionsPanel.error = Failed to fetch permissions
 *   roles.permissionsPanel.loading = Loading permissions...
 *   roles.permissionsPanel.subtitle = View all permissions assigned to this role.
 *   roles.permissionsPanel.title = {name} - Permissions
 *   roles.searchPlaceholder = Search roles…
 *   roles.table.description = Description
 *   roles.table.name = Name
 *   roles.table.permissionCount = Permission Count
 *   roles.title = Roles
 */
export default function Page() {
  const { hasPermission } = useCapabilities();
  const { addToast } = useToast();

  // The Roles feature's own copy lives in the `admin` domain, but the shared UI
  // chrome it now renders directly — the DataTable/Dialog `ui.*` keys — lives in
  // `common`, exactly where the old `@/components/ui/*` wrappers sourced its
  // Arabic strings. `RolesScreen` takes a SINGLE translator, so compose one that
  // resolves `admin` first and falls back to `common` for the keys `admin`
  // lacks — restoring Arabic/RTL parity for the chrome without ever shipping an
  // English label on an RTL admin page.
  const tAdmin = useTranslation('admin');
  const tCommon = useTranslation('common');
  const t = useCallback(
    (key: string, fallback?: string, vars?: Record<string, string | number>): string => {
      const fromAdmin = tAdmin(key, I18N_MISS, vars);
      return fromAdmin === I18N_MISS ? tCommon(key, fallback, vars) : fromAdmin;
    },
    [tAdmin, tCommon]
  );

  return (
    <RolesScreen
      adapter={webRolesAdapter}
      can={hasPermission}
      t={t}
      onNotify={addToast}
    />
  );
}
