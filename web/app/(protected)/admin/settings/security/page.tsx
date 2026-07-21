'use client';

import { useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@amroksaleh/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Input } from '@amroksaleh/ui/input';
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
        description={
          <>
            You need the <code>security:manage</code> permission to configure admin-enforced
            2FA policies.
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
        title="Security"
        description="Require two-factor authentication tenant-wide, for an organizational unit, or for a specific person."
      />
      <SettingsTabs active="security" />
      <TwoFactorPoliciesSection addToast={addToast} onPoliciesChanged={() => setStatusRefreshKey((k) => k + 1)} />
      <EnrollmentStatusSection refreshKey={statusRefreshKey} />
    </div>
  );
}

function scopeLabel(policy: TwoFactorPolicy, ous: OuOption[], users: UserOption[]): string {
  if (policy.scope_type === 'tenant') {
    return 'Everyone in this tenant';
  }
  if (policy.scope_type === 'ou') {
    const ou = ous.find((o) => o.id === policy.scope_id);
    return ou ? `${ou.name} (and its sub-units)` : `Organizational unit #${policy.scope_id}`;
  }
  const user = users.find((u) => u.id === policy.scope_id);
  return user ? user.email : `User #${policy.scope_id}`;
}

function TwoFactorPoliciesSection({
  addToast,
  onPoliciesChanged,
}: {
  addToast: AddToast;
  onPoliciesChanged: () => void;
}) {
  const [adding, setAdding] = useState(false);
  const [pendingDelete, setPendingDelete] = useState<number | null>(null);

  const {
    data: policies,
    error: policiesError,
    refetch: refetchPolicies,
  } = useFetch<TwoFactorPolicy[]>(async () => {
    const { data: body, error } = await api.GET('/api/v1/2fa-policies');
    if (body === undefined) {
      throw new Error(errorMessage(error, 'Failed to load 2FA policies'));
    }
    return body.data;
  }, []);

  const { data: ous } = useFetch<OuOption[]>(async () => {
    const { data: body } = await api.GET('/api/v1/ous');
    return body?.data ?? [];
  }, []);

  const { data: users } = useFetch<UserOption[]>(async () => {
    const { data: body } = await api.GET('/api/v1/users');
    return body?.data ?? [];
  }, []);

  const handleDelete = async (id: number) => {
    const { error, response } = await api.DELETE('/api/v1/2fa-policies/{id}', {
      params: { path: { id } },
    });
    if (error !== undefined || !response.ok) {
      addToast(errorMessage(error, 'Failed to delete policy'), 'error');
      return;
    }
    addToast('2FA policy removed.', 'success');
    setPendingDelete(null);
    refetchPolicies();
    onPoliciesChanged();
  };

  return (
    <Card className="border border-border bg-card shadow-sm" data-testid="two-factor-policies-card">
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="text-lg font-bold font-heading">
            <h2>2FA policies</h2>
          </CardTitle>
          <CardDescription className="text-sm">
            A profile in scope of any policy below must enroll in 2FA. During the grace
            period, login still succeeds with a reminder; once it elapses, login is refused
            until the person enrolls.
          </CardDescription>
        </div>
        {!adding && (
          <Button className="gap-2 shrink-0" data-testid="add-two-factor-policy" onClick={() => setAdding(true)}>
            <IconPlus className="w-4 h-4" />
            Add policy
          </Button>
        )}
      </CardHeader>
      <CardContent className="space-y-4">
        {adding && (
          <PolicyFormCard
            ous={ous ?? []}
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
              No 2FA policies configured. 2FA enrollment stays fully self-service until you add
              one.
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
                      {scopeLabel(policy, ous ?? [], users ?? [])}
                    </span>
                    <Badge variant="secondary" className="text-[10px] uppercase">
                      {policy.scope_type}
                    </Badge>
                  </div>
                  <p className="text-xs text-muted-foreground">
                    {policy.grace_period_days === 0
                      ? 'No grace period — enforced immediately.'
                      : `${policy.grace_period_days}-day grace period from the policy's creation.`}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  {pendingDelete === policy.id ? (
                    <>
                      <span className="text-xs text-muted-foreground">Remove?</span>
                      <Button
                        variant="destructive"
                        size="sm"
                        data-testid={`confirm-delete-policy-${policy.id}`}
                        onClick={() => void handleDelete(policy.id)}
                      >
                        Yes, remove
                      </Button>
                      <Button variant="ghost" size="sm" onClick={() => setPendingDelete(null)}>
                        Cancel
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
                      Remove
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
  users,
  onCancel,
  onSaved,
  addToast,
}: {
  ous: OuOption[];
  users: UserOption[];
  onCancel: () => void;
  onSaved: () => void;
  addToast: AddToast;
}) {
  const [scopeType, setScopeType] = useState<ScopeChoice>('tenant');
  const [scopeId, setScopeId] = useState<string>('');
  const [gracePeriodDays, setGracePeriodDays] = useState('0');
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    if (scopeType !== 'tenant' && scopeId === '') {
      addToast(
        scopeType === 'ou' ? 'Select an organizational unit.' : 'Select a user.',
        'error'
      );
      return;
    }
    const grace = Number.parseInt(gracePeriodDays, 10);
    if (!Number.isFinite(grace) || grace < 0) {
      addToast('Grace period must be a non-negative number of days.', 'error');
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
        throw new Error(errorMessage(error, 'Failed to create policy'));
      }
      addToast('2FA policy created.', 'success');
      onSaved();
    } catch (err) {
      addToast(err instanceof Error ? err.message : 'Failed to create policy', 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-4 rounded-lg border border-primary/30 bg-card p-4" data-testid="two-factor-policy-form">
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <label className="text-sm font-medium text-foreground">Applies to</label>
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
              <SelectItem value="tenant">Everyone in this tenant</SelectItem>
              <SelectItem value="ou">An organizational unit</SelectItem>
              <SelectItem value="user">A specific person</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-1.5">
          <label className="text-sm font-medium text-foreground">Grace period (days)</label>
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
          <label className="text-sm font-medium text-foreground">Organizational unit</label>
          <Select value={scopeId} onValueChange={setScopeId}>
            <SelectTrigger data-testid="policy-scope-ou">
              <SelectValue placeholder="Select an organizational unit" />
            </SelectTrigger>
            <SelectContent>
              {ous.map((ou) => (
                <SelectItem key={ou.id} value={String(ou.id)}>
                  {ou.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}

      {scopeType === 'user' && (
        <div className="space-y-1.5">
          <label className="text-sm font-medium text-foreground">Person</label>
          <Select value={scopeId} onValueChange={setScopeId}>
            <SelectTrigger data-testid="policy-scope-user">
              <SelectValue placeholder="Select a user" />
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
          Cancel
        </Button>
        <Button onClick={() => void submit()} disabled={saving} data-testid="save-two-factor-policy">
          {saving ? 'Saving…' : 'Add policy'}
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
  const { data, error } = useFetch<StatusEntry[]>(async () => {
    const { data: body, error: statusError } = await api.GET('/api/v1/2fa-policies/status');
    if (body === undefined) {
      throw new Error(errorMessage(statusError, 'Failed to load enrollment status'));
    }
    return body.data;
  }, [refreshKey]);

  const columns: DataTableColumn<StatusEntry>[] = [
    { id: 'email', accessorKey: 'email', header: 'Person', enableSorting: true },
    {
      id: 'enrolled',
      header: '2FA status',
      cell: (row) => (
        <Badge variant={row.enrolled ? 'default' : 'destructive'} className="text-[10px]">
          {row.enrolled ? 'Enrolled' : 'Not enrolled'}
        </Badge>
      ),
    },
    {
      id: 'enforcement_deadline',
      header: 'Enforcement deadline',
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
          <h2>Enrollment status</h2>
        </CardTitle>
        <CardDescription className="text-sm">
          Everyone any policy above covers, and whether they have enrolled in 2FA yet.
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
          globalFilterPlaceholder="Search by email…"
          pagination={{ pageSize: 10 }}
          emptyState={{
            title: 'No one in scope yet',
            description: 'Add a policy above to bring people into scope.',
          }}
        />
      </CardContent>
    </Card>
  );
}
