'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type Column } from '@/components/admin/data-table';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { IconMenu2, IconPlus } from '@tabler/icons-react';
import { CreateRoleModal } from './create-modal';
import { EditRoleModal } from './edit-modal';
import { DeleteRoleModal } from './delete-modal';
import { PermissionsPanel } from './permissions-panel';
import type { Role } from './types';

export default function RolesPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [isPermissionsPanelOpen, setIsPermissionsPanelOpen] = useState(false);
  const [selectedRole, setSelectedRole] = useState<Role | null>(null);

  const { data, loading: isLoading, error, refetch: fetchRoles } = useFetch(async () => {
    const response = await apiClient('/api/roles');
    if (!response.ok) {
      throw new Error('Failed to fetch roles');
    }
    const data = await response.json();
    return (data.data ?? []) as Role[];
  }, [apiClient]);

  const roles = data ?? [];

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

  const columns: Column<Role>[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'description', label: 'Description', sortable: true },
    { key: 'permissionCount', label: 'Permission Count', sortable: false },
  ];

  const rowActions = (role: Role) => (
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
        <DropdownMenuItem onClick={() => handleEditClick(role)}>
          Edit
        </DropdownMenuItem>
        <DropdownMenuItem
          onClick={() => handleDeleteClick(role)}
          className="text-destructive focus:text-destructive"
        >
          Delete
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );

  return (
    <div className="space-y-8">
      <AdminHeader
        title="Roles"
        description="Manage roles and their permissions"
        action={
          <Button
            onClick={() => setIsCreateModalOpen(true)}
            className="gap-2"
          >
            <IconPlus size={18} />
            Create Role
          </Button>
        }
      />

      <DataTable
        columns={columns}
        data={roles}
        rowActions={rowActions}
        isLoading={isLoading}
      />

      <CreateRoleModal
        isOpen={isCreateModalOpen}
        onOpenChange={setIsCreateModalOpen}
        onSuccess={() => {
          setIsCreateModalOpen(false);
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
