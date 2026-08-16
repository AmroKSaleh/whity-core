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

/** Mirrors `commands::auth::EnrollResult` (internally-tagged on `status`). */
export type EnrollResult =
  | { status: "enrolled"; email: string; deviceId: number }
  | { status: "requires2fa"; tempToken: string }
  | { status: "requiresTenantSelection"; selectionToken: string | null }

export const authClient = {
  /** The current enrollment/session snapshot (pure local read). */
  status: () => invoke<AuthStatus>("auth_status"),

  /** The offline-lock verdict derived from the stored auth state (pure, no network). */
  lockState: () => invoke<LockState>("auth_lock_state"),

  /** Interactive one-time enrollment: login -> register device -> store credential -> first exchange. */
  enroll: (email: string, password: string, deviceName: string) =>
    invoke<EnrollResult>("auth_enroll", { email, password, deviceName }),

  /** Exchange the stored credential for a fresh session (resets the offline-lock clock). */
  login: () => invoke<AuthStatus>("auth_login"),

  /** Best-effort server revocation + clear the local credential (local DATA is left intact). */
  logout: () => invoke<void>("auth_logout"),

  /** The backend URL currently in effect — pre-fills the login screen's Server field. */
  getBackendUrl: () => invoke<string>("get_backend_url"),

  /** Point subsequent backend calls at a new server (takes effect immediately;
   * only persisted on a successful `enroll`). */
  setBackendUrl: (url: string) => invoke<string>("set_backend_url", { url }),
}
