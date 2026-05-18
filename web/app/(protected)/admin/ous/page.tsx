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
import { CreateOuModal } from './create-modal';
import { EditOuModal } from './edit-modal';
import { DeleteOuModal } from './delete-modal';
import type { OU } from './types';

export default function OUsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [ous, setOus] = useState<OU[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [selectedOu, setSelectedOu] = useState<OU | null>(null);

  const fetchOUs = async () => {
    try {
      setIsLoading(true);
      const response = await apiClient('/api/ous');

      if (!response.ok) {
        throw new Error('Failed to fetch organizational units');
      }

      const data = await response.json();
      setOus(data.data || []);
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to fetch organizational units';
      addToast(message, 'error');
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchOUs();
  }, []);

  const handleEditClick = (ou: OU) => {
    setSelectedOu(ou);
    setIsEditModalOpen(true);
  };

  const handleDeleteClick = (ou: OU) => {
    setSelectedOu(ou);
    setIsDeleteModalOpen(true);
  };

  const columns: Column<OU>[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'slug', label: 'Slug', sortable: true },
    { key: 'description', label: 'Description', sortable: false },
    { key: 'parent_id', label: 'Parent ID', sortable: false },
  ];

  const rowActions = (ou: OU) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon-sm">
          <IconMenu2 size={16} />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onClick={() => handleEditClick(ou)}>
          Edit
        </DropdownMenuItem>
        <DropdownMenuItem
          onClick={() => handleDeleteClick(ou)}
          className="text-red-600 focus:text-red-600 dark:text-red-400 dark:focus:text-red-400"
        >
          Delete
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );

  return (
    <div className="ml-64 min-h-screen bg-slate-50 dark:bg-slate-950">
      <AdminHeader
        title="Organizational Units"
        description="Manage organizational units and hierarchies"
      />

      <div className="flex-1 space-y-8 p-8">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-3xl font-bold tracking-tight">Organizational Units</h2>
            <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
              Create and manage organizational units (OUs) to structure your organization
            </p>
          </div>
          <Button onClick={() => setIsCreateModalOpen(true)}>
            <IconPlus size={20} className="mr-2" />
            Create OU
          </Button>
        </div>

        {isLoading ? (
          <div className="flex items-center justify-center py-8">
            <p className="text-slate-600 dark:text-slate-400">Loading...</p>
          </div>
        ) : ous.length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-300 py-8 dark:border-slate-700">
            <p className="text-slate-600 dark:text-slate-400">No organizational units yet</p>
            <Button
              onClick={() => setIsCreateModalOpen(true)}
              variant="outline"
              className="mt-4"
            >
              Create the first OU
            </Button>
          </div>
        ) : (
          <DataTable columns={columns} data={ous} rowActions={rowActions} />
        )}
      </div>

      <CreateOuModal
        isOpen={isCreateModalOpen}
        onClose={() => {
          setIsCreateModalOpen(false);
          setSelectedOu(null);
        }}
        onSuccess={() => {
          setIsCreateModalOpen(false);
          fetchOUs();
        }}
        ous={ous}
      />

      {selectedOu && (
        <EditOuModal
          isOpen={isEditModalOpen}
          onClose={() => {
            setIsEditModalOpen(false);
            setSelectedOu(null);
          }}
          onSuccess={() => {
            setIsEditModalOpen(false);
            setSelectedOu(null);
            fetchOUs();
          }}
          ou={selectedOu}
          ous={ous}
        />
      )}

      {selectedOu && (
        <DeleteOuModal
          isOpen={isDeleteModalOpen}
          onClose={() => {
            setIsDeleteModalOpen(false);
            setSelectedOu(null);
          }}
          onSuccess={() => {
            setIsDeleteModalOpen(false);
            setSelectedOu(null);
            fetchOUs();
          }}
          ou={selectedOu}
        />
      )}
    </div>
  );
}
