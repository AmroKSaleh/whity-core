import { useCallback, useMemo } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { parsePermissions } from '@/lib/capabilities';

/**
 * Returns the caller's effective permission set from GET /api/me/capabilities.
 *
 * Fail-closed: while loading or on any error (network failure, non-ok response,
 * malformed body) `permissions` is `[]` and `hasPermission` returns `false`.
 * The server stays authoritative — these slugs are UI hints that hide write
 * controls the caller cannot use; they grant nothing.
 */
export interface UseCapabilitiesResult {
  /** The caller's resolved permission slugs, or `[]` while loading / on error. */
  permissions: string[];
  /** True while the capabilities fetch is in flight. */
  loading: boolean;
  /**
   * Returns `true` only when loading is complete and `slug` is in
   * `permissions`. Always returns `false` while loading or on error.
   */
  hasPermission: (slug: string) => boolean;
}

export function useCapabilities(): UseCapabilitiesResult {
  const { apiClient } = useAuth();

  const { data, loading } = useFetch(async () => {
    const response = await apiClient('/api/me/capabilities');
    if (!response.ok) {
      // Fail closed: a non-ok response yields an empty permission set.
      return [];
    }
    return parsePermissions(await response.json());
  }, [apiClient]);

  // Stable reference: only changes when the fetched data changes, preventing
  // hasPermission from being re-created on every render while data is null.
  const permissions = useMemo(() => data ?? [], [data]);

  const hasPermission = useCallback(
    (slug: string): boolean => {
      if (loading) return false;
      return permissions.includes(slug);
    },
    [loading, permissions]
  );

  return { permissions, loading, hasPermission };
}
