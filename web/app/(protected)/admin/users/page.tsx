'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
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
import { CreateUserModal } from './create-modal';
import { EditUserModal } from './edit-modal';
import { DeleteUserModal } from './delete-modal';

export interface User {
  id: number;
  name: string;
  email: string;
  role: string;
  tenantId: number;
  createdAt: string;
}

export default function UsersPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [users, setUsers] = useState<User[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [selectedUser, setSelectedUser] = useState<User | null>(null);

  const fetchUsers = async () => {
    try {
      setIsLoading(true);
      const response = await apiClient('/api/users');

      if (!response.ok) {
        throw new Error('Failed to fetch users');
      }

      const data = await response.json();
      setUsers(data.data || []);
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to fetch users';
      addToast(message, 'error');
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchUsers();
  }, []);

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

  const rowActions = (user: User) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon-sm">
          <IconMenu2 size={16} />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onClick={() => handleEditClick(user)}>
          Edit
        </DropdownMenuItem>
        <DropdownMenuItem
          onClick={() => handleDeleteClick(user)}
          className="text-red-600 focus:text-red-600 dark:text-red-400 dark:focus:text-red-400"
        >
          Delete
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );

  return (
    <div className="space-y-8">
      <AdminHeader
        title="Users"
        description="Manage users in your system"
        action={
          <Button
            onClick={() => setIsCreateModalOpen(true)}
            className="gap-2"
          >
            <IconPlus size={18} />
            Create User
          </Button>
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
