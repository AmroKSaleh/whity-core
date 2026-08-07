//! Tauri commands backing the DemoCatalog pilot feature's `DemoCatalogAdapter`
//! (see whity-core's packages/features/src/demo-catalog/types.ts). The frontend
//! adapter (src/demo-catalog-tauri-adapter.ts) calls these commands 1:1 —
//! `list`/`get`/`save` on the TS side, `list_items`/`get_item`/`save_item` here.
//!
//! Local-first (WC-desktop-sync): every write lands in local SQLite immediately
//! and is marked `dirty`/`pending`, and each row carries a stable `client_uuid`.
//! The frontend contract (`DemoCatalogItem`) is unchanged — the sync bookkeeping
//! columns (client_uuid/server_id/base_version/sync_state/dirty/deleted) stay
//! internal and never cross the IPC boundary. A later PR adds the sync engine
//! that pushes `dirty` rows and reconciles server changes; until then this is a
//! purely local store (no network) — but now with the metadata sync needs.

use crate::db::Db;
use crate::sync::scheduler::{SyncHandle, Trigger};
use rusqlite::{params, OptionalExtension};
use serde::{Deserialize, Serialize};
use tauri::State;
use uuid::Uuid;

/// Mirrors `DemoCatalogItem` in packages/features/src/demo-catalog/types.ts
/// field-for-field (camelCase over the wire — Tauri's IPC serializes this
/// exactly as the frontend expects). `updated_at` is the server timestamp once
/// known, falling back to the local edit clock so the UI always shows a time
/// (see the COALESCE in the queries).
#[derive(Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct DemoCatalogItem {
    pub id: i64,
    pub name: String,
    pub description: Option<String>,
    pub status: String,
    pub created_at: Option<String>,
    pub updated_at: Option<String>,
}

/// Mirrors `DemoCatalogItemInput` — what `save()` sends: `id` present means
/// "update this row", absent means "create".
#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct DemoCatalogItemInput {
    pub id: Option<i64>,
    pub name: String,
    pub description: Option<String>,
    pub status: Option<String>,
}

/// Column list projecting a row to the frontend shape. `updated_at` coalesces
/// the (nullable) server timestamp onto the always-present local clock.
const SELECT_COLS: &str = "id, name, description, status, created_at,
    COALESCE(updated_at, updated_at_local) AS updated_at";

fn row_to_item(row: &rusqlite::Row) -> rusqlite::Result<DemoCatalogItem> {
    Ok(DemoCatalogItem {
        id: row.get("id")?,
        name: row.get("name")?,
        description: row.get("description")?,
        status: row.get("status")?,
        created_at: row.get("created_at")?,
        updated_at: row.get("updated_at")?,
    })
}

#[tauri::command]
pub fn list_items(db: State<'_, Db>) -> Result<Vec<DemoCatalogItem>, String> {
    let conn = db.0.lock().map_err(|e| e.to_string())?;
    let mut stmt = conn
        .prepare(&format!(
            "SELECT {SELECT_COLS}
             FROM demo_catalog_items
             WHERE deleted = 0
             ORDER BY created_at DESC, id DESC"
        ))
        .map_err(|e| e.to_string())?;

    let items = stmt
        .query_map([], row_to_item)
        .map_err(|e| e.to_string())?
        .collect::<Result<Vec<_>, _>>()
        .map_err(|e| e.to_string())?;

    Ok(items)
}

#[tauri::command]
pub fn get_item(db: State<'_, Db>, id: i64) -> Result<Option<DemoCatalogItem>, String> {
    let conn = db.0.lock().map_err(|e| e.to_string())?;
    conn.query_row(
        &format!("SELECT {SELECT_COLS} FROM demo_catalog_items WHERE id = ?1 AND deleted = 0"),
        params![id],
        row_to_item,
    )
    .optional()
    .map_err(|e| e.to_string())
}

#[tauri::command]
pub fn save_item(
    db: State<'_, Db>,
    scheduler: State<'_, SyncHandle>,
    input: DemoCatalogItemInput,
) -> Result<DemoCatalogItem, String> {
    if input.name.trim().is_empty() {
        return Err("name must not be empty".to_string());
    }

    let conn = db.0.lock().map_err(|e| e.to_string())?;
    let status = input.status.unwrap_or_else(|| "active".to_string());
    let now = "strftime('%Y-%m-%dT%H:%M:%fZ', 'now')";

    let id = match input.id {
        // UPDATE: bump the local edit clock and re-mark the row dirty. A row that
        // was already 'synced' becomes 'pending' again so the sync engine repushes
        // it; a row still 'pending'/'conflict' keeps that state.
        Some(existing_id) => {
            let changed = conn
                .execute(
                    &format!(
                        "UPDATE demo_catalog_items
                         SET name = ?1, description = ?2, status = ?3,
                             updated_at_local = {now}, dirty = 1,
                             sync_state = CASE WHEN sync_state = 'synced' THEN 'pending' ELSE sync_state END
                         WHERE id = ?4 AND deleted = 0"
                    ),
                    params![input.name, input.description, status, existing_id],
                )
                .map_err(|e| e.to_string())?;
            if changed == 0 {
                return Err("item not found".to_string());
            }
            existing_id
        }
        // CREATE: mint a stable client_uuid; the row starts dirty/pending with no
        // server_id until its first successful sync.
        None => {
            conn.execute(
                &format!(
                    "INSERT INTO demo_catalog_items
                       (client_uuid, name, description, status, created_at, updated_at_local, dirty, sync_state)
                     VALUES (?1, ?2, ?3, ?4, {now}, {now}, 1, 'pending')"
                ),
                params![Uuid::new_v4().to_string(), input.name, input.description, status],
            )
            .map_err(|e| e.to_string())?;
            conn.last_insert_rowid()
        }
    };

    let item = conn
        .query_row(
            &format!("SELECT {SELECT_COLS} FROM demo_catalog_items WHERE id = ?1"),
            params![id],
            row_to_item,
        )
        .map_err(|e| e.to_string())?;
    drop(conn);

    // Nudge the background loop to push this write (debounced; no-op if offline).
    scheduler.trigger(Trigger::LocalWrite);
    Ok(item)
}

/// Soft-delete an item (WC-desktop-sync): it vanishes from the UI immediately
/// and is staged for the sync engine to propagate as a server tombstone. Not
/// part of the shared `DemoCatalogAdapter` contract (which is list/get/save) —
/// a template-local capability the desktop UI/sync layer wire up.
#[tauri::command]
pub fn delete_item(
    db: State<'_, Db>,
    scheduler: State<'_, SyncHandle>,
    id: i64,
) -> Result<(), String> {
    {
        let conn = db.0.lock().map_err(|e| e.to_string())?;
        let existed = crate::db::items_repo::soft_delete(&conn, id).map_err(|e| e.to_string())?;
        if !existed {
            return Err("item not found".to_string());
        }
    }
    // Nudge the background loop to propagate the tombstone (debounced).
    scheduler.trigger(Trigger::LocalWrite);
    Ok(())
}
