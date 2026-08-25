/**
 * Rendering the timestamps a record page is full of.
 *
 * Every admin screen here has its own copy of this guard, and the guard is the
 * point: a malformed or non-ISO value from the wire must render as ITSELF rather
 * than as the string "Invalid Date", which tells a reader nothing and looks like
 * a product bug rather than a data one. Two copies already disagreed about
 * whether an empty string is a date; a third would have made it three.
 *
 * THE LOCALE ARGUMENT, AND THE COMMENT THAT USED TO BE HERE
 * --------------------------------------------------------
 * This file used to say that calling `toLocaleDateString()` with no locale was
 * correct because it follows the browser's, and that "the app's own locale
 * negotiation happens above this". No such negotiation existed. Nothing in the
 * app read the chosen language and passed it down here, and no call site
 * supplied one — so an Arabic user on an `en-US` browser got `8/24/2026,
 * 5:47:00 PM` sitting inside an Arabic right-to-left sentence, which is exactly
 * the outcome that comment named as the thing to avoid.
 *
 * The browser locale is the wrong source in any case. A person who has chosen
 * Arabic in THIS product has told us which language to render in; their
 * browser's Accept-Language is a different statement, often made by whoever set
 * the machine up. So the caller passes the resolved language code, and the
 * default is only what to do when it genuinely has none.
 *
 * `undefined` (rather than a hard-coded 'en') is deliberate as that default: it
 * restores the old runtime behaviour exactly for any caller that has not been
 * updated, so adding the parameter cannot change what an existing screen prints.
 */

/** The resolved UI language, or undefined to fall back to the browser's. */
export type RecordLocale = string | undefined;

/** A date, or the raw value when it is not parseable, or null when absent. */
export function formatRecordDate(
  value: string | null | undefined,
  locale?: RecordLocale,
): string | null {
  if (!value) return null;
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleDateString(locale);
}

/** A date and time, under the same fallback rule. */
export function formatRecordDateTime(
  value: string | null | undefined,
  locale?: RecordLocale,
): string | null {
  if (!value) return null;
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString(locale);
}
