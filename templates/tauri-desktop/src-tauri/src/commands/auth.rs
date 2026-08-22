//! Device-auth commands (WC-desktop-sync) — the enroll/exchange/logout
//! orchestration. Keeps the DB write (`auth_state`) here rather than in
//! `AuthManager`, so the manager stays a thin HTTP/token holder.
//!
//! Scope note: the single-tenant path (login → enroll → exchange) and the
//! MULTI-TENANT path (#914: login → prompt → `auth_enroll_with_tenant` → the
//! same enroll → exchange) are both wired. 2FA remains a gap: `auth_enroll`
//! still returns `Requires2fa` as a terminal outcome and no command completes
//! it — that flow submits a CODE against a temp token rather than choosing from
//! a list, so it shares none of this machinery.

use std::time::{SystemTime, UNIX_EPOCH};

use serde::Serialize;
use tauri::{AppHandle, State};

use crate::auth::api::{self, LoginOutcome, SelectTenantError, TenantMembership};
use crate::auth::lock::{self, LockState};
use crate::auth::{credential_store, AuthManager};
use crate::commands::post_login;
use crate::db::auth_repo::{self, AuthStatus};
use crate::db::Db;

/// What an enrollment attempt settled on.
///
/// `rename_all_fields` is load-bearing, not tidiness: on an ENUM, serde's
/// `rename_all` renames the VARIANTS only, so without it `selection_token`
/// would reach the webview as `selection_token` while `auth-client.ts` reads
/// `selectionToken` — and the follow-up call would silently carry `undefined`.
/// (It also makes `deviceId` true, which the TS type already claimed.)
#[derive(Serialize)]
#[serde(rename_all = "camelCase", rename_all_fields = "camelCase", tag = "status")]
pub enum EnrollResult {
    /// Fully enrolled: credential stored + first session exchanged.
    Enrolled { email: String, device_id: i64 },
    /// Login needs a 2FA code; complete with `temp_token`. STILL A GAP — no
    /// command completes this; the UI surfaces it as an error (#914).
    Requires2fa { temp_token: String },
    /// The profile holds active memberships in more than one tenant, so the
    /// server issued no session and wants a choice. The UI shows `memberships`
    /// and calls `auth_enroll_with_tenant` with the picked id + this token.
    ///
    /// The client NEVER picks for the operator — not even when `memberships`
    /// has one plausible-looking candidate among several. See
    /// `api::TenantMembership` for why auto-selection is the thing that was
    /// closed off.
    RequiresTenantSelection {
        selection_token: Option<String>,
        memberships: Vec<TenantMembership>,
    },
    /// The 300-second selection token lapsed (or the membership changed) before
    /// the choice arrived. RETRYABLE: the UI returns to the credentials step
    /// with a "sign in again" message rather than stranding the enrollment.
    SelectionLapsed,
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
        // Exactly one active membership: the server already resolved the tenant,
        // so there is nothing to record beyond what it minted.
        LoginOutcome::Session { access_token } => {
            finish_enroll(&app, &db, &auth, &cfg, &access_token, &device_name, &email, None)
        }
        LoginOutcome::Requires2fa { temp_token } => Ok(EnrollResult::Requires2fa { temp_token }),
        LoginOutcome::RequiresTenantSelection { selection_token, memberships } => {
            Ok(EnrollResult::RequiresTenantSelection { selection_token, memberships })
        }
    }
}

/// Complete a multi-membership enrollment (#914): exchange the operator's
/// CHOSEN tenant + the login step's selection token for a session, then run the
/// identical enroll tail as the single-tenant path — register the device, store
/// the credential in the keychain, exchange the first session, record
/// `auth_state`.
///
/// `email` is the address typed at the credentials step, used only as a
/// fallback: the server echoes the authoritative one alongside the session.
#[tauri::command]
#[allow(clippy::too_many_arguments)]
pub fn auth_enroll_with_tenant(
    app: AppHandle,
    db: State<'_, Db>,
    auth: State<'_, AuthManager>,
    selection_token: String,
    tenant_id: i64,
    device_name: String,
    email: String,
) -> Result<EnrollResult, String> {
    let cfg = auth.config();
    match api::select_tenant(auth.client(), &cfg, &selection_token, tenant_id) {
        Ok(session) => {
            let email = session.email.unwrap_or(email);
            finish_enroll(
                &app,
                &db,
                &auth,
                &cfg,
                &session.access_token,
                &device_name,
                &email,
                Some(tenant_id),
            )
        }
        // Not an Err: a lapse is a normal, retryable step of this flow, and
        // returning it as a RESULT keeps the UI's handling typed rather than
        // making it pattern-match an error string.
        Err(SelectTenantError::Lapsed) => Ok(EnrollResult::SelectionLapsed),
        Err(SelectTenantError::Failed(message)) => Err(message),
    }
}

/// The tail shared by both enrollment paths. `active_tenant_id` is `Some` only
/// when the operator explicitly chose one, so the stored `auth_state` records
/// WHICH tenant this device enrolled into; the single-membership path leaves it
/// `None` rather than inventing a tenant the server never named back.
#[allow(clippy::too_many_arguments)]
fn finish_enroll(
    app: &AppHandle,
    db: &State<'_, Db>,
    auth: &State<'_, AuthManager>,
    cfg: &crate::config::Config,
    access_token: &str,
    device_name: &str,
    email: &str,
    active_tenant_id: Option<i64>,
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
    auth_repo::set_enrolled(
        &conn,
        device.id,
        email,
        active_tenant_id,
        &device.expires_at,
        &cfg.backend_url,
    )
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
