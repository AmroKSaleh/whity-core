import * as React from "react"

import { Alert, AlertDescription } from "@amroksaleh/ui/alert"
import { Button } from "@amroksaleh/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@amroksaleh/ui/card"
import { Input } from "@amroksaleh/ui/input"
import { LockedScreen } from "@amroksaleh/ui/locked-screen"
import { useSyncStatus } from "@amroksaleh/features/sync"

import { authClient, type AuthStatus, type EnrollResult } from "./auth-client"
import { createTauriSyncController, type TauriSyncController } from "./sync-controller-tauri"

/**
 * App-wide state for the offline-first sync feature (WC-desktop-sync): owns the
 * single {@link TauriSyncController} (created once, started on mount) and the
 * current {@link AuthStatus}, and exposes them via context. {@link AuthGate}
 * consumes both to gate the app behind enrollment + the offline TTL lock.
 */
interface AppStateValue {
  controller: TauriSyncController
  auth: AuthStatus | null
  authLoaded: boolean
  reloadAuth: () => Promise<void>
}

const AppStateContext = React.createContext<AppStateValue | null>(null)

export function useAppState(): AppStateValue {
  const value = React.useContext(AppStateContext)
  if (!value) throw new Error("useAppState must be used within an <AppStateProvider>")
  return value
}

export function AppStateProvider({ children }: { children: React.ReactNode }) {
  // One stable controller for the app's lifetime.
  const controllerRef = React.useRef<TauriSyncController | null>(null)
  if (controllerRef.current === null) controllerRef.current = createTauriSyncController()
  const controller = controllerRef.current

  const [auth, setAuth] = React.useState<AuthStatus | null>(null)
  const [authLoaded, setAuthLoaded] = React.useState(false)

  const reloadAuth = React.useCallback(async () => {
    try {
      setAuth(await authClient.status())
    } catch (err) {
      console.warn("auth_status failed:", err)
    } finally {
      setAuthLoaded(true)
    }
  }, [])

  React.useEffect(() => {
    void reloadAuth()
    const dispose = controller.start()
    return dispose
  }, [controller, reloadAuth])

  const value = React.useMemo<AppStateValue>(
    () => ({ controller, auth, authLoaded, reloadAuth }),
    [controller, auth, authLoaded, reloadAuth],
  )

  return <AppStateContext.Provider value={value}>{children}</AppStateContext.Provider>
}

/**
 * Gates its children behind enrollment + the offline lock. Not enrolled -> the
 * one-time {@link EnrollForm}; enrolled but locked (TTL elapsed) -> a
 * {@link LockedScreen} that re-authenticates online; otherwise the app renders.
 * The sidebar + unsynced banner (mounted by App above this gate) stay visible
 * either way.
 */
export function AuthGate({ children }: { children: React.ReactNode }) {
  const { controller, auth, authLoaded, reloadAuth } = useAppState()
  const status = useSyncStatus(controller)

  if (!authLoaded) return null

  if (!auth?.enrolled) {
    return (
      <EnrollForm
        onEnrolled={async () => {
          await reloadAuth()
          await controller.refresh()
        }}
      />
    )
  }

  if (status?.locked) {
    return (
      <ReloginScreen
        email={auth.email}
        onRelogin={async () => {
          await authClient.login()
          await reloadAuth()
          await controller.refresh()
        }}
        onReenroll={async () => {
          // Clears the keychain credential + auth_state (local DATA is left
          // intact), which drops this gate back to <EnrollForm> — the only
          // screen that takes a password.
          await authClient.logout()
          await reloadAuth()
          await controller.refresh()
        }}
      />
    )
  }

  return <>{children}</>
}

/**
 * Renders for both first-enrollment and the `onReenroll` re-entry point (see
 * {@link AuthGate}'s `ReloginScreen`) — the Server field pre-fills with
 * whatever backend is currently in effect (the compile-time default on a
 * fresh install, or the previously chosen/stored one on re-enroll) via
 * `get_backend_url`, and stays editable so the user can point the device at a
 * different instance either way.
 */
function EnrollForm({ onEnrolled }: { onEnrolled: () => void | Promise<void> }) {
  const [serverUrl, setServerUrl] = React.useState("")
  const [serverLoaded, setServerLoaded] = React.useState(false)
  const [email, setEmail] = React.useState("")
  const [password, setPassword] = React.useState("")
  const [deviceName, setDeviceName] = React.useState("Whity Desktop")
  const [busy, setBusy] = React.useState(false)
  const [error, setError] = React.useState<string | null>(null)

  React.useEffect(() => {
    let cancelled = false
    void authClient
      .getBackendUrl()
      .then((url) => {
        if (!cancelled) setServerUrl(url)
      })
      .finally(() => {
        if (!cancelled) setServerLoaded(true)
      })
    return () => {
      cancelled = true
    }
  }, [])

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      // Point the backend at the chosen server BEFORE logging in, so `enroll`
      // (login -> register device -> exchange) hits the right instance.
      try {
        await authClient.setBackendUrl(serverUrl)
      } catch (err) {
        setError(`Invalid server: ${String(err)}`)
        return
      }

      const result: EnrollResult = await authClient.enroll(email, password, deviceName)
      if (result.status === "enrolled") {
        await onEnrolled()
      } else if (result.status === "requires2fa") {
        setError("This account requires 2FA. The template's enrollment supports single-tenant, non-2FA accounts only (see src-tauri/src/commands/auth.rs).")
      } else {
        setError("This account has multiple tenants. The template's enrollment supports single-tenant accounts only.")
      }
    } catch (err) {
      setError(String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto flex min-h-[500px] w-full max-w-md flex-col justify-center py-8">
      {/* Card/header/form shape matches the website's login page
          (web/app/login/page.tsx) — centered title + description, labeled
          fields in space-y-4/space-y-2, an Alert for the error banner, and a
          full-width submit button. The Rust-backed enroll flow itself
          (setBackendUrl -> enroll) is unchanged; only the visual shell here. */}
      <Card>
        <CardHeader className="text-center">
          <CardTitle className="text-2xl">Welcome to Whity Desktop</CardTitle>
          <CardDescription>
            Sign in once to register this device with the Whity backend. A long-lived credential is
            stored in your OS keychain; work then continues offline until the login window elapses.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form className="space-y-4" onSubmit={submit}>
            {error ? (
              <Alert variant="destructive">
                <AlertDescription role="alert">{error}</AlertDescription>
              </Alert>
            ) : null}

            <div className="space-y-2">
              <label htmlFor="enroll-server" className="text-sm font-medium">
                Server
              </label>
              <Input
                id="enroll-server"
                type="url"
                placeholder="https://your-instance.example.com"
                value={serverUrl}
                onChange={(e) => setServerUrl(e.target.value)}
                disabled={!serverLoaded}
                required
              />
            </div>

            <div className="space-y-2">
              <label htmlFor="enroll-email" className="text-sm font-medium">
                Email
              </label>
              <Input
                id="enroll-email"
                type="email"
                autoComplete="username"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
              />
            </div>

            <div className="space-y-2">
              <label htmlFor="enroll-password" className="text-sm font-medium">
                Password
              </label>
              <Input
                id="enroll-password"
                type="password"
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
              />
            </div>

            <div className="space-y-2">
              <label htmlFor="enroll-device-name" className="text-sm font-medium">
                Device name
              </label>
              <Input
                id="enroll-device-name"
                value={deviceName}
                onChange={(e) => setDeviceName(e.target.value)}
                required
              />
            </div>

            <Button type="submit" className="w-full" disabled={busy || !serverUrl || !email || !password}>
              {busy ? "Enrolling…" : "Enroll device"}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}

/**
 * The locked state re-authenticates SILENTLY — it exchanges the device
 * credential already in the OS keychain, so there is deliberately no password
 * field here (the password only ever appears at first enrollment).
 *
 * That leaves one dead end, which `onReenroll` exists to open: if the stored
 * credential is no longer valid for the CURRENT backend — the device was
 * revoked, the credential expired, or (easy to hit) the build was repointed at
 * a different `WHITY_BACKEND_URL` than the one it enrolled against, since
 * neither the keychain entry nor `auth_state` is scoped per backend — the
 * exchange fails forever and `enrolled` stays true, so the gate never falls
 * back to the enroll form on its own.
 */
function ReloginScreen({
  email,
  onRelogin,
  onReenroll,
}: {
  email: string | null
  onRelogin: () => Promise<void>
  onReenroll: () => Promise<void>
}) {
  const [busy, setBusy] = React.useState<"relogin" | "reenroll" | null>(null)
  const [error, setError] = React.useState<string | null>(null)

  const run = (kind: "relogin" | "reenroll", action: () => Promise<void>, label: string) => async () => {
    setBusy(kind)
    setError(null)
    try {
      await action()
    } catch (err) {
      setError(
        kind === "relogin"
          ? "Couldn't sign in with this device's stored credential. The server may be unreachable — or the credential is no longer valid for it (device revoked, or this build points at a different backend than it enrolled against). Use the password option below in that case."
          : "Couldn't clear this device's enrollment. See the console for details.",
      )
      console.warn(`${label} failed:`, err)
    } finally {
      setBusy(null)
    }
  }

  return (
    <LockedScreen
      description={
        <>
          Your offline login window has elapsed{email ? ` for ${email}` : ""}. Sign in online to keep
          working — your local changes are safe and will sync once you're back in.
        </>
      }
      action={
        <div className="grid gap-2">
          <Button onClick={run("relogin", onRelogin, "auth_login")} disabled={busy !== null}>
            {busy === "relogin" ? "Signing in…" : "Sign in again"}
          </Button>
          <Button
            variant="link"
            onClick={run("reenroll", onReenroll, "auth_logout")}
            disabled={busy !== null}
          >
            {busy === "reenroll" ? "Clearing…" : "Sign in with your password instead"}
          </Button>
          {error ? (
            <p role="alert" className="text-sm text-destructive">
              {error}
            </p>
          ) : null}
        </div>
      }
    />
  )
}
