//! Resource descriptors (WC-sync-generalize): the engine (`engine.rs`/`http.rs`)
//! no longer hardcodes "DemoCatalog" anywhere — every push/pull/conflict
//! function is parameterized by one of these. A descriptor is a static,
//! compile-time fact (this template is meant to be forked and extended, not
//! configured at runtime), and independently converges on the same shape the
//! server-side `Whity\Sdk\Sync\SyncableResource` interface settled on
//! (`table()`/`domainColumns()`).
//!
//! Adding a second native syncable resource: add an entry to `RESOURCES`, a
//! migration creating its table (same sync-identity column set
//! `demo_catalog_items` already has), a `commands/<resource>.rs` CRUD surface,
//! and a frontend adapter — no other engine change needed.

/// One resource the device sync engine pushes/pulls against ITS OWN local
/// SQLite table. Table/column names are trusted, code-defined constants,
/// string-interpolated into SQL (SQLite has no parameterized-identifier
/// syntax) — `every_resource_identifier_is_sql_safe` below guards that trust.
#[derive(Debug, Clone, Copy)]
pub struct ResourceDescriptor {
    /// Stable partition key into `sync_state_kv` / `item_conflicts.resource`.
    pub key: &'static str,
    /// Local table name. Must carry the sync-identity columns every syncable
    /// table adopts: `client_uuid`, `server_id`, `base_version`, `sync_state`,
    /// `dirty`, `deleted`, `updated_at_local`, `updated_by`, `push_attempts`,
    /// `next_attempt_at`, `last_push_error` (see `demo_catalog_items` in
    /// `db::migrations` for the reference shape).
    pub table: &'static str,
    /// Server REST base path relative to `api_base`, e.g. "/demo-catalog/items".
    pub base_path: &'static str,
    /// Domain (non-sync-metadata) column names, in stable projection order.
    pub domain_columns: &'static [&'static str],
}

pub static DEMO_CATALOG_ITEMS: ResourceDescriptor = ResourceDescriptor {
    key: "demo-catalog/items",
    table: "demo_catalog_items",
    base_path: "/demo-catalog/items",
    domain_columns: &["name", "description", "status"],
};

/// Every native (device-table-backed) syncable resource this app ships.
pub static RESOURCES: &[&ResourceDescriptor] = &[&DEMO_CATALOG_ITEMS];

pub fn find(key: &str) -> Option<&'static ResourceDescriptor> {
    RESOURCES.iter().copied().find(|r| r.key == key)
}

/// A resource identifier is safe to interpolate into SQL / a URL path
/// segment: lowercase ascii, digits, underscore, starting with a letter or
/// underscore. Mirrors the identifier check the (unmerged) server-side
/// `SyncController` applies for the same reason.
pub fn is_sql_safe_identifier(s: &str) -> bool {
    let mut chars = s.chars();
    match chars.next() {
        Some(c) if c.is_ascii_lowercase() || c == '_' => {}
        _ => return false,
    }
    chars.all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_')
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_resource_identifier_is_sql_safe() {
        for r in RESOURCES {
            assert!(is_sql_safe_identifier(r.table), "table {:?} must be SQL-safe", r.table);
            for col in r.domain_columns {
                assert!(is_sql_safe_identifier(col), "column {col:?} on {:?} must be SQL-safe", r.table);
            }
        }
    }

    #[test]
    fn find_looks_up_by_key() {
        assert!(find("demo-catalog/items").is_some());
        assert!(find("does-not-exist").is_none());
    }

    #[test]
    fn identifier_safety_rejects_the_obvious_bad_cases() {
        assert!(is_sql_safe_identifier("demo_catalog_items"));
        assert!(is_sql_safe_identifier("_private"));
        assert!(!is_sql_safe_identifier(""));
        assert!(!is_sql_safe_identifier("Items"));
        assert!(!is_sql_safe_identifier("items; DROP TABLE x"));
        assert!(!is_sql_safe_identifier("items-table"));
        assert!(!is_sql_safe_identifier("1items"));
    }
}
