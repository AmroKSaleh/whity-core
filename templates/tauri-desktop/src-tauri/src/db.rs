//! Local SQLite storage for the DemoCatalog pilot feature.
//!
//! This is the desktop half of the multi-client feature-extraction pilot
//! (see whity-core's packages/features): the SAME `DemoCatalogList`/
//! `DemoCatalogDetail` components web/ renders against a server API render
//! here, unmodified, against a real local SQLite database file living in the
//! OS's per-app data directory — no server, no Node, offline-first.

use rusqlite::Connection;
use std::fs;
use tauri::{AppHandle, Manager};

/// Open (creating if needed) the app's SQLite database in the OS app-data
/// directory, and apply the schema migration. Idempotent: safe to call on
/// every launch.
pub fn init_db(app_handle: &AppHandle) -> rusqlite::Result<Connection> {
    let app_dir = app_handle
        .path()
        .app_data_dir()
        .expect("failed to resolve the app data directory");

    fs::create_dir_all(&app_dir).expect("failed to create the app data directory");

    let db_path = app_dir.join("whity-desktop.sqlite");
    let conn = Connection::open(db_path)?;

    conn.execute(
        "CREATE TABLE IF NOT EXISTS demo_catalog_items (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT NOT NULL,
            description TEXT,
            status      TEXT NOT NULL DEFAULT 'active',
            created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
            updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
        )",
        (),
    )?;

    Ok(conn)
}
