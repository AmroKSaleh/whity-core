//! Frontend-facing command for the plugin-sync status view (WC-plugin-sync).
//! Plugin installation itself is no longer manual/opt-in — see
//! `commands::post_login` and `plugins::reconcile` — so this file exposes
//! only a read-only snapshot of the last automatic sync pass.

use tauri::State;

use crate::db::{plugin_sync_repo::PluginSyncStatus, Db};

#[tauri::command]
pub fn plugin_sync_status(db: State<'_, Db>) -> Result<PluginSyncStatus, String> {
    let conn = db.0.lock().map_err(|e| format!("database busy: {e}"))?;
    crate::db::plugin_sync_repo::status(&conn).map_err(|e| e.to_string())
}
