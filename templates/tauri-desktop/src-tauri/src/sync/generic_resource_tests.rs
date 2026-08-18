//! Proves the sync engine generalized (WC-sync-generalize) beyond DemoCatalog:
//! a synthetic second resource with MIXED-TYPE domain columns (`title: TEXT`,
//! `priority: INTEGER`, `done: INTEGER` as bool) — an axis DemoCatalog alone
//! can't exercise, since all three of its domain columns are strings — driven
//! through `engine::sync_cycle_for` against an in-process `tiny_http` mock
//! implementing the same minimal wire contract the real backend does. No live
//! backend needed; runs in plain `cargo test`, not gated behind `--ignored`.

use std::sync::{Arc, Mutex};
use std::thread;

use reqwest::blocking::Client;
use rusqlite::Connection;
use tiny_http::{Header, Method, Response, Server};

use super::engine;
use super::resource::ResourceDescriptor;

/// A resource whose rows live in a template-test-only local table (never
/// part of `resource::RESOURCES` / never migrated into a real app database —
/// see `migrated()` below, which creates its table by hand).
static WIDGET_ITEMS: ResourceDescriptor =
    ResourceDescriptor { key: "widgets/items", table: "widget_items", base_path: "/widgets/items", domain_columns: &["title", "priority", "done"] };

#[derive(Clone)]
struct MockRow {
    id: i64,
    client_uuid: Option<String>,
    version: i64,
    deleted_at: Option<String>,
    updated_by: Option<i64>,
    title: String,
    priority: i64,
    done: bool,
}

impl MockRow {
    fn to_json(&self) -> serde_json::Value {
        serde_json::json!({
            "id": self.id,
            "clientUuid": self.client_uuid,
            "version": self.version,
            "deletedAt": self.deleted_at,
            "updatedBy": self.updated_by,
            "createdAt": "2024-01-01T00:00:00.000Z",
            "updatedAt": "2024-01-01T00:00:00.000Z",
            "title": self.title,
            "priority": self.priority,
            "done": self.done,
        })
    }
}

struct MockServer {
    port: u16,
    rows: Arc<Mutex<Vec<MockRow>>>,
}

/// Spawn an in-process mock speaking the sync wire contract for `/widgets/items`
/// only: idempotent-on-clientUuid create, If-Match/baseVersion optimistic
/// update/delete (409 with `serverItem` on mismatch), and an `updatedSince`
/// changes feed keyed by row id (a real cursor's opacity doesn't matter here).
fn spawn_mock() -> MockServer {
    let server = Server::http("127.0.0.1:0").expect("bind mock server");
    let port = server.server_addr().to_ip().expect("mock server has a local IP").port();
    let rows: Arc<Mutex<Vec<MockRow>>> = Arc::new(Mutex::new(Vec::new()));
    let next_id = Arc::new(Mutex::new(1i64));

    let thread_rows = rows.clone();
    thread::spawn(move || {
        for mut request in server.incoming_requests() {
            let method = request.method().clone();
            let url = request.url().to_string();
            let (path, query) = url.split_once('?').unwrap_or((url.as_str(), ""));
            let if_match = request_if_match(&request);
            let mut body = String::new();
            let _ = request.as_reader().read_to_string(&mut body);

            match (&method, path) {
                (Method::Get, "/widgets/items") => handle_list(request, &thread_rows, query),
                (Method::Post, "/widgets/items") => handle_create(request, &thread_rows, &next_id, &body),
                (Method::Patch, p) if p.starts_with("/widgets/items/") => {
                    handle_update(request, &thread_rows, id_from_path(p), &if_match, &body)
                }
                (Method::Delete, p) if p.starts_with("/widgets/items/") => {
                    handle_delete(request, &thread_rows, id_from_path(p), &if_match)
                }
                _ => respond(request, 404, &serde_json::json!({"error": "not found"})),
            }
        }
    });

    MockServer { port, rows }
}

fn request_if_match(request: &tiny_http::Request) -> Option<String> {
    request.headers().iter().find(|h| h.field.equiv("If-Match")).map(|h| h.value.as_str().to_string())
}

fn id_from_path(path: &str) -> i64 {
    path.rsplit('/').next().and_then(|s| s.parse().ok()).unwrap_or(0)
}

fn query_param(query: &str, key: &str) -> Option<String> {
    query.split('&').find_map(|pair| {
        let (k, v) = pair.split_once('=')?;
        (k == key).then(|| v.to_string())
    })
}

fn handle_list(request: tiny_http::Request, rows: &Arc<Mutex<Vec<MockRow>>>, query: &str) {
    let since: i64 = query_param(query, "updatedSince").and_then(|s| s.parse().ok()).unwrap_or(0);
    let limit: usize = query_param(query, "limit").and_then(|s| s.parse().ok()).unwrap_or(200);
    let guard = rows.lock().unwrap();
    let mut page: Vec<&MockRow> = guard.iter().filter(|r| r.id > since).collect();
    page.sort_by_key(|r| r.id);
    page.truncate(limit);
    let cursor = page.last().map(|r| r.id.to_string()).unwrap_or_else(|| since.to_string());
    let data: Vec<_> = page.iter().map(|r| r.to_json()).collect();
    respond(request, 200, &serde_json::json!({"data": data, "cursor": cursor, "hasMore": false}));
}

fn handle_create(request: tiny_http::Request, rows: &Arc<Mutex<Vec<MockRow>>>, next_id: &Arc<Mutex<i64>>, body: &str) {
    let parsed: serde_json::Value = serde_json::from_str(body).unwrap_or_default();
    let client_uuid = parsed.get("clientUuid").and_then(|v| v.as_str()).map(str::to_string);

    let mut guard = rows.lock().unwrap();
    if let Some(existing) = client_uuid.as_deref().and_then(|u| guard.iter().find(|r| r.client_uuid.as_deref() == Some(u))) {
        let json = existing.to_json();
        return respond(request, 200, &serde_json::json!({"data": json}));
    }

    let mut id_guard = next_id.lock().unwrap();
    let id = *id_guard;
    *id_guard += 1;
    let row = MockRow {
        id,
        client_uuid,
        version: 1,
        deleted_at: None,
        updated_by: Some(1),
        title: parsed.get("title").and_then(|v| v.as_str()).unwrap_or_default().to_string(),
        priority: parsed.get("priority").and_then(|v| v.as_i64()).unwrap_or(0),
        done: parsed.get("done").and_then(|v| v.as_bool()).unwrap_or(false),
    };
    guard.push(row.clone());
    respond(request, 201, &serde_json::json!({"data": row.to_json()}));
}

fn handle_update(request: tiny_http::Request, rows: &Arc<Mutex<Vec<MockRow>>>, id: i64, if_match: &Option<String>, body: &str) {
    let parsed: serde_json::Value = serde_json::from_str(body).unwrap_or_default();
    let base_version: i64 = if_match
        .as_deref()
        .and_then(|s| s.parse().ok())
        .or_else(|| parsed.get("baseVersion").and_then(|v| v.as_i64()))
        .unwrap_or(-1);

    let mut guard = rows.lock().unwrap();
    let Some(row) = guard.iter_mut().find(|r| r.id == id) else {
        return respond(request, 404, &serde_json::json!({"error": "not found"}));
    };
    if row.version != base_version {
        return respond(request, 409, &serde_json::json!({"error": "conflict", "serverItem": row.to_json()}));
    }
    if let Some(title) = parsed.get("title").and_then(|v| v.as_str()) {
        row.title = title.to_string();
    }
    if let Some(priority) = parsed.get("priority").and_then(|v| v.as_i64()) {
        row.priority = priority;
    }
    if let Some(done) = parsed.get("done").and_then(|v| v.as_bool()) {
        row.done = done;
    }
    row.version += 1;
    respond(request, 200, &serde_json::json!({"data": row.to_json()}));
}

fn handle_delete(request: tiny_http::Request, rows: &Arc<Mutex<Vec<MockRow>>>, id: i64, if_match: &Option<String>) {
    let base_version: i64 = if_match.as_deref().and_then(|s| s.parse().ok()).unwrap_or(-1);
    let mut guard = rows.lock().unwrap();
    let Some(row) = guard.iter_mut().find(|r| r.id == id) else {
        return respond(request, 404, &serde_json::json!({"error": "not found"}));
    };
    if row.version != base_version {
        return respond(request, 409, &serde_json::json!({"error": "conflict", "serverItem": row.to_json()}));
    }
    row.deleted_at = Some("2024-01-01T00:00:00.000Z".to_string());
    row.version += 1;
    respond(request, 200, &serde_json::json!({"data": row.to_json()}));
}

fn respond(request: tiny_http::Request, status: u16, body: &serde_json::Value) {
    let json = body.to_string();
    let content_type = Header::from_bytes(&b"Content-Type"[..], &b"application/json"[..]).expect("static header is valid");
    let response = Response::from_string(json).with_status_code(status).with_header(content_type);
    let _ = request.respond(response);
}

fn migrated_with_widgets() -> Connection {
    let conn = Connection::open_in_memory().unwrap();
    crate::db::migrations::run(&conn).unwrap();
    // Test-only table — never part of the real migration script, matching the
    // exact sync-identity column set `demo_catalog_items` has (see
    // db::migrations::V2_ITEMS) so the SAME generic engine code path applies.
    conn.execute_batch(
        "CREATE TABLE widget_items (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            client_uuid       TEXT    NOT NULL UNIQUE,
            server_id         INTEGER,
            title             TEXT    NOT NULL,
            priority          INTEGER NOT NULL DEFAULT 0,
            done              INTEGER NOT NULL DEFAULT 0,
            base_version      INTEGER NOT NULL DEFAULT 0,
            sync_state        TEXT    NOT NULL DEFAULT 'pending',
            dirty             INTEGER NOT NULL DEFAULT 1,
            deleted           INTEGER NOT NULL DEFAULT 0,
            created_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
            updated_at        TEXT,
            updated_at_local  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
            updated_by        TEXT,
            push_attempts     INTEGER NOT NULL DEFAULT 0,
            next_attempt_at   TEXT,
            last_push_error   TEXT
        )",
    )
    .unwrap();
    conn
}

#[test]
fn generalizes_to_a_second_resource_with_non_string_domain_columns() {
    let mock = spawn_mock();
    let api_base = format!("http://127.0.0.1:{}", mock.port);
    let client = Client::builder().connect_timeout(std::time::Duration::from_secs(3)).build().unwrap();
    let resources: &[&ResourceDescriptor] = &[&WIDGET_ITEMS];

    // --- PUSH: a locally-created widget (int + bool domain columns) reaches the mock ---
    let db1 = migrated_with_widgets();
    db1.execute(
        "INSERT INTO widget_items (client_uuid, title, priority, done, dirty, sync_state, updated_at_local)
         VALUES ('w-1', 'Ship it', 3, 0, 1, 'pending', strftime('%Y-%m-%dT%H:%M:%fZ','now'))",
        [],
    )
    .unwrap();

    let s1 = engine::sync_cycle_for(&db1, &client, &api_base, Some("tok"), resources).unwrap();
    assert_eq!(s1.pushed, 1, "the pending widget create should push");
    let (server_id, sync_state, priority, done): (Option<i64>, String, i64, i64) = db1
        .query_row("SELECT server_id, sync_state, priority, done FROM widget_items WHERE client_uuid = 'w-1'", [], |r| {
            Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?))
        })
        .unwrap();
    let server_id = server_id.expect("server_id assigned after push");
    assert_eq!(sync_state, "synced");
    assert_eq!(priority, 3, "the INTEGER domain column pushed correctly");
    assert_eq!(done, 0, "the BOOL-as-INTEGER domain column pushed correctly");
    assert_eq!(mock.rows.lock().unwrap().len(), 1);

    // --- PULL: a fresh client sees the pushed row, including its non-string fields ---
    let db2 = migrated_with_widgets();
    let s2 = engine::sync_cycle_for(&db2, &client, &api_base, Some("tok"), resources).unwrap();
    assert_eq!(s2.pulled, 1);
    let (title, priority2, done2): (String, i64, i64) =
        db2.query_row("SELECT title, priority, done FROM widget_items WHERE client_uuid = 'w-1'", [], |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?))).unwrap();
    assert_eq!(title, "Ship it");
    assert_eq!(priority2, 3);
    assert_eq!(done2, 0);

    // --- CONFLICT: the mock moves ahead of a locally-dirty row ---
    {
        let mut rows = mock.rows.lock().unwrap();
        let row = rows.iter_mut().find(|r| r.id == server_id).unwrap();
        row.priority = 9;
        row.version += 1;
    }
    db1.execute("UPDATE widget_items SET priority = 5, dirty = 1, sync_state = 'pending' WHERE client_uuid = 'w-1'", []).unwrap();

    let s3 = engine::sync_cycle_for(&db1, &client, &api_base, Some("tok"), resources).unwrap();
    assert_eq!(s3.conflicts, 1, "the stale local edit must conflict with the mock's newer version");
    let conflict_state: String = db1.query_row("SELECT sync_state FROM widget_items WHERE client_uuid = 'w-1'", [], |r| r.get(0)).unwrap();
    assert_eq!(conflict_state, "conflict");
    let (mine, theirs): (String, String) =
        db1.query_row("SELECT mine_json, theirs_json FROM item_conflicts WHERE resource = 'widgets/items' AND client_uuid = 'w-1'", [], |r| {
            Ok((r.get(0)?, r.get(1)?))
        }).unwrap();
    assert!(mine.contains("\"priority\":5"), "mine snapshot preserves the int field: {mine}");
    assert!(theirs.contains("\"priority\":9"), "theirs snapshot preserves the int field: {theirs}");

    // --- RESOLVE: rebase onto the mock's version and re-push ---
    let mut resolved = serde_json::Map::new();
    resolved.insert("title".to_string(), serde_json::Value::String("Ship it".to_string()));
    resolved.insert("priority".to_string(), serde_json::Value::from(9));
    resolved.insert("done".to_string(), serde_json::Value::from(true));
    assert!(crate::db::conflicts_repo::resolve(&db1, &WIDGET_ITEMS, "w-1", &resolved).unwrap());
    let s4 = engine::sync_cycle_for(&db1, &client, &api_base, Some("tok"), resources).unwrap();
    assert_eq!(s4.pushed, 1, "the merged resolution pushes cleanly");
    let (final_state, final_done): (String, i64) =
        db1.query_row("SELECT sync_state, done FROM widget_items WHERE client_uuid = 'w-1'", [], |r| Ok((r.get(0)?, r.get(1)?))).unwrap();
    assert_eq!(final_state, "synced");
    assert_eq!(final_done, 1, "the bool field round-tripped through resolve + re-push");
    let conflicts_left: i64 =
        db1.query_row("SELECT COUNT(*) FROM item_conflicts WHERE resource = 'widgets/items' AND client_uuid = 'w-1'", [], |r| r.get(0)).unwrap();
    assert_eq!(conflicts_left, 0);
}
