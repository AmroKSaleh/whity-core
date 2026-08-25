'use client'

/**
 * Whether dates are shown at all, as a context every surface reads (#1068).
 *
 * DUMB ON PURPOSE. This provider is handed the answer; it does not fetch one.
 * The same split {@see BrandingProvider} uses in the web app: resolving a value
 * needs an app's cookies, its host header, its auth context and its idea of when
 * the active tenant has changed, and none of that belongs in a package three
 * different clients import. Web seeds it from the server render and refreshes it
 * on a tenant switch; a Tauri or Vite client seeds it from whatever it has.
 *
 * NON-THROWING WHEN UNMOUNTED, and for the reason {@see useFormattingLocale} is:
 * it is read from components that also render with no provider above them
 * (Storybook, isolated unit tests, a client that has not wired this up yet).
 * Falling back to `hidden: false` there is not a degraded mode — it is exactly
 * the behaviour that existed before this feature, so an unwired surface renders
 * precisely as it did.
 *
 * FAILS OPEN, unlike {@see CapabilitiesProvider}, and the asymmetry is
 * deliberate. A permission check that guesses wrong grants access; this one
 * decides whether a label is printed. Nothing behind it is filtered — the
 * timestamps are on the wire either way, and any reader can fetch them from the
 * API — so there is no secret that failing shut would protect, and the cost of
 * failing shut is every date on every screen disappearing while a request is in
 * flight, which reads as the product breaking.
 */

import { createContext, useContext, useMemo, type ReactNode } from 'react'

/** What the shell knows about how this tenant wants its interface to look. */
export interface DateDisplayPreferences {
  /**
   * True when this tenant has asked for dates and times to be off the screen
   * entirely (`ui.hide_dates`). A DISPLAY fact and nothing more: every
   * timestamp is still recorded, still queryable, still on the wire, and still
   * in the audit trail.
   */
  hidden: boolean
}

export const DateDisplayContext = createContext<DateDisplayPreferences | null>(null)

export interface DateDisplayProviderProps extends DateDisplayPreferences {
  children: ReactNode
}

export function DateDisplayProvider({ hidden, children }: DateDisplayProviderProps) {
  const value = useMemo<DateDisplayPreferences>(() => ({ hidden }), [hidden])

  return <DateDisplayContext.Provider value={value}>{children}</DateDisplayContext.Provider>
}

/**
 * The raw preference, for the rare surface that needs the flag and not the
 * formatters — a table dropping a whole column, a card dropping a whole row.
 *
 * Most callers want {@see useDateDisplay} instead, which gives them this flag
 * AND the only sanctioned way to render a date.
 */
export function useDateDisplayPreferences(): DateDisplayPreferences {
  return useContext(DateDisplayContext) ?? { hidden: false }
}
