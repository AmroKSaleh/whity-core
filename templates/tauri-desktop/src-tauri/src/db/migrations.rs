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
//! - v5: the sync engine's pull-cursor store (`sync_state_kv`) and field-level
//!   conflict snapshot store (`item_conflicts`).
//! - v6: per-row push-retry bookkeeping (`push_attempts` / `next_attempt_at` /
//!   `last_push_error`) so a flaky push backs off.
//! - v7: `auth_state.server_url` — records which backend this device
//!   enrolled against, for informational display only (the account footer
//!   shows it); the backend itself is fixed for the whole build (see
//!   `config.rs`), never chosen per device. Lives on `auth_state` (not a
//!   separate table) because it's a device-level fact, not a per-session
//!   one — logout deliberately does NOT clear it (see `auth_repo::clear()`).
//! - v8: the singleton `plugin_sync_state` row (WC-plugin-sync) — bookkeeping
//!   for the last automatic plugin reconcile pass (see `plugins::reconcile`),
//!   so the UI can show sync status without needing a fresh sync to answer it.
//! - v9 (WC-sync-generalize): `item_conflicts` gains a `resource` column
//!   (composite PK `(resource, client_uuid)`) — the sync engine now serves
//!   more than one resource (see `sync::resource::ResourceDescriptor`), and a
//!   bare `client_uuid` primary key would let two resources' conflicts
//!   collide. Existing rows backfill as `'demo-catalog/items'`, the only
//!   resource that existed before this version.
//! - v10 (WC-plugin-data-bridge): `bridge_resource_state` (per
//!   `(resource, client_uuid)`, the last-known id/version on each side of a
//!   `sync::bridge` relay) and `bridge_cursor_kv` (the local-host and
//!   remote-server changes-feed cursors, tracked independently per
//!   resource). No local domain-row mirror — a bridged resource's data lives
//!   entirely in the PHP host's own SQLite file or the real server; Rust
//!   only remembers enough to relay between them (see `sync::bridge`).
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
        version = 4;
    }
    if version < 5 {
        migrate_to_v5(conn)?;
        conn.pragma_update(None, "user_version", 5)?;
        version = 5;
    }
    if version < 6 {
        migrate_to_v6(conn)?;
        conn.pragma_update(None, "user_version", 6)?;
        version = 6;
    }
    if version < 7 {
        migrate_to_v7(conn)?;
        conn.pragma_update(None, "user_version", 7)?;
        version = 7;
    }
    if version < 8 {
        migrate_to_v8(conn)?;
        conn.pragma_update(None, "user_version", 8)?;
        version = 8;
    }
    if version < 9 {
        migrate_to_v9(conn)?;
        conn.pragma_update(None, "user_version", 9)?;
        version = 9;
    }
    if version < 10 {
        migrate_to_v10(conn)?;
        conn.pragma_update(None, "user_version", 10)?;
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

/// v5 (the sync engine): the per-resource pull CURSOR store, and the field-level
/// CONFLICT snapshot store. `item_conflicts` captures the diverging local ('mine')
/// and server ('theirs') snapshots when a push 409s or a pull detects concurrent
/// edits, keyed by the item's client_uuid; the resolver (a later PR) reads these.
const V5_SYNC_STATE: &str = "
    CREATE TABLE sync_state_kv (
        resource     TEXT PRIMARY KEY,
        cursor       TEXT,
        last_pull_at TEXT,
        last_push_at TEXT
    );
    CREATE TABLE item_conflicts (
        client_uuid    TEXT PRIMARY KEY,
        base_version   INTEGER NOT NULL,
        server_version INTEGER NOT NULL,
        mine_json      TEXT NOT NULL,
        theirs_json    TEXT NOT NULL,
        detected_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
    )";

fn migrate_to_v5(conn: &Connection) -> rusqlite::Result<()> {
    conn.execute_batch(V5_SYNC_STATE)?;
    Ok(())
}

/// v6 (push resilience): per-row retry bookkeeping so a flaky push backs off
/// instead of hammering the server or aborting the whole sync cycle.
/// `push_attempts` counts consecutive failures, `next_attempt_at` is the earliest
/// RFC3339 time to retry (NULL = due now), `last_push_error` surfaces a permanent
/// (non-retryable) failure to the UI.
const V6_PUSH_RETRY: &str = "
    ALTER TABLE demo_catalog_items ADD COLUMN push_attempts INTEGER NOT NULL DEFAULT 0;
    ALTER TABLE demo_catalog_items ADD COLUMN next_attempt_at TEXT;
    ALTER TABLE demo_catalog_items ADD COLUMN last_push_error TEXT;";

fn migrate_to_v6(conn: &Connection) -> rusqlite::Result<()> {
    conn.execute_batch(V6_PUSH_RETRY)?;
    Ok(())
}

/// v7: which backend this device enrolled against, persisted on the
/// `auth_state` singleton purely for informational display (the account
/// footer). NULL until the user enrolls at least once.
const V7_SERVER_URL: &str = "ALTER TABLE auth_state ADD COLUMN server_url TEXT;";

fn migrate_to_v7(conn: &Connection) -> rusqlite::Result<()> {
    conn.execute_batch(V7_SERVER_URL)
}

/// v8 (WC-plugin-sync): the singleton `plugin_sync_state` row — bookkeeping
/// for the last automatic reconcile pass (see `plugins::reconcile` and
/// `commands::post_login`), so the Plugins page can show "last synced at"
/// and any per-plugin failures without needing a fresh sync to answer that.
/// `last_failed_json` is a JSON-encoded `list<PluginSyncFailure>` (empty
/// array when nothing failed) rather than a normalized table — this is a
/// small, purely-informational snapshot, not queried by anything else.
const V8_PLUGIN_SYNC_STATE: &str = "
    CREATE TABLE plugin_sync_state (
        id                INTEGER PRIMARY KEY CHECK (id = 1),
        last_synced_at    INTEGER,
        last_installed    INTEGER NOT NULL DEFAULT 0,
        last_updated      INTEGER NOT NULL DEFAULT 0,
        last_removed      INTEGER NOT NULL DEFAULT 0,
        last_failed_json  TEXT NOT NULL DEFAULT '[]',
        last_error        TEXT
    )";

fn migrate_to_v8(conn: &Connection) -> rusqlite::Result<()> {
    conn.execute_batch(V8_PLUGIN_SYNC_STATE)?;
    conn.execute("INSERT INTO plugin_sync_state (id) VALUES (1)", [])?;
    Ok(())
}

/// v9 (WC-sync-generalize): `item_conflicts` needs a `resource` column now
/// that the engine serves more than one resource — SQLite can't add a column
/// into a PRIMARY KEY via ALTER, so this is the same rename -> create -> copy
/// -> drop technique v2 already used for `demo_catalog_items`. Every existing
/// row backfills as `'demo-catalog/items'`, the only resource that could have
/// produced a conflict before this version existed.
const V9_ITEM_CONFLICTS_RESOURCE: &str = "
    ALTER TABLE item_conflicts RENAME TO item_conflicts_v8;
    CREATE TABLE item_conflicts (
        resource       TEXT    NOT NULL DEFAULT 'demo-catalog/items',
        client_uuid    TEXT    NOT NULL,
        base_version   INTEGER NOT NULL,
        server_version INTEGER NOT NULL,
        mine_json      TEXT    NOT NULL,
        theirs_json    TEXT    NOT NULL,
        detected_at    TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
        PRIMARY KEY (resource, client_uuid)
    );
    INSERT INTO item_conflicts (resource, client_uuid, base_version, server_version, mine_json, theirs_json, detected_at)
      SELECT 'demo-catalog/items', client_uuid, base_version, server_version, mine_json, theirs_json, detected_at
      FROM item_conflicts_v8;
    DROP TABLE item_conflicts_v8;";

fn migrate_to_v9(conn: &Connection) -> rusqlite::Result<()> {
    conn.execute_batch(V9_ITEM_CONFLICTS_RESOURCE)
}

/// v10 (WC-plugin-data-bridge): bookkeeping for `sync::bridge`'s local-host
/// <-> remote-server relay. No local domain-row mirror — see the module doc
/// on `sync::bridge` for why (an HTTP relay through `php_host::proxy`, not
/// `ATTACH DATABASE`).
const V10_BRIDGE_STATE: &str = "
    CREATE TABLE bridge_resource_state (
        resource       TEXT    NOT NULL,
        client_uuid    TEXT    NOT NULL,
        local_id       INTEGER,
        local_version  INTEGER,
        remote_id      INTEGER,
        remote_version INTEGER,
        PRIMARY KEY (resource, client_uuid)
    );
    CREATE TABLE bridge_cursor_kv (
        resource      TEXT PRIMARY KEY,
        local_cursor  TEXT,
        remote_cursor TEXT,
        last_relay_at TEXT
    )";

fn migrate_to_v10(conn: &Connection) -> rusqlite::Result<()> {
    conn.execute_batch(V10_BRIDGE_STATE)
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
        assert_eq!(ver, 10);

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
        assert_eq!(ver, 10);
        assert!(column_exists(&conn, "demo_catalog_items", "client_uuid").unwrap());
        assert!(table_exists(&conn, "item_drafts").unwrap());
        assert!(table_exists(&conn, "auth_state").unwrap());
        assert!(table_exists(&conn, "sync_state_kv").unwrap());
        assert!(table_exists(&conn, "item_conflicts").unwrap());
        assert!(column_exists(&conn, "demo_catalog_items", "next_attempt_at").unwrap());
        assert!(column_exists(&conn, "auth_state", "server_url").unwrap());
        assert!(table_exists(&conn, "plugin_sync_state").unwrap());
        assert!(column_exists(&conn, "item_conflicts", "resource").unwrap());
        assert!(table_exists(&conn, "bridge_resource_state").unwrap());
        assert!(table_exists(&conn, "bridge_cursor_kv").unwrap());

        // The auth_state singleton exists and starts un-enrolled, with no
        // server_url recorded yet (nothing has enrolled).
        let (id, enrolled, server_url): (i64, i64, Option<String>) = conn
            .query_row("SELECT id, enrolled, server_url FROM auth_state", [], |r| {
                Ok((r.get(0)?, r.get(1)?, r.get(2)?))
            })
            .unwrap();
        assert_eq!(id, 1);
        assert_eq!(enrolled, 0);
        assert_eq!(server_url, None);

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
        assert_eq!(ver, 10);
        assert!(table_exists(&conn, "item_drafts").unwrap());
        assert!(table_exists(&conn, "auth_state").unwrap());
    }

    #[test]
    fn migrates_pre_v9_conflicts_backfilling_the_resource_column() {
        // Simulate a DB at v8: item_conflicts in its OLD shape (bare
        // client_uuid PK, no resource column) holding one real row.
        let conn = Connection::open_in_memory().unwrap();
        conn.execute_batch(V2_ITEMS).unwrap();
        conn.execute_batch(
            "CREATE TABLE item_conflicts (
                client_uuid    TEXT PRIMARY KEY,
                base_version   INTEGER NOT NULL,
                server_version INTEGER NOT NULL,
                mine_json      TEXT NOT NULL,
                theirs_json    TEXT NOT NULL,
                detected_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
            )",
        )
        .unwrap();
        conn.execute(
            "INSERT INTO item_conflicts (client_uuid, base_version, server_version, mine_json, theirs_json)
             VALUES ('pre-v9', 1, 2, '{}', '{}')",
            [],
        )
        .unwrap();
        conn.pragma_update(None, "user_version", 8).unwrap();

        run(&conn).unwrap();

        assert!(column_exists(&conn, "item_conflicts", "resource").unwrap());
        let resource: String = conn
            .query_row("SELECT resource FROM item_conflicts WHERE client_uuid = 'pre-v9'", [], |r| r.get(0))
            .unwrap();
        assert_eq!(resource, "demo-catalog/items", "the only resource that could have produced a pre-v9 conflict");
    }

    #[test]
    fn run_is_idempotent() {
        let conn = v1_conn();
        run(&conn).unwrap();
        run(&conn).unwrap(); // version already latest → no-op
        let ver: i64 = conn.query_row("PRAGMA user_version", [], |r| r.get(0)).unwrap();
        assert_eq!(ver, 10);
    }

    #[test]
    fn server_url_survives_logout() {
        let conn = migrated_for_auth_tests();
        conn.execute(
            "UPDATE auth_state SET server_url = 'https://staging.example.com' WHERE id = 1",
            [],
        )
        .unwrap();

        // clear() (logout) resets enrollment/session state but must NOT null
        // server_url — it's a device-level fact ("which server"), not a
        // per-session one, so re-enrolling pre-fills the same trusted server.
        crate::db::auth_repo::clear(&conn).unwrap();

        let server_url: Option<String> = conn
            .query_row("SELECT server_url FROM auth_state WHERE id = 1", [], |r| r.get(0))
            .unwrap();
        assert_eq!(server_url.as_deref(), Some("https://staging.example.com"));
    }

    #[test]
    fn plugin_sync_state_singleton_seeded_on_fresh_install() {
        let conn = Connection::open_in_memory().unwrap();
        run(&conn).unwrap();

        let (id, last_synced_at, last_installed, last_failed_json): (i64, Option<i64>, i64, String) = conn
            .query_row(
                "SELECT id, last_synced_at, last_installed, last_failed_json FROM plugin_sync_state",
                [],
                |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?)),
            )
            .unwrap();
        assert_eq!(id, 1);
        assert_eq!(last_synced_at, None, "no sync has run yet");
        assert_eq!(last_installed, 0);
        assert_eq!(last_failed_json, "[]");
    }

    fn migrated_for_auth_tests() -> Connection {
        let conn = Connection::open_in_memory().unwrap();
        run(&conn).unwrap();
        conn
    }
}
