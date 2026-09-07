/**
 * datetime — the single path a date takes to a screen (#1068).
 *
 * A tenant may set `ui.hide_dates` and be told that no date or time will appear
 * anywhere in the interface. That promise is only as true as its leakiest
 * surface, so there is exactly one way to render one — {@see useDateDisplay} —
 * and `scripts/ci-date-display-guard.php` fails the build on anything that
 * formats a date some other way.
 *
 * The slice is deliberately separate from `record`, where the two functions it
 * replaces used to live. Dates are not a record-page concern: they are on
 * tables, in the inbox, in the audit log, on the status page, inside plugin
 * screens, and a helper that lives inside one feature is a helper the next
 * feature writes its own copy of. Six such copies existed.
 *
 * Usage:
 *   // 1. Seed the preference once, at the app root.
 *   <DateDisplayProvider hidden={preferences.hideDates}>…</DateDisplayProvider>
 *
 *   // 2. Read it wherever a date is rendered.
 *   const { hidden, date, dateTime, relative, dateColumns } = useDateDisplay()
 *
 * Nothing here filters data. Every timestamp is still written, still queryable,
 * still on the wire, and still in the audit trail; this is what the screen does
 * with one.
 */

export {
  DateDisplayProvider,
  DateDisplayContext,
  useDateDisplayPreferences,
  type DateDisplayPreferences,
  type DateDisplayProviderProps,
} from './date-display-context'
export {
  useDateDisplay,
  type DateColumnSpec,
  type DateDisplay,
  type RelativeAgeLabels,
} from './useDateDisplay'
export {
  isDateFieldName,
  parseWireDate,
  relativeAge,
  type DateLocale,
  type RelativeAge,
  type RelativeAgeUnit,
} from './format'
