//! Bridges the two SQLite DBs — the deferred "hard gap": a plugin's OFFLINE
//! writes today live only in the PHP host's own `whity-offline.sqlite` and
//! never reach the server. Rather than touching either SQLite file directly,
//! this module treats the local PHP host as a SECOND "remote" and relays it
//! against the real server using the exact same generalized
//! create/update/delete/fetch_changes functions from `sync::http`
//! (WC-sync-generalize) — no cross-process SQLite access, no schema
//! coupling, just two HTTP legs through the identical wire contract both
//! sides already speak.
//!
//! REJECTED: `ATTACH DATABASE`. `whity-desktop.sqlite` runs WAL
//! (`db::open_at`); the PHP host's `SqliteCompatPdo` sets no journal-mode
//! pragma at all. A cross-database transaction spanning a WAL-mode DB and a
//! rollback-mode one loses atomic-commit guarantees on crash — SQLite's own
//! documented limitation. Matching journal modes would still leave two
//! independent OS processes sharing one file's `-wal`/`-shm` sidecars —
//! untested territory this app has never needed before. The HTTP relay
//! avoids all of that: it only ever talks through `php_host::proxy`, a
//! channel already shipping and already the sole way Rust reaches the PHP
//! host.
//!
//! There's no local Rust-owned table for a bridged resource — Rust only
//! remembers, per `(resource, client_uuid)`, the last-known id/version on
//! EACH side (`bridge_resource_state`, migration v10), the same optimistic-
//! concurrency primitive `create`/`update`/`delete` already use.

use reqwest::blocking::Client;
use rusqlite::{params, Connection, OptionalExtension};

use super::http::{self, WriteOutcome};
use super::SyncRow;

const PAGE_LIMIT: u32 = 200;

/// A resource relayed between the local PHP host and the real server —
/// deliberately lighter than `resource::ResourceDescriptor`: there's no
/// local table (`sync::http`'s functions only ever need a base path — see
/// its own module doc) and no domain-column list (a pure relay forwards
/// whatever `SyncRow::domain` the "from" side returned, unmodified).
pub struct BridgeResource {
    /// Partition key into `bridge_resource_state` / `bridge_cursor_kv`.
    pub key: &'static str,
    /// The REST base path, identical on both the php-host and the remote
    /// server (e.g. "/demo-catalog/items") — the whole point of the relay is
    /// that both sides speak the SAME wire contract at the SAME path.
    pub base_path: &'static str,
}

/// Populated with every plugin whose local route is expected to speak the
/// sync wire contract. Relaying against a plugin that isn't actually
/// installed on this device is a safe no-op — each HTTP call simply fails,
/// is logged, and is retried next cycle (its cursor never advances past the
/// failure) — so this can be populated ahead of confirming a resource is
/// entitled/downloadable for any given test tenant.
///
/// `demo-catalog/items` intentionally isn't here: demos are being dropped
/// from the product (see the removal of the bundled demo plugins) and the
/// backend agent has unseeded DemoCatalog@1.0.0 from the live catalog.
/// `relations/persons` (PR #818's `PersonResource`/`PersonsApiHandler`,
/// slice 1 of the Relations plugin — `display_name`, `birth_date`,
/// `deceased`, `notes`) is the real bridge target going forward, published
/// through the desktop-plugin-release pipeline like any other plugin.
pub static BRIDGE_RESOURCES: &[&BridgeResource] = &[&BridgeResource { key: "relations/persons", base_path: "/persons" }];

#[derive(Debug, Default, Clone)]
pub struct BridgeSummary {
    pub relayed: usize,
    pub conflicts: usize,
}

/// Relay every `BridgeResource` in both directions: local (php-host) ->
/// remote (server), then remote -> local. Never propagates a single
/// resource's failure — a down or not-yet-installed plugin route must not
/// block other resources or the caller's own device sync cycle; failures are
/// logged and that leg is simply retried next cycle.
pub fn relay_cycle(
    conn: &Connection,
    client: &Client,
    php_host_base: &str,
    api_base: &str,
    token: &str,
    resources: &[&BridgeResource],
) -> BridgeSummary {
    let mut summary = BridgeSummary::default();
    for r in resources {
        match relay_local_to_remote(conn, client, php_host_base, api_base, token, r) {
            Ok((relayed, conflicts)) => {
                summary.relayed += relayed;
                summary.conflicts += conflicts;
            }
            Err(e) => eprintln!("[sync::bridge] {} local->remote relay failed: {e}", r.key),
        }
        match relay_remote_to_local(conn, client, php_host_base, api_base, token, r) {
            Ok((relayed, conflicts)) => {
                summary.relayed += relayed;
                summary.conflicts += conflicts;
            }
            Err(e) => eprintln!("[sync::bridge] {} remote->local relay failed: {e}", r.key),
        }
    }
    summary
}

/// Rows changed on the LOCAL php-host side, pushed to the REMOTE server.
fn relay_local_to_remote(
    conn: &Connection,
    client: &Client,
    php_host_base: &str,
    api_base: &str,
    token: &str,
    resource: &BridgeResource,
) -> Result<(usize, usize), String> {
    let mut cursor = get_leg_cursor(conn, resource, "local_cursor").map_err(db_err)?;
    let mut relayed = 0;
    let mut conflicts = 0;

    loop {
        let page =
            http::fetch_changes(client, php_host_base, None, resource.base_path, &cursor, PAGE_LIMIT).map_err(|e| e.to_string())?;
        for item in &page.items {
            let Some(client_uuid) = item.client_uuid.clone() else { continue };
            let state = get_state(conn, resource, &client_uuid).map_err(db_err)?;
            if state.as_ref().and_then(|s| s.local_id) == Some(item.id) && state.as_ref().and_then(|s| s.local_version) == Some(item.version) {
                continue; // already relayed this exact version
            }
            match relay_one(client, api_base, Some(token), resource, item, state.as_ref().and_then(|s| s.remote_id), state.as_ref().and_then(|s| s.remote_version)) {
                Ok(RelayOutcome::Written(remote)) => {
                    upsert_state(conn, resource, &client_uuid, Some(item.id), Some(item.version), Some(remote.id), Some(remote.version)).map_err(db_err)?;
                }
                Ok(RelayOutcome::NothingToDo) => {
                    upsert_state(conn, resource, &client_uuid, Some(item.id), Some(item.version), None, None).map_err(db_err)?;
                }
                Ok(RelayOutcome::Conflict(remote)) => {
                    park_bridge_conflict(conn, resource, &client_uuid, item, &remote).map_err(db_err)?;
                    conflicts += 1;
                }
                Ok(RelayOutcome::OtherSideVanished) => {
                    upsert_state(conn, resource, &client_uuid, Some(item.id), Some(item.version), None, None).map_err(db_err)?;
                }
                Err(e) => eprintln!("[sync::bridge] {} item {client_uuid} failed to relay local->remote: {e}", resource.key),
            }
            relayed += 1;
        }
        cursor = page.cursor.clone();
        set_leg_cursor(conn, resource, "local_cursor", &cursor).map_err(db_err)?;
        if !page.has_more {
            break;
        }
    }
    Ok((relayed, conflicts))
}

/// Rows changed on the REMOTE server side, applied back to the LOCAL php-host.
fn relay_remote_to_local(
    conn: &Connection,
    client: &Client,
    php_host_base: &str,
    api_base: &str,
    token: &str,
    resource: &BridgeResource,
) -> Result<(usize, usize), String> {
    let mut cursor = get_leg_cursor(conn, resource, "remote_cursor").map_err(db_err)?;
    let mut relayed = 0;
    let mut conflicts = 0;

    loop {
        let page = http::fetch_changes(client, api_base, Some(token), resource.base_path, &cursor, PAGE_LIMIT).map_err(|e| e.to_string())?;
        for item in &page.items {
            let Some(client_uuid) = item.client_uuid.clone() else { continue };
            let state = get_state(conn, resource, &client_uuid).map_err(db_err)?;
            if state.as_ref().and_then(|s| s.remote_id) == Some(item.id) && state.as_ref().and_then(|s| s.remote_version) == Some(item.version) {
                continue; // already relayed this exact version
            }
            match relay_one(client, php_host_base, None, resource, item, state.as_ref().and_then(|s| s.local_id), state.as_ref().and_then(|s| s.local_version)) {
                Ok(RelayOutcome::Written(local)) => {
                    upsert_state(conn, resource, &client_uuid, Some(local.id), Some(local.version), Some(item.id), Some(item.version)).map_err(db_err)?;
                }
                Ok(RelayOutcome::NothingToDo) => {
                    upsert_state(conn, resource, &client_uuid, None, None, Some(item.id), Some(item.version)).map_err(db_err)?;
                }
                Ok(RelayOutcome::Conflict(local)) => {
                    park_bridge_conflict(conn, resource, &client_uuid, item, &local).map_err(db_err)?;
                    conflicts += 1;
                }
                Ok(RelayOutcome::OtherSideVanished) => {
                    upsert_state(conn, resource, &client_uuid, None, None, Some(item.id), Some(item.version)).map_err(db_err)?;
                }
                Err(e) => eprintln!("[sync::bridge] {} item {client_uuid} failed to relay remote->local: {e}", resource.key),
            }
            relayed += 1;
        }
        cursor = page.cursor.clone();
        set_leg_cursor(conn, resource, "remote_cursor", &cursor).map_err(db_err)?;
        if !page.has_more {
            break;
        }
    }
    Ok((relayed, conflicts))
}

enum RelayOutcome {
    /// The write applied on the other side; carries its resulting row.
    Written(SyncRow),
    /// The item was already a tombstone and had never been relayed — there's
    /// nothing to create on the other side.
    NothingToDo,
    /// An optimistic-concurrency mismatch on the other side; carries its
    /// current row so the caller can park a conflict.
    Conflict(SyncRow),
    /// The other side's row vanished (404) — its recorded id/version is
    /// cleared so the next cycle re-creates it there.
    OtherSideVanished,
}

/// Apply one changed row to the OTHER side: create if never relayed there,
/// else update/delete using that side's last-known id/version as the
/// optimistic-concurrency base.
fn relay_one(
    client: &Client,
    to_base: &str,
    to_token: Option<&str>,
    resource: &BridgeResource,
    item: &SyncRow,
    other_id: Option<i64>,
    other_version: Option<i64>,
) -> Result<RelayOutcome, String> {
    let Some(client_uuid) = item.client_uuid.as_deref() else {
        return Ok(RelayOutcome::NothingToDo);
    };

    let outcome = match other_id {
        None => {
            if item.is_deleted() {
                return Ok(RelayOutcome::NothingToDo);
            }
            http::create(client, to_base, to_token, resource.base_path, client_uuid, &item.domain).map(WriteOutcome::Applied)
        }
        Some(id) if item.is_deleted() => http::delete(client, to_base, to_token, resource.base_path, id, other_version.unwrap_or(0)),
        Some(id) => http::update(client, to_base, to_token, resource.base_path, id, other_version.unwrap_or(0), &item.domain),
    }
    .map_err(|e| e.to_string())?;

    Ok(match outcome {
        WriteOutcome::Applied(other) => RelayOutcome::Written(other),
        WriteOutcome::Conflict(other) => RelayOutcome::Conflict(other),
        WriteOutcome::NotFound => RelayOutcome::OtherSideVanished,
    })
}

// ---------------------------------------------------------------- state

struct BridgeState {
    local_id: Option<i64>,
    local_version: Option<i64>,
    remote_id: Option<i64>,
    remote_version: Option<i64>,
}

fn get_state(conn: &Connection, resource: &BridgeResource, client_uuid: &str) -> rusqlite::Result<Option<BridgeState>> {
    conn.query_row(
        "SELECT local_id, local_version, remote_id, remote_version FROM bridge_resource_state WHERE resource = ?1 AND client_uuid = ?2",
        params![resource.key, client_uuid],
        |r| Ok(BridgeState { local_id: r.get(0)?, local_version: r.get(1)?, remote_id: r.get(2)?, remote_version: r.get(3)? }),
    )
    .optional()
}

/// Upsert this item's known id/version on each side; `None` for a side
/// leaves its recorded value UNCHANGED (not nulled) — a caller that only
/// learned about one side passes `None` for the other.
#[allow(clippy::too_many_arguments)]
fn upsert_state(
    conn: &Connection,
    resource: &BridgeResource,
    client_uuid: &str,
    local_id: Option<i64>,
    local_version: Option<i64>,
    remote_id: Option<i64>,
    remote_version: Option<i64>,
) -> rusqlite::Result<()> {
    conn.execute(
        "INSERT INTO bridge_resource_state (resource, client_uuid, local_id, local_version, remote_id, remote_version)
         VALUES (?1, ?2, ?3, ?4, ?5, ?6)
         ON CONFLICT(resource, client_uuid) DO UPDATE SET
             local_id = COALESCE(excluded.local_id, bridge_resource_state.local_id),
             local_version = COALESCE(excluded.local_version, bridge_resource_state.local_version),
             remote_id = COALESCE(excluded.remote_id, bridge_resource_state.remote_id),
             remote_version = COALESCE(excluded.remote_version, bridge_resource_state.remote_version)",
        params![resource.key, client_uuid, local_id, local_version, remote_id, remote_version],
    )?;
    Ok(())
}

fn get_leg_cursor(conn: &Connection, resource: &BridgeResource, column: &str) -> rusqlite::Result<String> {
    // `column` is a fixed internal identifier (local_cursor | remote_cursor).
    let existing: Option<String> = conn
        .query_row(&format!("SELECT {column} FROM bridge_cursor_kv WHERE resource = ?1"), params![resource.key], |r| r.get(0))
        .ok()
        .flatten();
    match existing {
        Some(c) => Ok(c),
        None => {
            conn.execute("INSERT INTO bridge_cursor_kv (resource) VALUES (?1) ON CONFLICT(resource) DO NOTHING", params![resource.key])?;
            Ok("0".to_string())
        }
    }
}

fn set_leg_cursor(conn: &Connection, resource: &BridgeResource, column: &str, cursor: &str) -> rusqlite::Result<()> {
    conn.execute(
        &format!(
            "INSERT INTO bridge_cursor_kv (resource, {column}, last_relay_at) VALUES (?1, ?2, strftime('%Y-%m-%dT%H:%M:%fZ','now'))
             ON CONFLICT(resource) DO UPDATE SET {column} = excluded.{column}, last_relay_at = excluded.last_relay_at"
        ),
        params![resource.key, cursor],
    )?;
    Ok(())
}

/// Parks a bridge conflict under a key distinct from a native resource's own
/// conflicts (they share the `item_conflicts` table — see
/// `db::conflicts_repo`), so the UI can tell them apart. Dual-side
/// resolution (a merge that must PATCH both sides at once) is a v1.1
/// follow-up — this just surfaces the divergence; the item is retried every
/// cycle until it's resolved by hand on one side.
fn park_bridge_conflict(conn: &Connection, resource: &BridgeResource, client_uuid: &str, mine: &SyncRow, theirs: &SyncRow) -> rusqlite::Result<()> {
    let bridge_key = format!("{}@bridge", resource.key);
    let mine_json = serde_json::Value::Object(mine.domain.clone()).to_string();
    let theirs_json = serde_json::Value::Object(theirs.domain.clone()).to_string();
    conn.execute(
        "INSERT INTO item_conflicts (resource, client_uuid, base_version, server_version, mine_json, theirs_json)
         VALUES (?1, ?2, ?3, ?4, ?5, ?6)
         ON CONFLICT(resource, client_uuid) DO UPDATE SET
             base_version = excluded.base_version,
             server_version = excluded.server_version,
             mine_json = excluded.mine_json,
             theirs_json = excluded.theirs_json,
             detected_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')",
        params![bridge_key, client_uuid, mine.version, theirs.version, mine_json, theirs_json],
    )?;
    Ok(())
}

fn db_err<E: std::fmt::Display>(e: E) -> String {
    format!("local database error: {e}")
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::db::migrations;

    fn migrated() -> Connection {
        let conn = Connection::open_in_memory().unwrap();
        migrations::run(&conn).unwrap();
        conn
    }

    #[test]
    fn state_upsert_only_overwrites_the_side_that_changed() {
        let conn = migrated();
        let r = &BridgeResource { key: "test/items", base_path: "/test/items" };

        upsert_state(&conn, r, "u1", Some(1), Some(1), None, None).unwrap();
        upsert_state(&conn, r, "u1", None, None, Some(9), Some(1)).unwrap();

        let state = get_state(&conn, r, "u1").unwrap().unwrap();
        assert_eq!(state.local_id, Some(1), "local side preserved across the second (remote-only) upsert");
        assert_eq!(state.local_version, Some(1));
        assert_eq!(state.remote_id, Some(9));
        assert_eq!(state.remote_version, Some(1));
    }

    #[test]
    fn leg_cursor_starts_at_zero_and_persists() {
        let conn = migrated();
        let r = &BridgeResource { key: "test/items", base_path: "/test/items" };

        assert_eq!(get_leg_cursor(&conn, r, "local_cursor").unwrap(), "0");
        set_leg_cursor(&conn, r, "local_cursor", "42").unwrap();
        assert_eq!(get_leg_cursor(&conn, r, "local_cursor").unwrap(), "42");
        // The other leg's cursor is independent.
        assert_eq!(get_leg_cursor(&conn, r, "remote_cursor").unwrap(), "0");
    }

    #[test]
    fn bridge_conflicts_are_tagged_distinctly_from_native_ones() {
        let conn = migrated();
        let r = &BridgeResource { key: "demo-catalog/items", base_path: "/demo-catalog/items" };
        let mut mine_domain = serde_json::Map::new();
        mine_domain.insert("name".to_string(), serde_json::Value::String("Mine".to_string()));
        let mut theirs_domain = serde_json::Map::new();
        theirs_domain.insert("name".to_string(), serde_json::Value::String("Theirs".to_string()));

        let mine = SyncRow {
            id: 1,
            tenant_id: Some(1),
            client_uuid: Some("u1".to_string()),
            version: 1,
            deleted_at: None,
            updated_by: None,
            created_at: None,
            updated_at: None,
            domain: mine_domain,
        };
        let theirs = SyncRow { version: 2, domain: theirs_domain, ..mine.clone() };

        park_bridge_conflict(&conn, r, "u1", &mine, &theirs).unwrap();

        let resource_key: String = conn.query_row("SELECT resource FROM item_conflicts WHERE client_uuid = 'u1'", [], |row| row.get(0)).unwrap();
        assert_eq!(resource_key, "demo-catalog/items@bridge", "distinct from the native resource's own 'demo-catalog/items' key");
    }
}
