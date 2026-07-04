'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { USERS_WRITE, USERS_DELETE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type Column } from '@/components/admin/data-table';
import { Button } from '@amroksaleh/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@amroksaleh/ui/dropdown-menu';
import { IconMenu2, IconPlus } from '@tabler/icons-react';
import { CreateUserModal } from './create-modal';
import { EditUserModal } from './edit-modal';
import { DeleteUserModal } from './delete-modal';

/**
 * The user row shape, derived from the OpenAPI schema (WC-168) so it tracks
 * the published `GET /api/users` contract instead of hand-mirroring it.
 */
export type User = components['schemas']['User'];

export default function UsersPage() {
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const canCreate = hasPermission(USERS_WRITE);
  const canEdit = hasPermission(USERS_WRITE);
  const canDelete = hasPermission(USERS_DELETE);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [selectedUser, setSelectedUser] = useState<User | null>(null);

  const { data, loading: isLoading, error, refetch: fetchUsers } = useFetch(async () => {
    const { data: responseData } = await api.GET('/api/v1/users');
    if (responseData === undefined) {
      throw new Error('Failed to fetch users');
    }
    return responseData.data;
  }, []);

  const users = data ?? [];

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  const handleEditClick = (user: User) => {
    setSelectedUser(user);
    setIsEditModalOpen(true);
  };

  const handleDeleteClick = (user: User) => {
    setSelectedUser(user);
    setIsDeleteModalOpen(true);
  };

  const columns: Column<User>[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'email', label: 'Email', sortable: true },
    { key: 'role', label: 'Role', sortable: true },
    { key: 'tenantId', label: 'Tenant ID', sortable: false },
    { key: 'createdAt', label: 'Created At', sortable: true },
  ];

  const rowActions = (user: User) => {
    if (!canEdit && !canDelete) return null;
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon-sm" aria-label="Row actions">
            <IconMenu2 />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          {canEdit && (
            <DropdownMenuItem onClick={() => handleEditClick(user)}>
              Edit
            </DropdownMenuItem>
          )}
          {canDelete && (
            <DropdownMenuItem
              variant="destructive"
              onClick={() => handleDeleteClick(user)}
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
        title="Users"
        description="Manage users in your system"
        action={
          canCreate ? (
            <Button
              onClick={() => setIsCreateModalOpen(true)}
              className="gap-2"
            >
              <IconPlus />
              Create User
            </Button>
          ) : undefined
        }
      />

      <DataTable
        columns={columns}
        data={users}
        rowActions={rowActions}
        isLoading={isLoading}
      />

      <CreateUserModal
        isOpen={isCreateModalOpen}
        onOpenChange={setIsCreateModalOpen}
        onSuccess={() => {
          setIsCreateModalOpen(false);
          fetchUsers();
        }}
      />

      {selectedUser && (
        <>
          <EditUserModal
            isOpen={isEditModalOpen}
            onOpenChange={setIsEditModalOpen}
            user={selectedUser}
            onSuccess={() => {
              setIsEditModalOpen(false);
              setSelectedUser(null);
              fetchUsers();
            }}
          />

          <DeleteUserModal
            isOpen={isDeleteModalOpen}
            onOpenChange={setIsDeleteModalOpen}
            user={selectedUser}
            onSuccess={() => {
              setIsDeleteModalOpen(false);
              setSelectedUser(null);
              fetchUsers();
            }}
          />
        </>
      )}
    </div>
  );
}
