//! The offline-first SYNC ENGINE (WC-desktop-sync), generalized to any
//! `resource::ResourceDescriptor` (WC-sync-generalize) rather than hardcoded
//! to DemoCatalog:
//!   - PUSH: every locally `dirty` row is created (idempotent on `client_uuid`),
//!     updated (optimistic `baseVersion`), or soft-deleted on the server;
//!   - PULL: the server changes feed (`?updatedSince=<cursor>`) is applied to
//!     local, advancing a persisted cursor;
//!   - CONFLICT: a push 409 or a pull that finds the server ahead of a locally
//!     dirty row parks a field-level snapshot in `item_conflicts` and marks the
//!     row `conflict` (see `db::conflicts_repo`).
//!
//! HTTP is blocking (`reqwest`) and Rust-side, reusing the access token from
//! `AuthManager`; the engine holds no async runtime. `engine::sync_cycle` is
//! the entry point, invoked by `sync::scheduler`'s background loop.

pub mod bridge;
pub mod engine;
pub mod http;
pub mod resource;
pub mod scheduler;
pub mod sql_value;

#[cfg(test)]
mod generic_resource_tests;

use serde::{Deserialize, Serialize};

/// A syncable row as the wire carries it (camelCase), generalized across
/// resources: the sync-metadata fields stay strongly typed, and a resource's
/// domain fields (e.g. DemoCatalog's `name`/`description`/`status`) flatten
/// into `domain` — the set of keys is whatever `ResourceDescriptor::domain_columns`
/// declares, not fixed at compile time.
///
/// LOCKED to the server's `Whity\Sdk\Sync\SyncableResource` item shape
/// (confirmed with the backend agent, WC-sync-generalize coordination):
/// `{ id, tenantId, clientUuid, version, deletedAt, updatedBy, createdAt,
/// updatedAt, ...domainFields }`. Every field here MUST have an explicit,
/// typed home — `tenant_id` in particular must stay a real field, not fall
/// through to `domain`: `sync::bridge` forwards `domain` VERBATIM to the
/// other side's create/update body (no `domain_columns` allowlist there, by
/// design — see bridge.rs), so any sync-metadata field missing from this
/// struct would leak into an outgoing request body instead of being read
/// (and safely dropped) here. `tenant_id` is read-only/response-only: the
/// wire contract's `POST`/`PATCH` bodies never send it — the server always
/// derives tenant from the authenticated caller, never from client input.
#[derive(Debug, Clone, Deserialize, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct SyncRow {
    pub id: i64,
    pub tenant_id: Option<i64>,
    pub client_uuid: Option<String>,
    pub version: i64,
    pub deleted_at: Option<String>,
    pub updated_by: Option<i64>,
    pub created_at: Option<String>,
    pub updated_at: Option<String>,
    #[serde(flatten)]
    pub domain: serde_json::Map<String, serde_json::Value>,
}

impl SyncRow {
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

/// A point-in-time view of local sync state (for `get_sync_status`), summed
/// across every resource in `resource::RESOURCES`.
#[derive(Debug, Default, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct SyncStatusView {
    pub unsynced_count: usize,
    pub conflict_count: usize,
    pub last_pull_at: Option<String>,
    pub last_push_at: Option<String>,
}
