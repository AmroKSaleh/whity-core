'use client';

import { useEffect, useMemo, useState } from 'react';
import { api } from '@/lib/api/client';
import { apiClient } from '@/lib/api-client';
import { useToast } from '@/lib/toast-context';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Input } from '@amroksaleh/ui/input';
import { Badge } from '@amroksaleh/ui/badge';
import {
  IconAlertCircle,
  IconDeviceFloppy,
  IconMail,
  IconSend,
} from '@tabler/icons-react';
import { SettingsTabs } from '../settings-tabs';
import {
  SETTINGS_MANAGE,
  SYSTEM_TENANT_ID,
  RegistrySettingControl,
  errorMessage,
  fieldErrorsFrom,
  type RegistryEntry,
  type SettingsMap,
} from '../settings-shared';

const AUTH_PROVIDERS_MANAGE = 'auth_providers:manage';

/**
 * Outgoing email (SMTP) settings — WC-3ac81b7e. Operator-only: the non-secret
 * fields are GLOBAL settings (system-tenant, `settings:manage`) read/written via
 * GET/PATCH /api/v1/settings/global; the SMTP password is a secret managed via
 * dedicated endpoints and NEVER round-trips. A dedicated page (not the generic
 * global form) so transport can conditionally reveal the SMTP fields, the
 * write-only password has its own control, and a test message can be sent.
 *
 * Built to the backend contract; while that backend is in flight the page still
 * renders (client-side field defaults) and the secret/test endpoints degrade to
 * a clear error toast rather than crashing.
 */

const MAIL_KEYS = {
  transport: 'mail.transport',
  host: 'mail.smtp.host',
  port: 'mail.smtp.port',
  encryption: 'mail.smtp.encryption',
  username: 'mail.smtp.username',
  fromAddress: 'mail.from_address',
  fromName: 'mail.from_name',
  welcome: 'mail.events.welcome_enabled',
  approval: 'mail.events.approval_enabled',
  invitation: 'mail.events.invitation_enabled',
} as const;

// Client-side fallbacks so the page renders before the backend publishes these
// keys in the registry. When the real registry entry exists it takes precedence
// (including its enum `options`); these only fill the gap.
const FALLBACK_ENTRIES: Record<string, RegistryEntry> = {
  [MAIL_KEYS.transport]: { key: MAIL_KEYS.transport, type: 'enum', default: 'none', options: ['none', 'log', 'smtp'] },
  [MAIL_KEYS.host]: { key: MAIL_KEYS.host, type: 'string', default: '' },
  [MAIL_KEYS.port]: { key: MAIL_KEYS.port, type: 'string', default: '' },
  [MAIL_KEYS.encryption]: { key: MAIL_KEYS.encryption, type: 'enum', default: 'tls', options: ['none', 'tls', 'ssl'] },
  [MAIL_KEYS.username]: { key: MAIL_KEYS.username, type: 'string', default: '' },
  [MAIL_KEYS.fromAddress]: { key: MAIL_KEYS.fromAddress, type: 'string', default: '' },
  [MAIL_KEYS.fromName]: { key: MAIL_KEYS.fromName, type: 'string', default: '' },
  [MAIL_KEYS.welcome]: { key: MAIL_KEYS.welcome, type: 'bool', default: 'false' },
  [MAIL_KEYS.approval]: { key: MAIL_KEYS.approval, type: 'bool', default: 'false' },
  [MAIL_KEYS.invitation]: { key: MAIL_KEYS.invitation, type: 'bool', default: 'false' },
};

const SMTP_FIELD_KEYS = [
  MAIL_KEYS.host,
  MAIL_KEYS.port,
  MAIL_KEYS.encryption,
  MAIL_KEYS.username,
  MAIL_KEYS.fromAddress,
  MAIL_KEYS.fromName,
] as const;

const NOTIFICATION_KEYS = [MAIL_KEYS.welcome, MAIL_KEYS.approval, MAIL_KEYS.invitation] as const;

interface MailStatus {
  has_smtp_password: boolean;
}

export default function EmailSettingsPage() {
  const { addToast } = useToast();
  const { user } = useAuth();
  const { hasPermission, loading: capsLoading } = useCapabilities();

  const canManage = hasPermission(SETTINGS_MANAGE);
  const canManageProviders = hasPermission(AUTH_PROVIDERS_MANAGE);
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
      <div className="flex flex-col items-center justify-center min-h-[450px] p-8 text-center bg-card border border-border rounded-2xl shadow-sm">
        <div className="p-4 bg-destructive/10 rounded-full text-destructive mb-4">
          <IconAlertCircle size={48} />
        </div>
        <h2 className="text-xl font-bold mb-2">Access Denied</h2>
        <p className="text-muted-foreground max-w-md mb-6 text-sm">
          Outgoing email is an instance-wide setting managed by the system tenant with the{' '}
          <code>settings:manage</code> permission.
        </p>
        <Button onClick={() => window.history.back()} variant="outline">
          Go Back
        </Button>
      </div>
    );
  }

  return (
    <EmailSettingsForm
      adminEmail={user?.email ?? ''}
      canManageProviders={canManageProviders}
      addToast={addToast}
    />
  );
}

function EmailSettingsForm({
  adminEmail,
  canManageProviders,
  addToast,
}: {
  adminEmail: string;
  canManageProviders: boolean;
  addToast: ReturnType<typeof useToast>['addToast'];
}) {
  const { data, error, refetch } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings/global');
    if (body === undefined) {
      throw new Error(errorMessage(getError, 'Failed to load settings'));
    }
    return body.data;
  }, []);

  const { data: status, refetch: refetchStatus } = useFetch<MailStatus>(async () => {
    const res = await apiClient('/api/v1/settings/mail/status');
    if (!res.ok) return { has_smtp_password: false };
    const body: unknown = await res.json();
    // The API wraps payloads in a { data: ... } envelope.
    const payload =
      body && typeof body === 'object' ? (body as { data?: unknown }).data : undefined;
    return {
      has_smtp_password: Boolean(
        payload && typeof payload === 'object' && (payload as { has_smtp_password?: unknown }).has_smtp_password
      ),
    };
  }, []);

  const global = data?.global as SettingsMap | undefined;
  const registry = useMemo<RegistryEntry[]>(() => data?.registry ?? [], [data]);
  const byKey = useMemo(() => new Map(registry.map((e) => [e.key, e])), [registry]);

  const [draft, setDraft] = useState<Record<string, string>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const [passwordInput, setPasswordInput] = useState('');
  const [savingPassword, setSavingPassword] = useState(false);

  const [testTo, setTestTo] = useState(adminEmail);
  const [testing, setTesting] = useState(false);

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

  const transport = valueOf(MAIL_KEYS.transport);
  const dirty = Object.keys(draft).length > 0;
  const hasPassword = status?.has_smtp_password ?? false;

  useEffect(() => {
    // Surface a load failure; the page still renders with client defaults.
    if (error) addToast(error, 'error');
  }, [error, addToast]);

  const control = (key: string, disabled = false) => (
    <RegistrySettingControl
      key={key}
      entry={entryFor(key)}
      idPrefix="email"
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
        throw new Error(errorMessage(patchError, 'Failed to save email settings'));
      }
      addToast('Email settings saved.', 'success');
      setDraft({});
      refetch();
    } catch (err) {
      addToast(err instanceof Error ? err.message : 'Failed to save email settings', 'error');
    } finally {
      setSaving(false);
    }
  };

  const handleSavePassword = async () => {
    setSavingPassword(true);
    try {
      const res = await apiClient('/api/v1/settings/mail/smtp-password', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ password: passwordInput }),
      });
      if (!res.ok && res.status !== 204) {
        addToast(await readError(res, 'Could not save the SMTP password'), 'error');
        return;
      }
      addToast(passwordInput === '' ? 'SMTP password cleared.' : 'SMTP password saved.', 'success');
      setPasswordInput('');
      refetchStatus();
    } finally {
      setSavingPassword(false);
    }
  };

  const handleSendTest = async () => {
    if (testTo.trim() === '') {
      addToast('Enter a recipient address for the test email.', 'error');
      return;
    }
    setTesting(true);
    try {
      const res = await apiClient('/api/v1/settings/mail/test', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ to: testTo.trim() }),
      });
      if (!res.ok) {
        addToast(await readError(res, 'Test email failed'), 'error');
        return;
      }
      addToast('Test email sent — check the inbox (Mailpit at http://localhost:8025 in dev).', 'success');
    } finally {
      setTesting(false);
    }
  };

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title="Email"
        description="Outgoing email (SMTP) for this instance. Managed by the system tenant."
      />
      <SettingsTabs active="email" showGlobal showEmail showSso={canManageProviders} />

      {/* Transport */}
      <Card className="border border-border bg-card shadow-sm" data-testid="email-transport-card">
        <CardHeader>
          <div className="flex items-center gap-2">
            <span className="p-2 bg-primary/10 rounded-lg text-primary">
              <IconMail className="w-5 h-5" />
            </span>
            <div>
              <CardTitle className="text-lg font-bold font-heading">
                <h2>Delivery</h2>
              </CardTitle>
              <CardDescription className="text-sm">How outgoing email leaves this instance.</CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          {control(MAIL_KEYS.transport)}
          {transport === 'none' && (
            <p data-testid="email-transport-note" className="text-sm text-muted-foreground">
              Email is disabled — nothing is sent.
            </p>
          )}
          {transport === 'log' && (
            <p data-testid="email-transport-note" className="text-sm text-muted-foreground">
              Emails are written to the server log (dev only) — not delivered.
            </p>
          )}
        </CardContent>
      </Card>

      {/* SMTP connection (only when transport = smtp) */}
      {transport === 'smtp' && (
        <Card className="border border-border bg-card shadow-sm" data-testid="email-smtp-card">
          <CardHeader>
            <CardTitle className="text-lg font-bold font-heading">
              <h2>SMTP connection</h2>
            </CardTitle>
            <CardDescription className="text-sm">
              The server that relays your mail. In dev, point this at Mailpit.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-5">
            {control(MAIL_KEYS.host)}
            {control(MAIL_KEYS.port)}
            {control(MAIL_KEYS.encryption)}
            {control(MAIL_KEYS.username)}

            {/* Write-only password: shows only whether one is set. */}
            <div className="space-y-1.5" data-testid="email-password-field">
              <div className="flex items-center justify-between gap-2">
                <label htmlFor="email-smtp-password" className="text-sm font-medium text-foreground">
                  Password
                </label>
                <Badge
                  data-testid="email-password-status"
                  variant={hasPassword ? 'secondary' : 'outline'}
                  className="text-[10px]"
                >
                  {hasPassword ? 'Password is set' : 'Not set'}
                </Badge>
              </div>
              <div className="flex items-center gap-2">
                <Input
                  id="email-smtp-password"
                  type="password"
                  autoComplete="new-password"
                  placeholder={hasPassword ? '•••••••• (unchanged)' : 'Enter SMTP password'}
                  value={passwordInput}
                  disabled={savingPassword}
                  onChange={(e) => setPasswordInput(e.target.value)}
                />
                <Button
                  variant="outline"
                  onClick={() => void handleSavePassword()}
                  disabled={savingPassword}
                  data-testid="email-save-password"
                >
                  {savingPassword ? 'Saving…' : 'Save password'}
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">
                Stored encrypted; never shown again. Leave blank and save to clear it.
              </p>
            </div>

            {control(MAIL_KEYS.fromAddress)}
            {control(MAIL_KEYS.fromName)}
          </CardContent>
        </Card>
      )}

      {/* Notifications */}
      <Card className="border border-border bg-card shadow-sm" data-testid="email-notifications-card">
        <CardHeader>
          <CardTitle className="text-lg font-bold font-heading">
            <h2>Notifications</h2>
          </CardTitle>
          <CardDescription className="text-sm">
            Which transactional emails this instance sends{transport === 'none' ? ' (requires a transport above)' : ''}.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {NOTIFICATION_KEYS.map((key) => control(key))}
        </CardContent>
      </Card>

      {/* Save non-secret fields */}
      <div className="flex justify-end">
        <Button onClick={() => void handleSave()} disabled={saving || !dirty} className="gap-2" data-testid="email-save">
          <IconDeviceFloppy className="w-4 h-4" />
          {saving ? 'Saving…' : 'Save email settings'}
        </Button>
      </div>

      {/* Send test */}
      <Card className="border border-border bg-card shadow-sm" data-testid="email-test-card">
        <CardHeader>
          <CardTitle className="text-lg font-bold font-heading">
            <h2>Send a test email</h2>
          </CardTitle>
          <CardDescription className="text-sm">
            Sends a message using the current (saved) configuration to confirm it works.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <Input
              type="email"
              aria-label="Test recipient"
              placeholder="you@example.com"
              value={testTo}
              disabled={testing}
              onChange={(e) => setTestTo(e.target.value)}
              className="sm:max-w-xs"
            />
            <Button
              onClick={() => void handleSendTest()}
              disabled={testing || transport === 'none'}
              className="gap-2"
              data-testid="email-send-test"
            >
              <IconSend className="w-4 h-4" />
              {testing ? 'Sending…' : 'Send test email'}
            </Button>
          </div>
          {transport === 'none' && (
            <p className="mt-2 text-xs text-muted-foreground">
              Choose a transport above to enable test sends.
            </p>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

/** Read the `{ error }` envelope from a failed response; friendly 404 fallback. */
async function readError(res: Response, fallback: string): Promise<string> {
  if (res.status === 404) {
    return 'Email is not available on this server yet.';
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
