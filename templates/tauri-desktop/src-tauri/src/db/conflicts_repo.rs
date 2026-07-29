//! Reading + resolving parked sync conflicts (WC-desktop-sync). The sync engine
//! writes `item_conflicts` (mine/theirs snapshots) when a push 409s or a pull
//! finds the server ahead of a dirty row; this module reads them for the UI and
//! applies the user's resolution.

use rusqlite::{params, Connection};
use serde::Serialize;

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
/// keys back).
#[derive(Serialize, Debug)]
#[serde(rename_all = "camelCase")]
pub struct ConflictView {
    pub id: i64,
    pub client_uuid: String,
    pub title: Option<String>,
    pub fields: Vec<FieldDiff>,
}

/// The user-content fields we diff/merge (name/description/status).
const FIELDS: [&str; 3] = ["name", "description", "status"];

/// List all parked conflicts, each with the fields whose mine/theirs differ.
pub fn list(conn: &Connection) -> rusqlite::Result<Vec<ConflictView>> {
    let mut stmt = conn.prepare(
        "SELECT c.client_uuid, c.mine_json, c.theirs_json, i.id, i.name
         FROM item_conflicts c
         JOIN demo_catalog_items i ON i.client_uuid = c.client_uuid
         ORDER BY c.detected_at ASC",
    )?;
    let rows = stmt
        .query_map([], |r| {
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
        for field in FIELDS {
            let m = string_field(&mine, field);
            let t = string_field(&theirs, field);
            if m != t {
                fields.push(FieldDiff { field: field.to_string(), mine: m, theirs: t });
            }
        }
        out.push(ConflictView { id, client_uuid, title, fields });
    }
    Ok(out)
}

/// Apply a resolution: set the item's fields to the resolved values, REBASE onto
/// the server version (so it's no longer stale), mark it dirty/pending so the
/// next sync pushes the merged result, and clear the conflict. Returns false if
/// there was no such parked conflict.
pub fn resolve(
    conn: &Connection,
    client_uuid: &str,
    name: &str,
    description: Option<&str>,
    status: &str,
) -> rusqlite::Result<bool> {
    let server_version: Option<i64> = conn
        .query_row(
            "SELECT server_version FROM item_conflicts WHERE client_uuid = ?1",
            params![client_uuid],
            |r| r.get(0),
        )
        .ok();
    let Some(server_version) = server_version else {
        return Ok(false);
    };

    let now = "strftime('%Y-%m-%dT%H:%M:%fZ','now')";
    conn.execute(
        &format!(
            "UPDATE demo_catalog_items
             SET name = ?1, description = ?2, status = ?3,
                 base_version = ?4, dirty = 1, sync_state = 'pending', updated_at_local = {now}
             WHERE client_uuid = ?5"
        ),
        params![name, description, status, server_version, client_uuid],
    )?;
    conn.execute(
        "DELETE FROM item_conflicts WHERE client_uuid = ?1",
        params![client_uuid],
    )?;
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
            "INSERT INTO item_conflicts (client_uuid, base_version, server_version, mine_json, theirs_json)
             VALUES (?1, 1, 2, ?2, ?3)",
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

        let conflicts = list(&conn).unwrap();
        assert_eq!(conflicts.len(), 1);
        let c = &conflicts[0];
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

        assert!(resolve(&conn, &uuid, "Merged name", Some("local desc"), "archived").unwrap());

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

        let remaining: i64 = conn
            .query_row("SELECT COUNT(*) FROM item_conflicts", [], |r| r.get(0))
            .unwrap();
        assert_eq!(remaining, 0, "the conflict is cleared");

        // Resolving an unknown conflict is a no-op false.
        assert!(!resolve(&conn, "nope", "x", None, "active").unwrap());
    }
}
