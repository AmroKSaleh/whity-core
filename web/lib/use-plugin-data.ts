'use client';

/**
 * WC-231: usePluginData — fetch hook for data-bound plugin UI blocks.
 *
 * Manages a loading → error / empty / ready state machine over apiClient.
 * The hook:
 *   - Fetches `source` verbatim via `apiClient` on mount and on refresh/retry.
 *   - Uses an AbortController to cancel the in-flight request on unmount or
 *     re-fetch, guarding against setState-after-unmount.
 *   - Expects the response to be a `{ data: unknown }` envelope; anything else
 *     maps to `error`.
 *   - Delegates parse/validate to the caller-supplied `parse` function;
 *     `parse` returning `null` maps to `empty`.
 *
 * Loading is derived from a (requestKey, resolvedKey) pair, where requestKey
 * increments on each new fetch and resolvedKey tracks the last settled key.
 * This avoids calling setState synchronously in the effect body
 * (react-hooks/set-state-in-effect).
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { apiClient } from '@/lib/api-client';

export type PluginDataState<T> =
  | { status: 'loading' }
  | { status: 'error'; retry: () => void }
  | { status: 'empty'; refresh: () => void }
  | { status: 'ready'; data: T; refresh: () => void };

type ResolvedResult<T> =
  | { key: number; status: 'error' }
  | { key: number; status: 'empty' }
  | { key: number; status: 'ready'; data: T };

/**
 * Fetch `source` via `apiClient` and map the result into a discriminated
 * state. `parse` receives the unwrapped `body.data` value and must return `T`
 * (valid, non-empty) or `null` (treat as empty). The hook never throws.
 *
 * @param source - The versioned API path to fetch (e.g. `/api/v1/x/rows`).
 * @param parse  - Extractor/validator: returns `T` or `null` (→ empty).
 */
export function usePluginData<T>(
  source: string,
  parse: (body: unknown) => T | null
): PluginDataState<T> {
  // A counter bump triggers a re-fetch; used for both refresh and retry.
  const [fetchKey, setFetchKey] = useState(0);

  // Stores the result of the last completed fetch, tagged with the fetchKey it
  // settled for. When fetchKey > resolved.key the hook is still loading.
  const [resolved, setResolved] = useState<ResolvedResult<T> | null>(null);

  // Stable bump callback — its identity never changes so it does not add a
  // dependency that would re-trigger the effect on every render.
  const bump = useCallback(() => setFetchKey((k) => k + 1), []);

  // Track mounted state to guard against setState-after-unmount.
  const mountedRef = useRef(true);
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  useEffect(() => {
    const key = fetchKey;
    const controller = new AbortController();

    const run = async (): Promise<void> => {
      try {
        const response = await apiClient(source, { signal: controller.signal });

        if (!mountedRef.current) return;

        if (!response.ok) {
          setResolved({ key, status: 'error' });
          return;
        }

        let body: unknown;
        try {
          body = await response.json();
        } catch {
          if (!mountedRef.current) return;
          setResolved({ key, status: 'error' });
          return;
        }

        if (!mountedRef.current) return;

        // Expect a `{ data: unknown }` envelope.
        if (
          typeof body !== 'object' ||
          body === null ||
          !('data' in body)
        ) {
          setResolved({ key, status: 'error' });
          return;
        }

        const envelope = body as { data: unknown };
        const parsed = parse(envelope.data);

        if (!mountedRef.current) return;

        if (parsed === null) {
          setResolved({ key, status: 'empty' });
        } else {
          setResolved({ key, status: 'ready', data: parsed });
        }
      } catch {
        // AbortError from unmount/re-fetch or network error — map to error.
        if (!mountedRef.current) return;
        setResolved({ key, status: 'error' });
      }
    };

    void run();

    return () => {
      controller.abort();
    };
    // `parse` is intentionally excluded: callers typically pass an inline
    // function and including it would cause infinite re-fetches. Only `source`
    // and `fetchKey` drive re-fetches; `bump` is stable.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [source, fetchKey, bump]);

  // If we have not yet received a result for the current fetchKey → loading.
  if (resolved === null || resolved.key !== fetchKey) {
    return { status: 'loading' };
  }

  if (resolved.status === 'error') {
    return { status: 'error', retry: bump };
  }

  if (resolved.status === 'empty') {
    return { status: 'empty', refresh: bump };
  }

  return { status: 'ready', data: resolved.data, refresh: bump };
}
