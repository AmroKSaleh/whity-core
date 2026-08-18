import * as React from "react"
import { invoke } from "@tauri-apps/api/core"
import { listen } from "@tauri-apps/api/event"

import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@amroksaleh/ui/card"
import { Badge } from "@amroksaleh/ui/badge"

/** Mirrors `db::plugin_sync_repo::PluginSyncStatus` (camelCase over the IPC wire). */
interface PluginSyncStatus {
  lastSyncedAt: number | null
  lastInstalled: number
  lastUpdated: number
  lastRemoved: number
  lastFailed: PluginSyncFailure[]
  lastError: string | null
}

/** Mirrors `plugins::reconcile::PluginSyncFailure`. */
interface PluginSyncFailure {
  name: string
  message: string
}

/** Mirrors `commands::post_login::PluginSyncEvent`. */
type PluginSyncEvent =
  | { state: "syncing" }
  | { state: "synced"; installed: number; updated: number; removed: number; failed: PluginSyncFailure[] }
  | { state: "failed"; message: string }

type PhpStatusEvent =
  | { state: "starting" }
  | { state: "ready"; port: number }
  | { state: "crashed"; message: string }
  | { state: "restarting"; attempt: number }
  | { state: "reloading" }
  | { state: "failed"; message: string }

interface PhpResponse {
  status: number
  body: unknown
}

interface LoadedPlugin {
  fqcn: string
  name: string
  version: string
}

interface QuarantinedPlugin {
  fqcn: string
  name: string
  reason: string
}

interface PluginsReport {
  loaded: LoadedPlugin[]
  quarantined: QuarantinedPlugin[]
}

/**
 * Read-only status view for the plugins this device runs (WC-plugin-sync).
 * There is no install/uninstall control here anymore — the tenant's plugin
 * catalog on the connected server is the single source of truth, and every
 * successful login automatically reconciles this device to match it (see
 * src-tauri/src/plugins/reconcile.rs + src-tauri/src/commands/post_login.rs).
 * This page only reports what happened: the last sync's outcome, and what's
 * actually loaded (or quarantined) in the running PHP plugin host right now.
 */
export function PluginsPage() {
  const [syncStatus, setSyncStatus] = React.useState<PluginSyncStatus | null>(null)
  const [pluginsReport, setPluginsReport] = React.useState<PluginsReport | null>(null)
  const [syncing, setSyncing] = React.useState(false)
  const [lastEvent, setLastEvent] = React.useState<PluginSyncEvent | null>(null)

  const loadSyncStatus = React.useCallback(async () => {
    try {
      setSyncStatus(await invoke<PluginSyncStatus>("plugin_sync_status"))
    } catch {
      // Pre-first-boot or a busy DB — the next successful sync will populate it.
    }
  }, [])

  const loadPluginsReport = React.useCallback(async () => {
    try {
      const response = await invoke<PhpResponse>("php_request", {
        method: "GET",
        path: "/__whity/plugins",
        body: null,
      })
      setPluginsReport(response.body as PluginsReport)
    } catch {
      // The php-host proxy is transiently unreachable right after a reload —
      // not worth surfacing as an error; the next reload retriggers this.
    }
  }, [])

  React.useEffect(() => {
    void loadSyncStatus()
    void loadPluginsReport()

    let unlistenSync: (() => void) | undefined
    let unlistenPhp: (() => void) | undefined

    void listen<PluginSyncEvent>("plugin-sync:status", (event) => {
      setLastEvent(event.payload)
      setSyncing(event.payload.state === "syncing")
      if (event.payload.state !== "syncing") {
        void loadSyncStatus()
      }
    }).then((un) => {
      unlistenSync = un
    })

    // A sync that changed anything triggers a FrankenPHP reload — re-check
    // what's actually loaded once it comes back up.
    void listen<PhpStatusEvent>("php:status", (event) => {
      if (event.payload.state === "ready") {
        void loadPluginsReport()
      }
    }).then((un) => {
      unlistenPhp = un
    })

    return () => {
      unlistenSync?.()
      unlistenPhp?.()
    }
  }, [loadSyncStatus, loadPluginsReport])

  const loadedNames = new Set(pluginsReport?.loaded.map((p) => p.name) ?? [])
  const quarantinedByName = new Map((pluginsReport?.quarantined ?? []).map((q) => [q.name, q.reason]))
  const allNames = Array.from(new Set([...loadedNames, ...quarantinedByName.keys()]))

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle className="text-sm">Sync status</CardTitle>
          <CardDescription>
            {syncing
              ? "Syncing plugins…"
              : syncStatus?.lastSyncedAt
                ? `Last synced ${new Date(syncStatus.lastSyncedAt * 1000).toLocaleString()}`
                : "Not synced yet — this happens automatically the next time you sign in."}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-1 text-xs text-muted-foreground">
          {syncStatus && (syncStatus.lastInstalled > 0 || syncStatus.lastUpdated > 0 || syncStatus.lastRemoved > 0) && (
            <p>
              Last pass: {syncStatus.lastInstalled} installed, {syncStatus.lastUpdated} updated, {syncStatus.lastRemoved}{" "}
              removed.
            </p>
          )}
          {syncStatus?.lastError && <p className="text-destructive">{syncStatus.lastError}</p>}
          {lastEvent?.state === "failed" && <p className="text-destructive">{lastEvent.message}</p>}
          {(syncStatus?.lastFailed?.length ?? 0) > 0 && (
            <div className="space-y-0.5">
              {syncStatus?.lastFailed.map((f) => (
                <p key={f.name} className="text-destructive">
                  {f.name}: {f.message}
                </p>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {allNames.length === 0 && (
        <p className="text-sm text-muted-foreground">No plugins are running on this device yet.</p>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        {allNames.map((name) => {
          const isLoaded = loadedNames.has(name)
          const quarantineReason = quarantinedByName.get(name)
          const loaded = pluginsReport?.loaded.find((p) => p.name === name)

          return (
            <Card key={name}>
              <CardHeader>
                <CardTitle className="flex items-center justify-between gap-2 text-sm">
                  {name}
                  {isLoaded && <Badge variant="success-solid">Loaded</Badge>}
                  {quarantineReason && <Badge variant="destructive-solid">Quarantined</Badge>}
                </CardTitle>
                {loaded && <CardDescription>v{loaded.version}</CardDescription>}
              </CardHeader>
              {quarantineReason && (
                <CardContent className="text-xs text-destructive">{quarantineReason}</CardContent>
              )}
            </Card>
          )
        })}
      </div>
    </div>
  )
}
