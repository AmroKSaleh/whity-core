'use client';

import { createContext, useContext, useEffect } from 'react';
import { Direction as RadixDirection } from 'radix-ui';
import { useLanguageDirection } from '@amroksaleh/features/i18n';

/**
 * App-wide text/layout direction (LTR / RTL), DERIVED FROM THE CHOSEN LANGUAGE.
 *
 * Direction is not a preference of its own. Each language record carries its
 * own `direction` ('ltr'/'rtl' — `languages.direction`, migration 090), the
 * LanguageProvider resolves it alongside the language, and this provider is the
 * one place that writes it onto <html dir>. Switching to Arabic therefore
 * mirrors the interface; switching back to English un-mirrors it. Adding
 * Hebrew, Farsi or Urdu is a row in `languages`, not a change here — nothing in
 * this file, or anywhere below it, tests a language code.
 *
 * This context is READ-ONLY. It exists because several components need to know
 * the direction in JS (the sidebar's collapse chevron, the plugin CRUD screen's
 * table affordances) and reading it from one place beats each of them
 * re-deriving it. To CHANGE direction, change the language.
 *
 * Because the per-user language preference is stored on the profile
 * (PATCH /api/v1/settings/language), direction follows the user across devices
 * for free — there is no second thing to persist.
 *
 * Components should style with LOGICAL utilities (ms/me, ps/pe, start/end,
 * border-s/e, text-start/end) so they follow this direction automatically;
 * `rtl:`/`ltr:` variants cover the few transform/icon cases that can't.
 */

export type Direction = 'ltr' | 'rtl';

/**
 * The key the retired manual direction toggle used to write.
 *
 * It is REMOVED on mount and never read. A returning user who had toggled to
 * RTL while running the interface in English would otherwise be stuck in a
 * direction their language contradicts, with no toggle left to undo it — so the
 * language wins and the stale key is cleared rather than migrated. There is
 * nothing to migrate: the value it held was, by the old design's own admission,
 * deliberately independent of the language and so cannot tell us anything about
 * which language the user wants.
 */
const RETIRED_STORAGE_KEY = 'whity.dir';

/**
 * Where the last resolved direction is remembered, purely so the blocking
 * script below can apply it before first paint.
 *
 * NOT a preference, and nothing reads it as one. The language is still the only
 * input to direction; this is a cache of the answer, refreshed on every render
 * that resolves one, and a stale value is corrected within a frame of the
 * provider mounting. It is deliberately separate from the language key: the
 * script must not need to know that 'ar' means RTL, because the whole point of
 * `languages.direction` is that no code anywhere tests a language code.
 */
const DIRECTION_CACHE_KEY = 'whity.dir.resolved';

/** Where LanguageProvider remembers the last resolved language code. */
const LANGUAGE_CACHE_KEY = 'i18n_language';

/**
 * Applies the remembered direction to <html> BEFORE the browser paints.
 *
 * The comment on ThemeModeInitScript used to say a wrong-then-corrected
 * direction, unlike a wrong colour scheme, was not worth blocking for. That is
 * backwards. `dir` was applied in a useEffect that runs after the language has
 * been fetched over the network, so an Arabic user's every full page load began
 * left-to-right and in English: the sidebar on the wrong side, every panel
 * mirrored, text ragged on the wrong edge, then the whole layout jumping when
 * the fetch returned. A colour flash changes how the page looks; this one
 * changes where everything IS.
 *
 * Same shape as ThemeModeInitScript: synchronous, tiny, wrapped in try/catch so
 * a browser with storage disabled falls through to the server-rendered default
 * rather than throwing before any of the bundle has run.
 */
const BLOCKING_SCRIPT = `(function(){try{` +
  `var d=localStorage.getItem('${DIRECTION_CACHE_KEY}');` +
  `if(d==='rtl'||d==='ltr'){document.documentElement.dir=d;}` +
  `var l=localStorage.getItem('${LANGUAGE_CACHE_KEY}');` +
  `if(l){document.documentElement.lang=l;}` +
  `}catch(e){}})();`;

/**
 * Renders the blocking anti-flash script. MUST be placed in <head>, as early as
 * the theme's equivalent, so `dir` lands before the first paint.
 */
export function DirectionInitScript() {
  return <script dangerouslySetInnerHTML={{ __html: BLOCKING_SCRIPT }} />;
}

interface DirectionContextValue {
  dir: Direction;
}

const DirectionContext = createContext<DirectionContextValue | null>(null);

export function DirectionProvider({ children }: { children: React.ReactNode }) {
  const dir = useLanguageDirection();

  // Reflect the language's direction onto <html> (DOM mutation, not React state),
  // and remember it so the next page load can apply it before paint.
  useEffect(() => {
    document.documentElement.dir = dir;
    try {
      localStorage.setItem(DIRECTION_CACHE_KEY, dir);
    } catch {
      // Storage disabled (private mode): the only cost is that the next load
      // starts LTR and corrects itself, which is exactly the old behaviour.
    }
  }, [dir]);

  // Drop the retired toggle's key once, so it cannot linger in a browser
  // profile forever looking like live state.
  useEffect(() => {
    try {
      localStorage.removeItem(RETIRED_STORAGE_KEY);
    } catch {
      // Ignore storage failures (private mode) — the key is never read anyway.
    }
  }, []);

  // RADIX HAS ITS OWN DIRECTION CHANNEL, AND IT DEFAULTS TO LTR.
  //
  // Every Radix primitive resolves direction from `DirectionProvider` context
  // and falls back to 'ltr' when there is none — it does not read <html dir>.
  // `Tabs` acts on that immediately by stamping `dir="ltr"` onto its own root,
  // and CSS direction inherits, so everything inside a tab panel flipped back
  // to left-to-right INSIDE an otherwise correct right-to-left page.
  //
  // The visible damage was worst where it was least obvious. On
  // /admin/document-templates the whole table re-laid out left-to-right — Name
  // in the leftmost column instead of the rightmost — and, because the page
  // around it was still RTL, the table's own width then overflowed the wrong
  // edge and clipped its last two columns off-screen entirely. It reads as a
  // width bug, and no amount of logical-property work would have fixed it.
  //
  // One provider fixes every Radix component at once (Tabs, DropdownMenu,
  // Select, Slider, Toast, ContextMenu…), which is the reason to do it here
  // rather than pass `dir` at each call site: the next component someone adds
  // is correct without anyone remembering.
  return (
    <DirectionContext.Provider value={{ dir }}>
      <RadixDirection.Provider dir={dir}>{children}</RadixDirection.Provider>
    </DirectionContext.Provider>
  );
}

export function useDirection(): DirectionContextValue {
  const ctx = useContext(DirectionContext);
  if (!ctx) {
    throw new Error('useDirection must be used within a DirectionProvider');
  }
  return ctx;
}
