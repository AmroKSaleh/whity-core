import * as React from "react"

import { Alert, AlertDescription } from "@amroksaleh/ui/alert"
import { Button } from "@amroksaleh/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@amroksaleh/ui/card"
import { Input } from "@amroksaleh/ui/input"
import { LockedScreen } from "@amroksaleh/ui/locked-screen"
import { RadioGroup, RadioGroupItem } from "@amroksaleh/ui/radio-group"
import { useSyncStatus } from "@amroksaleh/features/sync"

import { authClient, type AuthStatus, type EnrollResult, type TenantMembership } from "./auth-client"
import { appT } from "./sync-i18n"
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
 * The four states {@link AuthGate} (and, above it, `App.tsx` — which needs to
 * know the SAME thing to decide whether the sidebar mounts at all, matching
 * the website's split between `/login` (no chrome) and
 * `app/(protected)/layout.tsx`, sidebar only once authenticated) branch on.
 * A single hook so the two call sites can never disagree about which state
 * they're in.
 */
export type AuthGateState = "loading" | "unauthenticated" | "locked" | "ready"

export function useAuthGateState(): AuthGateState {
  const { controller, auth, authLoaded } = useAppState()
  const status = useSyncStatus(controller)

  if (!authLoaded) return "loading"
  if (!auth?.enrolled) return "unauthenticated"
  if (status?.locked) return "locked"
  return "ready"
}

/**
 * Gates its children behind enrollment + the offline lock. Not enrolled -> the
 * one-time {@link EnrollForm}; enrolled but locked (TTL elapsed) -> a
 * {@link LockedScreen} that re-authenticates online; otherwise the app renders.
 * Matches the website's `/login` page (full-screen, centered, no sidebar) for
 * both of the gated states — `App.tsx` only mounts the sidebar shell once this
 * reaches "ready".
 */
export function AuthGate({ children }: { children: React.ReactNode }) {
  const { controller, auth, reloadAuth } = useAppState()
  const state = useAuthGateState()

  if (state === "loading") return null

  if (state === "unauthenticated") {
    return (
      <FullScreenAuthShell>
        <EnrollForm
          onEnrolled={async () => {
            await reloadAuth()
            await controller.refresh()
          }}
        />
      </FullScreenAuthShell>
    )
  }

  if (state === "locked") {
    return (
      <FullScreenAuthShell>
        <ReloginScreen
          email={auth?.email ?? null}
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
      </FullScreenAuthShell>
    )
  }

  return <>{children}</>
}

/** Matches web/app/login/page.tsx's own outermost wrapper exactly, so the
 * enroll/relogin card sits centered on a bare page — no sidebar, no header. */
function FullScreenAuthShell({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-background to-muted p-4">
      {children}
    </div>
  )
}

/**
 * A login the server refused to resolve on its own (#914): the profile holds
 * ACTIVE memberships in more than one tenant, so no session was issued and a
 * choice is owed. Held between the two steps of {@link EnrollForm}.
 */
interface PendingTenantSelection {
  /** Short-lived (300s) token binding the choice to this login. */
  selectionToken: string
  memberships: TenantMembership[]
}

/**
 * Renders for both first-enrollment and the `onReenroll` re-entry point (see
 * {@link AuthGate}'s `ReloginScreen`). There is deliberately no Server field
 * here - the backend is fixed for the whole build (`WHITY_BACKEND_URL` in
 * `.env`, see `src-tauri/src/config.rs`), never chosen per device, so this
 * app is portable to a different whity-core instance by changing exactly
 * that one setting and rebuilding.
 *
 * TWO STEPS, and only when the server asks for the second: credentials, then -
 * if the profile is active in several tenants - a {@link TenantPicker}. The
 * enrollment tail (register device, keychain, first exchange, `auth_state`) is
 * identical either way; it just runs behind a different Rust command.
 */
function EnrollForm({ onEnrolled }: { onEnrolled: () => void | Promise<void> }) {
  const [email, setEmail] = React.useState("")
  const [password, setPassword] = React.useState("")
  const [deviceName, setDeviceName] = React.useState("Whity Desktop")
  const [busy, setBusy] = React.useState(false)
  const [error, setError] = React.useState<string | null>(null)
  const [pending, setPending] = React.useState<PendingTenantSelection | null>(null)

  /**
   * Both steps land here, because both Rust commands answer with the same
   * {@link EnrollResult}. Keeping one reducer is what stops the two call sites
   * disagreeing about what "enrolled" or "lapsed" means.
   */
  const applyResult = async (result: EnrollResult) => {
    switch (result.status) {
      case "enrolled":
        await onEnrolled()
        return
      case "requiresTenantSelection":
        if (result.selectionToken === null) {
          // Token mode always returns one; without it the flow cannot proceed.
          setError(appT("enroll.error.noSelectionToken"))
          return
        }
        setError(null)
        setPending({ selectionToken: result.selectionToken, memberships: result.memberships })
        return
      case "selectionLapsed":
        // RETRYABLE, never a dead end: drop back to the credentials step with
        // the fields still filled, so re-submitting is a single click.
        setPending(null)
        setError(appT("enroll.tenant.lapsed"))
        return
      case "requires2fa":
        // Still a gap - a code against a temp token is a different flow, and
        // no command completes it (#914's "out of scope").
        setError(appT("enroll.error.requires2fa"))
        return
    }
  }

  const run = async (action: () => Promise<EnrollResult>) => {
    setBusy(true)
    setError(null)
    try {
      await applyResult(await action())
    } catch (err) {
      setError(String(err))
    } finally {
      setBusy(false)
    }
  }

  const submitCredentials = async (event: React.FormEvent) => {
    event.preventDefault()
    await run(() => authClient.enroll(email, password, deviceName))
  }

  const submitTenant = async (tenantId: number) => {
    if (pending === null) return
    await run(() => authClient.enrollWithTenant(pending.selectionToken, tenantId, deviceName, email))
  }

  if (pending !== null) {
    return (
      <TenantPicker
        memberships={pending.memberships}
        busy={busy}
        error={error}
        onSelect={submitTenant}
        onBack={() => {
          setPending(null)
          setError(null)
        }}
      />
    )
  }

  return (
    // Card/header/form shape matches the website's login page
    // (web/app/login/page.tsx) exactly - same `w-full max-w-md` card sitting
    // directly in FullScreenAuthShell's centered wrapper (AuthGate, above),
    // centered title + description, labeled fields in space-y-4/space-y-2, an
    // Alert for the error banner, and a full-width submit button.
    <Card className="w-full max-w-md">
      <CardHeader className="text-center">
        <CardTitle className="text-2xl">{appT("enroll.title")}</CardTitle>
        <CardDescription>{appT("enroll.description")}</CardDescription>
      </CardHeader>
      <CardContent>
        <form className="space-y-4" onSubmit={submitCredentials}>
          {error ? (
            <Alert variant="destructive">
              <AlertDescription role="alert">{error}</AlertDescription>
            </Alert>
          ) : null}

          <div className="space-y-2">
            <label htmlFor="enroll-email" className="text-sm font-medium">
              {appT("enroll.emailLabel")}
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
              {appT("enroll.passwordLabel")}
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
              {appT("enroll.deviceNameLabel")}
            </label>
            <Input
              id="enroll-device-name"
              value={deviceName}
              onChange={(e) => setDeviceName(e.target.value)}
              required
            />
          </div>

          <Button type="submit" className="w-full" disabled={busy || !email || !password}>
            {busy ? appT("enroll.submitting") : appT("enroll.submit")}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}

/** A tenant's display label, falling back to its id when the row has no name. */
function tenantLabel(membership: TenantMembership): string {
  const name = membership.tenantName.trim()
  return name === "" ? `${appT("enroll.tenant.unnamed")} ${membership.tenantId}` : name
}

/**
 * The second enrollment step (#914): which tenant this device enrolls into.
 *
 * NOTHING IS PRE-SELECTED, and the submit button stays disabled until the
 * operator picks. That is the whole point rather than a styling choice: per
 * `AuthHandler::handleSelectTenant()`'s docblock, a profile that genuinely
 * holds an active tenant-0 membership choosing it is legitimate system
 * authority - the escalation that was closed is a multi-membership profile
 * having tenant 0 silently AUTO-picked. So the system tenant is listed as an
 * equal choice, no membership is filtered out, and no default is applied even
 * when only one option looks like the obvious candidate. The server re-validates
 * the choice against a live ACTIVE membership regardless of what is sent.
 *
 * Tenant names are tenant DATA and are frequently Arabic, so every one is
 * rendered `dir="auto"` (the same treatment the shared sync components give
 * user content).
 */
function TenantPicker({
  memberships,
  busy,
  error,
  onSelect,
  onBack,
}: {
  memberships: TenantMembership[]
  busy: boolean
  error: string | null
  onSelect: (tenantId: number) => void | Promise<void>
  onBack: () => void
}) {
  // `null` = no choice yet. Never seeded from `memberships`.
  const [choice, setChoice] = React.useState<string | null>(null)

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    // `Number("0")` is 0 - the system tenant is a real, selectable id here, so
    // this must never be guarded with a truthiness check.
    if (choice !== null) void onSelect(Number(choice))
  }

  return (
    <Card className="w-full max-w-md">
      <CardHeader className="text-center">
        <CardTitle className="text-2xl">{appT("enroll.tenant.title")}</CardTitle>
        <CardDescription>{appT("enroll.tenant.description")}</CardDescription>
      </CardHeader>
      <CardContent>
        <form className="space-y-4" onSubmit={submit}>
          {error ? (
            <Alert variant="destructive">
              <AlertDescription role="alert">{error}</AlertDescription>
            </Alert>
          ) : null}

          {memberships.length === 0 ? (
            <Alert variant="destructive">
              <AlertDescription role="alert">{appT("enroll.tenant.empty")}</AlertDescription>
            </Alert>
          ) : (
            <RadioGroup
              aria-label={appT("enroll.tenant.legend")}
              value={choice ?? ""}
              onValueChange={setChoice}
            >
              {memberships.map((membership) => (
                <label
                  key={membership.tenantId}
                  htmlFor={`tenant-${membership.tenantId}`}
                  className="flex cursor-pointer items-start gap-3 rounded-md border p-3 hover:bg-accent"
                >
                  <RadioGroupItem
                    id={`tenant-${membership.tenantId}`}
                    value={String(membership.tenantId)}
                    className="mt-0.5"
                  />
                  <span className="grid gap-0.5">
                    <span dir="auto" className="text-sm font-medium">
                      {tenantLabel(membership)}
                    </span>
                    {membership.role ? (
                      <span dir="auto" className="text-xs text-muted-foreground">
                        {membership.role}
                      </span>
                    ) : null}
                  </span>
                </label>
              ))}
            </RadioGroup>
          )}

          <Button
            type="submit"
            className="w-full"
            disabled={busy || choice === null || memberships.length === 0}
          >
            {busy ? appT("enroll.submitting") : appT("enroll.tenant.submit")}
          </Button>
          <Button type="button" variant="link" className="w-full" onClick={onBack} disabled={busy}>
            {appT("enroll.tenant.back")}
          </Button>
        </form>
      </CardContent>
    </Card>
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
