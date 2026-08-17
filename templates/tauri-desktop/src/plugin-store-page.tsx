import * as React from "react"
import { invoke } from "@tauri-apps/api/core"
import { listen } from "@tauri-apps/api/event"

import { Alert, AlertDescription } from "@amroksaleh/ui/alert"
import { Badge } from "@amroksaleh/ui/badge"
import { Button } from "@amroksaleh/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@amroksaleh/ui/card"
import { EmptyState } from "@amroksaleh/ui/empty-state"
import { Skeleton } from "@amroksaleh/ui/skeleton"

import { remoteRequest } from "./remote-client"

/**
 * The desktop Plugin store (WC-plugin-sync). Lists this tenant's entitled
 * desktop-plugin catalog from the connected backend (`GET
 * /api/v1/desktop-plugins`, over the remote transport) and cross-references it
 * with what the local FrankenPHP host actually has loaded (`GET
 * /__whity/plugins`, over `php_request`) to show each plugin as installed /
 * update-available / not-yet-installed.
 *
 * Install/update is NOT per-plugin here: the backend catalog is the single
 * source of truth for what a device runs, and `src-tauri/src/plugins/
 * reconcile.rs` converges the whole set at once (install missing, update
 * stale, remove revoked). "Sync now" re-runs that exact reconcile pass on
 * demand (`reconcile_plugins_now`) instead of waiting for the next sign-in —
 * the read-only runtime view stays on the separate Plugins page.
 */

/** One version of a catalogued plugin — mirrors `plugins::CatalogVersion`. */
interface CatalogVersion {
  version: string
  sha256: string
  sizeBytes: number
  releasedAt: string
}

/** A plugin's catalog entry — mirrors `plugins::CatalogEntry`. */
interface CatalogEntry {
  name: string
  latestVersion: string
  versions: CatalogVersion[]
}

/** Mirrors `php-host`'s `/__whity/plugins` report (see plugins-page.tsx). */
interface LoadedPlugin {
  fqcn: string
  name: string
  version: string
}
interface PluginsReport {
  loaded: LoadedPlugin[]
  quarantined: { fqcn: string; name: string; reason: string }[]
}

interface PhpResponse {
  status: number
  body: unknown
}

/** Mirrors `commands::post_login::PluginSyncEvent`. */
type PluginSyncEvent =
  | { state: "syncing" }
  | { state: "synced"; installed: number; updated: number; removed: number }
  | { state: "failed"; message: string }

type PhpStatusEvent = { state: string; port?: number; message?: string }

type CatalogState =
  | { status: "loading" }
  | { status: "error"; message: string }
  | { status: "ready"; entries: CatalogEntry[] }

export function PluginStorePage() {
  const [catalog, setCatalog] = React.useState<CatalogState>({ status: "loading" })
  const [installed, setInstalled] = React.useState<Map<string, string>>(new Map())
  const [syncing, setSyncing] = React.useState(false)
  const [syncError, setSyncError] = React.useState<string | null>(null)

  const loadCatalog = React.useCallback(async () => {
    try {
      const response = await remoteRequest("GET", "/api/v1/desktop-plugins")
      if (response.status === 401 || response.status === 403) {
        setCatalog({ status: "error", message: "You don't have access to the plugin catalog on this server." })
        return
      }
      if (response.status !== 200) {
        setCatalog({ status: "error", message: `Couldn't load the catalog (HTTP ${response.status}).` })
        return
      }
      const data = (response.body as { data?: CatalogEntry[] })?.data
      setCatalog({ status: "ready", entries: Array.isArray(data) ? data : [] })
    } catch (thrown) {
      // A thrown invoke is the local precondition failing (device not enrolled,
      // backend unreachable) — surface its message rather than a blank screen.
      setCatalog({ status: "error", message: thrown instanceof Error ? thrown.message : String(thrown) })
    }
  }, [])

  const loadInstalled = React.useCallback(async () => {
    try {
      const response = await invoke<PhpResponse>("php_request", { method: "GET", path: "/__whity/plugins", body: null })
      const report = response.body as PluginsReport | undefined
      setInstalled(new Map((report?.loaded ?? []).map((p) => [p.name, p.version])))
    } catch {
      // The php-host proxy is transiently unreachable right after a reload — the
      // next `php:status` ready event re-triggers this.
    }
  }, [])

  React.useEffect(() => {
    void loadCatalog()
    void loadInstalled()

    let unlistenSync: (() => void) | undefined
    let unlistenPhp: (() => void) | undefined

    void listen<PluginSyncEvent>("plugin-sync:status", (event) => {
      setSyncing(event.payload.state === "syncing")
      if (event.payload.state === "synced") {
        setSyncError(null)
        void loadCatalog()
        void loadInstalled()
      } else if (event.payload.state === "failed") {
        setSyncError(event.payload.message)
      }
    }).then((un) => {
      unlistenSync = un
    })

    // A sync that changed anything restarts FrankenPHP — re-read what's loaded
    // once it's back up.
    void listen<PhpStatusEvent>("php:status", (event) => {
      if (event.payload.state === "ready") void loadInstalled()
    }).then((un) => {
      unlistenPhp = un
    })

    return () => {
      unlistenSync?.()
      unlistenPhp?.()
    }
  }, [loadCatalog, loadInstalled])

  const syncNow = async () => {
    setSyncError(null)
    setSyncing(true)
    try {
      // Fire-and-forget: the command returns once the background reconcile is
      // spawned; progress arrives via the `plugin-sync:status` listener above.
      await invoke("reconcile_plugins_now")
    } catch (thrown) {
      setSyncing(false)
      setSyncError(thrown instanceof Error ? thrown.message : String(thrown))
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-4">
        <p className="text-sm text-muted-foreground">
          Plugins your organization has enabled for this device. Installing and updating happens for the whole set at
          once — there is nothing to pick.
        </p>
        <Button variant="outline" disabled={syncing} onClick={syncNow}>
          {syncing ? "Syncing…" : "Sync now"}
        </Button>
      </div>

      {syncError && (
        <Alert variant="destructive">
          <AlertDescription>{syncError}</AlertDescription>
        </Alert>
      )}

      {catalog.status === "loading" && <Skeleton className="h-24 w-full" />}

      {catalog.status === "error" && (
        <Alert variant="destructive">
          <AlertDescription>{catalog.message}</AlertDescription>
        </Alert>
      )}

      {catalog.status === "ready" && catalog.entries.length === 0 && (
        <EmptyState title="No plugins available" description="Your organization hasn't published any desktop plugins to this server yet." />
      )}

      {catalog.status === "ready" && catalog.entries.length > 0 && (
        <div className="grid gap-4 sm:grid-cols-2">
          {catalog.entries.map((entry) => (
            <PluginCard key={entry.name} entry={entry} installedVersion={installed.get(entry.name)} />
          ))}
        </div>
      )}
    </div>
  )
}

function PluginCard({ entry, installedVersion }: { entry: CatalogEntry; installedVersion: string | undefined }) {
  const isInstalled = installedVersion !== undefined
  const updateAvailable = isInstalled && installedVersion !== entry.latestVersion

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center justify-between gap-2 text-sm">
          {entry.name}
          {!isInstalled && <Badge variant="secondary">Available</Badge>}
          {isInstalled && !updateAvailable && <Badge variant="success-solid">Installed</Badge>}
          {updateAvailable && <Badge variant="warning-solid">Update available</Badge>}
        </CardTitle>
        <CardDescription>
          Latest v{entry.latestVersion}
          {isInstalled && ` · installed v${installedVersion}`}
        </CardDescription>
      </CardHeader>
      <CardContent className="text-xs text-muted-foreground">
        {entry.versions.length} version{entry.versions.length === 1 ? "" : "s"} published
        {updateAvailable && <p className="mt-1">A newer version installs on the next sync.</p>}
        {!isInstalled && <p className="mt-1">Installs on the next sync.</p>}
      </CardContent>
    </Card>
  )
}
