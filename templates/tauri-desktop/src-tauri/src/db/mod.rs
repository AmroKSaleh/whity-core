//! Local SQLite storage for the DemoCatalog pilot feature.
//!
//! `db.rs` grew into this `db/` module as the template gained its offline-first
//! sync layer (WC-desktop-sync): `mod` owns opening the connection + the managed
//! handle; `migrations` owns the versioned schema. The DemoCatalog table now
//! carries the sync-metadata columns every syncable table adopts (`client_uuid`
//! / `server_id` / `base_version` / `sync_state` / `dirty` / `deleted` /
//! `updated_at_local`) — the reusable pattern this template demonstrates. Reads
//! and writes still go through the same commands (see `commands/items.rs`), and
//! nothing here reaches the network — a later PR adds the sync engine that acts
//! on this metadata.

mod migrations;
pub mod auth_repo;
pub mod drafts_repo;
pub mod items_repo;

use rusqlite::Connection;
use std::fs;
use std::sync::Mutex;
use tauri::{AppHandle, Manager};

/// Shared, mutex-guarded connection handle managed by Tauri (see lib.rs's
/// `.manage(...)`), injected into commands via `State<'_, Db>`.
///
/// SCALING NOTE: one `Mutex<Connection>` serializes every read AND write. The
/// connection is opened in WAL mode below so a future move to a small pool
/// (e.g. `r2d2_sqlite`) lets readers stop blocking on the writer — the first
/// thing to revisit once the background sync connection and the UI contend.
pub struct Db(pub Mutex<Connection>);

/// Open (creating if needed) the app's SQLite database in the OS app-data
/// directory, set pragmas, and apply schema migrations. Idempotent: safe to
/// call on every launch.
pub fn open(app_handle: &AppHandle) -> rusqlite::Result<Connection> {
    let app_dir = app_handle
        .path()
        .app_data_dir()
        .expect("failed to resolve the app data directory");
    fs::create_dir_all(&app_dir).expect("failed to create the app data directory");

    let db_path = app_dir.join("whity-desktop.sqlite");
    let conn = Connection::open(db_path)?;

    // WAL + busy timeout so the UI connection and the (future) background sync
    // connection don't trip over each other; foreign keys on for the aux tables
    // later sync PRs add. execute_batch tolerates the row `journal_mode` returns.
    conn.execute_batch(
        "PRAGMA journal_mode=WAL;
         PRAGMA busy_timeout=5000;
         PRAGMA foreign_keys=ON;",
    )?;

    migrations::run(&conn)?;
    Ok(conn)
}
