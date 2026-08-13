'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { LANGUAGES_MANAGE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Switch } from '@amroksaleh/ui/switch';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { AccessDenied } from '@amroksaleh/ui/access-denied';
import {
  useI18nAvailability,
  useRichTranslation,
  useTranslation,
} from '@amroksaleh/features/i18n';
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
 *
 * REACHABLE EVEN WHEN i18n IS SWITCHED OFF (`i18n.enabled`). Preparing the
 * languages an instance will offer BEFORE turning the feature on is the whole
 * reason the flag exists; hiding this page while it is off would make the
 * feature impossible to get ready. Everything here still writes for real — a
 * language added now is live the moment the flag flips — so there is no silent
 * no-op. The banner below says so plainly, because an operator who cannot see
 * their change take effect anywhere in the product deserves to know why.
 */
const SYSTEM_TENANT_ID = 0;

export default function LanguagesPage() {
  const { user } = useAuth();
  const { addToast } = useToast();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();
  const t = useTranslation('admin');
  const rt = useRichTranslation('admin');
  // Three-valued: the notice below ASSERTS the feature is off, so it must wait
  // for a real answer rather than treating "not loaded yet" as "off".
  const i18n = useI18nAvailability();

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
      throw new Error(errorMessage(error, t('languages.error.load', 'Failed to fetch languages')));
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
        throw new Error(errorMessage(error, t('languages.error.update', 'Failed to update language')));
      }
      addToast(
        enabled
          ? t('languages.toggle.enabled', '{name} enabled.', { name: language.name })
          : t('languages.toggle.disabled', '{name} disabled.', { name: language.name }),
        'success'
      );
      fetchLanguages();
    } catch (err) {
      addToast(
        err instanceof Error ? err.message : t('languages.error.update', 'Failed to update language'),
        'error'
      );
    } finally {
      setTogglingId(null);
    }
  };

  /**
   * Direction is a property of the LANGUAGE, so it is edited here alongside the
   * name — never as a per-user toggle. Changing it re-mirrors the interface for
   * everyone who has selected that language; nothing infers it from the code.
   */
  const handleDirectionChange = async (language: Language, direction: 'ltr' | 'rtl') => {
    setTogglingId(language.id);
    try {
      const { error } = await api.PATCH('/api/v1/languages/{id}', {
        params: { path: { id: language.id } },
        body: { direction },
      });
      if (error) {
        throw new Error(errorMessage(error, t('languages.error.update', 'Failed to update language')));
      }
      addToast(
        direction === 'rtl'
          ? t('languages.direction.rtlToast', '{name} now reads right to left.', {
              name: language.name,
            })
          : t('languages.direction.ltrToast', '{name} now reads left to right.', {
              name: language.name,
            }),
        'success'
      );
      fetchLanguages();
    } catch (err) {
      addToast(
        err instanceof Error ? err.message : t('languages.error.update', 'Failed to update language'),
        'error'
      );
    } finally {
      setTogglingId(null);
    }
  };

  const columns: DataTableColumn<Language>[] = [
    {
      accessorKey: 'code',
      header: t('languages.table.code', 'Code'),
      enableSorting: true,
      enableColumnFilter: true,
    },
    {
      accessorKey: 'name',
      header: t('languages.table.name', 'Name'),
      enableSorting: true,
      enableColumnFilter: true,
    },
    {
      id: 'direction',
      header: t('languages.table.direction', 'Direction'),
      enableSorting: true,
      cell: (language) => (
        <select
          value={language.direction}
          disabled={togglingId === language.id}
          onChange={(e) =>
            void handleDirectionChange(language, e.target.value as 'ltr' | 'rtl')
          }
          aria-label={t('languages.table.directionLabel', 'Writing direction for {name}', {
            name: language.name,
          })}
          className="h-8 rounded-md border border-input bg-input/20 px-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
        >
          <option value="ltr">{t('languages.direction.ltr', 'Left to right')}</option>
          <option value="rtl">{t('languages.direction.rtl', 'Right to left')}</option>
        </select>
      ),
    },
    {
      id: 'enabled',
      header: t('languages.table.enabled', 'Enabled'),
      enableSorting: true,
      cell: (language) => (
        <Switch
          checked={language.enabled}
          disabled={togglingId === language.id}
          onCheckedChange={(checked) => void handleToggle(language, checked)}
          aria-label={t('languages.table.toggleLabel', 'Toggle {name}', { name: language.name })}
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
        // ONE sentence with the permission slug rendered as <code> inside it —
        // rich rather than split, so a translator can move the slug where their
        // grammar wants it.
        description={rt(
          'languages.accessDenied.permission',
          'You do not have the required permission (<0>{permission}</0>) to view Languages.',
          { permission: LANGUAGES_MANAGE },
          [<code key="permission" />]
        )}
        action={
          <Button onClick={() => window.history.back()} variant="outline">
            {t('languages.goBack', 'Go Back')}
          </Button>
        }
      />
    );
  }

  if (!isSystemTenant) {
    return (
      <AccessDenied
        description={t(
          'languages.accessDenied.systemTenant',
          'Language management affects the whole platform and is restricted to the system tenant.'
        )}
      />
    );
  }

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('languages.title', 'Languages')}
        description={t(
          'languages.description',
          'Manage which languages are available across the platform. Each language carries its own writing direction — the interface mirrors automatically for right-to-left languages.'
        )}
        action={
          <Button onClick={() => setIsCreateModalOpen(true)} className="gap-2">
            <IconPlus size={18} />
            {t('languages.addButton', 'Add Language')}
          </Button>
        }
      />

      {i18n === 'disabled' && (
        <Alert variant="info" data-testid="i18n-disabled-notice">
          <AlertDescription>
            {t(
              'languages.i18nDisabled',
              'Multiple languages are switched off for this instance, so everyone currently sees ' +
                'the default language, left to right, with no language control. Changes made here are ' +
                'saved and take effect as soon as an operator turns the feature on under Feature ' +
                'Flags — nothing set up now is lost in the meantime.'
            )}
          </AlertDescription>
        </Alert>
      )}

      <DataTable
        columns={columns}
        data={languages}
        getRowId={(language) => String(language.id)}
        isLoading={isLoading}
        enableGlobalFilter
        globalFilterPlaceholder={t('languages.searchPlaceholder', 'Search languages…')}
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
