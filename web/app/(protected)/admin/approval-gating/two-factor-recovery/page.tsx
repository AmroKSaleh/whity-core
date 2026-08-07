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
 * Pending "lost my 2FA device" recovery requests
 * (WC-password-reset-2fa-recovery) — the "2FA auth reset" tab of the unified
 * Approval Gating admin surface.
 *
 * This is the highest-stakes queue of the three: approving CLEARS the target
 * user's two-factor authentication entirely and issues them a fresh
 * password-reset link — a genuinely account-takeover-adjacent action, gated on
 * the narrow `two_factor_recovery:approve` permission (distinct from
 * `password_resets:approve`). Scoped to the CALLER'S OWN tenant, like the
 * Password reset tab (not system-tenant-restricted).
 */
const TWO_FACTOR_RECOVERY_APPROVE = 'two_factor_recovery:approve';

interface PendingTwoFactorRecovery {
  id: number;
  profile_id: number;
  email: string;
  display_name: string;
  created_at: string;
}

export default function TwoFactorRecoveryApprovalsPage() {
  const { addToast } = useToast();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();

  const canApprove = hasPermission(TWO_FACTOR_RECOVERY_APPROVE);

  const [items, setItems] = useState<PendingTwoFactorRecovery[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(false);
    try {
      const res = await fetch('/api/v1/2fa-recovery/pending', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'include',
      });
      if (!res.ok) {
        setLoadError(true);
        setItems([]);
        return;
      }
      const body = await res.json().catch(() => ({}));
      setItems(Array.isArray(body?.data) ? (body.data as PendingTwoFactorRecovery[]) : []);
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

  const act = async (item: PendingTwoFactorRecovery, action: 'approve' | 'reject') => {
    setBusyId(item.id);
    try {
      const res = await fetch(`/api/v1/2fa-recovery/${item.id}/${action}`, {
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
          ? `Approved — ${item.email}'s two-factor authentication has been cleared and a password-reset link was sent.`
          : `Rejected the recovery request for ${item.email}.`,
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
        <ApprovalGatingTabs active="two-factor-recovery" />
        <AccessDenied
          data-testid="two-factor-recovery-access-denied"
          description="You don't have permission to review pending 2FA-recovery requests."
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
      <ApprovalGatingTabs active="two-factor-recovery" />
      <AdminHeader
        title="2FA Auth Reset Approvals"
        description="Review requests from users locked out because they lost both their password and their 2FA device."
      />

      <Card className="border border-border bg-card shadow-sm">
        <CardHeader>
          <CardTitle className="text-lg font-bold font-heading">
            <h2>Awaiting approval</h2>
          </CardTitle>
          <CardDescription className="text-sm">
            Approving clears the user&rsquo;s two-factor authentication entirely and emails them a
            fresh password-reset link. Rejecting leaves their account completely untouched.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {loading ? (
            <div className="flex items-center justify-center py-10">
              <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-primary"></div>
            </div>
          ) : loadError ? (
            <div className="text-sm text-destructive" data-testid="two-factor-recovery-load-error">
              Failed to load pending 2FA-recovery requests.{' '}
              <button type="button" onClick={() => void load()} className="underline font-medium">
                Retry
              </button>
            </div>
          ) : items.length === 0 ? (
            <div
              className="flex flex-col items-center justify-center py-12 text-center text-muted-foreground"
              data-testid="two-factor-recovery-empty"
            >
              <IconInbox size={40} className="mb-3 opacity-60" />
              <p className="text-sm">No pending 2FA-recovery requests.</p>
            </div>
          ) : (
            <ul className="divide-y divide-border" data-testid="two-factor-recovery-list">
              {items.map((item) => (
                <li
                  key={item.id}
                  className="flex flex-col gap-3 py-4 first:pt-0 sm:flex-row sm:items-center sm:justify-between"
                  data-testid={`two-factor-recovery-row-${item.id}`}
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
                      data-testid={`two-factor-recovery-approve-${item.id}`}
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
                      data-testid={`two-factor-recovery-reject-${item.id}`}
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
