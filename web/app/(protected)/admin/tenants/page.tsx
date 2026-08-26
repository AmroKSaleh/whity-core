'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { TENANTS_WRITE, TENANTS_DELETE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@amroksaleh/ui/dropdown-menu';
import { IconMenu2, IconPlus } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useDateDisplay } from '@amroksaleh/features/datetime';
import { CreateTenantModal } from './create-modal';
import { DeleteTenantModal } from './delete-modal';

/**
 * The tenant row shape.
 *
 * Re-exported from the record screen, which derives it from the OpenAPI schema
 * (WC-168), rather than hand-mirrored here: this page used to declare `slug` and
 * `createdAt` as non-nullable while the published contract says both may be
 * null, and the delete dialog read the first of those straight into a sentence.
 */
import type { Tenant } from './record-screen';

export type { Tenant };

export default function TenantsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const canCreate = hasPermission(TENANTS_WRITE);
  const canEdit = hasPermission(TENANTS_WRITE);
  const canDelete = hasPermission(TENANTS_DELETE);

  const router = useRouter();

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [selectedTenant, setSelectedTenant] = useState<Tenant | null>(null);

  // The backend supports page/per_page but not sort/filter query params, so
  // sort/filter/pagination all run CLIENT-side over a single fetch — fetching
  // the backend's own page-size ceiling (100) rather than its default fixes
  // the previous silent page-1-only truncation for the common case. Tenants
  // beyond 100 rows are still capped; that's a pre-existing limit, just moved
  // further out.
  const { data, loading: isLoading, error, refetch: fetchTenants } = useFetch(async () => {
    const response = await apiClient('/api/v1/tenants?per_page=100');
    if (!response.ok) {
      throw new Error(t('tenants.error.load', 'Failed to fetch tenants'));
    }
    const data = await response.json();
    return (data.data ?? []) as Tenant[];
  }, [apiClient]);

  const tenants = data ?? [];

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  /**
   * #882: open the workspace's RECORD PAGE.
   *
   * This is the whole of what "Edit" means on this screen now — there is no
   * edit dialog behind a flag to fall back to. #884 asked for a decision per
   * screen, and for tenants the decision is that the page SUPERSEDES the modal:
   * the dialog edited two fields and could show nothing else, while the record
   * page carries the workspace's plan and entitlement overrides beside them and
   * states the cross-tenant write rule instead of letting Save 403.
   */
  const openRecord = useCallback(
    (tenant: Tenant) => {
      router.push(`/admin/tenants/${tenant.id}`);
    },
    [router]
  );

  const handleDeleteClick = (tenant: Tenant) => {
    setSelectedTenant(tenant);
    setIsDeleteModalOpen(true);
  };

  const columns: DataTableColumn<Tenant>[] = [
    {
      accessorKey: 'name',
      header: t('tenants.table.name', 'Name'),
      enableSorting: true,
      enableColumnFilter: true,
      // #882: the row's own name opens the record — the affordance a list gets
      // once its records have addresses. Same treatment the users and roles
      // lists gained. Offered to every reader, not only writers: the record page
      // is READABLE without tenants:write, and it is the only place a
      // workspace's plan and entitlements are shown at all.
      cell: (tenant) => (
        <button
          type="button"
          onClick={() => openRecord(tenant)}
          className="text-start font-medium text-primary underline-offset-4 hover:underline"
        >
          {tenant.name}
        </button>
      ),
    },
    {
      accessorKey: 'slug',
      header: t('tenants.table.slug', 'Slug'),
      enableSorting: true,
      enableColumnFilter: true,
    },
    { accessorKey: 'userCount', header: t('tenants.table.userCount', 'User Count'), enableSorting: true },
    // #1068, and the same two changes the users list needed: gated on the
    // tenant preference, and formatted at all rather than rendered as the raw
    // wire string an `accessorKey` with no `cell` produces.
    ...dates.dateColumns<Tenant>([
      {
        id: 'createdAt',
        header: t('tenants.table.createdAt', 'Created At'),
        value: (tenant) => tenant.createdAt,
        enableSorting: true,
      },
    ]),
  ];

  // Always rendered now: "open this workspace" is a READ, available to anyone
  // who can reach this page, and it is the only route to the plan and
  // entitlement panels the record page carries. The write controls inside it
  // stay gated on tenants:write separately — same reasoning as the users list's
  // always-present row menu (#797 §2).
  const rowActions = (tenant: Tenant) => {
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={t('tenants.rowActions.label', 'Row actions')}
          >
            <IconMenu2 size={16} />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onClick={() => openRecord(tenant)}>
            {canEdit
              ? t('tenants.actions.edit', 'Edit')
              : t('tenants.actions.view', 'Open workspace')}
          </DropdownMenuItem>
          {canDelete && (
            <DropdownMenuItem
              onClick={() => handleDeleteClick(tenant)}
              className="text-destructive focus:text-destructive"
            >
              {t('tenants.actions.delete', 'Delete')}
            </DropdownMenuItem>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    );
  };

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('tenants.title', 'Tenants')}
        description={t('tenants.description', 'Manage tenants in your system')}
        action={
          canCreate ? (
            <Button
              onClick={() => setIsCreateModalOpen(true)}
              className="gap-2"
            >
              <IconPlus size={18} />
              {t('tenants.createButton', 'Create Tenant')}
            </Button>
          ) : undefined
        }
      />

      <DataTable
        columns={columns}
        data={tenants}
        getRowId={(tenant) => String(tenant.id)}
        rowActions={rowActions}
        isLoading={isLoading}
        enableGlobalFilter
        globalFilterPlaceholder={t('tenants.searchPlaceholder', 'Search tenants…')}
        pagination={{ pageSize: 10 }}
      />

      <CreateTenantModal
        isOpen={isCreateModalOpen}
        onOpenChange={setIsCreateModalOpen}
        onSuccess={() => {
          setIsCreateModalOpen(false);
          fetchTenants();
        }}
      />

      {/* Delete stays a dialog on the LIST. A confirmation is not a record
          surface — it has no fields, nothing to link to and nothing to come back
          to afterwards, because the record it names is about to stop existing. */}
      {selectedTenant && (
        <DeleteTenantModal
          isOpen={isDeleteModalOpen}
          onOpenChange={setIsDeleteModalOpen}
          tenant={selectedTenant}
          onSuccess={() => {
            setIsDeleteModalOpen(false);
            setSelectedTenant(null);
            fetchTenants();
          }}
        />
      )}
    </div>
  );
}
