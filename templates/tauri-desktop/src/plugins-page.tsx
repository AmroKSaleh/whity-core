import * as React from "react"
import { invoke } from "@tauri-apps/api/core"
import { listen } from "@tauri-apps/api/event"

import { Button } from "@amroksaleh/ui/button"
import { Card, CardContent, CardHeader, CardTitle, CardDescription, CardFooter } from "@amroksaleh/ui/card"
import { Badge } from "@amroksaleh/ui/badge"

/** Mirrors `plugins::CatalogVersion` (camelCase over the IPC wire). */
interface CatalogVersion {
  version: string
  sha256: string
  sizeBytes: number
  releasedAt: string
}

/** Mirrors `plugins::CatalogEntry`. */
interface CatalogEntry {
  name: string
  latestVersion: string
  versions: CatalogVersion[]
}

/** Mirrors `plugins::InstallOutcome`. */
interface InstallOutcome {
  name: string
  version: string
}

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
 * Browse this tenant's entitled desktop plugin catalog (fetched from the
 * chosen backend — see src-tauri/src/plugins/) and install a version at
 * runtime. Installing writes into the writable `plugins-downloaded/` dir and
 * reloads FrankenPHP (its worker only discovers plugins once, at boot) — this
 * page watches the same `php:status` event `PhpHostDemo` does to show that
 * reload, then re-checks `GET /__whity/plugins` to confirm the plugin landed
 * loaded (surfacing the quarantine reason if the plugin host rejected it).
 */
export function PluginsPage() {
  const [catalog, setCatalog] = React.useState<CatalogEntry[] | null>(null)
  const [catalogError, setCatalogError] = React.useState<string | null>(null)
  const [installingName, setInstallingName] = React.useState<string | null>(null)
  const [installError, setInstallError] = React.useState<string | null>(null)
  const [lastOutcome, setLastOutcome] = React.useState<InstallOutcome | null>(null)
  const [phpStatus, setPhpStatus] = React.useState<PhpStatusEvent | null>(null)
  const [pluginsReport, setPluginsReport] = React.useState<PluginsReport | null>(null)
  const awaitingReloadRef = React.useRef(false)

  const loadCatalog = React.useCallback(async () => {
    setCatalogError(null)
    try {
      setCatalog(await invoke<CatalogEntry[]>("plugin_catalog"))
    } catch (error) {
      setCatalogError(String(error))
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
      // not worth surfacing as an error; the user can re-check via the badge.
    }
  }, [])

  React.useEffect(() => {
    void loadCatalog()
    void loadPluginsReport()

    let unlisten: (() => void) | undefined
    void listen<PhpStatusEvent>("php:status", (event) => {
      setPhpStatus(event.payload)
      if (event.payload.state === "ready" && awaitingReloadRef.current) {
        awaitingReloadRef.current = false
        void loadPluginsReport()
      }
    }).then((un) => {
      unlisten = un
    })

    return () => unlisten?.()
  }, [loadCatalog, loadPluginsReport])

  async function handleInstall(entry: CatalogEntry, version: CatalogVersion) {
    setInstallingName(entry.name)
    setInstallError(null)
    try {
      const outcome = await invoke<InstallOutcome>("plugin_install", {
        name: entry.name,
        version: version.version,
        expectedSha256: version.sha256,
      })
      setLastOutcome(outcome)
      awaitingReloadRef.current = true
    } catch (error) {
      setInstallError(String(error))
    } finally {
      setInstallingName(null)
    }
  }

  const reloading = phpStatus?.state === "reloading" || awaitingReloadRef.current
  const loadedNames = new Set(pluginsReport?.loaded.map((p) => p.name) ?? [])
  const quarantinedByName = new Map((pluginsReport?.quarantined ?? []).map((q) => [q.name, q.reason]))

  return (
    <div className="space-y-4">
      {reloading && (
        <p className="text-sm text-muted-foreground">Reloading the PHP plugin host…</p>
      )}
      {lastOutcome && !reloading && (
        <p className="text-sm text-muted-foreground">
          Installed {lastOutcome.name} v{lastOutcome.version}.
        </p>
      )}
      {installError && (
        <p role="alert" className="text-sm text-destructive">
          {installError}
        </p>
      )}

      {catalogError && (
        <Card>
          <CardContent className="pt-6">
            <p className="text-sm text-destructive">{catalogError}</p>
            <Button size="sm" variant="outline" className="mt-3" onClick={() => void loadCatalog()}>
              Retry
            </Button>
          </CardContent>
        </Card>
      )}

      {catalog && catalog.length === 0 && !catalogError && (
        <p className="text-sm text-muted-foreground">No desktop plugins are available from this server yet.</p>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        {catalog?.map((entry) => {
          const latest = entry.versions.find((v) => v.version === entry.latestVersion) ?? entry.versions[0]
          const isLoaded = loadedNames.has(entry.name)
          const quarantineReason = quarantinedByName.get(entry.name)

          return (
            <Card key={entry.name}>
              <CardHeader>
                <CardTitle className="flex items-center justify-between gap-2 text-sm">
                  {entry.name}
                  {isLoaded && <Badge variant="success-solid">Loaded</Badge>}
                  {quarantineReason && <Badge variant="destructive-solid">Quarantined</Badge>}
                </CardTitle>
                <CardDescription>Latest v{entry.latestVersion}</CardDescription>
              </CardHeader>
              <CardContent className="space-y-1 text-xs text-muted-foreground">
                {latest && (
                  <>
                    <p>Released {latest.releasedAt}</p>
                    <p>{(latest.sizeBytes / 1024).toFixed(1)} KB</p>
                  </>
                )}
                {quarantineReason && <p className="text-destructive">{quarantineReason}</p>}
              </CardContent>
              <CardFooter>
                <Button
                  size="sm"
                  disabled={!latest || installingName === entry.name || isLoaded}
                  onClick={() => latest && void handleInstall(entry, latest)}
                >
                  {isLoaded ? "Installed" : installingName === entry.name ? "Installing…" : "Install"}
                </Button>
              </CardFooter>
            </Card>
          )
        })}
      </div>
    </div>
  )
}
