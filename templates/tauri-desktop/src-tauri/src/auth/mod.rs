//! Desktop authentication (WC-desktop-sync): enroll this device against the
//! Whity backend, cache the long-lived credential in the OS keychain, and
//! exchange it for short-lived access sessions. The orchestration that touches
//! the DB (recording `auth_state`) lives in `commands/auth.rs`; this module owns
//! the HTTP client + in-memory access token.

pub mod api;
pub mod credential_store;

use std::sync::Mutex;

use crate::config::Config;

/// Managed auth state: the backend config, a reused blocking HTTP client, and
/// the current access token held IN MEMORY ONLY (never persisted — only the
/// 90-day credential is, in the keychain).
pub struct AuthManager {
    pub cfg: Config,
    client: reqwest::blocking::Client,
    access: Mutex<Option<String>>,
}

impl AuthManager {
    pub fn new(cfg: Config) -> Result<Self, String> {
        Ok(Self {
            client: api::build_client()?,
            cfg,
            access: Mutex::new(None),
        })
    }

    pub fn client(&self) -> &reqwest::blocking::Client {
        &self.client
    }

    /// Cache the current access token (replacing any previous).
    pub fn set_access(&self, token: String) {
        *self.access.lock().expect("auth access mutex poisoned") = Some(token);
    }

    /// Take (and clear) the cached access token — used on logout to revoke it.
    pub fn take_access(&self) -> Option<String> {
        self.access.lock().expect("auth access mutex poisoned").take()
    }
}
