'use client'

/**
 * useDateDisplay — the ONE way a date reaches a screen (#1068).
 *
 * ```tsx
 * const { hidden, dateTime } = useDateDisplay()
 * …
 * <RecordListItem primary={person.name} secondary={dateTime(person.created_at) ?? EMPTY} />
 * ```
 *
 * WHY A HOOK AND NOT A FUNCTION
 * -----------------------------
 * Two things a call site should never have to remember travel with it: the
 * resolved UI language, and whether this tenant wants dates on screen at all.
 * Eight of the twenty call sites of the function this replaces forgot the first
 * one, and those screens quietly formatted in the browser's locale instead of
 * the reader's — an Arabic reader on an `en-US` machine got `8/24/2026, 5:47 PM`
 * inside a right-to-left sentence. A parameter that can be forgotten will be;
 * a hook that supplies both cannot.
 *
 * WHAT `hidden` DOES TO EACH RETURN VALUE
 * ---------------------------------------
 * Every formatter returns `null`, exactly as it does for an absent value. That
 * is the whole gate, and putting it here rather than at seventy call sites is
 * the point of the file.
 *
 * `null` and not `''`, deliberately: a caller writes `?? EMPTY_VALUE` and gets an
 * em dash where a date was, which is right in a two-column layout where the
 * label is already on screen. Where the label is NOT wanted either — a table
 * column headed "Created", a `<dt>` reading "First issued" — the caller reads
 * `hidden` and drops the whole thing, because a column of em dashes under a
 * header that says "Created" is worse than no column. {@see dateColumns} is that
 * decision for the table case, written once.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 * Relative phrasing is not a middle ground. "last week" is still a date, and a
 * tenant that asked for dates off the screen has not asked for vaguer ones — so
 * {@see relative} is gated exactly as the absolute formatters are.
 *
 * It has no bearing on what may be EDITED. A date INPUT is a control a person
 * types into, not the platform's account of when work happened; blanking one
 * would break the form and delete a value the user themselves put there. The
 * rule is: read-only rendering of a date is hidden, an editable control is not.
 */

import { useCallback, useMemo, type ReactNode } from 'react'
import type { DataTableColumn } from '@amroksaleh/ui/data-table'

import { useFormattingLocale } from '../i18n/useFormattingLocale'
import { useDateDisplayPreferences } from './date-display-context'
import {
  formatDate,
  formatDateTime,
  relativeAge,
  type RelativeAge,
  type RelativeAgeUnit,
} from './format'

/** One translated string per magnitude — see {@see relativeAge} for why. */
export type RelativeAgeLabels = {
  [unit in RelativeAgeUnit]: (n: number) => string
}

/**
 * A date column, described rather than built, so the decision to drop it when
 * dates are hidden is made in one place instead of nine.
 */
export interface DateColumnSpec<TData> {
  /** Column id, as `DataTableColumn.id`. */
  id: string
  header: ReactNode
  /** The wire timestamp for a row. */
  value: (row: TData) => string | null | undefined
  /** Whether to print the time of day as well. Defaults to true. */
  withTime?: boolean
  /** What an absent or unparseable value looks like. Defaults to an em dash. */
  empty?: string
  enableSorting?: boolean
  size?: number
  className?: string
}

export interface DateDisplay {
  /**
   * True when this tenant has asked for dates off the screen. Read it to drop a
   * whole column, row or label; the formatters below already return null.
   */
  readonly hidden: boolean
  /** A calendar date, or null when hidden, absent or unparseable. */
  date(value: string | null | undefined): string | null
  /** A date and a time, under the same rule. */
  dateTime(value: string | null | undefined): string | null
  /** A bucketed age, or null under the same rule. {@see relativeAge}. */
  age(value: string | null | undefined): RelativeAge | null
  /** A bucketed age, already put into words by the caller's own labels. */
  relative(value: string | null | undefined, labels: RelativeAgeLabels): string | null
  /** The given date columns, or none of them when dates are hidden. */
  dateColumns<TData>(specs: DateColumnSpec<TData>[]): DataTableColumn<TData>[]
}

const EM_DASH = '—'

export function useDateDisplay(): DateDisplay {
  const { hidden } = useDateDisplayPreferences()
  const locale = useFormattingLocale()

  const date = useCallback(
    (value: string | null | undefined): string | null => (hidden ? null : formatDate(value, locale)),
    [hidden, locale]
  )

  const dateTime = useCallback(
    (value: string | null | undefined): string | null => (hidden ? null : formatDateTime(value, locale)),
    [hidden, locale]
  )

  const age = useCallback(
    (value: string | null | undefined): RelativeAge | null => (hidden ? null : relativeAge(value)),
    [hidden]
  )

  const relative = useCallback(
    (value: string | null | undefined, labels: RelativeAgeLabels): string | null => {
      const bucketed = age(value)

      return bucketed === null ? null : labels[bucketed.unit](bucketed.n)
    },
    [age]
  )

  const dateColumns = useCallback(
    <TData,>(specs: DateColumnSpec<TData>[]): DataTableColumn<TData>[] => {
      if (hidden) return []

      return specs.map((spec) => ({
        id: spec.id,
        header: spec.header,
        // Always a `cell`, never a bare `accessorKey`. A DataTable column with
        // an accessorKey and no cell renders `String(value)` — the raw wire
        // string — which is how four admin tables were printing
        // `2026-08-25 14:02:11` at readers long before this setting existed.
        cell: (row: TData) =>
          (spec.withTime === false
            ? formatDate(spec.value(row), locale)
            : formatDateTime(spec.value(row), locale)) ?? spec.empty ?? EM_DASH,
        enableSorting: spec.enableSorting,
        size: spec.size,
        className: spec.className,
      }))
    },
    [hidden, locale]
  )

  return useMemo<DateDisplay>(
    () => ({ hidden, date, dateTime, age, relative, dateColumns }),
    [hidden, date, dateTime, age, relative, dateColumns]
  )
}
