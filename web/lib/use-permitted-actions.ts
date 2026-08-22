'use client';

/**
 * #868: `usePermittedActions` — resolve, through the platform's own authority,
 * which of a batch of concrete requests the caller may actually make.
 *
 * This is the client half of `POST /api/v1/me/permitted-actions`. It is the
 * whole reason the `inbox` block type lives in core: a plugin answering the
 * question locally would be re-deriving authorization beside the middleware's,
 * and the two would drift silently.
 *
 * Fail-closed, three times over. While the answer is loading, on any error, and
 * whenever the batch has moved on from the answer in hand, every ref resolves
 * to NOT allowed — so an unresolved batch renders no action buttons rather than
 * buttons that 403 on click. This mirrors `useCapabilities`' policy for the same
 * reason. And the answers are UI hints regardless: the server re-gates every
 * request when it is actually made.
 *
 * Batching is by design. One request per (item × action) would be a screenful of
 * requests, and each answer would arrive at a different moment, so the action
 * row would visibly assemble itself. One request answers the whole page.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { apiClient } from '@/lib/api-client';

/** The endpoint whose answer is authoritative. Versioned, like every block source. */
export const PERMITTED_ACTIONS_ENDPOINT = '/api/v1/me/permitted-actions';

/** One concrete request to resolve. `ref` is the caller's own correlation key. */
export interface PermittedActionCheck {
  ref: string;
  /**
   * The verb. GET joined the write verbs for #909's `accessGate`, where "may I
   * SEE this region at all?" is a read; the server and the offline host accept
   * the same set.
   */
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  path: string;
  /** A registered resource type, for the per-record narrowing. */
  resourceType?: string;
  /** The record's id — sent as-is; the server accepts an int or its decimal string. */
  resourceId?: string | number;
  /** The per-record predicate. Can only narrow the route gate's answer. */
  scopedPermission?: string;
}

export type PermittedActionsState =
  | { status: 'loading'; isAllowed: (ref: string) => boolean }
  | { status: 'error'; isAllowed: (ref: string) => boolean; retry: () => void }
  | { status: 'ready'; isAllowed: (ref: string) => boolean; refresh: () => void };

/**
 * A settled answer, tagged with BOTH the fetch nonce and the batch it answered.
 * The batch tag is what makes a stale answer unusable: `fetchKey` does not
 * change when the caller's batch does, so an answer about the PREVIOUS batch
 * would otherwise keep reporting `ready` — and keep authorizing buttons — until
 * the new response landed.
 */
interface ResolvedResult {
  key: number;
  batchKey: string;
  status: 'error' | 'ready';
  allowed: Set<string>;
}

/** Nothing is allowed — the loading and error answer. */
const denyAll = (): boolean => false;

/**
 * Parse the `{ data: [{ ref, allowed }] }` envelope into the set of allowed
 * refs. Anything unparseable yields an empty set, which denies everything.
 */
function parseAllowed(body: unknown): Set<string> {
  const allowed = new Set<string>();
  if (typeof body !== 'object' || body === null || !('data' in body)) {
    return allowed;
  }
  const data = (body as { data: unknown }).data;
  if (!Array.isArray(data)) {
    return allowed;
  }
  for (const entry of data) {
    if (
      typeof entry === 'object' &&
      entry !== null &&
      'ref' in entry &&
      'allowed' in entry &&
      typeof (entry as { ref: unknown }).ref === 'string' &&
      (entry as { allowed: unknown }).allowed === true
    ) {
      allowed.add((entry as { ref: string }).ref);
    }
  }
  return allowed;
}

/**
 * Resolve a batch of checks. Re-resolves whenever the batch changes — which is
 * why callers pass a `batchKey` they control rather than relying on array
 * identity: a fresh array every render would re-POST every render.
 *
 * @param checks   The concrete requests to resolve. An empty batch resolves
 *                 without a request.
 * @param batchKey A value that changes exactly when `checks` meaningfully does.
 */
export function usePermittedActions(
  checks: PermittedActionCheck[],
  batchKey: string
): PermittedActionsState {
  const [fetchKey, setFetchKey] = useState(0);
  const [resolved, setResolved] = useState<ResolvedResult | null>(null);

  const bump = useCallback(() => setFetchKey((k) => k + 1), []);

  const mountedRef = useRef(true);
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  const isEmptyBatch = checks.length === 0;

  useEffect(() => {
    // An empty batch has a known answer, derived during render below. Asking the
    // server would be a round trip to be told nothing, and settling it here
    // would be a synchronous setState in an effect body — the cascading-render
    // hazard `usePluginData` avoids the same way.
    if (isEmptyBatch) return;

    const key = fetchKey;
    // `checks` from THIS render's closure. The effect re-runs whenever
    // `batchKey` changes, and `batchKey` is derived from `checks`, so the batch
    // captured here is always the one that key describes.
    const batch = checks;

    const controller = new AbortController();
    // Same hang guard as usePluginData, for the same reason: a block must not
    // sit in a loading state forever when the backend never answers.
    const hangGuard = setTimeout(() => controller.abort(), 15_000);

    const settle = (status: 'error' | 'ready', allowed: Set<string>): void => {
      if (!mountedRef.current) return;
      setResolved({ key, batchKey, status, allowed });
    };

    const run = async (): Promise<void> => {
      try {
        const response = await apiClient(PERMITTED_ACTIONS_ENDPOINT, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ checks: batch }),
          signal: controller.signal,
        });

        if (!response.ok) {
          settle('error', new Set<string>());
          return;
        }

        let body: unknown;
        try {
          body = await response.json();
        } catch {
          settle('error', new Set<string>());
          return;
        }

        settle('ready', parseAllowed(body));
      } catch {
        // AbortError from unmount/re-fetch, or a network failure.
        settle('error', new Set<string>());
      }
    };

    void run();

    return () => {
      clearTimeout(hangGuard);
      controller.abort();
    };
    // `checks` is deliberately excluded from the deps — it is a fresh array
    // every render, so including it would re-POST every render. `batchKey` is
    // the caller's declaration of when the batch actually changed.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [batchKey, fetchKey, isEmptyBatch]);

  // An answer counts only when it answered THIS fetch AND THIS batch.
  const fresh =
    resolved !== null && resolved.key === fetchKey && resolved.batchKey === batchKey
      ? resolved
      : null;

  const allowedSet = fresh !== null && fresh.status === 'ready' ? fresh.allowed : null;

  const isAllowed = useMemo(() => {
    if (allowedSet === null) return denyAll;
    return (ref: string): boolean => allowedSet.has(ref);
  }, [allowedSet]);

  if (isEmptyBatch) {
    return { status: 'ready', isAllowed: denyAll, refresh: bump };
  }

  if (fresh === null) {
    return { status: 'loading', isAllowed: denyAll };
  }

  if (fresh.status === 'error') {
    return { status: 'error', isAllowed: denyAll, retry: bump };
  }

  return { status: 'ready', isAllowed, refresh: bump };
}
