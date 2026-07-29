//! Mid-edit DRAFT store repo (WC-desktop-sync): cheap autosave of in-progress
//! edits to `item_drafts`, DISTINCT from committed rows and never synced. The
//! frontend can autosave a form on change (keyed by the item's `client_uuid`,
//! or a fresh uuid for a brand-new item) and rehydrate it on reopen, so nothing
//! typed is lost across an app restart — without touching the committed record
//! or the sync queue. Discarded when the edit is committed via `save_item`.
//!
//! Functions take a raw `&Connection` (unit-testable); `commands/drafts.rs`
//! wraps them with the managed connection.

use rusqlite::{params, Connection, OptionalExtension};
use serde::{Deserialize, Serialize};

/// A persisted draft. camelCase over the IPC wire.
#[derive(Serialize, Deserialize, Debug, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct Draft {
    pub client_uuid: String,
    /// The committed row this draft edits, or `None` for a brand-new item.
    pub base_local_id: Option<i64>,
    pub name: Option<String>,
    pub description: Option<String>,
    pub status: Option<String>,
    pub updated_at: Option<String>,
}

/// What the frontend sends to autosave a draft.
#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct DraftInput {
    pub client_uuid: String,
    pub base_local_id: Option<i64>,
    pub name: Option<String>,
    pub description: Option<String>,
    pub status: Option<String>,
}

/// Upsert a draft keyed by `client_uuid`, bumping its `updated_at`, and return
/// the stored row.
pub fn upsert(conn: &Connection, input: &DraftInput) -> rusqlite::Result<Draft> {
    let now = "strftime('%Y-%m-%dT%H:%M:%fZ','now')";
    conn.execute(
        &format!(
            "INSERT INTO item_drafts (client_uuid, base_local_id, name, description, status, updated_at)
             VALUES (?1, ?2, ?3, ?4, ?5, {now})
             ON CONFLICT(client_uuid) DO UPDATE SET
                 base_local_id = excluded.base_local_id,
                 name          = excluded.name,
                 description   = excluded.description,
                 status        = excluded.status,
                 updated_at    = {now}"
        ),
        params![
            input.client_uuid,
            input.base_local_id,
            input.name,
            input.description,
            input.status
        ],
    )?;
    Ok(get(conn, &input.client_uuid)?.expect("draft row exists immediately after upsert"))
}

/// Fetch a draft by `client_uuid`, or `None` if there isn't one.
pub fn get(conn: &Connection, client_uuid: &str) -> rusqlite::Result<Option<Draft>> {
    conn.query_row(
        "SELECT client_uuid, base_local_id, name, description, status, updated_at
         FROM item_drafts WHERE client_uuid = ?1",
        params![client_uuid],
        |r| {
            Ok(Draft {
                client_uuid: r.get(0)?,
                base_local_id: r.get(1)?,
                name: r.get(2)?,
                description: r.get(3)?,
                status: r.get(4)?,
                updated_at: r.get(5)?,
            })
        },
    )
    .optional()
}

/// Delete a draft by `client_uuid`. Returns `false` if there was none.
pub fn discard(conn: &Connection, client_uuid: &str) -> rusqlite::Result<bool> {
    let changed = conn.execute(
        "DELETE FROM item_drafts WHERE client_uuid = ?1",
        params![client_uuid],
    )?;
    Ok(changed > 0)
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

    fn input(uuid: &str, name: &str) -> DraftInput {
        DraftInput {
            client_uuid: uuid.to_string(),
            base_local_id: None,
            name: Some(name.to_string()),
            description: None,
            status: Some("active".to_string()),
        }
    }

    #[test]
    fn upsert_creates_then_updates_in_place() {
        let conn = migrated();
        let created = upsert(&conn, &input("u1", "Draft A")).unwrap();
        assert_eq!(created.name.as_deref(), Some("Draft A"));
        assert!(created.updated_at.is_some());

        // Same client_uuid → updates, not a second row.
        let mut next = input("u1", "Draft A edited");
        next.description = Some("more".to_string());
        let updated = upsert(&conn, &next).unwrap();
        assert_eq!(updated.name.as_deref(), Some("Draft A edited"));
        assert_eq!(updated.description.as_deref(), Some("more"));

        let count: i64 = conn
            .query_row("SELECT COUNT(*) FROM item_drafts", [], |r| r.get(0))
            .unwrap();
        assert_eq!(count, 1);
    }

    #[test]
    fn get_returns_none_when_absent_and_discard_removes() {
        let conn = migrated();
        assert!(get(&conn, "missing").unwrap().is_none());

        upsert(&conn, &input("u2", "B")).unwrap();
        assert!(get(&conn, "u2").unwrap().is_some());

        assert!(discard(&conn, "u2").unwrap());
        assert!(get(&conn, "u2").unwrap().is_none());
        // Discarding again is a no-op.
        assert!(!discard(&conn, "u2").unwrap());
    }
}
