//! The push/pull reconciliation logic, generalized (WC-sync-generalize) to
//! any `resource::ResourceDescriptor` rather than hardcoded to DemoCatalog.
//! Pure functions over a `&Connection` + an HTTP `Client` + `(api_base,
//! token)` — no Tauri types — so the flow is testable against a real backend
//! (or a mock — see `resource.rs`'s sibling test module) from a harness.
//! `sync_cycle_for` = push then pull, once per resource.

use reqwest::blocking::Client;
use rusqlite::{params, Connection, OptionalExtension};

use super::http::{self, WriteOutcome};
use super::resource::{self, ResourceDescriptor};
use super::{SyncRow, SyncStatusView, SyncSummary};

const PAGE_LIMIT: u32 = 200;

/// Run one full reconciliation of every resource in `resource::RESOURCES`.
/// Conflicts are parked (not fatal); an expired session aborts the whole
/// cycle immediately (all resources share one token).
pub fn sync_cycle(conn: &Connection, client: &Client, api_base: &str, token: &str) -> Result<SyncSummary, String> {
    sync_cycle_for(conn, client, api_base, Some(token), resource::RESOURCES)
}

/// Same as `sync_cycle`, but over an explicit resource slice and an optional
/// token — the entry point `sync::bridge` reuses for its local<->remote
/// relay, where one leg (the local PHP host) takes no bearer token at all.
pub fn sync_cycle_for(
    conn: &Connection,
    client: &Client,
    api_base: &str,
    token: Option<&str>,
    resources: &[&ResourceDescriptor],
) -> Result<SyncSummary, String> {
    let mut summary = SyncSummary::default();
    for r in resources {
        let (pushed, push_conflicts) = push_pending(conn, client, api_base, token, r)?;
        stamp(conn, r, "last_push_at")?;
        let (pulled, pull_conflicts) = pull_changes(conn, client, api_base, token, r)?;
        summary.pushed += pushed;
        summary.pulled += pulled;
        summary.conflicts += push_conflicts + pull_conflicts;
    }
    summary.unsynced_count = unsynced_count(conn, resources)?;
    Ok(summary)
}

// ---------------------------------------------------------------- push

struct PendingRow {
    id: i64,
    client_uuid: String,
    server_id: Option<i64>,
    base_version: i64,
    sync_state: String,
    domain: serde_json::Map<String, serde_json::Value>,
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
    token: Option<&str>,
    resource: &ResourceDescriptor,
) -> Result<(usize, usize), String> {
    let rows = select_pending(conn, resource).map_err(db_err)?;
    let mut pushed = 0;
    let mut conflicts = 0;

    for row in rows {
        match push_row(conn, client, api_base, token, resource, &row) {
            Ok(RowOutcome::Pushed) => pushed += 1,
            Ok(RowOutcome::Conflicted) => conflicts += 1,
            // A dead session aborts the whole cycle — the caller re-authenticates.
            Err(StepError::Http(http::HttpError::Unauthorized)) => {
                return Err("unauthorized (session expired); re-authenticate".to_string());
            }
            // Per-row transient/permanent failures back the ROW off but never abort
            // the cycle — one flaky or invalid row can't block the rest.
            Err(StepError::Http(http::HttpError::Retryable(msg))) => {
                record_push_failure(conn, resource, row.id, false, &msg).map_err(db_err)?;
            }
            Err(StepError::Http(http::HttpError::Permanent(msg))) => {
                record_push_failure(conn, resource, row.id, true, &msg).map_err(db_err)?;
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
    token: Option<&str>,
    resource: &ResourceDescriptor,
    row: &PendingRow,
) -> Result<RowOutcome, StepError> {
    if row.sync_state == "deleted_pending" {
        return match row.server_id {
            // Never synced → nothing to delete server-side; drop it locally.
            None => {
                conn.execute(&format!("DELETE FROM {} WHERE id = ?1", resource.table), params![row.id])?;
                Ok(RowOutcome::Pushed)
            }
            Some(server_id) => {
                match http::delete(client, api_base, token, resource.base_path, server_id, row.base_version)? {
                    WriteOutcome::Applied(_) | WriteOutcome::NotFound => {
                        conn.execute(
                            &format!(
                                "UPDATE {} SET dirty = 0, sync_state = 'synced', deleted = 1,
                                     push_attempts = 0, next_attempt_at = NULL, last_push_error = NULL
                                 WHERE id = ?1",
                                resource.table
                            ),
                            params![row.id],
                        )?;
                        Ok(RowOutcome::Pushed)
                    }
                    WriteOutcome::Conflict(server) => {
                        park_conflict(conn, resource, &row.client_uuid, &row.domain, true, row.base_version, &server)?;
                        Ok(RowOutcome::Conflicted)
                    }
                }
            }
        };
    }

    match row.server_id {
        None => {
            let item = http::create(client, api_base, token, resource.base_path, &row.client_uuid, &row.domain)?;
            conn.execute(
                &format!(
                    "UPDATE {} SET server_id = ?1, base_version = ?2, dirty = 0, sync_state = 'synced',
                         updated_at = ?3, updated_by = ?4,
                         push_attempts = 0, next_attempt_at = NULL, last_push_error = NULL
                     WHERE id = ?5",
                    resource.table
                ),
                params![item.id, item.version, item.updated_at, item.updated_by, row.id],
            )?;
            Ok(RowOutcome::Pushed)
        }
        Some(server_id) => {
            match http::update(client, api_base, token, resource.base_path, server_id, row.base_version, &row.domain)? {
                WriteOutcome::Applied(item) => {
                    conn.execute(
                        &format!(
                            "UPDATE {} SET base_version = ?1, dirty = 0, sync_state = 'synced',
                                 updated_at = ?2, updated_by = ?3,
                                 push_attempts = 0, next_attempt_at = NULL, last_push_error = NULL
                             WHERE id = ?4",
                            resource.table
                        ),
                        params![item.version, item.updated_at, item.updated_by, row.id],
                    )?;
                    Ok(RowOutcome::Pushed)
                }
                WriteOutcome::Conflict(server) => {
                    park_conflict(conn, resource, &row.client_uuid, &row.domain, false, row.base_version, &server)?;
                    Ok(RowOutcome::Conflicted)
                }
                // Server row vanished → forget the server id so it re-creates next push.
                WriteOutcome::NotFound => {
                    conn.execute(&format!("UPDATE {} SET server_id = NULL WHERE id = ?1", resource.table), params![row.id])?;
                    Ok(RowOutcome::Pushed)
                }
            }
        }
    }
}

/// Record a failed push: bump the attempt count and schedule the next retry
/// (exponential backoff; a permanent error is parked ~a day out so it stops
/// hammering the server but stays visible via `last_push_error`).
fn record_push_failure(
    conn: &Connection,
    resource: &ResourceDescriptor,
    id: i64,
    permanent: bool,
    message: &str,
) -> rusqlite::Result<()> {
    let attempts: i64 = conn
        .query_row(&format!("SELECT push_attempts FROM {} WHERE id = ?1", resource.table), params![id], |r| r.get(0))
        .unwrap_or(0)
        + 1;
    let delay = if permanent { 24 * 3600 } else { next_backoff(attempts) };
    conn.execute(
        &format!(
            "UPDATE {} SET push_attempts = ?1, last_push_error = ?2,
                 next_attempt_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '+' || ?3 || ' seconds')
             WHERE id = ?4",
            resource.table
        ),
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

fn select_pending(conn: &Connection, resource: &ResourceDescriptor) -> rusqlite::Result<Vec<PendingRow>> {
    let domain_cols = resource.domain_columns.join(", ");
    let sql = format!(
        "SELECT id, client_uuid, server_id, base_version, sync_state{sep}{domain_cols}
         FROM {table}
         WHERE sync_state IN ('pending', 'deleted_pending')
           AND (next_attempt_at IS NULL OR next_attempt_at <= strftime('%Y-%m-%dT%H:%M:%fZ','now'))
         ORDER BY updated_at_local ASC, id ASC",
        sep = if domain_cols.is_empty() { "" } else { ", " },
        domain_cols = domain_cols,
        table = resource.table,
    );
    let mut stmt = conn.prepare(&sql)?;
    let columns = resource.domain_columns;
    let rows = stmt
        .query_map([], |r| {
            let mut domain = serde_json::Map::new();
            for (i, col) in columns.iter().enumerate() {
                domain.insert((*col).to_string(), super::sql_value::sql_to_json(r, 5 + i)?);
            }
            Ok(PendingRow {
                id: r.get(0)?,
                client_uuid: r.get(1)?,
                server_id: r.get(2)?,
                base_version: r.get(3)?,
                sync_state: r.get(4)?,
                domain,
            })
        })?
        .collect::<Result<Vec<_>, _>>()?;
    Ok(rows)
}

// ---------------------------------------------------------------- pull

struct LocalMatch {
    id: i64,
    client_uuid: String,
    base_version: i64,
    dirty: bool,
    deleted: bool,
    domain: serde_json::Map<String, serde_json::Value>,
}

fn pull_changes(
    conn: &Connection,
    client: &Client,
    api_base: &str,
    token: Option<&str>,
    resource: &ResourceDescriptor,
) -> Result<(usize, usize), String> {
    let mut cursor = get_cursor(conn, resource).map_err(db_err)?;
    let mut pulled = 0;
    let mut conflicts = 0;

    loop {
        let page = http::fetch_changes(client, api_base, token, resource.base_path, &cursor, PAGE_LIMIT).map_err(|e| e.to_string())?;
        for item in &page.items {
            if apply_pulled(conn, resource, item).map_err(db_err)? {
                conflicts += 1;
            }
            pulled += 1;
        }
        cursor = page.cursor.clone();
        set_cursor(conn, resource, &cursor).map_err(db_err)?;
        if !page.has_more {
            break;
        }
    }
    stamp(conn, resource, "last_pull_at")?;

    Ok((pulled, conflicts))
}

/// Apply one server item to local. Returns true when it produced a conflict.
fn apply_pulled(conn: &Connection, resource: &ResourceDescriptor, server: &SyncRow) -> rusqlite::Result<bool> {
    let local = find_local(conn, resource, server.id, server.client_uuid.as_deref())?;
    match local {
        None => {
            insert_from_server(conn, resource, server)?;
            Ok(false)
        }
        Some(l) if !l.dirty => {
            update_from_server(conn, resource, l.id, server)?;
            Ok(false)
        }
        Some(l) if server.version > l.base_version => {
            // Locally dirty AND the server moved past our base → real divergence.
            park_conflict(conn, resource, &l.client_uuid, &l.domain, l.deleted, l.base_version, server)?;
            Ok(true)
        }
        // Locally dirty but the server hasn't advanced since our base → our
        // pending push will carry the local change; leave it be.
        Some(_) => Ok(false),
    }
}

fn find_local(
    conn: &Connection,
    resource: &ResourceDescriptor,
    server_id: i64,
    client_uuid: Option<&str>,
) -> rusqlite::Result<Option<LocalMatch>> {
    let domain_cols = resource.domain_columns.join(", ");
    let sql = format!(
        "SELECT id, client_uuid, base_version, dirty, deleted{sep}{domain_cols}
         FROM {table}
         WHERE server_id = ?1 OR client_uuid = ?2
         LIMIT 1",
        sep = if domain_cols.is_empty() { "" } else { ", " },
        domain_cols = domain_cols,
        table = resource.table,
    );
    let mut stmt = conn.prepare(&sql)?;
    let columns = resource.domain_columns;
    let mut rows = stmt.query(params![server_id, client_uuid])?;
    if let Some(r) = rows.next()? {
        let mut domain = serde_json::Map::new();
        for (i, col) in columns.iter().enumerate() {
            domain.insert((*col).to_string(), super::sql_value::sql_to_json(r, 5 + i)?);
        }
        Ok(Some(LocalMatch {
            id: r.get(0)?,
            client_uuid: r.get(1)?,
            base_version: r.get(2)?,
            dirty: r.get::<_, i64>(3)? != 0,
            deleted: r.get::<_, i64>(4)? != 0,
            domain,
        }))
    } else {
        Ok(None)
    }
}

fn insert_from_server(conn: &Connection, resource: &ResourceDescriptor, server: &SyncRow) -> rusqlite::Result<()> {
    let client_uuid = server.client_uuid.clone().unwrap_or_else(|| format!("srv-{}", server.id));
    let deleted = server.is_deleted() as i64;
    let domain_cols = resource.domain_columns.join(", ");
    let domain_placeholders: Vec<String> = (0..resource.domain_columns.len()).map(|i| format!("?{}", 8 + i)).collect();
    let sql = format!(
        "INSERT INTO {table}
           (client_uuid, server_id, base_version, sync_state, dirty, deleted,
            created_at, updated_at, updated_by, updated_at_local{sep}{domain_cols})
         VALUES (?1, ?2, ?3, 'synced', 0, ?4,
                 COALESCE(?5, strftime('%Y-%m-%dT%H:%M:%fZ','now')), ?6, ?7,
                 strftime('%Y-%m-%dT%H:%M:%fZ','now'){psep}{placeholders})",
        table = resource.table,
        sep = if domain_cols.is_empty() { "" } else { ", " },
        domain_cols = domain_cols,
        psep = if domain_placeholders.is_empty() { "" } else { ", " },
        placeholders = domain_placeholders.join(", "),
    );

    let domain_values: Vec<_> = resource.domain_columns.iter().map(|c| super::sql_value::json_to_sql(server.domain.get(*c))).collect();
    let mut bound: Vec<&dyn rusqlite::ToSql> =
        vec![&client_uuid, &server.id, &server.version, &deleted, &server.created_at, &server.updated_at, &server.updated_by];
    for v in &domain_values {
        bound.push(v);
    }
    conn.execute(&sql, bound.as_slice())?;
    Ok(())
}

fn update_from_server(conn: &Connection, resource: &ResourceDescriptor, local_id: i64, server: &SyncRow) -> rusqlite::Result<()> {
    let domain_set: String =
        resource.domain_columns.iter().enumerate().map(|(i, c)| format!("{c} = ?{}", 6 + i)).collect::<Vec<_>>().join(", ");
    let id_placeholder = 6 + resource.domain_columns.len();
    let sql = format!(
        "UPDATE {table} SET server_id = ?1, base_version = ?2, sync_state = 'synced', dirty = 0,
             deleted = ?3, updated_at = ?4, updated_by = ?5{sep}{domain_set}
         WHERE id = ?{id_placeholder}",
        table = resource.table,
        sep = if domain_set.is_empty() { "" } else { ", " },
    );

    let deleted = server.is_deleted() as i64;
    let domain_values: Vec<_> = resource.domain_columns.iter().map(|c| super::sql_value::json_to_sql(server.domain.get(*c))).collect();
    let mut bound: Vec<&dyn rusqlite::ToSql> = vec![&server.id, &server.version, &deleted, &server.updated_at, &server.updated_by];
    for v in &domain_values {
        bound.push(v);
    }
    bound.push(&local_id);
    conn.execute(&sql, bound.as_slice())?;
    Ok(())
}

fn park_conflict(
    conn: &Connection,
    resource: &ResourceDescriptor,
    client_uuid: &str,
    mine: &serde_json::Map<String, serde_json::Value>,
    mine_deleted: bool,
    base_version: i64,
    server: &SyncRow,
) -> rusqlite::Result<()> {
    let mut mine_obj = mine.clone();
    mine_obj.insert("deleted".to_string(), serde_json::Value::from(mine_deleted));
    let mine_json = serde_json::Value::Object(mine_obj).to_string();

    let mut theirs_obj = server.domain.clone();
    theirs_obj.insert("deleted".to_string(), serde_json::Value::from(server.is_deleted()));
    theirs_obj.insert(
        "updatedBy".to_string(),
        server.updated_by.map(serde_json::Value::from).unwrap_or(serde_json::Value::Null),
    );
    let theirs_json = serde_json::Value::Object(theirs_obj).to_string();

    conn.execute(
        "INSERT INTO item_conflicts (resource, client_uuid, base_version, server_version, mine_json, theirs_json)
         VALUES (?1, ?2, ?3, ?4, ?5, ?6)
         ON CONFLICT(resource, client_uuid) DO UPDATE SET
             base_version = excluded.base_version,
             server_version = excluded.server_version,
             mine_json = excluded.mine_json,
             theirs_json = excluded.theirs_json,
             detected_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')",
        params![resource.key, client_uuid, base_version, server.version, mine_json, theirs_json],
    )?;
    conn.execute(
        &format!("UPDATE {} SET sync_state = 'conflict' WHERE client_uuid = ?1", resource.table),
        params![client_uuid],
    )?;
    Ok(())
}

// ---------------------------------------------------------------- cursor / status

fn get_cursor(conn: &Connection, resource: &ResourceDescriptor) -> rusqlite::Result<String> {
    let existing: Option<String> =
        conn.query_row("SELECT cursor FROM sync_state_kv WHERE resource = ?1", params![resource.key], |r| r.get(0)).ok().flatten();
    match existing {
        Some(c) => Ok(c),
        None => {
            conn.execute(
                "INSERT INTO sync_state_kv (resource, cursor) VALUES (?1, '0') ON CONFLICT(resource) DO NOTHING",
                params![resource.key],
            )?;
            Ok("0".to_string())
        }
    }
}

fn set_cursor(conn: &Connection, resource: &ResourceDescriptor, cursor: &str) -> rusqlite::Result<()> {
    conn.execute(
        "INSERT INTO sync_state_kv (resource, cursor) VALUES (?1, ?2)
         ON CONFLICT(resource) DO UPDATE SET cursor = excluded.cursor",
        params![resource.key, cursor],
    )?;
    Ok(())
}

fn stamp(conn: &Connection, resource: &ResourceDescriptor, column: &str) -> Result<(), String> {
    // `column` is a fixed internal identifier (last_pull_at | last_push_at).
    conn.execute(
        &format!(
            "INSERT INTO sync_state_kv (resource, {column}) VALUES (?1, strftime('%Y-%m-%dT%H:%M:%fZ','now'))
             ON CONFLICT(resource) DO UPDATE SET {column} = strftime('%Y-%m-%dT%H:%M:%fZ','now')"
        ),
        params![resource.key],
    )
    .map_err(db_err)?;
    Ok(())
}

fn unsynced_count_for(conn: &Connection, resource: &ResourceDescriptor) -> Result<usize, String> {
    let n: i64 = conn
        .query_row(&format!("SELECT COUNT(*) FROM {} WHERE sync_state <> 'synced'", resource.table), [], |r| r.get(0))
        .map_err(db_err)?;
    Ok(n as usize)
}

fn conflict_count_for(conn: &Connection, resource: &ResourceDescriptor) -> Result<usize, String> {
    let n: i64 = conn
        .query_row(&format!("SELECT COUNT(*) FROM {} WHERE sync_state = 'conflict'", resource.table), [], |r| r.get(0))
        .map_err(db_err)?;
    Ok(n as usize)
}

fn stamps_for(conn: &Connection, resource: &ResourceDescriptor) -> rusqlite::Result<(Option<String>, Option<String>)> {
    conn.query_row("SELECT last_pull_at, last_push_at FROM sync_state_kv WHERE resource = ?1", params![resource.key], |r| {
        Ok((r.get(0)?, r.get(1)?))
    })
    .optional()
    .map(|o| o.unwrap_or((None, None)))
}

/// ISO-8601 timestamps compare lexically; `None` loses to any `Some`.
fn later_iso(a: Option<String>, b: Option<String>) -> Option<String> {
    match (a, b) {
        (Some(a), Some(b)) => Some(if b > a { b } else { a }),
        (Some(a), None) => Some(a),
        (None, Some(b)) => Some(b),
        (None, None) => None,
    }
}

pub fn unsynced_count(conn: &Connection, resources: &[&ResourceDescriptor]) -> Result<usize, String> {
    let mut total = 0;
    for r in resources {
        total += unsynced_count_for(conn, r)?;
    }
    Ok(total)
}

/// A point-in-time status snapshot summed across `resources` — the single
/// implementation `commands::sync::get_sync_status` and
/// `scheduler::run_cycle` both now call, replacing what used to be three
/// independent copies of the same query.
pub fn read_status(conn: &Connection, resources: &[&ResourceDescriptor]) -> Result<SyncStatusView, String> {
    let mut unsynced = 0usize;
    let mut conflicts = 0usize;
    let mut last_pull_at: Option<String> = None;
    let mut last_push_at: Option<String> = None;
    for r in resources {
        unsynced += unsynced_count_for(conn, r)?;
        conflicts += conflict_count_for(conn, r)?;
        let (pull, push) = stamps_for(conn, r).map_err(db_err)?;
        last_pull_at = later_iso(last_pull_at, pull);
        last_push_at = later_iso(last_push_at, push);
    }
    Ok(SyncStatusView { unsynced_count: unsynced, conflict_count: conflicts, last_pull_at, last_push_at })
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

        let uuids: Vec<String> =
            select_pending(&conn, &resource::DEMO_CATALOG_ITEMS).unwrap().into_iter().map(|r| r.client_uuid).collect();
        assert!(uuids.contains(&"due-null".to_string()));
        assert!(uuids.contains(&"due-past".to_string()));
        assert!(!uuids.contains(&"not-due".to_string()), "a future next_attempt_at is skipped");
    }

    #[test]
    fn select_pending_captures_declared_domain_columns() {
        let conn = migrated();
        insert_pending(&conn, "u1");
        let rows = select_pending(&conn, &resource::DEMO_CATALOG_ITEMS).unwrap();
        assert_eq!(rows.len(), 1);
        assert_eq!(rows[0].domain.get("name").and_then(|v| v.as_str()), Some("n"));
        assert_eq!(rows[0].domain.get("status").and_then(|v| v.as_str()), Some("active"));
    }

    #[test]
    fn push_backs_off_on_network_failure_without_aborting_the_cycle() {
        let conn = migrated();
        insert_pending(&conn, "a");
        insert_pending(&conn, "b");
        // A closed local port → connection refused → a Retryable network error.
        let client = reqwest::blocking::Client::builder().connect_timeout(std::time::Duration::from_secs(3)).build().unwrap();

        let (pushed, conflicts) =
            push_pending(&conn, &client, "http://127.0.0.1:1/api/v1", Some("tok"), &resource::DEMO_CATALOG_ITEMS).unwrap();
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

    #[test]
    fn read_status_sums_across_resources_and_takes_the_later_stamp() {
        let conn = migrated();
        insert_pending(&conn, "a");
        stamp(&conn, &resource::DEMO_CATALOG_ITEMS, "last_pull_at").unwrap();
        let status = read_status(&conn, resource::RESOURCES).unwrap();
        assert_eq!(status.unsynced_count, 1);
        assert!(status.last_pull_at.is_some());
        assert_eq!(status.last_push_at, None);
    }

    #[test]
    fn later_iso_picks_the_lexically_greater_timestamp() {
        assert_eq!(later_iso(Some("2024-01-01T00:00:00Z".into()), Some("2024-06-01T00:00:00Z".into())), Some("2024-06-01T00:00:00Z".into()));
        assert_eq!(later_iso(None, Some("x".into())), Some("x".into()));
        assert_eq!(later_iso(Some("x".into()), None), Some("x".into()));
        assert_eq!(later_iso(None, None), None);
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
        Config::resolve()
    }

    fn token(client: &Client, cfg: &Config) -> String {
        match api::login(client, cfg, "admin@example.com", "admin123").expect("login") {
            LoginOutcome::Session { access_token } => {
                let dev = api::register_device(client, cfg, &access_token, "sync-it", cfg.platform).expect("device enroll");
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

    fn domain(name: &str, status: &str) -> serde_json::Map<String, serde_json::Value> {
        let mut m = serde_json::Map::new();
        m.insert("name".to_string(), serde_json::Value::String(name.to_string()));
        m.insert("description".to_string(), serde_json::Value::Null);
        m.insert("status".to_string(), serde_json::Value::String(status.to_string()));
        m
    }

    #[test]
    #[ignore = "requires a live PR-A2 backend (WHITY_BACKEND_URL must be set)"]
    fn pushes_pulls_and_detects_conflicts() {
        let cfg = explicit_backend();
        let client = api::build_client().unwrap();
        let tok = token(&client, &cfg);
        let api_base = cfg.api_base();
        let resource = &resource::DEMO_CATALOG_ITEMS;

        // --- PUSH: a locally-created item reaches the server ---
        let db1 = local_db();
        let uuid = Uuid::new_v4().to_string();
        insert_local_pending(&db1, &uuid, "Engine IT");

        let s1 = sync_cycle(&db1, &client, &api_base, &tok).unwrap();
        assert!(s1.pushed >= 1, "the pending create should push");
        let (server_id, state, base_v): (Option<i64>, String, i64) = db1
            .query_row("SELECT server_id, sync_state, base_version FROM demo_catalog_items WHERE client_uuid = ?1", [&uuid], |r| {
                Ok((r.get(0)?, r.get(1)?, r.get(2)?))
            })
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
        match http::update(&client, &api_base, Some(&tok), resource.base_path, server_id, 1, &domain("Server edit", "active")).unwrap() {
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
        let cstate: String =
            db1.query_row("SELECT sync_state FROM demo_catalog_items WHERE client_uuid = ?1", [&uuid], |r| r.get(0)).unwrap();
        assert_eq!(cstate, "conflict");
        let (mine, theirs): (String, String) = db1
            .query_row("SELECT mine_json, theirs_json FROM item_conflicts WHERE client_uuid = ?1", [&uuid], |r| {
                Ok((r.get(0)?, r.get(1)?))
            })
            .unwrap();
        assert!(mine.contains("Local edit"), "mine snapshot: {mine}");
        assert!(theirs.contains("Server edit"), "theirs snapshot: {theirs}");

        // --- RESOLVE: apply a merge, re-sync; the merged result reaches the server ---
        let mut merged = serde_json::Map::new();
        merged.insert("name".to_string(), serde_json::Value::String("Merged name".to_string()));
        merged.insert("status".to_string(), serde_json::Value::String("active".to_string()));
        assert!(crate::db::conflicts_repo::resolve(&db1, resource, &uuid, &merged).unwrap());
        let _ = sync_cycle(&db1, &client, &api_base, &tok).unwrap();
        let (rstate, rname, rbase): (String, String, i64) = db1
            .query_row("SELECT sync_state, name, base_version FROM demo_catalog_items WHERE client_uuid = ?1", [&uuid], |r| {
                Ok((r.get(0)?, r.get(1)?, r.get(2)?))
            })
            .unwrap();
        assert_eq!(rstate, "synced");
        assert_eq!(rname, "Merged name");
        assert!(rbase >= 3, "version advanced past the server's v2 after pushing the merge");
        let conflicts_left: i64 =
            db1.query_row("SELECT COUNT(*) FROM item_conflicts WHERE client_uuid = ?1", [&uuid], |r| r.get(0)).unwrap();
        assert_eq!(conflicts_left, 0, "the conflict is cleared after resolve + sync");
    }
}
