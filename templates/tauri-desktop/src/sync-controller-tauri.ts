import { invoke } from "@tauri-apps/api/core"
import { listen } from "@tauri-apps/api/event"
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
 * Sync runs in a Rust BACKGROUND LOOP (`src-tauri/src/sync/scheduler.rs`) that
 * owns the interval, connectivity, and auto-push. This controller is a thin
 * projection of that loop: it SUBSCRIBES to `sync:status` events for its
 * {@link SyncStatus}, fires `sync_now` (a non-blocking trigger) on demand, and
 * keeps only a slow backstop poll for a missed event. It no longer owns the
 * sync cadence or connectivity — the loop does.
 *
 * Referential-stability contract (see SyncController): {@link getStatus} returns
 * a cached object whose identity only changes when something actually changed —
 * required by `useSyncExternalStore`, which compares snapshots by identity.
 */

/** The Rust `sync:status` event payload (see `scheduler::SyncStatusEvent`). */
interface SyncStatusEvent {
  online: boolean
  syncing: boolean
  locked: boolean
  unsyncedCount: number
  conflictCount: number
  lastPullAt: string | null
  lastPushAt: string | null
  lastError: string | null
}

/** Raw `sync::SyncStatusView` over the wire (the `get_sync_status` backstop). */
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
  resource: string
  id: number
  clientUuid: string
  title: string | null
  fields: FieldDiff[]
}

/** Raw `auth::lock::LockState` over the wire (the backstop lock read). */
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
  /** Subscribe to the Rust loop's events + a backstop poll; returns a disposer. */
  start(): () => void
  /** Force an immediate local-read refresh (backstop; no network). */
  refresh(): Promise<void>
}

export interface TauriSyncControllerOptions {
  /** Backstop local-read interval (ms) in case a `sync:status` event is missed. */
  backstopMs?: number
}

export function createTauriSyncController(
  options: TauriSyncControllerOptions = {},
): TauriSyncController {
  const backstopMs = options.backstopMs ?? 30000

  const listeners = new Set<(status: SyncStatus) => void>()
  // conflict id -> (resource, client_uuid), so resolveConflict can key back
  // into Rust without needing to guess which ResourceDescriptor owns it.
  const keyById = new Map<number, { resource: string; clientUuid: string }>()

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

  function mapConflicts(views: ConflictView[]): Conflict[] {
    keyById.clear()
    return views.map((c) => {
      keyById.set(c.id, { resource: c.resource, clientUuid: c.clientUuid })
      return {
        id: c.id,
        resource: c.resource,
        title: c.title ?? undefined,
        fields: c.fields.map<FieldConflict>((f) => ({
          field: f.field,
          label: fieldLabel(f.field),
          mine: f.mine,
          theirs: f.theirs,
        })),
      }
    })
  }

  /** Apply a Rust `sync:status` event; fetch the conflict LIST only when there
   *  are conflicts (the event carries counts, not the list). */
  async function applyEvent(ev: SyncStatusEvent): Promise<void> {
    let conflicts: Conflict[] = []
    if (ev.conflictCount > 0) {
      try {
        conflicts = mapConflicts(await invoke<ConflictView[]>("list_conflicts"))
      } catch (err) {
        console.warn("list_conflicts failed:", err)
      }
    } else {
      keyById.clear()
    }
    if (ev.lastError) console.warn("sync error:", ev.lastError)
    emit({
      online: ev.online,
      syncing: ev.syncing,
      unsyncedCount: ev.unsyncedCount,
      lastSyncedAt: laterIso(ev.lastPullAt, ev.lastPushAt),
      conflicts,
      locked: ev.locked,
    })
  }

  /** Backstop: local reads only. Preserves the loop-owned `online`/`syncing`. */
  async function refresh(): Promise<void> {
    const [view, conflictViews, lock] = await Promise.all([
      invoke<SyncStatusView>("get_sync_status"),
      invoke<ConflictView[]>("list_conflicts"),
      invoke<LockState>("auth_lock_state"),
    ])
    emit({
      online: snapshot.online,
      syncing: snapshot.syncing,
      unsyncedCount: view.unsyncedCount,
      lastSyncedAt: laterIso(view.lastPullAt, view.lastPushAt),
      conflicts: mapConflicts(conflictViews),
      locked: lock.locked,
    })
  }

  async function syncNow(): Promise<void> {
    // Optimistic spinner; the loop's events confirm/clear it.
    emit({ ...snapshot, syncing: true })
    try {
      await invoke("sync_now") // fires a Manual trigger; returns immediately
    } catch (err) {
      console.warn("sync_now trigger failed:", err)
    }
  }

  async function resolveConflict(resolution: Resolution): Promise<void> {
    const id = Number(resolution.conflictId)
    const key = keyById.get(id)
    if (!key) throw new Error(`resolveConflict: no client_uuid for conflict ${id}`)
    const { resource, clientUuid } = key

    // Seed non-diverging fields from the current local record so they're
    // preserved (only the fields IN the conflict are otherwise present in
    // `fields`), then apply the user's per-field pick on top. Only
    // demo-catalog/items has a local-read command to seed from today — a
    // second resource with its own UI needs its own seed call here, matching
    // how `commands/items.rs` + `demo-catalog-tauri-adapter.ts` are
    // deliberately per-resource, not generalized (see sync/resource.rs).
    const fields: Record<string, unknown> =
      resource === "demo-catalog/items" ? await seedDemoCatalogFields(id) : {}

    const current = snapshot.conflicts.find((c) => Number(c.id) === id)
    for (const field of current?.fields ?? []) {
      const choice = resolution.fields[field.field]
      if (!choice) continue
      const picked =
        choice.pick === "custom" ? choice.value : choice.pick === "mine" ? field.mine : field.theirs
      fields[field.field] = picked == null ? null : String(picked)
    }

    // Rust's resolve_conflict rebases + fires a LocalWrite trigger, so the loop
    // pushes the merged result and emits the cleared status. Refresh locally for
    // an immediate UI update (the conflict is already gone from the local read).
    await invoke("resolve_conflict", { resource, clientUuid, fields })
    await refresh()
  }

  async function seedDemoCatalogFields(id: number): Promise<Record<string, unknown>> {
    const base = await invoke<LocalItem | null>("get_item", { id })
    return {
      name: base?.name ?? "",
      description: base?.description ?? null,
      status: base?.status ?? "active",
    }
  }

  function start(): () => void {
    void refresh() // immediate local snapshot before the first event lands

    let unlistenStatus: (() => void) | undefined
    void listen<SyncStatusEvent>("sync:status", (event) => void applyEvent(event.payload)).then(
      (un) => {
        unlistenStatus = un
      },
    )

    // Backstop poll for a missed event, and nudge the loop on a browser reconnect
    // hint (the loop owns the authoritative connectivity via its exchange probe).
    const backstop = setInterval(() => void refresh(), backstopMs)
    const onOnline = () => void syncNow()
    window.addEventListener("online", onOnline)

    return () => {
      clearInterval(backstop)
      window.removeEventListener("online", onOnline)
      unlistenStatus?.()
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
