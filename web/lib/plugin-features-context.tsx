'use client';

import React, { createContext, useContext, useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import {
  fetchPluginFeatures,
  type DroppedPluginFeature,
  type PluginFeature,
} from '@/lib/plugin-features';

interface PluginFeaturesContextType {
  /** Server-side permission-filtered features for the current user. */
  features: PluginFeature[];
  /**
   * Feature descriptors the host REFUSED, and why (issue #953). Empty unless
   * the caller holds `plugins:read` — the server sends the key to nobody else.
   * Carried here rather than fetched again by the plugin console: this
   * provider already holds the one response that knows, and a second request
   * for the same body could disagree with the navigation built from the first.
   */
  dropped: DroppedPluginFeature[];
  /** True until auth has settled and the fetch for the current user resolved. */
  isLoading: boolean;
}

const PluginFeaturesContext = createContext<
  PluginFeaturesContextType | undefined
>(undefined);

/**
 * Auth-aware source of the plugin feature list.
 *
 * The features endpoint is server-side permission-filtered, so the list is
 * only meaningful PER AUTHENTICATED USER: the provider waits for auth to
 * settle, fetches when (and only when) a user is signed in, clears the list
 * on logout, and refetches when the signed-in user changes. Fetching once on
 * mount would capture the PRE-AUTH (empty) list for the whole SPA session —
 * the "Feature unavailable after login until hard refresh" bug found in the
 * WC-169 adversarial review.
 *
 * Mounted inside AuthProvider in the root layout, alongside NavigationProvider.
 */
export function PluginFeaturesProvider({
  children,
}: {
  children: React.ReactNode;
}) {
  const { user, isLoading: isAuthLoading } = useAuth();
  const [features, setFeatures] = useState<PluginFeature[]>([]);
  const [dropped, setDropped] = useState<DroppedPluginFeature[]>([]);
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
    // the effect body (react-hooks/set-state-in-effect); the signed-out
    // branch awaits a resolved promise for the same reason. Signed out, the
    // server would only 401 — expose an empty list without a pointless
    // request (and drop the previous user's features). fetchPluginFeatures
    // already maps every failure to [], so no error branch is needed here.
    const load = async (): Promise<void> => {
      const fetched = await (userId === null
        ? Promise.resolve({ features: [], dropped: [] })
        : fetchPluginFeatures());
      if (!cancelled) {
        setFeatures(fetched.features);
        setDropped(fetched.dropped);
        setIsLoading(false);
      }
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [userId, isAuthLoading]);

  return (
    <PluginFeaturesContext.Provider value={{ features, dropped, isLoading }}>
      {children}
    </PluginFeaturesContext.Provider>
  );
}

export function usePluginFeatures(): PluginFeaturesContextType {
  const context = useContext(PluginFeaturesContext);
  if (!context) {
    throw new Error(
      'usePluginFeatures must be used within PluginFeaturesProvider'
    );
  }
  return context;
}
