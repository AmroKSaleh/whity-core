//! Reading + resolving parked sync conflicts (WC-desktop-sync), generalized
//! (WC-sync-generalize) to any `sync::resource::ResourceDescriptor`. The sync
//! engine writes `item_conflicts` (mine/theirs snapshots, keyed by
//! `(resource, client_uuid)`) when a push 409s or a pull finds the server
//! ahead of a dirty row; this module reads them for the UI and applies the
//! user's resolution.

use rusqlite::{params, Connection};
use serde::Serialize;

use crate::sync::resource::ResourceDescriptor;

/// One diverging field, shaped for the shared `FieldConflict` contract.
#[derive(Serialize, Debug, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct FieldDiff {
    pub field: String,
    pub mine: Option<String>,
    pub theirs: Option<String>,
}

/// A parked conflict, shaped for the shared `Conflict` contract (`id` is the
/// stable local item id the frontend routes on; `clientUuid` is how `resolve`
/// keys back; `resource` says which resource — and therefore which local
/// table — it belongs to, so `resolve_conflict` can look up the right
/// descriptor without the frontend needing to guess).
#[derive(Serialize, Debug)]
#[serde(rename_all = "camelCase")]
pub struct ConflictView {
    pub resource: String,
    pub id: i64,
    pub client_uuid: String,
    pub title: Option<String>,
    pub fields: Vec<FieldDiff>,
}

/// List every parked conflict across `resources`, each with the fields whose
/// mine/theirs differ.
pub fn list(conn: &Connection, resources: &[&ResourceDescriptor]) -> rusqlite::Result<Vec<ConflictView>> {
    let mut out = Vec::new();
    for r in resources {
        out.extend(list_for(conn, r)?);
    }
    Ok(out)
}

fn list_for(conn: &Connection, resource: &ResourceDescriptor) -> rusqlite::Result<Vec<ConflictView>> {
    // The item's own "title" for display purposes: the first domain column
    // (matches DemoCatalog's `name` being column 0) — a resource whose most
    // identifying field isn't first can override this by simply ordering
    // `domain_columns` accordingly.
    let Some(title_column) = resource.domain_columns.first() else {
        return Ok(Vec::new());
    };
    let sql = format!(
        "SELECT c.client_uuid, c.mine_json, c.theirs_json, i.id, i.{title_column}
         FROM item_conflicts c
         JOIN {table} i ON i.client_uuid = c.client_uuid
         WHERE c.resource = ?1
         ORDER BY c.detected_at ASC",
        title_column = title_column,
        table = resource.table,
    );
    let mut stmt = conn.prepare(&sql)?;
    let rows = stmt
        .query_map(params![resource.key], |r| {
            Ok((
                r.get::<_, String>(0)?,
                r.get::<_, String>(1)?,
                r.get::<_, String>(2)?,
                r.get::<_, i64>(3)?,
                r.get::<_, Option<String>>(4)?,
            ))
        })?
        .collect::<Result<Vec<_>, _>>()?;

    let mut out = Vec::new();
    for (client_uuid, mine_json, theirs_json, id, title) in rows {
        let mine = serde_json::from_str::<serde_json::Value>(&mine_json).unwrap_or_default();
        let theirs = serde_json::from_str::<serde_json::Value>(&theirs_json).unwrap_or_default();

        let mut fields = Vec::new();
        for field in resource.domain_columns {
            let m = string_field(&mine, field);
            let t = string_field(&theirs, field);
            if m != t {
                fields.push(FieldDiff { field: field.to_string(), mine: m, theirs: t });
            }
        }
        out.push(ConflictView { resource: resource.key.to_string(), id, client_uuid, title, fields });
    }
    Ok(out)
}

/// Apply a resolution: set the item's declared domain fields to the resolved
/// values (any field absent from `fields` is left untouched — the frontend
/// seeds non-diverging fields from the current record before calling this),
/// REBASE onto the server version (so it's no longer stale), mark it
/// dirty/pending so the next sync pushes the merged result, and clear the
/// conflict. Returns false if there was no such parked conflict.
pub fn resolve(
    conn: &Connection,
    resource: &ResourceDescriptor,
    client_uuid: &str,
    fields: &serde_json::Map<String, serde_json::Value>,
) -> rusqlite::Result<bool> {
    let server_version: Option<i64> = conn
        .query_row(
            "SELECT server_version FROM item_conflicts WHERE resource = ?1 AND client_uuid = ?2",
            params![resource.key, client_uuid],
            |r| r.get(0),
        )
        .ok();
    let Some(server_version) = server_version else {
        return Ok(false);
    };

    let present: Vec<&&str> = resource.domain_columns.iter().filter(|c| fields.contains_key(**c)).collect();
    let set_clause: String = present.iter().enumerate().map(|(i, c)| format!("{c} = ?{}", i + 1)).collect::<Vec<_>>().join(", ");
    let base_version_placeholder = present.len() + 1;
    let client_uuid_placeholder = present.len() + 2;
    let sql = format!(
        "UPDATE {table} SET {set_clause}{sep}base_version = ?{base_version_placeholder}, dirty = 1,
             sync_state = 'pending', updated_at_local = strftime('%Y-%m-%dT%H:%M:%fZ','now')
         WHERE client_uuid = ?{client_uuid_placeholder}",
        table = resource.table,
        sep = if set_clause.is_empty() { "" } else { ", " },
    );

    let domain_values: Vec<_> = present.iter().map(|c| crate::sync::sql_value::json_to_sql(fields.get(**c))).collect();
    let mut bound: Vec<&dyn rusqlite::ToSql> = domain_values.iter().map(|v| v as &dyn rusqlite::ToSql).collect();
    bound.push(&server_version);
    bound.push(&client_uuid);
    conn.execute(&sql, bound.as_slice())?;

    conn.execute("DELETE FROM item_conflicts WHERE resource = ?1 AND client_uuid = ?2", params![resource.key, client_uuid])?;
    Ok(true)
}

fn string_field(value: &serde_json::Value, field: &str) -> Option<String> {
    match value.get(field) {
        Some(serde_json::Value::String(s)) => Some(s.clone()),
        Some(serde_json::Value::Null) | None => None,
        Some(other) => Some(other.to_string()),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::sync::resource::DEMO_CATALOG_ITEMS;
    use rusqlite::Connection;

    fn migrated() -> Connection {
        let conn = Connection::open_in_memory().unwrap();
        crate::db::migrations::run(&conn).unwrap();
        conn
    }

    fn seed_conflict(conn: &Connection) -> String {
        let uuid = "c-1";
        conn.execute(
            "INSERT INTO demo_catalog_items (client_uuid, server_id, name, description, status, base_version, sync_state, dirty)
             VALUES (?1, 10, 'Local name', 'local desc', 'active', 1, 'conflict', 1)",
            params![uuid],
        )
        .unwrap();
        conn.execute(
            "INSERT INTO item_conflicts (resource, client_uuid, base_version, server_version, mine_json, theirs_json)
             VALUES ('demo-catalog/items', ?1, 1, 2, ?2, ?3)",
            params![
                uuid,
                r#"{"name":"Local name","description":"local desc","status":"active","deleted":false}"#,
                r#"{"name":"Server name","description":"local desc","status":"archived","deleted":false}"#,
            ],
        )
        .unwrap();
        uuid.to_string()
    }

    #[test]
    fn lists_only_the_diverging_fields() {
        let conn = migrated();
        seed_conflict(&conn);

        let conflicts = list(&conn, &[&DEMO_CATALOG_ITEMS]).unwrap();
        assert_eq!(conflicts.len(), 1);
        let c = &conflicts[0];
        assert_eq!(c.resource, "demo-catalog/items");
        assert_eq!(c.id, 1, "ConflictView.id is the LOCAL item id (server_id 10 is separate)");
        assert_eq!(c.client_uuid, "c-1");
        // name + status differ; description is identical → excluded.
        let mut fields: Vec<&str> = c.fields.iter().map(|f| f.field.as_str()).collect();
        fields.sort();
        assert_eq!(fields, vec!["name", "status"]);
        let name = c.fields.iter().find(|f| f.field == "name").unwrap();
        assert_eq!(name.mine.as_deref(), Some("Local name"));
        assert_eq!(name.theirs.as_deref(), Some("Server name"));
    }

    #[test]
    fn resolve_applies_values_rebases_and_clears() {
        let conn = migrated();
        let uuid = seed_conflict(&conn);

        let mut fields = serde_json::Map::new();
        fields.insert("name".to_string(), serde_json::Value::String("Merged name".to_string()));
        fields.insert("description".to_string(), serde_json::Value::String("local desc".to_string()));
        fields.insert("status".to_string(), serde_json::Value::String("archived".to_string()));
        assert!(resolve(&conn, &DEMO_CATALOG_ITEMS, &uuid, &fields).unwrap());

        let (name, status, base_version, sync_state, dirty): (String, String, i64, String, i64) = conn
            .query_row(
                "SELECT name, status, base_version, sync_state, dirty FROM demo_catalog_items WHERE client_uuid = ?1",
                params![uuid],
                |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?, r.get(4)?)),
            )
            .unwrap();
        assert_eq!(name, "Merged name");
        assert_eq!(status, "archived");
        assert_eq!(base_version, 2, "rebased onto the server version");
        assert_eq!(sync_state, "pending", "re-queued to push the merged result");
        assert_eq!(dirty, 1);

        let remaining: i64 = conn.query_row("SELECT COUNT(*) FROM item_conflicts", [], |r| r.get(0)).unwrap();
        assert_eq!(remaining, 0, "the conflict is cleared");

        // Resolving an unknown conflict is a no-op false.
        assert!(!resolve(&conn, &DEMO_CATALOG_ITEMS, "nope", &serde_json::Map::new()).unwrap());
    }

    #[test]
    fn resolve_leaves_fields_absent_from_the_map_untouched() {
        let conn = migrated();
        let uuid = seed_conflict(&conn);

        // Only "status" resolved — "name"/"description" must keep their
        // current (locally-dirty) values, not be nulled out.
        let mut fields = serde_json::Map::new();
        fields.insert("status".to_string(), serde_json::Value::String("archived".to_string()));
        assert!(resolve(&conn, &DEMO_CATALOG_ITEMS, &uuid, &fields).unwrap());

        let name: String = conn.query_row("SELECT name FROM demo_catalog_items WHERE client_uuid = ?1", params![uuid], |r| r.get(0)).unwrap();
        assert_eq!(name, "Local name", "untouched field keeps its prior value");
    }
}
