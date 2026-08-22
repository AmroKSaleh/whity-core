import { invoke } from "@tauri-apps/api/core"

/**
 * Thin typed wrappers over the Rust device-auth commands (WC-desktop-sync — see
 * src-tauri/src/commands/auth.rs). The SECRET 90-day device credential lives in
 * the OS keychain and the short-lived access token in Rust memory — NEITHER ever
 * crosses into JS, so this module only ever moves non-secret status around.
 */

/** Mirrors `db::auth_repo::AuthStatus` (camelCase over the IPC wire). */
export interface AuthStatus {
  enrolled: boolean
  email: string | null
  deviceId: number | null
  activeTenantId: number | null
  credentialExpiresAt: string | null
  lastOnlineAuthAt: number | null
  maxLoginSeconds: number | null
  /** The backend this device last enrolled against; null before first enrollment. */
  serverUrl: string | null
}

/** Mirrors `auth::lock::LockState`. */
export interface LockState {
  locked: boolean
  /** "not_enrolled" | "ttl_expired" when locked; null when unlocked. */
  reason: string | null
  secondsRemaining: number | null
}

/**
 * Mirrors `auth::api::TenantMembership` - one tenant the signing-in profile may
 * complete enrollment into.
 *
 * `tenantId` 0 is the system tenant. It is listed like any other choice and is
 * never pre-selected: per `AuthHandler::handleSelectTenant()`, holding an
 * active tenant-0 membership and choosing it IS legitimate system authority -
 * what must never happen is it being picked silently on the operator's behalf.
 */
export interface TenantMembership {
  tenantId: number
  /** May be empty, and may be Arabic - always render it with `dir="auto"`. */
  tenantName: string
  role: string
}

/** Mirrors `commands::auth::EnrollResult` (internally-tagged on `status`). */
export type EnrollResult =
  | { status: "enrolled"; email: string; deviceId: number }
  | { status: "requires2fa"; tempToken: string }
  | { status: "requiresTenantSelection"; selectionToken: string | null; memberships: TenantMembership[] }
  /** The 300s selection token lapsed before a tenant was chosen - retryable. */
  | { status: "selectionLapsed" }

export const authClient = {
  /** The current enrollment/session snapshot (pure local read). */
  status: () => invoke<AuthStatus>("auth_status"),

  /** The offline-lock verdict derived from the stored auth state (pure, no network). */
  lockState: () => invoke<LockState>("auth_lock_state"),

  /** Interactive one-time enrollment: login -> register device -> store credential -> first exchange. */
  enroll: (email: string, password: string, deviceName: string) =>
    invoke<EnrollResult>("auth_enroll", { email, password, deviceName }),

  /**
   * Complete an enrollment that stopped at the tenant prompt (#914): the chosen
   * `tenantId` plus the login step's `selectionToken`, then the identical
   * register -> store -> exchange tail. `email` is the typed address, used only
   * as a fallback for the one the server echoes back with the session.
   */
  enrollWithTenant: (selectionToken: string, tenantId: number, deviceName: string, email: string) =>
    invoke<EnrollResult>("auth_enroll_with_tenant", { selectionToken, tenantId, deviceName, email }),

  /** Exchange the stored credential for a fresh session (resets the offline-lock clock). */
  login: () => invoke<AuthStatus>("auth_login"),

  /** Best-effort server revocation + clear the local credential (local DATA is left intact). */
  logout: () => invoke<void>("auth_logout"),
}
