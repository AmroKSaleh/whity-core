'use client';

import { useEffect, useMemo, useState } from 'react';
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
import { SettingsTabs } from './settings-tabs';
import {
  SETTINGS_READ,
  SETTINGS_WRITE,
  SETTINGS_MANAGE,
  SECURITY_MANAGE,
  SYSTEM_TENANT_ID,
  GENERAL_SETTING_KEYS,
  FIELD_LABELS,
  RegistrySettingControl,
  groupRegistry,
  validate,
  errorMessage,
  fieldErrorsFrom,
  SettingsField,
  type RegistryEntry,
  type SettingsMap,
  type SettingKey,
  type AddToast,
} from './settings-shared';

const AUTH_PROVIDERS_MANAGE = 'auth_providers:manage';

/**
 * General — the CURRENT tenant's overrides (site name, timezone, support
 * email). Locale + logos/colors live on the Branding tab; SSO, Sign-up,
 * Email, and Storage each have their own system-tenant-gated tab.
 */
export default function AdminSettingsPage() {
  const { addToast } = useToast();
  const { user } = useAuth();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();

  const canRead = hasPermission(SETTINGS_READ);
  const canWrite = hasPermission(SETTINGS_WRITE);
  const canManageGlobal = hasPermission(SETTINGS_MANAGE);
  const canManageProviders = hasPermission(AUTH_PROVIDERS_MANAGE);
  const canManageSecurity = hasPermission(SECURITY_MANAGE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

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
            You do not have the required permission (<code>settings:read</code>) to view
            Website Settings.
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
        title="General"
        description="Your tenant's instance identity. Cleared fields fall back to the platform-wide global default. Editing requires the settings:write permission."
      />
      <SettingsTabs
        active="general"
        showSignup={isSystemTenant}
        showEmail={isSystemTenant}
        showStorage={isSystemTenant}
        showSso={canManageProviders}
        showSecurity={canManageSecurity}
      />
      <TenantSettingsSection canWrite={canWrite} addToast={addToast} />
      {isSystemTenant && canManageGlobal && <PlatformDefaultsSection addToast={addToast} />}
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
  const [draft, setDraft] = useState<Partial<Record<(typeof GENERAL_SETTING_KEYS)[number], string>>>({});
  const [saving, setSaving] = useState(false);

  const effective = data?.effective;
  const overridden = useMemo(() => new Set(data?.overridden ?? []), [data]);
  // WC-224: the system tenant (0) has globals only, so a per-tenant write always
  // 422s — when false we hide the editable form and point at Platform defaults.
  const tenantOverridable = data?.tenant_overridable ?? true;

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  const valueOf = (key: SettingKey): string => draft[key as keyof typeof draft] ?? effective?.[key] ?? '';

  const setField = (key: SettingKey, value: string) => {
    setDraft((prev) => ({ ...prev, [key]: value }));
  };

  const handleSave = async () => {
    if (!effective) return;

    const current = {
      site_name: valueOf('site_name'),
      timezone: valueOf('timezone'),
      support_email: valueOf('support_email'),
    };

    const validationError = validate(current, false);
    if (validationError) {
      addToast(validationError, 'error');
      return;
    }

    // An empty value clears the override (falls back to global/default).
    const settings: Record<string, string | null> = {};
    for (const key of GENERAL_SETTING_KEYS) {
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
            {GENERAL_SETTING_KEYS.map((key) => (
              <div key={key} className="h-12 animate-pulse rounded-md bg-muted/40" />
            ))}
          </div>
        ) : !tenantOverridable ? (
          // WC-224: the system tenant has no per-tenant override layer — a write
          // would always 422 — so we never show the editable fields. The Platform
          // defaults card below (rendered on this same page) is where they edit.
          <div
            data-testid="tenant-no-override-notice"
            role="note"
            className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-4 text-sm text-muted-foreground"
          >
            <p>As the system tenant, you have no per-tenant overrides. Edit the platform-wide values below.</p>
          </div>
        ) : (
          <>
            {GENERAL_SETTING_KEYS.map((key) => (
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

/**
 * Platform defaults — the system tenant's instance-wide fallback values
 * (site name / timezone / support email / locale) plus the MCP integration
 * toggle. These used to live on the standalone Global Settings page's
 * "General"/"Integrations" sections; folded in here so a system-tenant admin
 * sees their own tenant fields and the platform-wide fallback right next to
 * each other instead of on a separate route.
 */
function PlatformDefaultsSection({ addToast }: { addToast: AddToast }) {
  const { data, loading, error, refetch } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings/global');
    if (body === undefined) {
      throw new Error(errorMessage(getError, 'Failed to load global defaults'));
    }
    return body.data;
  }, []);

  const [draft, setDraft] = useState<Record<string, string>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const global = data?.global as SettingsMap | undefined;
  const registry = useMemo<RegistryEntry[]>(() => data?.registry ?? [], [data]);
  const sections = useMemo(
    () => groupRegistry(registry).filter((s) => s.section.id === 'general' || s.section.id === 'integrations'),
    [registry]
  );
  const dirty = Object.keys(draft).length > 0;

  useEffect(() => {
    if (error) addToast(error, 'error');
  }, [error, addToast]);

  const valueOf = (entry: RegistryEntry): string => draft[entry.key] ?? global?.[entry.key] ?? entry.default;

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
      const { error: patchError } = await api.PATCH('/api/v1/settings/global', { body: { settings } });
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
            <h2>Platform defaults</h2>
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
              <h2>{section.id === 'general' ? 'Platform defaults' : section.title}</h2>
            </CardTitle>
            <CardDescription className="text-sm">
              {section.id === 'general'
                ? 'Instance-wide fallback values applied to every tenant that has not overridden them.'
                : section.description}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-5">
            {entries.map((entry) => (
              <RegistrySettingControl
                key={entry.key}
                entry={entry}
                idPrefix="platform"
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
          data-testid="platform-defaults-save"
        >
          <IconDeviceFloppy className="w-4 h-4" />
          {saving ? 'Saving…' : 'Save platform defaults'}
        </Button>
      </div>
    </div>
  );
}
