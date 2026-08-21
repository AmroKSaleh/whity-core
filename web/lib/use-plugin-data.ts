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
 *   - Exhausts pagination when that response turns out to be a paginated core
 *     envelope, and maps a walk it could not finish to `error` (see below).
 *   - Delegates parse/validate to the caller-supplied `parse` function;
 *     `parse` returning `null` maps to `empty`.
 *
 * #867: one request is one page, and every core list endpoint is paginated at
 * 25. A data-bound block presents its `source` as the whole collection — the
 * DSL's only row limit, `pageSize`, is client-side paging over what was
 * fetched — so page 1 rendered as the set is the #823/#824 defect reproduced
 * for every plugin on the platform: an operator who cannot find row 26 reads
 * it as missing data, not as a truncated list. That is how the original OU
 * report reached us as "the department was never created".
 *
 * Loading is derived from a (requestKey, resolvedKey) pair, where requestKey
 * increments on each new fetch and resolvedKey tracks the last settled key.
 * This avoids calling setState synchronously in the effect body
 * (react-hooks/set-state-in-effect).
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { apiClient } from '@/lib/api-client';
import { fetchAllPages, isPaginationEnvelope } from '@/lib/api/fetch-all-pages';

export type PluginDataState<T> =
  | { status: 'loading' }
  | { status: 'error'; retry: () => void }
  | { status: 'empty'; refresh: () => void }
  | { status: 'ready'; data: T; refresh: () => void };

type ResolvedResult<T> =
  | { key: number; status: 'error' }
  | { key: number; status: 'empty' }
  | { key: number; status: 'ready'; data: T };

/** How long any single request may take before the hang guard aborts it. */
const HANG_GUARD_MS = 15_000;

/**
 * Whether the response we already have is a page of a larger set.
 *
 * Detection keys off the `pagination` block itself — via the same predicate the
 * walk uses, so the two can never disagree about what "paginated" means — and
 * off the row count rather than `totalPages`, because the row count is what
 * `walkPages` treats as the contract when the two disagree.
 *
 * Everything else takes the single-request path unchanged, down to the URL
 * requested: a plugin's own unpaginated route, a `dataStat`'s single object, an
 * endpoint whose whole set fit in the first page. Only a body that says, in the
 * platform's own envelope, that it withheld rows triggers a second look.
 */
function hasUnfetchedPages(body: {
  data: unknown;
  pagination?: unknown;
}): boolean {
  return (
    Array.isArray(body.data) &&
    isPaginationEnvelope(body.pagination) &&
    body.data.length < body.pagination.total
  );
}

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
    // #883: an EMPTY source is not a path, it is "nobody has said which record
    // yet" — a `dataRecord` whose `{token}` has not resolved. Hooks cannot be
    // skipped, so the caller passes `''` and the fetch is skipped here instead.
    // Requesting `''` would resolve against the current document URL and answer
    // with a page of HTML, which parses as an error the user has no way to act
    // on. The state stays `loading`, which is what the caller renders around.
    if (source === '') return;

    const key = fetchKey;
    const controller = new AbortController();
    // Hang guard on the SAME controller already used for unmount/re-fetch
    // cleanup (a plain setTimeout+abort, not AbortSignal.any/.timeout() —
    // both are unsupported in the jsdom test environment this hook is
    // exercised under). A data-bound block's source can be any plugin-owned
    // route, so there is no bound on how long it might take an unhealthy
    // backend to answer (or never answer) without this — the block would
    // show its loading state forever with no way out.
    let hangGuard = setTimeout(() => controller.abort(), HANG_GUARD_MS);

    // Re-armed before each page request, so the guard stays what it was meant
    // to be — a bound on one unanswered request — instead of becoming a budget
    // for the whole set. A paginated source legitimately costs several
    // round-trips, and failing a walk that is making steady progress would
    // manufacture exactly the "could not load everything" error this change
    // exists to make rare.
    const armHangGuard = (): void => {
      clearTimeout(hangGuard);
      hangGuard = setTimeout(() => controller.abort(), HANG_GUARD_MS);
    };

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

        const envelope = body as { data: unknown; pagination?: unknown };
        let value: unknown = envelope.data;

        if (hasUnfetchedPages(envelope)) {
          // Re-walk from page 1 rather than continuing from page 2. It spends
          // one extra request — this response is discarded — and buys the
          // shared walk used verbatim plus the server's maximum page size,
          // which is FEWER total requests than continuing at the default 25
          // for any source big enough to reach here.
          const all = await fetchAllPages<unknown>((url) => {
            armHangGuard();
            return apiClient(url, { signal: controller.signal });
          }, source);

          if (!mountedRef.current) return;

          if (!all.complete) {
            // A page failed, or the walk hit the helper's request cap. Both
            // mean we hold a short list, and #824's finding is that a short
            // list must never be presented as a complete one — so this
            // surfaces as `error`, with the retry every consumer already
            // renders, instead of quietly rendering what did arrive.
            setResolved({ key, status: 'error' });
            return;
          }

          value = all.items;
        }

        const parsed = parse(value);

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
      clearTimeout(hangGuard);
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
