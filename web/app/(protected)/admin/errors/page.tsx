'use client';

/**
 * The error inbox (WC-error-tracking) — `/admin/errors`.
 *
 * Operator-only, and gated the same way the rest of the admin console is: the
 * server enforces `settings:manage` + system tenant, this page just refuses to
 * render controls the caller cannot use.
 *
 * Shows one row per DISTINCT error rather than per occurrence, which is how the
 * backend stores it: a 500-storm firing the same exception ten thousand times is
 * one row with a counter, not ten thousand rows. That is what keeps the inbox
 * readable and the table small enough to live in the app's own database.
 */

import { useCallback, useEffect, useState } from 'react';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useToast } from '@/lib/toast-context';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import { EmptyState } from '@amroksaleh/ui/empty-state';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import { AccessDenied } from '@amroksaleh/ui/access-denied';

type ErrorStatus = 'unresolved' | 'resolved' | 'ignored';

interface ErrorGroup {
  id: number;
  type: string;
  message: string;
  file: string | null;
  line: number | null;
  environment: string | null;
  occurrences: number;
  status: ErrorStatus;
  first_seen_at: string;
  last_seen_at: string;
}

interface ListResponse {
  data: ErrorGroup[];
  counts: Record<ErrorStatus, number>;
}

const TABS: { key: ErrorStatus; label: string }[] = [
  { key: 'unresolved', label: 'Unresolved' },
  { key: 'resolved', label: 'Resolved' },
  { key: 'ignored', label: 'Ignored' },
];

function when(iso: string): string {
  const then = new Date(iso.replace(' ', 'T') + (/[Z+]/.test(iso) ? '' : 'Z'));
  if (Number.isNaN(then.getTime())) return iso;
  const s = Math.max(0, Math.round((Date.now() - then.getTime()) / 1000));
  if (s < 90) return `${s}s ago`;
  if (s < 5400) return `${Math.round(s / 60)}m ago`;
  if (s < 172800) return `${Math.round(s / 3600)}h ago`;
  return `${Math.round(s / 86400)}d ago`;
}

export default function ErrorsPage() {
  const { has, loading: capsLoading } = useCapabilities();
  const { addToast } = useToast();
  const canManage = has('settings:manage');

  const [tab, setTab] = useState<ErrorStatus>('unresolved');
  const [groups, setGroups] = useState<ErrorGroup[]>([]);
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [loading, setLoading] = useState(true);
  const [expanded, setExpanded] = useState<number | null>(null);

  const load = useCallback(async (status: ErrorStatus) => {
    setLoading(true);
    try {
      // Plain fetch, not the typed client: these routes are deliberately
      // KNOWN_UNDOCUMENTED for now, so they are absent from the generated
      // OpenAPI schema the typed client is built from.
      const res = await fetch(`/api/v1/errors?status=${status}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!res.ok) throw new Error(String(res.status));
      const body = (await res.json()) as ListResponse;
      setGroups(body.data ?? []);
      setCounts(body.counts ?? {});
    } catch {
      addToast('Could not load errors', 'error');
    } finally {
      setLoading(false);
    }
  }, [addToast]);

  useEffect(() => {
    if (!canManage) return;
    // Scheduled, not called synchronously in the effect body — a setState that
    // lands during the effect triggers a cascading render.
    const t = setTimeout(() => void load(tab), 0);
    return () => clearTimeout(t);
  }, [canManage, tab, load]);

  const setStatus = async (id: number, status: ErrorStatus) => {
    try {
      const res = await fetch(`/api/v1/errors/${id}`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ status }),
      });
      if (!res.ok) throw new Error(String(res.status));
      addToast(status === 'resolved' ? 'Marked resolved' : `Marked ${status}`, 'success');
      void load(tab);
    } catch {
      addToast('Could not update the error', 'error');
    }
  };

  if (capsLoading) {
    return <Skeleton className="h-64 w-full" />;
  }

  if (!canManage) {
    return (
      <AccessDenied description="You do not have the required permissions (`settings:manage`) to view the error inbox." />
    );
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold tracking-tight">Errors</h1>
        <p className="text-sm text-muted-foreground">
          Errors recorded on this deployment, grouped so a repeating failure stays one entry.
        </p>
      </header>

      <div className="flex gap-2" role="tablist">
        {TABS.map((t) => (
          <Button
            key={t.key}
            role="tab"
            aria-selected={tab === t.key}
            variant={tab === t.key ? 'default' : 'outline'}
            size="sm"
            onClick={() => setTab(t.key)}
          >
            {t.label}
            {counts[t.key] !== undefined ? (
              <span className="ml-2 text-xs opacity-70">{counts[t.key]}</span>
            ) : null}
          </Button>
        ))}
      </div>

      {loading ? (
        <div className="space-y-2">
          <Skeleton className="h-16 w-full" />
          <Skeleton className="h-16 w-full" />
        </div>
      ) : groups.length === 0 ? (
        <EmptyState
          title={tab === 'unresolved' ? 'No unresolved errors' : `No ${tab} errors`}
          description={
            tab === 'unresolved'
              ? 'Nothing has failed since the last time errors were cleared.'
              : undefined
          }
        />
      ) : (
        <ul className="divide-y divide-border rounded-xl border border-border">
          {groups.map((g) => (
            <li key={g.id} className="px-4 py-3">
              <div className="flex items-start justify-between gap-4">
                <button
                  type="button"
                  className="min-w-0 flex-1 text-left"
                  onClick={() => setExpanded(expanded === g.id ? null : g.id)}
                  aria-expanded={expanded === g.id}
                >
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{g.type}</span>
                    {g.environment ? <Badge variant="outline">{g.environment}</Badge> : null}
                    <Badge variant={g.occurrences > 1 ? 'default' : 'outline'}>
                      {g.occurrences}×
                    </Badge>
                  </div>
                  <p className="mt-1 truncate text-sm text-muted-foreground">{g.message}</p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {g.file ? `${g.file}:${g.line ?? 0}` : 'unknown location'} · last seen{' '}
                    {when(g.last_seen_at)}
                  </p>
                </button>

                <div className="flex shrink-0 gap-2">
                  {g.status !== 'resolved' ? (
                    <Button size="sm" variant="outline" onClick={() => void setStatus(g.id, 'resolved')}>
                      Resolve
                    </Button>
                  ) : (
                    <Button size="sm" variant="outline" onClick={() => void setStatus(g.id, 'unresolved')}>
                      Reopen
                    </Button>
                  )}
                  {g.status !== 'ignored' ? (
                    <Button size="sm" variant="ghost" onClick={() => void setStatus(g.id, 'ignored')}>
                      Ignore
                    </Button>
                  ) : null}
                </div>
              </div>

              {expanded === g.id ? (
                <dl className="mt-3 grid gap-1 rounded-lg bg-muted p-3 text-xs">
                  <div className="flex gap-2">
                    <dt className="text-muted-foreground">First seen</dt>
                    <dd>{when(g.first_seen_at)}</dd>
                  </div>
                  <div className="flex gap-2">
                    <dt className="text-muted-foreground">Message</dt>
                    <dd className="break-all">{g.message}</dd>
                  </div>
                </dl>
              ) : null}
            </li>
          ))}
        </ul>
      )}

      <p className="text-xs text-muted-foreground">
        Messages and stack traces are scrubbed before they are stored — credentials, tokens and
        email addresses are redacted at capture.
      </p>
    </div>
  );
}
