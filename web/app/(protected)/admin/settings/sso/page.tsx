'use client';

import { useState } from 'react';
import { apiClient } from '@/lib/api-client';
import { useToast } from '@/lib/toast-context';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Input } from '@amroksaleh/ui/input';
import { Switch } from '@amroksaleh/ui/switch';
import { Badge } from '@amroksaleh/ui/badge';
import { AccessDenied } from '@amroksaleh/ui/access-denied';
import {
  IconAlertCircle,
  IconCopy,
  IconEdit,
  IconPlus,
  IconShieldLock,
  IconTrash,
} from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { SettingsTabs } from '../settings-tabs';
import { api } from '@/lib/api/client';
import {
  RegistrySettingControl,
  errorMessage,
  type RegistryEntry,
  type SettingsMap,
} from '../settings-shared';

/** Only the system tenant (id 0) is the operator/global scope. */
const SYSTEM_TENANT_ID = 0;
const AUTH_PROVIDERS_MANAGE = 'auth_providers:manage';

/** Provider keys the backend accepts (IdentityProvidersApiHandler::ALLOWED_PROVIDERS). */
const PROVIDER_KEYS = ['google', 'microsoft', 'oidc'] as const;
type ProviderKey = (typeof PROVIDER_KEYS)[number];

const PROVIDER_LABELS: Record<ProviderKey, string> = {
  google: 'Google',
  microsoft: 'Microsoft',
  oidc: 'Generic OpenID Connect',
};

/** Sensible issuer defaults offered when adding a provider (operator can edit). */
const ISSUER_SUGGESTIONS: Record<ProviderKey, string> = {
  google: 'https://accounts.google.com',
  microsoft: 'https://login.microsoftonline.com/common/v2.0',
  oidc: '',
};

const DEFAULT_SCOPES = 'openid email profile';

/** The display-safe identity provider returned by GET /api/v1/identity-providers. */
interface IdentityProvider {
  id: number;
  provider_key: string;
  display_name: string;
  client_id: string;
  has_secret: boolean;
  issuer: string;
  discovery_url: string | null;
  scopes: string;
  domain: string | null;
  enabled: boolean;
}

interface ProviderForm {
  provider_key: ProviderKey;
  display_name: string;
  client_id: string;
  client_secret: string;
  issuer: string;
  discovery_url: string;
  scopes: string;
  domain: string;
  enabled: boolean;
}

function emptyForm(): ProviderForm {
  return {
    provider_key: 'google',
    display_name: PROVIDER_LABELS.google,
    client_id: '',
    client_secret: '',
    issuer: ISSUER_SUGGESTIONS.google,
    discovery_url: '',
    scopes: DEFAULT_SCOPES,
    domain: '',
    enabled: true,
  };
}

function formForEdit(p: IdentityProvider): ProviderForm {
  const key = (PROVIDER_KEYS as readonly string[]).includes(p.provider_key)
    ? (p.provider_key as ProviderKey)
    : 'oidc';
  return {
    provider_key: key,
    display_name: p.display_name,
    client_id: p.client_id,
    client_secret: '', // write-only: blank keeps the stored secret
    issuer: p.issuer,
    discovery_url: p.discovery_url ?? '',
    scopes: p.scopes,
    domain: p.domain ?? '',
    enabled: p.enabled,
  };
}

/** Read the `{ error }` / `{ error, details }` envelope from a failed response. */
async function readError(res: Response, fallback: string): Promise<string> {
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

const NATIVE_SELECT_CLASS =
  'h-7 w-full min-w-0 rounded-md border border-input bg-input/20 px-2 text-sm transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-50';

/**
 * Identity providers (SSO / OIDC) admin — WC-7b3d9f2c.
 *
 * Configure "Sign in with …" providers. Gated on `auth_providers:manage`. The
 * API is tenant-scoped: the system-tenant superuser manages the operator/global
 * provider; a regular tenant admin manages that tenant's own — the same
 * endpoints serve both, scoped server-side by tenant context. The client secret
 * is write-only: we only ever show whether one is set (`has_secret`) and offer a
 * "replace secret" input; on edit the field is omitted to keep the stored value.
 */
export default function SsoProvidersPage() {
  const { addToast } = useToast();
  const { user } = useAuth();
  const { hasPermission, loading: capsLoading } = useCapabilities();
  const t = useTranslation('admin');

  const canManage = hasPermission(AUTH_PROVIDERS_MANAGE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  // Read the browser origin lazily (only used once providers load or the form
  // opens — both client-driven — so it never renders during SSR/hydration).
  const [origin] = useState(() => (typeof window !== 'undefined' ? window.location.origin : ''));

  const [editing, setEditing] = useState<IdentityProvider | null>(null);
  const [adding, setAdding] = useState(false);
  const [pendingDelete, setPendingDelete] = useState<number | null>(null);

  // Load the tenant-scoped provider list (server authorizes by cookie regardless
  // of the client capability check below). `refetch` re-runs it after mutations.
  const {
    data: providers,
    error: loadError,
    refetch,
  } = useFetch<IdentityProvider[]>(async () => {
    const res = await apiClient('/api/v1/identity-providers');
    if (!res.ok) {
      throw new Error(
        await readError(res, t('settings.sso.error.load', 'Failed to load identity providers'))
      );
    }
    const body: unknown = await res.json();
    return body && typeof body === 'object' && Array.isArray((body as { data?: unknown }).data)
      ? ((body as { data: IdentityProvider[] }).data)
      : [];
  }, []);

  const redirectUriFor = (providerKey: string): string =>
    `${origin}/api/v1/auth/sso/${providerKey}/callback`;

  if (capsLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
      </div>
    );
  }

  if (!canManage) {
    return (
      <AccessDenied
        description={t(
          'settings.sso.accessDenied',
          'You need the {permission} permission to configure single sign-on.',
          { permission: AUTH_PROVIDERS_MANAGE }
        )}
        action={
          <Button onClick={() => window.history.back()} variant="outline">
            {t('settings.sso.goBack', 'Go Back')}
          </Button>
        }
      />
    );
  }

  const closeForm = () => {
    setAdding(false);
    setEditing(null);
  };

  const handleDelete = async (id: number) => {
    const res = await apiClient(`/api/v1/identity-providers/${id}`, { method: 'DELETE' });
    if (!res.ok && res.status !== 204) {
      addToast(
        await readError(res, t('settings.sso.error.delete', 'Failed to delete provider')),
        'error'
      );
      return;
    }
    addToast(t('settings.sso.toast.deleted', 'Identity provider deleted.'), 'success');
    setPendingDelete(null);
    if (editing?.id === id) closeForm();
    refetch();
  };

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title={t('settings.sso.title', 'Single sign-on')}
        description={
          isSystemTenant
            ? t(
                'settings.sso.description.system',
                'Configure the operator (global) identity providers offered on every workspace that has not set its own.'
              )
            : t(
                'settings.sso.description.tenant',
                "Configure the identity providers your workspace's members can sign in with."
              )
        }
        action={
          !adding && !editing ? (
            <Button
              className="gap-2"
              data-testid="sso-add-provider"
              onClick={() => {
                setEditing(null);
                setAdding(true);
              }}
            >
              <IconPlus className="w-4 h-4" />
              {t('settings.sso.addProvider', 'Add provider')}
            </Button>
          ) : undefined
        }
      />
      <SettingsTabs active="sso" />

      {isSystemTenant && <SsoMasterToggle addToast={addToast} />}

      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <IconShieldLock className="w-4 h-4 text-primary" aria-hidden="true" />
        <span>
          {t('settings.sso.scope.label', 'Scope:')}{' '}
          <span className="font-medium text-foreground">
            {isSystemTenant
              ? t('settings.sso.scope.operator', 'Operator (global)')
              : t('settings.sso.scope.workspace', 'This workspace')}
          </span>
        </span>
      </div>

      {(adding || editing) && (
        <ProviderFormCard
          key={editing?.id ?? 'new'}
          initial={editing ? formForEdit(editing) : emptyForm()}
          editing={editing}
          redirectUriFor={redirectUriFor}
          onCancel={closeForm}
          onSaved={() => {
            closeForm();
            refetch();
          }}
          addToast={addToast}
        />
      )}

      {loadError && (
        <Alert>{loadError}</Alert>
      )}

      {providers === null ? (
        <div className="space-y-3">
          {[0, 1].map((i) => (
            <div key={i} className="h-24 animate-pulse rounded-lg bg-muted/40" />
          ))}
        </div>
      ) : providers.length === 0 && !adding && !editing ? (
        <Card className="border border-dashed border-border bg-card/50">
          <CardContent className="flex flex-col items-center gap-2 py-10 text-center">
            <IconShieldLock className="w-8 h-8 text-muted-foreground" aria-hidden="true" />
            <p className="text-sm text-muted-foreground">
              {t(
                'settings.sso.empty',
                'No identity providers configured yet. Add one to offer “Sign in with…” on the login screen.'
              )}
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-3">
          {providers.map((p) => (
            <Card
              key={p.id}
              data-testid={`sso-provider-${p.id}`}
              className="border border-border bg-card shadow-sm"
            >
              <CardContent className="flex flex-col gap-4 py-4 md:flex-row md:items-start md:justify-between">
                <div className="min-w-0 space-y-1.5">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium text-foreground">{p.display_name}</span>
                    <Badge variant="secondary" className="text-[10px] uppercase">
                      {p.provider_key}
                    </Badge>
                    <Badge
                      data-testid={`sso-status-${p.id}`}
                      variant={p.enabled ? 'default' : 'outline'}
                      className="text-[10px]"
                    >
                      {p.enabled
                        ? t('settings.sso.status.enabled', 'Enabled')
                        : t('settings.sso.status.disabled', 'Disabled')}
                    </Badge>
                    <Badge variant={p.has_secret ? 'secondary' : 'destructive'} className="text-[10px]">
                      {p.has_secret
                        ? t('settings.sso.secret.set', 'Secret set')
                        : t('settings.sso.secret.none', 'No secret')}
                    </Badge>
                  </div>
                  <p className="truncate text-xs text-muted-foreground">
                    <span className="text-foreground/70">
                      {t('settings.sso.provider.issuer.label', 'Issuer:')}
                    </span>{' '}
                    {p.issuer}
                  </p>
                  <RedirectUri uri={redirectUriFor(p.provider_key)} addToast={addToast} />
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  {pendingDelete === p.id ? (
                    <>
                      <span className="text-xs text-muted-foreground">
                        {t('settings.sso.delete.confirm', 'Delete?')}
                      </span>
                      <Button
                        variant="destructive"
                        size="sm"
                        data-testid={`sso-confirm-delete-${p.id}`}
                        onClick={() => void handleDelete(p.id)}
                      >
                        {t('settings.sso.delete.confirmYes', 'Yes, delete')}
                      </Button>
                      <Button variant="ghost" size="sm" onClick={() => setPendingDelete(null)}>
                        {t('settings.sso.delete.cancel', 'Cancel')}
                      </Button>
                    </>
                  ) : (
                    <>
                      <Button
                        variant="outline"
                        size="sm"
                        className="gap-1"
                        data-testid={`sso-edit-${p.id}`}
                        onClick={() => {
                          setAdding(false);
                          setEditing(p);
                        }}
                      >
                        <IconEdit className="w-3.5 h-3.5" />
                        {t('settings.sso.provider.edit', 'Edit')}
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        className="gap-1 text-destructive"
                        data-testid={`sso-delete-${p.id}`}
                        onClick={() => setPendingDelete(p.id)}
                      >
                        <IconTrash className="w-3.5 h-3.5" />
                        {t('settings.sso.provider.delete', 'Delete')}
                      </Button>
                    </>
                  )}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}

const SSO_ENABLED_KEY = 'auth.sso_enabled';

/**
 * The instance-wide "is SSO on at all" switch (formerly Global Settings'
 * "Sign-in & SSO" section). System-tenant only — when off, every configured
 * identity provider is disabled instance-wide regardless of its own
 * `enabled` flag, so it lives right above the provider list it gates.
 */
function SsoMasterToggle({ addToast }: { addToast: ReturnType<typeof useToast>['addToast'] }) {
  const t = useTranslation('admin');

  const { data, error, refetch } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings/global');
    if (body === undefined) {
      throw new Error(
        errorMessage(
          getError,
          t('settings.sso.masterToggle.error.load', 'Failed to load the SSO master switch')
        )
      );
    }
    return body.data;
  }, []);

  const [saving, setSaving] = useState(false);

  const global = data?.global as SettingsMap | undefined;
  const registry = (data?.registry ?? []) as RegistryEntry[];
  const entry = registry.find((e) => e.key === SSO_ENABLED_KEY);
  const value = global?.[SSO_ENABLED_KEY] ?? entry?.default ?? 'false';

  if (error) {
    return null; // Non-fatal: the provider list below still works.
  }
  if (!entry) {
    return null; // Backend hasn't published this key yet.
  }

  const handleToggle = async (next: string) => {
    setSaving(true);
    try {
      const { error: patchError } = await api.PATCH('/api/v1/settings/global', {
        body: { settings: { [SSO_ENABLED_KEY]: next } },
      });
      if (patchError) {
        throw new Error(
          errorMessage(patchError, t('settings.sso.masterToggle.error.save', 'Failed to save'))
        );
      }
      addToast(
        next === 'true'
          ? t('settings.sso.masterToggle.toast.enabled', 'Single sign-on enabled.')
          : t('settings.sso.masterToggle.toast.disabled', 'Single sign-on disabled.'),
        'success'
      );
      refetch();
    } catch (err) {
      addToast(
        err instanceof Error
          ? err.message
          : t('settings.sso.masterToggle.error.save', 'Failed to save'),
        'error'
      );
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card className="border border-border bg-card shadow-sm" data-testid="sso-master-toggle-card">
      <CardContent className="pt-6">
        <RegistrySettingControl
          entry={entry}
          idPrefix="sso-master"
          value={value}
          disabled={saving}
          onChange={(v) => void handleToggle(v)}
        />
      </CardContent>
    </Card>
  );
}

/** A small inline alert used for the load-error state (token-styled). */
function Alert({ children }: { children: React.ReactNode }) {
  return (
    <div
      role="alert"
      className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"
    >
      <IconAlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
      <span>{children}</span>
    </div>
  );
}

/** The read-only redirect URI with a copy-to-clipboard affordance. */
function RedirectUri({
  uri,
  addToast,
}: {
  uri: string;
  addToast: (m: string, t: 'success' | 'error' | 'info' | 'warning') => void;
}) {
  const t = useTranslation('admin');

  return (
    <div className="flex items-center gap-2">
      <code className="min-w-0 truncate rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
        {uri}
      </code>
      <Button
        type="button"
        variant="ghost"
        size="icon-xs"
        aria-label={t('settings.sso.redirectUri.copy', 'Copy redirect URI')}
        onClick={() => {
          void navigator.clipboard?.writeText(uri).then(
            () => addToast(t('settings.sso.redirectUri.copied', 'Redirect URI copied.'), 'success'),
            () =>
              addToast(
                t('settings.sso.redirectUri.copyFailed', 'Could not copy to clipboard.'),
                'error'
              )
          );
        }}
      >
        <IconCopy className="h-3.5 w-3.5" />
      </Button>
    </div>
  );
}

function ProviderFormCard({
  initial,
  editing,
  redirectUriFor,
  onCancel,
  onSaved,
  addToast,
}: {
  initial: ProviderForm;
  editing: IdentityProvider | null;
  redirectUriFor: (key: string) => string;
  onCancel: () => void;
  onSaved: () => void;
  addToast: (m: string, t: 'success' | 'error' | 'info' | 'warning') => void;
}) {
  const t = useTranslation('admin');
  const [form, setForm] = useState<ProviderForm>(initial);
  const [saving, setSaving] = useState(false);

  const set = <K extends keyof ProviderForm>(key: K, value: ProviderForm[K]) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  // When adding, changing the provider preset also fills sensible issuer/name
  // defaults — but only while those fields still hold the previous preset value,
  // so an operator's edits are never clobbered.
  const onProviderKeyChange = (nextKey: ProviderKey) => {
    setForm((prev) => {
      const next = { ...prev, provider_key: nextKey };
      if (!editing) {
        if (prev.display_name === '' || prev.display_name === PROVIDER_LABELS[prev.provider_key]) {
          next.display_name = PROVIDER_LABELS[nextKey];
        }
        if (prev.issuer === '' || prev.issuer === ISSUER_SUGGESTIONS[prev.provider_key]) {
          next.issuer = ISSUER_SUGGESTIONS[nextKey];
        }
      }
      return next;
    });
  };

  const submit = async () => {
    if (form.display_name.trim() === '' || form.client_id.trim() === '' || form.issuer.trim() === '') {
      addToast(
        t('settings.sso.form.error.required', 'Display name, client ID and issuer are required.'),
        'error'
      );
      return;
    }
    if (!editing && form.client_secret.trim() === '') {
      addToast(
        t(
          'settings.sso.form.error.secretRequired',
          'A client secret is required when adding a provider.'
        ),
        'error'
      );
      return;
    }

    // Build the body. On edit, OMIT client_secret unless the operator typed a
    // replacement — an empty field keeps the stored secret.
    const body: Record<string, string | boolean> = {
      provider_key: form.provider_key,
      display_name: form.display_name.trim(),
      client_id: form.client_id.trim(),
      issuer: form.issuer.trim(),
      discovery_url: form.discovery_url.trim(),
      scopes: form.scopes.trim() || DEFAULT_SCOPES,
      domain: form.domain.trim(),
      enabled: form.enabled,
    };
    if (form.client_secret.trim() !== '') {
      body.client_secret = form.client_secret;
    }

    setSaving(true);
    try {
      const res = editing
        ? await apiClient(`/api/v1/identity-providers/${editing.id}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
          })
        : await apiClient('/api/v1/identity-providers', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
          });
      if (!res.ok) {
        addToast(
          await readError(res, t('settings.sso.form.error.save', 'Failed to save provider')),
          'error'
        );
        return;
      }
      addToast(
        editing
          ? t('settings.sso.form.toast.updated', 'Identity provider updated.')
          : t('settings.sso.form.toast.added', 'Identity provider added.'),
        'success'
      );
      onSaved();
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card className="border border-primary/30 bg-card shadow-sm" data-testid="sso-form">
      <CardHeader>
        <CardTitle className="text-lg font-bold font-heading">
          <h2>
            {editing
              ? t('settings.sso.form.editTitle', 'Edit {name}', { name: editing.display_name })
              : t('settings.sso.form.addTitle', 'Add identity provider')}
          </h2>
        </CardTitle>
        <CardDescription className="text-sm">
          {t(
            'settings.sso.form.description',
            'Register this exact redirect URI with the provider’s OAuth client.'
          )}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-5">
        <Field label={t('settings.sso.form.provider.label', 'Provider')} htmlFor="sso-provider-key">
          <select
            id="sso-provider-key"
            className={NATIVE_SELECT_CLASS}
            value={form.provider_key}
            disabled={saving}
            onChange={(e) => onProviderKeyChange(e.target.value as ProviderKey)}
          >
            {PROVIDER_KEYS.map((k) => (
              <option key={k} value={k}>
                {PROVIDER_LABELS[k]}
              </option>
            ))}
          </select>
        </Field>

        <div className="space-y-1.5">
          <span className="text-sm font-medium text-foreground">
            {t('settings.sso.form.redirectUri.label', 'Redirect URI')}
          </span>
          <RedirectUri uri={redirectUriFor(form.provider_key)} addToast={addToast} />
        </div>

        <Field
          label={t('settings.sso.form.displayName.label', 'Display name')}
          htmlFor="sso-display-name"
        >
          <Input
            id="sso-display-name"
            value={form.display_name}
            disabled={saving}
            onChange={(e) => set('display_name', e.target.value)}
          />
        </Field>

        <Field label={t('settings.sso.form.clientId.label', 'Client ID')} htmlFor="sso-client-id">
          <Input
            id="sso-client-id"
            value={form.client_id}
            disabled={saving}
            onChange={(e) => set('client_id', e.target.value)}
          />
        </Field>

        <Field
          label={t('settings.sso.form.clientSecret.label', 'Client secret')}
          htmlFor="sso-client-secret"
          hint={
            editing
              ? editing.has_secret
                ? t(
                    'settings.sso.form.clientSecret.hint.set',
                    'A secret is set. Leave blank to keep it, or type a new one to replace it.'
                  )
                : t(
                    'settings.sso.form.clientSecret.hint.unset',
                    'No secret set. Enter one to enable this provider.'
                  )
              : t(
                  'settings.sso.form.clientSecret.hint.new',
                  'Encrypted at rest; never shown again after saving.'
                )
          }
        >
          <Input
            id="sso-client-secret"
            type="password"
            autoComplete="new-password"
            placeholder={
              editing && editing.has_secret
                ? t('settings.sso.form.clientSecret.placeholder', '•••••••• (unchanged)')
                : ''
            }
            value={form.client_secret}
            disabled={saving}
            onChange={(e) => set('client_secret', e.target.value)}
          />
        </Field>

        <Field
          label={t('settings.sso.form.issuer.label', 'Issuer')}
          htmlFor="sso-issuer"
          hint={t('settings.sso.form.issuer.hint', 'https URL, e.g. https://accounts.google.com')}
        >
          <Input
            id="sso-issuer"
            type="url"
            value={form.issuer}
            disabled={saving}
            onChange={(e) => set('issuer', e.target.value)}
          />
        </Field>

        <Field
          label={t('settings.sso.form.discoveryUrl.label', 'Discovery URL')}
          htmlFor="sso-discovery-url"
          hint={t(
            'settings.sso.form.discoveryUrl.hint',
            'Optional. Defaults to <issuer>/.well-known/openid-configuration'
          )}
        >
          <Input
            id="sso-discovery-url"
            type="url"
            value={form.discovery_url}
            disabled={saving}
            onChange={(e) => set('discovery_url', e.target.value)}
          />
        </Field>

        <Field
          label={t('settings.sso.form.scopes.label', 'Scopes')}
          htmlFor="sso-scopes"
          hint={t('settings.sso.form.scopes.hint', 'Space-separated OAuth scopes.')}
        >
          <Input
            id="sso-scopes"
            value={form.scopes}
            disabled={saving}
            onChange={(e) => set('scopes', e.target.value)}
          />
        </Field>

        <Field
          label={t('settings.sso.form.domain.label', 'Domain hint')}
          htmlFor="sso-domain"
          hint={t('settings.sso.form.domain.hint', 'Optional email domain hint.')}
        >
          <Input
            id="sso-domain"
            value={form.domain}
            disabled={saving}
            onChange={(e) => set('domain', e.target.value)}
          />
        </Field>

        <div className="flex items-start justify-between gap-4 rounded-lg border border-border bg-muted/20 p-4">
          <div className="space-y-0.5">
            <label htmlFor="sso-enabled" className="text-sm font-medium text-foreground">
              {t('settings.sso.form.enabled.label', 'Enabled')}
            </label>
            <p className="text-xs text-muted-foreground">
              {t(
                'settings.sso.form.enabled.help',
                'When on, a “Sign in with {name}” button appears on the login screen.',
                {
                  name:
                    form.display_name ||
                    t('settings.sso.form.enabled.thisProvider', 'this provider'),
                }
              )}
            </p>
          </div>
          <Switch
            id="sso-enabled"
            data-testid="sso-enabled-switch"
            checked={form.enabled}
            disabled={saving}
            onCheckedChange={(v) => set('enabled', v)}
          />
        </div>

        <div className="flex justify-end gap-2 pt-2">
          <Button variant="ghost" onClick={onCancel} disabled={saving}>
            {t('settings.sso.form.cancel', 'Cancel')}
          </Button>
          <Button onClick={() => void submit()} disabled={saving} data-testid="sso-save-provider">
            {saving
              ? t('settings.sso.form.saving', 'Saving…')
              : editing
                ? t('settings.sso.form.save', 'Save changes')
                : t('settings.sso.form.add', 'Add provider')}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function Field({
  label,
  htmlFor,
  hint,
  children,
}: {
  label: string;
  htmlFor: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={htmlFor} className="text-sm font-medium text-foreground">
        {label}
      </label>
      {children}
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}
