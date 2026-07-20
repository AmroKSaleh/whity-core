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

const AUTH_PROVIDERS_MANAGE = 'auth_providers:manage';

/**
 * Sign-up governance (formerly part of Global Settings' "General"/"Sign-up
 * governance" section stack — WC-235 / WC-2b9d4f6a). Controls whether and how
 * new people can create accounts on this instance. A SYSTEM-TENANT resource:
 * gated on the system tenant (id 0) AND settings:manage, mirroring the
 * backend, which returns 403 for a non-system caller even if they hold
 * settings:manage (a regular tenant's admin does).
 */
export default function SignupSettingsPage() {
  const { addToast } = useToast();
  const { user } = useAuth();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();

  const canManage = hasPermission(SETTINGS_MANAGE);
  const canManageProviders = hasPermission(AUTH_PROVIDERS_MANAGE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  if (isCapabilitiesLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  // Sign-up governance is system-tenant only. A regular tenant's admin holds
  // settings:manage within its own tenant, but must never manage platform-wide
  // defaults — so gate on BOTH the system tenant and the permission.
  if (!isSystemTenant || !canManage) {
    return (
      <AccessDenied
        description={
          <>
            Sign-up governance can only be managed from the system tenant. Your tenant&rsquo;s
            settings are on the{' '}
            <Link href="/admin/settings" className="font-medium underline">
              General
            </Link>{' '}
            page.
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
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title="Sign-up"
        description="Control whether and how new people can create accounts on this instance."
      />
      <SettingsTabs active="signup" showSignup showEmail showStorage showSso={canManageProviders} />
      <SignupSettingsForm addToast={addToast} />
    </div>
  );
}

function SignupSettingsForm({ addToast }: { addToast: AddToast }) {
  const { data, loading, error, refetch } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings/global');
    if (body === undefined) {
      throw new Error(errorMessage(getError, 'Failed to load sign-up settings'));
    }
    return body.data;
  }, []);

  const [draft, setDraft] = useState<Record<string, string>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const global = data?.global as SettingsMap | undefined;
  const registry = useMemo<RegistryEntry[]>(() => data?.registry ?? [], [data]);
  const sections = useMemo(
    () => groupRegistry(registry).filter((s) => s.section.id === 'signup'),
    [registry]
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
        throw new Error(errorMessage(patchError, 'Failed to save sign-up settings'));
      }
      addToast('Sign-up settings saved.', 'success');
      setDraft({});
      refetch();
    } catch (err) {
      addToast(err instanceof Error ? err.message : 'Failed to save sign-up settings', 'error');
    } finally {
      setSaving(false);
    }
  };

  if (loading || !global) {
    return (
      <Card className="border border-border bg-card shadow-sm">
        <CardHeader>
          <CardTitle className="text-lg font-bold font-heading">
            <h2>Sign-up governance</h2>
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {Array.from({ length: 2 }).map((_, i) => (
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
                idPrefix="signup"
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
          data-testid="signup-settings-save"
        >
          <IconDeviceFloppy className="w-4 h-4" />
          {saving ? 'Saving…' : 'Save sign-up settings'}
        </Button>
      </div>
    </div>
  );
}
