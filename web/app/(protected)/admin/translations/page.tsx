'use client';

import { useCallback, useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { TRANSLATIONS_MANAGE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
import { Badge } from '@amroksaleh/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { AccessDenied } from '@amroksaleh/ui/access-denied';
import { IconPlus, IconRefresh } from '@tabler/icons-react';
import { EditableTranslationCell } from './editable-cell';
import { AddKeyModal } from './add-key-modal';
import { errorMessage } from '../languages/shared';
import type { TranslationAdminRow } from './types';

/**
 * Unlike Languages, translation rows ARE tenant-scoped — every tenant holding
 * `translations:manage` may reach this page, but which COLUMN is editable
 * depends on the caller: the system tenant (id 0) edits only the
 * system-default column, a regular tenant edits only its own tenant-override
 * column (WC-583, mirroring the base-roles / global-settings asymmetry).
 */
const SYSTEM_TENANT_ID = 0;
const DEFAULT_DOMAIN = 'common';

export default function TranslationsPage() {
  const { user } = useAuth();
  const { addToast } = useToast();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();

  const canManage = hasPermission(TRANSLATIONS_MANAGE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  const [languageCode, setLanguageCode] = useState('en');
  const [domain, setDomain] = useState(DEFAULT_DOMAIN);
  const [appliedFilter, setAppliedFilter] = useState({ languageCode: 'en', domain: DEFAULT_DOMAIN });
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);

  const { data: languageOptions } = useFetch(async () => {
    const { data: body } = await api.GET('/api/v1/languages');
    return body?.languages ?? [];
  }, []);

  const {
    data: rows,
    loading: isLoading,
    error,
    refetch,
  } = useFetch(async () => {
    const { data: body, error } = await api.GET('/api/v1/translations', {
      params: { query: { language_code: appliedFilter.languageCode, domain: appliedFilter.domain } },
    });
    if (body === undefined) {
      throw new Error(errorMessage(error, 'Failed to load translations'));
    }
    return body.data;
  }, [appliedFilter]);

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  const handleLoad = () => {
    if (languageCode.trim() === '' || domain.trim() === '') {
      addToast('Choose a language and enter a domain first.', 'error');
      return;
    }
    setAppliedFilter({ languageCode, domain: domain.trim() });
  };

  const handleSaveCell = useCallback(
    async (row: TranslationAdminRow, existingId: number | undefined, value: string) => {
      const { error } = existingId
        ? await api.PATCH('/api/v1/translations/{id}', {
            params: { path: { id: existingId } },
            body: { translation: value },
          })
        : await api.POST('/api/v1/translations', {
            body: {
              language_code: appliedFilter.languageCode,
              domain: appliedFilter.domain,
              key: row.key,
              translation: value,
            },
          });
      if (error) {
        throw new Error(errorMessage(error, 'Failed to save translation'));
      }
      addToast('Translation saved.', 'success');
      refetch();
    },
    [appliedFilter, addToast, refetch]
  );

  const handleDelete = useCallback(
    async (id: number, label: string) => {
      const { error } = await api.DELETE('/api/v1/translations/{id}', {
        params: { path: { id } },
      });
      if (error) {
        addToast(errorMessage(error, `Failed to delete the ${label}`), 'error');
        return;
      }
      addToast(`${label} deleted.`, 'success');
      refetch();
    },
    [addToast, refetch]
  );

  if (isCapabilitiesLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!canManage) {
    return (
      <AccessDenied
        description={
          <>
            You do not have the required permission (<code>translations:manage</code>) to view
            Translations.
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

  return (
    <div className="space-y-8">
      <AdminHeader
        title="Translations"
        description={
          isSystemTenant
            ? 'Edit the system-default translation strings shared by every tenant.'
            : "Edit your tenant's translation overrides. System defaults are shown alongside for reference."
        }
        action={
          <Button onClick={() => setIsAddModalOpen(true)} className="gap-2">
            <IconPlus size={18} />
            Add Key
          </Button>
        }
      />

      <div className="flex flex-wrap items-end gap-3 rounded-lg border border-border bg-muted/20 p-4">
        <div className="space-y-1.5">
          <label className="text-sm font-medium text-foreground">Language</label>
          <Select value={languageCode} onValueChange={setLanguageCode}>
            <SelectTrigger className="w-44">
              <SelectValue placeholder="Select a language" />
            </SelectTrigger>
            <SelectContent>
              {(languageOptions ?? []).map((lang) => (
                <SelectItem key={lang.code} value={lang.code}>
                  {lang.name} ({lang.code})
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <label className="text-sm font-medium text-foreground">Domain</label>
          <Input
            value={domain}
            onChange={(e) => setDomain(e.target.value)}
            placeholder="e.g. common"
            className="w-48"
          />
        </div>
        <Button onClick={handleLoad} variant="outline" className="gap-2">
          <IconRefresh size={16} />
          Load
        </Button>
      </div>

      <div className="overflow-x-auto rounded-lg border border-border">
        <table className="w-full text-sm">
          <thead className="bg-muted/40">
            <tr>
              <th className="p-3 text-start font-medium">Key</th>
              <th className="p-3 text-start font-medium">
                System default
                {isSystemTenant && (
                  <Badge variant="secondary" className="ms-2 text-[10px]">
                    editable
                  </Badge>
                )}
              </th>
              <th className="p-3 text-start font-medium">
                Tenant override
                {!isSystemTenant && (
                  <Badge variant="secondary" className="ms-2 text-[10px]">
                    editable
                  </Badge>
                )}
              </th>
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <tr>
                <td colSpan={3} className="p-6 text-center text-muted-foreground">
                  Loading…
                </td>
              </tr>
            ) : (rows ?? []).length === 0 ? (
              <tr>
                <td colSpan={3} className="p-6 text-center text-muted-foreground">
                  No keys found for this language and domain yet. Use &quot;Add Key&quot; to
                  create one.
                </td>
              </tr>
            ) : (
              (rows ?? []).map((row) => (
                <tr key={row.key} className="border-t border-border align-top">
                  <td className="p-3 font-mono text-xs">{row.key}</td>
                  <td className="p-3">
                    <EditableTranslationCell
                      value={row.system_default?.translation ?? null}
                      editable={isSystemTenant}
                      placeholder="No system default yet"
                      onSave={(value) => handleSaveCell(row, row.system_default?.id, value)}
                      onDelete={
                        isSystemTenant && row.system_default
                          ? () => handleDelete(row.system_default!.id, 'system default')
                          : undefined
                      }
                    />
                  </td>
                  <td className="p-3">
                    {isSystemTenant ? (
                      <span className="text-sm text-muted-foreground">
                        The system tenant has no per-tenant override layer.
                      </span>
                    ) : (
                      <EditableTranslationCell
                        value={row.tenant_override?.translation ?? null}
                        editable={!isSystemTenant}
                        placeholder="No override — using the system default"
                        onSave={(value) => handleSaveCell(row, row.tenant_override?.id, value)}
                        onDelete={
                          row.tenant_override
                            ? () => handleDelete(row.tenant_override!.id, 'override')
                            : undefined
                        }
                      />
                    )}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <AddKeyModal
        isOpen={isAddModalOpen}
        onOpenChange={setIsAddModalOpen}
        languageCode={appliedFilter.languageCode}
        domain={appliedFilter.domain}
        onSuccess={() => {
          setIsAddModalOpen(false);
          refetch();
        }}
      />
    </div>
  );
}
