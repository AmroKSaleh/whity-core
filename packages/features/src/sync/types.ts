/**
 * The offline-first SYNC contract (WC-desktop-sync).
 *
 * A client that syncs local-first data to a server (today: the Tauri desktop
 * template, backed by a Rust sync engine over local SQLite) exposes a
 * {@link SyncController} — a SECOND injected capability, deliberately separate
 * from the feature data adapters (e.g. `DemoCatalogAdapter {list,get,save}`),
 * which stay unchanged. An online-only client (web, the SPA harness) either
 * omits the controller entirely or injects {@link createAlwaysSyncedController},
 * so the shared sync UI degrades to nothing without any client special-casing.
 *
 * Everything here is plain data + one small interface — no React, no transport,
 * no feature coupling — so the same contract serves any feature and any client.
 */

/** One field's divergence between the local ('mine') and server ('theirs') copy. */
export interface FieldConflict<V = unknown> {
  /** Machine field key, e.g. "name" | "description" | "status". */
  field: string
  /** A literal label… */
  label?: string
  /** …or an i18n key resolved via the injected translator (takes precedence). */
  labelKey?: string
  /** The local value. */
  mine: V
  /** The server value. */
  theirs: V
}

/** One record whose local and server versions diverge across >=1 fields. */
export interface Conflict {
  /** Matches the domain item id (the desktop exposes its stable local id). */
  id: string | number
  /** Optional feature tag (e.g. "demo-catalog"), so one resolver can serve many features. */
  resource?: string
  /** Optional record title for the resolver header. */
  title?: string
  /** The diverging fields, each with mine/theirs. */
  fields: FieldConflict[]
}

/**
 * A per-field resolution choice. `custom` carries a user-entered / merged value
 * for that field (field-level manual merge).
 */
export type FieldChoice =
  | { pick: "mine" | "theirs" }
  | { pick: "custom"; value: unknown }

/** A full resolution for one conflict: a choice per diverging field key. */
export interface Resolution {
  conflictId: string | number
  fields: Record<string, FieldChoice>
}

/** A point-in-time snapshot of a client's sync state. */
export interface SyncStatus {
  /** Whether the client currently believes it can reach the server. */
  online: boolean
  /** A sync cycle is in progress. */
  syncing: boolean
  /** Count of local records not yet confirmed synced (pending + conflicted). */
  unsyncedCount: number
  /** ISO timestamp of the last successful sync, or null if never. */
  lastSyncedAt: string | null
  /** Records currently needing manual, field-level resolution. */
  conflicts: Conflict[]
  /**
   * The offline login TTL has elapsed (see auth.desktop_login_max_hours) — the
   * client is hard-locked and must re-authenticate online. Optional so
   * always-online clients can omit it.
   */
  locked?: boolean
}

/**
 * The sync capability a syncing client injects, alongside (never merged into)
 * its feature data adapters.
 *
 * CONTRACT — referential stability: {@link SyncController.getStatus} MUST return
 * a REFERENTIALLY STABLE object between notifications (same identity until an
 * actual change, then a new object). {@link useSyncStatus} feeds it to
 * React's `useSyncExternalStore`, which compares snapshots by identity — a fresh
 * object literal on every call throws "getSnapshot should be cached" and loops.
 * (Same failure class as the inline-translator render loop documented in
 * ../nav/types.ts.) Implementations should cache the last snapshot and only swap
 * references when something actually changes.
 */
export interface SyncController {
  /** The current snapshot (see the referential-stability contract above). */
  getStatus(): SyncStatus
  /** Subscribe to status changes; returns an unsubscribe function. */
  subscribe(listener: (status: SyncStatus) => void): () => void
  /** Trigger a sync cycle now (best-effort; resolves when the trigger is accepted). */
  syncNow(): Promise<void>
  /** Apply a field-level resolution to a conflicted record. */
  resolveConflict(resolution: Resolution): Promise<void>
}
