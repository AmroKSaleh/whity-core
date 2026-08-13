'use client';

import { useState } from 'react';
import { apiClient } from '@/lib/api-client';
import { useToast } from '@/lib/toast-context';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Input } from '@/components/ui/input';
import { Switch } from '@amroksaleh/ui/switch';
import { Badge } from '@amroksaleh/ui/badge';
import { AccessDenied } from '@amroksaleh/ui/access-denied';
import {
  IconAlertCircle,
  IconCircleCheck,
  IconCopy,
  IconPlus,
  IconTrash,
  IconWorld,
} from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { SettingsTabs } from '../settings-tabs';

const NATIVE_SELECT_CLASS =
  'h-7 w-full min-w-0 rounded-md border border-input bg-input/20 px-2 text-sm transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-50';

interface DomainVerification {
  record_name: string;
  record_type: string;
  record_value: string;
}

/** Mirrors TenantEmailDomainsRepository::normalizeRow()'s public shape. */
interface EmailDomain {
  id: number;
  tenant_id: number;
  domain: string;
  default_role_id: number;
  auto_provision: boolean;
  verified_at: string | null;
  is_verified: boolean;
  created_at: string;
  verification?: DomainVerification;
}

interface Role {
  id: number;
  name: string;
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

/**
 * Tenant email-domain policy admin — WC-ac35b6cf.
 *
 * Manage which email domains auto-provision/auto-accept memberships into this
 * tenant (TenantEmailDomainApiHandler, gated on the `admin` ROLE — not a
 * permission, hence the client-side `user.role` check below rather than
 * useCapabilities()). A registered domain never auto-provisions until the
 * tenant proves ownership via the DNS TXT challenge; each domain's own
 * `auto_provision` toggle is the closest backend-driven equivalent to a
 * "require domain match" policy — it decides whether a newly-verified email
 * on that domain auto-joins this tenant or must be handled manually.
 */
export default function EmailDomainsPage() {
  const { addToast } = useToast();
  const { user } = useAuth();
  const t = useTranslation('admin');
  const isAdmin = user?.role === 'admin';

  const [addingOpen, setAddingOpen] = useState(false);
  const [pendingDelete, setPendingDelete] = useState<number | null>(null);

  const {
    data: domains,
    error: loadError,
    refetch,
  } = useFetch<EmailDomain[]>(async () => {
    const res = await apiClient('/api/v1/email-domains');
    if (!res.ok) {
      throw new Error(
        await readError(res, t('settings.emailDomains.loadFailed', 'Failed to load email domains'))
      );
    }
    const body: unknown = await res.json();
    return body && typeof body === 'object' && Array.isArray((body as { data?: unknown }).data)
      ? (body as { data: EmailDomain[] }).data
      : [];
  }, []);

  const { data: roles } = useFetch<Role[]>(async () => {
    const res = await apiClient('/api/v1/roles?per_page=100');
    if (!res.ok) {
      return [];
    }
    const body: unknown = await res.json();
    return body && typeof body === 'object' && Array.isArray((body as { data?: unknown }).data)
      ? (body as { data: Role[] }).data
      : [];
  }, []);

  if (!isAdmin) {
    return (
      <AccessDenied
        description={t(
          'settings.emailDomains.accessDenied',
          'You need the tenant {role} role to manage email-domain policies.',
          { role: 'admin' }
        )}
        action={
          <Button onClick={() => window.history.back()} variant="outline">
            {t('settings.emailDomains.goBack', 'Go Back')}
          </Button>
        }
      />
    );
  }

  const handleVerify = async (domain: EmailDomain) => {
    const res = await apiClient(`/api/v1/email-domains/${domain.id}/verify`, { method: 'POST' });
    const body: unknown = await res.json().catch(() => ({}));
    if (!res.ok) {
      // 422 still returns a body with the challenge instructions — refresh so
      // the card shows the (possibly regenerated) TXT record instead of just
      // toasting an error and leaving the admin stuck.
      addToast(
        body && typeof body === 'object' && 'error' in body
          ? String((body as { error: unknown }).error)
          : t('settings.emailDomains.verify.notYet', 'Domain ownership not verified yet.'),
        'error'
      );
      refetch();
      return;
    }
    addToast(t('settings.emailDomains.verify.success', 'Domain ownership verified.'), 'success');
    refetch();
  };

  const handleDelete = async (id: number) => {
    const res = await apiClient(`/api/v1/email-domains/${id}`, { method: 'DELETE' });
    if (!res.ok && res.status !== 204) {
      addToast(
        await readError(res, t('settings.emailDomains.delete.failed', 'Failed to remove domain')),
        'error'
      );
      return;
    }
    addToast(t('settings.emailDomains.delete.success', 'Domain registration removed.'), 'success');
    setPendingDelete(null);
    refetch();
  };

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title={t('settings.emailDomains.title', 'Email domains')}
        description={t(
          'settings.emailDomains.description',
          'Manage which email domains automatically provision memberships into your workspace.'
        )}
        action={
          !addingOpen ? (
            <Button className="gap-2" data-testid="email-domains-add" onClick={() => setAddingOpen(true)}>
              <IconPlus className="w-4 h-4" />
              {t('settings.emailDomains.add', 'Add domain')}
            </Button>
          ) : undefined
        }
      />
      <SettingsTabs active="email-domains" />

      {addingOpen && (
        <AddDomainCard
          roles={roles ?? []}
          onCancel={() => setAddingOpen(false)}
          onSaved={() => {
            setAddingOpen(false);
            refetch();
          }}
          addToast={addToast}
        />
      )}

      {loadError && <Alert>{loadError}</Alert>}

      {domains === null ? (
        <div className="space-y-3">
          {[0, 1].map((i) => (
            <div key={i} className="h-24 animate-pulse rounded-lg bg-muted/40" />
          ))}
        </div>
      ) : domains.length === 0 && !addingOpen ? (
        <Card className="border border-dashed border-border bg-card/50">
          <CardContent className="flex flex-col items-center gap-2 py-10 text-center">
            <IconWorld className="w-8 h-8 text-muted-foreground" aria-hidden="true" />
            <p className="text-sm text-muted-foreground">
              {t(
                'settings.emailDomains.empty',
                'No email domains registered yet. Add one so members with a verified address on that domain can automatically join your workspace.'
              )}
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-3">
          {domains.map((d) => (
            <Card key={d.id} data-testid={`email-domain-${d.id}`} className="border border-border bg-card shadow-sm">
              <CardContent className="flex flex-col gap-4 py-4 md:flex-row md:items-start md:justify-between">
                <div className="min-w-0 space-y-1.5">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium text-foreground">{d.domain}</span>
                    <Badge
                      data-testid={`email-domain-status-${d.id}`}
                      variant={d.is_verified ? 'default' : 'outline'}
                      className="text-[10px]"
                    >
                      {d.is_verified
                        ? t('settings.emailDomains.status.verified', 'Verified')
                        : t('settings.emailDomains.status.pending', 'Pending verification')}
                    </Badge>
                    <Badge variant="secondary" className="text-[10px]">
                      {d.auto_provision
                        ? t('settings.emailDomains.autoProvision.on', 'Auto-provision on')
                        : t('settings.emailDomains.autoProvision.off', 'Auto-provision off')}
                    </Badge>
                  </div>
                  {!d.is_verified && d.verification && (
                    <div className="space-y-1.5 rounded-md border border-border bg-muted/20 p-3">
                      <p className="text-xs text-muted-foreground">
                        {t(
                          'settings.emailDomains.challenge.instructions',
                          'Publish this TXT record, then verify:'
                        )}
                      </p>
                      <ChallengeRow
                        label={t('settings.emailDomains.challenge.name', 'Name')}
                        value={d.verification.record_name}
                        addToast={addToast}
                      />
                      <ChallengeRow
                        label={t('settings.emailDomains.challenge.value', 'Value')}
                        value={d.verification.record_value}
                        addToast={addToast}
                      />
                    </div>
                  )}
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  {pendingDelete === d.id ? (
                    <>
                      <span className="text-xs text-muted-foreground">
                        {t('settings.emailDomains.delete.confirm', 'Delete?')}
                      </span>
                      <Button
                        variant="destructive"
                        size="sm"
                        data-testid={`email-domain-confirm-delete-${d.id}`}
                        onClick={() => void handleDelete(d.id)}
                      >
                        {t('settings.emailDomains.delete.confirmYes', 'Yes, delete')}
                      </Button>
                      <Button variant="ghost" size="sm" onClick={() => setPendingDelete(null)}>
                        {t('settings.emailDomains.delete.confirmCancel', 'Cancel')}
                      </Button>
                    </>
                  ) : (
                    <>
                      {!d.is_verified && (
                        <Button
                          variant="outline"
                          size="sm"
                          className="gap-1"
                          data-testid={`email-domain-verify-${d.id}`}
                          onClick={() => void handleVerify(d)}
                        >
                          <IconCircleCheck className="w-3.5 h-3.5" />
                          {t('settings.emailDomains.verify.action', 'Verify')}
                        </Button>
                      )}
                      <Button
                        variant="ghost"
                        size="sm"
                        className="gap-1 text-destructive"
                        data-testid={`email-domain-delete-${d.id}`}
                        onClick={() => setPendingDelete(d.id)}
                      >
                        <IconTrash className="w-3.5 h-3.5" />
                        {t('settings.emailDomains.delete.action', 'Delete')}
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

function ChallengeRow({
  label,
  value,
  addToast,
}: {
  label: string;
  value: string;
  addToast: (m: string, t: 'success' | 'error' | 'info' | 'warning') => void;
}) {
  const t = useTranslation('admin');

  return (
    <div className="flex items-center gap-2">
      <span className="w-12 shrink-0 text-xs font-medium text-foreground">{label}</span>
      <code className="min-w-0 flex-1 truncate rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
        {value}
      </code>
      <Button
        type="button"
        variant="ghost"
        size="icon-xs"
        aria-label={t('settings.emailDomains.challenge.copy', 'Copy {label}', { label })}
        onClick={() => {
          void navigator.clipboard?.writeText(value).then(
            () => addToast(t('settings.emailDomains.challenge.copied', 'Copied.'), 'success'),
            () =>
              addToast(
                t('settings.emailDomains.challenge.copyFailed', 'Could not copy to clipboard.'),
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

function AddDomainCard({
  roles,
  onCancel,
  onSaved,
  addToast,
}: {
  roles: Role[];
  onCancel: () => void;
  onSaved: () => void;
  addToast: (m: string, t: 'success' | 'error' | 'info' | 'warning') => void;
}) {
  const t = useTranslation('admin');
  const [domain, setDomain] = useState('');
  const [defaultRoleId, setDefaultRoleId] = useState<number | ''>(roles[0]?.id ?? '');
  const [autoProvision, setAutoProvision] = useState(true);
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    if (domain.trim() === '') {
      addToast(t('settings.emailDomains.form.domainRequired', 'A domain is required.'), 'error');
      return;
    }
    if (defaultRoleId === '') {
      addToast(t('settings.emailDomains.form.roleRequired', 'A default role is required.'), 'error');
      return;
    }

    setSaving(true);
    try {
      const res = await apiClient('/api/v1/email-domains', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          domain: domain.trim(),
          default_role_id: defaultRoleId,
          auto_provision: autoProvision,
        }),
      });
      if (!res.ok) {
        addToast(
          await readError(res, t('settings.emailDomains.form.saveFailed', 'Failed to register domain')),
          'error'
        );
        return;
      }
      addToast(
        t(
          'settings.emailDomains.form.saved',
          'Domain registered — publish the TXT record to verify it.'
        ),
        'success'
      );
      onSaved();
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card className="border border-primary/30 bg-card shadow-sm" data-testid="email-domain-form">
      <CardHeader>
        <CardTitle className="text-lg font-bold font-heading">
          <h2>{t('settings.emailDomains.form.title', 'Add email domain')}</h2>
        </CardTitle>
        <CardDescription className="text-sm">
          {t(
            'settings.emailDomains.form.description',
            'Members who verify an email on this domain can automatically join your workspace once you’ve proven you control it.'
          )}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-5">
        <div className="space-y-1.5">
          <label htmlFor="domain-input" className="text-sm font-medium text-foreground">
            {t('settings.emailDomains.form.domain', 'Domain')}
          </label>
          {/* `example.com` is the reserved documentation domain, not prose — a
              translated local part would show a hostname that is not a valid
              example. Left literal, as the sign-in screen left its masks. */}
          <Input
            id="domain-input"
            placeholder="example.com"
            value={domain}
            disabled={saving}
            onChange={(e) => setDomain(e.target.value)}
          />
        </div>

        <div className="space-y-1.5">
          <label htmlFor="domain-default-role" className="text-sm font-medium text-foreground">
            {t('settings.emailDomains.form.defaultRole', 'Default role')}
          </label>
          <select
            id="domain-default-role"
            className={NATIVE_SELECT_CLASS}
            value={defaultRoleId}
            disabled={saving}
            onChange={(e) => setDefaultRoleId(Number(e.target.value))}
          >
            {roles.length === 0 && (
              <option value="">{t('settings.emailDomains.form.noRoles', 'No roles available')}</option>
            )}
            {roles.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </select>
          <p className="text-xs text-muted-foreground">
            {t(
              'settings.emailDomains.form.defaultRoleHelp',
              'Role assigned when a new member auto-joins via this domain.'
            )}
          </p>
        </div>

        <div className="flex items-start justify-between gap-4 rounded-lg border border-border bg-muted/20 p-4">
          <div className="space-y-0.5">
            <label htmlFor="domain-auto-provision" className="text-sm font-medium text-foreground">
              {t('settings.emailDomains.form.autoProvision', 'Auto-provision')}
            </label>
            <p className="text-xs text-muted-foreground">
              {t(
                'settings.emailDomains.form.autoProvisionHelp',
                'When on, a verified email on this domain automatically joins this workspace. When off, ownership is still tracked but nobody auto-joins.'
              )}
            </p>
          </div>
          <Switch
            id="domain-auto-provision"
            data-testid="email-domain-auto-provision-switch"
            checked={autoProvision}
            disabled={saving}
            onCheckedChange={setAutoProvision}
          />
        </div>

        <div className="flex justify-end gap-2 pt-2">
          <Button variant="ghost" onClick={onCancel} disabled={saving}>
            {t('settings.emailDomains.form.cancel', 'Cancel')}
          </Button>
          <Button onClick={() => void submit()} disabled={saving} data-testid="email-domain-save">
            {saving
              ? t('settings.emailDomains.form.saving', 'Saving…')
              : t('settings.emailDomains.form.submit', 'Add domain')}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
