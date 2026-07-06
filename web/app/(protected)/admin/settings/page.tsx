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
import { IconAlertCircle, IconDeviceFloppy, IconInfoCircle, IconWorld } from '@tabler/icons-react';
import { BrandingSettings } from '@/components/branding-settings';
import {
  SETTINGS_READ,
  SETTINGS_WRITE,
  SYSTEM_TENANT_ID,
  SETTING_KEYS,
  FIELD_LABELS,
  validate,
  errorMessage,
  SettingsField,
  type SettingsValueMap,
  type SettingKey,
  type AddToast,
} from './settings-shared';

/**
 * Website (tenant) settings — the CURRENT tenant's overrides + branding. The
 * platform-wide GLOBAL defaults live on a separate page (/admin/settings/global)
 * restricted to the system tenant, so a regular tenant's admin can never reach
 * them (WC-235 privilege fix; the backend also rejects them with 403).
 */
export default function AdminSettingsPage() {
  const { addToast } = useToast();
  const { user } = useAuth();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();

  const canRead = hasPermission(SETTINGS_READ);
  const canWrite = hasPermission(SETTINGS_WRITE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  // Fetch tenant_overridable at the page level so it can be passed to
  // BrandingSettings. Must be called unconditionally (Rules of Hooks).
  const { data: settingsMeta } = useFetch(async () => {
    const { data: body } = await api.GET('/api/v1/settings');
    return body?.data ?? null;
  }, []);
  const tenantOverridable = settingsMeta?.tenant_overridable ?? true;

  if (isCapabilitiesLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!canRead) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[450px] p-8 text-center bg-card border border-border rounded-2xl shadow-sm">
        <div className="p-4 bg-destructive/10 rounded-full text-destructive mb-4">
          <IconAlertCircle size={48} />
        </div>
        <h2 className="text-xl font-bold mb-2">Access Denied</h2>
        <p className="text-muted-foreground max-w-md mb-6 text-sm">
          You do not have the required permission (<code>settings:read</code>) to view Website
          Settings.
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
        title="Website Settings"
        description="Your tenant's settings and branding. Cleared fields fall back to the platform-wide global default. Editing requires the settings:write permission."
      />
      {isSystemTenant && (
        <Link
          href="/admin/settings/global"
          className="flex items-center gap-3 rounded-lg border border-border bg-muted/40 p-4 text-sm hover:bg-muted/70 transition-colors"
        >
          <span className="p-2 bg-primary/10 rounded-lg text-primary">
            <IconWorld className="w-5 h-5" />
          </span>
          <span>
            <span className="font-medium text-foreground">Global defaults (system-wide)</span>
            <span className="block text-muted-foreground">
              Manage the platform-wide defaults applied to every tenant →
            </span>
          </span>
        </Link>
      )}
      <TenantSettingsSection canWrite={canWrite} addToast={addToast} />
      <BrandingSettings tenantOverridable={tenantOverridable} />
    </div>
  );
}

function TenantSettingsSection({
  canWrite,
  addToast,
}: {
  canWrite: boolean;
  addToast: AddToast;
}) {
  const { data, loading, error, refetch } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings');
    if (body === undefined) {
      throw new Error(errorMessage(getError, 'Failed to load settings'));
    }
    return body.data;
  }, []);

  // Draft overlay: only edited keys live in state; displayed as
  // `draft[key] ?? fetched[key]`, so the form is never seeded via an effect.
  const [draft, setDraft] = useState<Partial<SettingsValueMap>>({});
  const [saving, setSaving] = useState(false);

  const effective = data?.effective;
  const overridden = useMemo(() => new Set(data?.overridden ?? []), [data]);
  // WC-224: the system tenant (0) has globals only, so a per-tenant write always
  // 422s — when false we hide the editable form and point at the Global page.
  const tenantOverridable = data?.tenant_overridable ?? true;

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  const valueOf = (key: SettingKey): string => draft[key] ?? effective?.[key] ?? '';

  const setField = (key: SettingKey, value: string) => {
    setDraft((prev) => ({ ...prev, [key]: value }));
  };

  const handleSave = async () => {
    if (!effective) return;

    const current: SettingsValueMap = {
      site_name: valueOf('site_name'),
      timezone: valueOf('timezone'),
      locale: valueOf('locale'),
      support_email: valueOf('support_email'),
    };

    const validationError = validate(current, false);
    if (validationError) {
      addToast(validationError, 'error');
      return;
    }

    // An empty value clears the override (falls back to global/default).
    const settings: Record<string, string | null> = {};
    for (const key of SETTING_KEYS) {
      const value = current[key].trim();
      settings[key] = value === '' ? '' : value;
    }

    setSaving(true);
    try {
      const { error: patchError } = await api.PATCH('/api/v1/settings', {
        body: { settings },
      });
      if (patchError) {
        throw new Error(errorMessage(patchError, 'Failed to save settings'));
      }
      addToast('Tenant settings saved.', 'success');
      setDraft({});
      refetch();
    } catch (err) {
      addToast(err instanceof Error ? err.message : 'Failed to save settings', 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card className="border border-border bg-card shadow-sm">
      <CardHeader>
        <CardTitle className="text-lg font-bold font-heading">
          <h2>Tenant settings</h2>
        </CardTitle>
        <CardDescription className="text-sm">
          Overrides for your current tenant. Cleared fields fall back to the global default.
          {effective && tenantOverridable && !canWrite &&
            ' You have read-only access (settings:write required to edit).'}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        {loading || !effective ? (
          <div className="space-y-4">
            {SETTING_KEYS.map((key) => (
              <div key={key} className="h-12 animate-pulse rounded-md bg-muted/40" />
            ))}
          </div>
        ) : !tenantOverridable ? (
          // WC-224: the system tenant has no per-tenant override layer — a write
          // would always 422 — so we never show the editable fields. Route the
          // user to the Global defaults page instead.
          <div
            data-testid="tenant-no-override-notice"
            role="note"
            className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-4 text-sm text-muted-foreground"
          >
            <IconInfoCircle className="mt-0.5 h-5 w-5 shrink-0 text-primary" aria-hidden="true" />
            <p>
              As the system tenant, you have no per-tenant overrides. Edit the platform-wide values
              on the{' '}
              <Link href="/admin/settings/global" className="font-medium text-foreground underline">
                Global defaults
              </Link>{' '}
              page.
            </p>
          </div>
        ) : (
          <>
            {SETTING_KEYS.map((key) => (
              <SettingsField
                key={key}
                settingKey={key}
                idPrefix="tenant"
                label={FIELD_LABELS[key]}
                value={valueOf(key)}
                disabled={!canWrite}
                onChange={(value) => setField(key, value)}
                status={overridden.has(key) ? 'overridden' : 'inherited'}
              />
            ))}
            {canWrite && (
              <div className="flex justify-end pt-2">
                <Button onClick={handleSave} disabled={saving} className="gap-2">
                  <IconDeviceFloppy className="w-4 h-4" />
                  {saving ? 'Saving…' : 'Save tenant settings'}
                </Button>
              </div>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}
