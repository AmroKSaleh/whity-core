'use client';

/**
 * Notification delivery health — `/admin/notifications`.
 *
 * The read side of WC-notifications. The dispatcher has recorded a PII-free
 * lifecycle trail and aggregated metrics since #4d40cc1c, and until now there
 * was no screen for either: `GET /api/v1/notification-metrics` existed with
 * nothing calling it, so the answer to "are our emails going out?" lived in a
 * SQL prompt.
 *
 * ── Why this page does not list individual deliveries ─────────────────────
 *
 * The obvious build is a log viewer. There already is one: the dispatcher
 * audits `notification.dispatch` and `notification.delivery.*`, and
 * `/admin/audit-logs` filters by action. A second viewer over the same rows
 * would be a second place to keep paging, filtering, RBAC and date handling
 * correct, and the two would drift. So this page answers the aggregate
 * question and hands off to the trail for the per-delivery one.
 *
 * ── The bounced count is labelled, not just shown ─────────────────────────
 *
 * `bounced` is a valid status in the schema that NOTHING in production ever
 * sets — a bounce arrives asynchronously from the receiving mail server and
 * there is no webhook ingestion to hear it. Rendering a bare `0` beside Failed
 * would read as "no bounces", which is the opposite of true: it means bounces
 * are not being counted at all. It is shown as not-tracked instead.
 */

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useToast } from '@/lib/toast-context';
import { useTranslation } from '@amroksaleh/features/i18n';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { EmptyState } from '@amroksaleh/ui/empty-state';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import { AccessDenied } from '@amroksaleh/ui/access-denied';
import { latencyDisplay, formatRate } from './format';

type DeliveryStatus = 'queued' | 'sent' | 'failed' | 'bounced';

interface Metrics {
  total: number;
  by_status: Record<DeliveryStatus, number>;
  queue_depth: number;
  /** 0..1, already rounded to 4dp server-side. */
  failure_rate: number;
  /** Seconds between a delivery being created and marked sent; null when none have been. */
  avg_latency_seconds: number | null;
}

/**
 * The per-status labels reach `t()` through this table rather than as literals
 * at the call site, which no static scanner can read — so they are declared
 * here and the extractor takes the catalogue from this block.
 *
 * @i18n-keys admin
 *   notifications.status.queued = Queued
 *   notifications.status.sent = Sent
 *   notifications.status.failed = Failed
 *   notifications.status.bounced = Bounced
 */
const STATUS_LABELS: { key: DeliveryStatus; labelKey: string; label: string }[] = [
  { key: 'queued', labelKey: 'notifications.status.queued', label: 'Queued' },
  { key: 'sent', labelKey: 'notifications.status.sent', label: 'Sent' },
  { key: 'failed', labelKey: 'notifications.status.failed', label: 'Failed' },
  { key: 'bounced', labelKey: 'notifications.status.bounced', label: 'Bounced' },
];

/**
 * The average send time in words. Lives here, not in `./format`, because this
 * is the file with the `useTranslation('admin')` binding the key extractor
 * reads — see that module's header for why the split runs where it does.
 */
function latencyText(seconds: number | null, t: ReturnType<typeof useTranslation>): string {
  const d = latencyDisplay(seconds);
  switch (d.unit) {
    case 'none':
      return t('notifications.latency.none', 'No deliveries sent yet');
    case 'subSecond':
      return t('notifications.latency.subSecond', '{n}s').replace('{n}', d.value);
    case 'seconds':
      return t('notifications.latency.seconds', '{n}s').replace('{n}', d.value);
    case 'minutes':
      return t('notifications.latency.minutes', '{n} min').replace('{n}', d.value);
  }
}

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">{label}</CardTitle>
      </CardHeader>
      <CardContent>
        <p className="text-2xl font-semibold tabular-nums">{value}</p>
        {hint ? <p className="mt-1 text-xs text-muted-foreground">{hint}</p> : null}
      </CardContent>
    </Card>
  );
}

export default function NotificationsAdminPage() {
  const t = useTranslation('admin');
  const { has, loading: capsLoading } = useCapabilities();
  const { addToast } = useToast();

  const [metrics, setMetrics] = useState<Metrics | null>(null);
  const [loading, setLoading] = useState(true);

  const canManage = has('notifications:manage');

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch('/api/v1/notification-metrics', {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!res.ok) throw new Error(String(res.status));
      const body = (await res.json()) as { data: Metrics };
      setMetrics(body.data);
    } catch {
      addToast(
        t('notifications.toast.loadFailed', 'Could not load notification metrics'),
        'error'
      );
    } finally {
      setLoading(false);
    }
  }, [addToast, t]);

  useEffect(() => {
    if (!canManage) return;
    // Scheduled, not called synchronously in the effect body — a setState that
    // lands during the effect triggers a cascading render (React 19).
    const timer = setTimeout(() => void load(), 0);
    return () => clearTimeout(timer);
  }, [canManage, load]);

  if (capsLoading) {
    return <Skeleton className="h-64 w-full" />;
  }

  if (!canManage) {
    return (
      <AccessDenied
        description={t(
          'notifications.accessDenied',
          'You do not have the required permissions (`notifications:manage`) to view notification delivery health.'
        )}
      />
    );
  }

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">
            {t('notifications.title', 'Notification delivery')}
          </h1>
          <p className="text-sm text-muted-foreground">
            {t(
              'notifications.description',
              'How this workspace’s notifications are being delivered. Counts and rates only — no recipients and no message content.'
            )}
          </p>
        </div>
        <Button variant="outline" onClick={() => void load()} disabled={loading}>
          {t('notifications.action.refresh', 'Refresh')}
        </Button>
      </header>

      {loading ? (
        <Skeleton className="h-64 w-full" />
      ) : metrics === null ? (
        <EmptyState
          title={t('notifications.error.title', 'Metrics unavailable')}
          description={t(
            'notifications.error.description',
            'The metrics could not be loaded. Refresh to try again.'
          )}
        />
      ) : metrics.total === 0 ? (
        <EmptyState
          title={t('notifications.empty.title', 'No notifications sent yet')}
          description={t(
            'notifications.empty.description',
            'Once this workspace sends its first notification, its delivery health appears here.'
          )}
        />
      ) : (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Stat
              label={t('notifications.stat.total', 'Total deliveries')}
              value={String(metrics.total)}
            />
            <Stat
              label={t('notifications.stat.queueDepth', 'Waiting to send')}
              value={String(metrics.queue_depth)}
              hint={t('notifications.stat.queueDepthHint', 'Queued, not yet attempted')}
            />
            <Stat
              label={t('notifications.stat.failureRate', 'Failure rate')}
              value={formatRate(metrics.failure_rate)}
            />
            <Stat
              label={t('notifications.stat.latency', 'Average time to send')}
              value={latencyText(metrics.avg_latency_seconds, t)}
            />
          </div>

          <section className="space-y-3">
            <h2 className="text-lg font-semibold tracking-tight">
              {t('notifications.byStatus.title', 'By outcome')}
            </h2>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {STATUS_LABELS.map(({ key, labelKey, label }) => (
                <Stat
                  key={key}
                  label={t(labelKey, label)}
                  // A bounce is reported asynchronously by the receiving mail
                  // server and nothing here ingests those reports, so this
                  // counter can only ever be zero. Showing the zero would say
                  // "no bounces" when the truth is "bounces are not counted".
                  value={
                    key === 'bounced'
                      ? t('notifications.status.notTracked', 'Not tracked')
                      : String(metrics.by_status[key] ?? 0)
                  }
                  hint={
                    key === 'bounced'
                      ? t(
                          'notifications.status.bouncedHint',
                          'Needs mail-provider webhooks, which are not set up'
                        )
                      : undefined
                  }
                />
              ))}
            </div>
          </section>

          <section className="space-y-2">
            <h2 className="text-lg font-semibold tracking-tight">
              {t('notifications.trail.title', 'Individual deliveries')}
            </h2>
            <p className="text-sm text-muted-foreground">
              {t(
                'notifications.trail.description',
                'Every dispatch and delivery outcome is recorded in the audit trail, without recipients or message content.'
              )}
            </p>
            <Button asChild variant="outline">
              <Link href="/admin/audit-logs?action=notification.">
                {t('notifications.trail.link', 'Open the delivery trail')}
              </Link>
            </Button>
          </section>
        </>
      )}
    </div>
  );
}
