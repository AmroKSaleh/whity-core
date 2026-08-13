import * as React from "react"
import { invoke } from "@tauri-apps/api/core"
import { listen } from "@tauri-apps/api/event"

import { Button } from "@amroksaleh/ui/button"
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@amroksaleh/ui/card"
import { Textarea } from "@amroksaleh/ui/textarea"

type PhpStatusEvent =
  | { state: "starting" }
  | { state: "ready"; port: number }
  | { state: "crashed"; message: string }
  | { state: "restarting"; attempt: number }
  | { state: "failed"; message: string }

interface PhpResponse {
  status: number
  body: unknown
}

/**
 * Worked example of the offline PHP plugin host: DemoCatalog (a real,
 * unmodified whity plugin) and PrintDemo (a new plugin calling back into
 * Rust for native hardware) both running inside a bundled FrankenPHP
 * process. See src-tauri/src/php_host/ for the Rust side and
 * templates/tauri-desktop/php-host/ for the PHP app itself.
 */
export function PhpHostDemo() {
  const [ready, setReady] = React.useState(false)
  const [lastEvent, setLastEvent] = React.useState<PhpStatusEvent | null>(null)
  const [listResult, setListResult] = React.useState("")
  const [printText, setPrintText] = React.useState("Hello from a whity plugin!")
  const [printResult, setPrintResult] = React.useState<
    { kind: "idle" } | { kind: "ok"; body: unknown } | { kind: "error"; message: string }
  >({ kind: "idle" })

  React.useEffect(() => {
    let cancelled = false
    void invoke<boolean>("php_host_status").then((status) => {
      if (!cancelled) setReady(status)
    })

    let unlisten: (() => void) | undefined
    void listen<PhpStatusEvent>("php:status", (event) => {
      setLastEvent(event.payload)
      setReady(event.payload.state === "ready")
    }).then((un) => {
      unlisten = un
    })

    return () => {
      cancelled = true
      unlisten?.()
    }
  }, [])

  async function handleList() {
    try {
      const response = await invoke<PhpResponse>("php_request", {
        method: "GET",
        path: "/api/demo-catalog/items",
        body: null,
      })
      setListResult(JSON.stringify(response, null, 2))
    } catch (error) {
      setListResult(String(error))
    }
  }

  async function handlePrint() {
    setPrintResult({ kind: "idle" })
    try {
      const response = await invoke<PhpResponse>("php_request", {
        method: "POST",
        path: "/api/print-demo/print",
        body: { text: printText },
      })
      setPrintResult({ kind: "ok", body: response.body })
    } catch (error) {
      setPrintResult({ kind: "error", message: String(error) })
    }
  }

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle>PHP plugin host</CardTitle>
          <CardDescription>
            {ready ? "Ready — FrankenPHP is serving real whity plugins offline." : "Starting…"}{" "}
            {lastEvent && (lastEvent.state === "crashed" || lastEvent.state === "failed") && (
              <span className="text-destructive">{lastEvent.message}</span>
            )}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <Button onClick={handleList} disabled={!ready}>
            List DemoCatalog items (via PHP)
          </Button>
          {listResult && <pre className="whitespace-pre-wrap text-xs">{listResult}</pre>}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>PrintDemo plugin (native round trip)</CardTitle>
          <CardDescription>
            A whity plugin calling back into Rust for hardware access via the native bridge —
            proves a plugin written once can reach printers/scanners offline.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <Textarea value={printText} onChange={(event) => setPrintText(event.target.value)} rows={2} />
          <Button onClick={handlePrint} disabled={!ready}>
            Print via PHP plugin
          </Button>
          {printResult.kind === "ok" && (
            <p className="text-xs text-muted-foreground">{JSON.stringify(printResult.body)}</p>
          )}
          {printResult.kind === "error" && <p className="text-xs text-destructive">{printResult.message}</p>}
        </CardContent>
      </Card>
    </div>
  )
}
