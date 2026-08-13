import * as React from "react"

import { Button } from "@amroksaleh/ui/button"
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

function EnrollForm({ onEnrolled }: { onEnrolled: () => void | Promise<void> }) {
  const [email, setEmail] = React.useState("")
  const [password, setPassword] = React.useState("")
  const [deviceName, setDeviceName] = React.useState("Whity Desktop")
  const [busy, setBusy] = React.useState(false)
  const [error, setError] = React.useState<string | null>(null)

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
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
    <div className="mx-auto flex min-h-[450px] max-w-md flex-col justify-center">
      <h2 className="mb-1 text-xl font-bold text-foreground">Enroll this device</h2>
      <p className="mb-6 text-sm text-muted-foreground">
        Sign in once to register this device with the Whity backend. A long-lived credential is stored
        in your OS keychain; work then continues offline until the login window elapses.
      </p>
      <form className="grid gap-3" onSubmit={submit}>
        <label className="grid gap-1.5 text-sm">
          <span className="font-medium">Email</span>
          <Input
            type="email"
            autoComplete="username"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </label>
        <label className="grid gap-1.5 text-sm">
          <span className="font-medium">Password</span>
          <Input
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
        </label>
        <label className="grid gap-1.5 text-sm">
          <span className="font-medium">Device name</span>
          <Input value={deviceName} onChange={(e) => setDeviceName(e.target.value)} required />
        </label>
        {error ? (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        ) : null}
        <Button type="submit" disabled={busy || !email || !password}>
          {busy ? "Enrolling…" : "Enroll device"}
        </Button>
      </form>
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
