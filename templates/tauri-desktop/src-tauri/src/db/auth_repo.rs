//! The singleton `auth_state` row (WC-desktop-sync): non-secret enrollment +
//! session bookkeeping. The SECRET device credential lives in the OS keychain
//! (see `auth::credential_store`), never here. `last_online_auth_at` +
//! `max_login_seconds` drive the offline TTL lock a later PR adds.

use rusqlite::{params, Connection};
use serde::Serialize;

#[derive(Serialize, Debug, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct AuthStatus {
    pub enrolled: bool,
    pub email: Option<String>,
    pub device_id: Option<i64>,
    pub active_tenant_id: Option<i64>,
    /// The credential's own expiry (already capped by the backend's TTL policy).
    pub credential_expires_at: Option<String>,
    /// Epoch seconds of the last successful online credential exchange.
    pub last_online_auth_at: Option<i64>,
    /// The server-echoed desktop-login TTL (seconds); the offline-lock window.
    pub max_login_seconds: Option<i64>,
    /// The backend this device last enrolled against (WC-server-select), so the
    /// UI can display "connected to: …". `None` before the first enrollment.
    pub server_url: Option<String>,
}

pub fn status(conn: &Connection) -> rusqlite::Result<AuthStatus> {
    conn.query_row(
        "SELECT enrolled, email, device_id, active_tenant_id,
                credential_expires_at, last_online_auth_at, max_login_seconds, server_url
         FROM auth_state WHERE id = 1",
        [],
        |r| {
            Ok(AuthStatus {
                enrolled: r.get::<_, i64>(0)? != 0,
                email: r.get(1)?,
                device_id: r.get(2)?,
                active_tenant_id: r.get(3)?,
                credential_expires_at: r.get(4)?,
                last_online_auth_at: r.get(5)?,
                max_login_seconds: r.get(6)?,
                server_url: r.get(7)?,
            })
        },
    )
}

/// The stored backend URL, read BEFORE `Config`/`AuthManager` exist (app
/// startup, ahead of the full `AuthStatus` query) — `None` pre-enrollment.
pub fn get_server_url(conn: &Connection) -> rusqlite::Result<Option<String>> {
    conn.query_row("SELECT server_url FROM auth_state WHERE id = 1", [], |r| r.get(0))
}

/// Mark the device enrolled and record its identity/credential metadata,
/// including the backend it just enrolled against.
pub fn set_enrolled(
    conn: &Connection,
    device_id: i64,
    email: &str,
    active_tenant_id: Option<i64>,
    credential_expires_at: &str,
    server_url: &str,
) -> rusqlite::Result<()> {
    conn.execute(
        "UPDATE auth_state
         SET enrolled = 1, device_id = ?1, email = ?2,
             active_tenant_id = ?3, credential_expires_at = ?4, server_url = ?5
         WHERE id = 1",
        params![device_id, email, active_tenant_id, credential_expires_at, server_url],
    )?;
    Ok(())
}

/// Record a successful online credential exchange: stamps the clock the offline
/// lock measures against, and stores the server-echoed TTL (keeping any prior
/// value when the backend didn't echo one).
pub fn record_online_auth(
    conn: &Connection,
    now_epoch: i64,
    max_login_seconds: Option<i64>,
) -> rusqlite::Result<()> {
    conn.execute(
        "UPDATE auth_state
         SET last_online_auth_at = ?1,
             max_login_seconds = COALESCE(?2, max_login_seconds)
         WHERE id = 1",
        params![now_epoch, max_login_seconds],
    )?;
    Ok(())
}

/// Reset to the un-enrolled state (logout).
pub fn clear(conn: &Connection) -> rusqlite::Result<()> {
    conn.execute(
        "UPDATE auth_state
         SET enrolled = 0, device_id = NULL, email = NULL, active_tenant_id = NULL,
             credential_expires_at = NULL, last_online_auth_at = NULL, max_login_seconds = NULL
         WHERE id = 1",
        [],
    )?;
    Ok(())
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
    fn starts_unenrolled_then_records_enrollment_and_online_auth() {
        let conn = migrated();

        let s0 = status(&conn).unwrap();
        assert!(!s0.enrolled);
        assert!(s0.email.is_none());
        assert!(s0.last_online_auth_at.is_none());

        set_enrolled(
            &conn,
            7,
            "admin@example.com",
            Some(1),
            "2026-08-01T00:00:00+00:00",
            "https://whity.example.com",
        )
        .unwrap();
        record_online_auth(&conn, 1_700_000_000, Some(259_200)).unwrap();

        let s1 = status(&conn).unwrap();
        assert!(s1.enrolled);
        assert_eq!(s1.email.as_deref(), Some("admin@example.com"));
        assert_eq!(s1.device_id, Some(7));
        assert_eq!(s1.active_tenant_id, Some(1));
        assert_eq!(s1.last_online_auth_at, Some(1_700_000_000));
        assert_eq!(s1.max_login_seconds, Some(259_200));
        assert_eq!(s1.server_url.as_deref(), Some("https://whity.example.com"));

        // A later exchange with no echoed TTL keeps the prior max_login_seconds.
        record_online_auth(&conn, 1_700_100_000, None).unwrap();
        let s2 = status(&conn).unwrap();
        assert_eq!(s2.last_online_auth_at, Some(1_700_100_000));
        assert_eq!(s2.max_login_seconds, Some(259_200));
    }

    #[test]
    fn clear_resets_to_unenrolled_but_keeps_server_url() {
        let conn = migrated();
        set_enrolled(&conn, 1, "a@b.c", None, "x", "https://whity.example.com").unwrap();
        record_online_auth(&conn, 123, Some(3600)).unwrap();

        clear(&conn).unwrap();

        let s = status(&conn).unwrap();
        assert!(!s.enrolled);
        assert!(s.email.is_none());
        assert!(s.max_login_seconds.is_none());
        // server_url is a device-level fact, not session state — logout must not
        // clear it, so re-enrolling pre-fills the same previously trusted server.
        assert_eq!(s.server_url.as_deref(), Some("https://whity.example.com"));
    }

    #[test]
    fn get_server_url_reads_independently_of_full_status() {
        let conn = migrated();
        assert_eq!(get_server_url(&conn).unwrap(), None);

        set_enrolled(&conn, 1, "a@b.c", None, "x", "https://whity.example.com").unwrap();
        assert_eq!(get_server_url(&conn).unwrap().as_deref(), Some("https://whity.example.com"));
    }
}
