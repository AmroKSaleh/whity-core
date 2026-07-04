'use client';

import { useEffect, useMemo, useState } from 'react';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';
import { useToast } from '@/lib/toast-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Input } from '@amroksaleh/ui/input';
import { IconAlertCircle, IconDeviceFloppy, IconInfoCircle, IconWorld } from '@tabler/icons-react';
import { BrandingSettings } from '@/components/branding-settings';

// Granular RBAC for the Website Settings console (mirrors the backend catalogue):
//   read   → may view the effective/editable set (else Access Denied)
//   write  → may edit the CURRENT tenant's overrides (else read-only)
//   manage → may edit the GLOBAL platform defaults (else the section is hidden)
const SETTINGS_READ = 'settings:read';
const SETTINGS_WRITE = 'settings:write';
const SETTINGS_MANAGE = 'settings:manage';

type SettingsValueMap = components['schemas']['SettingsValueMap'];

// The four known keys, kept in display order. The registry is authoritative on
// the backend; this list only drives field order/labels on the client.
type SettingKey = keyof SettingsValueMap;
const SETTING_KEYS: readonly SettingKey[] = ['site_name', 'timezone', 'locale', 'support_email'];

const FIELD_LABELS: Record<SettingKey, string> = {
  site_name: 'Site name',
  timezone: 'Timezone',
  locale: 'Locale',
  support_email: 'Support email',
};

// A reasonable short set of UI locales. The backend validates against a
// BCP-47-ish pattern, so unknown values still round-trip via server validation;
// this just covers the common cases as a friendly dropdown.
const LOCALE_OPTIONS: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'en', label: 'English (en)' },
  { value: 'en-US', label: 'English — United States (en-US)' },
  { value: 'en-GB', label: 'English — United Kingdom (en-GB)' },
  { value: 'de', label: 'German (de)' },
  { value: 'fr', label: 'French (fr)' },
  { value: 'es', label: 'Spanish (es)' },
  { value: 'ar', label: 'Arabic (ar)' },
];

/** IANA timezone identifiers, with a guarded fallback for older runtimes. */
function timezoneOptions(): string[] {
  const supported = (Intl as unknown as { supportedValuesOf?: (key: string) => string[] })
    .supportedValuesOf;
  if (typeof supported === 'function') {
    try {
      return supported('timeZone');
    } catch {
      // Fall through to the static fallback below.
    }
  }
  return ['UTC', 'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Los_Angeles', 'Asia/Tokyo'];
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Client-side validation mirroring the registry's intent (server stays
 * authoritative and will 422 the rest). Returns an error message, or null when
 * the values are acceptable to send.
 *
 * `requireSiteName` is true for the GLOBAL defaults form, where an empty
 * site_name is meaningless (the registry default is non-empty). For the TENANT
 * form an empty value is legitimate: it CLEARS the override so the key falls
 * back to the global/default, so emptiness is not an error there.
 */
function validate(values: SettingsValueMap, requireSiteName: boolean): string | null {
  if (requireSiteName && values.site_name.trim() === '') {
    return 'Site name cannot be empty.';
  }
  if (values.support_email.trim() !== '' && !EMAIL_RE.test(values.support_email.trim())) {
    return 'Support email must be a valid email address (or left blank).';
  }
  return null;
}

/**
 * Narrow a `details` envelope to a `Record<string, string>` (per-field messages
 * keyed by setting key), discarding any non-string entries. Returns null when
 * the value is not a usable details object.
 */
function fieldDetails(value: unknown): Record<string, string> | null {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }
  const details: Record<string, string> = {};
  for (const [key, message] of Object.entries(value as Record<string, unknown>)) {
    if (typeof message === 'string' && message !== '') {
      details[key] = message;
    }
  }
  return Object.keys(details).length > 0 ? details : null;
}

/**
 * Extract a human-friendly message from a failed client call.
 *
 * The uniform error envelope is `{ error, details? }`. When a 422 carries a
 * `details` object of per-field messages (e.g. `{ site_name: "..." }`), surface
 * those — joining multiple — so the toast is the actionable field guidance, not
 * the generic top-level "Validation failed". Falls back to the top-level
 * `error` string, then to the provided fallback when neither is present.
 */
function errorMessage(error: unknown, fallback: string): string {
  if (error && typeof error === 'object') {
    if ('details' in error) {
      const details = fieldDetails((error as { details?: unknown }).details);
      if (details) {
        return Object.values(details).join(' ');
      }
    }
    if ('error' in error) {
      const value = (error as { error?: unknown }).error;
      if (typeof value === 'string' && value !== '') {
        return value;
      }
    }
  }
  return fallback;
}

interface SettingsFieldProps {
  settingKey: SettingKey;
  idPrefix: string;
  label: string;
  value: string;
  disabled: boolean;
  onChange: (value: string) => void;
  /** Optional inherited/overridden indicator (tenant form only). */
  status?: 'overridden' | 'inherited';
}

/**
 * A single label-associated form control. timezone/locale render as native
 * <select> elements (a stable IANA/locale list); everything else is an input.
 */
function SettingsField({
  settingKey,
  idPrefix,
  label,
  value,
  disabled,
  onChange,
  status,
}: SettingsFieldProps) {
  const id = `${idPrefix}-${settingKey}`;

  return (
    <div className="space-y-1.5">
      <div className="flex items-center justify-between gap-2">
        <label htmlFor={id} className="text-sm font-medium text-foreground">
          {label}
        </label>
        {status && (
          <Badge
            data-testid={`status-${settingKey}`}
            variant={status === 'overridden' ? 'default' : 'secondary'}
            className="text-[10px] font-medium capitalize"
          >
            {status}
          </Badge>
        )}
      </div>

      {settingKey === 'timezone' ? (
        <select
          id={id}
          value={value}
          disabled={disabled}
          onChange={(e) => onChange(e.target.value)}
          className="h-7 w-full min-w-0 rounded-md border border-input bg-input/20 px-2 text-sm transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {timezoneOptions().map((tz) => (
            <option key={tz} value={tz}>
              {tz}
            </option>
          ))}
        </select>
      ) : settingKey === 'locale' ? (
        <select
          id={id}
          value={value}
          disabled={disabled}
          onChange={(e) => onChange(e.target.value)}
          className="h-7 w-full min-w-0 rounded-md border border-input bg-input/20 px-2 text-sm transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {/* Keep the current value selectable even if it is outside the short list. */}
          {!LOCALE_OPTIONS.some((o) => o.value === value) && value !== '' && (
            <option value={value}>{value}</option>
          )}
          {LOCALE_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      ) : (
        <Input
          id={id}
          type={settingKey === 'support_email' ? 'email' : 'text'}
          value={value}
          disabled={disabled}
          onChange={(e) => onChange(e.target.value)}
        />
      )}
    </div>
  );
}

export default function AdminSettingsPage() {
  const { addToast } = useToast();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();

  const canRead = hasPermission(SETTINGS_READ);
  const canWrite = hasPermission(SETTINGS_WRITE);
  const canManage = hasPermission(SETTINGS_MANAGE);

  // Fetch tenant_overridable at the page level so it can be passed to
  // BrandingSettings. Must be called unconditionally (Rules of Hooks), before
  // any early return. The browser dedupes this against TenantSettingsSection's
  // identical call within the same render cycle.
  const { data: settingsMeta } = useFetch(async () => {
    const { data: body } = await api.GET('/api/v1/settings');
    return body?.data ?? null;
  }, []);

  // Default to true so the branding section shows the editable form while
  // loading — same defensive default used in TenantSettingsSection.
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
        description="Platform-wide defaults that each tenant can override. Editing requires the settings:write (tenant) or settings:manage (global) permission."
      />
      <TenantSettingsSection canWrite={canWrite} addToast={addToast} />
      <BrandingSettings tenantOverridable={tenantOverridable} />
      {canManage && <GlobalSettingsSection addToast={addToast} />}
    </div>
  );
}

type AddToast = ReturnType<typeof useToast>['addToast'];

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

  // Draft overlay: only the keys the user has edited live in state. The
  // displayed value is `draft[key] ?? fetched[key]`, so the form never needs to
  // be SEEDED from the fetch via an effect (which would trip
  // react-hooks/set-state-in-effect). A successful save clears the draft and
  // refetches, snapping the form back to the authoritative effective values.
  const [draft, setDraft] = useState<Partial<SettingsValueMap>>({});
  const [saving, setSaving] = useState(false);

  const effective = data?.effective;
  const overridden = useMemo(() => new Set(data?.overridden ?? []), [data]);
  // WC-224: the server reports whether THIS tenant has a per-tenant override
  // layer. The system tenant (0) has globals only, so a per-tenant write always
  // 422s — when false we hide the editable form entirely and point at Global
  // defaults. Default to true so a loading/legacy payload keeps the prior form.
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
          // would always 422 — so we never show the editable fields or Save. The
          // notice routes the user to the Global defaults form below instead.
          <div
            data-testid="tenant-no-override-notice"
            role="note"
            className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-4 text-sm text-muted-foreground"
          >
            <IconInfoCircle className="mt-0.5 h-5 w-5 shrink-0 text-primary" aria-hidden="true" />
            <p>
              As the system tenant, you have no per-tenant overrides. Edit the platform-wide values
              in <strong className="font-medium text-foreground">Global defaults</strong> below.
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

function GlobalSettingsSection({ addToast }: { addToast: AddToast }) {
  const { data, loading, error, refetch } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings/global');
    if (body === undefined) {
      throw new Error(errorMessage(getError, 'Failed to load global defaults'));
    }
    return body.data;
  }, []);

  // Draft overlay (see TenantSettingsSection): edited keys only, displayed as
  // `draft[key] ?? global[key]`, so the form is never seeded via an effect.
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
              Platform-wide defaults applied to every tenant that has not overridden a value
              (settings:manage).
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
