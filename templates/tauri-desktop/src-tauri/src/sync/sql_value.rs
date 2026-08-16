//! JSON <-> SQLite value conversion for a resource's DOMAIN columns
//! (WC-sync-generalize). Sync-metadata columns (id/client_uuid/version/...)
//! stay strongly typed in `SyncRow`; only the per-resource domain fields need
//! this, since their shape varies per `ResourceDescriptor`.
//!
//! Deliberately narrow (v1 limitation, matches what DemoCatalog needs today):
//! null / bool / integer / float / string round-trip; array/object/blob do
//! not — a resource declaring such a domain column is out of scope until a
//! real need arises.

use rusqlite::types::{ToSqlOutput, Value as SqlValue, ValueRef};
use rusqlite::Row;
use serde_json::Value as JsonValue;

pub fn json_to_sql(v: Option<&JsonValue>) -> ToSqlOutput<'static> {
    let sql_value = match v {
        None | Some(JsonValue::Null) => SqlValue::Null,
        Some(JsonValue::Bool(b)) => SqlValue::Integer(*b as i64),
        Some(JsonValue::Number(n)) => {
            if let Some(i) = n.as_i64() {
                SqlValue::Integer(i)
            } else if let Some(f) = n.as_f64() {
                SqlValue::Real(f)
            } else {
                SqlValue::Null
            }
        }
        Some(JsonValue::String(s)) => SqlValue::Text(s.clone()),
        // Arrays/objects aren't a supported domain-column shape (see module
        // doc) — store NULL rather than silently stringifying, so a caller
        // that tries this notices via a missing value, not corrupted data.
        Some(JsonValue::Array(_)) | Some(JsonValue::Object(_)) => SqlValue::Null,
    };
    ToSqlOutput::Owned(sql_value)
}

pub fn sql_to_json(row: &Row, idx: usize) -> rusqlite::Result<JsonValue> {
    match row.get_ref(idx)? {
        ValueRef::Null => Ok(JsonValue::Null),
        ValueRef::Integer(i) => Ok(JsonValue::from(i)),
        ValueRef::Real(f) => Ok(JsonValue::from(f)),
        ValueRef::Text(t) => Ok(JsonValue::String(String::from_utf8_lossy(t).into_owned())),
        ValueRef::Blob(_) => Ok(JsonValue::Null),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use rusqlite::Connection;

    #[test]
    fn round_trips_each_supported_json_type() {
        let conn = Connection::open_in_memory().unwrap();
        conn.execute_batch("CREATE TABLE t (a, b, c, d, e)").unwrap();
        conn.execute(
            "INSERT INTO t (a,b,c,d,e) VALUES (?1,?2,?3,?4,?5)",
            rusqlite::params![
                json_to_sql(Some(&JsonValue::from(true))),
                json_to_sql(Some(&JsonValue::from(42))),
                json_to_sql(Some(&JsonValue::from(1.5))),
                json_to_sql(Some(&JsonValue::from("hi"))),
                json_to_sql(None),
            ],
        )
        .unwrap();

        conn.query_row("SELECT a,b,c,d,e FROM t", [], |row| {
            assert_eq!(sql_to_json(row, 0).unwrap(), JsonValue::from(1)); // bool -> 0/1 integer
            assert_eq!(sql_to_json(row, 1).unwrap(), JsonValue::from(42));
            assert_eq!(sql_to_json(row, 2).unwrap(), JsonValue::from(1.5));
            assert_eq!(sql_to_json(row, 3).unwrap(), JsonValue::from("hi"));
            assert_eq!(sql_to_json(row, 4).unwrap(), JsonValue::Null);
            Ok(())
        })
        .unwrap();
    }

    #[test]
    fn arrays_and_objects_store_as_null_not_silently_stringified() {
        assert!(matches!(json_to_sql(Some(&serde_json::json!([1, 2]))), ToSqlOutput::Owned(SqlValue::Null)));
        assert!(matches!(json_to_sql(Some(&serde_json::json!({"a": 1}))), ToSqlOutput::Owned(SqlValue::Null)));
    }
}
