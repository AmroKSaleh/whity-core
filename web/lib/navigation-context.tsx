'use client';

import React, { createContext, useContext, useState, useCallback, useEffect } from 'react';
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

  useEffect(() => {
    if (isAuthLoading) {
      return;
    }

    let cancelled = false;

    // Fetcher defined inside the effect so no setState runs synchronously in
    // the effect body (react-hooks/set-state-in-effect). Signed out, the server
    // would only 401 — expose an empty list without a pointless request (and
    // drop the previous user's nav). Any failure maps to an empty list so the
    // sidebar renders nothing rather than crashing.
    const load = async (): Promise<void> => {
      let fetched: NavigationItem[] = [];
      if (userId !== null) {
        try {
          const response = await fetch('/api/v1/navigation', {
            credentials: 'include',
          });
          if (!response.ok) throw new Error('Failed to fetch navigation');
          const data = await response.json();
          fetched = data.data || [];
        } catch (error) {
          console.error('Error fetching navigation:', error);
        }
      }
      if (!cancelled) {
        setItems(fetched);
        setIsLoading(false);
      }
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [userId, isAuthLoading]);

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
    <NavigationContext.Provider value={{ items, isLoading, getGroupedItems }}>
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
