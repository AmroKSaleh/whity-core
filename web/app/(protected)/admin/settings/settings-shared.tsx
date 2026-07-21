'use client';

/**
 * Shared primitives for the Website Settings console (WC-235 / WC-2b9d4f6a).
 * Split across two pages after the global-settings privilege fix:
 *   - /admin/settings         → the caller tenant's overrides (settings:write)
 *   - /admin/settings/global  → platform-wide defaults, SYSTEM TENANT ONLY
 *
 * The global page is gated client-side on the system tenant (id 0) to mirror the
 * backend, which rejects a non-system caller with 403 even if they hold
 * settings:manage (a regular tenant's admin does). Never present the global form
 * to a caller the backend will reject.
 *
 * Both surfaces are REGISTRY-DRIVEN: the backend publishes a `registry`
 * descriptor list ({ key, type, default }) alongside the values, and the client
 * renders one control per descriptor grouped into friendly sections. A key the
 * client does not recognise still renders (labelled from its key, as a text
 * input for an unknown type), so new backend settings surface automatically
 * without a frontend change.
 */
import * as React from 'react';
import type { components } from '@/lib/api/schema';
import type { useToast } from '@/lib/toast-context';
import { Badge } from '@amroksaleh/ui/badge';
import { Input } from '@amroksaleh/ui/input';
import { Switch } from '@amroksaleh/ui/switch';

// Granular RBAC for the Website Settings console (mirrors the backend catalogue):
//   read   → may view the effective/editable set (else Access Denied)
//   write  → may edit the CURRENT tenant's overrides (else read-only)
//   manage → may edit the GLOBAL platform defaults — AND only from the system tenant
export const SETTINGS_READ = 'settings:read';
export const SETTINGS_WRITE = 'settings:write';
export const SETTINGS_MANAGE = 'settings:manage';

/** Admin-enforced 2FA policy CRUD + status (WC-525), tenant-scoped self-service. */
export const SECURITY_MANAGE = 'security:manage';

/** The system tenant (id 0) is the only tenant that may manage global defaults. */
export const SYSTEM_TENANT_ID = 0;

export type SettingsValueMap = components['schemas']['SettingsValueMap'];
export type SettingKey = keyof SettingsValueMap;
export type AddToast = ReturnType<typeof useToast>['addToast'];

/**
 * One registry descriptor as published by the backend settings endpoints.
 * `options` accompanies `type:"enum"` (the allowed values); it is optional so a
 * backend that has not yet added enum support degrades to a text input.
 */
export type RegistryEntry = components['schemas']['SettingsRegistryEntry'] & {
  options?: string[];
};

/**
 * The value map is registry-driven and open-ended: beyond the four typed
 * SettingsValueMap fields the backend adds governance / SSO / storage keys over
 * time, so we treat the returned map as a plain string record on the client.
 */
export type SettingsMap = Record<string, string>;

/** The General tab's tenant-overridable key set. */
export type GeneralSettingKey = 'site_name' | 'timezone' | 'support_email';

// The General tab's tenant-overridable keys, kept in display order. `locale`
// lives on the Branding tab instead (grouped with logos/colors as the
// instance's "identity & language" surface) — see branding/page.tsx.
export const GENERAL_SETTING_KEYS: readonly GeneralSettingKey[] = ['site_name', 'timezone', 'support_email'];

export const FIELD_LABELS: Record<SettingKey, string> = {
  site_name: 'Site name',
  timezone: 'Timezone',
  locale: 'Locale',
  support_email: 'Support email',
};

// ---------------------------------------------------------------------------
// Field + section metadata (friendly labels / help text / grouping).
// ---------------------------------------------------------------------------

export interface FieldMeta {
  label: string;
  help?: string;
}

/**
 * Human-facing label + help for known keys. A key absent here still renders,
 * labelled from a humanised form of its key (see {@link fieldMetaFor}).
 */
export const FIELD_META: Record<string, FieldMeta> = {
  site_name: {
    label: 'Site name',
    help: 'The public name of this instance, shown in the browser title and on the sign-in screen.',
  },
  timezone: {
    label: 'Default timezone',
    help: 'Applied for tenants that have not chosen their own timezone.',
  },
  locale: {
    label: 'Default locale',
    help: 'Default interface language for tenants that have not overridden it.',
  },
  support_email: {
    label: 'Support email',
    help: 'Shown to users who need help. Leave blank to hide it.',
  },
  'mcp.enabled': {
    label: 'Model Context Protocol (MCP) endpoint',
    help: 'Expose the MCP tool endpoint so connected AI clients can call this instance.',
  },
  'auth.self_registration_enabled': {
    label: 'Public sign-up',
    help: 'Let anyone create an account from the public registration page. Off by default — an operator-provisioned instance opens sign-up explicitly.',
  },
  'auth.registration_approval_required': {
    label: 'Require admin approval',
    help: 'When sign-up is open, hold each new account as pending until an administrator approves it.',
  },
  'auth.sso_enabled': {
    label: 'Single sign-on (SSO)',
    help: 'Master switch for federated sign-in. When off, every configured identity provider is disabled instance-wide.',
  },
  'storage.driver': {
    label: 'Storage driver',
    help: 'Where uploaded files are stored: local disk, or an S3-compatible object store.',
  },
  'storage.s3.endpoint': {
    label: 'S3 endpoint',
    help: 'Base URL of the S3-compatible service.',
  },
  'storage.s3.region': { label: 'S3 region' },
  'storage.s3.bucket': { label: 'S3 bucket' },
  'storage.s3.access_key': {
    label: 'S3 access key',
    help: 'The matching secret key is supplied via the deployment environment and is never stored here.',
  },
  'storage.s3.path_style': {
    label: 'S3 path-style addressing',
    help: 'Use path-style bucket URLs (required by most self-hosted S3 gateways).',
  },
  'storage.s3.public_base_url': {
    label: 'S3 public base URL',
    help: 'Public base URL used to serve stored assets, when it differs from the endpoint.',
  },
  // Outgoing email (managed on the dedicated Email settings page).
  'mail.transport': {
    label: 'Transport',
    help: 'How outgoing email is delivered.',
  },
  'mail.smtp.host': { label: 'SMTP host' },
  'mail.smtp.port': { label: 'SMTP port', help: '587 = STARTTLS · 465 = SSL · 25 / 1025 = none' },
  'mail.smtp.encryption': { label: 'Encryption' },
  'mail.smtp.username': { label: 'SMTP username', help: 'Optional — leave blank for unauthenticated relays.' },
  'mail.from_address': { label: 'From address', help: 'The address recipients see messages come from.' },
  'mail.from_name': { label: 'From name', help: 'The display name shown alongside the from address.' },
  'mail.events.welcome_enabled': {
    label: 'Welcome email',
    help: 'Send a welcome message when a new account is created.',
  },
  'mail.events.approval_enabled': {
    label: 'Approval decision email',
    help: 'Notify a registrant when their account is approved or rejected.',
  },
  'mail.events.invitation_enabled': {
    label: 'Workspace invitation email',
    help: 'Email people when they are invited to a workspace.',
  },
};

export interface SectionDef {
  id: string;
  title: string;
  description?: string;
  /** Keys that belong to this section, in display order. */
  keys: readonly string[];
}

/**
 * Ordered sections for the global settings surface. New sections (Email, Rate
 * limits, …) are added here as their keys land; until then any unclaimed key
 * still renders under "Other settings" so nothing is silently hidden.
 */
export const SETTINGS_SECTIONS: readonly SectionDef[] = [
  {
    id: 'general',
    title: 'General',
    description: 'Instance identity and defaults inherited by every tenant.',
    keys: ['site_name', 'support_email', 'timezone', 'locale'],
  },
  {
    id: 'signup',
    title: 'Sign-up governance',
    description: 'Control whether and how new people can create accounts on this instance.',
    keys: ['auth.self_registration_enabled', 'auth.registration_approval_required'],
  },
  {
    id: 'signin',
    title: 'Sign-in & SSO',
    description: 'Federated sign-in across the whole instance.',
    keys: ['auth.sso_enabled'],
  },
  {
    id: 'integrations',
    title: 'Integrations',
    description: 'Machine-facing endpoints and connected tooling.',
    keys: ['mcp.enabled'],
  },
  {
    id: 'storage',
    title: 'Storage',
    description: 'Where uploaded files and assets are kept.',
    keys: [
      'storage.driver',
      'storage.s3.endpoint',
      'storage.s3.region',
      'storage.s3.bucket',
      'storage.s3.access_key',
      'storage.s3.path_style',
      'storage.s3.public_base_url',
    ],
  },
];

/** Catch-all for registry keys not claimed by a named section above. */
const OTHER_SECTION: SectionDef = {
  id: 'other',
  title: 'Other settings',
  description: 'Additional settings published by this instance.',
  keys: [],
};

export interface RegistrySection {
  section: SectionDef;
  entries: RegistryEntry[];
}

/**
 * Bucket registry descriptors into ordered sections. A section with no present
 * keys is dropped; any key not claimed by a named section is appended under
 * "Other settings" so a newly-added backend key always appears somewhere.
 */
export function groupRegistry(registry: readonly RegistryEntry[]): RegistrySection[] {
  const byKey = new Map(registry.map((entry) => [entry.key, entry]));
  const claimed = new Set<string>();
  const sections: RegistrySection[] = [];

  for (const section of SETTINGS_SECTIONS) {
    const entries: RegistryEntry[] = [];
    for (const key of section.keys) {
      const entry = byKey.get(key);
      if (entry) {
        entries.push(entry);
        claimed.add(key);
      }
    }
    if (entries.length > 0) {
      sections.push({ section, entries });
    }
  }

  // `mail.*` keys are managed on the dedicated Email settings page (transport-
  // conditional layout + write-only password + test-send), so they are excluded
  // from this generic form rather than dumped into "Other settings".
  const leftover = registry.filter(
    (entry) => !claimed.has(entry.key) && !entry.key.startsWith('mail.')
  );
  if (leftover.length > 0) {
    sections.push({ section: OTHER_SECTION, entries: leftover });
  }

  return sections;
}

/** Turn a raw key (`storage.s3.public_base_url`) into a readable label. */
export function humanizeKey(key: string): string {
  const tail = key.includes('.') ? key.slice(key.lastIndexOf('.') + 1) : key;
  return tail
    .replace(/[_.]+/g, ' ')
    .trim()
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

/** The label + help for a key, falling back to a humanised label. */
export function fieldMetaFor(key: string): FieldMeta {
  return FIELD_META[key] ?? { label: humanizeKey(key) };
}

/** A boolean setting is the literal string 'true'. */
export function isTruthyFlag(value: string): boolean {
  return value === 'true';
}

/** Common protocol acronyms shown uppercased in enum dropdowns. */
const ENUM_ACRONYMS = new Set(['smtp', 'tls', 'ssl', 's3', 'oidc', 'mcp']);

/** Human-facing label for an enum option value (e.g. "smtp" → "SMTP"). */
export function enumOptionLabel(option: string): string {
  const lower = option.toLowerCase();
  if (ENUM_ACRONYMS.has(lower)) return option.toUpperCase();
  return lower.charAt(0).toUpperCase() + lower.slice(1);
}

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

const NATIVE_SELECT_CLASS =
  'h-7 w-full min-w-0 rounded-md border border-input bg-input/20 px-2 text-sm transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-2 aria-invalid:ring-destructive/20';

interface NativeSelectProps {
  id: string;
  value: string;
  disabled: boolean;
  invalid?: boolean;
  describedBy?: string;
  onChange: (value: string) => void;
}

/** Native <select> of IANA timezone identifiers. */
function TimezoneSelect({ id, value, disabled, invalid, describedBy, onChange }: NativeSelectProps) {
  return (
    <select
      id={id}
      value={value}
      disabled={disabled}
      aria-invalid={invalid || undefined}
      aria-describedby={describedBy}
      onChange={(e) => onChange(e.target.value)}
      className={NATIVE_SELECT_CLASS}
    >
      {timezoneOptions().map((tz) => (
        <option key={tz} value={tz}>
          {tz}
        </option>
      ))}
    </select>
  );
}

/** Native <select> of short locale codes; keeps an out-of-list value selectable. */
function LocaleSelect({ id, value, disabled, invalid, describedBy, onChange }: NativeSelectProps) {
  return (
    <select
      id={id}
      value={value}
      disabled={disabled}
      aria-invalid={invalid || undefined}
      aria-describedby={describedBy}
      onChange={(e) => onChange(e.target.value)}
      className={NATIVE_SELECT_CLASS}
    >
      {!LOCALE_OPTIONS.some((o) => o.value === value) && value !== '' && (
        <option value={value}>{value}</option>
      )}
      {LOCALE_OPTIONS.map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </select>
  );
}

/**
 * Client-side validation mirroring the registry's intent (server stays
 * authoritative and will 422 the rest). Returns an error message, or null.
 *
 * `requireSiteName` is true for the GLOBAL defaults form, where an empty
 * site_name is meaningless. For the TENANT form an empty value CLEARS the
 * override (falls back to global/default), so emptiness is not an error there.
 *
 * Only inspects `site_name`/`support_email` — callers may pass a partial
 * settings object (e.g. the General tab, which no longer carries `locale`).
 */
export function validate(
  values: Pick<SettingsValueMap, 'site_name' | 'support_email'>,
  requireSiteName: boolean
): string | null {
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
 * Extract a `{ key: reason }` map of per-field validation messages from a failed
 * client call (the 422 `details` envelope), or `{}` when there are none. Lets a
 * form surface each backend reason next to its own control.
 */
export function fieldErrorsFrom(error: unknown): Record<string, string> {
  if (error && typeof error === 'object' && 'details' in error) {
    return fieldDetails((error as { details?: unknown }).details) ?? {};
  }
  return {};
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
 * A single label-associated form control for the fixed per-tenant key set.
 * timezone/locale render as native <select> elements; everything else is an
 * input. (The global page uses {@link RegistrySettingControl}, which is
 * registry-driven and also renders boolean toggles.)
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
        <TimezoneSelect id={id} value={value} disabled={disabled} onChange={onChange} />
      ) : settingKey === 'locale' ? (
        <LocaleSelect id={id} value={value} disabled={disabled} onChange={onChange} />
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

interface RegistrySettingControlProps {
  entry: RegistryEntry;
  idPrefix: string;
  value: string;
  disabled?: boolean;
  /** A per-field validation message (e.g. from a 422), shown under the control. */
  error?: string;
  status?: 'overridden' | 'inherited';
  onChange: (value: string) => void;
}

/**
 * Registry-driven control: chooses its input from the descriptor's `type`
 * (`bool` → toggle, `string`/unknown → text input) and its `key` (timezone /
 * locale render dedicated selects). Renders friendly label + help text and, when
 * present, an inline validation error. Unknown keys degrade gracefully to a text
 * input labelled from the key.
 */
export function RegistrySettingControl({
  entry,
  idPrefix,
  value,
  disabled = false,
  error,
  status,
  onChange,
}: RegistrySettingControlProps) {
  const { key, type } = entry;
  const meta = fieldMetaFor(key);
  const id = `${idPrefix}-${key.replace(/\./g, '-')}`;
  const helpId = meta.help ? `${id}-help` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const describedBy = [helpId, errorId].filter(Boolean).join(' ') || undefined;

  const helpNode = meta.help ? (
    <p id={helpId} className="text-xs text-muted-foreground">
      {meta.help}
    </p>
  ) : null;

  const errorNode = error ? (
    <p id={errorId} role="alert" className="text-xs font-medium text-destructive">
      {error}
    </p>
  ) : null;

  // Boolean flags: label + help on the left, a toggle on the right.
  if (type === 'bool') {
    return (
      <div
        data-testid={`setting-row-${key}`}
        className="flex items-start justify-between gap-4 rounded-lg border border-border bg-muted/20 p-4"
      >
        <div className="space-y-0.5">
          <label htmlFor={id} className="text-sm font-medium text-foreground">
            {meta.label}
          </label>
          {helpNode}
          {errorNode}
        </div>
        <Switch
          id={id}
          data-testid={`setting-switch-${key}`}
          checked={isTruthyFlag(value)}
          disabled={disabled}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          onCheckedChange={(next) => onChange(next ? 'true' : 'false')}
        />
      </div>
    );
  }

  return (
    <div data-testid={`setting-row-${key}`} className="space-y-1.5">
      <div className="flex items-center justify-between gap-2">
        <label htmlFor={id} className="text-sm font-medium text-foreground">
          {meta.label}
        </label>
        {status && (
          <Badge
            data-testid={`status-${key}`}
            variant={status === 'overridden' ? 'default' : 'secondary'}
            className="text-[10px] font-medium capitalize"
          >
            {status}
          </Badge>
        )}
      </div>
      {helpNode}
      {key === 'timezone' ? (
        <TimezoneSelect
          id={id}
          value={value}
          disabled={disabled}
          invalid={Boolean(error)}
          describedBy={describedBy}
          onChange={onChange}
        />
      ) : key === 'locale' ? (
        <LocaleSelect
          id={id}
          value={value}
          disabled={disabled}
          invalid={Boolean(error)}
          describedBy={describedBy}
          onChange={onChange}
        />
      ) : type === 'enum' && entry.options && entry.options.length > 0 ? (
        <select
          id={id}
          value={value}
          disabled={disabled}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          onChange={(e) => onChange(e.target.value)}
          className={NATIVE_SELECT_CLASS}
        >
          {/* Keep an out-of-range stored value selectable rather than silently
              snapping it to the first option. */}
          {!entry.options.includes(value) && value !== '' && (
            <option value={value}>{value}</option>
          )}
          {entry.options.map((option) => (
            <option key={option} value={option}>
              {enumOptionLabel(option)}
            </option>
          ))}
        </select>
      ) : (
        <Input
          id={id}
          type={key === 'support_email' ? 'email' : 'text'}
          value={value}
          disabled={disabled}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          onChange={(e) => onChange(e.target.value)}
        />
      )}
      {errorNode}
    </div>
  );
}
