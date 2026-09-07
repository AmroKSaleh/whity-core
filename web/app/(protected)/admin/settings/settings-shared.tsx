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
import { Input } from '@/components/ui/input';
import { Switch } from '@amroksaleh/ui/switch';
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';

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
 * `isFlag` mirrors `options`' shape: present (and always `true`) only for keys
 * {@see SettingsRegistry::isFeatureFlag()} curates as a "feature flag" — see
 * the Feature Flags tab (`feature-flags/page.tsx`), which filters the registry
 * on this field alone rather than a hardcoded key list.
 */
export type RegistryEntry = components['schemas']['SettingsRegistryEntry'] & {
  options?: string[];
  isFlag?: boolean;
  /**
   * Plugin-declared keys (#713 item 1) additionally carry their own
   * presentation and attribution, because core cannot hardcode either:
   *   - `source` — the plugin that declared the key. Shown beside the control,
   *     so an operator changing `acme:mode` on a shared screen knows whose
   *     setting it is and which plugin to remove to make it go away.
   *   - `label` / `description` — locale => text, supplied by the declaration.
   *     Core keys carry neither (their copy lives in fieldMeta() below).
   * All optional: a core descriptor is unchanged.
   */
  source?: string;
  label?: Record<string, string>;
  description?: string;
};

/** The namespace separator the host puts between a plugin and its key. */
export const PLUGIN_KEY_SEPARATOR = ':';

/** Whether a registry key was declared by a plugin rather than by core. */
export function isPluginKey(key: string): boolean {
  return key.includes(PLUGIN_KEY_SEPARATOR);
}

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

/**
 * Per-tenant DISPLAY preferences, rendered on the General tab beneath the four
 * fields above (#1068).
 *
 * A separate list because these are rendered from the SERVER's registry
 * descriptor — which supplies the control type and the default — rather than
 * from a bespoke control per key. A second display preference is a string here
 * and nothing else.
 */
export const DISPLAY_SETTING_KEYS: readonly string[] = ['ui.hide_dates'];

/**
 * Short labels for the fixed per-tenant key set, as rendered on the General and
 * Branding tabs.
 *
 * A FUNCTION of `t` rather than a constant map because a constant cannot be
 * translated: it is built once at module scope, before any provider exists.
 * The keys stay literal at the call site, which is what keeps them extractable
 * (see docs/wiki/Internationalization.md).
 */
export function generalFieldLabels(t: TranslateFn): Record<SettingKey, string> {
  return {
    site_name: t('settings.field.site_name', 'Site name'),
    timezone: t('settings.field.timezone', 'Timezone'),
    locale: t('settings.field.locale', 'Locale'),
    support_email: t('settings.field.support_email', 'Support email'),
  };
}

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
export function fieldMeta(t: TranslateFn): Record<string, FieldMeta> {
  return {
    'ui.hide_dates': {
      label: t('settings.meta.ui.hide_dates.label', 'Hide dates and times'),
      help: t(
        'settings.meta.ui.hide_dates.help',
        'Stop showing dates and times anywhere in the interface. Everything is still recorded — timestamps stay in the database, in the audit trail and in the API, and turning this off brings them all back. The public document-verification page is not affected; it has its own setting.'
      ),
    },
    'error_tracking.enabled': {
      label: t('settings.meta.error_tracking.enabled.label', 'Record errors'),
      help: t(
        'settings.meta.error_tracking.enabled.help',
        'Capture unhandled errors from this instance. Off means nothing is recorded or sent.'
      ),
    },
    'error_tracking.provider': {
      label: t('settings.meta.error_tracking.provider.label', 'Destination'),
      help: t(
        'settings.meta.error_tracking.provider.help',
        'Keep errors on this instance in the built-in inbox, or forward them over the Sentry protocol.'
      ),
    },
    'error_tracking.environment': {
      label: t('settings.meta.error_tracking.environment.label', 'Environment label'),
      help: t(
        'settings.meta.error_tracking.environment.help',
        'Tags events with the deployment they came from (e.g. staging, production). Leave blank to omit.'
      ),
    },
    'error_tracking.notify_admins': {
      label: t('settings.meta.error_tracking.notify_admins.label', 'Email platform admins'),
      help: t(
        'settings.meta.error_tracking.notify_admins.help',
        'Alerts admins when an error group first appears, or reappears after being resolved. Sent through the durable queue, so a mail outage never loses an alert.'
      ),
    },
    'error_tracking.retention_days': {
      label: t('settings.meta.error_tracking.retention_days.label', 'Retention (days)'),
      help: t(
        'settings.meta.error_tracking.retention_days.help',
        'How long resolved groups and their occurrences are kept before pruning.'
      ),
    },
    site_name: {
      label: t('settings.meta.site_name.label', 'Site name'),
      help: t(
        'settings.meta.site_name.help',
        'The public name of this instance, shown in the browser title and on the sign-in screen.'
      ),
    },
    timezone: {
      label: t('settings.meta.timezone.label', 'Default timezone'),
      help: t(
        'settings.meta.timezone.help',
        'Applied for tenants that have not chosen their own timezone.'
      ),
    },
    locale: {
      label: t('settings.meta.locale.label', 'Default locale'),
      help: t(
        'settings.meta.locale.help',
        'Default interface language for tenants that have not overridden it.'
      ),
    },
    support_email: {
      label: t('settings.meta.support_email.label', 'Support email'),
      help: t('settings.meta.support_email.help', 'Shown to users who need help. Leave blank to hide it.'),
    },
    'mcp.enabled': {
      label: t('settings.meta.mcp.enabled.label', 'Model Context Protocol (MCP) endpoint'),
      help: t(
        'settings.meta.mcp.enabled.help',
        'Expose the MCP tool endpoint so connected AI clients can call this instance.'
      ),
    },
    'auth.self_registration_enabled': {
      label: t('settings.meta.auth.self_registration_enabled.label', 'Public sign-up'),
      help: t(
        'settings.meta.auth.self_registration_enabled.help',
        'Let anyone create an account from the public registration page. Off by default — an operator-provisioned instance opens sign-up explicitly.'
      ),
    },
    'auth.registration_approval_required': {
      label: t('settings.meta.auth.registration_approval_required.label', 'Require admin approval'),
      help: t(
        'settings.meta.auth.registration_approval_required.help',
        'When sign-up is open, hold each new account as pending until an administrator approves it.'
      ),
    },
    'auth.sso_enabled': {
      label: t('settings.meta.auth.sso_enabled.label', 'Single sign-on (SSO)'),
      help: t(
        'settings.meta.auth.sso_enabled.help',
        'Master switch for federated sign-in. When off, every configured identity provider is disabled instance-wide.'
      ),
    },
    'storage.driver': {
      label: t('settings.meta.storage.driver.label', 'Storage driver'),
      help: t(
        'settings.meta.storage.driver.help',
        'Where uploaded files are stored: local disk, or an S3-compatible object store.'
      ),
    },
    'storage.s3.endpoint': {
      label: t('settings.meta.storage.s3.endpoint.label', 'S3 endpoint'),
      help: t('settings.meta.storage.s3.endpoint.help', 'Base URL of the S3-compatible service.'),
    },
    'storage.s3.region': { label: t('settings.meta.storage.s3.region.label', 'S3 region') },
    'storage.s3.bucket': { label: t('settings.meta.storage.s3.bucket.label', 'S3 bucket') },
    'storage.s3.access_key': {
      label: t('settings.meta.storage.s3.access_key.label', 'S3 access key'),
      help: t(
        'settings.meta.storage.s3.access_key.help',
        'The matching secret key is supplied via the deployment environment and is never stored here.'
      ),
    },
    'storage.s3.path_style': {
      label: t('settings.meta.storage.s3.path_style.label', 'S3 path-style addressing'),
      help: t(
        'settings.meta.storage.s3.path_style.help',
        'Use path-style bucket URLs (required by most self-hosted S3 gateways).'
      ),
    },
    'storage.s3.public_base_url': {
      label: t('settings.meta.storage.s3.public_base_url.label', 'S3 public base URL'),
      help: t(
        'settings.meta.storage.s3.public_base_url.help',
        'Public base URL used to serve stored assets, when it differs from the endpoint.'
      ),
    },
    // Outgoing email (managed on the dedicated Email settings page).
    'mail.transport': {
      label: t('settings.meta.mail.transport.label', 'Transport'),
      help: t('settings.meta.mail.transport.help', 'How outgoing email is delivered.'),
    },
    'mail.smtp.host': { label: t('settings.meta.mail.smtp.host.label', 'SMTP host') },
    'mail.smtp.port': {
      label: t('settings.meta.mail.smtp.port.label', 'SMTP port'),
      // Protocol port numbers and their transport names read the same in every
      // language, so the mask itself is not translated.
      help: '587 = STARTTLS · 465 = SSL · 25 / 1025 = none',
    },
    'mail.smtp.encryption': { label: t('settings.meta.mail.smtp.encryption.label', 'Encryption') },
    'mail.smtp.username': {
      label: t('settings.meta.mail.smtp.username.label', 'SMTP username'),
      help: t(
        'settings.meta.mail.smtp.username.help',
        'Optional — leave blank for unauthenticated relays.'
      ),
    },
    'mail.from_address': {
      label: t('settings.meta.mail.from_address.label', 'From address'),
      help: t(
        'settings.meta.mail.from_address.help',
        'The address recipients see messages come from.'
      ),
    },
    'mail.from_name': {
      label: t('settings.meta.mail.from_name.label', 'From name'),
      help: t(
        'settings.meta.mail.from_name.help',
        'The display name shown alongside the from address.'
      ),
    },
    'mail.events.welcome_enabled': {
      label: t('settings.meta.mail.events.welcome_enabled.label', 'Welcome email'),
      help: t(
        'settings.meta.mail.events.welcome_enabled.help',
        'Send a welcome message when a new account is created.'
      ),
    },
    'mail.events.approval_enabled': {
      label: t('settings.meta.mail.events.approval_enabled.label', 'Approval decision email'),
      help: t(
        'settings.meta.mail.events.approval_enabled.help',
        'Notify a registrant when their account is approved or rejected.'
      ),
    },
    'mail.events.invitation_enabled': {
      label: t('settings.meta.mail.events.invitation_enabled.label', 'Workspace invitation email'),
      help: t(
        'settings.meta.mail.events.invitation_enabled.help',
        'Email people when they are invited to a workspace.'
      ),
    },
    'i18n.enabled': {
      label: t('settings.meta.i18n.enabled.label', 'Multiple languages'),
      help: t(
        'settings.meta.i18n.enabled.help',
        'Let people choose the language the interface is shown in, and mirror it for right-to-left languages. Off means everyone sees the default language, left-to-right, with no language control anywhere — stored preferences and translations are kept, so turning it back on restores them.'
      ),
    },
    'plugins.store_enabled': {
      label: t('settings.meta.plugins.store_enabled.label', 'Plugin marketplace'),
      help: t(
        'settings.meta.plugins.store_enabled.help',
        'Allow installing plugins from a trusted external store. A non-empty plugins.store_allowed_hosts allowlist is also required — this switch lets an operator disable the whole integration without losing that host list.'
      ),
    },
  };
}

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
export function settingsSections(t: TranslateFn): readonly SectionDef[] {
  return [
    {
      id: 'general',
      title: t('settings.section.general.title', 'General'),
      description: t(
        'settings.section.general.description',
        'Instance identity and defaults inherited by every tenant.'
      ),
      keys: ['site_name', 'support_email', 'timezone', 'locale'],
    },
    {
      id: 'signup',
      title: t('settings.section.signup.title', 'Sign-up governance'),
      description: t(
        'settings.section.signup.description',
        'Control whether and how new people can create accounts on this instance.'
      ),
      keys: ['auth.self_registration_enabled', 'auth.registration_approval_required'],
    },
    {
      id: 'i18n',
      title: t('settings.section.i18n.title', 'Languages'),
      description: t(
        'settings.section.i18n.description',
        'Whether this instance presents itself in more than one language.'
      ),
      keys: ['i18n.enabled'],
    },
    {
      id: 'signin',
      title: t('settings.section.signin.title', 'Sign-in & SSO'),
      description: t(
        'settings.section.signin.description',
        'Federated sign-in across the whole instance.'
      ),
      keys: ['auth.sso_enabled'],
    },
    {
      id: 'integrations',
      title: t('settings.section.integrations.title', 'Integrations'),
      description: t(
        'settings.section.integrations.description',
        'Machine-facing endpoints and connected tooling.'
      ),
      keys: ['mcp.enabled'],
    },
    {
      id: 'storage',
      title: t('settings.section.storage.title', 'Storage'),
      description: t('settings.section.storage.description', 'Where uploaded files and assets are kept.'),
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
}

/**
 * Catch-all for PLUGIN-declared keys (#713 item 1).
 *
 * Grouped rather than scattered through core's sections deliberately: an
 * operator reading this page needs to know which switches belong to the
 * platform and which arrived with a plugin, because uninstalling the plugin
 * takes its keys with it. Only keys whose declaration opted in with
 * `admin => true` are published by the backend at all, so anything reaching
 * this section is here because its author chose to put it here.
 */
function pluginSection(t: TranslateFn): SectionDef {
  return {
    id: 'plugins',
    title: t('settings.section.plugins.title', 'Plugin settings'),
    description: t(
      'settings.section.plugins.description',
      'Settings declared by the plugins installed on this instance.'
    ),
    keys: [],
  };
}

/** Catch-all for registry keys not claimed by a named section above. */
function otherSection(t: TranslateFn): SectionDef {
  return {
    id: 'other',
    title: t('settings.section.other.title', 'Other settings'),
    description: t(
      'settings.section.other.description',
      'Additional settings published by this instance.'
    ),
    keys: [],
  };
}

export interface RegistrySection {
  section: SectionDef;
  entries: RegistryEntry[];
}

/**
 * Bucket registry descriptors into ordered sections. A section with no present
 * keys is dropped; any key not claimed by a named section is appended under
 * "Other settings" so a newly-added backend key always appears somewhere.
 */
export function groupRegistry(registry: readonly RegistryEntry[], t: TranslateFn): RegistrySection[] {
  const byKey = new Map(registry.map((entry) => [entry.key, entry]));
  const claimed = new Set<string>();
  const sections: RegistrySection[] = [];

  for (const section of settingsSections(t)) {
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

  // Plugin-declared keys form their own section, in declaration order.
  const pluginEntries = registry.filter((entry) => !claimed.has(entry.key) && isPluginKey(entry.key));
  for (const entry of pluginEntries) {
    claimed.add(entry.key);
  }
  if (pluginEntries.length > 0) {
    sections.push({ section: pluginSection(t), entries: pluginEntries });
  }

  // `mail.*` keys are managed on the dedicated Email settings page (transport-
  // conditional layout + write-only password + test-send), so they are excluded
  // from this generic form rather than dumped into "Other settings".
  const leftover = registry.filter(
    (entry) => !claimed.has(entry.key) && !entry.key.startsWith('mail.')
  );
  if (leftover.length > 0) {
    sections.push({ section: otherSection(t), entries: leftover });
  }

  return sections;
}

/**
 * The registry entries curated as feature flags — the `isFlag` marker set by
 * the backend ({@see SettingsRegistry::FEATURE_FLAG_KEYS}), NOT a hardcoded
 * client-side key list, so a new flag added server-side appears here with
 * zero frontend changes. Order follows the registry's own declaration order.
 */
export function featureFlagEntries(registry: readonly RegistryEntry[]): RegistryEntry[] {
  return registry.filter((entry) => entry.isFlag === true);
}

/** Turn a raw key (`storage.s3.public_base_url`) into a readable label. */
export function humanizeKey(key: string): string {
  // Drop the plugin namespace first, so `acme:sync.interval` humanises from
  // `interval` rather than from `acme:sync.interval` — this is only the
  // FALLBACK path; a plugin that declared a label gets its own text instead.
  const bare = key.includes(PLUGIN_KEY_SEPARATOR)
    ? key.slice(key.lastIndexOf(PLUGIN_KEY_SEPARATOR) + 1)
    : key;
  const tail = bare.includes('.') ? bare.slice(bare.lastIndexOf('.') + 1) : bare;
  return tail
    .replace(/[_.]+/g, ' ')
    .trim()
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

/** The label + help for a key, falling back to a humanised label. */
export function fieldMetaFor(key: string, t: TranslateFn): FieldMeta {
  return fieldMeta(t)[key] ?? { label: humanizeKey(key) };
}

/**
 * Label + help for one descriptor, preferring what the DECLARATION said.
 *
 * Core keys keep their curated copy in fieldMeta(). A plugin key cannot — core
 * has never heard of it — so the declaration carries its own label and
 * description and they win here. A plugin that declared neither still renders,
 * labelled from the humanised bare key.
 */
export function entryMetaFor(entry: RegistryEntry, t: TranslateFn): FieldMeta {
  const declared = entry.label?.en ?? Object.values(entry.label ?? {})[0];
  if (declared || entry.description) {
    return {
      label: declared || humanizeKey(entry.key),
      help: entry.description || undefined,
    };
  }

  return fieldMetaFor(entry.key, t);
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

function localeOptions(t: TranslateFn): ReadonlyArray<{ value: string; label: string }> {
  return [
    { value: 'en', label: t('settings.localeOption.en', 'English (en)') },
    { value: 'en-US', label: t('settings.localeOption.enUS', 'English — United States (en-US)') },
    { value: 'en-GB', label: t('settings.localeOption.enGB', 'English — United Kingdom (en-GB)') },
    { value: 'de', label: t('settings.localeOption.de', 'German (de)') },
    { value: 'fr', label: t('settings.localeOption.fr', 'French (fr)') },
    { value: 'es', label: t('settings.localeOption.es', 'Spanish (es)') },
    { value: 'ar', label: t('settings.localeOption.ar', 'Arabic (ar)') },
  ];
}

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
  const t = useTranslation('admin');
  const options = localeOptions(t);

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
      {!options.some((o) => o.value === value) && value !== '' && (
        <option value={value}>{value}</option>
      )}
      {options.map((o) => (
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
  requireSiteName: boolean,
  t: TranslateFn
): string | null {
  if (requireSiteName && values.site_name.trim() === '') {
    return t('settings.validation.siteNameRequired', 'Site name cannot be empty.');
  }
  if (values.support_email.trim() !== '' && !EMAIL_RE.test(values.support_email.trim())) {
    return t(
      'settings.validation.supportEmailInvalid',
      'Support email must be a valid email address (or left blank).'
    );
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

/**
 * Badge text for the inherited/overridden indicator. Kept lowercase because the
 * badge capitalises it in CSS, exactly as before translation.
 */
function statusLabel(status: 'overridden' | 'inherited', t: TranslateFn): string {
  return status === 'overridden'
    ? t('settings.status.overridden', 'overridden')
    : t('settings.status.inherited', 'inherited');
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
  const t = useTranslation('admin');
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
            {statusLabel(status, t)}
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
  const t = useTranslation('admin');
  const { key, type } = entry;
  const meta = entryMetaFor(entry, t);
  const id = `${idPrefix}-${key.replace(/[.:]/g, '-')}`;
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

  // Attribution for a plugin-declared key (#713 item 1). Not decoration: these
  // controls sit on the same screen as core's own, and an operator about to
  // change one needs to know it belongs to a plugin — that its meaning is the
  // plugin's, and that removing the plugin removes the setting.
  const sourceNode = entry.source ? (
    <Badge
      data-testid={`setting-source-${key}`}
      variant="outline"
      className="text-[10px] font-medium"
    >
      {entry.source}
    </Badge>
  ) : null;

  // Boolean flags: label + help on the left, a toggle on the right.
  if (type === 'bool') {
    return (
      <div
        data-testid={`setting-row-${key}`}
        className="flex items-start justify-between gap-4 rounded-lg border border-border bg-muted/20 p-4"
      >
        <div className="space-y-0.5">
          <div className="flex items-center gap-2">
            <label htmlFor={id} className="text-sm font-medium text-foreground">
              {meta.label}
            </label>
            {sourceNode}
            {/* #1068: a bool control could not report inherited/overridden, and
                every other control on the tenant card can. On a card whose whole
                subject is per-tenant OVERRIDES, a row that cannot say whether it
                is one is the row an operator misreads. Only rendered when a
                caller passes `status`, so the global-only surfaces that have no
                such distinction are unchanged. */}
            {status && (
              <Badge
                data-testid={`status-${key}`}
                variant={status === 'overridden' ? 'default' : 'secondary'}
                className="text-[10px] font-medium capitalize"
              >
                {statusLabel(status, t)}
              </Badge>
            )}
          </div>
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
        <div className="flex items-center gap-2">
          <label htmlFor={id} className="text-sm font-medium text-foreground">
            {meta.label}
          </label>
          {sourceNode}
        </div>
        {status && (
          <Badge
            data-testid={`status-${key}`}
            variant={status === 'overridden' ? 'default' : 'secondary'}
            className="text-[10px] font-medium capitalize"
          >
            {statusLabel(status, t)}
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
