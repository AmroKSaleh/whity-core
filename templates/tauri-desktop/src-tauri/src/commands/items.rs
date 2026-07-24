//! Tauri commands backing the DemoCatalog pilot feature's `DemoCatalogAdapter`
//! (see whity-core's packages/features/src/demo-catalog/types.ts). The
//! frontend adapter (src/demo-catalog-tauri-adapter.ts) calls these three
//! commands 1:1 — `list`/`get`/`save` on the TS side, `list_items`/
//! `get_item`/`save_item` here.

use rusqlite::{params, Connection, OptionalExtension};
use serde::{Deserialize, Serialize};
use std::sync::Mutex;
use tauri::State;

/// Shared, mutex-guarded connection handle managed by Tauri (see lib.rs's
/// `.manage(...)` call) and injected into every command via `State<'_, Db>`.
pub struct Db(pub Mutex<Connection>);

/// Mirrors `DemoCatalogItem` in packages/features/src/demo-catalog/types.ts
/// field-for-field (camelCase over the wire — Tauri's IPC serializes this
/// exactly as the frontend expects, no manual mapping needed).
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
        .prepare(
            "SELECT id, name, description, status, created_at, updated_at
             FROM demo_catalog_items
             ORDER BY created_at DESC, id DESC",
        )
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
        "SELECT id, name, description, status, created_at, updated_at
         FROM demo_catalog_items WHERE id = ?1",
        params![id],
        row_to_item,
    )
    .optional()
    .map_err(|e| e.to_string())
}

#[tauri::command]
pub fn save_item(
    db: State<'_, Db>,
    input: DemoCatalogItemInput,
) -> Result<DemoCatalogItem, String> {
    if input.name.trim().is_empty() {
        return Err("name must not be empty".to_string());
    }

    let conn = db.0.lock().map_err(|e| e.to_string())?;
    let status = input.status.unwrap_or_else(|| "active".to_string());
    let now = "strftime('%Y-%m-%dT%H:%M:%fZ', 'now')";

    let id = match input.id {
        Some(existing_id) => {
            conn.execute(
                &format!(
                    "UPDATE demo_catalog_items
                     SET name = ?1, description = ?2, status = ?3, updated_at = {now}
                     WHERE id = ?4"
                ),
                params![input.name, input.description, status, existing_id],
            )
            .map_err(|e| e.to_string())?;
            existing_id
        }
        None => {
            conn.execute(
                &format!(
                    "INSERT INTO demo_catalog_items (name, description, status, created_at, updated_at)
                     VALUES (?1, ?2, ?3, {now}, {now})"
                ),
                params![input.name, input.description, status],
            )
            .map_err(|e| e.to_string())?;
            conn.last_insert_rowid()
        }
    };

    conn.query_row(
        "SELECT id, name, description, status, created_at, updated_at
         FROM demo_catalog_items WHERE id = ?1",
        params![id],
        row_to_item,
    )
    .map_err(|e| e.to_string())
}
