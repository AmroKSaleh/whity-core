//! The push/pull reconciliation logic. Pure functions over a `&Connection` + an
//! HTTP `Client` + `(api_base, token)` — no Tauri types — so the flow is
//! testable against a real backend from a harness. `sync_cycle` = push then pull.

use reqwest::blocking::Client;
use rusqlite::{params, Connection};

use super::http::{self, WriteOutcome};
use super::{ServerItem, SyncSummary, DEMO_CATALOG_RESOURCE};

const PAGE_LIMIT: u32 = 200;

/// Run one full reconciliation: push every dirty local row, then pull + apply
/// the server changes feed. Conflicts are parked (not fatal).
pub fn sync_cycle(
    conn: &Connection,
    client: &Client,
    api_base: &str,
    token: &str,
) -> Result<SyncSummary, String> {
    let (pushed, push_conflicts) = push_pending(conn, client, api_base, token)?;
    stamp(conn, "last_push_at")?;
    let (pulled, pull_conflicts) = pull_changes(conn, client, api_base, token)?;

    Ok(SyncSummary {
        pushed,
        pulled,
        conflicts: push_conflicts + pull_conflicts,
        unsynced_count: unsynced_count(conn)?,
    })
}

// ---------------------------------------------------------------- push

struct PendingRow {
    id: i64,
    client_uuid: String,
    server_id: Option<i64>,
    name: String,
    description: Option<String>,
    status: String,
    base_version: i64,
    sync_state: String,
}

fn push_pending(
    conn: &Connection,
    client: &Client,
    api_base: &str,
    token: &str,
) -> Result<(usize, usize), String> {
    let rows = select_pending(conn).map_err(db_err)?;
    let mut pushed = 0;
    let mut conflicts = 0;

    for row in rows {
        if row.sync_state == "deleted_pending" {
            match row.server_id {
                // Never synced → nothing to delete server-side; drop it locally.
                None => {
                    conn.execute("DELETE FROM demo_catalog_items WHERE id = ?1", params![row.id])
                        .map_err(db_err)?;
                    pushed += 1;
                }
                Some(server_id) => {
                    match http::delete(client, api_base, token, server_id, row.base_version)
                        .map_err(|e| e.to_string())?
                    {
                        WriteOutcome::Applied(_) | WriteOutcome::NotFound => {
                            conn.execute(
                                "UPDATE demo_catalog_items SET dirty = 0, sync_state = 'synced', deleted = 1 WHERE id = ?1",
                                params![row.id],
                            ).map_err(db_err)?;
                            pushed += 1;
                        }
                        WriteOutcome::Conflict(server) => {
                            park_conflict(conn, &row.client_uuid, &row.name, row.description.as_deref(), &row.status, true, row.base_version, &server).map_err(db_err)?;
                            conflicts += 1;
                        }
                    }
                }
            }
            continue;
        }

        // pending create / update
        match row.server_id {
            None => {
                let item = http::create(
                    client, api_base, token,
                    &row.client_uuid, &row.name, row.description.as_deref(), &row.status,
                ).map_err(|e| e.to_string())?;
                conn.execute(
                    "UPDATE demo_catalog_items
                     SET server_id = ?1, base_version = ?2, dirty = 0, sync_state = 'synced',
                         updated_at = ?3, updated_by = ?4
                     WHERE id = ?5",
                    params![item.id, item.version, item.updated_at, item.updated_by, row.id],
                ).map_err(db_err)?;
                pushed += 1;
            }
            Some(server_id) => {
                match http::update(
                    client, api_base, token, server_id, row.base_version,
                    &row.name, row.description.as_deref(), &row.status,
                ).map_err(|e| e.to_string())? {
                    WriteOutcome::Applied(item) => {
                        conn.execute(
                            "UPDATE demo_catalog_items
                             SET base_version = ?1, dirty = 0, sync_state = 'synced',
                                 updated_at = ?2, updated_by = ?3
                             WHERE id = ?4",
                            params![item.version, item.updated_at, item.updated_by, row.id],
                        ).map_err(db_err)?;
                        pushed += 1;
                    }
                    WriteOutcome::Conflict(server) => {
                        park_conflict(conn, &row.client_uuid, &row.name, row.description.as_deref(), &row.status, false, row.base_version, &server).map_err(db_err)?;
                        conflicts += 1;
                    }
                    // Server row vanished → forget the server id so it re-creates next push.
                    WriteOutcome::NotFound => {
                        conn.execute(
                            "UPDATE demo_catalog_items SET server_id = NULL WHERE id = ?1",
                            params![row.id],
                        ).map_err(db_err)?;
                    }
                }
            }
        }
    }

    Ok((pushed, conflicts))
}

fn select_pending(conn: &Connection) -> rusqlite::Result<Vec<PendingRow>> {
    let mut stmt = conn.prepare(
        "SELECT id, client_uuid, server_id, name, description, status, base_version, sync_state
         FROM demo_catalog_items
         WHERE sync_state IN ('pending', 'deleted_pending')
         ORDER BY updated_at_local ASC, id ASC",
    )?;
    let rows = stmt
        .query_map([], |r| {
            Ok(PendingRow {
                id: r.get(0)?,
                client_uuid: r.get(1)?,
                server_id: r.get(2)?,
                name: r.get(3)?,
                description: r.get(4)?,
                status: r.get(5)?,
                base_version: r.get(6)?,
                sync_state: r.get(7)?,
            })
        })?
        .collect::<Result<Vec<_>, _>>()?;
    Ok(rows)
}

// ---------------------------------------------------------------- pull

struct LocalMatch {
    id: i64,
    client_uuid: String,
    name: String,
    description: Option<String>,
    status: String,
    base_version: i64,
    dirty: bool,
    deleted: bool,
}

fn pull_changes(
    conn: &Connection,
    client: &Client,
    api_base: &str,
    token: &str,
) -> Result<(usize, usize), String> {
    let mut cursor = get_cursor(conn).map_err(db_err)?;
    let mut pulled = 0;
    let mut conflicts = 0;

    loop {
        let page = http::fetch_changes(client, api_base, token, &cursor, PAGE_LIMIT)
            .map_err(|e| e.to_string())?;
        for item in &page.items {
            if apply_pulled(conn, item).map_err(db_err)? {
                conflicts += 1;
            }
            pulled += 1;
        }
        cursor = page.cursor.clone();
        set_cursor(conn, &cursor).map_err(db_err)?;
        if !page.has_more {
            break;
        }
    }
    stamp(conn, "last_pull_at")?;

    Ok((pulled, conflicts))
}

/// Apply one server item to local. Returns true when it produced a conflict.
fn apply_pulled(conn: &Connection, server: &ServerItem) -> rusqlite::Result<bool> {
    let local = find_local(conn, server.id, server.client_uuid.as_deref())?;
    match local {
        None => {
            insert_from_server(conn, server)?;
            Ok(false)
        }
        Some(l) if !l.dirty => {
            update_from_server(conn, l.id, server)?;
            Ok(false)
        }
        Some(l) if server.version > l.base_version => {
            // Locally dirty AND the server moved past our base → real divergence.
            park_conflict(conn, &l.client_uuid, &l.name, l.description.as_deref(), &l.status, l.deleted, l.base_version, server)?;
            Ok(true)
        }
        // Locally dirty but the server hasn't advanced since our base — our
        // pending push will carry the local change; leave it be.
        Some(_) => Ok(false),
    }
}

fn find_local(conn: &Connection, server_id: i64, client_uuid: Option<&str>) -> rusqlite::Result<Option<LocalMatch>> {
    let mut stmt = conn.prepare(
        "SELECT id, client_uuid, name, description, status, base_version, dirty, deleted
         FROM demo_catalog_items
         WHERE server_id = ?1 OR client_uuid = ?2
         LIMIT 1",
    )?;
    let mut rows = stmt.query(params![server_id, client_uuid])?;
    if let Some(r) = rows.next()? {
        Ok(Some(LocalMatch {
            id: r.get(0)?,
            client_uuid: r.get(1)?,
            name: r.get(2)?,
            description: r.get(3)?,
            status: r.get(4)?,
            base_version: r.get(5)?,
            dirty: r.get::<_, i64>(6)? != 0,
            deleted: r.get::<_, i64>(7)? != 0,
        }))
    } else {
        Ok(None)
    }
}

fn insert_from_server(conn: &Connection, server: &ServerItem) -> rusqlite::Result<()> {
    let client_uuid = server
        .client_uuid
        .clone()
        .unwrap_or_else(|| format!("srv-{}", server.id));
    conn.execute(
        "INSERT INTO demo_catalog_items
           (client_uuid, server_id, name, description, status, base_version,
            sync_state, dirty, deleted, created_at, updated_at, updated_by, updated_at_local)
         VALUES (?1, ?2, ?3, ?4, ?5, ?6, 'synced', 0, ?7,
                 COALESCE(?8, strftime('%Y-%m-%dT%H:%M:%fZ','now')), ?9, ?10,
                 strftime('%Y-%m-%dT%H:%M:%fZ','now'))",
        params![
            client_uuid,
            server.id,
            server.name,
            server.description,
            server.status,
            server.version,
            server.is_deleted() as i64,
            server.created_at,
            server.updated_at,
            server.updated_by,
        ],
    )?;
    Ok(())
}

fn update_from_server(conn: &Connection, local_id: i64, server: &ServerItem) -> rusqlite::Result<()> {
    conn.execute(
        "UPDATE demo_catalog_items
         SET server_id = ?1, name = ?2, description = ?3, status = ?4, base_version = ?5,
             sync_state = 'synced', dirty = 0, deleted = ?6, updated_at = ?7, updated_by = ?8
         WHERE id = ?9",
        params![
            server.id,
            server.name,
            server.description,
            server.status,
            server.version,
            server.is_deleted() as i64,
            server.updated_at,
            server.updated_by,
            local_id,
        ],
    )?;
    Ok(())
}

#[allow(clippy::too_many_arguments)]
fn park_conflict(
    conn: &Connection,
    client_uuid: &str,
    mine_name: &str,
    mine_description: Option<&str>,
    mine_status: &str,
    mine_deleted: bool,
    base_version: i64,
    server: &ServerItem,
) -> rusqlite::Result<()> {
    let mine = serde_json::json!({
        "name": mine_name,
        "description": mine_description,
        "status": mine_status,
        "deleted": mine_deleted,
    })
    .to_string();
    let theirs = serde_json::json!({
        "name": server.name,
        "description": server.description,
        "status": server.status,
        "deleted": server.is_deleted(),
        "updatedBy": server.updated_by,
    })
    .to_string();

    conn.execute(
        "INSERT INTO item_conflicts (client_uuid, base_version, server_version, mine_json, theirs_json)
         VALUES (?1, ?2, ?3, ?4, ?5)
         ON CONFLICT(client_uuid) DO UPDATE SET
             base_version = excluded.base_version,
             server_version = excluded.server_version,
             mine_json = excluded.mine_json,
             theirs_json = excluded.theirs_json,
             detected_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')",
        params![client_uuid, base_version, server.version, mine, theirs],
    )?;
    conn.execute(
        "UPDATE demo_catalog_items SET sync_state = 'conflict' WHERE client_uuid = ?1",
        params![client_uuid],
    )?;
    Ok(())
}

// ---------------------------------------------------------------- cursor / status

fn get_cursor(conn: &Connection) -> rusqlite::Result<String> {
    let existing: Option<String> = conn
        .query_row(
            "SELECT cursor FROM sync_state_kv WHERE resource = ?1",
            params![DEMO_CATALOG_RESOURCE],
            |r| r.get(0),
        )
        .ok()
        .flatten();
    match existing {
        Some(c) => Ok(c),
        None => {
            conn.execute(
                "INSERT INTO sync_state_kv (resource, cursor) VALUES (?1, '0')
                 ON CONFLICT(resource) DO NOTHING",
                params![DEMO_CATALOG_RESOURCE],
            )?;
            Ok("0".to_string())
        }
    }
}

fn set_cursor(conn: &Connection, cursor: &str) -> rusqlite::Result<()> {
    conn.execute(
        "INSERT INTO sync_state_kv (resource, cursor) VALUES (?1, ?2)
         ON CONFLICT(resource) DO UPDATE SET cursor = excluded.cursor",
        params![DEMO_CATALOG_RESOURCE, cursor],
    )?;
    Ok(())
}

fn stamp(conn: &Connection, column: &str) -> Result<(), String> {
    // `column` is a fixed internal identifier (last_pull_at | last_push_at).
    conn.execute(
        &format!(
            "INSERT INTO sync_state_kv (resource, {column}) VALUES (?1, strftime('%Y-%m-%dT%H:%M:%fZ','now'))
             ON CONFLICT(resource) DO UPDATE SET {column} = strftime('%Y-%m-%dT%H:%M:%fZ','now')"
        ),
        params![DEMO_CATALOG_RESOURCE],
    )
    .map_err(db_err)?;
    Ok(())
}

pub fn unsynced_count(conn: &Connection) -> Result<usize, String> {
    let n: i64 = conn
        .query_row(
            "SELECT COUNT(*) FROM demo_catalog_items WHERE sync_state <> 'synced'",
            [],
            |r| r.get(0),
        )
        .map_err(db_err)?;
    Ok(n as usize)
}

fn db_err<E: std::fmt::Display>(e: E) -> String {
    format!("local database error: {e}")
}

#[cfg(test)]
mod integration {
    //! End-to-end engine tests against a LIVE PR-A2 backend
    //! (WHITY_BACKEND_URL, default http://localhost:8300, admin@example.com/admin123).
    //! `#[ignore]` so plain `cargo test` skips them in CI; run explicitly with:
    //!   $env:WHITY_BACKEND_URL="http://localhost:8300"; cargo test -- --ignored
    use super::*;
    use crate::auth::api::{self, LoginOutcome};
    use crate::config::Config;
    use crate::db::migrations;
    use rusqlite::Connection;
    use uuid::Uuid;

    fn token(client: &Client, cfg: &Config) -> String {
        match api::login(client, cfg, "admin@example.com", "admin123").expect("login") {
            LoginOutcome::Session { access_token } => {
                let dev = api::register_device(client, cfg, &access_token, "sync-it", cfg.platform)
                    .expect("device enroll");
                api::exchange(client, cfg, &dev.credential).expect("exchange").access_token
            }
            _ => panic!("expected a full session for the seeded admin"),
        }
    }

    fn local_db() -> Connection {
        let conn = Connection::open_in_memory().unwrap();
        migrations::run(&conn).unwrap();
        conn
    }

    fn insert_local_pending(conn: &Connection, uuid: &str, name: &str) {
        conn.execute(
            "INSERT INTO demo_catalog_items (client_uuid, name, status, dirty, sync_state, created_at, updated_at_local)
             VALUES (?1, ?2, 'active', 1, 'pending', strftime('%Y-%m-%dT%H:%M:%fZ','now'), strftime('%Y-%m-%dT%H:%M:%fZ','now'))",
            params![uuid, name],
        )
        .unwrap();
    }

    #[test]
    #[ignore = "requires a live PR-A2 backend (WHITY_BACKEND_URL, default :8300)"]
    fn pushes_pulls_and_detects_conflicts() {
        let cfg = Config::from_env();
        let client = api::build_client().unwrap();
        let tok = token(&client, &cfg);
        let api_base = cfg.api_base();

        // --- PUSH: a locally-created item reaches the server ---
        let db1 = local_db();
        let uuid = Uuid::new_v4().to_string();
        insert_local_pending(&db1, &uuid, "Engine IT");

        let s1 = sync_cycle(&db1, &client, &api_base, &tok).unwrap();
        assert!(s1.pushed >= 1, "the pending create should push");
        let (server_id, state, base_v): (Option<i64>, String, i64) = db1
            .query_row(
                "SELECT server_id, sync_state, base_version FROM demo_catalog_items WHERE client_uuid = ?1",
                [&uuid],
                |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?)),
            )
            .unwrap();
        let server_id = server_id.expect("server_id assigned after push");
        assert_eq!(state, "synced");
        assert_eq!(base_v, 1);

        // --- PULL: a fresh client sees the pushed row ---
        let db2 = local_db();
        let s2 = sync_cycle(&db2, &client, &api_base, &tok).unwrap();
        assert!(s2.pulled >= 1);
        let found: i64 = db2
            .query_row(
                "SELECT COUNT(*) FROM demo_catalog_items WHERE client_uuid = ?1 AND sync_state = 'synced' AND server_id = ?2",
                params![&uuid, server_id],
                |r| r.get(0),
            )
            .unwrap();
        assert_eq!(found, 1, "the fresh client pulled the pushed row");

        // --- CONFLICT: server moves ahead of a locally-dirty row ---
        match http::update(&client, &api_base, &tok, server_id, 1, "Server edit", None, "active").unwrap() {
            WriteOutcome::Applied(item) => assert_eq!(item.version, 2),
            other => panic!("expected the server update to apply, got {other:?}"),
        }
        db1.execute(
            "UPDATE demo_catalog_items SET name = 'Local edit', dirty = 1, sync_state = 'pending' WHERE client_uuid = ?1",
            [&uuid],
        )
        .unwrap();

        let s3 = sync_cycle(&db1, &client, &api_base, &tok).unwrap();
        assert!(s3.conflicts >= 1, "the stale local edit must conflict with server v2");
        let cstate: String = db1
            .query_row(
                "SELECT sync_state FROM demo_catalog_items WHERE client_uuid = ?1",
                [&uuid],
                |r| r.get(0),
            )
            .unwrap();
        assert_eq!(cstate, "conflict");
        let (mine, theirs): (String, String) = db1
            .query_row(
                "SELECT mine_json, theirs_json FROM item_conflicts WHERE client_uuid = ?1",
                [&uuid],
                |r| Ok((r.get(0)?, r.get(1)?)),
            )
            .unwrap();
        assert!(mine.contains("Local edit"), "mine snapshot: {mine}");
        assert!(theirs.contains("Server edit"), "theirs snapshot: {theirs}");

        // --- RESOLVE: apply a merge, re-sync; the merged result reaches the server ---
        assert!(
            crate::db::conflicts_repo::resolve(&db1, &uuid, "Merged name", None, "active").unwrap()
        );
        let _ = sync_cycle(&db1, &client, &api_base, &tok).unwrap();
        let (rstate, rname, rbase): (String, String, i64) = db1
            .query_row(
                "SELECT sync_state, name, base_version FROM demo_catalog_items WHERE client_uuid = ?1",
                [&uuid],
                |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?)),
            )
            .unwrap();
        assert_eq!(rstate, "synced");
        assert_eq!(rname, "Merged name");
        assert!(rbase >= 3, "version advanced past the server's v2 after pushing the merge");
        let conflicts_left: i64 = db1
            .query_row(
                "SELECT COUNT(*) FROM item_conflicts WHERE client_uuid = ?1",
                [&uuid],
                |r| r.get(0),
            )
            .unwrap();
        assert_eq!(conflicts_left, 0, "the conflict is cleared after resolve + sync");
    }
}
