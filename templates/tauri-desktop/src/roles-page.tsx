import * as React from "react"

import { Alert, AlertDescription } from "@amroksaleh/ui/alert"
import { RolesScreen } from "@amroksaleh/features/roles"

import { rolesAdapter } from "./roles-tauri-adapter"
import { rolesT } from "./sync-i18n"

/**
 * Desktop mount of the shared Roles admin (@amroksaleh/features/roles) — the
 * same React that renders the Roles page on web, injected with the desktop
 * `rolesAdapter` (which talks to the enrolled remote instance over
 * `remote_request`) instead of web's cookie-authenticated adapter. That is the
 * whole point of Path B: one page, two transports.
 *
 * This wrapper owns the three cross-cutting props `RolesScreen` needs and the
 * host must supply:
 *   - `can`: built from this device user's effective capability slugs
 *     (`GET /api/v1/me/capabilities` via the adapter), fail-closed until they
 *     load so Edit/Create/Delete stay hidden rather than flashing enabled.
 *   - `t`: the app's roles translator (see `sync-i18n.rolesT`).
 *   - `onNotify`: a transient inline banner — this template ships no toast
 *     runtime, so a dismissing `Alert` is the desktop equivalent.
 *
 * `RolesScreen` renders its OWN `PageHeader`, so App.tsx mounts this without the
 * wrapping header the other routes add.
 */
export function RolesPage() {
  const [capabilities, setCapabilities] = React.useState<Set<string> | null>(null)
  const [notice, setNotice] = React.useState<{ message: string; type: "success" | "error" } | null>(null)

  React.useEffect(() => {
    let cancelled = false
    void rolesAdapter
      .getCapabilities()
      .then((slugs) => {
        if (!cancelled) setCapabilities(new Set(slugs))
      })
      .catch(() => {
        // Offline / not enrolled → deny-by-default (fail-closed), matching the
        // server's own gate; the screen still lists roles read-only.
        if (!cancelled) setCapabilities(new Set())
      })
    return () => {
      cancelled = true
    }
  }, [])

  React.useEffect(() => {
    if (!notice) return
    const timer = window.setTimeout(() => setNotice(null), 5000)
    return () => window.clearTimeout(timer)
  }, [notice])

  const can = React.useCallback(
    (capability: string) => capabilities?.has(capability) ?? false,
    [capabilities],
  )
  const onNotify = React.useCallback(
    (message: string, type: "success" | "error") => setNotice({ message, type }),
    [],
  )

  return (
    <>
      {notice && (
        <div className="mb-4">
          <Alert variant={notice.type === "error" ? "destructive" : "success"}>
            <AlertDescription>{notice.message}</AlertDescription>
          </Alert>
        </div>
      )}
      <RolesScreen adapter={rolesAdapter} can={can} t={rolesT} onNotify={onNotify} />
    </>
  )
}
