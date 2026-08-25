/**
 * Turning a wire timestamp into something a person reads — THE implementation,
 * and the only one (#1068).
 *
 * WHY THIS FILE IS ALLOWED TO CALL `toLocale*` AND NOTHING ELSE IS
 * ----------------------------------------------------------------
 * `ui.hide_dates` promises a tenant that no date reaches any screen. A promise
 * like that is only as true as its leakiest surface, and before #1068 there were
 * six ways a date got onto a page: this pair of helpers, seven private
 * `formatWhen`/`formatStamp`/`formatTimestamp` copies in individual screens,
 * inline `new Date(x).toLocaleString(locale)` at the call site, a `DataTable`
 * column with an `accessorKey` and no `cell` (which renders the raw wire string),
 * two hand-rolled relative-age helpers, and a handful of values interpolated
 * straight into a sentence. Gating one of six would have made the setting 90%
 * true, and the screen that leaked would have been the one nobody checked.
 *
 * So there is now exactly one path, {@see useDateDisplay}, and
 * `scripts/ci-date-display-guard.php` fails the build on any other. This file
 * is the sanctioned inside of that path; everything here is pure, so the guard's
 * exemption for it is a statement about ONE directory rather than a habit.
 *
 * PARSING: ONE READING OF THE WIRE, AND IT IS UTC
 * ----------------------------------------------
 * The two implementations this file replaces disagreed, and the disagreement was
 * invisible. `formatRecordDateTime` used a bare `new Date(value)`; the status and
 * error screens' own helpers normalised first. That matters because PostgreSQL
 * hands back `2026-08-25 14:02:11` — a space, no offset — and `new Date()` reads
 * an offset-less string in the LOCAL zone, so a UTC instant was being printed
 * three hours out on a UTC+3 machine while the same value on the status page was
 * printed correctly. Consolidating forced a choice between two readings of the
 * same string, and the correct one is the one that matches what the server
 * stores. See {@see parseWireDate}.
 *
 * THE LOCALE ARGUMENT
 * -------------------
 * A person who has chosen Arabic in THIS product has told us which language to
 * render in; their browser's Accept-Language is a different statement, often made
 * by whoever set the machine up. So the locale is the resolved UI language,
 * supplied by {@see useDateDisplay} from `useFormattingLocale()` — which is why
 * these functions take it rather than reaching for a hook, and why no call site
 * has to remember to pass it any more. Eight of the twenty old call sites did
 * not, and every one of those screens quietly followed the browser instead.
 */

/** The resolved UI language, or undefined to fall back to the runtime's. */
export type DateLocale = string | undefined

/**
 * A wire timestamp read as the UTC instant the server meant.
 *
 * Accepts the three shapes this platform actually emits: PostgreSQL's
 * `2026-08-25 14:02:11`, SQLite's identical shape, and genuine ISO 8601 with a
 * `Z` or a numeric offset. The first two carry no zone and are UTC by
 * construction — every writer in `src/` uses `gmdate()` or lets the database
 * default to UTC — so the missing designator is supplied rather than guessed at.
 *
 * A value carrying its own offset is left alone: appending `Z` to
 * `2026-08-25T14:02:11+03:00` would produce nonsense, so the check is for a zone
 * DESIGNATOR and not merely for a `T`.
 *
 * @returns the instant, or null when the value is absent or unparseable.
 */
export function parseWireDate(value: string | null | undefined): Date | null {
  if (value === null || value === undefined || value === '') return null

  const trimmed = value.trim()
  // `+`/`-` only count as an offset in the TIME half; a bare date is all
  // hyphens, and `2026-08-25` must not be read as carrying a zone.
  const timeHalf = trimmed.slice(11)
  const hasZone = /[Zz]$/.test(trimmed) || /[+-]\d{2}:?\d{2}$/.test(timeHalf)
  const normalised = trimmed.replace(' ', 'T') + (hasZone ? '' : 'Z')

  const parsed = new Date(normalised)

  return Number.isNaN(parsed.getTime()) ? null : parsed
}

/**
 * A calendar date, or null when the value is absent or unparseable.
 *
 * NULL RATHER THAN THE RAW VALUE, which is the one behavioural change from the
 * function this replaces. That one returned the wire string when it could not
 * parse it, so that a malformed value rendered as ITSELF rather than as "Invalid
 * Date" — a reasonable instinct that becomes a hole the moment a tenant asks for
 * dates to be gone: the caller writes `?? raw`, the formatter is bypassed, and a
 * timestamp appears on a screen that promised none. Callers now say what an
 * absent date looks like with a LITERAL, and the guard refuses a fallback that
 * is anything else.
 */
export function formatDate(value: string | null | undefined, locale: DateLocale): string | null {
  const parsed = parseWireDate(value)

  return parsed === null ? null : parsed.toLocaleDateString(locale)
}

/** A date and a time, under {@see formatDate}'s rules. */
export function formatDateTime(value: string | null | undefined, locale: DateLocale): string | null {
  const parsed = parseWireDate(value)

  return parsed === null ? null : parsed.toLocaleString(locale)
}

/** The coarsest unit in which an age still reads as a duration. */
export type RelativeAgeUnit = 'seconds' | 'minutes' | 'hours' | 'days'

/** How long ago something was, as a magnitude and the unit to say it in. */
export interface RelativeAge {
  unit: RelativeAgeUnit
  n: number
}

/**
 * An age, bucketed — the arithmetic half of "3m ago", with no words in it.
 *
 * The words stay at the call site because each unit is its own translation key:
 * the unit letter and the word order both move between languages, so `{n}` has
 * to sit INSIDE the string rather than in front of it. What was duplicated
 * between the status page and the error list was never the strings; it was these
 * four thresholds, and they are what lives here.
 *
 * Never negative: a clock a few seconds ahead of the server should read "0s ago",
 * not "in 4 seconds", which would be a whole tense the strings do not have.
 */
export function relativeAge(value: string | null | undefined, now: number = Date.now()): RelativeAge | null {
  const parsed = parseWireDate(value)
  if (parsed === null) return null

  const seconds = Math.max(0, Math.round((now - parsed.getTime()) / 1000))

  if (seconds < 90) return { unit: 'seconds', n: seconds }
  if (seconds < 5400) return { unit: 'minutes', n: Math.round(seconds / 60) }
  if (seconds < 172800) return { unit: 'hours', n: Math.round(seconds / 3600) }

  return { unit: 'days', n: Math.round(seconds / 86400) }
}

/**
 * Whether a field NAME reads as a timestamp.
 *
 * A heuristic, and it is one because the alternative does not exist yet: not a
 * single field in `public/openapi.json` carries `format: date-time` (the
 * generator emits a bare `{"type": "string"}` for every one of them), and a
 * plugin's schema is whatever that plugin declared. So the schema-driven
 * surfaces — the plugin CRUD table, whose columns are derived from an item
 * schema nobody in this repo wrote — have the field's name and nothing else to
 * go on.
 *
 * Deliberately conservative. It matches the suffixes this platform actually
 * uses for recorded timestamps (`created_at`, `createdAt`, `birthDate`,
 * `timestamp`, `expires`) and does NOT match a field merely containing the
 * substring "date" somewhere, so `update_mandate` or `candidate_name` are safe.
 * A false positive here hides a column a tenant wanted; a false negative leaves
 * a date on a screen that promised none, so where the two are in tension this
 * errs towards hiding — but only for names that genuinely read as timestamps.
 */
export function isDateFieldName(name: string): boolean {
  return /(^|_|\b)(at|date|time|timestamp|expires|deadline|since|until)$/i.test(name)
    || /[a-z0-9](At|Date|Time|Timestamp|Expires|Deadline|Since|Until)$/.test(name)
}
