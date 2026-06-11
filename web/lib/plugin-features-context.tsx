'use client';

import React, { createContext, useContext, useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import {
  fetchPluginFeatures,
  type PluginFeature,
} from '@/lib/plugin-features';

interface PluginFeaturesContextType {
  /** Server-side permission-filtered features for the current user. */
  features: PluginFeature[];
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
        ? Promise.resolve<PluginFeature[]>([])
        : fetchPluginFeatures());
      if (!cancelled) {
        setFeatures(fetched);
        setIsLoading(false);
      }
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [userId, isAuthLoading]);

  return (
    <PluginFeaturesContext.Provider value={{ features, isLoading }}>
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
