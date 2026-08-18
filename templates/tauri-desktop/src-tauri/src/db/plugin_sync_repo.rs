//! Bookkeeping for the last automatic plugin reconcile pass (WC-plugin-sync)
//! — the singleton `plugin_sync_state` row (v8 migration), read by the
//! Plugins page to show "last synced at" and any per-plugin failures without
//! needing to trigger a fresh sync to answer that. Written by
//! `commands::post_login` after every `plugins::reconcile::reconcile()` call.

use rusqlite::{params, Connection};
use serde::Serialize;

use crate::plugins::reconcile::{PluginSyncFailure, ReconcileOutcome};

#[derive(Serialize, Debug, PartialEq)]
#[serde(rename_all = "camelCase")]
pub struct PluginSyncStatus {
    /// Epoch seconds of the last successful reconcile pass; `None` if one
    /// has never completed (a catalog-fetch failure does NOT update this —
    /// see `record_failure`).
    pub last_synced_at: Option<i64>,
    pub last_installed: i64,
    pub last_updated: i64,
    pub last_removed: i64,
    pub last_failed: Vec<PluginSyncFailure>,
    /// The most recent HARD failure (the catalog fetch itself failing —
    /// nothing to converge against). Cleared on the next successful pass.
    pub last_error: Option<String>,
}

pub fn status(conn: &Connection) -> rusqlite::Result<PluginSyncStatus> {
    conn.query_row(
        "SELECT last_synced_at, last_installed, last_updated, last_removed, last_failed_json, last_error
         FROM plugin_sync_state WHERE id = 1",
        [],
        |r| {
            let failed_json: String = r.get(4)?;
            let last_failed: Vec<PluginSyncFailure> = serde_json::from_str(&failed_json).unwrap_or_default();
            Ok(PluginSyncStatus {
                last_synced_at: r.get(0)?,
                last_installed: r.get(1)?,
                last_updated: r.get(2)?,
                last_removed: r.get(3)?,
                last_failed,
                last_error: r.get(5)?,
            })
        },
    )
}

/// Record a completed reconcile pass (individual per-plugin failures are
/// part of `outcome.failed`, not a hard error — the pass as a whole still
/// "succeeded" in the sense that it ran to completion). Clears any
/// previously recorded hard error.
pub fn record_success(conn: &Connection, now_epoch: i64, outcome: &ReconcileOutcome) -> rusqlite::Result<()> {
    let failed_json = serde_json::to_string(&outcome.failed).unwrap_or_else(|_| "[]".to_string());
    conn.execute(
        "UPDATE plugin_sync_state
         SET last_synced_at = ?1, last_installed = ?2, last_updated = ?3, last_removed = ?4,
             last_failed_json = ?5, last_error = NULL
         WHERE id = 1",
        params![
            now_epoch,
            outcome.installed.len() as i64,
            outcome.updated.len() as i64,
            outcome.removed.len() as i64,
            failed_json,
        ],
    )?;
    Ok(())
}

/// Record a HARD failure (the catalog fetch itself failed — nothing to
/// converge against). Deliberately does NOT touch `last_synced_at`/the
/// install-update-remove counts/`last_failed_json`: those describe the last
/// pass that actually RAN, and a fetch failure means no pass ran this time.
pub fn record_failure(conn: &Connection, message: &str) -> rusqlite::Result<()> {
    conn.execute("UPDATE plugin_sync_state SET last_error = ?1 WHERE id = 1", params![message])?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::plugins::{reconcile::PluginSyncFailure, InstallOutcome};

    fn migrated() -> Connection {
        let conn = Connection::open_in_memory().unwrap();
        crate::db::migrations::run(&conn).unwrap();
        conn
    }

    #[test]
    fn starts_empty_then_records_success() {
        let conn = migrated();
        let s0 = status(&conn).unwrap();
        assert_eq!(s0.last_synced_at, None);
        assert!(s0.last_failed.is_empty());

        let outcome = ReconcileOutcome {
            installed: vec![InstallOutcome { name: "HelloWorld".into(), version: "1.0.0".into() }],
            updated: vec![],
            removed: vec!["Retired".into()],
            failed: vec![PluginSyncFailure { name: "Broken".into(), message: "checksum mismatch".into() }],
        };
        record_success(&conn, 1_700_000_000, &outcome).unwrap();

        let s1 = status(&conn).unwrap();
        assert_eq!(s1.last_synced_at, Some(1_700_000_000));
        assert_eq!(s1.last_installed, 1);
        assert_eq!(s1.last_removed, 1);
        assert_eq!(s1.last_failed.len(), 1);
        assert_eq!(s1.last_failed[0].name, "Broken");
        assert_eq!(s1.last_error, None);
    }

    #[test]
    fn failure_records_error_without_disturbing_prior_success() {
        let conn = migrated();
        let outcome = ReconcileOutcome {
            installed: vec![InstallOutcome { name: "HelloWorld".into(), version: "1.0.0".into() }],
            updated: vec![],
            removed: vec![],
            failed: vec![],
        };
        record_success(&conn, 1_700_000_000, &outcome).unwrap();

        record_failure(&conn, "network error reaching the server").unwrap();

        let s = status(&conn).unwrap();
        assert_eq!(s.last_synced_at, Some(1_700_000_000), "prior successful pass's timestamp is untouched");
        assert_eq!(s.last_installed, 1);
        assert_eq!(s.last_error.as_deref(), Some("network error reaching the server"));
    }
}
