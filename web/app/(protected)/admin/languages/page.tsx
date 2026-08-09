'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { LANGUAGES_MANAGE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@amroksaleh/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Switch } from '@amroksaleh/ui/switch';
import { AccessDenied } from '@amroksaleh/ui/access-denied';
import { IconPlus } from '@tabler/icons-react';
import { CreateLanguageModal } from './create-modal';
import { errorMessage } from './shared';
import type { Language } from './types';

/**
 * Languages carry no `tenant_id` column at all — create/update/enable/
 * disable is a PLATFORM capability restricted to the SYSTEM tenant (id 0),
 * mirroring the Feature Flags/Email/Storage settings tabs (WC-583). The nav
 * item is already hidden from every other tenant (systemTenantOnly in
 * public/index.php's navigation.register), but a direct URL visit is still
 * gated here defensively.
 */
const SYSTEM_TENANT_ID = 0;

export default function LanguagesPage() {
  const { user } = useAuth();
  const { addToast } = useToast();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();

  const canRead = hasPermission(LANGUAGES_MANAGE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [togglingId, setTogglingId] = useState<number | null>(null);

  const {
    data,
    loading: isLoading,
    error,
    refetch: fetchLanguages,
  } = useFetch(async () => {
    const { data: body, error } = await api.GET('/api/v1/admin/languages');
    if (body === undefined) {
      throw new Error(errorMessage(error, 'Failed to fetch languages'));
    }
    return body.data;
  }, []);

  const languages = data ?? [];

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  const handleToggle = async (language: Language, enabled: boolean) => {
    setTogglingId(language.id);
    try {
      const { error } = await api.PATCH('/api/v1/languages/{id}', {
        params: { path: { id: language.id } },
        body: { enabled },
      });
      if (error) {
        throw new Error(errorMessage(error, 'Failed to update language'));
      }
      addToast(`${language.name} ${enabled ? 'enabled' : 'disabled'}.`, 'success');
      fetchLanguages();
    } catch (err) {
      addToast(err instanceof Error ? err.message : 'Failed to update language', 'error');
    } finally {
      setTogglingId(null);
    }
  };

  const columns: DataTableColumn<Language>[] = [
    { accessorKey: 'code', header: 'Code', enableSorting: true, enableColumnFilter: true },
    { accessorKey: 'name', header: 'Name', enableSorting: true, enableColumnFilter: true },
    {
      id: 'enabled',
      header: 'Enabled',
      enableSorting: true,
      cell: (language) => (
        <Switch
          checked={language.enabled}
          disabled={togglingId === language.id}
          onCheckedChange={(checked) => void handleToggle(language, checked)}
          aria-label={`Toggle ${language.name}`}
        />
      ),
    },
  ];

  if (isCapabilitiesLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!canRead) {
    return (
      <AccessDenied
        description={
          <>
            You do not have the required permission (<code>languages:manage</code>) to view
            Languages.
          </>
        }
        action={
          <Button onClick={() => window.history.back()} variant="outline">
            Go Back
          </Button>
        }
      />
    );
  }

  if (!isSystemTenant) {
    return (
      <AccessDenied
        description="Language management affects the whole platform and is restricted to the system tenant."
      />
    );
  }

  return (
    <div className="space-y-8">
      <AdminHeader
        title="Languages"
        description="Manage which languages are available across the platform."
        action={
          <Button onClick={() => setIsCreateModalOpen(true)} className="gap-2">
            <IconPlus size={18} />
            Add Language
          </Button>
        }
      />

      <DataTable
        columns={columns}
        data={languages}
        getRowId={(language) => String(language.id)}
        isLoading={isLoading}
        enableGlobalFilter
        globalFilterPlaceholder="Search languages…"
        pagination={{ pageSize: 10 }}
      />

      <CreateLanguageModal
        isOpen={isCreateModalOpen}
        onOpenChange={setIsCreateModalOpen}
        onSuccess={() => {
          setIsCreateModalOpen(false);
          fetchLanguages();
        }}
      />
    </div>
  );
}
