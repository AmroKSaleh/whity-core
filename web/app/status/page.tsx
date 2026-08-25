'use client';

/**
 * Public service-status page (WC-status-page).
 *
 * Lives OUTSIDE the (protected) route group, so it renders with no session —
 * which is the point. The reader who most needs this page is the one who cannot
 * sign in, so it must never depend on auth, and it must degrade to something
 * honest when the API itself cannot be reached.
 *
 * It reads GET /api/v1/status, which aggregates the `health_samples` time
 * series written by the `health:watch` collector. Nothing here probes anything:
 * during an incident this page adds no load to a struggling deployment.
 *
 * Deliberately plain — no app chrome, no nav, no auth-aware components. A
 * status page that pulls in the whole application shell shares more of the
 * application's ways of breaking.
 */

import { useCallback, useEffect, useState } from 'react';

import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';
import { useDateDisplay } from '@amroksaleh/features/datetime';
import type { DateDisplay } from '@amroksaleh/features/datetime';

type ComponentStatus = 'operational' | 'degraded' | 'down' | 'unknown';

interface StatusComponent {
  key: string;
  name: string;
  status: ComponentStatus;
  uptime: number | null;
  checked_at: string | null;
}

interface StatusIncident {
  component: string;
  status: string;
  started_at: string;
  ended_at: string;
  minutes: number;
}

interface StatusPayload {
  status: ComponentStatus;
  components: StatusComponent[];
  incidents: StatusIncident[];
  window_days: number;
  generated_at: string;
}

/** Refresh cadence. The collector samples every 60s, so polling faster only adds load. */
const POLL_MS = 60_000;

const TONE: Record<ComponentStatus, { dot: string; text: string }> = {
  operational: { dot: 'bg-success', text: 'text-success' },
  degraded: { dot: 'bg-warning', text: 'text-warning' },
  down: { dot: 'bg-error', text: 'text-error' },
  unknown: { dot: 'bg-muted-foreground', text: 'text-muted-foreground' },
};

/**
 * The overall headline and the per-component label are both reached through a
 * status lookup rather than as literals, which no static scanner can read — so
 * both sets are declared here and the extractor takes the catalogue from this
 * block. The English stays on the record as the runtime fallback.
 *
 * @i18n-keys status
 *   headline.operational = All systems operational
 *   headline.degraded = Some systems degraded
 *   headline.down = Service disruption
 *   headline.unknown = Status unavailable
 *   label.operational = Operational
 *   label.degraded = Degraded
 *   label.down = Down
 *   label.unknown = No recent data
 */
const HEADLINE: Record<ComponentStatus, { key: string; text: string }> = {
  operational: { key: 'headline.operational', text: 'All systems operational' },
  degraded: { key: 'headline.degraded', text: 'Some systems degraded' },
  down: { key: 'headline.down', text: 'Service disruption' },
  unknown: { key: 'headline.unknown', text: 'Status unavailable' },
};

const LABEL: Record<ComponentStatus, { key: string; text: string }> = {
  operational: { key: 'label.operational', text: 'Operational' },
  degraded: { key: 'label.degraded', text: 'Degraded' },
  down: { key: 'label.down', text: 'Down' },
  // "Unknown" is shown when samples have gone stale — silence is not health,
  // and saying so is more useful than freezing on the last good value.
  unknown: { key: 'label.unknown', text: 'No recent data' },
};

/**
 * Relative age, as one translatable unit per magnitude rather than a number
 * glued to a suffix — the unit letter and the word order both move between
 * languages, so `{n}` has to sit inside the string rather than in front of it.
 *
 * The BUCKETING moved to the shared date path (#1068) and only the words stayed
 * here, because the four thresholds were duplicated character-for-character on
 * the error-tracking screen and the two would have drifted. Going through
 * `dates` is also what makes relative phrasing honour `ui.hide_dates`: "3m ago"
 * is a date said less precisely, not a middle ground, so it is hidden exactly as
 * an absolute timestamp is — hence the `null`, which every caller renders as no
 * line at all.
 */
function formatWhen(t: TranslateFn, dates: DateDisplay, iso: string | null): string | null {
  if (dates.hidden) return null;
  if (!iso) return t('when.never', 'never');

  return dates.relative(iso, {
    seconds: (n) => t('when.seconds', '{n}s ago', { n }),
    minutes: (n) => t('when.minutes', '{n}m ago', { n }),
    hours: (n) => t('when.hours', '{n}h ago', { n }),
    days: (n) => t('when.days', '{n}d ago', { n }),
  }) ?? t('when.unknown', 'unknown');
}

export default function StatusPage() {
  const t = useTranslation('status');
  const dates = useDateDisplay();
  const [data, setData] = useState<StatusPayload | null>(null);
  const [unreachable, setUnreachable] = useState(false);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    try {
      const res = await fetch('/api/v1/status', { headers: { Accept: 'application/json' } });
      if (!res.ok) throw new Error(String(res.status));
      setData((await res.json()) as StatusPayload);
      setUnreachable(false);
    } catch {
      // The API being unreachable IS status information. Say so plainly rather
      // than showing a spinner forever or a stack trace.
      setUnreachable(true);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    // Both the first fetch and the interval are scheduled rather than run
    // synchronously in the effect body: a setState that lands during the effect
    // triggers a cascading render, which the repo's lint rule rejects (and
    // which NavigationProvider/CapabilitiesProvider avoid the same way).
    const kickoff = setTimeout(() => void load(), 0);
    const timer = setInterval(() => void load(), POLL_MS);
    return () => {
      clearTimeout(kickoff);
      clearInterval(timer);
    };
  }, [load]);

  const overall: ComponentStatus = unreachable ? 'unknown' : (data?.status ?? 'unknown');
  const tone = TONE[overall];

  return (
    <main className="min-h-screen bg-background text-foreground">
      <div className="mx-auto w-full max-w-3xl px-6 py-16">
        <header className="mb-10">
          <p className="text-sm font-medium uppercase tracking-wider text-muted-foreground">
            Whity
          </p>
          <h1 className="mt-1 text-3xl font-semibold tracking-tight">
            {t('title', 'Service status')}
          </h1>
        </header>

        <section
          className={'mb-10 flex items-center gap-3 rounded-xl border border-border bg-card px-5 py-4'}
          aria-live="polite"
        >
          <span className={`h-3 w-3 shrink-0 rounded-full ${tone.dot}`} aria-hidden />
          <div>
            <p className={`font-medium ${tone.text}`}>
              {t(HEADLINE[overall].key, HEADLINE[overall].text)}
            </p>
            {unreachable ? (
              <p className="text-sm text-muted-foreground">
                {t(
                  'unreachable',
                  'The status service could not be reached from your browser.'
                )}
              </p>
            ) : data && formatWhen(t, dates, data.generated_at) !== null ? (
              <p className="text-sm text-muted-foreground">
                {t('updated', 'Updated {when}', {
                  when: formatWhen(t, dates, data.generated_at) ?? '',
                })}
              </p>
            ) : null}
          </div>
        </section>

        {loading && !data ? (
          <p className="text-sm text-muted-foreground">{t('loading', 'Loading…')}</p>
        ) : null}

        {data ? (
          <>
            <section aria-labelledby="components-heading" className="mb-12">
              <h2 id="components-heading" className="sr-only">
                {t('components.heading', 'Components')}
              </h2>
              <ul className="divide-y divide-border rounded-xl border border-border">
                {data.components.map((component) => (
                  <li key={component.key} className="flex items-center justify-between gap-4 px-5 py-4">
                    <div className="min-w-0">
                      <p className="truncate font-medium">{component.name}</p>
                      {/*
                        #1068: the freshness line goes when dates are hidden.
                        The component's NAME, its state badge and its uptime
                        percentage are all still here — which is what somebody
                        who cannot sign in opens this page to see.
                      */}
                      {(() => {
                        const checked = formatWhen(t, dates, component.checked_at);
                        if (checked === null) return null;

                        return (
                          <p className="text-xs text-muted-foreground">
                            {t('components.checked', 'checked {when}', { when: checked })}
                          </p>
                        );
                      })()}
                    </div>
                    <div className="flex shrink-0 items-center gap-3">
                      {component.uptime !== null ? (
                        <span className="tabular-nums text-xs text-muted-foreground">
                          {component.uptime.toFixed(2)}%
                        </span>
                      ) : null}
                      <span className="flex items-center gap-2">
                        <span
                          className={`h-2.5 w-2.5 rounded-full ${TONE[component.status].dot}`}
                          aria-hidden
                        />
                        <span className={`text-sm ${TONE[component.status].text}`}>
                          {t(LABEL[component.status].key, LABEL[component.status].text)}
                        </span>
                      </span>
                    </div>
                  </li>
                ))}
              </ul>
              <p className="mt-3 text-xs text-muted-foreground">
                {t('components.uptimeWindow', 'Uptime over the last {days} days.', {
                  days: data.window_days,
                })}
              </p>
            </section>

            <section aria-labelledby="incidents-heading">
              <h2 id="incidents-heading" className="mb-4 text-lg font-semibold">
                {t('incidents.heading', 'Recent incidents')}
              </h2>
              {data.incidents.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  {t('incidents.none', 'No incidents recorded in the last {days} days.', {
                    days: data.window_days,
                  })}
                </p>
              ) : (
                <ul className="space-y-3">
                  {data.incidents.map((incident, i) => (
                    <li
                      key={`${incident.component}-${incident.started_at}-${i}`}
                      className="rounded-lg border border-border px-4 py-3"
                    >
                      <div className="flex flex-wrap items-center gap-2">
                        <span
                          className={`h-2 w-2 rounded-full ${
                            TONE[(incident.status as ComponentStatus) ?? 'unknown']?.dot ?? TONE.unknown.dot
                          }`}
                          aria-hidden
                        />
                        <span className="font-medium capitalize">{incident.component}</span>
                        {/* `incident.status` is the backend's own slug, shown
                            verbatim today and left that way — it travels
                            through the sentence as a placeholder. */}
                        <span className="text-sm text-muted-foreground">
                          {t('incidents.duration', '{status} for {minutes} min', {
                            status: incident.status,
                            minutes: incident.minutes,
                          })}
                        </span>
                      </div>
                      {/*
                        #1068: the start time goes; the DURATION above it stays.
                        The two are different kinds of fact — one is a point in
                        time this tenant has asked not to show, the other is how
                        long an outage lasted, which is the incident itself and
                        the only reason the row is on the page.
                      */}
                      {(() => {
                        const started = dates.dateTime(incident.started_at);
                        if (started === null) return null;

                        return (
                          <p className="mt-1 text-xs text-muted-foreground">{started}</p>
                        );
                      })()}
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </>
        ) : null}

        <footer className="mt-16 border-t border-border pt-6 text-xs text-muted-foreground">
          <p>
            {t(
              'footer',
              'This page reports the health of this deployment. It refreshes automatically every minute.'
            )}
          </p>
        </footer>
      </div>
    </main>
  );
}
