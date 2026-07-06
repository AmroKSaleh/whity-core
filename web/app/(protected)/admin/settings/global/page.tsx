'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { IconAlertCircle, IconDeviceFloppy, IconWorld } from '@tabler/icons-react';
import {
  SETTINGS_MANAGE,
  SYSTEM_TENANT_ID,
  SETTING_KEYS,
  FIELD_LABELS,
  validate,
  errorMessage,
  SettingsField,
  type SettingsValueMap,
  type SettingKey,
  type AddToast,
} from '../settings-shared';

/**
 * System-wide GLOBAL defaults (WC-235). These apply to every tenant that has
 * not overridden a value, so they are a SYSTEM-TENANT resource: the page is
 * gated on the system tenant (id 0) AND settings:manage. This mirrors the
 * backend, which returns 403 for a non-system caller even if they hold
 * settings:manage (a regular tenant's admin does) — never present a form the
 * backend will reject. Per-tenant settings live on /admin/settings.
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
      />
      <GlobalSettingsSection addToast={addToast} />
    </div>
  );
}

function GlobalSettingsSection({ addToast }: { addToast: AddToast }) {
  const { data, loading, error, refetch } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings/global');
    if (body === undefined) {
      throw new Error(errorMessage(getError, 'Failed to load global defaults'));
    }
    return body.data;
  }, []);

  // Draft overlay: edited keys only, displayed as `draft[key] ?? global[key]`.
  const [draft, setDraft] = useState<Partial<SettingsValueMap>>({});
  const [saving, setSaving] = useState(false);

  const global = data?.global;

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  const valueOf = (key: SettingKey): string => draft[key] ?? global?.[key] ?? '';

  const setField = (key: SettingKey, value: string) => {
    setDraft((prev) => ({ ...prev, [key]: value }));
  };

  const handleSave = async () => {
    if (!global) return;

    const current: SettingsValueMap = {
      site_name: valueOf('site_name'),
      timezone: valueOf('timezone'),
      locale: valueOf('locale'),
      support_email: valueOf('support_email'),
    };

    const validationError = validate(current, true);
    if (validationError) {
      addToast(validationError, 'error');
      return;
    }

    const settings: Record<string, string | null> = {};
    for (const key of SETTING_KEYS) {
      const value = current[key].trim();
      settings[key] = value === '' ? '' : value;
    }

    setSaving(true);
    try {
      const { error: patchError } = await api.PATCH('/api/v1/settings/global', {
        body: { settings },
      });
      if (patchError) {
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

  return (
    <Card className="border border-border bg-card shadow-sm">
      <CardHeader>
        <div className="flex items-center gap-2">
          <div className="p-2 bg-primary/10 rounded-lg text-primary">
            <IconWorld className="w-5 h-5" />
          </div>
          <div>
            <CardTitle className="text-lg font-bold font-heading">
              <h2>Global defaults</h2>
            </CardTitle>
            <CardDescription className="text-sm">
              Platform-wide defaults applied to every tenant that has not overridden a value.
            </CardDescription>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-6">
        {loading || !global ? (
          <div className="space-y-4">
            {SETTING_KEYS.map((key) => (
              <div key={key} className="h-12 animate-pulse rounded-md bg-muted/40" />
            ))}
          </div>
        ) : (
          <>
            {SETTING_KEYS.map((key) => (
              <SettingsField
                key={key}
                settingKey={key}
                idPrefix="global"
                label={`Global ${FIELD_LABELS[key].toLowerCase()}`}
                value={valueOf(key)}
                disabled={false}
                onChange={(value) => setField(key, value)}
              />
            ))}
            <div className="flex justify-end pt-2">
              <Button onClick={handleSave} disabled={saving} className="gap-2">
                <IconDeviceFloppy className="w-4 h-4" />
                {saving ? 'Saving…' : 'Save global defaults'}
              </Button>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}
