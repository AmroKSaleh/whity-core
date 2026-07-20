'use client';

import React, { createContext, useContext, useState, useCallback, useEffect, useRef } from 'react';
import { useAuth } from '@/lib/auth-context';

export interface NavigationItem {
  id: string;
  label: string;
  href: string;
  icon: string;
  group?: string;
  order: number;
}

interface NavigationContextType {
  items: NavigationItem[];
  isLoading: boolean;
  getGroupedItems: () => Map<string, NavigationItem[]>;
  /**
   * Re-run the `/api/v1/navigation` fetch for the current user and apply the
   * result. Resolves once the new (RBAC-filtered) list is in state, so callers
   * that just changed plugin state can `await` the sidebar reflecting it —
   * without a full page reload. A no-op (resolves immediately) when signed out.
   */
  refresh: () => Promise<void>;
  /**
   * Optimistically drop nav items whose `href` is in `hrefs` from the local
   * list, BEFORE any refetch resolves. Used by the plugins console to make a
   * disabled/uninstalled plugin's contributed links vanish instantly; a
   * subsequent {@link refresh} then reconciles with the server.
   */
  removeItemsByHref: (hrefs: readonly string[]) => void;
}

const NavigationContext = createContext<NavigationContextType | undefined>(undefined);

/**
 * Auth-aware source of the sidebar navigation.
 *
 * `GET /api/navigation` is server-side RBAC-filtered, so the list is only
 * meaningful PER AUTHENTICATED USER: the provider waits for auth to settle,
 * fetches when (and only when) a user is signed in, clears the list on logout,
 * and refetches when the signed-in user changes. Fetching once on mount would
 * capture the PRE-AUTH state for the whole SPA session — and since
 * `/api/navigation` now 401s when unauthenticated (WC-175 / #191), that one-shot
 * pre-login fetch pinned an empty sidebar for every role after an in-context
 * login until a hard refresh.
 *
 * Mounted inside AuthProvider in the root layout, alongside
 * PluginFeaturesProvider.
 */
export function NavigationProvider({ children }: { children: React.ReactNode }) {
  const { user, isLoading: isAuthLoading } = useAuth();
  const [items, setItems] = useState<NavigationItem[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  // The stable identity driving refetches: a login, logout, or user switch
  // changes it; token refreshes for the same user do not.
  const userId = user !== null ? user.id : null;

  // Mirror userId into a ref so the stable `refresh` callback can read the
  // current signed-in user without being re-created (and without becoming a
  // dependency that would force consumers to re-render on every auth tick).
  // The ref is synced in an effect rather than during render (a render-phase
  // ref write is a React anti-pattern); the value only needs to be current by
  // the time refresh() is INVOKED from an event handler, which is always after
  // the commit that ran this effect.
  const userIdRef = useRef<number | null>(userId);
  useEffect(() => {
    userIdRef.current = userId;
  }, [userId]);

  // Fetch the current user's RBAC-filtered nav list. Signed out, the server
  // would only 401 — return an empty list without a pointless request. Any
  // failure maps to an empty list so the sidebar renders nothing rather than
  // crashing. Shared by the mount/auth effect and the imperative refresh().
  const fetchNavigation = useCallback(async (): Promise<NavigationItem[]> => {
    if (userIdRef.current === null) {
      return [];
    }
    // Bounded so an unhealthy backend degrades to an empty sidebar (still
    // caught below) instead of hanging every admin page forever — this
    // provider wraps the whole authenticated app (root layout), so an
    // unbounded hang here blocks EVERY page, not just one. A plain
    // setTimeout+abort rather than AbortSignal.timeout(), which (like
    // AbortSignal.any()) is unsupported in the jsdom test environment this
    // is exercised under.
    const controller = new AbortController();
    const hangGuard = setTimeout(() => controller.abort(), 15_000);
    try {
      const response = await fetch('/api/v1/navigation', {
        credentials: 'include',
        signal: controller.signal,
      });
      if (!response.ok) throw new Error('Failed to fetch navigation');
      const data = await response.json();
      return data.data || [];
    } catch (error) {
      console.error('Error fetching navigation:', error);
      return [];
    } finally {
      clearTimeout(hangGuard);
    }
  }, []);

  useEffect(() => {
    if (isAuthLoading) {
      return;
    }

    let cancelled = false;

    // setState is deferred into this async fetcher so none runs synchronously
    // in the effect body (react-hooks/set-state-in-effect).
    const load = async (): Promise<void> => {
      const fetched = await fetchNavigation();
      if (!cancelled) {
        setItems(fetched);
        setIsLoading(false);
      }
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [userId, isAuthLoading, fetchNavigation]);

  // Imperative refetch for callers that just mutated server state (e.g. the
  // plugins console disabling a plugin) and want the sidebar to reflect it
  // without a full page reload. Resolves once the new list is applied.
  const refresh = useCallback(async (): Promise<void> => {
    const fetched = await fetchNavigation();
    setItems(fetched);
    setIsLoading(false);
  }, [fetchNavigation]);

  // Optimistic, local-only removal: drop items whose href is in `hrefs`. Lets
  // the plugins console make a disabled/uninstalled plugin's links disappear
  // instantly; refresh() then reconciles with the authoritative server list.
  const removeItemsByHref = useCallback((hrefs: readonly string[]): void => {
    if (hrefs.length === 0) {
      return;
    }
    const drop = new Set(hrefs);
    setItems((prev) => prev.filter((item) => !drop.has(item.href)));
  }, []);

  const getGroupedItems = useCallback(() => {
    const grouped = new Map<string, NavigationItem[]>();
    grouped.set('_ungrouped', []);

    // Sort by `order` ascending so the client is robust regardless of the order
    // the API returns items in. Array.prototype.sort is stable (ES2019+), so
    // items sharing an `order` keep their original relative order.
    const sortedItems = [...items].sort((a, b) => a.order - b.order);

    // Group items by group property
    sortedItems.forEach((item) => {
      const groupId = item.group || '_ungrouped';
      if (!grouped.has(groupId)) {
        grouped.set(groupId, []);
      }
      grouped.get(groupId)!.push(item);
    });

    return grouped;
  }, [items]);

  return (
    <NavigationContext.Provider
      value={{ items, isLoading, getGroupedItems, refresh, removeItemsByHref }}
    >
      {children}
    </NavigationContext.Provider>
  );
}

export function useNavigation() {
  const context = useContext(NavigationContext);
  if (!context) {
    throw new Error('useNavigation must be used within NavigationProvider');
  }
  return context;
}
