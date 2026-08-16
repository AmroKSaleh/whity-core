import { createContext, useCallback, useContext, useEffect, useState } from "react"
import {
  isThemeModePreference,
  resolveIsDark,
  THEME_STORAGE_KEY,
  type ResolvedThemeMode,
  type ThemeModePreference,
} from "@amroksaleh/ui/theme-mode"

/**
 * App-wide color scheme (light / dark / system) — a direct port of
 * web/lib/theme-mode-context.tsx's provider, sharing the SAME cross-client
 * contract (`@amroksaleh/ui/theme-mode`: the `.dark`-class strategy, the
 * `whity.theme` storage key, the light/dark/system resolution rule) so a
 * toggle here looks identical to the website's.
 *
 * What did NOT port: the website's blocking anti-FOUC `<script>` in `<head>`
 * (`ThemeModeInitScript`) — that trick exists only because Next.js
 * server-renders HTML that then hydrates, so a wrong-then-corrected class is
 * visible as a flash. A Tauri window loads this SPA's JS bundle fresh on
 * every launch with no server-rendered HTML to flash-then-correct, so
 * applying the resolved class synchronously before the first `render()` call
 * (see `main.tsx`) is the equivalent, simpler step for this client.
 */

export type { ThemeModePreference, ResolvedThemeMode }

interface ThemeModeContextValue {
  /** The raw stored preference, including 'system'. */
  preference: ThemeModePreference
  /** The actually-applied scheme ('system' resolved against the OS). */
  resolved: ResolvedThemeMode
  setPreference: (pref: ThemeModePreference) => void
  /** Cycles light -> dark -> light (system is adopted only via setPreference). */
  toggle: () => void
}

const ThemeModeContext = createContext<ThemeModeContextValue | null>(null)

function systemPrefersDark(): boolean {
  return typeof window !== "undefined" && window.matchMedia("(prefers-color-scheme: dark)").matches
}

export function ThemeModeProvider({ children }: { children: React.ReactNode }) {
  // main.tsx already applied the right class + read the stored preference
  // before this module's state exists — read both back from the DOM/storage
  // on first render rather than defaulting blind, so there's no flash of the
  // wrong toggle icon on mount.
  const initialResolved: ResolvedThemeMode =
    typeof document !== "undefined" && document.documentElement.classList.contains("dark") ? "dark" : "light"
  const initialPreference: ThemeModePreference = (() => {
    const stored = typeof localStorage !== "undefined" ? localStorage.getItem(THEME_STORAGE_KEY) : null
    return isThemeModePreference(stored) ? stored : "system"
  })()

  const [preference, setPreferenceState] = useState<ThemeModePreference>(initialPreference)
  const [resolved, setResolved] = useState<ResolvedThemeMode>(initialResolved)

  // Reflect preference changes onto <html> + track system-preference changes
  // while 'system' is active.
  useEffect(() => {
    const apply = () => {
      const dark = resolveIsDark(preference, systemPrefersDark())
      document.documentElement.classList.toggle("dark", dark)
      setResolved(dark ? "dark" : "light")
    }
    apply()

    if (preference !== "system") return
    const mq = window.matchMedia("(prefers-color-scheme: dark)")
    mq.addEventListener("change", apply)
    return () => mq.removeEventListener("change", apply)
  }, [preference])

  const setPreference = useCallback((next: ThemeModePreference) => {
    setPreferenceState(next)
    try {
      localStorage.setItem(THEME_STORAGE_KEY, next)
    } catch {
      // Ignore storage failures (quota, disabled) — the scheme still applies.
    }
  }, [])

  const toggle = useCallback(() => {
    setPreferenceState((prev) => {
      const currentlyDark = resolveIsDark(prev, systemPrefersDark())
      const next: ThemeModePreference = currentlyDark ? "light" : "dark"
      try {
        localStorage.setItem(THEME_STORAGE_KEY, next)
      } catch {
        // Ignore storage failures.
      }
      return next
    })
  }, [])

  return (
    <ThemeModeContext.Provider value={{ preference, resolved, setPreference, toggle }}>
      {children}
    </ThemeModeContext.Provider>
  )
}

export function useThemeMode(): ThemeModeContextValue {
  const ctx = useContext(ThemeModeContext)
  if (!ctx) {
    throw new Error("useThemeMode must be used within a ThemeModeProvider")
  }
  return ctx
}
