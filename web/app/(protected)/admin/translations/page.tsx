'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
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
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { AccessDenied } from '@amroksaleh/ui/access-denied';
import { IconPlus, IconRefresh } from '@tabler/icons-react';
import { useI18nAvailability, useTranslation } from '@amroksaleh/features/i18n';
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
 *
 * This screen is also the WORKFLOW for everything the extraction pipeline
 * produces. `whity-cli i18n:sync` seeds English and nothing else — deliberately,
 * because a machine-translated or English-copied row is indistinguishable from a
 * finished one — so every other language arrives here as a gap, and the person
 * who closes it is a translator, not an engineer. Hence the coverage panel:
 * before it existed, a list of rows could only show work already DONE, and a
 * language looked most complete exactly when nobody had started it.
 *
 * REACHABLE EVEN WHEN i18n IS SWITCHED OFF (`i18n.enabled`), for the same reason
 * as the Languages page — and with more force, given the paragraph above: doing
 * this work BEFORE the operator turns the feature on is exactly what the flag is
 * for. Every edit here is persisted for real. It reads the PUBLIC language list
 * rather than the admin one deliberately — this page is open to any tenant
 * holding `translations:manage`, while the admin listing is system-tenant-only —
 * which is also why that endpoint keeps serving the whole catalogue with the
 * flag off instead of narrowing to one language.
 */
const SYSTEM_TENANT_ID = 0;
const DEFAULT_DOMAIN = 'common';

export default function TranslationsPage() {
  const { user } = useAuth();
  const { addToast } = useToast();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();
  const t = useTranslation('admin');
  // Three-valued: the notice below ASSERTS the feature is off, so it must wait
  // for a real answer rather than treating "not loaded yet" as "off".
  const i18n = useI18nAvailability();

  const canManage = hasPermission(TRANSLATIONS_MANAGE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  const [languageCode, setLanguageCode] = useState('en');
  const [domain, setDomain] = useState(DEFAULT_DOMAIN);
  const [untranslatedOnly, setUntranslatedOnly] = useState(false);
  const [appliedFilter, setAppliedFilter] = useState({
    languageCode: 'en',
    domain: DEFAULT_DOMAIN,
    untranslatedOnly: false,
  });
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);

  const { data: coverage, refetch: refetchCoverage } = useFetch(async () => {
    const { data: body, error } = await api.GET('/api/v1/translations/coverage');
    if (body === undefined) {
      throw new Error(errorMessage(error, t('translations.coverage.error', 'Failed to load translation coverage')));
    }
    return body.data;
  }, []);

  const {
    data: rows,
    loading: isLoading,
    error,
    refetch,
  } = useFetch(async () => {
    const { data: body, error } = await api.GET('/api/v1/translations', {
      params: {
        query: {
          language_code: appliedFilter.languageCode,
          domain: appliedFilter.domain,
          ...(appliedFilter.untranslatedOnly ? { untranslated: '1' } : {}),
        },
      },
    });
    if (body === undefined) {
      throw new Error(errorMessage(error, t('translations.error.load', 'Failed to load translations')));
    }
    return body.data;
  }, [appliedFilter]);

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  const languages = useMemo(() => coverage?.languages ?? [], [coverage]);
  const selectedCoverage = useMemo(
    () => languages.find((language) => language.language_code === languageCode),
    [languages, languageCode]
  );
  // Shown only once we KNOW the selected language is not the source language —
  // there is nothing to translate English from, and a column of em-dashes while
  // coverage is still loading is worse than no column.
  const showSourceColumn = coverage != null && languageCode !== coverage.source_language_code;
  const columnCount = showSourceColumn ? 4 : 3;

  const handleLoad = (nextDomain = domain, nextUntranslatedOnly = untranslatedOnly) => {
    if (languageCode.trim() === '' || nextDomain.trim() === '') {
      addToast(
        t('translations.filter.required', 'Choose a language and enter a domain first.'),
        'error'
      );
      return;
    }
    setDomain(nextDomain);
    setUntranslatedOnly(nextUntranslatedOnly);
    setAppliedFilter({
      languageCode,
      domain: nextDomain.trim(),
      untranslatedOnly: nextUntranslatedOnly,
    });
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
        throw new Error(errorMessage(error, t('translations.error.save', 'Failed to save translation')));
      }
      addToast(t('translations.saved', 'Translation saved.'), 'success');
      refetch();
      refetchCoverage();
    },
    [appliedFilter, addToast, refetch, refetchCoverage, t]
  );

  const handleDelete = useCallback(
    async (id: number, label: string) => {
      const { error } = await api.DELETE('/api/v1/translations/{id}', {
        params: { path: { id } },
      });
      if (error) {
        addToast(
          errorMessage(error, t('translations.delete.failed', 'Failed to delete the {label}', { label })),
          'error'
        );
        return;
      }
      addToast(t('translations.deleted', '{label} deleted.', { label }), 'success');
      refetch();
      refetchCoverage();
    },
    [addToast, refetch, refetchCoverage, t]
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
        description={t(
          'translations.accessDenied',
          'You do not have the required permission ({permission}) to view Translations.',
          { permission: TRANSLATIONS_MANAGE }
        )}
        action={
          <Button onClick={() => window.history.back()} variant="outline">
            {t('translations.goBack', 'Go Back')}
          </Button>
        }
      />
    );
  }

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('translations.title', 'Translations')}
        description={
          isSystemTenant
            ? t(
                'translations.description.system',
                'Edit the system-default translation strings shared by every tenant.'
              )
            : t(
                'translations.description.tenant',
                "Edit your tenant's translation overrides. System defaults are shown alongside for reference."
              )
        }
        action={
          <Button onClick={() => setIsAddModalOpen(true)} className="gap-2">
            <IconPlus size={18} />
            {t('translations.addKey', 'Add Key')}
          </Button>
        }
      />

      {i18n === 'disabled' && (
        <Alert variant="info" data-testid="i18n-disabled-notice">
          <AlertDescription>
            {t(
              'translations.i18nDisabled',
              'Multiple languages are switched off for this instance, so everyone currently sees the default language. Translations edited here are saved and take effect as soon as an operator turns the feature on under Feature Flags.'
            )}
          </AlertDescription>
        </Alert>
      )}

      <section className="space-y-3 rounded-lg border border-border p-4">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="text-sm font-semibold">
            {t('translations.coverage.title', 'Translation coverage')}
          </h2>
          <p className="text-xs text-muted-foreground">
            {t(
              'translations.coverage.hint',
              'English comes from the code itself. Every other language is filled in here.'
            )}
          </p>
        </div>

        <div className="flex flex-wrap gap-2">
          {languages.map((language) => {
            const isSource = language.language_code === coverage?.source_language_code;
            return (
              <button
                key={language.language_code}
                type="button"
                onClick={() => setLanguageCode(language.language_code)}
                className={`rounded-md border px-3 py-2 text-start text-sm transition-colors ${
                  language.language_code === languageCode
                    ? 'border-primary bg-primary/5'
                    : 'border-border hover:bg-muted/40'
                }`}
              >
                <span className="font-medium">
                  {language.name} ({language.language_code})
                </span>
                <span className="ms-2 text-xs text-muted-foreground">
                  {t('translations.coverage.summary', '{translated} of {total} translated', {
                    translated: language.translated,
                    total: language.total,
                  })}
                </span>
                {isSource ? (
                  <Badge variant="secondary" className="ms-2 text-[10px]">
                    {t('translations.coverage.sourceLanguage', 'Source language')}
                  </Badge>
                ) : language.missing > 0 ? (
                  <Badge variant="destructive" className="ms-2 text-[10px]">
                    {t('translations.coverage.missing', '{count} missing', { count: language.missing })}
                  </Badge>
                ) : (
                  <Badge variant="secondary" className="ms-2 text-[10px]">
                    {t('translations.coverage.complete', 'Complete')}
                  </Badge>
                )}
              </button>
            );
          })}
        </div>

        {selectedCoverage && selectedCoverage.domains.length > 0 && (
          <ul className="divide-y divide-border rounded-md border border-border">
            {selectedCoverage.domains.map((domainCoverage) => (
              <li key={domainCoverage.domain}>
                <button
                  type="button"
                  className="flex w-full items-center justify-between gap-3 p-2.5 text-start text-sm hover:bg-muted/40"
                  onClick={() => handleLoad(domainCoverage.domain, domainCoverage.missing > 0)}
                >
                  <span className="font-mono text-xs">{domainCoverage.domain}</span>
                  <span className="flex items-center gap-2 text-xs text-muted-foreground">
                    {t('translations.coverage.summary', '{translated} of {total} translated', {
                      translated: domainCoverage.translated,
                      total: domainCoverage.total,
                    })}
                    {domainCoverage.missing > 0 && (
                      <Badge variant="destructive" className="text-[10px]">
                        {t('translations.coverage.missing', '{count} missing', {
                          count: domainCoverage.missing,
                        })}
                      </Badge>
                    )}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

      <div className="flex flex-wrap items-end gap-3 rounded-lg border border-border bg-muted/20 p-4">
        <div className="space-y-1.5">
          <label className="text-sm font-medium text-foreground">
            {t('translations.filter.language', 'Language')}
          </label>
          <Select value={languageCode} onValueChange={setLanguageCode}>
            <SelectTrigger className="w-44">
              <SelectValue placeholder={t('translations.filter.languagePlaceholder', 'Select a language')} />
            </SelectTrigger>
            <SelectContent>
              {languages.map((language) => (
                <SelectItem key={language.language_code} value={language.language_code}>
                  {language.name} ({language.language_code})
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <label className="text-sm font-medium text-foreground" htmlFor="translation-domain">
            {t('translations.filter.domain', 'Domain')}
          </label>
          <Input
            id="translation-domain"
            list="translation-domains"
            value={domain}
            onChange={(e) => setDomain(e.target.value)}
            placeholder={t('translations.filter.domainPlaceholder', 'e.g. common')}
            className="w-48"
          />
          <datalist id="translation-domains">
            {(selectedCoverage?.domains ?? []).map((domainCoverage) => (
              <option key={domainCoverage.domain} value={domainCoverage.domain} />
            ))}
          </datalist>
        </div>
        <label className="flex items-center gap-2 pb-2 text-sm font-medium text-foreground">
          <input
            type="checkbox"
            className="size-4"
            checked={untranslatedOnly}
            onChange={(e) => setUntranslatedOnly(e.target.checked)}
          />
          {t('translations.filter.untranslatedOnly', 'Only untranslated')}
        </label>
        <Button onClick={() => handleLoad()} variant="outline" className="gap-2">
          <IconRefresh size={16} />
          {t('translations.filter.load', 'Load')}
        </Button>
      </div>

      <div className="overflow-x-auto rounded-lg border border-border">
        <table className="w-full text-sm">
          <thead className="bg-muted/40">
            <tr>
              <th className="p-3 text-start font-medium">{t('translations.column.key', 'Key')}</th>
              {showSourceColumn && (
                <th className="p-3 text-start font-medium">
                  {t('translations.column.source', 'English source')}
                </th>
              )}
              <th className="p-3 text-start font-medium">
                {t('translations.column.systemDefault', 'System default')}
                {isSystemTenant && (
                  <Badge variant="secondary" className="ms-2 text-[10px]">
                    {t('translations.badge.editable', 'editable')}
                  </Badge>
                )}
              </th>
              <th className="p-3 text-start font-medium">
                {t('translations.column.tenantOverride', 'Tenant override')}
                {!isSystemTenant && (
                  <Badge variant="secondary" className="ms-2 text-[10px]">
                    {t('translations.badge.editable', 'editable')}
                  </Badge>
                )}
              </th>
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <tr>
                <td colSpan={columnCount} className="p-6 text-center text-muted-foreground">
                  {t('translations.loading', 'Loading…')}
                </td>
              </tr>
            ) : (rows ?? []).length === 0 ? (
              <tr>
                <td colSpan={columnCount} className="p-6 text-center text-muted-foreground">
                  {appliedFilter.untranslatedOnly
                    ? t(
                        'translations.empty.untranslated',
                        'Nothing left untranslated in this domain.'
                      )
                    : t(
                        'translations.empty',
                        'No keys found for this language and domain yet. Use "Add Key" to create one.'
                      )}
                </td>
              </tr>
            ) : (
              (rows ?? []).map((row) => (
                <tr key={row.key} className="border-t border-border align-top">
                  <td className="p-3 font-mono text-xs">{row.key}</td>
                  {showSourceColumn && (
                    <td className="p-3 text-sm text-muted-foreground">{row.source_text ?? '—'}</td>
                  )}
                  <td className="p-3">
                    <EditableTranslationCell
                      value={row.system_default?.translation ?? null}
                      editable={isSystemTenant}
                      placeholder={t('translations.cell.noSystemDefault', 'No system default yet')}
                      onSave={(value) => handleSaveCell(row, row.system_default?.id, value)}
                      onDelete={
                        isSystemTenant && row.system_default
                          ? () =>
                              handleDelete(
                                row.system_default!.id,
                                t('translations.label.systemDefault', 'system default')
                              )
                          : undefined
                      }
                    />
                  </td>
                  <td className="p-3">
                    {isSystemTenant ? (
                      <span className="text-sm text-muted-foreground">
                        {t(
                          'translations.systemTenantNoOverride',
                          'The system tenant has no per-tenant override layer.'
                        )}
                      </span>
                    ) : (
                      <EditableTranslationCell
                        value={row.tenant_override?.translation ?? null}
                        editable={!isSystemTenant}
                        placeholder={t(
                          'translations.cell.noOverride',
                          'No override — using the system default'
                        )}
                        onSave={(value) => handleSaveCell(row, row.tenant_override?.id, value)}
                        onDelete={
                          row.tenant_override
                            ? () =>
                                handleDelete(
                                  row.tenant_override!.id,
                                  t('translations.label.override', 'override')
                                )
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
          refetchCoverage();
        }}
      />
    </div>
  );
}
