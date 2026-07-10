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
import { IconAlertCircle, IconDeviceFloppy, IconMail, IconRocket } from '@tabler/icons-react';
import { BrandingSettings } from '@/components/branding-settings';
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
 * System-wide GLOBAL defaults (WC-235 / WC-2b9d4f6a). These apply to every
 * tenant that has not overridden a value, so they are a SYSTEM-TENANT resource:
 * the page is gated on the system tenant (id 0) AND settings:manage. This mirrors
 * the backend, which returns 403 for a non-system caller even if they hold
 * settings:manage (a regular tenant's admin does) — never present a form the
 * backend will reject. Per-tenant settings live on /admin/settings.
 *
 * The form is REGISTRY-DRIVEN: it renders one control per descriptor the backend
 * publishes, grouped into friendly sections, so governance / SSO / storage keys
 * (and any future key) surface automatically without a frontend change.
 */
export default function GlobalSettingsPage() {
  const { addToast } = useToast();
  const { user } = useAuth();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();

  const canManage = hasPermission(SETTINGS_MANAGE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  if (isCapabilitiesLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  // Global defaults are system-tenant only. A regular tenant's admin holds
  // settings:manage within its own tenant, but must never manage platform-wide
  // defaults — so gate on BOTH the system tenant and the permission.
  if (!isSystemTenant || !canManage) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[450px] p-8 text-center bg-card border border-border rounded-2xl shadow-sm">
        <div className="p-4 bg-destructive/10 rounded-full text-destructive mb-4">
          <IconAlertCircle size={48} />
        </div>
        <h2 className="text-xl font-bold mb-2">Access Denied</h2>
        <p className="text-muted-foreground max-w-md mb-6 text-sm">
          Global (system-wide) defaults can only be managed from the system tenant. Your tenant&rsquo;s
          settings are on the{' '}
          <Link href="/admin/settings" className="font-medium underline">
            Website Settings
          </Link>{' '}
          page.
        </p>
        <Button onClick={() => window.history.back()} variant="outline">
          Go Back
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title="Global Settings"
        description="System-wide defaults applied to every tenant that has not overridden a value. Managed by the system tenant only."
        action={
          <Button asChild variant="outline" className="gap-2">
            <Link href="/onboarding">
              <IconRocket className="w-4 h-4" />
              Setup wizard
            </Link>
          </Button>
        }
      />
      <GlobalSettingsForm addToast={addToast} />
      <Link
        href="/admin/settings/email"
        className="flex items-center gap-3 rounded-lg border border-border bg-muted/40 p-4 text-sm hover:bg-muted/70 transition-colors"
      >
        <span className="p-2 bg-primary/10 rounded-lg text-primary">
          <IconMail className="w-5 h-5" />
        </span>
        <span>
          <span className="font-medium text-foreground">Email (SMTP)</span>
          <span className="block text-muted-foreground">
            Configure outgoing email and send a test message →
          </span>
        </span>
      </Link>
      <BrandingSettings variant="global" />
    </div>
  );
}

function GlobalSettingsForm({ addToast }: { addToast: AddToast }) {
  const { data, loading, error, refetch } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings/global');
    if (body === undefined) {
      throw new Error(errorMessage(getError, 'Failed to load global defaults'));
    }
    return body.data;
  }, []);

  // Draft overlay: only the keys the operator has edited live here, displayed
  // as `draft[key] ?? global[key] ?? registry default`. Only these keys are sent
  // on save, so an untouched (and possibly not-yet-writable) key is never
  // submitted — a partial backend surface can't 422 a value the user didn't set.
  const [draft, setDraft] = useState<Record<string, string>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const global = data?.global as SettingsMap | undefined;
  const registry = useMemo<RegistryEntry[]>(() => data?.registry ?? [], [data]);
  const sections = useMemo(() => groupRegistry(registry), [registry]);
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
    // Clear a stale per-field error the moment the operator edits that field.
    setFieldErrors((prev) => {
      if (!(key in prev)) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const handleSave = async () => {
    if (!global || !dirty) return;

    // Send ONLY edited keys. An empty string clears the key back to its
    // registry default; booleans always send the literal 'true'/'false'.
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
        throw new Error(errorMessage(patchError, 'Failed to save global defaults'));
      }
      addToast('Global defaults saved.', 'success');
      setDraft({});
      refetch();
    } catch (err) {
      addToast(err instanceof Error ? err.message : 'Failed to save global defaults', 'error');
    } finally {
      setSaving(false);
    }
  };

  if (loading || !global) {
    return (
      <Card className="border border-border bg-card shadow-sm">
        <CardHeader>
          <CardTitle className="text-lg font-bold font-heading">
            <h2>Global defaults</h2>
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
                idPrefix="global"
                value={valueOf(entry)}
                error={fieldErrors[entry.key]}
                onChange={(value) => setField(entry.key, value)}
              />
            ))}
          </CardContent>
        </Card>
      ))}

      <div className="sticky bottom-4 flex justify-end">
        <Button
          onClick={handleSave}
          disabled={saving || !dirty}
          className="gap-2 shadow-md"
          data-testid="global-settings-save"
        >
          <IconDeviceFloppy className="w-4 h-4" />
          {saving ? 'Saving…' : 'Save global defaults'}
        </Button>
      </div>
    </div>
  );
}
