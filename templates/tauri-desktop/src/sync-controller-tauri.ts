import { invoke } from "@tauri-apps/api/core"
import type {
  Conflict,
  FieldConflict,
  Resolution,
  SyncController,
  SyncStatus,
} from "@amroksaleh/features/sync"

/**
 * The desktop implementation of the shared {@link SyncController} contract
 * (WC-desktop-sync). It is the SECOND injected capability the shared sync UI
 * consumes, deliberately separate from `demoCatalogAdapter` (list/get/save) —
 * an online-only client just omits it and the banner/resolver render nothing.
 *
 * The Rust engine currently exposes no push events, so this controller derives
 * its {@link SyncStatus} by POLLING three cheap local-only reads
 * (`get_sync_status`, `list_conflicts`, `auth_lock_state`) plus the webview's
 * connectivity, and drives an explicit `sync_now` on a debounced interval / on
 * reconnect. (A Rust-side event emitter is the documented follow-up; swapping it
 * in only changes how `refresh()` is triggered, not this contract.)
 *
 * Referential-stability contract (see SyncController): {@link getStatus} returns
 * a cached object whose identity only changes when something actually changed —
 * required by `useSyncExternalStore`, which compares snapshots by identity.
 */

/** Raw `sync::SyncStatusView` over the wire. */
interface SyncStatusView {
  unsyncedCount: number
  conflictCount: number
  lastPullAt: string | null
  lastPushAt: string | null
}

/** Raw `db::conflicts_repo::FieldDiff` / `ConflictView` over the wire. */
interface FieldDiff {
  field: string
  mine: string | null
  theirs: string | null
}
interface ConflictView {
  id: number
  clientUuid: string
  title: string | null
  fields: FieldDiff[]
}

/** Raw `auth::lock::LockState` over the wire. */
interface LockState {
  locked: boolean
  reason: string | null
  secondsRemaining: number | null
}

/** Just enough of the local item to seed a resolution's non-diverging fields. */
interface LocalItem {
  name: string
  description: string | null
  status: "active" | "archived"
}

export interface TauriSyncController extends SyncController {
  /** Begin polling + reconnect/interval-driven sync; returns a disposer. */
  start(): () => void
  /** Force an immediate status refresh (local reads only, no network). */
  refresh(): Promise<void>
}

export interface TauriSyncControllerOptions {
  /** How often to re-read local sync state (ms). */
  pollMs?: number
  /** How often to auto-push pending changes while online + unlocked (ms). */
  autoSyncMs?: number
}

export function createTauriSyncController(
  options: TauriSyncControllerOptions = {},
): TauriSyncController {
  const pollMs = options.pollMs ?? 4000
  const autoSyncMs = options.autoSyncMs ?? 30000

  const listeners = new Set<(status: SyncStatus) => void>()
  // conflict id -> client_uuid, so resolveConflict can key back into Rust.
  const uuidById = new Map<number, string>()

  let syncing = false
  let snapshot: SyncStatus = {
    online: isOnline(),
    syncing: false,
    unsyncedCount: 0,
    lastSyncedAt: null,
    conflicts: [],
    locked: false,
  }

  function emit(next: SyncStatus): void {
    // Only swap identity on a REAL change (referential-stability contract).
    if (sameStatus(snapshot, next)) return
    snapshot = next
    for (const listener of listeners) listener(snapshot)
  }

  function getStatus(): SyncStatus {
    return snapshot
  }

  function subscribe(listener: (status: SyncStatus) => void): () => void {
    listeners.add(listener)
    return () => listeners.delete(listener)
  }

  function buildStatus(
    view: SyncStatusView,
    conflicts: ConflictView[],
    lock: LockState,
    online: boolean,
  ): SyncStatus {
    uuidById.clear()
    const mapped: Conflict[] = conflicts.map((c) => {
      uuidById.set(c.id, c.clientUuid)
      return {
        id: c.id,
        resource: "demo-catalog",
        title: c.title ?? undefined,
        fields: c.fields.map<FieldConflict>((f) => ({
          field: f.field,
          label: fieldLabel(f.field),
          mine: f.mine,
          theirs: f.theirs,
        })),
      }
    })
    return {
      online,
      syncing,
      unsyncedCount: view.unsyncedCount,
      lastSyncedAt: laterIso(view.lastPullAt, view.lastPushAt),
      conflicts: mapped,
      locked: lock.locked,
    }
  }

  async function refresh(): Promise<void> {
    const [view, conflicts, lock] = await Promise.all([
      invoke<SyncStatusView>("get_sync_status"),
      invoke<ConflictView[]>("list_conflicts"),
      invoke<LockState>("auth_lock_state"),
    ])
    emit(buildStatus(view, conflicts, lock, isOnline()))
  }

  async function syncNow(): Promise<void> {
    if (syncing) return
    syncing = true
    emit({ ...snapshot, syncing: true })
    try {
      await invoke("sync_now")
    } catch (err) {
      // A network/auth failure just leaves rows pending — the banner keeps
      // showing the unsynced count and the next cycle retries (Rust backoff).
      console.warn("sync_now failed:", err)
    } finally {
      syncing = false
      await refresh()
    }
  }

  async function resolveConflict(resolution: Resolution): Promise<void> {
    const id = Number(resolution.conflictId)
    const clientUuid = uuidById.get(id)
    if (!clientUuid) throw new Error(`resolveConflict: no client_uuid for conflict ${id}`)

    // Seed from the current local record so fields that did NOT diverge (and so
    // aren't in the conflict) are preserved, then apply the user's per-field pick.
    const base = await invoke<LocalItem | null>("get_item", { id })
    let name = base?.name ?? ""
    let description: string | null = base?.description ?? null
    let status: "active" | "archived" = base?.status ?? "active"

    const current = snapshot.conflicts.find((c) => Number(c.id) === id)
    for (const field of current?.fields ?? []) {
      const choice = resolution.fields[field.field]
      if (!choice) continue
      const picked =
        choice.pick === "custom" ? choice.value : choice.pick === "mine" ? field.mine : field.theirs
      const value = picked == null ? null : String(picked)
      if (field.field === "name") name = value ?? ""
      else if (field.field === "description") description = value
      else if (field.field === "status") status = value === "archived" ? "archived" : "active"
    }

    await invoke("resolve_conflict", { clientUuid, name, description, status })
    await refresh()
    // Push the merged result straight away when we can.
    if (snapshot.online && !snapshot.locked) void syncNow()
  }

  function start(): () => void {
    let disposed = false
    void refresh()

    const pollTimer = setInterval(() => void refresh(), pollMs)
    const autoSyncTimer = setInterval(() => {
      if (disposed) return
      if (snapshot.online && !snapshot.locked && snapshot.unsyncedCount > 0) void syncNow()
    }, autoSyncMs)

    const onOnline = () => {
      void (async () => {
        await refresh()
        if (!snapshot.locked) void syncNow()
      })()
    }
    const onOffline = () => emit({ ...snapshot, online: false })
    window.addEventListener("online", onOnline)
    window.addEventListener("offline", onOffline)

    return () => {
      disposed = true
      clearInterval(pollTimer)
      clearInterval(autoSyncTimer)
      window.removeEventListener("online", onOnline)
      window.removeEventListener("offline", onOffline)
    }
  }

  return { getStatus, subscribe, syncNow, resolveConflict, start, refresh }
}

function isOnline(): boolean {
  return typeof navigator === "undefined" ? true : navigator.onLine
}

/** ISO-8601 UTC strings sort lexically, so ">=" picks the later stamp. */
function laterIso(a: string | null, b: string | null): string | null {
  if (!a) return b
  if (!b) return a
  return a >= b ? a : b
}

function fieldLabel(field: string): string {
  return field.charAt(0).toUpperCase() + field.slice(1)
}

function sameStatus(a: SyncStatus, b: SyncStatus): boolean {
  return (
    a.online === b.online &&
    a.syncing === b.syncing &&
    a.unsyncedCount === b.unsyncedCount &&
    a.lastSyncedAt === b.lastSyncedAt &&
    Boolean(a.locked) === Boolean(b.locked) &&
    JSON.stringify(a.conflicts) === JSON.stringify(b.conflicts)
  )
}
