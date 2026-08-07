import * as React from "react"
import { invoke } from "@tauri-apps/api/core"

import { Button } from "@amroksaleh/ui/button"
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@amroksaleh/ui/card"
import { Textarea } from "@amroksaleh/ui/textarea"

/**
 * Worked example of the "custom function" extension pattern the brief asked
 * for: a native capability (printing) that plain JS/web code cannot do,
 * implemented as a Rust crate + a #[tauri::command], called from the
 * frontend exactly like any other Tauri command.
 *
 * See src-tauri/src/commands/printer.rs for the Rust side (built on the
 * `printers` crate) and the template README's "Adding a new native
 * capability" section for the step-by-step recipe this command followed.
 */
export function PrinterDemo() {
  const [text, setText] = React.useState("Hello from the Whity Tauri template!")
  const [status, setStatus] = React.useState<{ kind: "idle" } | { kind: "ok"; printer: string } | { kind: "error"; message: string }>({
    kind: "idle",
  })
  const [printing, setPrinting] = React.useState(false)

  async function handlePrint() {
    setPrinting(true)
    setStatus({ kind: "idle" })
    try {
      const printer = await invoke<string>("print_text", { text })
      setStatus({ kind: "ok", printer })
    } catch (error) {
      setStatus({ kind: "error", message: String(error) })
    } finally {
      setPrinting(false)
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Printer demo (native crate example)</CardTitle>
        <CardDescription>
          Sends this text to the OS default printer via a Rust command backed by the `printers`
          crate — proves the "add a crate for custom functionality" pattern with a real capability
          the web can't do.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <Textarea value={text} onChange={(event) => setText(event.target.value)} rows={3} />
        <Button onClick={handlePrint} disabled={printing}>
          {printing ? "Printing…" : "Print"}
        </Button>
        {status.kind === "ok" && (
          <p className="text-xs text-muted-foreground">Sent to printer: {status.printer}</p>
        )}
        {status.kind === "error" && <p className="text-xs text-destructive">{status.message}</p>}
      </CardContent>
    </Card>
  )
}
