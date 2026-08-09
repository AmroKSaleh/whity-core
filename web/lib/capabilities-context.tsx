'use client';

import React, { createContext, useContext, useState, useCallback, useEffect, useMemo } from 'react';
import { useAuth } from '@/lib/auth-context';
import { parsePermissions } from '@/lib/capabilities';

/**
 * The shared, tenant-scoped capability surface (WC-663f6b6b).
 *
 * `has`/`hasAny`/`hasAll` are the canonical checks; `hasPermission` is kept as
 * a back-compat alias of `has` for the many call sites that predate this
 * provider (WC-176/WC-177, #205) and still destructure that name.
 *
 * SECURITY CONTRACT — fail-closed: while `loading` is true (including the
 * in-flight window right after the active user or tenant changes), on any
 * fetch error, or on a malformed response body, every check returns `false`.
 * There is no code path that returns `true` without a successfully parsed,
 * current-user-and-tenant permission set. The server remains authoritative —
 * these slugs are UI hints only (hide/disable controls) and grant nothing.
 */
export interface CapabilitiesContextValue {
  /** The caller's resolved permission slugs, or `[]` while loading, signed out, or on error. */
  permissions: string[];
  /** True until the fetch for the CURRENT user+tenant pair has resolved. */
  loading: boolean;
  /** Fail-closed: `true` only once loaded and `capability` is held. */
  has: (capability: string) => boolean;
  /** Fail-closed: `true` once loaded and at least one of `capabilities` is held. */
  hasAny: (capabilities: readonly string[]) => boolean;
  /** Fail-closed: `true` once loaded and every one of `capabilities` is held. */
  hasAll: (capabilities: readonly string[]) => boolean;
  /** @deprecated Back-compat alias for {@link has}. */
  hasPermission: (capability: string) => boolean;
}

const CapabilitiesContext = createContext<CapabilitiesContextValue | undefined>(undefined);

/**
 * Auth-aware, single-fetch source of the caller's effective permission set
 * from `GET /api/v1/me/capabilities` (`{ data: { permissions: string[] } }`,
 * {@see MeCapabilitiesApiHandler}).
 *
 * Centralizes what used to be a per-component fetch inside `useCapabilities`
 * (one network round trip per mounted consumer, all racing independently) into
 * ONE fetch shared via context, re-run only when the identity that determines
 * the answer changes:
 *
 * - Signed out → signed in (and vice versa): a fresh user has a fresh set.
 * - A different profile signs in: `user.id` changes.
 * - The ACTIVE TENANT changes (multi-membership tenant switch,
 *   {@see AuthContextType.switchTenant}): `user.tenant_id` changes. This is
 *   the multi-tenant-critical case — the effective permission set is
 *   TENANT-SCOPED server-side (`RoleChecker::getEffectivePermissionsForProfile`),
 *   so a permission held for tenant A must never be read as held for tenant B
 *   just because the old fetch's result is still sitting in React state.
 *
 * On every one of those transitions the provider resets to `loading: true`
 * and `permissions: []` BEFORE the new fetch resolves, so every `has`/
 * `hasAny`/`hasAll` call fails closed for the whole in-flight window rather
 * than answering with the previous identity's (possibly more privileged)
 * permission set.
 *
 * Mounted inside `AuthProvider` in the root layout (and in the Storybook
 * provider stack, alongside `NavigationProvider`/`PluginFeaturesProvider`,
 * which follow the same auth-aware refetch shape for their own resources).
 */
export function CapabilitiesProvider({ children }: { children: React.ReactNode }) {
  const { user, isLoading: isAuthLoading, apiClient } = useAuth();
  const [permissions, setPermissions] = useState<string[]>([]);
  const [loading, setLoading] = useState(true);

  // The stable identity driving refetches: a login, logout, profile switch, OR
  // active-tenant switch changes one of these; a same-user/same-tenant token
  // refresh changes neither.
  const userId = user !== null ? user.id : null;
  const tenantId = user !== null ? user.tenant_id : null;

  useEffect(() => {
    if (isAuthLoading) {
      return;
    }

    let cancelled = false;

    // Fetcher defined inside the effect so every setState call is sequenced
    // through this one async function rather than running synchronously in
    // the effect body (react-hooks/set-state-in-effect), mirroring
    // NavigationProvider/PluginFeaturesProvider.
    const load = async (): Promise<void> => {
      // Reset FIRST, before the network hop: this is what makes the
      // user/tenant transition itself fail-closed, not just the eventual
      // result. Without this, the PREVIOUS identity's permissions (and a
      // stale loading=false) would remain readable via has()/hasAny()/
      // hasAll() for as long as the new fetch takes to resolve.
      setLoading(true);
      setPermissions([]);

      if (userId === null) {
        // Signed out: nothing to fetch: a bare 401/403 would be indistinguishable
        // from "not signed in" anyway, and every check already fails closed via
        // the empty permission set above.
        if (!cancelled) {
          setLoading(false);
        }
        return;
      }

      try {
        const response = await apiClient('/api/v1/me/capabilities');
        if (!response.ok) {
          // Fail closed: a non-ok response keeps the already-reset empty set.
          return;
        }
        const body: unknown = await response.json();
        if (!cancelled) {
          setPermissions(parsePermissions(body));
        }
      } catch {
        // Fail closed: a network error keeps the already-reset empty set.
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [userId, tenantId, isAuthLoading, apiClient]);

  const has = useCallback(
    (capability: string): boolean => {
      if (loading) return false;
      return permissions.includes(capability);
    },
    [loading, permissions]
  );

  const hasAny = useCallback(
    (capabilities: readonly string[]): boolean => {
      if (loading) return false;
      return capabilities.some((capability) => permissions.includes(capability));
    },
    [loading, permissions]
  );

  const hasAll = useCallback(
    (capabilities: readonly string[]): boolean => {
      if (loading) return false;
      return capabilities.every((capability) => permissions.includes(capability));
    },
    [loading, permissions]
  );

  const value = useMemo<CapabilitiesContextValue>(
    () => ({ permissions, loading, has, hasAny, hasAll, hasPermission: has }),
    [permissions, loading, has, hasAny, hasAll]
  );

  return (
    <CapabilitiesContext.Provider value={value}>
      {children}
    </CapabilitiesContext.Provider>
  );
}

export function useCapabilitiesContext(): CapabilitiesContextValue {
  const context = useContext(CapabilitiesContext);
  if (!context) {
    throw new Error('useCapabilitiesContext must be used within CapabilitiesProvider');
  }
  return context;
}
