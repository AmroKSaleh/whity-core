/**
 * The shared theme-mode contract every Whity client (web, desktop, Flutter)
 * must follow for a toggle built in one client to look identical in another:
 *
 *   - Strategy: a `.dark` class on the root element (`<html>` on web) — the
 *     exact selector `@custom-variant dark (&:is(.dark *));` (see
 *     @amroksaleh/tokens/css) and every `.dark { ... }` token block target.
 *     No class means light mode.
 *   - Persistence key: `whity.theme`, storing the raw preference —
 *     'light' | 'dark' | 'system' — not the resolved value.
 *   - Default preference: 'system' (resolved against the OS/platform
 *     light-dark setting) when nothing is stored yet.
 *   - FOUC: a resolved-then-corrected color scheme is highly visible (a white
 *     flash for a dark-mode user), so the class must be applied synchronously
 *     before first paint — see buildThemeInitScript() below for the web
 *     version of that blocking step. A native client (desktop, Flutter)
 *     should apply the equivalent of resolveIsDark() as early as its own
 *     platform allows (e.g. before the first frame is composited).
 *
 * This module is the framework-agnostic (no React) half of the contract, so
 * it can be imported by both the React provider (web/lib/theme-mode-context.tsx)
 * and any other JS/TS client without pulling React in. Non-JS clients
 * (Flutter) can't import this file directly — they must replicate the same
 * key, values, and resolution rule by hand.
 */

export type ThemeModePreference = 'light' | 'dark' | 'system';
export type ResolvedThemeMode = 'light' | 'dark';

export const THEME_STORAGE_KEY = 'whity.theme';

export function isThemeModePreference(value: unknown): value is ThemeModePreference {
  return value === 'light' || value === 'dark' || value === 'system';
}

/** Resolve a stored preference (or its absence) against the OS/platform preference. */
export function resolveIsDark(preference: ThemeModePreference | null | undefined, systemPrefersDark: boolean): boolean {
  return preference === 'dark' || (preference !== 'light' && systemPrefersDark);
}

/**
 * Source for a blocking `<script>` that must run as early as possible in
 * `<head>` (before any stylesheet / styled content) so `.dark` lands on
 * `<html>` before the browser's first paint. Kept as a standalone string
 * (rather than a function reference) since it has to execute before any
 * bundle code — see web/lib/theme-mode-context.tsx's ThemeModeInitScript for
 * the actual `<script>` element.
 */
export function buildThemeInitScript(): string {
  const key = JSON.stringify(THEME_STORAGE_KEY);
  return `(function(){try{var k=${key};var s=localStorage.getItem(k);var d=s==='dark'||(s!=='light'&&window.matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.classList.toggle('dark',d);}catch(e){}})();`;
}
