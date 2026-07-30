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

pub(crate) mod migrations;
pub mod auth_repo;
pub mod conflicts_repo;
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
    let conn = open_at(&db_path(app_handle))?;
    migrations::run(&conn)?;
    Ok(conn)
}

/// Open a SECOND connection to the same database for the background sync loop
/// (`sync::scheduler`). WAL mode (set below) lets this connection write while the
/// UI's `Db` connection keeps serving reads without blocking — so a background
/// sync cycle never freezes the UI, which is exactly why the engine can keep
/// holding its own connection across network I/O. Migrations are NOT re-run here:
/// the primary `open()` already applied them at startup.
pub fn open_sync_connection(app_handle: &AppHandle) -> rusqlite::Result<Connection> {
    open_at(&db_path(app_handle))
}

/// Resolve (creating the parent dir) the database file path in the OS app-data dir.
fn db_path(app_handle: &AppHandle) -> std::path::PathBuf {
    let app_dir = app_handle
        .path()
        .app_data_dir()
        .expect("failed to resolve the app data directory");
    fs::create_dir_all(&app_dir).expect("failed to create the app data directory");
    app_dir.join("whity-desktop.sqlite")
}

/// Open a connection with the shared pragmas (no migrations).
fn open_at(path: &std::path::Path) -> rusqlite::Result<Connection> {
    let conn = Connection::open(path)?;
    // WAL + busy timeout so the UI connection and the background sync connection
    // don't trip over each other; foreign keys on for the aux sync tables.
    // execute_batch tolerates the row `journal_mode` returns.
    conn.execute_batch(
        "PRAGMA journal_mode=WAL;
         PRAGMA busy_timeout=5000;
         PRAGMA foreign_keys=ON;",
    )?;
    Ok(conn)
}
