/**
 * Display decisions for the notification-delivery page — deliberately with no
 * translation in them.
 *
 * ── Why these do not take a `t` ───────────────────────────────────────────
 *
 * The obvious shape is `formatLatency(seconds, t): string`, and that is what
 * this file had first. It does not work here: the key extractor resolves a
 * key's domain from a literal `useTranslation('…')` in the same file, a
 * parameter is not a binding, and a module of nothing but pure helpers has no
 * component to inherit a domain from. The keys extract as
 * `[unresolved-domain]` and the i18n drift gate fails — which is exactly what
 * happened, after the helpers were moved out of `page.tsx` to satisfy a
 * different rule (an App Router page module may export only its default).
 *
 * The established way out is to keep a component that uses them in the same
 * file, as `document-templates/audience.tsx` does. There is no such component
 * here: the one candidate renders a label and a number and translates
 * nothing, so a `useTranslation` call in it would exist only to feed the
 * extractor.
 *
 * So the split runs along a real seam instead: this module decides WHICH
 * phrasing applies and computes the number; the page, which has the binding,
 * turns that into words. The functions stay unit-testable without rendering,
 * every `t()` call sits where the extractor can see it, and nothing exists
 * purely to satisfy a tool.
 */

/** Which phrasing the average send time needs, and the number to put in it. */
export type LatencyDisplay =
  | { unit: 'none' }
  | { unit: 'subSecond'; value: string }
  | { unit: 'seconds'; value: string }
  | { unit: 'minutes'; value: string };

/**
 * The coarsest unit that still reads as a duration.
 *
 * Sub-second values keep one decimal: "0s" for a 400ms send reads as instant
 * and would hide a regression to 900ms. Whole seconds and minutes do not need
 * that precision.
 */
export function latencyDisplay(seconds: number | null): LatencyDisplay {
  if (seconds === null) return { unit: 'none' };
  if (seconds < 1) return { unit: 'subSecond', value: seconds.toFixed(1) };
  if (seconds < 60) return { unit: 'seconds', value: String(Math.round(seconds)) };
  return { unit: 'minutes', value: String(Math.round(seconds / 60)) };
}

/**
 * A rate the API holds as 0..1, shown as a percentage.
 *
 * Two decimals below one per cent, one above. A rate of 0.0004 is a real and
 * possibly rising failure rate; rendering it as "0.0%" would say there are no
 * failures, which is the one answer this figure must never give wrongly.
 *
 * Rounding follows the binary value, not the decimal literal: `0.0725 * 100`
 * is 7.249999999999999, so it shows as 7.2% rather than 7.3%. Pinned by a
 * test rather than engineered around — closing it needs decimal arithmetic on
 * the string, and a tenth of a percentage point changes no decision anybody
 * makes from this screen.
 *
 * No translation: a percentage is digits and a sign in every locale this
 * ships to, and the digits themselves are the renderer's business.
 */
export function formatRate(rate: number): string {
  return `${(rate * 100).toFixed(rate > 0 && rate < 0.01 ? 2 : 1)}%`;
}
