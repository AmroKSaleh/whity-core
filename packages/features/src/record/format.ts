/**
 * Rendering the timestamps a record page is full of.
 *
 * Every admin screen here has its own copy of this guard, and the guard is the
 * point: a malformed or non-ISO value from the wire must render as ITSELF rather
 * than as the string "Invalid Date", which tells a reader nothing and looks like
 * a product bug rather than a data one. Two copies already disagreed about
 * whether an empty string is a date; a third would have made it three.
 *
 * `toLocaleDateString`/`toLocaleString` with no explicit locale follow the
 * browser's, which is what an Arabic/RTL install wants — the app's own locale
 * negotiation happens above this, and hard-coding one here would print Gregorian
 * English dates onto an Arabic page.
 */

/** A date, or the raw value when it is not parseable, or null when absent. */
export function formatRecordDate(value: string | null | undefined): string | null {
  if (!value) return null;
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleDateString();
}

/** A date and time, under the same fallback rule. */
export function formatRecordDateTime(value: string | null | undefined): string | null {
  if (!value) return null;
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString();
}
