//! Sync commands (WC-desktop-sync). `sync_now` runs one push+pull cycle against
//! the server; `get_sync_status` reports local counts for the UI.
//!
//! NOTE: `sync_now` holds the DB mutex for the whole cycle (network included).
//! Fine for an explicit, user-triggered sync; the background scheduler (a later
//! hardening PR) will read a batch, release the lock across network I/O, then
//! re-lock to apply — so UI reads never block on a long sync.

use rusqlite::OptionalExtension;
use tauri::State;

use crate::auth::AuthManager;
use crate::db::Db;
use crate::sync::{engine, SyncStatusView, SyncSummary, DEMO_CATALOG_RESOURCE};

#[tauri::command]
pub fn sync_now(db: State<'_, Db>, auth: State<'_, AuthManager>) -> Result<SyncSummary, String> {
    // A fresh access token (this also re-stamps the offline-auth clock).
    let session = auth.exchange_session()?;
    let api_base = auth.cfg.api_base();

    let conn = db.0.lock().map_err(|e| e.to_string())?;
    engine::sync_cycle(&conn, auth.client(), &api_base, &session.access_token)
}

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
