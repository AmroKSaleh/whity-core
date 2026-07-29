//! Draft autosave commands (WC-desktop-sync) — thin wrappers over
//! `db::drafts_repo`. The frontend autosaves a form as it changes via
//! `save_draft`, rehydrates on reopen via `get_draft`, and clears it via
//! `discard_draft` (or lets a committing `save_item` supersede it).

use crate::db::drafts_repo::{self, Draft, DraftInput};
use crate::db::Db;
use tauri::State;

#[tauri::command]
pub fn save_draft(db: State<'_, Db>, draft: DraftInput) -> Result<Draft, String> {
    let conn = db.0.lock().map_err(|e| e.to_string())?;
    drafts_repo::upsert(&conn, &draft).map_err(|e| e.to_string())
}

#[tauri::command]
pub fn get_draft(db: State<'_, Db>, client_uuid: String) -> Result<Option<Draft>, String> {
    let conn = db.0.lock().map_err(|e| e.to_string())?;
    drafts_repo::get(&conn, &client_uuid).map_err(|e| e.to_string())
}

#[tauri::command]
pub fn discard_draft(db: State<'_, Db>, client_uuid: String) -> Result<(), String> {
    let conn = db.0.lock().map_err(|e| e.to_string())?;
    drafts_repo::discard(&conn, &client_uuid).map_err(|e| e.to_string())?;
    Ok(())
}
