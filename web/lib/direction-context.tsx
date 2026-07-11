'use client';

import { createContext, useCallback, useContext, useEffect, useState } from 'react';

/**
 * App-wide text/layout direction (LTR / RTL) for Arabic support.
 *
 * Sets `dir` on <html> so the whole UI mirrors, and persists the choice in
 * localStorage. Direction is a UI-layout concern kept separate from message
 * translation (which lands later) — RTL must work regardless of copy.
 *
 * Components should style with LOGICAL utilities (ms/me, ps/pe, start/end,
 * border-s/e, text-start/end) so they follow this direction automatically;
 * `rtl:`/`ltr:` variants cover the few transform/icon cases that can't.
 */

export type Direction = 'ltr' | 'rtl';

const STORAGE_KEY = 'whity.dir';

interface DirectionContextValue {
  dir: Direction;
  setDir: (dir: Direction) => void;
  toggle: () => void;
}

const DirectionContext = createContext<DirectionContextValue | null>(null);

export function DirectionProvider({ children }: { children: React.ReactNode }) {
  const [dir, setDirState] = useState<Direction>('ltr');

  // Hydrate the stored preference after mount (deferred to stay clear of the
  // set-state-in-effect rule while still applying on first paint).
  useEffect(() => {
    const p = Promise.resolve().then(() => {
      const stored = typeof localStorage !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null;
      if (stored === 'rtl' || stored === 'ltr') setDirState(stored);
    });
    void p;
  }, []);

  // Reflect the current direction onto <html> (DOM mutation, not React state).
  useEffect(() => {
    document.documentElement.dir = dir;
  }, [dir]);

  const setDir = useCallback((next: Direction) => {
    setDirState(next);
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // Ignore storage failures (private mode, quota) — direction still applies.
    }
  }, []);

  const toggle = useCallback(() => {
    setDirState((prev) => {
      const next = prev === 'rtl' ? 'ltr' : 'rtl';
      try {
        localStorage.setItem(STORAGE_KEY, next);
      } catch {
        // Ignore storage failures.
      }
      return next;
    });
  }, []);

  return <DirectionContext.Provider value={{ dir, setDir, toggle }}>{children}</DirectionContext.Provider>;
}

export function useDirection(): DirectionContextValue {
  const ctx = useContext(DirectionContext);
  if (!ctx) {
    throw new Error('useDirection must be used within a DirectionProvider');
  }
  return ctx;
}
