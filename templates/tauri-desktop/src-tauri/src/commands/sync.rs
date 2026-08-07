//! Sync commands (WC-desktop-sync). Sync itself runs in the background loop
//! (`sync::scheduler`); these commands just nudge it and read local state for
//! the UI. `sync_now` fires a trigger and returns immediately — the cycle's
//! outcome arrives via `sync:status` events, not this call's return value.

use rusqlite::OptionalExtension;
use tauri::State;

use crate::db::conflicts_repo::{self, ConflictView};
use crate::db::Db;
use crate::sync::scheduler::{SyncHandle, Trigger};
use crate::sync::{SyncStatusView, DEMO_CATALOG_RESOURCE};

/// Ask the background loop to run a cycle now (non-blocking). The result is
/// emitted as a `sync:status` event; the loop, not this call, does the work.
#[tauri::command]
pub fn sync_now(scheduler: State<'_, SyncHandle>) -> Result<(), String> {
    scheduler.trigger(Trigger::Manual);
    Ok(())
}

/// A point-in-time read of local sync state for the initial snapshot / a
/// backstop poll (the `sync:status` event carries the same fields live).
#[tauri::command]
pub fn get_sync_status(db: State<'_, Db>) -> Result<SyncStatusView, String> {
    let conn = db.0.lock().map_err(|e| e.to_string())?;

    let unsynced: i64 = conn
        .query_row(
            "SELECT COUNT(*) FROM demo_catalog_items WHERE sync_state <> 'synced'",
            [],
            |r| r.get(0),
        )
        .map_err(|e| e.to_string())?;
    let conflicts: i64 = conn
        .query_row(
            "SELECT COUNT(*) FROM demo_catalog_items WHERE sync_state = 'conflict'",
            [],
            |r| r.get(0),
        )
        .map_err(|e| e.to_string())?;
    let stamps: Option<(Option<String>, Option<String>)> = conn
        .query_row(
            "SELECT last_pull_at, last_push_at FROM sync_state_kv WHERE resource = ?1",
            [DEMO_CATALOG_RESOURCE],
            |r| Ok((r.get(0)?, r.get(1)?)),
        )
        .optional()
        .map_err(|e| e.to_string())?;
    let (last_pull_at, last_push_at) = stamps.unwrap_or((None, None));

    Ok(SyncStatusView {
        unsynced_count: unsynced as usize,
        conflict_count: conflicts as usize,
        last_pull_at,
        last_push_at,
    })
}

/// List the parked conflicts for the resolver UI (shared `Conflict` shape).
#[tauri::command]
pub fn list_conflicts(db: State<'_, Db>) -> Result<Vec<ConflictView>, String> {
    let conn = db.0.lock().map_err(|e| e.to_string())?;
    conflicts_repo::list(&conn).map_err(|e| e.to_string())
}

/// Apply a resolver decision: the frontend sends the resolved concrete values
/// (per-field mine/theirs/custom already collapsed), which rebase the row onto
/// the server version and re-queue it. Nudges the loop to push the merged result.
#[tauri::command]
pub fn resolve_conflict(
    db: State<'_, Db>,
    scheduler: State<'_, SyncHandle>,
    client_uuid: String,
    name: String,
    description: Option<String>,
    status: String,
) -> Result<(), String> {
    {
        let conn = db.0.lock().map_err(|e| e.to_string())?;
        let resolved =
            conflicts_repo::resolve(&conn, &client_uuid, &name, description.as_deref(), &status)
                .map_err(|e| e.to_string())?;
        if !resolved {
            return Err("conflict not found".to_string());
        }
    }
    scheduler.trigger(Trigger::LocalWrite);
    Ok(())
}
