'use client';

import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { AccessDenied } from '@amroksaleh/ui/access-denied';
import { IconDeviceFloppy } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { SettingsTabs } from '../settings-tabs';
import {
  SETTINGS_MANAGE,
  SYSTEM_TENANT_ID,
  RegistrySettingControl,
  groupRegistry,
  errorMessage,
  fieldErrorsFrom,
  type RegistryEntry,
  type SettingsMap,
  type AddToast,
} from '../settings-shared';

/**
 * Storage — where uploaded files and assets are kept (formerly Global
 * Settings' "Storage" section). A SYSTEM-TENANT resource: gated on the
 * system tenant (id 0) AND settings:manage, mirroring the backend.
 */
export default function StorageSettingsPage() {
  const { addToast } = useToast();
  const { user } = useAuth();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();
  const t = useTranslation('admin');

  const canManage = hasPermission(SETTINGS_MANAGE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  if (isCapabilitiesLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!isSystemTenant || !canManage) {
    // ONE translatable sentence with a {link} hole, split at render time so the
    // General link stays a link without fragmenting the sentence for translators.
    const [beforeLink, afterLink] = t(
      'settings.storage.accessDenied',
      'Storage configuration can only be managed from the system tenant. Your tenant’s settings are on the {link} page.'
    ).split('{link}');

    return (
      <AccessDenied
        description={
          <>
            {beforeLink}
            <Link href="/admin/settings" className="font-medium underline">
              {t('settings.storage.accessDenied.link', 'General')}
            </Link>
            {afterLink}
          </>
        }
        action={
          <Button onClick={() => window.history.back()} variant="outline">
            {t('settings.storage.goBack', 'Go Back')}
          </Button>
        }
      />
    );
  }

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title={t('settings.storage.title', 'Storage')}
        description={t(
          'settings.storage.description',
          'Where uploaded files and assets are kept for this instance.'
        )}
      />
      <SettingsTabs active="storage" />
      <StorageSettingsForm addToast={addToast} />
    </div>
  );
}

function StorageSettingsForm({ addToast }: { addToast: AddToast }) {
  const t = useTranslation('admin');
  const { data, loading, error, refetch } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings/global');
    if (body === undefined) {
      throw new Error(
        errorMessage(getError, t('settings.storage.loadFailed', 'Failed to load storage settings'))
      );
    }
    return body.data;
  }, []);

  const [draft, setDraft] = useState<Record<string, string>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const global = data?.global as SettingsMap | undefined;
  const registry = useMemo<RegistryEntry[]>(() => data?.registry ?? [], [data]);
  const sections = useMemo(
    () => groupRegistry(registry, t).filter((s) => s.section.id === 'storage'),
    [registry, t]
  );
  const dirty = Object.keys(draft).length > 0;

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  const valueOf = (entry: RegistryEntry): string =>
    draft[entry.key] ?? global?.[entry.key] ?? entry.default;

  const setField = (key: string, value: string) => {
    setDraft((prev) => ({ ...prev, [key]: value }));
    setFieldErrors((prev) => {
      if (!(key in prev)) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const handleSave = async () => {
    if (!global || !dirty) return;

    const settings: Record<string, string> = {};
    for (const key of Object.keys(draft)) {
      settings[key] = draft[key].trim();
    }

    setSaving(true);
    setFieldErrors({});
    try {
      const { error: patchError } = await api.PATCH('/api/v1/settings/global', {
        body: { settings },
      });
      if (patchError) {
        setFieldErrors(fieldErrorsFrom(patchError));
        throw new Error(
          errorMessage(patchError, t('settings.storage.saveFailed', 'Failed to save storage settings'))
        );
      }
      addToast(t('settings.storage.saved', 'Storage settings saved.'), 'success');
      setDraft({});
      refetch();
    } catch (err) {
      addToast(
        err instanceof Error
          ? err.message
          : t('settings.storage.saveFailed', 'Failed to save storage settings'),
        'error'
      );
    } finally {
      setSaving(false);
    }
  };

  if (loading || !global) {
    return (
      <Card className="border border-border bg-card shadow-sm">
        <CardHeader>
          <CardTitle className="text-lg font-bold font-heading">
            <h2>{t('settings.storage.form.title', 'Storage')}</h2>
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-12 animate-pulse rounded-md bg-muted/40" />
          ))}
        </CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      {sections.map(({ section, entries }) => (
        <Card
          key={section.id}
          data-testid={`settings-section-${section.id}`}
          className="border border-border bg-card shadow-sm"
        >
          <CardHeader>
            <CardTitle className="text-lg font-bold font-heading">
              <h2>{section.title}</h2>
            </CardTitle>
            {section.description && (
              <CardDescription className="text-sm">{section.description}</CardDescription>
            )}
          </CardHeader>
          <CardContent className="space-y-5">
            {entries.map((entry) => (
              <RegistrySettingControl
                key={entry.key}
                entry={entry}
                idPrefix="storage"
                value={valueOf(entry)}
                error={fieldErrors[entry.key]}
                onChange={(value) => setField(entry.key, value)}
              />
            ))}
          </CardContent>
        </Card>
      ))}

      <div className="flex justify-end">
        <Button
          onClick={handleSave}
          disabled={saving || !dirty}
          className="gap-2"
          data-testid="storage-settings-save"
        >
          <IconDeviceFloppy className="w-4 h-4" />
          {saving
            ? t('settings.storage.saving', 'Saving…')
            : t('settings.storage.save', 'Save storage settings')}
        </Button>
      </div>
    </div>
  );
}
