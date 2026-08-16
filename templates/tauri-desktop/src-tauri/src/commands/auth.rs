//! Device-auth commands (WC-desktop-sync) — the enroll/exchange/logout
//! orchestration. Keeps the DB write (`auth_state`) here rather than in
//! `AuthManager`, so the manager stays a thin HTTP/token holder.
//!
//! Scope note: the happy path (single-tenant, no-2FA login → enroll → exchange)
//! is fully wired + verified. When login needs 2FA or tenant selection,
//! `auth_enroll` returns that as a terminal outcome so the UI can prompt; the
//! submit-2FA / select-tenant completion is a scoped follow-up (a backend test
//! account exercising those paths is needed to verify them).

use std::time::{SystemTime, UNIX_EPOCH};

use serde::Serialize;
use tauri::{AppHandle, State};

use crate::auth::api::{self, LoginOutcome};
use crate::auth::lock::{self, LockState};
use crate::auth::{credential_store, AuthManager};
use crate::commands::post_login;
use crate::db::auth_repo::{self, AuthStatus};
use crate::db::Db;

#[derive(Serialize)]
#[serde(rename_all = "camelCase", tag = "status")]
pub enum EnrollResult {
    /// Fully enrolled: credential stored + first session exchanged.
    Enrolled { email: String, device_id: i64 },
    /// Login needs a 2FA code; complete with `temp_token` (follow-up).
    Requires2fa { temp_token: String },
    /// The profile has multiple tenants; a selection is required (follow-up).
    RequiresTenantSelection { selection_token: Option<String> },
}

/// Enroll this device: login → (if a full session) register the device, store
/// the credential in the keychain, exchange it for a first session, and record
/// `auth_state`.
#[tauri::command]
pub fn auth_enroll(
    app: AppHandle,
    db: State<'_, Db>,
    auth: State<'_, AuthManager>,
    email: String,
    password: String,
    device_name: String,
) -> Result<EnrollResult, String> {
    let cfg = auth.config();
    match api::login(auth.client(), &cfg, &email, &password)? {
        LoginOutcome::Session { access_token } => {
            finish_enroll(&app, &db, &auth, &cfg, &access_token, &device_name, &email)
        }
        LoginOutcome::Requires2fa { temp_token } => Ok(EnrollResult::Requires2fa { temp_token }),
        LoginOutcome::RequiresTenantSelection { selection_token } => {
            Ok(EnrollResult::RequiresTenantSelection { selection_token })
        }
    }
}

fn finish_enroll(
    app: &AppHandle,
    db: &State<'_, Db>,
    auth: &State<'_, AuthManager>,
    cfg: &crate::config::Config,
    access_token: &str,
    device_name: &str,
    email: &str,
) -> Result<EnrollResult, String> {
    let device = api::register_device(auth.client(), cfg, access_token, device_name, cfg.platform)?;
    // Persist the 90-day credential in the OS keychain BEFORE exchanging, so an
    // exchange failure still leaves an enrolled, retryable device.
    credential_store::store(&device.credential)?;

    let session = api::exchange(auth.client(), cfg, &device.credential)?;
    // Cloned before set_access() consumes it — post_login::spawn_after_login
    // needs its own copy of the same just-exchanged token, no second round trip.
    let access_token_for_sync = session.access_token.clone();
    auth.set_access(session.access_token);

    let conn = db.0.lock().map_err(lock_err)?;
    // Records which backend this device enrolled against — informational
    // only (shown in the account footer); the backend itself is fixed for
    // the whole build (see config.rs), never chosen per device.
    auth_repo::set_enrolled(&conn, device.id, email, None, &device.expires_at, &cfg.backend_url)
        .map_err(|e| e.to_string())?;
    auth_repo::record_online_auth(&conn, now_epoch(), session.desktop_login_max_seconds)
        .map_err(|e| e.to_string())?;
    drop(conn);

    // Mandatory plugin sync (WC-plugin-sync): fire-and-forget, never blocks
    // enrollment on a slow/down catalog endpoint.
    post_login::spawn_after_login(app.clone(), auth, access_token_for_sync);

    Ok(EnrollResult::Enrolled {
        email: email.to_string(),
        device_id: device.id,
    })
}

/// Exchange the stored credential for a fresh session (call on startup /
/// reconnect). Refreshes the online-auth clock the offline lock measures.
#[tauri::command]
pub fn auth_login(app: AppHandle, db: State<'_, Db>, auth: State<'_, AuthManager>) -> Result<AuthStatus, String> {
    let credential = credential_store::load()?
        .ok_or_else(|| "not enrolled — enroll a device first".to_string())?;
    let session = api::exchange(auth.client(), &auth.config(), &credential)?;
    let access_token_for_sync = session.access_token.clone();
    auth.set_access(session.access_token);

    let conn = db.0.lock().map_err(lock_err)?;
    auth_repo::record_online_auth(&conn, now_epoch(), session.desktop_login_max_seconds)
        .map_err(|e| e.to_string())?;
    let status = auth_repo::status(&conn).map_err(|e| e.to_string())?;
    drop(conn);

    // Mandatory plugin sync (WC-plugin-sync): fire-and-forget, same as enroll.
    post_login::spawn_after_login(app, &auth, access_token_for_sync);

    Ok(status)
}

/// Log out: best-effort server revocation of the access token, clear the stored
/// credential, and reset `auth_state`. Local DATA (including the chosen
/// `server_url`) is intentionally left intact.
#[tauri::command]
pub fn auth_logout(db: State<'_, Db>, auth: State<'_, AuthManager>) -> Result<(), String> {
    if let Some(token) = auth.take_access() {
        let _ = api::logout(auth.client(), &auth.config(), &token); // best-effort
    }
    credential_store::clear()?;
    let conn = db.0.lock().map_err(lock_err)?;
    auth_repo::clear(&conn).map_err(|e| e.to_string())
}

/// The current enrollment/session snapshot (drives the UI + the offline lock).
#[tauri::command]
pub fn auth_status(db: State<'_, Db>) -> Result<AuthStatus, String> {
    let conn = db.0.lock().map_err(lock_err)?;
    auth_repo::status(&conn).map_err(|e| e.to_string())
}

/// The offline-lock state derived from the stored auth state (see auth::lock).
/// Pure read — it does NOT reach the network; the frontend calls `auth_login`
/// (an exchange) to reset the clock when it detects connectivity.
#[tauri::command]
pub fn auth_lock_state(db: State<'_, Db>) -> Result<LockState, String> {
    let conn = db.0.lock().map_err(lock_err)?;
    let status = auth_repo::status(&conn).map_err(|e| e.to_string())?;
    Ok(lock::evaluate(&status, now_epoch()))
}

fn now_epoch() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0)
}

fn lock_err<E: std::fmt::Display>(e: E) -> String {
    format!("database busy: {e}")
}
