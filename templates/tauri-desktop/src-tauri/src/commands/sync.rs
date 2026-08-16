//! Sync commands (WC-desktop-sync). Sync itself runs in the background loop
//! (`sync::scheduler`); these commands just nudge it and read local state for
//! the UI. `sync_now` fires a trigger and returns immediately — the cycle's
//! outcome arrives via `sync:status` events, not this call's return value.
//! Generalized (WC-sync-generalize) across every resource in
//! `sync::resource::RESOURCES` instead of hardcoded to DemoCatalog.

use tauri::State;

use crate::db::conflicts_repo::{self, ConflictView};
use crate::db::Db;
use crate::sync::engine;
use crate::sync::resource;
use crate::sync::scheduler::{SyncHandle, Trigger};
use crate::sync::SyncStatusView;

/// Ask the background loop to run a cycle now (non-blocking). The result is
/// emitted as a `sync:status` event; the loop, not this call, does the work.
#[tauri::command]
pub fn sync_now(scheduler: State<'_, SyncHandle>) -> Result<(), String> {
    scheduler.trigger(Trigger::Manual);
    Ok(())
}

/// A point-in-time read of local sync state for the initial snapshot / a
/// backstop poll (the `sync:status` event carries the same fields live),
/// summed across every resource.
#[tauri::command]
pub fn get_sync_status(db: State<'_, Db>) -> Result<SyncStatusView, String> {
    let conn = db.0.lock().map_err(|e| e.to_string())?;
    engine::read_status(&conn, resource::RESOURCES)
}

/// List the parked conflicts for the resolver UI (shared `Conflict` shape),
/// across every resource.
#[tauri::command]
pub fn list_conflicts(db: State<'_, Db>) -> Result<Vec<ConflictView>, String> {
    let conn = db.0.lock().map_err(|e| e.to_string())?;
    conflicts_repo::list(&conn, resource::RESOURCES).map_err(|e| e.to_string())
}

/// Apply a resolver decision: the frontend sends the resolved concrete values
/// as a generic field map (per-field mine/theirs/custom already collapsed),
/// which rebase the row onto the server version and re-queue it. Nudges the
/// loop to push the merged result. `resource` selects which
/// `ResourceDescriptor` (and therefore which local table) `client_uuid`
/// belongs to.
#[tauri::command]
pub fn resolve_conflict(
    db: State<'_, Db>,
    scheduler: State<'_, SyncHandle>,
    resource: String,
    client_uuid: String,
    fields: serde_json::Map<String, serde_json::Value>,
) -> Result<(), String> {
    let descriptor = crate::sync::resource::find(&resource).ok_or_else(|| format!("unknown sync resource: {resource}"))?;
    {
        let conn = db.0.lock().map_err(|e| e.to_string())?;
        let resolved = conflicts_repo::resolve(&conn, descriptor, &client_uuid, &fields).map_err(|e| e.to_string())?;
        if !resolved {
            return Err("conflict not found".to_string());
        }
    }
    scheduler.trigger(Trigger::LocalWrite);
    Ok(())
}
