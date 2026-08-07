import type { SyncController, SyncStatus } from "./types"

/**
 * A single frozen "everything is synced" snapshot. Frozen + module-level so its
 * identity is stable across calls — satisfying the {@link SyncController}
 * referential-stability contract that {@link useSyncStatus} relies on.
 */
const ALWAYS_SYNCED: SyncStatus = {
  online: true,
  syncing: false,
  unsyncedCount: 0,
  lastSyncedAt: null,
  conflicts: [],
  locked: false,
}

/**
 * A no-op {@link SyncController} for ONLINE-ONLY clients (web, the SPA harness)
 * that have no local-first sync layer. It reports "online, nothing pending, no
 * conflicts, never locked" forever, so shared sync UI (e.g. `UnsyncedBanner`)
 * renders inert instead of each online client special-casing sync's absence.
 *
 * `syncNow` / `resolveConflict` are no-ops; `subscribe` never fires (the status
 * never changes) and returns a no-op unsubscribe.
 */
export function createAlwaysSyncedController(): SyncController {
  return {
    getStatus: () => ALWAYS_SYNCED,
    subscribe: () => () => {},
    syncNow: async () => {},
    resolveConflict: async () => {},
  }
}
