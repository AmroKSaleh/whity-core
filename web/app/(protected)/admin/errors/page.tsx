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
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';
import { useDateDisplay } from '@amroksaleh/features/datetime';
import type { DateDisplay } from '@amroksaleh/features/datetime';
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

/**
 * The tab labels reach `t()` through this table rather than as literals at the
 * call site, which no static scanner can read — so they are declared here and
 * the extractor takes the catalogue from this block. The English stays on the
 * record as the runtime fallback.
 *
 * @i18n-keys admin
 *   errors.tab.unresolved = Unresolved
 *   errors.tab.resolved = Resolved
 *   errors.tab.ignored = Ignored
 */
const TABS: { key: ErrorStatus; labelKey: string; label: string }[] = [
  { key: 'unresolved', labelKey: 'errors.tab.unresolved', label: 'Unresolved' },
  { key: 'resolved', labelKey: 'errors.tab.resolved', label: 'Resolved' },
  { key: 'ignored', labelKey: 'errors.tab.ignored', label: 'Ignored' },
];

/**
 * How long ago, in the coarsest unit that still reads as a duration.
 *
 * Takes the translate function and the date path rather than reaching for
 * either hook: it is a plain function, not a component. Each bucket is its own
 * key so a language can put the number where its grammar needs it, and the
 * thresholds live in the shared path (#1068) because this file and the status
 * page carried the same four of them, character for character.
 *
 * Returns null when the tenant hides dates. "2h ago" is a date said less
 * precisely rather than a middle ground, so it is hidden exactly as an absolute
 * timestamp is, and the callers below drop their line.
 */
function when(iso: string, t: TranslateFn, dates: DateDisplay): string | null {
  return dates.relative(iso, {
    seconds: (count) => t('errors.when.seconds', '{count}s ago', { count }),
    minutes: (count) => t('errors.when.minutes', '{count}m ago', { count }),
    hours: (count) => t('errors.when.hours', '{count}h ago', { count }),
    days: (count) => t('errors.when.days', '{count}d ago', { count }),
  });
}

export default function ErrorsPage() {
  const { has, loading: capsLoading } = useCapabilities();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const dates = useDateDisplay();
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
      addToast(t('errors.toast.loadFailed', 'Could not load errors'), 'error');
    } finally {
      setLoading(false);
    }
  }, [addToast, t]);

  useEffect(() => {
    if (!canManage) return;
    // Scheduled, not called synchronously in the effect body — a setState that
    // lands during the effect triggers a cascading render.
    const timer = setTimeout(() => void load(tab), 0);
    return () => clearTimeout(timer);
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
      addToast(
        status === 'resolved'
          ? t('errors.toast.markedResolved', 'Marked resolved')
          : status === 'ignored'
            ? t('errors.toast.markedIgnored', 'Marked ignored')
            : t('errors.toast.markedUnresolved', 'Marked unresolved'),
        'success'
      );
      void load(tab);
    } catch {
      addToast(t('errors.toast.updateFailed', 'Could not update the error'), 'error');
    }
  };

  if (capsLoading) {
    return <Skeleton className="h-64 w-full" />;
  }

  if (!canManage) {
    return (
      <AccessDenied
        description={t(
          'errors.accessDenied',
          'You do not have the required permissions (`settings:manage`) to view the error inbox.'
        )}
      />
    );
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold tracking-tight">{t('errors.title', 'Errors')}</h1>
        <p className="text-sm text-muted-foreground">
          {t(
            'errors.description',
            'Errors recorded on this deployment, grouped so a repeating failure stays one entry.'
          )}
        </p>
      </header>

      <div className="flex gap-2" role="tablist">
        {TABS.map((entry) => (
          <Button
            key={entry.key}
            role="tab"
            aria-selected={tab === entry.key}
            variant={tab === entry.key ? 'default' : 'outline'}
            size="sm"
            onClick={() => setTab(entry.key)}
          >
            {t(entry.labelKey, entry.label)}
            {counts[entry.key] !== undefined ? (
              <span className="ml-2 text-xs opacity-70">{counts[entry.key]}</span>
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
          title={
            tab === 'unresolved'
              ? t('errors.empty.unresolved', 'No unresolved errors')
              : tab === 'resolved'
                ? t('errors.empty.resolved', 'No resolved errors')
                : t('errors.empty.ignored', 'No ignored errors')
          }
          description={
            tab === 'unresolved'
              ? t(
                  'errors.empty.unresolvedDescription',
                  'Nothing has failed since the last time errors were cleared.'
                )
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
                      {t('errors.row.occurrences', '{count}×', { count: g.occurrences })}
                    </Badge>
                  </div>
                  <p className="mt-1 truncate text-sm text-muted-foreground">{g.message}</p>
                  {/*
                    #1068: the LOCATION survives on its own. It is what an
                    operator needs to find the code; "· last seen —" would be a
                    separator with nothing after it.
                  */}
                  {(() => {
                    const location = g.file
                      ? `${g.file}:${g.line ?? 0}`
                      : t('errors.row.unknownLocation', 'unknown location');
                    const lastSeen = when(g.last_seen_at, t, dates);

                    return (
                      <p className="mt-1 text-xs text-muted-foreground">
                        {lastSeen === null
                          ? location
                          : t('errors.row.lastSeen', '{location} · last seen {when}', {
                              location,
                              when: lastSeen,
                            })}
                      </p>
                    );
                  })()}
                </button>

                <div className="flex shrink-0 gap-2">
                  {g.status !== 'resolved' ? (
                    <Button size="sm" variant="outline" onClick={() => void setStatus(g.id, 'resolved')}>
                      {t('errors.action.resolve', 'Resolve')}
                    </Button>
                  ) : (
                    <Button size="sm" variant="outline" onClick={() => void setStatus(g.id, 'unresolved')}>
                      {t('errors.action.reopen', 'Reopen')}
                    </Button>
                  )}
                  {g.status !== 'ignored' ? (
                    <Button size="sm" variant="ghost" onClick={() => void setStatus(g.id, 'ignored')}>
                      {t('errors.action.ignore', 'Ignore')}
                    </Button>
                  ) : null}
                </div>
              </div>

              {expanded === g.id ? (
                <dl className="mt-3 grid gap-1 rounded-lg bg-muted p-3 text-xs">
                  {/*
                    #1068: the whole <dt>/<dd> pair goes, not just the value —
                    a term list with a label and nothing beside it reads as a
                    load that failed.
                  */}
                  {(() => {
                    const firstSeen = when(g.first_seen_at, t, dates);
                    if (firstSeen === null) return null;

                    return (
                      <div className="flex gap-2">
                        <dt className="text-muted-foreground">
                          {t('errors.detail.firstSeen', 'First seen')}
                        </dt>
                        <dd>{firstSeen}</dd>
                      </div>
                    );
                  })()}
                  <div className="flex gap-2">
                    <dt className="text-muted-foreground">
                      {t('errors.detail.message', 'Message')}
                    </dt>
                    <dd className="break-all">{g.message}</dd>
                  </div>
                </dl>
              ) : null}
            </li>
          ))}
        </ul>
      )}

      <p className="text-xs text-muted-foreground">
        {t(
          'errors.footnote',
          'Messages and stack traces are scrubbed before they are stored — credentials, tokens ' +
            'and email addresses are redacted at capture.'
        )}
      </p>
    </div>
  );
}
