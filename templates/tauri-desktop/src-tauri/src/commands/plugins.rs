//! Frontend-facing commands for the plugin catalog (WC-plugin-sync): a
//! read-only snapshot of the last automatic sync pass (`plugin_sync_status`),
//! and an on-demand re-run of that same reconcile pass
//! (`reconcile_plugins_now`) so the Plugin store screen can drive install/
//! update without waiting for the next login. The catalog LISTING the store
//! shows is fetched from the frontend over `remote_request`
//! (`GET /api/v1/desktop-plugins`); the actual download/verify/install still
//! goes exclusively through `plugins::reconcile` — the server's catalog stays
//! the single source of truth for what a device runs.

use tauri::{AppHandle, State};

use crate::auth::AuthManager;
use crate::commands::post_login;
use crate::db::{plugin_sync_repo::PluginSyncStatus, Db};

#[tauri::command]
pub fn plugin_sync_status(db: State<'_, Db>) -> Result<PluginSyncStatus, String> {
    let conn = db.0.lock().map_err(|e| format!("database busy: {e}"))?;
    crate::db::plugin_sync_repo::status(&conn).map_err(|e| e.to_string())
}

/// Manually re-run the post-login reconcile pass (WC-plugin-sync): converge
/// this device's installed plugins to the backend catalog, restarting
/// FrankenPHP only if something changed. Reuses the EXACT same
/// `post_login::spawn_after_login` machinery a login fires (self-update check
/// → reconcile → conditional restart → `plugin-sync:status` events), so the
/// Plugin store's progress/outcome UI is identical to the login-time one and
/// there is only ever one reconcile code path. Fire-and-forget: it returns as
/// soon as the background pass is spawned; the frontend tracks progress via the
/// `plugin-sync:status` event it already listens to.
///
/// Errors only on the local precondition (device not enrolled / credential
/// exchange failed) — a caller with no valid device session can't reconcile.
#[tauri::command]
pub fn reconcile_plugins_now(app: AppHandle, auth: State<'_, AuthManager>) -> Result<(), String> {
    let token = match auth.access_token() {
        Some(t) => t,
        None => auth.refresh_access()?,
    };
    post_login::spawn_after_login(app, &auth, token);
    Ok(())
}
