'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useToast } from '@/lib/toast-context';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { ApprovalGatingTabs } from '@/components/admin/approval-gating-tabs';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { AccessDenied } from '@amroksaleh/ui/access-denied';
import { IconCheck, IconInbox, IconX } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';

/**
 * Pending self-service registrations (WC-235 admin-approval activation).
 *
 * When ADMIN_APPROVAL_ENFORCED is on, registration provisions the workspace
 * owner as 'invited' (pending); the owner cannot log in until approved here.
 * This is a SYSTEM-TENANT resource — approving a registration activates another
 * tenant's owner — so the page is gated on the system tenant (id 0) AND
 * registrations:approve, mirroring the backend (which returns 403 for any other
 * caller even if they hold the permission). The endpoints are untyped (not in
 * the generated OpenAPI client), so they are called via fetch with the CSRF
 * header, matching the registration page.
 *
 * This is the "Signup" tab of the unified Approval Gating admin surface
 * (WC-password-reset-2fa-recovery) — folded in unchanged alongside the new
 * Password reset / 2FA auth reset tabs, see
 * web/components/admin/approval-gating-tabs.tsx.
 */
const REGISTRATIONS_APPROVE = 'registrations:approve';
const SYSTEM_TENANT_ID = 0;

interface PendingRegistration {
  membership_id: number;
  tenant_id: number;
  tenant_name: string;
  tenant_slug: string;
  profile_id: number;
  display_name: string;
  owner_email: string;
  created_at: string;
}

export default function PendingRegistrationsPage() {
  const { addToast } = useToast();
  const { user } = useAuth();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();
  const t = useTranslation('admin');

  const canApprove = hasPermission(REGISTRATIONS_APPROVE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  const [items, setItems] = useState<PendingRegistration[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);
  // Tracks the membership id currently being approved/rejected so its row's
  // buttons disable while the request is in flight.
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(false);
    try {
      const res = await fetch('/api/v1/registrations/pending', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'include',
      });
      if (!res.ok) {
        setLoadError(true);
        setItems([]);
        return;
      }
      const body = await res.json().catch(() => ({}));
      setItems(Array.isArray(body?.data) ? (body.data as PendingRegistration[]) : []);
    } catch {
      setLoadError(true);
      setItems([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    // Only fetch once the caller is confirmed eligible — otherwise the API
    // returns 403 and we render the access-denied state instead. Deferred off
    // the synchronous effect tick (a microtask) so load()'s initial setState
    // does not run synchronously within the effect body.
    if (!isCapabilitiesLoading && isSystemTenant && canApprove) {
      void Promise.resolve().then(load);
    }
  }, [isCapabilitiesLoading, isSystemTenant, canApprove, load]);

  const act = async (item: PendingRegistration, action: 'approve' | 'reject') => {
    setBusyId(item.membership_id);
    try {
      const res = await fetch(`/api/v1/registrations/${item.membership_id}/${action}`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'include',
      });
      if (!res.ok) {
        addToast(
          action === 'approve'
            ? t('registrations.approve.failedStatus', 'Failed to approve {tenant} ({status}).', {
                tenant: item.tenant_name,
                status: res.status,
              })
            : t('registrations.reject.failedStatus', 'Failed to reject {tenant} ({status}).', {
                tenant: item.tenant_name,
                status: res.status,
              }),
          'error'
        );
        // Re-sync in case the row was already handled elsewhere.
        void load();
        return;
      }
      addToast(
        action === 'approve'
          ? t('registrations.approve.success', 'Approved {tenant}. The owner can now sign in.', {
              tenant: item.tenant_name,
            })
          : t('registrations.reject.success', 'Rejected {tenant}.', { tenant: item.tenant_name }),
        'success'
      );
      // Drop the handled row locally (avoids a full refetch flicker).
      setItems((prev) => prev.filter((i) => i.membership_id !== item.membership_id));
    } catch {
      addToast(
        action === 'approve'
          ? t('registrations.approve.failed', 'Failed to approve {tenant}.', {
              tenant: item.tenant_name,
            })
          : t('registrations.reject.failed', 'Failed to reject {tenant}.', {
              tenant: item.tenant_name,
            }),
        'error'
      );
    } finally {
      setBusyId(null);
    }
  };

  if (isCapabilitiesLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  // Approving a registration activates another tenant's owner — a platform
  // operation. Gate on BOTH the system tenant and the permission, matching the
  // backend (which 403s any other caller).
  if (!isSystemTenant || !canApprove) {
    // ONE translatable sentence with a {link} hole, split at render time so the
    // Website Settings link stays a link without fragmenting the sentence for
    // translators.
    const [beforeLink, afterLink] = t(
      'registrations.accessDenied',
      'Pending registrations can only be reviewed from the system tenant. Your tenant’s settings are on the {link} page.'
    ).split('{link}');

    return (
      <div className="space-y-6 max-w-4xl mx-auto px-4 md:px-0 pb-16">
        <ApprovalGatingTabs active="signup" />
        <AccessDenied
          data-testid="registrations-access-denied"
          description={
            <>
              {beforeLink}
              <Link href="/admin/settings" className="font-medium underline">
                {t('registrations.accessDenied.link', 'Website Settings')}
              </Link>
              {afterLink}
            </>
          }
          action={
            <Button onClick={() => window.history.back()} variant="outline">
              {t('registrations.goBack', 'Go Back')}
            </Button>
          }
        />
      </div>
    );
  }

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <ApprovalGatingTabs active="signup" />
      <AdminHeader
        title={t('registrations.title', 'Pending Registrations')}
        description={t(
          'registrations.description',
          'Review and approve new self-service workspace sign-ups before their owners can sign in.'
        )}
      />

      <Card className="border border-border bg-card shadow-sm">
        <CardHeader>
          <CardTitle className="text-lg font-bold font-heading">
            <h2>{t('registrations.list.title', 'Awaiting approval')}</h2>
          </CardTitle>
          <CardDescription className="text-sm">
            {t(
              'registrations.list.description',
              'Each entry is a new workspace whose owner cannot log in until you approve it. Rejecting suspends the account; you can approve it later.'
            )}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {loading ? (
            <div className="flex items-center justify-center py-10">
              <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-primary"></div>
            </div>
          ) : loadError ? (
            <div className="text-sm text-destructive" data-testid="registrations-load-error">
              {t('registrations.loadError', 'Failed to load pending registrations.')}{' '}
              <button type="button" onClick={() => void load()} className="underline font-medium">
                {t('registrations.retry', 'Retry')}
              </button>
            </div>
          ) : items.length === 0 ? (
            <div
              className="flex flex-col items-center justify-center py-12 text-center text-muted-foreground"
              data-testid="registrations-empty"
            >
              <IconInbox size={40} className="mb-3 opacity-60" />
              <p className="text-sm">{t('registrations.empty', 'No pending registrations.')}</p>
            </div>
          ) : (
            <ul className="divide-y divide-border" data-testid="registrations-list">
              {items.map((item) => (
                <li
                  key={item.membership_id}
                  className="flex flex-col gap-3 py-4 first:pt-0 sm:flex-row sm:items-center sm:justify-between"
                  data-testid={`registration-row-${item.membership_id}`}
                >
                  <div className="min-w-0">
                    <p className="font-medium text-foreground truncate">{item.tenant_name}</p>
                    <p className="text-sm text-muted-foreground truncate">
                      {item.owner_email || item.display_name}
                    </p>
                  </div>
                  <div className="flex items-center gap-2 shrink-0">
                    <Button
                      type="button"
                      size="sm"
                      disabled={busyId === item.membership_id}
                      onClick={() => void act(item, 'approve')}
                      className="gap-1"
                      data-testid={`registration-approve-${item.membership_id}`}
                    >
                      <IconCheck className="w-4 h-4" />
                      {t('registrations.action.approve', 'Approve')}
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={busyId === item.membership_id}
                      onClick={() => void act(item, 'reject')}
                      className="gap-1"
                      data-testid={`registration-reject-${item.membership_id}`}
                    >
                      <IconX className="w-4 h-4" />
                      {t('registrations.action.reject', 'Reject')}
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
