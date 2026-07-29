import { useCallback, useSyncExternalStore } from "react"

import type { SyncController, SyncStatus } from "./types"

const NOOP = (): void => {}

/**
 * Subscribe a component to a {@link SyncController}'s status.
 *
 * Wraps React's `useSyncExternalStore`. Passing `null` (a client with no sync
 * capability — web, the SPA harness) yields `null`, so callers render sync UI
 * only when a controller exists.
 *
 * Relies on the controller's referential-stability contract (see
 * {@link SyncController}): `getStatus()` must return the same object identity
 * until something actually changes. If it returns a fresh object each call,
 * `useSyncExternalStore` will loop — that requirement lives on the controller,
 * not here.
 */
export function useSyncStatus(controller: SyncController | null): SyncStatus | null {
  const subscribe = useCallback(
    (onStoreChange: () => void) => (controller ? controller.subscribe(onStoreChange) : NOOP),
    [controller],
  )
  const getSnapshot = useCallback(
    (): SyncStatus | null => (controller ? controller.getStatus() : null),
    [controller],
  )
  // Same snapshot on the server (SSR web) — the controller is client-only, so a
  // server render simply sees "no sync" (null) rather than throwing.
  return useSyncExternalStore(subscribe, getSnapshot, getSnapshot)
}
