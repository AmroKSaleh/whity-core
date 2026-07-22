'use client';

import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import {
  buildThemeInitScript,
  isThemeModePreference,
  resolveIsDark,
  THEME_STORAGE_KEY,
  type ResolvedThemeMode,
  type ThemeModePreference,
} from '@amroksaleh/ui/theme-mode';

/**
 * App-wide color scheme (light / dark / system), mirroring the
 * DirectionProvider pattern (lib/direction-context.tsx) for LTR/RTL.
 *
 * Sets/removes the `.dark` class on <html> (the exact selector Tailwind's
 * `@custom-variant dark (&:is(.dark *));` — see globals.css — and every
 * generated `.dark { ... }` token block already target) and persists the raw
 * preference ('light' | 'dark' | 'system') in localStorage.
 *
 * FOUC: unlike direction, a wrong-then-corrected color scheme is highly
 * visible (a white flash for a dark-mode user). That's handled by a small
 * BLOCKING inline script rendered in <head> (see ThemeModeInitScript below),
 * which runs before first paint and applies the class synchronously — this
 * provider's own effects only keep DOM state in sync with subsequent
 * user-driven changes, they never own the very first paint.
 *
 * The storage key, preference type, and resolution rule are the shared
 * cross-client contract — see @amroksaleh/ui/theme-mode (and its module doc
 * comment) for the framework-agnostic half other clients replicate.
 */

export type { ThemeModePreference, ResolvedThemeMode };

const STORAGE_KEY = THEME_STORAGE_KEY;

/**
 * Inline script source shared between the blocking <head> script and the
 * provider's own logic, so the "how do we resolve a preference" rule lives
 * in exactly one place conceptually (duplicated verbatim into the script
 * string since it must run standalone, before any React/bundle code).
 */
const BLOCKING_SCRIPT = buildThemeInitScript();

/**
 * Renders the blocking anti-FOUC script. MUST be placed as early as possible
 * in <head> (before globals.css / any styled content) so the `.dark` class
 * lands before the browser's first paint.
 */
export function ThemeModeInitScript() {
  return <script dangerouslySetInnerHTML={{ __html: BLOCKING_SCRIPT }} />;
}

interface ThemeModeContextValue {
  /** The raw stored preference, including 'system'. */
  preference: ThemeModePreference;
  /** The actually-applied scheme ('system' resolved against the OS). */
  resolved: ResolvedThemeMode;
  setPreference: (pref: ThemeModePreference) => void;
  /** Cycles light -> dark -> light (system is adopted only via setPreference). */
  toggle: () => void;
}

const ThemeModeContext = createContext<ThemeModeContextValue | null>(null);

function systemPrefersDark(): boolean {
  return typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

export function ThemeModeProvider({ children }: { children: React.ReactNode }) {
  // Default assumes the blocking script already applied the right class
  // to <html> before mount; resolved state is only read back from the DOM
  // after mount to avoid a hydration mismatch (SSR always renders 'light').
  const [preference, setPreferenceState] = useState<ThemeModePreference>('system');
  const [resolved, setResolved] = useState<ResolvedThemeMode>('light');

  // Hydrate the stored preference + the class the blocking script already
  // applied (deferred to stay clear of the set-state-in-effect rule while
  // still applying on first paint, matching DirectionProvider's pattern).
  useEffect(() => {
    const p = Promise.resolve().then(() => {
      const stored = typeof localStorage !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null;
      if (isThemeModePreference(stored)) {
        setPreferenceState(stored);
      }
      setResolved(document.documentElement.classList.contains('dark') ? 'dark' : 'light');
    });
    void p;
  }, []);

  // Reflect preference changes onto <html> + track system-preference changes
  // while 'system' is active.
  useEffect(() => {
    const apply = () => {
      const dark = resolveIsDark(preference, systemPrefersDark());
      document.documentElement.classList.toggle('dark', dark);
      setResolved(dark ? 'dark' : 'light');
    };
    apply();

    if (preference !== 'system') return;
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    mq.addEventListener('change', apply);
    return () => mq.removeEventListener('change', apply);
  }, [preference]);

  const setPreference = useCallback((next: ThemeModePreference) => {
    setPreferenceState(next);
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // Ignore storage failures (private mode, quota) — the scheme still applies.
    }
  }, []);

  const toggle = useCallback(() => {
    setPreferenceState((prev) => {
      const currentlyDark = resolveIsDark(prev, systemPrefersDark());
      const next: ThemeModePreference = currentlyDark ? 'light' : 'dark';
      try {
        localStorage.setItem(STORAGE_KEY, next);
      } catch {
        // Ignore storage failures.
      }
      return next;
    });
  }, []);

  return (
    <ThemeModeContext.Provider value={{ preference, resolved, setPreference, toggle }}>
      {children}
    </ThemeModeContext.Provider>
  );
}

export function useThemeMode(): ThemeModeContextValue {
  const ctx = useContext(ThemeModeContext);
  if (!ctx) {
    throw new Error('useThemeMode must be used within a ThemeModeProvider');
  }
  return ctx;
}
