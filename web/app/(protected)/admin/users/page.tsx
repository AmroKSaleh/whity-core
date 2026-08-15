'use client';

import { useEffect, useState } from 'react';
import { apiClient } from '@/lib/api-client';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { USERS_WRITE, USERS_DELETE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@amroksaleh/ui/dropdown-menu';
import { IconMenu2, IconPlus } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { CreateUserModal } from './create-modal';
import { EditUserModal } from './edit-modal';
import { DeleteUserModal } from './delete-modal';
import { InvitationsPanel } from './invitations-panel';
import { MembershipsModal } from './memberships-modal';

/**
 * The user row shape, derived from the OpenAPI schema (WC-168) so it tracks
 * the published `GET /api/users` contract instead of hand-mirroring it.
 */
export type User = components['schemas']['User'];

export default function UsersPage() {
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const canCreate = hasPermission(USERS_WRITE);
  const canEdit = hasPermission(USERS_WRITE);
  const canDelete = hasPermission(USERS_DELETE);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [isMembershipsModalOpen, setIsMembershipsModalOpen] = useState(false);
  const [selectedUser, setSelectedUser] = useState<User | null>(null);

  // The backend supports page/per_page but not sort/filter query params, so
  // sort/filter/pagination all run CLIENT-side over a single fetch — fetching
  // the backend's own page-size ceiling (100) rather than its 25-row default
  // fixes the previous silent page-1-only truncation for the common case.
  // Tenants with >100 users are still capped until the backend grows real
  // search/sort support; that's a pre-existing limit, just moved further out.
  const { data, loading: isLoading, error, refetch: fetchUsers } = useFetch(async () => {
    const response = await apiClient('/api/v1/users?per_page=100');
    if (!response.ok) {
      throw new Error(t('users.error.load', 'Failed to fetch users'));
    }
    const body: { data: User[] } = await response.json();
    return body.data;
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

  const handleMembershipsClick = (user: User) => {
    setSelectedUser(user);
    setIsMembershipsModalOpen(true);
  };

  // Deactivate/reactivate (WC-user-status) is gated on the SAME users:write
  // capability as Edit — no dedicated permission exists for it server-side.
  const handleToggleAccountStatus = async (user: User) => {
    const nextStatus = user.accountStatus === 'inactive' ? 'active' : 'inactive';
    try {
      const { error, response } = await api.PATCH('/api/v1/users/{id}', {
        params: { path: { id: user.id } },
        body: { accountStatus: nextStatus },
      });

      if (error !== undefined || !response.ok) {
        throw new Error(
          error?.error ?? t('users.status.error', 'Failed to update user status')
        );
      }

      addToast(
        nextStatus === 'inactive'
          ? t('users.status.deactivated', 'User deactivated')
          : t('users.status.reactivated', 'User reactivated'),
        'success'
      );
      fetchUsers();
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('users.status.error', 'Failed to update user status');
      addToast(message, 'error');
    }
  };

  const columns: DataTableColumn<User>[] = [
    {
      accessorKey: 'name',
      header: t('users.table.name', 'Name'),
      enableSorting: true,
      enableColumnFilter: true,
    },
    {
      accessorKey: 'email',
      header: t('users.table.email', 'Email'),
      enableSorting: true,
      enableColumnFilter: true,
    },
    { accessorKey: 'role', header: t('users.table.role', 'Role'), enableSorting: true },
    { accessorKey: 'tenantId', header: t('users.table.tenantId', 'Tenant ID') },
    {
      accessorKey: 'accountStatus',
      header: t('users.table.status', 'Status'),
      enableSorting: true,
      cell: (user) => (
        <Badge variant={user.accountStatus === 'inactive' ? 'secondary' : 'outline'}>
          {user.accountStatus === 'inactive'
            ? t('users.status.inactive', 'Inactive')
            : t('users.status.active', 'Active')}
        </Badge>
      ),
    },
    {
      accessorKey: 'createdAt',
      header: t('users.table.createdAt', 'Created At'),
      enableSorting: true,
    },
  ];

  // The dropdown is always rendered: "which tenants is this person in?" is a
  // READ, available to anyone who can reach this page, and it is the only place
  // that question is answered at all (#797 §2). The grant/revoke controls inside
  // the modal are gated on users:write separately.
  const rowActions = (user: User) => {
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={t('users.rowActions.label', 'Row actions')}
          >
            <IconMenu2 />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onClick={() => handleMembershipsClick(user)}>
            {t('users.rowActions.memberships', 'Tenants and roles')}
          </DropdownMenuItem>
          {canEdit && (
            <DropdownMenuItem onClick={() => handleEditClick(user)}>
              {t('users.rowActions.edit', 'Edit')}
            </DropdownMenuItem>
          )}
          {canEdit && (
            <DropdownMenuItem onClick={() => handleToggleAccountStatus(user)}>
              {user.accountStatus === 'inactive'
                ? t('users.rowActions.reactivate', 'Reactivate')
                : t('users.rowActions.deactivate', 'Deactivate')}
            </DropdownMenuItem>
          )}
          {canDelete && (
            <DropdownMenuItem
              variant="destructive"
              onClick={() => handleDeleteClick(user)}
            >
              {t('users.rowActions.delete', 'Delete')}
            </DropdownMenuItem>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    );
  };

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('users.title', 'Users')}
        description={t('users.description', 'Manage users in your system')}
        action={
          canCreate ? (
            <Button
              onClick={() => setIsCreateModalOpen(true)}
              className="gap-2"
            >
              <IconPlus />
              {t('users.createUser', 'Create User')}
            </Button>
          ) : undefined
        }
      />

      <DataTable
        ariaLabel={t('users.table.label', 'Users')}
        columns={columns}
        data={users}
        getRowId={(user) => String(user.id)}
        rowActions={rowActions}
        isLoading={isLoading}
        enableGlobalFilter
        globalFilterPlaceholder={t('users.searchPlaceholder', 'Search users…')}
        pagination={{ pageSize: 10 }}
      />

      {/*
        Invitations sit under the user list rather than on a page of their own:
        an invited person is a user who has not arrived yet, and "is Sara in?"
        should not depend on knowing whether she was added or invited (WHIT-417).
      */}
      <InvitationsPanel />

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

          {/*
            Closing re-fetches the list: a granted role can change the PRIMARY
            row the list shows, and a stale table would contradict the modal
            the operator just used.
          */}
          <MembershipsModal
            isOpen={isMembershipsModalOpen}
            onOpenChange={(open) => {
              setIsMembershipsModalOpen(open);
              if (!open) {
                fetchUsers();
              }
            }}
            user={selectedUser}
            canManage={canEdit}
          />
        </>
      )}
    </div>
  );
}
