//! Item repo functions over a raw `&Connection` (no Tauri `State`), so the SQL
//! is unit-testable in isolation. The `#[tauri::command]`s in
//! `commands/items.rs` are thin wrappers that lock the managed connection and
//! delegate here.

use rusqlite::{params, Connection};

/// Soft-delete an item: mark it `deleted` + `dirty` and stage it as
/// `deleted_pending` so the sync engine (a later PR) propagates the deletion to
/// the server as a tombstone. `list_items`/`get_item` already filter
/// `deleted = 0`, so the row disappears from the UI immediately. Returns `false`
/// when no LIVE row had that id (already deleted, or never existed).
pub fn soft_delete(conn: &Connection, id: i64) -> rusqlite::Result<bool> {
    let now = "strftime('%Y-%m-%dT%H:%M:%fZ','now')";
    let changed = conn.execute(
        &format!(
            "UPDATE demo_catalog_items
             SET deleted = 1, dirty = 1, sync_state = 'deleted_pending', updated_at_local = {now}
             WHERE id = ?1 AND deleted = 0"
        ),
        params![id],
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

    #[test]
    fn soft_delete_hides_row_and_stages_the_deletion() {
        let conn = migrated();
        conn.execute(
            "INSERT INTO demo_catalog_items (client_uuid, name) VALUES ('u1','A')",
            [],
        )
        .unwrap();
        let id: i64 = conn
            .query_row("SELECT id FROM demo_catalog_items WHERE name='A'", [], |r| {
                r.get(0)
            })
            .unwrap();

        assert!(soft_delete(&conn, id).unwrap());

        let (deleted, sync_state, dirty): (i64, String, i64) = conn
            .query_row(
                "SELECT deleted, sync_state, dirty FROM demo_catalog_items WHERE id = ?1",
                [id],
                |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?)),
            )
            .unwrap();
        assert_eq!(deleted, 1);
        assert_eq!(sync_state, "deleted_pending");
        assert_eq!(dirty, 1);

        // A second delete is a no-op (already deleted), and an unknown id too.
        assert!(!soft_delete(&conn, id).unwrap());
        assert!(!soft_delete(&conn, 9999).unwrap());
    }
}
