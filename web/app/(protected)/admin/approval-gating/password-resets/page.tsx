'use client';

import { useCallback, useEffect, useState } from 'react';
import { useToast } from '@/lib/toast-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { ApprovalGatingTabs } from '@/components/admin/approval-gating-tabs';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { AccessDenied } from '@amroksaleh/ui/access-denied';
import { IconCheck, IconInbox, IconX } from '@tabler/icons-react';

/**
 * Pending self-service password-reset requests
 * (WC-password-reset-2fa-recovery) — the "Password reset" tab of the unified
 * Approval Gating admin surface. Appears only when
 * `auth.password_reset_approval_required` is on for the operator/tenant;
 * otherwise a self-service reset always applies immediately and this queue
 * stays empty.
 *
 * UNLIKE the Signup tab (system-tenant only — see ../../registrations/page.tsx),
 * this is scoped to the CALLER'S OWN tenant: the requesting user's account and
 * its tenant admin already exist, so any tenant's admin reviews its own
 * tenant's queue. Gated on password_resets:approve only (no system-tenant
 * check), mirroring the backend (PasswordResetApprovalsApiHandler).
 *
 * Mirrors the Signup tab's structure/conventions (fetch via raw `fetch` + the
 * CSRF header, not the generated OpenAPI client, matching that page's existing
 * precedent for this admin-queue shape).
 */
const PASSWORD_RESETS_APPROVE = 'password_resets:approve';

interface PendingPasswordReset {
  id: number;
  profile_id: number;
  email: string;
  display_name: string;
  created_at: string;
}

export default function PasswordResetApprovalsPage() {
  const { addToast } = useToast();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();

  const canApprove = hasPermission(PASSWORD_RESETS_APPROVE);

  const [items, setItems] = useState<PendingPasswordReset[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(false);
    try {
      const res = await fetch('/api/v1/password-resets/pending', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'include',
      });
      if (!res.ok) {
        setLoadError(true);
        setItems([]);
        return;
      }
      const body = await res.json().catch(() => ({}));
      setItems(Array.isArray(body?.data) ? (body.data as PendingPasswordReset[]) : []);
    } catch {
      setLoadError(true);
      setItems([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!isCapabilitiesLoading && canApprove) {
      void Promise.resolve().then(load);
    }
  }, [isCapabilitiesLoading, canApprove, load]);

  const act = async (item: PendingPasswordReset, action: 'approve' | 'reject') => {
    setBusyId(item.id);
    try {
      const res = await fetch(`/api/v1/password-resets/${item.id}/${action}`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'include',
      });
      if (!res.ok) {
        addToast(`Failed to ${action} the request for ${item.email} (${res.status}).`, 'error');
        void load();
        return;
      }
      addToast(
        action === 'approve'
          ? `Approved the password reset for ${item.email}.`
          : `Rejected the password reset for ${item.email}.`,
        'success'
      );
      setItems((prev) => prev.filter((i) => i.id !== item.id));
    } catch {
      addToast(`Failed to ${action} the request for ${item.email}.`, 'error');
    } finally {
      setBusyId(null);
    }
  };

  if (isCapabilitiesLoading) {
    return (
      <div className="flex items-center justify-center min-h-100">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!canApprove) {
    return (
      <div className="space-y-6 max-w-4xl mx-auto px-4 md:px-0 pb-16">
        <ApprovalGatingTabs active="password-resets" />
        <AccessDenied
          data-testid="password-resets-access-denied"
          description="You don't have permission to review pending password-reset requests."
          action={
            <Button onClick={() => window.history.back()} variant="outline">
              Go Back
            </Button>
          }
        />
      </div>
    );
  }

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <ApprovalGatingTabs active="password-resets" />
      <AdminHeader
        title="Password Reset Approvals"
        description="Review self-service password-reset requests staged for approval before the new password takes effect."
      />

      <Card className="border border-border bg-card shadow-sm">
        <CardHeader>
          <CardTitle className="text-lg font-bold font-heading">
            <h2>Awaiting approval</h2>
          </CardTitle>
          <CardDescription className="text-sm">
            Approving applies the requester&rsquo;s new password immediately and signs them out of
            every existing session. Rejecting discards the staged password; the account keeps its
            current one.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {loading ? (
            <div className="flex items-center justify-center py-10">
              <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-primary"></div>
            </div>
          ) : loadError ? (
            <div className="text-sm text-destructive" data-testid="password-resets-load-error">
              Failed to load pending password-reset requests.{' '}
              <button type="button" onClick={() => void load()} className="underline font-medium">
                Retry
              </button>
            </div>
          ) : items.length === 0 ? (
            <div
              className="flex flex-col items-center justify-center py-12 text-center text-muted-foreground"
              data-testid="password-resets-empty"
            >
              <IconInbox size={40} className="mb-3 opacity-60" />
              <p className="text-sm">No pending password-reset requests.</p>
            </div>
          ) : (
            <ul className="divide-y divide-border" data-testid="password-resets-list">
              {items.map((item) => (
                <li
                  key={item.id}
                  className="flex flex-col gap-3 py-4 first:pt-0 sm:flex-row sm:items-center sm:justify-between"
                  data-testid={`password-reset-row-${item.id}`}
                >
                  <div className="min-w-0">
                    <p className="font-medium text-foreground truncate">{item.email}</p>
                    <p className="text-sm text-muted-foreground truncate">{item.display_name}</p>
                  </div>
                  <div className="flex items-center gap-2 shrink-0">
                    <Button
                      type="button"
                      size="sm"
                      disabled={busyId === item.id}
                      onClick={() => void act(item, 'approve')}
                      className="gap-1"
                      data-testid={`password-reset-approve-${item.id}`}
                    >
                      <IconCheck className="w-4 h-4" />
                      Approve
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={busyId === item.id}
                      onClick={() => void act(item, 'reject')}
                      className="gap-1"
                      data-testid={`password-reset-reject-${item.id}`}
                    >
                      <IconX className="w-4 h-4" />
                      Reject
                    </Button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
