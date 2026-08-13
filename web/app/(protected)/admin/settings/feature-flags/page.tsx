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
  featureFlagEntries,
  errorMessage,
  fieldErrorsFrom,
  type RegistryEntry,
  type SettingsMap,
  type AddToast,
} from '../settings-shared';

/**
 * Feature Flags — a generic admin surface over the registry's curated
 * capability toggles (see `SettingsRegistry::FEATURE_FLAG_KEYS` /
 * `isFeatureFlag()` server-side). Deliberately narrow: this reuses the
 * EXISTING SettingsRegistry-backed boolean settings only — no new storage, no
 * per-tenant overrides, no plugin-facing declaration system. A broader
 * platform-level feature-flags registry (dedicated table, plugin SDK
 * contribution point, percentage rollouts, ...) is tracked separately as
 * GitHub issue #326 ("Platform-level feature-flags registry", `post-launch`)
 * and is explicitly out of scope here.
 *
 * The flag SET is NOT hardcoded on the client: every registry entry carrying
 * `isFlag: true` renders here automatically ({@link featureFlagEntries}), so a
 * future flag needs only one line added to FEATURE_FLAG_KEYS server-side —
 * zero changes anywhere on the frontend.
 *
 * A SYSTEM-TENANT resource (the underlying settings are global-only, like
 * Sign-up/Email/Storage): gated on the system tenant (id 0) AND
 * settings:manage, mirroring the backend, which returns 403 for a non-system
 * caller even if they hold settings:manage (a regular tenant's admin does).
 */
export default function FeatureFlagsSettingsPage() {
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
      'settings.featureFlags.accessDenied',
      'Feature flags can only be managed from the system tenant. Your tenant’s settings are on the {link} page.'
    ).split('{link}');

    return (
      <AccessDenied
        description={
          <>
            {beforeLink}
            <Link href="/admin/settings" className="font-medium underline">
              {t('settings.featureFlags.accessDenied.link', 'General')}
            </Link>
            {afterLink}
          </>
        }
        action={
          <Button onClick={() => window.history.back()} variant="outline">
            {t('settings.featureFlags.goBack', 'Go Back')}
          </Button>
        }
      />
    );
  }

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title={t('settings.featureFlags.title', 'Feature flags')}
        description={t(
          'settings.featureFlags.description',
          'Turn platform-wide capabilities on or off for this instance.'
        )}
      />
      <SettingsTabs active="feature-flags" />
      <FeatureFlagsForm addToast={addToast} />
    </div>
  );
}

function FeatureFlagsForm({ addToast }: { addToast: AddToast }) {
  const t = useTranslation('admin');

  const { data, loading, error, refetch } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings/global');
    if (body === undefined) {
      throw new Error(
        errorMessage(getError, t('settings.featureFlags.error.load', 'Failed to load feature flags'))
      );
    }
    return body.data;
  }, []);

  const [draft, setDraft] = useState<Record<string, string>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const global = data?.global as SettingsMap | undefined;
  const registry = useMemo<RegistryEntry[]>(() => data?.registry ?? [], [data]);
  const flags = useMemo(() => featureFlagEntries(registry), [registry]);
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
          errorMessage(patchError, t('settings.featureFlags.error.save', 'Failed to save feature flags'))
        );
      }
      addToast(t('settings.featureFlags.saved', 'Feature flags saved.'), 'success');
      setDraft({});
      refetch();
    } catch (err) {
      addToast(
        err instanceof Error
          ? err.message
          : t('settings.featureFlags.error.save', 'Failed to save feature flags'),
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
            <h2>{t('settings.featureFlags.card.title', 'Feature flags')}</h2>
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
      <Card
        data-testid="settings-section-feature-flags"
        className="border border-border bg-card shadow-sm"
      >
        <CardHeader>
          <CardTitle className="text-lg font-bold font-heading">
            <h2>{t('settings.featureFlags.card.title', 'Feature flags')}</h2>
          </CardTitle>
          <CardDescription className="text-sm">
            {t(
              'settings.featureFlags.card.description',
              'Capability toggles for this instance. Per-tenant overrides and plugin-declared flags are not supported here (tracked separately).'
            )}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-5">
          {flags.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {t('settings.featureFlags.empty', 'No feature flags are published by this instance.')}
            </p>
          ) : (
            flags.map((entry) => (
              <RegistrySettingControl
                key={entry.key}
                entry={entry}
                idPrefix="feature-flags"
                value={valueOf(entry)}
                error={fieldErrors[entry.key]}
                onChange={(value) => setField(entry.key, value)}
              />
            ))
          )}
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <Button
          onClick={handleSave}
          disabled={saving || !dirty || flags.length === 0}
          className="gap-2"
          data-testid="feature-flags-settings-save"
        >
          <IconDeviceFloppy className="w-4 h-4" />
          {saving
            ? t('settings.featureFlags.saving', 'Saving…')
            : t('settings.featureFlags.save', 'Save feature flags')}
        </Button>
      </div>
    </div>
  );
}
