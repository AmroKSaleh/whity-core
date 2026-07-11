'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { ROLES_WRITE, ROLES_DELETE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type Column } from '@/components/admin/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@amroksaleh/ui/dropdown-menu';
import { IconMenu2, IconPlus, IconSearch } from '@tabler/icons-react';
import { CreateRoleModal } from './create-modal';
import { EditRoleModal } from './edit-modal';
import { DeleteRoleModal } from './delete-modal';
import { PermissionsPanel } from './permissions-panel';
import type { Role } from './types';

export default function RolesPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const canCreate = hasPermission(ROLES_WRITE);
  const canEdit = hasPermission(ROLES_WRITE);
  const canDelete = hasPermission(ROLES_DELETE);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [isPermissionsPanelOpen, setIsPermissionsPanelOpen] = useState(false);
  const [selectedRole, setSelectedRole] = useState<Role | null>(null);
  const [query, setQuery] = useState('');
  const [cloneInitial, setCloneInitial] = useState<
    { name: string; description: string; permissionIds: number[] } | undefined
  >(undefined);

  const { data, loading: isLoading, error, refetch: fetchRoles } = useFetch(async () => {
    const response = await apiClient('/api/v1/roles');
    if (!response.ok) {
      throw new Error('Failed to fetch roles');
    }
    const data = await response.json();
    return (data.data ?? []) as Role[];
  }, [apiClient]);

  const allRoles = data ?? [];
  const q = query.trim().toLowerCase();
  const roles = q
    ? allRoles.filter(
        (r) => r.name.toLowerCase().includes(q) || (r.description ?? '').toLowerCase().includes(q)
      )
    : allRoles;

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  const handleViewPermissions = (role: Role) => {
    setSelectedRole(role);
    setIsPermissionsPanelOpen(true);
  };

  const handleEditClick = (role: Role) => {
    setSelectedRole(role);
    setIsEditModalOpen(true);
  };

  const handleDeleteClick = (role: Role) => {
    setSelectedRole(role);
    setIsDeleteModalOpen(true);
  };

  // Clone: open the Create modal prefilled with the source role's permissions
  // (works even for non-manageable global base roles — the clone is a new
  // tenant role). Uses the existing create API; no new endpoint.
  const handleCloneClick = async (role: Role) => {
    try {
      const response = await apiClient(`/api/v1/roles/${role.id}`);
      if (!response.ok) throw new Error('Failed to load role to clone');
      const detail = await response.json();
      const permissionIds: number[] = (detail.data?.permissions ?? []).map(
        (p: { id: number }) => p.id
      );
      setCloneInitial({
        name: `${role.name} (copy)`,
        description: role.description,
        permissionIds,
      });
      setIsCreateModalOpen(true);
    } catch (err) {
      addToast(err instanceof Error ? err.message : 'Failed to clone role', 'error');
    }
  };

  const columns: Column<Role>[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'description', label: 'Description', sortable: true },
    { key: 'permissionCount', label: 'Permission Count', sortable: false },
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
          <Button variant="ghost" size="icon-sm">
            <IconMenu2 size={16} />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onClick={() => handleViewPermissions(role)}>
            View Permissions
          </DropdownMenuItem>
          {canCreate && (
            <DropdownMenuItem onClick={() => void handleCloneClick(role)}>
              Clone
            </DropdownMenuItem>
          )}
          {canEdit && (
            <DropdownMenuItem
              disabled={editDisabled}
              title={
                editDisabled
                  ? 'Global base roles can only be edited by the system tenant.'
                  : undefined
              }
              onClick={
                editDisabled ? undefined : () => handleEditClick(role)
              }
            >
              Edit
            </DropdownMenuItem>
          )}
          {canDelete && (
            <DropdownMenuItem
              disabled={deleteDisabled}
              title={
                deleteDisabled
                  ? 'Global base roles can only be deleted by the system tenant.'
                  : undefined
              }
              onClick={
                deleteDisabled ? undefined : () => handleDeleteClick(role)
              }
              className="text-destructive focus:text-destructive"
            >
              Delete
            </DropdownMenuItem>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    );
  };

  return (
    <div className="space-y-8">
      <AdminHeader
        title="Roles"
        description="Manage roles and their permissions"
        action={
          canCreate ? (
            <Button
              onClick={() => setIsCreateModalOpen(true)}
              className="gap-2"
            >
              <IconPlus size={18} />
              Create Role
            </Button>
          ) : undefined
        }
      />

      <div className="relative max-w-sm">
        <IconSearch
          size={16}
          className="pointer-events-none absolute inset-s-2.5 top-1/2 -translate-y-1/2 text-muted-foreground"
        />
        <Input
          data-testid="roles-search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Filter roles…"
          className="ps-8"
        />
      </div>

      <DataTable
        columns={columns}
        data={roles}
        rowActions={rowActions}
        isLoading={isLoading}
      />

      <CreateRoleModal
        isOpen={isCreateModalOpen}
        initial={cloneInitial}
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
          <EditRoleModal
            isOpen={isEditModalOpen}
            onOpenChange={setIsEditModalOpen}
            role={selectedRole}
            onSuccess={() => {
              setIsEditModalOpen(false);
              setSelectedRole(null);
              fetchRoles();
            }}
          />

          <DeleteRoleModal
            isOpen={isDeleteModalOpen}
            onOpenChange={setIsDeleteModalOpen}
            role={selectedRole}
            onSuccess={() => {
              setIsDeleteModalOpen(false);
              setSelectedRole(null);
              fetchRoles();
            }}
          />

          <PermissionsPanel
            isOpen={isPermissionsPanelOpen}
            onOpenChange={setIsPermissionsPanelOpen}
            role={selectedRole}
          />
        </>
      )}
    </div>
  );
}
