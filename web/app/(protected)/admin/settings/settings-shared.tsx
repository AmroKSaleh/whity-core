'use client';

/**
 * Shared primitives for the Website Settings console (WC-235). Split across two
 * pages after the global-settings privilege fix:
 *   - /admin/settings         → the caller tenant's overrides (settings:write)
 *   - /admin/settings/global  → platform-wide defaults, SYSTEM TENANT ONLY
 *
 * The global page is gated client-side on the system tenant (id 0) to mirror the
 * backend, which rejects a non-system caller with 403 even if they hold
 * settings:manage (a regular tenant's admin does). Never present the global form
 * to a caller the backend will reject.
 */
import type { components } from '@/lib/api/schema';
import type { useToast } from '@/lib/toast-context';
import { Badge } from '@amroksaleh/ui/badge';
import { Input } from '@amroksaleh/ui/input';

// Granular RBAC for the Website Settings console (mirrors the backend catalogue):
//   read   → may view the effective/editable set (else Access Denied)
//   write  → may edit the CURRENT tenant's overrides (else read-only)
//   manage → may edit the GLOBAL platform defaults — AND only from the system tenant
export const SETTINGS_READ = 'settings:read';
export const SETTINGS_WRITE = 'settings:write';
export const SETTINGS_MANAGE = 'settings:manage';

/** The system tenant (id 0) is the only tenant that may manage global defaults. */
export const SYSTEM_TENANT_ID = 0;

export type SettingsValueMap = components['schemas']['SettingsValueMap'];
export type SettingKey = keyof SettingsValueMap;
export type AddToast = ReturnType<typeof useToast>['addToast'];

// The known keys, kept in display order. The registry is authoritative on the
// backend; this list only drives field order/labels on the client.
export const SETTING_KEYS: readonly SettingKey[] = ['site_name', 'timezone', 'locale', 'support_email'];

export const FIELD_LABELS: Record<SettingKey, string> = {
  site_name: 'Site name',
  timezone: 'Timezone',
  locale: 'Locale',
  support_email: 'Support email',
};

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
 * authoritative and will 422 the rest). Returns an error message, or null.
 *
 * `requireSiteName` is true for the GLOBAL defaults form, where an empty
 * site_name is meaningless. For the TENANT form an empty value CLEARS the
 * override (falls back to global/default), so emptiness is not an error there.
 */
export function validate(values: SettingsValueMap, requireSiteName: boolean): string | null {
  if (requireSiteName && values.site_name.trim() === '') {
    return 'Site name cannot be empty.';
  }
  if (values.support_email.trim() !== '' && !EMAIL_RE.test(values.support_email.trim())) {
    return 'Support email must be a valid email address (or left blank).';
  }
  return null;
}

/** Narrow a `details` envelope to per-field messages, discarding non-strings. */
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
 * Extract a human-friendly message from a failed client call. Prefers per-field
 * `details` messages, then the top-level `error` string, then the fallback.
 */
export function errorMessage(error: unknown, fallback: string): string {
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
export function SettingsField({
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
