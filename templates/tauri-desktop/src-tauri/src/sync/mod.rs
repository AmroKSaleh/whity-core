//! The offline-first SYNC ENGINE (WC-desktop-sync).
//!
//! Reconciles the local SQLite store against the server's DemoCatalog sync API:
//!   - PUSH: every locally `dirty` row is created (idempotent on `client_uuid`),
//!     updated (optimistic `baseVersion`), or soft-deleted on the server;
//!   - PULL: the server changes feed (`?updatedSince=<cursor>`) is applied to
//!     local, advancing a persisted cursor;
//!   - CONFLICT: a push 409 or a pull that finds the server ahead of a locally
//!     dirty row parks a field-level snapshot in `item_conflicts` and marks the
//!     row `conflict` (the resolver — a later PR — consumes these).
//!
//! HTTP is blocking (`reqwest`) and Rust-side, reusing the access token from
//! `AuthManager`; the engine holds no async runtime (a background scheduler +
//! backoff is a later hardening PR). `engine::sync_cycle` is the entry point,
//! invoked by the `sync_now` command.

pub mod engine;
pub mod http;

use serde::{Deserialize, Serialize};

/// The resource key under which the pull cursor is stored in `sync_state_kv`.
pub const DEMO_CATALOG_RESOURCE: &str = "demo-catalog/items";

/// A DemoCatalog item as the server returns it (camelCase over the wire),
/// mirroring the backend's `toPublicItem`.
#[derive(Debug, Clone, Deserialize, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ServerItem {
    pub id: i64,
    pub client_uuid: Option<String>,
    pub name: String,
    pub description: Option<String>,
    pub status: String,
    pub version: i64,
    pub deleted_at: Option<String>,
    pub updated_by: Option<i64>,
    pub created_at: Option<String>,
    pub updated_at: Option<String>,
}

impl ServerItem {
    pub fn is_deleted(&self) -> bool {
        self.deleted_at.is_some()
    }
}

/// What one `sync_now` accomplished — surfaced to the UI.
#[derive(Debug, Default, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct SyncSummary {
    pub pushed: usize,
    pub pulled: usize,
    pub conflicts: usize,
    pub unsynced_count: usize,
}

/// A point-in-time view of local sync state (for `get_sync_status`).
#[derive(Debug, Default, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct SyncStatusView {
    pub unsynced_count: usize,
    pub conflict_count: usize,
    pub last_pull_at: Option<String>,
    pub last_push_at: Option<String>,
}
