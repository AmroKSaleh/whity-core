//! Background sync SCHEDULER (WC-desktop-sync). Owns a dedicated SQLite
//! connection (WAL mode, so it writes without blocking the UI connection's
//! reads) and a plain OS thread that runs `engine::sync_cycle` on a cadence and
//! on demand — so sync no longer depends on the frontend polling `sync_now`.
//!
//! It:
//!   - runs a cycle at startup, on an interval, on a manual `sync_now`, and on a
//!     debounced local write (a browser reconnect nudges it via `sync_now`);
//!   - owns CONNECTIVITY truth from the credential-exchange outcome (not
//!     `navigator.onLine`), emitting `connectivity` and self-catching-up on
//!     reconnect;
//!   - resets the offline-lock clock on every successful online exchange, so a
//!     TTL-locked-but-enrolled app auto-recovers once it can reach the server
//!     (matches the B3 lock rule: past-TTL + online ⇒ try exchange ⇒ unlock);
//!   - emits `sync:status` events the frontend subscribes to instead of polling.
//!
//! HTTP is blocking (`reqwest`), so this is a std thread + an mpsc trigger
//! channel with `recv_timeout` for the interval — no async runtime.

use std::sync::mpsc::{self, Receiver, RecvTimeoutError, Sender};
use std::sync::Mutex;
use std::thread;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use reqwest::blocking::Client;
use rusqlite::{Connection, OptionalExtension};
use serde::Serialize;
use tauri::{AppHandle, Emitter};

use crate::auth::{api, credential_store, lock};
use crate::config::Config;
use crate::db::auth_repo;
use crate::sync::{engine, DEMO_CATALOG_RESOURCE};

/// What wakes the sync loop (beyond the interval tick + the startup cycle).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Trigger {
    /// A user-initiated `sync_now` (or a browser reconnect hint from the webview).
    Manual,
    /// A local write (create/update/delete/resolve) — coalesced before pushing.
    LocalWrite,
}

/// App-side handle to nudge the loop; managed in Tauri state so commands can
/// fire triggers. The `Mutex<Sender>` just makes the handle `Sync`.
pub struct SyncHandle {
    tx: Mutex<Sender<Trigger>>,
}

impl SyncHandle {
    /// Best-effort nudge — if the loop has exited (app shutting down), drop it.
    pub fn trigger(&self, trigger: Trigger) {
        if let Ok(tx) = self.tx.lock() {
            let _ = tx.send(trigger);
        }
    }
}

/// Interval between automatic cycles when healthy vs. offline (retry sooner
/// while offline so a reconnect is noticed quickly), and how long to coalesce a
/// burst of local writes before pushing.
const IDLE_INTERVAL: Duration = Duration::from_secs(45);
const OFFLINE_INTERVAL: Duration = Duration::from_secs(15);
const WRITE_DEBOUNCE: Duration = Duration::from_millis(1500);

/// `sync:status` event payload (camelCase for the webview). Everything the
/// frontend `SyncController` needs except the conflict LIST — the controller
/// fetches that via `list_conflicts` when `conflictCount > 0`.
#[derive(Serialize, Clone)]
#[serde(rename_all = "camelCase")]
struct SyncStatusEvent {
    online: bool,
    syncing: bool,
    locked: bool,
    unsynced_count: usize,
    conflict_count: usize,
    last_pull_at: Option<String>,
    last_push_at: Option<String>,
    last_error: Option<String>,
}

/// Spawn the background sync loop; returns a handle for triggering it.
pub fn spawn(app: AppHandle, cfg: Config, conn: Connection) -> Result<SyncHandle, String> {
    let client = api::build_client()?;
    let (tx, rx) = mpsc::channel::<Trigger>();
    thread::Builder::new()
        .name("whity-sync".into())
        .spawn(move || run_loop(app, cfg, client, conn, rx))
        .map_err(|e| format!("failed to start the sync thread: {e}"))?;
    Ok(SyncHandle { tx: Mutex::new(tx) })
}

fn run_loop(app: AppHandle, cfg: Config, client: Client, conn: Connection, rx: Receiver<Trigger>) {
    let mut online = true;
    // Initial paint + a startup cycle.
    run_cycle(&app, &cfg, &client, &conn, &mut online);

    loop {
        let interval = if online { IDLE_INTERVAL } else { OFFLINE_INTERVAL };
        match rx.recv_timeout(interval) {
            Ok(Trigger::LocalWrite) => {
                // Coalesce a burst of writes into a single cycle.
                thread::sleep(WRITE_DEBOUNCE);
                while rx.try_recv().is_ok() {}
                run_cycle(&app, &cfg, &client, &conn, &mut online);
            }
            Ok(Trigger::Manual) => run_cycle(&app, &cfg, &client, &conn, &mut online),
            Err(RecvTimeoutError::Timeout) => run_cycle(&app, &cfg, &client, &conn, &mut online),
            // Every Sender dropped → the app is shutting down.
            Err(RecvTimeoutError::Disconnected) => return,
        }
    }
}

fn run_cycle(app: &AppHandle, cfg: &Config, client: &Client, conn: &Connection, online: &mut bool) {
    // Only sync an enrolled device — otherwise there's no credential to exchange.
    match auth_repo::status(conn) {
        Ok(s) if s.enrolled => {}
        Ok(_) => return emit_status(app, conn, *online, false, None),
        Err(_) => return, // DB not ready yet; try again next tick.
    }
    let credential = match credential_store::load() {
        Ok(Some(c)) => c,
        _ => return emit_status(app, conn, *online, false, None),
    };

    emit_status(app, conn, *online, true, None); // syncing = true

    // The credential exchange doubles as the connectivity probe: success ⇒ online
    // (and resets the offline-lock clock so a past-TTL app unlocks), a *network*
    // failure ⇒ offline. A non-network failure (e.g. 401 expired credential) is
    // surfaced but does not flip connectivity — the user must re-enroll.
    let session = match api::exchange(client, cfg, &credential) {
        Ok(s) => s,
        Err(e) => {
            let flipped = is_network_error(&e) && *online;
            if is_network_error(&e) {
                *online = false;
            }
            emit_status(app, conn, *online, false, Some(e));
            if flipped {
                let _ = app.emit("connectivity", false);
            }
            return;
        }
    };
    let _ = auth_repo::record_online_auth(conn, now_epoch(), session.desktop_login_max_seconds);

    let reconnected = !*online;
    *online = true;
    if reconnected {
        let _ = app.emit("connectivity", true);
    }

    // One reconciliation. Errors leave rows pending (the engine's per-row backoff
    // handles transient failures); surface the message but stay online — the
    // exchange already proved we can reach the server.
    let last_error = engine::sync_cycle(conn, client, &cfg.api_base(), &session.access_token).err();
    emit_status(app, conn, *online, false, last_error);
}

fn emit_status(
    app: &AppHandle,
    conn: &Connection,
    online: bool,
    syncing: bool,
    last_error: Option<String>,
) {
    let (unsynced_count, conflict_count, last_pull_at, last_push_at) = read_counts(conn);
    let locked = auth_repo::status(conn)
        .map(|s| lock::evaluate(&s, now_epoch()).locked)
        .unwrap_or(false);
    let _ = app.emit(
        "sync:status",
        SyncStatusEvent {
            online,
            syncing,
            locked,
            unsynced_count,
            conflict_count,
            last_pull_at,
            last_push_at,
            last_error,
        },
    );
}

/// (unsynced, conflicts, last_pull_at, last_push_at) — mirrors `get_sync_status`.
fn read_counts(conn: &Connection) -> (usize, usize, Option<String>, Option<String>) {
    let unsynced: i64 = conn
        .query_row(
            "SELECT COUNT(*) FROM demo_catalog_items WHERE sync_state <> 'synced'",
            [],
            |r| r.get(0),
        )
        .unwrap_or(0);
    let conflicts: i64 = conn
        .query_row(
            "SELECT COUNT(*) FROM demo_catalog_items WHERE sync_state = 'conflict'",
            [],
            |r| r.get(0),
        )
        .unwrap_or(0);
    let stamps: Option<(Option<String>, Option<String>)> = conn
        .query_row(
            "SELECT last_pull_at, last_push_at FROM sync_state_kv WHERE resource = ?1",
            [DEMO_CATALOG_RESOURCE],
            |r| Ok((r.get(0)?, r.get(1)?)),
        )
        .optional()
        .unwrap_or(None);
    let (last_pull_at, last_push_at) = stamps.unwrap_or((None, None));
    (unsynced as usize, conflicts as usize, last_pull_at, last_push_at)
}

/// Classify an exchange error as a connectivity failure vs. a server/auth one
/// (see `auth::api`'s `net_err` / `parse_or_err` message shapes).
fn is_network_error(msg: &str) -> bool {
    let m = msg.to_ascii_lowercase();
    m.contains("network") || m.contains("reaching the server") || m.contains("failed to read response")
}

fn now_epoch() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn classifies_network_vs_server_errors() {
        assert!(is_network_error("network error reaching the server: connect timed out"));
        assert!(is_network_error("credential exchange: failed to read response: eof"));
        assert!(!is_network_error("credential exchange failed (401): credential expired"));
        assert!(!is_network_error("unauthorized (session expired); re-authenticate"));
    }

    #[test]
    fn offline_interval_is_shorter_than_idle() {
        // Retry sooner while offline so a reconnect is noticed quickly.
        assert!(OFFLINE_INTERVAL < IDLE_INTERVAL);
    }

    #[test]
    fn read_counts_reflects_pending_and_conflict_rows() {
        let conn = Connection::open_in_memory().unwrap();
        crate::db::migrations::run(&conn).unwrap();
        conn.execute(
            "INSERT INTO demo_catalog_items (client_uuid, name, status, sync_state, dirty)
             VALUES ('u1','a','active','pending',1),
                    ('u2','b','active','conflict',1),
                    ('u3','c','active','synced',0)",
            [],
        )
        .unwrap();
        let (unsynced, conflicts, pull, push) = read_counts(&conn);
        assert_eq!(unsynced, 2, "pending + conflict are both unsynced");
        assert_eq!(conflicts, 1);
        assert_eq!((pull, push), (None, None), "no sync has stamped a cursor yet");
    }
}
