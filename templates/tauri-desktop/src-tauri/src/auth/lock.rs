//! The offline TTL lock (WC-desktop-sync). A pure evaluation over the stored
//! `auth_state`: once more than the tenant's `max_login_seconds` has elapsed
//! since the last successful online exchange, the app locks — even offline —
//! and must re-authenticate online. Re-authenticating (an exchange, via the
//! `auth_login` command) resets `last_online_auth_at` and unlocks.
//!
//! Kept pure (no DB, no clock, no network) so it's trivially unit-tested; the
//! `auth_lock_state` command supplies the current `AuthStatus` + `now`.

use serde::Serialize;

use crate::db::auth_repo::AuthStatus;

/// Client fallback when the server never echoed a TTL (older backend): 72h.
const DEFAULT_MAX_LOGIN_SECONDS: i64 = 72 * 3600;

#[derive(Serialize, Debug, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct LockState {
    pub locked: bool,
    /// "not_enrolled" | "ttl_expired" when locked; null when unlocked.
    pub reason: Option<String>,
    /// Seconds until the lock trips, when unlocked.
    pub seconds_remaining: Option<i64>,
}

impl LockState {
    fn locked(reason: &str) -> Self {
        Self { locked: true, reason: Some(reason.to_string()), seconds_remaining: None }
    }
    fn unlocked(seconds_remaining: i64) -> Self {
        Self { locked: false, reason: None, seconds_remaining: Some(seconds_remaining) }
    }
}

/// Evaluate the lock from the stored auth state at time `now_epoch` (seconds).
pub fn evaluate(status: &AuthStatus, now_epoch: i64) -> LockState {
    if !status.enrolled {
        return LockState::locked("not_enrolled");
    }
    let max = status.max_login_seconds.unwrap_or(DEFAULT_MAX_LOGIN_SECONDS).max(1);
    match status.last_online_auth_at {
        // Enrolled but never exchanged online yet → treat as needing online auth.
        None => LockState::locked("ttl_expired"),
        Some(last) => {
            let elapsed = now_epoch - last;
            if elapsed <= max {
                LockState::unlocked(max - elapsed)
            } else {
                LockState::locked("ttl_expired")
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn status(enrolled: bool, last: Option<i64>, max: Option<i64>) -> AuthStatus {
        AuthStatus {
            enrolled,
            email: None,
            device_id: None,
            active_tenant_id: None,
            credential_expires_at: None,
            last_online_auth_at: last,
            max_login_seconds: max,
        }
    }

    #[test]
    fn locked_when_not_enrolled() {
        let s = evaluate(&status(false, None, None), 1000);
        assert!(s.locked);
        assert_eq!(s.reason.as_deref(), Some("not_enrolled"));
    }

    #[test]
    fn unlocked_within_the_window_reports_remaining() {
        // last auth 1h ago, 72h window → unlocked, ~71h remaining.
        let now = 1_000_000;
        let s = evaluate(&status(true, Some(now - 3600), Some(72 * 3600)), now);
        assert!(!s.locked);
        assert_eq!(s.seconds_remaining, Some(72 * 3600 - 3600));
    }

    #[test]
    fn locked_when_past_the_window() {
        // last auth 73h ago, 72h window → ttl_expired.
        let now = 1_000_000;
        let s = evaluate(&status(true, Some(now - 73 * 3600), Some(72 * 3600)), now);
        assert!(s.locked);
        assert_eq!(s.reason.as_deref(), Some("ttl_expired"));
    }

    #[test]
    fn falls_back_to_default_window_when_no_echo() {
        let now = 1_000_000;
        // no max echoed → 72h default; 1h elapsed → unlocked.
        let s = evaluate(&status(true, Some(now - 3600), None), now);
        assert!(!s.locked);
        assert_eq!(s.seconds_remaining, Some(DEFAULT_MAX_LOGIN_SECONDS - 3600));
    }

    #[test]
    fn locked_when_enrolled_but_never_authed() {
        let s = evaluate(&status(true, None, Some(72 * 3600)), 1000);
        assert!(s.locked);
        assert_eq!(s.reason.as_deref(), Some("ttl_expired"));
    }
}
