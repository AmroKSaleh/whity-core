'use client';

/**
 * Injected-translator keys this file renders through `t`. Declared here for
 * the i18n catalogue extractor: it cannot infer a domain from a prop-injected
 * translator (see RolesTranslate — deliberately NOT typed `TranslateFn`, so
 * these files stay unscanned like DemoCatalog does via NavTranslate), so the
 * keys are enumerated below instead. Feature copy resolves in the `admin`
 * domain, shared UI chrome in `common`.
 *
 * @i18n-keys admin
 *   roles.action.clone = Clone
 *   roles.action.delete = Delete
 *   roles.action.delete.disabled = Global base roles can only be deleted by the system tenant.
 *   roles.action.edit = Edit
 *   roles.action.edit.disabled = Global base roles can only be edited by the system tenant.
 *   roles.action.open = Open record
 *   roles.clone.error = Failed to clone role
 *   roles.clone.name = {name} (copy)
 *   roles.description = Manage roles and their permissions
 *   roles.error.load = Failed to fetch roles
 *   roles.header.create = Create Role
 *   roles.scope.global = Global
 *   roles.scope.global.hint = Shared by every tenant on this deployment. Editing it changes it everywhere.
 *   roles.searchPlaceholder = Search roles…
 *   roles.table.description = Description
 *   roles.table.name = Name
 *   roles.table.permissionCount = Permission Count
 *   roles.title = Roles
 * @i18n-keys common
 *   ui.pagination.entries = {count} entries
 *   ui.pagination.entry = 1 entry
 *   ui.pagination.nav = Pagination
 *   ui.pagination.next = Next page
 *   ui.pagination.page = page {page} of {total}
 *   ui.pagination.previous = Previous page
 *   ui.table.actions = Actions
 *   ui.table.columnFilter = Filter…
 *   ui.table.empty = No data available
 */

import { useEffect, useState } from 'react';
import { PageHeader } from '@amroksaleh/ui/page-header';
import { DataTable, type DataTableColumn } from '@amroksaleh/ui/data-table';
import { Badge } from '@amroksaleh/ui/badge';
import { Button } from '@amroksaleh/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@amroksaleh/ui/dropdown-menu';
import { IconMenu2, IconPlus } from '@tabler/icons-react';
import { identityTranslate } from '../nav/types';
import { ROLES_WRITE, ROLES_DELETE } from './capabilities';
import { CreateRoleModal } from './create-modal';
import { DeleteRoleModal } from './delete-modal';
import type { Role, RolesScreenProps, RolesTranslate } from './types';

/**
 * The Roles admin screen — presentational and data-source-agnostic (Path B
 * extraction pilot). Never fetches directly: all data access goes through the
 * injected `adapter`, capability checks through `can`, copy through `t`, and
 * notifications through `onNotify`, so it mounts unchanged in the Next web app,
 * a Tauri desktop shell, or the Vite SPA harness.
 */
export function RolesScreen({
  adapter,
  can,
  t: injectedT,
  onNotify,
  onOpenRecord,
  scope,
  className,
}: RolesScreenProps) {
  const t: RolesTranslate = injectedT ?? identityTranslate;

  const canCreate = can(ROLES_WRITE);
  const canEdit = can(ROLES_WRITE);
  const canDelete = can(ROLES_DELETE);

  const [roles, setRoles] = useState<Role[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [refreshKey, setRefreshKey] = useState(0);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [selectedRole, setSelectedRole] = useState<Role | null>(null);
  const [cloneInitial, setCloneInitial] = useState<
    { name: string; description: string; permissionIds: number[] } | undefined
  >(undefined);

  // The backend supports page/per_page but not sort/filter query params, so
  // sort/filter/pagination all run CLIENT-side over a single fetch — the
  // adapter fetches the backend's own page-size ceiling (100) rather than its
  // default, fixing the previous silent page-1-only truncation for the common
  // case. Tenants with >100 roles are still capped until the backend grows real
  // search/sort support; that's a pre-existing limit, just moved further out.
  useEffect(() => {
    let cancelled = false;
    setIsLoading(true);
    adapter
      .listRoles()
      .then((data) => {
        if (!cancelled) setRoles(data);
      })
      .catch(() => {
        if (!cancelled) onNotify?.(t('roles.error.load', 'Failed to fetch roles'), 'error');
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });
    return () => {
      cancelled = true;
    };
    // Re-run on mount and on explicit refetch only; t/onNotify identity changes
    // must not refetch (identityTranslate is a stable module-level reference).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [adapter, refreshKey]);

  const fetchRoles = () => setRefreshKey((k) => k + 1);

  const handleDeleteClick = (role: Role) => {
    setSelectedRole(role);
    setIsDeleteModalOpen(true);
  };

  // Clone: open the Create modal prefilled with the source role's permissions
  // (works even for non-manageable global base roles — the clone is a new
  // tenant role). Uses the existing create API; no new endpoint.
  //
  // #910: `permissions` is optional on the wire now, because a caller without
  // `permissions:read` is not sent the role's permission set at all. Cloning
  // for such a caller therefore produces a role with NO permissions rather than
  // a copy — which is the only honest outcome, since the source's grants were
  // never in their hands to copy, and is strictly safer than the alternative of
  // asking the server to duplicate a set the caller may not see.
  const handleCloneClick = async (role: Role) => {
    try {
      const detail = await adapter.getRole(role.id);
      const permissionIds: number[] = (detail.permissions ?? []).map((p) => p.id);
      setCloneInitial({
        name: t('roles.clone.name', '{name} (copy)', { name: role.name }),
        description: role.description,
        permissionIds,
      });
      setIsCreateModalOpen(true);
    } catch (err) {
      onNotify?.(
        err instanceof Error && err.message ? err.message : t('roles.clone.error', 'Failed to clone role'),
        'error'
      );
    }
  };

  const columns: DataTableColumn<Role>[] = [
    {
      accessorKey: 'name',
      header: t('roles.table.name', 'Name'),
      enableSorting: true,
      enableColumnFilter: true,
      // Two things live in this cell.
      //
      // #882: the row's own name opens the record — the affordance a list gets
      // once its records have addresses. Unconditional since #910, when the seam
      // stopped being optional: editing a role happens on its record page, so a
      // host that cannot navigate to one cannot mount this screen.
      //
      // #886: a GLOBAL base role is marked HERE, next to the name, because the
      // list is where the action starts. The record page has carried a badge
      // since #885, but by the time an operator is on the record page they have
      // already decided which role to change. `role.global`, not
      // `!role.manageable`: for a tenant-0 operator every role is manageable, so
      // the flag that gates the Edit action cannot also be the flag that says
      // "this one is shared by everybody" — and tenant-0 is the only caller who
      // can actually perform that edit.
      cell: (role) => (
        <span className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => onOpenRecord(role)}
            className="text-start font-medium text-primary underline-offset-4 hover:underline"
          >
            {role.name}
          </button>
          {role.global && (
            <Badge
              variant="warning"
              data-testid="role-row-global-badge"
              title={t(
                'roles.scope.global.hint',
                'Shared by every tenant on this deployment. Editing it changes it everywhere.'
              )}
            >
              {t('roles.scope.global', 'Global')}
            </Badge>
          )}
        </span>
      ),
    },
    {
      accessorKey: 'description',
      header: t('roles.table.description', 'Description'),
      enableSorting: true,
      enableColumnFilter: true,
    },
    {
      accessorKey: 'permissionCount',
      header: t('roles.table.permissionCount', 'Permission Count'),
      enableSorting: false,
    },
  ];

  const rowActions = (role: Role) => {
    // Two independent gates apply to Edit/Delete: the caller must hold the
    // capability (ROLES_WRITE / ROLES_DELETE) AND the role must be manageable
    // by the current tenant. A global NULL-tenant base role is visible but not
    // manageable by a regular tenant, so writing it would 404 by design
    // (WC-110); we surface that as a DISABLED item with an explanatory tooltip
    // rather than letting the click fall through to a raw error (WC-222).
    const editDisabled = !role.manageable;
    const deleteDisabled = !role.manageable;

    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          {/* Named explicitly: an icon-only trigger had no accessible name at
              all, and #882 puts a SECOND button in the row (the name, which
              opens the record) — so "the button in this row" stopped being
              unambiguous for a screen reader and for a test alike. */}
          <Button variant="ghost" size="icon-sm" aria-label={t('ui.table.actions', 'Actions')}>
            <IconMenu2 size={16} />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          {/* "View Permissions" used to open a dialog showing this role's
              permission list. #910 retired it: the record page shows the same
              list, under a gate the dialog never had — a role's permission set
              is a description of what its holders can do to the whole install,
              and `permissions:read` is the slug that governs seeing it. A second
              surface that showed the same rows without asking would have been
              the gate's own bypass. */}
          <DropdownMenuItem onClick={() => onOpenRecord(role)}>
            {t('roles.action.open', 'Open record')}
          </DropdownMenuItem>
          {canCreate && (
            <DropdownMenuItem onClick={() => void handleCloneClick(role)}>
              {t('roles.action.clone', 'Clone')}
            </DropdownMenuItem>
          )}
          {canEdit && (
            <DropdownMenuItem
              disabled={editDisabled}
              title={
                editDisabled
                  ? t(
                      'roles.action.edit.disabled',
                      'Global base roles can only be edited by the system tenant.'
                    )
                  : undefined
              }
              // #910: Edit goes to the RECORD PAGE, always. The edit modal it
              // used to fall back to is gone — a `max-w-3xl` dialog wrapping a
              // `max-h-80` scroll region holding 53+ permission checkboxes was
              // the acute case behind #882, and it has no way to express a
              // record whose parts are governed separately: one gate, one set of
              // inputs, nowhere to say why half of them are missing.
              onClick={editDisabled ? undefined : () => onOpenRecord(role)}
            >
              {t('roles.action.edit', 'Edit')}
            </DropdownMenuItem>
          )}
          {canDelete && (
            <DropdownMenuItem
              disabled={deleteDisabled}
              title={
                deleteDisabled
                  ? t(
                      'roles.action.delete.disabled',
                      'Global base roles can only be deleted by the system tenant.'
                    )
                  : undefined
              }
              onClick={
                deleteDisabled ? undefined : () => handleDeleteClick(role)
              }
              className="text-destructive focus:text-destructive"
            >
              {t('roles.action.delete', 'Delete')}
            </DropdownMenuItem>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    );
  };

  return (
    <div className={className ?? 'space-y-8'}>
      <PageHeader
        title={t('roles.title', 'Roles')}
        description={t('roles.description', 'Manage roles and their permissions')}
        action={
          canCreate ? (
            <Button
              onClick={() => setIsCreateModalOpen(true)}
              className="gap-2"
            >
              <IconPlus size={18} />
              {t('roles.header.create', 'Create Role')}
            </Button>
          ) : undefined
        }
      />

      <DataTable
        columns={columns}
        data={roles}
        getRowId={(role) => String(role.id)}
        rowActions={rowActions}
        rowActionsLabel={t('ui.table.actions', 'Actions')}
        isLoading={isLoading}
        enableGlobalFilter
        globalFilterPlaceholder={t('roles.searchPlaceholder', 'Search roles…')}
        columnFilterPlaceholder={t('ui.table.columnFilter', 'Filter…')}
        emptyStateTitle={t('ui.table.empty', 'No data available')}
        pagination={{ pageSize: 10 }}
        paginationLabels={{
          entriesLabel: (total) =>
            total === 1
              ? t('ui.pagination.entry', '1 entry')
              : t('ui.pagination.entries', '{count} entries', { count: total }),
          pageLabel: (page, totalPages) =>
            t('ui.pagination.page', 'page {page} of {total}', { page, total: totalPages }),
          navLabel: t('ui.pagination.nav', 'Pagination'),
          previousLabel: t('ui.pagination.previous', 'Previous page'),
          nextLabel: t('ui.pagination.next', 'Next page'),
        }}
      />

      <CreateRoleModal
        isOpen={isCreateModalOpen}
        initial={cloneInitial}
        scope={scope}
        adapter={adapter}
        t={t}
        onNotify={onNotify}
        onOpenChange={(open) => {
          setIsCreateModalOpen(open);
          if (!open) setCloneInitial(undefined);
        }}
        onSuccess={() => {
          setIsCreateModalOpen(false);
          setCloneInitial(undefined);
          fetchRoles();
        }}
      />

      {selectedRole && (
        <>
          <DeleteRoleModal
            isOpen={isDeleteModalOpen}
            onOpenChange={setIsDeleteModalOpen}
            role={selectedRole}
            adapter={adapter}
            t={t}
            onNotify={onNotify}
            onSuccess={() => {
              setIsDeleteModalOpen(false);
              setSelectedRole(null);
              fetchRoles();
            }}
          />
        </>
      )}
    </div>
  );
}
