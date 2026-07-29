//! Versioned local-SQLite schema migrations, gated by `PRAGMA user_version`.
//!
//! - v1 (implicit, user_version 0): the original `demo_catalog_items` table
//!   (id / name / description / status / created_at / updated_at) — no sync
//!   metadata. This is what shipped before the offline-sync layer.
//! - v2: adds the sync-identity columns every syncable table adopts, so a row
//!   can be tracked, versioned and reconciled against the server: `client_uuid`
//!   (stable id that survives before a server id exists), `server_id`,
//!   `base_version`, `sync_state`, `dirty`, `deleted` (tombstone),
//!   `updated_at_local`, `updated_by`. Existing v1 rows are rebuilt with a
//!   generated `client_uuid` and left `pending`/`dirty` so they push as creates
//!   on the first sync.
//! - v3: the mid-edit DRAFT store (`item_drafts`) — cheap autosave rows distinct
//!   from committed records; never synced.
//! - v4: the singleton `auth_state` row — non-secret device-enrollment +
//!   session bookkeeping (the secret credential lives in the OS keychain).
//!
//! `run()` applies each pending step then stamps its version, so a partially
//! migrated DB always resumes correctly. Add later steps as another
//! `if version < N { migrate_to_vN(conn)?; stamp(N) }` block.

use rusqlite::{params, Connection};
use uuid::Uuid;

/// Apply any pending migrations, stamping `user_version` after each step.
/// Idempotent.
pub fn run(conn: &Connection) -> rusqlite::Result<()> {
    let mut version: i64 = conn.query_row("PRAGMA user_version", [], |r| r.get(0))?;
    if version < 2 {
        migrate_to_v2(conn)?;
        conn.pragma_update(None, "user_version", 2)?;
        version = 2;
    }
    if version < 3 {
        migrate_to_v3(conn)?;
        conn.pragma_update(None, "user_version", 3)?;
        version = 3;
    }
    if version < 4 {
        migrate_to_v4(conn)?;
        conn.pragma_update(None, "user_version", 4)?;
    }
    Ok(())
}

/// The v2 shape of `demo_catalog_items`. The frontend contract
/// (`DemoCatalogItem`) still sees only id/name/description/status/created_at/
/// updated_at; the rest is internal sync bookkeeping.
const V2_ITEMS: &str = "
    CREATE TABLE demo_catalog_items (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        client_uuid       TEXT    NOT NULL UNIQUE,
        server_id         INTEGER,
        name              TEXT    NOT NULL,
        description       TEXT,
        status            TEXT    NOT NULL DEFAULT 'active',
        base_version      INTEGER NOT NULL DEFAULT 0,
        sync_state        TEXT    NOT NULL DEFAULT 'pending',
        dirty             INTEGER NOT NULL DEFAULT 1,
        deleted           INTEGER NOT NULL DEFAULT 0,
        created_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
        updated_at        TEXT,
        updated_at_local  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
        updated_by        TEXT
    )";

/// The mid-edit draft store: cheap autosave rows keyed by the item's
/// `client_uuid` (or a fresh uuid for a brand-new item), with `base_local_id`
/// pointing at the committed row being edited (NULL for a new-item draft).
/// Never synced; discarded on commit.
const V3_DRAFTS: &str = "
    CREATE TABLE item_drafts (
        client_uuid   TEXT PRIMARY KEY,
        base_local_id INTEGER,
        name          TEXT,
        description   TEXT,
        status        TEXT,
        updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
    )";

/// The singleton auth/enrollment row. NON-secret: the device credential itself
/// lives in the OS keychain, never here. `last_online_auth_at` (epoch seconds)
/// + `max_login_seconds` (the server-echoed TTL) drive the offline lock.
const V4_AUTH_STATE: &str = "
    CREATE TABLE auth_state (
        id                    INTEGER PRIMARY KEY CHECK (id = 1),
        enrolled              INTEGER NOT NULL DEFAULT 0,
        device_id             INTEGER,
        email                 TEXT,
        active_tenant_id      INTEGER,
        credential_expires_at TEXT,
        last_online_auth_at   INTEGER,
        max_login_seconds     INTEGER
    )";

fn migrate_to_v2(conn: &Connection) -> rusqlite::Result<()> {
    let has_table = table_exists(conn, "demo_catalog_items")?;
    let is_v1 = has_table && !column_exists(conn, "demo_catalog_items", "client_uuid")?;

    if is_v1 {
        // Rebuild (SQLite ALTER can't add UNIQUE/NOT-NULL columns or backfill a
        // per-row uuid): rename old, create v2, copy rows with a generated uuid.
        conn.execute_batch("ALTER TABLE demo_catalog_items RENAME TO demo_catalog_items_v1;")?;
        conn.execute_batch(V2_ITEMS)?;

        let mut stmt = conn.prepare(
            "SELECT id, name, description, status, created_at, updated_at
             FROM demo_catalog_items_v1 ORDER BY id",
        )?;
        #[allow(clippy::type_complexity)]
        let rows: Vec<(i64, String, Option<String>, String, String, Option<String>)> = stmt
            .query_map([], |r| {
                Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?, r.get(4)?, r.get(5)?))
            })?
            .collect::<Result<Vec<_>, _>>()?;
        drop(stmt);

        for (id, name, description, status, created_at, updated_at) in rows {
            // A migrated row has no server copy yet → its local edit clock seeds
            // from the old updated_at (else created_at); it syncs as a create.
            let local = updated_at.clone().unwrap_or_else(|| created_at.clone());
            conn.execute(
                "INSERT INTO demo_catalog_items
                   (id, client_uuid, server_id, name, description, status,
                    base_version, sync_state, dirty, deleted,
                    created_at, updated_at, updated_at_local, updated_by)
                 VALUES (?1, ?2, NULL, ?3, ?4, ?5, 0, 'pending', 1, 0, ?6, ?7, ?8, NULL)",
                params![
                    id,
                    Uuid::new_v4().to_string(),
                    name,
                    description,
                    status,
                    created_at,
                    updated_at,
                    local,
                ],
            )?;
        }
        conn.execute_batch("DROP TABLE demo_catalog_items_v1;")?;
    } else if !has_table {
        conn.execute_batch(V2_ITEMS)?;
    }
    // else: table already v2-shaped (has client_uuid) though user_version was
    // < 2 — nothing structural to do; just create the indexes below.

    conn.execute_batch(
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_demo_catalog_items_server_id
             ON demo_catalog_items(server_id) WHERE server_id IS NOT NULL;
         CREATE INDEX IF NOT EXISTS idx_demo_catalog_items_pending
             ON demo_catalog_items(sync_state) WHERE sync_state <> 'synced';",
    )?;
    Ok(())
}

fn migrate_to_v3(conn: &Connection) -> rusqlite::Result<()> {
    conn.execute_batch(V3_DRAFTS)?;
    Ok(())
}

fn migrate_to_v4(conn: &Connection) -> rusqlite::Result<()> {
    conn.execute_batch(V4_AUTH_STATE)?;
    conn.execute("INSERT INTO auth_state (id, enrolled) VALUES (1, 0)", [])?;
    Ok(())
}

fn table_exists(conn: &Connection, name: &str) -> rusqlite::Result<bool> {
    let count: i64 = conn.query_row(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?1",
        [name],
        |r| r.get(0),
    )?;
    Ok(count > 0)
}

fn column_exists(conn: &Connection, table: &str, column: &str) -> rusqlite::Result<bool> {
    // `table` is a fixed internal identifier (never user input), so the inlined
    // PRAGMA argument is safe.
    let mut stmt = conn.prepare(&format!("PRAGMA table_info({table})"))?;
    let names: Vec<String> = stmt
        .query_map([], |r| r.get::<_, String>(1))?
        .collect::<Result<Vec<_>, _>>()?;
    Ok(names.iter().any(|n| n == column))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn v1_conn() -> Connection {
        let conn = Connection::open_in_memory().unwrap();
        conn.execute_batch(
            "CREATE TABLE demo_catalog_items (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL,
                description TEXT,
                status      TEXT NOT NULL DEFAULT 'active',
                created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
                updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
            );",
        )
        .unwrap();
        conn
    }

    #[test]
    fn migrates_v1_rows_preserving_id_and_generating_uuid() {
        let conn = v1_conn();
        conn.execute(
            "INSERT INTO demo_catalog_items (name, description, status) VALUES ('A','d','active')",
            [],
        )
        .unwrap();
        conn.execute(
            "INSERT INTO demo_catalog_items (name, status) VALUES ('B','archived')",
            [],
        )
        .unwrap();

        run(&conn).unwrap();

        let ver: i64 = conn.query_row("PRAGMA user_version", [], |r| r.get(0)).unwrap();
        assert_eq!(ver, 4);

        let (id, uuid, sync_state, dirty, deleted): (i64, String, String, i64, i64) = conn
            .query_row(
                "SELECT id, client_uuid, sync_state, dirty, deleted
                 FROM demo_catalog_items WHERE name='A'",
                [],
                |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?, r.get(4)?)),
            )
            .unwrap();
        assert_eq!(id, 1, "original id preserved");
        assert_eq!(sync_state, "pending");
        assert_eq!(dirty, 1);
        assert_eq!(deleted, 0);
        assert_eq!(uuid.len(), 36, "a v4 uuid was generated");

        let distinct: i64 = conn
            .query_row(
                "SELECT COUNT(DISTINCT client_uuid) FROM demo_catalog_items",
                [],
                |r| r.get(0),
            )
            .unwrap();
        assert_eq!(distinct, 2, "each migrated row gets its own uuid");
    }

    #[test]
    fn fresh_install_creates_latest_schema() {
        let conn = Connection::open_in_memory().unwrap();
        run(&conn).unwrap();

        let ver: i64 = conn.query_row("PRAGMA user_version", [], |r| r.get(0)).unwrap();
        assert_eq!(ver, 4);
        assert!(column_exists(&conn, "demo_catalog_items", "client_uuid").unwrap());
        assert!(table_exists(&conn, "item_drafts").unwrap());
        assert!(table_exists(&conn, "auth_state").unwrap());

        // The auth_state singleton exists and starts un-enrolled.
        let (id, enrolled): (i64, i64) = conn
            .query_row("SELECT id, enrolled FROM auth_state", [], |r| {
                Ok((r.get(0)?, r.get(1)?))
            })
            .unwrap();
        assert_eq!(id, 1);
        assert_eq!(enrolled, 0);

        conn.execute(
            "INSERT INTO demo_catalog_items (client_uuid, name) VALUES ('u1','X')",
            [],
        )
        .unwrap();
        let (ss, dirty): (String, i64) = conn
            .query_row(
                "SELECT sync_state, dirty FROM demo_catalog_items WHERE name='X'",
                [],
                |r| Ok((r.get(0)?, r.get(1)?)),
            )
            .unwrap();
        assert_eq!(ss, "pending");
        assert_eq!(dirty, 1);
    }

    #[test]
    fn upgrades_a_v2_db_to_latest() {
        // Simulate a DB already at v2 (items present, no drafts/auth tables).
        let conn = Connection::open_in_memory().unwrap();
        conn.execute_batch(V2_ITEMS).unwrap();
        conn.pragma_update(None, "user_version", 2).unwrap();
        assert!(!table_exists(&conn, "item_drafts").unwrap());
        assert!(!table_exists(&conn, "auth_state").unwrap());

        run(&conn).unwrap();

        let ver: i64 = conn.query_row("PRAGMA user_version", [], |r| r.get(0)).unwrap();
        assert_eq!(ver, 4);
        assert!(table_exists(&conn, "item_drafts").unwrap());
        assert!(table_exists(&conn, "auth_state").unwrap());
    }

    #[test]
    fn run_is_idempotent() {
        let conn = v1_conn();
        run(&conn).unwrap();
        run(&conn).unwrap(); // version already 4 → no-op
        let ver: i64 = conn.query_row("PRAGMA user_version", [], |r| r.get(0)).unwrap();
        assert_eq!(ver, 4);
    }
}
