'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { TENANTS_WRITE, TENANTS_DELETE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@amroksaleh/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@amroksaleh/ui/dropdown-menu';
import { IconMenu2, IconPlus } from '@tabler/icons-react';
import { CreateTenantModal } from './create-modal';
import { EditTenantModal } from './edit-modal';
import { DeleteTenantModal } from './delete-modal';

export interface Tenant {
  id: number;
  name: string;
  slug: string;
  userCount: number;
  createdAt: string;
}

export default function TenantsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const canCreate = hasPermission(TENANTS_WRITE);
  const canEdit = hasPermission(TENANTS_WRITE);
  const canDelete = hasPermission(TENANTS_DELETE);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
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
      throw new Error('Failed to fetch tenants');
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

  const handleEditClick = (tenant: Tenant) => {
    setSelectedTenant(tenant);
    setIsEditModalOpen(true);
  };

  const handleDeleteClick = (tenant: Tenant) => {
    setSelectedTenant(tenant);
    setIsDeleteModalOpen(true);
  };

  const columns: DataTableColumn<Tenant>[] = [
    { accessorKey: 'name', header: 'Name', enableSorting: true, enableColumnFilter: true },
    { accessorKey: 'slug', header: 'Slug', enableSorting: true, enableColumnFilter: true },
    { accessorKey: 'userCount', header: 'User Count', enableSorting: true },
    { accessorKey: 'createdAt', header: 'Created At', enableSorting: true },
  ];

  const rowActions = (tenant: Tenant) => {
    if (!canEdit && !canDelete) return null;
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon-sm">
            <IconMenu2 size={16} />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          {canEdit && (
            <DropdownMenuItem onClick={() => handleEditClick(tenant)}>
              Edit
            </DropdownMenuItem>
          )}
          {canDelete && (
            <DropdownMenuItem
              onClick={() => handleDeleteClick(tenant)}
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
        title="Tenants"
        description="Manage tenants in your system"
        action={
          canCreate ? (
            <Button
              onClick={() => setIsCreateModalOpen(true)}
              className="gap-2"
            >
              <IconPlus size={18} />
              Create Tenant
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
        globalFilterPlaceholder="Search tenants…"
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

      {selectedTenant && (
        <>
          <EditTenantModal
            isOpen={isEditModalOpen}
            onOpenChange={setIsEditModalOpen}
            tenant={selectedTenant}
            onSuccess={() => {
              setIsEditModalOpen(false);
              setSelectedTenant(null);
              fetchTenants();
            }}
          />

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
        </>
      )}
    </div>
  );
}
