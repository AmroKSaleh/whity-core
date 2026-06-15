'use client';

import { useCallback, useEffect, useState } from 'react';
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
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [selectedTenant, setSelectedTenant] = useState<Tenant | null>(null);

  const fetchTenants = useCallback(async () => {
    try {
      setIsLoading(true);
      const response = await apiClient('/api/tenants');

      if (!response.ok) {
        throw new Error('Failed to fetch tenants');
      }

      const data = await response.json();
      setTenants(data.data || []);
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to fetch tenants';
      addToast(message, 'error');
    } finally {
      setIsLoading(false);
    }
  }, [apiClient, addToast]);

  useEffect(() => {
    void (async () => {
      await fetchTenants();
    })();
  }, [fetchTenants]);

  const handleEditClick = (tenant: Tenant) => {
    setSelectedTenant(tenant);
    setIsEditModalOpen(true);
  };

  const handleDeleteClick = (tenant: Tenant) => {
    setSelectedTenant(tenant);
    setIsDeleteModalOpen(true);
  };

  const columns: Column<Tenant>[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'slug', label: 'Slug', sortable: true },
    { key: 'userCount', label: 'User Count', sortable: true },
    { key: 'createdAt', label: 'Created At', sortable: true },
  ];

  const rowActions = (tenant: Tenant) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon-sm">
          <IconMenu2 size={16} />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onClick={() => handleEditClick(tenant)}>
          Edit
        </DropdownMenuItem>
        <DropdownMenuItem
          onClick={() => handleDeleteClick(tenant)}
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
        title="Tenants"
        description="Manage tenants in your system"
        action={
          <Button
            onClick={() => setIsCreateModalOpen(true)}
            className="gap-2"
          >
            <IconPlus size={18} />
            Create Tenant
          </Button>
        }
      />

      <DataTable
        columns={columns}
        data={tenants}
        rowActions={rowActions}
        isLoading={isLoading}
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
