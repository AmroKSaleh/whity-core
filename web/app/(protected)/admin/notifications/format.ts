import type { TranslateFn } from '@amroksaleh/features/i18n';

/**
 * Display helpers for the notification-delivery page.
 *
 * They live beside `page.tsx` rather than inside it because an App Router page
 * module may export ONLY its default — `app-router-page-exports.test.ts`
 * enforces that, and caught these two when they started out in the page. The
 * rule is real: Next treats other named exports from a route module as route
 * configuration, so an unrecognised one is a contract violation rather than a
 * style preference.
 *
 * Keeping them exported also keeps them testable without rendering, which is
 * what the boundary cases below actually need.
 */

/**
 * A latency in the coarsest unit that still reads as a duration.
 *
 * Takes the translate function rather than reaching for the hook: this is a
 * plain function, not a component. Sub-second values keep one decimal because
 * "0s" for a 400ms send reads as instant and would hide a regression to 900ms;
 * whole seconds and minutes do not need that precision.
 */
export function formatLatency(seconds: number | null, t: TranslateFn): string {
  if (seconds === null) return t('notifications.latency.none', 'No deliveries sent yet');
  if (seconds < 1) {
    return t('notifications.latency.subSecond', '{n}s').replace('{n}', seconds.toFixed(1));
  }
  if (seconds < 60) {
    return t('notifications.latency.seconds', '{n}s').replace('{n}', String(Math.round(seconds)));
  }
  return t('notifications.latency.minutes', '{n} min').replace(
    '{n}',
    String(Math.round(seconds / 60))
  );
}

/**
 * A rate the API holds as 0..1, shown as a percentage.
 *
 * Two decimals below one per cent, one above. A rate of 0.0004 is a real and
 * possibly rising failure rate; rendering it as "0.0%" would say there are no
 * failures, which is the one answer this figure must never give wrongly.
 *
 * Rounding follows the binary value, not the decimal literal: `0.0725 * 100`
 * is 7.249999999999999, so it shows as 7.2% rather than 7.3%. That is pinned
 * by a test rather than engineered around — closing it needs decimal
 * arithmetic on the string, and a tenth of a percentage point changes no
 * decision anybody makes from this screen.
 */
export function formatRate(rate: number): string {
  return `${(rate * 100).toFixed(rate > 0 && rate < 0.01 ? 2 : 1)}%`;
}
