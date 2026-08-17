'use client';

import { useState } from 'react';
import { api } from '@/lib/api/client';
import { fetchAllPagesTyped } from '@/lib/api/fetch-all-pages';
import { useToast } from '@/lib/toast-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Input } from '@/components/ui/input';
import { Badge } from '@amroksaleh/ui/badge';
import { AccessDenied } from '@amroksaleh/ui/access-denied';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { IconPlus, IconShieldLock, IconTrash } from '@tabler/icons-react';
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';
import { SettingsTabs } from '../settings-tabs';
import { SECURITY_MANAGE, errorMessage, type AddToast } from '../settings-shared';
import type { components } from '@/lib/api/schema';

type TwoFactorPolicy = components['schemas']['TwoFactorPolicy'];
type StatusEntry = components['schemas']['TwoFactorPolicyStatusEntry'];
type OuOption = components['schemas']['OrganizationalUnit'];
type UserOption = components['schemas']['User'];

type ScopeChoice = 'tenant' | 'ou' | 'user';

/**
 * Admin-enforced 2FA policy (WC-525 PR-4): a tenant admin declares that 2FA
 * enrollment is required tenant-wide, for a specific organizational unit (and
 * everything beneath it), or for a specific user — with an optional grace
 * period before login starts being refused for the unenrolled. Enforcement
 * itself happens server-side at login (AuthHandler::issueSessionForProfile());
 * this page is purely the declaration + visibility surface.
 */
export default function SecurityPolicySettingsPage() {
  const { addToast } = useToast();
  const { hasPermission, loading: capsLoading } = useCapabilities();
  const t = useTranslation('admin');
  // Bumped whenever a policy is created/deleted so the enrollment-status
  // table (a sibling section with its own independent fetch) re-queries
  // instead of showing a stale "no one in scope" snapshot from before the
  // policy existed.
  const [statusRefreshKey, setStatusRefreshKey] = useState(0);

  const canManage = hasPermission(SECURITY_MANAGE);

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
          'settings.security.accessDenied',
          'You need the {permission} permission to configure admin-enforced 2FA policies.',
          { permission: SECURITY_MANAGE }
        )}
        action={
          <Button onClick={() => window.history.back()} variant="outline">
            {t('settings.security.goBack', 'Go Back')}
          </Button>
        }
      />
    );
  }

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title={t('settings.security.title', 'Security')}
        description={t(
          'settings.security.description',
          'Require two-factor authentication tenant-wide, for an organizational unit, or for a specific person.'
        )}
      />
      <SettingsTabs active="security" />
      <TwoFactorPoliciesSection addToast={addToast} onPoliciesChanged={() => setStatusRefreshKey((k) => k + 1)} />
      <EnrollmentStatusSection refreshKey={statusRefreshKey} />
    </div>
  );
}

function scopeLabel(
  policy: TwoFactorPolicy,
  ous: OuOption[],
  users: UserOption[],
  t: TranslateFn
): string {
  if (policy.scope_type === 'tenant') {
    return t('settings.security.scope.tenant', 'Everyone in this tenant');
  }
  if (policy.scope_type === 'ou') {
    const ou = ous.find((o) => o.id === policy.scope_id);
    return ou
      ? t('settings.security.scope.ou', '{name} (and its sub-units)', { name: ou.name })
      : t('settings.security.scope.ouFallback', 'Organizational unit #{id}', {
          id: String(policy.scope_id),
        });
  }
  const user = users.find((u) => u.id === policy.scope_id);
  return user
    ? user.email
    : t('settings.security.scope.userFallback', 'User #{id}', { id: String(policy.scope_id) });
}

function TwoFactorPoliciesSection({
  addToast,
  onPoliciesChanged,
}: {
  addToast: AddToast;
  onPoliciesChanged: () => void;
}) {
  const t = useTranslation('admin');
  const [adding, setAdding] = useState(false);
  const [pendingDelete, setPendingDelete] = useState<number | null>(null);

  const {
    data: policies,
    error: policiesError,
    refetch: refetchPolicies,
  } = useFetch<TwoFactorPolicy[]>(async () => {
    const { data: body, error } = await api.GET('/api/v1/2fa-policies');
    if (body === undefined) {
      throw new Error(
        errorMessage(error, t('settings.security.policies.loadError', 'Failed to load 2FA policies'))
      );
    }
    return body.data;
  }, []);

  // A 2FA policy scoped to an OU covers that unit and everything beneath it, so
  // the scope picker has to offer every unit — this endpoint is paginated, and a
  // unit on page 2 would look like one that does not exist. `ousError` is what
  // stops a short list being presented as the whole organization; the same list
  // also names the scope of existing policies below, which is why the failure is
  // surfaced rather than swallowed into an empty array as it used to be.
  const { data: ous, error: ousError } = useFetch<OuOption[]>(async () => {
    const result = await fetchAllPagesTyped<OuOption>((query) =>
      api.GET('/api/v1/ous', { params: { query } })
    );
    if (!result.complete) {
      throw new Error(
        result.total === null
          ? t('ous.error.load', 'Failed to fetch organizational units')
          : t(
              'ous.error.partial',
              'Loaded only {loaded} of {total} organizational units.',
              { loaded: result.items.length, total: result.total }
            )
      );
    }
    return result.items;
  }, [t]);

  const { data: users } = useFetch<UserOption[]>(async () => {
    const { data: body } = await api.GET('/api/v1/users');
    return body?.data ?? [];
  }, []);

  const handleDelete = async (id: number) => {
    const { error, response } = await api.DELETE('/api/v1/2fa-policies/{id}', {
      params: { path: { id } },
    });
    if (error !== undefined || !response.ok) {
      addToast(
        errorMessage(error, t('settings.security.policies.deleteError', 'Failed to delete policy')),
        'error'
      );
      return;
    }
    addToast(t('settings.security.policies.removed', '2FA policy removed.'), 'success');
    setPendingDelete(null);
    refetchPolicies();
    onPoliciesChanged();
  };

  return (
    <Card className="border border-border bg-card shadow-sm" data-testid="two-factor-policies-card">
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="text-lg font-bold font-heading">
            <h2>{t('settings.security.policies.title', '2FA policies')}</h2>
          </CardTitle>
          <CardDescription className="text-sm">
            {t(
              'settings.security.policies.description',
              'A profile in scope of any policy below must enroll in 2FA. During the grace period, login still succeeds with a reminder; once it elapses, login is refused until the person enrolls.'
            )}
          </CardDescription>
        </div>
        {!adding && (
          <Button className="gap-2 shrink-0" data-testid="add-two-factor-policy" onClick={() => setAdding(true)}>
            <IconPlus className="w-4 h-4" />
            {t('settings.security.policies.add', 'Add policy')}
          </Button>
        )}
      </CardHeader>
      <CardContent className="space-y-4">
        {adding && (
          <PolicyFormCard
            ous={ous ?? []}
            ousError={ousError}
            users={users ?? []}
            onCancel={() => setAdding(false)}
            onSaved={() => {
              setAdding(false);
              refetchPolicies();
              onPoliciesChanged();
            }}
            addToast={addToast}
          />
        )}

        {policiesError && (
          <p role="alert" className="text-sm text-destructive">
            {policiesError}
          </p>
        )}

        {policies === null ? (
          <div className="space-y-2">
            {[0, 1].map((i) => (
              <div key={i} className="h-16 animate-pulse rounded-lg bg-muted/40" />
            ))}
          </div>
        ) : policies.length === 0 ? (
          <div className="flex flex-col items-center gap-2 py-8 text-center">
            <IconShieldLock className="w-8 h-8 text-muted-foreground" aria-hidden="true" />
            <p className="text-sm text-muted-foreground">
              {t(
                'settings.security.policies.empty',
                'No 2FA policies configured. 2FA enrollment stays fully self-service until you add one.'
              )}
            </p>
          </div>
        ) : (
          <div className="space-y-2">
            {policies.map((policy) => (
              <div
                key={policy.id}
                data-testid={`two-factor-policy-${policy.id}`}
                className="flex flex-col gap-3 rounded-lg border border-border bg-muted/10 p-4 sm:flex-row sm:items-center sm:justify-between"
              >
                <div className="min-w-0 space-y-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium text-foreground">
                      {scopeLabel(policy, ous ?? [], users ?? [], t)}
                    </span>
                    <Badge variant="secondary" className="text-[10px] uppercase">
                      {policy.scope_type}
                    </Badge>
                  </div>
                  <p className="text-xs text-muted-foreground">
                    {policy.grace_period_days === 0
                      ? t(
                          'settings.security.policies.gracePeriod.none',
                          'No grace period — enforced immediately.'
                        )
                      : t(
                          'settings.security.policies.gracePeriod.days',
                          "{days}-day grace period from the policy's creation.",
                          { days: policy.grace_period_days }
                        )}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  {pendingDelete === policy.id ? (
                    <>
                      <span className="text-xs text-muted-foreground">
                        {t('settings.security.policies.confirmRemove', 'Remove?')}
                      </span>
                      <Button
                        variant="destructive"
                        size="sm"
                        data-testid={`confirm-delete-policy-${policy.id}`}
                        onClick={() => void handleDelete(policy.id)}
                      >
                        {t('settings.security.policies.confirmYes', 'Yes, remove')}
                      </Button>
                      <Button variant="ghost" size="sm" onClick={() => setPendingDelete(null)}>
                        {t('settings.security.policies.confirmCancel', 'Cancel')}
                      </Button>
                    </>
                  ) : (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="gap-1 text-destructive"
                      data-testid={`delete-policy-${policy.id}`}
                      onClick={() => setPendingDelete(policy.id)}
                    >
                      <IconTrash className="w-3.5 h-3.5" />
                      {t('settings.security.policies.remove', 'Remove')}
                    </Button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function PolicyFormCard({
  ous,
  ousError,
  users,
  onCancel,
  onSaved,
  addToast,
}: {
  ous: OuOption[];
  /** Non-null when the OU list is incomplete, which makes the OU scope unusable. */
  ousError: string | null;
  users: UserOption[];
  onCancel: () => void;
  onSaved: () => void;
  addToast: AddToast;
}) {
  const t = useTranslation('admin');
  const [scopeType, setScopeType] = useState<ScopeChoice>('tenant');
  const [scopeId, setScopeId] = useState<string>('');
  const [gracePeriodDays, setGracePeriodDays] = useState('0');
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    if (scopeType !== 'tenant' && scopeId === '') {
      addToast(
        scopeType === 'ou'
          ? t('settings.security.form.error.selectOu', 'Select an organizational unit.')
          : t('settings.security.form.error.selectUser', 'Select a user.'),
        'error'
      );
      return;
    }
    const grace = Number.parseInt(gracePeriodDays, 10);
    if (!Number.isFinite(grace) || grace < 0) {
      addToast(
        t(
          'settings.security.form.error.gracePeriod',
          'Grace period must be a non-negative number of days.'
        ),
        'error'
      );
      return;
    }

    setSaving(true);
    try {
      const { error, response } = await api.POST('/api/v1/2fa-policies', {
        body: {
          scope_type: scopeType,
          scope_id: scopeType === 'tenant' ? null : Number(scopeId),
          grace_period_days: grace,
        },
      });
      if (error !== undefined || !response.ok) {
        throw new Error(
          errorMessage(error, t('settings.security.form.createError', 'Failed to create policy'))
        );
      }
      addToast(t('settings.security.form.created', '2FA policy created.'), 'success');
      onSaved();
    } catch (err) {
      addToast(
        err instanceof Error
          ? err.message
          : t('settings.security.form.createError', 'Failed to create policy'),
        'error'
      );
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-4 rounded-lg border border-primary/30 bg-card p-4" data-testid="two-factor-policy-form">
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <label className="text-sm font-medium text-foreground">
            {t('settings.security.form.scopeType.label', 'Applies to')}
          </label>
          <Select
            value={scopeType}
            onValueChange={(value) => {
              setScopeType(value as ScopeChoice);
              setScopeId('');
            }}
          >
            <SelectTrigger data-testid="policy-scope-type">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="tenant">
                {t('settings.security.form.scopeType.tenant', 'Everyone in this tenant')}
              </SelectItem>
              <SelectItem value="ou">
                {t('settings.security.form.scopeType.ou', 'An organizational unit')}
              </SelectItem>
              <SelectItem value="user">
                {t('settings.security.form.scopeType.user', 'A specific person')}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-1.5">
          <label className="text-sm font-medium text-foreground">
            {t('settings.security.form.gracePeriod.label', 'Grace period (days)')}
          </label>
          <Input
            type="number"
            min={0}
            value={gracePeriodDays}
            disabled={saving}
            data-testid="policy-grace-period"
            onChange={(e) => setGracePeriodDays(e.target.value)}
          />
        </div>
      </div>

      {scopeType === 'ou' && (
        <div className="space-y-1.5">
          <label className="text-sm font-medium text-foreground">
            {t('settings.security.form.ou.label', 'Organizational unit')}
          </label>
          <Select value={scopeId} onValueChange={setScopeId} disabled={ousError !== null}>
            <SelectTrigger data-testid="policy-scope-ou">
              <SelectValue
                placeholder={t(
                  'settings.security.form.ou.placeholder',
                  'Select an organizational unit'
                )}
              />
            </SelectTrigger>
            <SelectContent>
              {ous.map((ou) => (
                <SelectItem key={ou.id} value={String(ou.id)}>
                  {ou.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {/*
            The scope option stays selectable so the reason is discoverable
            here, where the admin is looking for the missing unit, rather than
            as a greyed-out choice with no explanation. Submitting is already
            impossible while no unit is chosen, so an OU-scoped policy cannot be
            created against a list that is short — which would silently enforce
            2FA on the wrong part of the organization.
          */}
          {ousError !== null && (
            <p role="alert" className="text-sm text-destructive" data-testid="policy-scope-ou-error">
              {ousError}{' '}
              {t(
                'ous.error.pickerDisabled',
                'The picker is disabled because a partial list would hide units that exist.'
              )}
            </p>
          )}
        </div>
      )}

      {scopeType === 'user' && (
        <div className="space-y-1.5">
          <label className="text-sm font-medium text-foreground">
            {t('settings.security.form.user.label', 'Person')}
          </label>
          <Select value={scopeId} onValueChange={setScopeId}>
            <SelectTrigger data-testid="policy-scope-user">
              <SelectValue placeholder={t('settings.security.form.user.placeholder', 'Select a user')} />
            </SelectTrigger>
            <SelectContent>
              {users.map((user) => (
                <SelectItem key={user.id} value={String(user.id)}>
                  {user.email}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}

      <div className="flex justify-end gap-2 pt-2">
        <Button variant="ghost" onClick={onCancel} disabled={saving}>
          {t('settings.security.form.cancel', 'Cancel')}
        </Button>
        <Button onClick={() => void submit()} disabled={saving} data-testid="save-two-factor-policy">
          {saving
            ? t('settings.security.form.saving', 'Saving…')
            : t('settings.security.form.submit', 'Add policy')}
        </Button>
      </div>
    </div>
  );
}

function formatDeadline(epochSeconds: number | null): string {
  if (epochSeconds === null) {
    return '—';
  }
  return new Date(epochSeconds * 1000).toLocaleString();
}

function EnrollmentStatusSection({ refreshKey }: { refreshKey: number }) {
  const t = useTranslation('admin');
  const { data, error } = useFetch<StatusEntry[]>(async () => {
    const { data: body, error: statusError } = await api.GET('/api/v1/2fa-policies/status');
    if (body === undefined) {
      throw new Error(
        errorMessage(
          statusError,
          t('settings.security.status.loadError', 'Failed to load enrollment status')
        )
      );
    }
    return body.data;
  }, [refreshKey]);

  const columns: DataTableColumn<StatusEntry>[] = [
    {
      id: 'email',
      accessorKey: 'email',
      header: t('settings.security.status.column.person', 'Person'),
      enableSorting: true,
    },
    {
      id: 'enrolled',
      header: t('settings.security.status.column.enrolled', '2FA status'),
      cell: (row) => (
        <Badge variant={row.enrolled ? 'default' : 'destructive'} className="text-[10px]">
          {row.enrolled
            ? t('settings.security.status.enrolled', 'Enrolled')
            : t('settings.security.status.notEnrolled', 'Not enrolled')}
        </Badge>
      ),
    },
    {
      id: 'enforcement_deadline',
      header: t('settings.security.status.column.deadline', 'Enforcement deadline'),
      cell: (row) => (
        <span className="text-sm text-muted-foreground">
          {row.enrolled ? '—' : formatDeadline(row.enforcement_deadline)}
        </span>
      ),
    },
  ];

  return (
    <Card className="border border-border bg-card shadow-sm" data-testid="two-factor-status-card">
      <CardHeader>
        <CardTitle className="text-lg font-bold font-heading">
          <h2>{t('settings.security.status.title', 'Enrollment status')}</h2>
        </CardTitle>
        <CardDescription className="text-sm">
          {t(
            'settings.security.status.description',
            'Everyone any policy above covers, and whether they have enrolled in 2FA yet.'
          )}
        </CardDescription>
      </CardHeader>
      <CardContent>
        {error && (
          <p role="alert" className="mb-4 text-sm text-destructive">
            {error}
          </p>
        )}
        <DataTable
          columns={columns}
          data={data ?? []}
          getRowId={(row) => String(row.profile_id)}
          isLoading={data === null && !error}
          enableGlobalFilter
          globalFilterPlaceholder={t('settings.security.status.searchPlaceholder', 'Search by email…')}
          pagination={{ pageSize: 10 }}
          emptyState={{
            title: t('settings.security.status.empty.title', 'No one in scope yet'),
            description: t(
              'settings.security.status.empty.description',
              'Add a policy above to bring people into scope.'
            ),
          }}
        />
      </CardContent>
    </Card>
  );
}
