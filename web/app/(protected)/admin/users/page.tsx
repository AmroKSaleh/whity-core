'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { apiClient } from '@/lib/api-client';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { USERS_WRITE, USERS_DELETE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import {
  DataTable,
  dataTableQueryString,
  useDataTableQuery,
  type DataTableColumn,
} from '@/components/ui/data-table';
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
import { useDateDisplay } from '@amroksaleh/features/datetime';
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

/** `GET /api/v1/users` — the envelope every core list endpoint answers with. */
interface UsersResponse {
  data: User[];
  pagination?: { total: number; totalPages: number };
}

/**
 * Whether the list's Edit action opens the record page (#882) or the edit modal.
 *
 * Typed `boolean` rather than left as a literal so both branches stay live code
 * that the compiler checks — a `true` literal would narrow the modal path to
 * unreachable and let it rot until the day somebody needs it back.
 */
const EDIT_OPENS_RECORD: boolean = true;

export default function UsersPage() {
  const { addToast } = useToast();
  const router = useRouter();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const canCreate = hasPermission(USERS_WRITE);
  const canEdit = hasPermission(USERS_WRITE);
  const canDelete = hasPermission(USERS_DELETE);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [isMembershipsModalOpen, setIsMembershipsModalOpen] = useState(false);
  const [selectedUser, setSelectedUser] = useState<User | null>(null);

  /**
   * Page, sort and search all belong to the SERVER now (#1102).
   *
   * Until this, the screen fetched `per_page=100` and sorted, filtered and
   * paginated that slice in the browser — so on a tenant with 150 people, 50 of
   * them were simply not on this screen and nothing said so. The table could
   * not have told anybody either: everything it knew was the hundred rows it
   * had been handed.
   *
   * `sortKeys` maps THIS SCREEN's column ids onto the keys
   * `UsersApiHandler::listSpec()` declares — `name`, `email`, `role`, `status`,
   * `created`, plus `tenant` for the system tenant. A column whose id already
   * is the key needs no entry. Only those columns are marked `enableSorting`
   * below: an unknown key is not an error server-side, it silently falls back
   * to the endpoint's default order, so a sortable-looking header for a column
   * the endpoint does not know would click, animate and reorder nothing.
   */
  const query = useDataTableQuery({
    sortKeys: { accountStatus: 'status', createdAt: 'created' },
  });
  const queryString = dataTableQueryString(query.request);

  // `queryString` is the whole dependency, which is also what makes `fetchUsers`
  // safe to call after a create/edit/delete: `refetch` re-runs THIS closure, so
  // the refetch carries the page, sort and term the operator was looking at
  // rather than dropping them back to an unfiltered page 1.
  const { data, loading, error, refetch: fetchUsers } = useFetch(async () => {
    const response = await apiClient(`/api/v1/users?${queryString}`);
    if (!response.ok) {
      throw new Error(t('users.error.load', 'Failed to fetch users'));
    }
    const body: UsersResponse = await response.json();
    const rows = body.data ?? [];
    return {
      rows,
      // An endpoint that answered without an envelope would otherwise report
      // zero rows across one page while rendering rows — read what is there,
      // fall back to what the page itself proves.
      total: body.pagination?.total ?? rows.length,
      totalPages: body.pagination?.totalPages ?? 1,
    };
  }, [queryString]);

  const users = data?.rows ?? [];

  /**
   * The skeleton is for the FIRST load only.
   *
   * DataTable's loading branch replaces the whole table — search box included —
   * so showing it on every request would unmount the search input mid-word:
   * each debounced keystroke would blow away the draft and the caret. `data`
   * stays null until the first response lands and is non-null forever after,
   * which is exactly "have we ever had something to show". Later requests leave
   * the previous page on screen until the new one arrives.
   */
  const isLoading = loading && data === null;

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  /** #882: open the user's RECORD PAGE. */
  const openRecord = useCallback(
    (user: User) => {
      router.push(`/admin/users/${user.id}`);
    },
    [router]
  );

  // #882: Edit opens the record page. Both paths are live on purpose — the
  // record page is ADDITIVE, and reverting it is flipping the constant above
  // rather than restoring a deleted component, which is the same property the
  // roles list gets from its optional `onOpenRecord` prop. The edit modal below
  // is still mounted and still works; #884 decides per screen when a modal is
  // actually retired.
  const handleEditClick = (user: User) => {
    if (EDIT_OPENS_RECORD) {
      openRecord(user);
      return;
    }
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

  // The per-column filter boxes are gone from `name` and `email`, and their
  // absence is the same fix as the rest of this change. A column filter is
  // applied by the table to the rows it holds, which is now ONE page: typing
  // "sara" into it would hide four of the twenty-five on screen and leave the
  // Sara on page 3 exactly where she was. The search box above the table is the
  // replacement, and it asks the server — which matches on email and role name
  // (`searchable` in the same ListSpec).
  const columns: DataTableColumn<User>[] = [
    {
      accessorKey: 'name',
      header: t('users.table.name', 'Name'),
      enableSorting: true,
      // #882: the row's own name opens the record — the affordance a list gets
      // once its records have addresses. Same treatment the roles list gained.
      cell: (user) => (
        <button
          type="button"
          onClick={() => openRecord(user)}
          className="text-start font-medium text-primary underline-offset-4 hover:underline"
        >
          {user.name}
        </button>
      ),
    },
    {
      accessorKey: 'email',
      header: t('users.table.email', 'Email'),
      enableSorting: true,
    },
    { accessorKey: 'role', header: t('users.table.role', 'Role'), enableSorting: true },
    // NOT sortable, deliberately. `tenant` is in the spec's `sortable` map only
    // when the caller is the system tenant — for anybody else every row holds
    // the same tenant id, so the key would order nothing and the endpoint does
    // not offer it. This screen does not know which caller it is serving, and a
    // header that sorts for one operator and silently does nothing for the next
    // is worse than one that never claimed to.
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
    // #1068. Two things changed here. The column is now GATED on the tenant
    // preference, and it is now FORMATTED at all: an `accessorKey` with no
    // `cell` renders `String(value)`, so this column has been printing the raw
    // wire string — `2026-08-25 14:02:11`, unlocalised, in the middle of an
    // otherwise Arabic table — since it was written.
    ...dates.dateColumns<User>([
      {
        id: 'createdAt',
        header: t('users.table.createdAt', 'Created At'),
        value: (user) => user.createdAt,
        enableSorting: true,
      },
    ]),
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
        globalFilterPlaceholder={t('users.searchPlaceholder', 'Search users…')}
        sorting={query.sorting}
        search={query.search}
        pagination={query.pagination({
          total: data?.total ?? 0,
          totalPages: data?.totalPages ?? 1,
        })}
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
