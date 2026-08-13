'use client';

import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { api } from '@/lib/api/client';
import { apiClient } from '@/lib/api-client';
import { useToast } from '@/lib/toast-context';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Input } from '@/components/ui/input';
import { Badge } from '@amroksaleh/ui/badge';
import { AccessDenied } from '@amroksaleh/ui/access-denied';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { IconAlertTriangle, IconBug, IconDeviceFloppy, IconInbox } from '@tabler/icons-react';
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';
import { SettingsTabs } from '../settings-tabs';
import {
  SETTINGS_MANAGE,
  SYSTEM_TENANT_ID,
  RegistrySettingControl,
  errorMessage,
  fieldErrorsFrom,
  isTruthyFlag,
  type RegistryEntry,
  type SettingsMap,
} from '../settings-shared';

/**
 * Error-tracking settings (WC-error-tracking). Operator-only: `error_groups` is
 * an instance-wide table and the DSN is an instance credential, so this mirrors
 * Email/Storage's gate exactly — system tenant AND `settings:manage`.
 *
 * The DSN is WRITE-ONLY, handled exactly like the SMTP password: it never
 * round-trips through the settings API, so this page can only ever learn
 * WHETHER one is stored (`has_dsn` from GET .../error-tracking/status), never
 * its value. Everything else is a plain global setting read/written via
 * GET/PATCH /api/v1/settings/global.
 */

const ET_KEYS = {
  enabled: 'error_tracking.enabled',
  provider: 'error_tracking.provider',
  environment: 'error_tracking.environment',
  notifyAdmins: 'error_tracking.notify_admins',
  retentionDays: 'error_tracking.retention_days',
} as const;

// Client-side fallbacks mirroring SettingsRegistry's defaults, so the page still
// renders if the registry entry is missing. A real registry entry (including its
// enum `options`) always takes precedence.
const FALLBACK_ENTRIES: Record<string, RegistryEntry> = {
  [ET_KEYS.enabled]: { key: ET_KEYS.enabled, type: 'bool', default: '0' },
  [ET_KEYS.provider]: {
    key: ET_KEYS.provider,
    type: 'enum',
    default: 'internal',
    options: ['internal', 'sentry'],
  },
  [ET_KEYS.environment]: { key: ET_KEYS.environment, type: 'string', default: '' },
  [ET_KEYS.notifyAdmins]: { key: ET_KEYS.notifyAdmins, type: 'bool', default: '1' },
  // Not a bool/enum in SettingsRegistry, so it comes through as a string.
  [ET_KEYS.retentionDays]: { key: ET_KEYS.retentionDays, type: 'string', default: '90' },
};

interface ErrorTrackingStatus {
  has_dsn: boolean;
}

// Bool settings round-trip as the literal 'true'/'false' (see isTruthyFlag) —
// deriving "is it on" any other way would drift from what the Switch renders.

export default function ErrorTrackingSettingsPage() {
  const { addToast } = useToast();
  const { user } = useAuth();
  const { hasPermission, loading: capsLoading } = useCapabilities();
  const t = useTranslation('admin');

  const canManage = hasPermission(SETTINGS_MANAGE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  if (capsLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
      </div>
    );
  }

  if (!isSystemTenant || !canManage) {
    return (
      <AccessDenied
        description={t(
          'settings.errorTracking.accessDenied',
          'Error tracking is an instance-wide setting managed by the system tenant with the {permission} permission.',
          { permission: SETTINGS_MANAGE }
        )}
        action={
          <Button onClick={() => window.history.back()} variant="outline">
            {t('settings.errorTracking.goBack', 'Go Back')}
          </Button>
        }
      />
    );
  }

  return <ErrorTrackingSettingsForm addToast={addToast} />;
}

function ErrorTrackingSettingsForm({
  addToast,
}: {
  addToast: ReturnType<typeof useToast>['addToast'];
}) {
  const t = useTranslation('admin');

  const { data, error, refetch } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings/global');
    if (body === undefined) {
      throw new Error(
        errorMessage(getError, t('settings.errorTracking.loadFailed', 'Failed to load settings'))
      );
    }
    return body.data;
  }, []);

  const { data: status, refetch: refetchStatus } = useFetch<ErrorTrackingStatus>(async () => {
    const res = await apiClient('/api/v1/settings/error-tracking/status');
    if (!res.ok) return { has_dsn: false };
    const body: unknown = await res.json();
    // The API wraps payloads in a { data: ... } envelope.
    const payload =
      body && typeof body === 'object' ? (body as { data?: unknown }).data : undefined;
    return {
      has_dsn: Boolean(
        payload && typeof payload === 'object' && (payload as { has_dsn?: unknown }).has_dsn
      ),
    };
  }, []);

  const global = data?.global as SettingsMap | undefined;
  const registry = useMemo<RegistryEntry[]>(() => data?.registry ?? [], [data]);
  const byKey = useMemo(() => new Map(registry.map((e) => [e.key, e])), [registry]);

  const [draft, setDraft] = useState<Record<string, string>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const [dsnInput, setDsnInput] = useState('');
  const [savingDsn, setSavingDsn] = useState(false);

  const entryFor = (key: string): RegistryEntry => byKey.get(key) ?? FALLBACK_ENTRIES[key];
  const valueOf = (key: string): string => draft[key] ?? global?.[key] ?? entryFor(key).default;

  const setField = (key: string, value: string) => {
    setDraft((prev) => ({ ...prev, [key]: value }));
    setFieldErrors((prev) => {
      if (!(key in prev)) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const enabled = isTruthyFlag(valueOf(ET_KEYS.enabled));
  const provider = valueOf(ET_KEYS.provider);
  const dirty = Object.keys(draft).length > 0;
  const hasDsn = status?.has_dsn ?? false;
  const [internalNoteBeforeLink, internalNoteAfterLink] = t(
    'settings.errorTracking.note.internal',
    'Errors are grouped by fingerprint and stored on this instance — no third party involved. Review them in the {link}.'
  ).split('{link}');

  // Sentry can't send anywhere without a DSN — surface that before the operator
  // saves a config that silently drops every event.
  const sentryNeedsDsn = enabled && provider === 'sentry' && !hasDsn;

  useEffect(() => {
    // Surface a load failure; the page still renders with client defaults.
    if (error) addToast(error, 'error');
  }, [error, addToast]);

  const control = (key: string, disabled = false) => (
    <RegistrySettingControl
      key={key}
      entry={entryFor(key)}
      idPrefix="error-tracking"
      value={valueOf(key)}
      error={fieldErrors[key]}
      disabled={disabled}
      onChange={(v) => setField(key, v)}
    />
  );

  const handleSave = async () => {
    if (!dirty) return;
    const settings: Record<string, string> = {};
    for (const key of Object.keys(draft)) settings[key] = draft[key].trim();

    setSaving(true);
    setFieldErrors({});
    try {
      const { error: patchError } = await api.PATCH('/api/v1/settings/global', { body: { settings } });
      if (patchError) {
        setFieldErrors(fieldErrorsFrom(patchError));
        throw new Error(
          errorMessage(
            patchError,
            t('settings.errorTracking.save.failed', 'Failed to save error-tracking settings')
          )
        );
      }
      addToast(t('settings.errorTracking.save.success', 'Error-tracking settings saved.'), 'success');
      setDraft({});
      refetch();
    } catch (err) {
      addToast(
        err instanceof Error
          ? err.message
          : t('settings.errorTracking.save.failed', 'Failed to save error-tracking settings'),
        'error'
      );
    } finally {
      setSaving(false);
    }
  };

  const handleSaveDsn = async () => {
    setSavingDsn(true);
    try {
      const res = await apiClient('/api/v1/settings/error-tracking/dsn', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        // An empty box clears the stored DSN (the backend accepts null).
        body: JSON.stringify({ dsn: dsnInput.trim() === '' ? null : dsnInput.trim() }),
      });
      if (!res.ok && res.status !== 204) {
        addToast(
          await readError(res, t('settings.errorTracking.dsn.saveFailed', 'Could not save the DSN'), t),
          'error'
        );
        return;
      }
      addToast(
        dsnInput.trim() === ''
          ? t('settings.errorTracking.dsn.cleared', 'DSN cleared.')
          : t('settings.errorTracking.dsn.saved', 'DSN saved.'),
        'success'
      );
      setDsnInput('');
      refetchStatus();
    } finally {
      setSavingDsn(false);
    }
  };

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title={t('settings.errorTracking.title', 'Error tracking')}
        description={t(
          'settings.errorTracking.description',
          "Where this instance's unhandled errors go. Managed by the system tenant."
        )}
      />
      <SettingsTabs active="error-tracking" />

      {/* Collection + destination */}
      <Card className="border border-border bg-card shadow-sm" data-testid="error-tracking-provider-card">
        <CardHeader>
          <div className="flex items-center gap-2">
            <span className="p-2 bg-primary/10 rounded-lg text-primary">
              <IconBug className="w-5 h-5" />
            </span>
            <div>
              <CardTitle className="text-lg font-bold font-heading">
                <h2>{t('settings.errorTracking.collection.title', 'Collection')}</h2>
              </CardTitle>
              <CardDescription className="text-sm">
                {t(
                  'settings.errorTracking.collection.description',
                  'Whether errors are recorded, and where they are sent.'
                )}
              </CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          {control(ET_KEYS.enabled)}
          {control(ET_KEYS.provider, !enabled)}

          {enabled && provider === 'internal' && (
            <Alert variant="info" data-testid="error-tracking-provider-note">
              <AlertDescription className="flex flex-wrap items-center gap-1">
                {/* ONE translatable sentence with a {link} hole, split at render
                    time: a translator sees the whole sentence and can put the
                    link wherever the target language needs it. */}
                {internalNoteBeforeLink}
                <Link href="/admin/errors" className="font-medium underline underline-offset-2">
                  {t('settings.errorTracking.note.internalLink', 'error inbox')}
                </Link>
                {internalNoteAfterLink}
              </AlertDescription>
            </Alert>
          )}
          {enabled && provider === 'sentry' && (
            <Alert variant="info" data-testid="error-tracking-provider-note">
              <AlertDescription>
                {t(
                  'settings.errorTracking.note.sentry',
                  'Events are sent over the Sentry protocol — hosted Sentry, or a self-hosted GlitchTip/Bugsink. Set the DSN below.'
                )}
              </AlertDescription>
            </Alert>
          )}
          {!enabled && (
            <Alert variant="info" data-testid="error-tracking-provider-note">
              <AlertDescription>
                {t('settings.errorTracking.note.off', 'Error tracking is off — nothing is recorded or sent.')}
              </AlertDescription>
            </Alert>
          )}
        </CardContent>
      </Card>

      {/* Write-only DSN: only ever shows WHETHER one is stored. */}
      {enabled && provider === 'sentry' && (
        <Card className="border border-border bg-card shadow-sm" data-testid="error-tracking-dsn-card">
          <CardHeader>
            <CardTitle className="text-lg font-bold font-heading">
              <h2>{t('settings.errorTracking.dsn.title', 'Sentry DSN')}</h2>
            </CardTitle>
            <CardDescription className="text-sm">
              {t(
                'settings.errorTracking.dsn.description',
                'The endpoint events are sent to. Treated as a credential.'
              )}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-1.5" data-testid="error-tracking-dsn-field">
              <div className="flex items-center justify-between gap-2">
                <label htmlFor="error-tracking-dsn" className="text-sm font-medium text-foreground">
                  {t('settings.errorTracking.dsn.label', 'DSN')}
                </label>
                <Badge
                  data-testid="error-tracking-dsn-status"
                  variant={hasDsn ? 'secondary' : 'outline'}
                  className="text-[10px]"
                >
                  {hasDsn
                    ? t('settings.errorTracking.dsn.statusSet', 'DSN is set')
                    : t('settings.errorTracking.dsn.statusUnset', 'Not set')}
                </Badge>
              </div>
              <div className="flex items-center gap-2">
                {/* The empty-state hint is a DSN URL mask, identical in every
                    language, so only the "already stored" hint is translated. */}
                <Input
                  id="error-tracking-dsn"
                  type="password"
                  autoComplete="off"
                  placeholder={
                    hasDsn
                      ? t('settings.errorTracking.dsn.placeholderSet', '•••••••• (unchanged)')
                      : 'https://…@…ingest.sentry.io/…'
                  }
                  value={dsnInput}
                  disabled={savingDsn}
                  onChange={(e) => setDsnInput(e.target.value)}
                />
                <Button
                  variant="outline"
                  onClick={() => void handleSaveDsn()}
                  disabled={savingDsn}
                  data-testid="error-tracking-save-dsn"
                >
                  {savingDsn
                    ? t('settings.errorTracking.dsn.saving', 'Saving…')
                    : t('settings.errorTracking.dsn.save', 'Save DSN')}
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">
                {t(
                  'settings.errorTracking.dsn.help',
                  'Stored encrypted; never shown again. Leave blank and save to clear it.'
                )}
              </p>
            </div>

            {sentryNeedsDsn && (
              <Alert variant="warning" data-testid="error-tracking-dsn-warning">
                <IconAlertTriangle className="w-4 h-4" />
                <AlertDescription>
                  {t(
                    'settings.errorTracking.dsn.warning',
                    'No DSN is stored, so nothing is being sent. Errors are dropped until you set one.'
                  )}
                </AlertDescription>
              </Alert>
            )}
          </CardContent>
        </Card>
      )}

      {/* Alerts + retention */}
      <Card className="border border-border bg-card shadow-sm" data-testid="error-tracking-alerts-card">
        <CardHeader>
          <div className="flex items-center gap-2">
            <span className="p-2 bg-primary/10 rounded-lg text-primary">
              <IconInbox className="w-5 h-5" />
            </span>
            <div>
              <CardTitle className="text-lg font-bold font-heading">
                <h2>{t('settings.errorTracking.alerts.title', 'Alerts and retention')}</h2>
              </CardTitle>
              <CardDescription className="text-sm">
                {t(
                  'settings.errorTracking.alerts.description',
                  'Who hears about new errors, and how long they are kept.'
                )}
              </CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* Help text for each of these lives in fieldMeta(), rendered by the control. */}
          {control(ET_KEYS.notifyAdmins, !enabled)}
          {control(ET_KEYS.environment, !enabled)}
          {control(ET_KEYS.retentionDays, !enabled)}
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <Button
          onClick={() => void handleSave()}
          disabled={saving || !dirty}
          className="gap-2"
          data-testid="error-tracking-save"
        >
          <IconDeviceFloppy className="w-4 h-4" />
          {saving
            ? t('settings.errorTracking.save.saving', 'Saving…')
            : t('settings.errorTracking.save.action', 'Save error-tracking settings')}
        </Button>
      </div>
    </div>
  );
}

/** Read the `{ error }` envelope from a failed response; friendly 404 fallback. */
async function readError(res: Response, fallback: string, t: TranslateFn): Promise<string> {
  if (res.status === 404) {
    return t(
      'settings.errorTracking.unavailable',
      'Error tracking is not available on this server yet.'
    );
  }
  try {
    const body: unknown = await res.json();
    if (body && typeof body === 'object' && 'error' in body) {
      const value = (body as { error?: unknown }).error;
      if (typeof value === 'string' && value !== '') return value;
    }
  } catch {
    // no JSON body
  }
  return fallback;
}
