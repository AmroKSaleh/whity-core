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

enum RowOutcome {
    Pushed,
    Conflicted,
}

/// A push step failed either at the HTTP layer (classified) or the local DB.
enum StepError {
    Http(http::HttpError),
    Db(rusqlite::Error),
}
impl From<http::HttpError> for StepError {
    fn from(e: http::HttpError) -> Self {
        StepError::Http(e)
    }
}
impl From<rusqlite::Error> for StepError {
    fn from(e: rusqlite::Error) -> Self {
        StepError::Db(e)
    }
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
        match push_row(conn, client, api_base, token, &row) {
            Ok(RowOutcome::Pushed) => pushed += 1,
            Ok(RowOutcome::Conflicted) => conflicts += 1,
            // A dead session aborts the whole cycle — the caller re-authenticates.
            Err(StepError::Http(http::HttpError::Unauthorized)) => {
                return Err("unauthorized (session expired); re-authenticate".to_string());
            }
            // Per-row transient/permanent failures back the ROW off but never abort
            // the cycle — one flaky or invalid row can't block the rest.
            Err(StepError::Http(http::HttpError::Retryable(msg))) => {
                record_push_failure(conn, row.id, false, &msg).map_err(db_err)?;
            }
            Err(StepError::Http(http::HttpError::Permanent(msg))) => {
                record_push_failure(conn, row.id, true, &msg).map_err(db_err)?;
            }
            // A local DB failure is not recoverable here.
            Err(StepError::Db(e)) => return Err(db_err(e)),
        }
    }

    Ok((pushed, conflicts))
}

/// Push one pending row. On HTTP success it applies the server result locally
/// (resetting the retry counters); a 409 parks a conflict; HTTP/DB failures
/// propagate as `StepError` for the caller to classify.
fn push_row(
    conn: &Connection,
    client: &Client,
    api_base: &str,
    token: &str,
    row: &PendingRow,
) -> Result<RowOutcome, StepError> {
    if row.sync_state == "deleted_pending" {
        return match row.server_id {
            // Never synced → nothing to delete server-side; drop it locally.
            None => {
                conn.execute("DELETE FROM demo_catalog_items WHERE id = ?1", params![row.id])?;
                Ok(RowOutcome::Pushed)
            }
            Some(server_id) => match http::delete(client, api_base, token, server_id, row.base_version)? {
                WriteOutcome::Applied(_) | WriteOutcome::NotFound => {
                    conn.execute(
                        "UPDATE demo_catalog_items
                         SET dirty = 0, sync_state = 'synced', deleted = 1,
                             push_attempts = 0, next_attempt_at = NULL, last_push_error = NULL
                         WHERE id = ?1",
                        params![row.id],
                    )?;
                    Ok(RowOutcome::Pushed)
                }
                WriteOutcome::Conflict(server) => {
                    park_conflict(conn, &row.client_uuid, &row.name, row.description.as_deref(), &row.status, true, row.base_version, &server)?;
                    Ok(RowOutcome::Conflicted)
                }
            },
        };
    }

    match row.server_id {
        None => {
            let item = http::create(
                client, api_base, token,
                &row.client_uuid, &row.name, row.description.as_deref(), &row.status,
            )?;
            conn.execute(
                "UPDATE demo_catalog_items
                 SET server_id = ?1, base_version = ?2, dirty = 0, sync_state = 'synced',
                     updated_at = ?3, updated_by = ?4,
                     push_attempts = 0, next_attempt_at = NULL, last_push_error = NULL
                 WHERE id = ?5",
                params![item.id, item.version, item.updated_at, item.updated_by, row.id],
            )?;
            Ok(RowOutcome::Pushed)
        }
        Some(server_id) => match http::update(
            client, api_base, token, server_id, row.base_version,
            &row.name, row.description.as_deref(), &row.status,
        )? {
            WriteOutcome::Applied(item) => {
                conn.execute(
                    "UPDATE demo_catalog_items
                     SET base_version = ?1, dirty = 0, sync_state = 'synced',
                         updated_at = ?2, updated_by = ?3,
                         push_attempts = 0, next_attempt_at = NULL, last_push_error = NULL
                     WHERE id = ?4",
                    params![item.version, item.updated_at, item.updated_by, row.id],
                )?;
                Ok(RowOutcome::Pushed)
            }
            WriteOutcome::Conflict(server) => {
                park_conflict(conn, &row.client_uuid, &row.name, row.description.as_deref(), &row.status, false, row.base_version, &server)?;
                Ok(RowOutcome::Conflicted)
            }
            // Server row vanished → forget the server id so it re-creates next push.
            WriteOutcome::NotFound => {
                conn.execute(
                    "UPDATE demo_catalog_items SET server_id = NULL WHERE id = ?1",
                    params![row.id],
                )?;
                Ok(RowOutcome::Pushed)
            }
        },
    }
}

/// Record a failed push: bump the attempt count and schedule the next retry
/// (exponential backoff; a permanent error is parked ~a day out so it stops
/// hammering the server but stays visible via `last_push_error`).
fn record_push_failure(
    conn: &Connection,
    id: i64,
    permanent: bool,
    message: &str,
) -> rusqlite::Result<()> {
    let attempts: i64 = conn
        .query_row(
            "SELECT push_attempts FROM demo_catalog_items WHERE id = ?1",
            params![id],
            |r| r.get(0),
        )
        .unwrap_or(0)
        + 1;
    let delay = if permanent { 24 * 3600 } else { next_backoff(attempts) };
    conn.execute(
        "UPDATE demo_catalog_items
         SET push_attempts = ?1, last_push_error = ?2,
             next_attempt_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '+' || ?3 || ' seconds')
         WHERE id = ?4",
        params![attempts, message, delay, id],
    )?;
    Ok(())
}

/// Exponential backoff (seconds) for the Nth consecutive push failure:
/// 10, 20, 40, 80, 160, then capped at 300 (5 min).
fn next_backoff(attempts: i64) -> i64 {
    let shift = attempts.clamp(1, 6) as u32;
    (5_i64 << shift).min(300)
}

fn select_pending(conn: &Connection) -> rusqlite::Result<Vec<PendingRow>> {
    let mut stmt = conn.prepare(
        "SELECT id, client_uuid, server_id, name, description, status, base_version, sync_state
         FROM demo_catalog_items
         WHERE sync_state IN ('pending', 'deleted_pending')
           AND (next_attempt_at IS NULL OR next_attempt_at <= strftime('%Y-%m-%dT%H:%M:%fZ','now'))
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
mod tests {
    use super::*;
    use crate::db::migrations;
    use rusqlite::Connection;

    fn migrated() -> Connection {
        let conn = Connection::open_in_memory().unwrap();
        migrations::run(&conn).unwrap();
        conn
    }

    fn insert_pending(conn: &Connection, uuid: &str) {
        conn.execute(
            "INSERT INTO demo_catalog_items (client_uuid, name, status, dirty, sync_state, created_at, updated_at_local)
             VALUES (?1, 'n', 'active', 1, 'pending', strftime('%Y-%m-%dT%H:%M:%fZ','now'), strftime('%Y-%m-%dT%H:%M:%fZ','now'))",
            params![uuid],
        )
        .unwrap();
    }

    #[test]
    fn backoff_is_monotonic_and_capped() {
        let mut prev = 0;
        for n in 1..=6 {
            let b = next_backoff(n);
            assert!(b >= prev, "non-decreasing across attempts");
            prev = b;
        }
        assert_eq!(next_backoff(1), 10);
        assert_eq!(next_backoff(6), 300);
        assert_eq!(next_backoff(999), 300, "capped at 5 min");
    }

    #[test]
    fn select_pending_skips_rows_not_yet_due() {
        let conn = migrated();
        insert_pending(&conn, "due-null");
        insert_pending(&conn, "due-past");
        insert_pending(&conn, "not-due");
        conn.execute(
            "UPDATE demo_catalog_items SET next_attempt_at = strftime('%Y-%m-%dT%H:%M:%fZ','now','-60 seconds') WHERE client_uuid = 'due-past'",
            [],
        )
        .unwrap();
        conn.execute(
            "UPDATE demo_catalog_items SET next_attempt_at = strftime('%Y-%m-%dT%H:%M:%fZ','now','+3600 seconds') WHERE client_uuid = 'not-due'",
            [],
        )
        .unwrap();

        let uuids: Vec<String> = select_pending(&conn)
            .unwrap()
            .into_iter()
            .map(|r| r.client_uuid)
            .collect();
        assert!(uuids.contains(&"due-null".to_string()));
        assert!(uuids.contains(&"due-past".to_string()));
        assert!(!uuids.contains(&"not-due".to_string()), "a future next_attempt_at is skipped");
    }

    #[test]
    fn push_backs_off_on_network_failure_without_aborting_the_cycle() {
        let conn = migrated();
        insert_pending(&conn, "a");
        insert_pending(&conn, "b");
        // A closed local port → connection refused → a Retryable network error.
        let client = reqwest::blocking::Client::builder()
            .connect_timeout(std::time::Duration::from_secs(3))
            .build()
            .unwrap();

        let (pushed, conflicts) =
            push_pending(&conn, &client, "http://127.0.0.1:1/api/v1", "tok").unwrap();
        assert_eq!(pushed, 0);
        assert_eq!(conflicts, 0);

        for uuid in ["a", "b"] {
            let (attempts, next, err, state): (i64, Option<String>, Option<String>, String) = conn
                .query_row(
                    "SELECT push_attempts, next_attempt_at, last_push_error, sync_state
                     FROM demo_catalog_items WHERE client_uuid = ?1",
                    [uuid],
                    |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?)),
                )
                .unwrap();
            assert_eq!(attempts, 1, "one failed attempt recorded for {uuid}");
            assert!(next.is_some(), "a retry is scheduled for {uuid}");
            assert!(err.is_some(), "the error is surfaced for {uuid}");
            assert_eq!(state, "pending", "the row is preserved (not lost) for {uuid}");
        }
    }
}

#[cfg(test)]
mod integration {
    //! End-to-end engine tests against a LIVE PR-A2 backend
    //! (admin@example.com/admin123). `#[ignore]` so plain `cargo test` skips
    //! them in CI; run explicitly with:
    //!   $env:WHITY_BACKEND_URL="http://localhost:8300"; cargo test -- --ignored
    //!
    //! WHITY_BACKEND_URL is REQUIRED here, not defaulted: these tests enrol
    //! devices and create/update/delete catalog rows, and the compiled-in
    //! default is the hosted instance (see config.rs) — falling back to it
    //! would write test data straight into production.
    use super::*;
    use crate::auth::api::{self, LoginOutcome};
    use crate::config::Config;
    use crate::db::migrations;
    use rusqlite::Connection;
    use uuid::Uuid;

    /// The backend under test — named explicitly or not at all.
    fn explicit_backend() -> Config {
        assert!(
            std::env::var("WHITY_BACKEND_URL").is_ok_and(|v| !v.trim().is_empty()),
            "set WHITY_BACKEND_URL to a throwaway backend these tests may write to \
             (e.g. http://localhost:8300); refusing to fall back to the compiled-in default"
        );
        Config::from_env()
    }

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
    #[ignore = "requires a live PR-A2 backend (WHITY_BACKEND_URL must be set)"]
    fn pushes_pulls_and_detects_conflicts() {
        let cfg = explicit_backend();
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
