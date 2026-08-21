'use client';

/**
 * The load-once-per-record effect every record page repeats.
 *
 * The roles page hand-wrote this three times — once for the record, once for the
 * holders, once for the audit trail — and each copy carries the same four
 * decisions: cancel on unmount, re-run only for a DIFFERENT record, map the
 * `'forbidden'` sentinel to an absent panel rather than an error, and keep a
 * supplementary panel's failure out of the page's own failure. The user record
 * page needs four more of them. Four more copies is how the modals drifted.
 */

import { useEffect, useRef, useState } from 'react';
import type { DependencyList } from 'react';

import type { RecordResource } from './types';

/**
 * Load one resource for a record page.
 *
 * @param load         Runs the request. Resolving `'forbidden'` yields the
 *                     forbidden state — an ungranted capability, rendered as a
 *                     clean absence. Rejecting yields the error state.
 * @param deps         What actually IDENTIFIES the request — the record id, a
 *                     refresh token. Deliberately explicit: `load` is a fresh
 *                     closure over the adapter and the translator on every
 *                     render, so depending on it would refetch on every
 *                     keystroke. This mirrors what the hand-written effects did
 *                     with an `exhaustive-deps` suppression, with the
 *                     suppression written once here instead of at every site.
 * @param errorMessage Already-translated copy for the error state. It is what a
 *                     PANEL shows: raw backend text ("SQLSTATE[42P01]") beside a
 *                     side panel's title says nothing to the operator reading
 *                     it. The underlying message is kept as `detail` for the
 *                     places that genuinely want it — the record's own failure,
 *                     where "Role not found" beats "Failed to load this role".
 */
export function useRecordResource<T>(
  load: () => Promise<T | 'forbidden'>,
  deps: DependencyList,
  errorMessage: string
): RecordResource<T> {
  const [resource, setResource] = useState<RecordResource<T>>({ status: 'loading' });

  // The latest closure, read by the fetch effect below rather than depended on.
  // This effect is declared FIRST and has no dependency array, so React runs it
  // before the fetch effect on every render — including the render that changed
  // `deps` — and the fetch always sees the current adapter and translator.
  const latest = useRef({ load, errorMessage });
  useEffect(() => {
    latest.current = { load, errorMessage };
  });

  useEffect(() => {
    let cancelled = false;
    const { load: run, errorMessage: message } = latest.current;

    const start = async (): Promise<void> => {
      // Functional, and a no-op when the resource is already loading: on mount
      // it would otherwise replace one `{status:'loading'}` with an identical
      // one and cost every record page a wasted render per panel.
      setResource((current) => (current.status === 'loading' ? current : { status: 'loading' }));
      try {
        const value = await run();
        if (cancelled) return;
        setResource(
          value === 'forbidden' ? { status: 'forbidden' } : { status: 'ready', value }
        );
      } catch (error) {
        if (cancelled) return;
        setResource({
          status: 'error',
          message,
          detail: error instanceof Error && error.message !== '' ? error.message : null,
        });
      }
    };

    void start();

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  return resource;
}
