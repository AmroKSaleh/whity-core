//! Desktop authentication (WC-desktop-sync): enroll this device against the
//! Whity backend, cache the long-lived credential in the OS keychain, and
//! exchange it for short-lived access sessions. The orchestration that touches
//! the DB (recording `auth_state`) lives in `commands/auth.rs`; this module owns
//! the HTTP client + in-memory access token.

pub mod api;
pub mod credential_store;
pub mod lock;

use std::sync::{Arc, Mutex, RwLock};

use crate::config::Config;

/// Managed auth state: the backend config (behind an `RwLock` and SHARED via
/// `Arc` with `sync::scheduler`'s background loop — see `lib.rs` — so a
/// `set_backend_url` call takes effect for both without an app restart), a
/// reused blocking HTTP client, and the current access token held IN MEMORY
/// ONLY (never persisted — only the 90-day credential is, in the keychain).
pub struct AuthManager {
    cfg: Arc<RwLock<Config>>,
    client: reqwest::blocking::Client,
    access: Mutex<Option<String>>,
}

impl AuthManager {
    pub fn new(cfg: Arc<RwLock<Config>>) -> Result<Self, String> {
        Ok(Self {
            client: api::build_client()?,
            cfg,
            access: Mutex::new(None),
        })
    }

    pub fn client(&self) -> &reqwest::blocking::Client {
        &self.client
    }

    /// A snapshot of the current config. Cloned under a short-lived read lock
    /// — callers must NOT hold this across a blocking HTTP call by re-reading
    /// the lock; take the owned clone once per call instead, so
    /// `set_backend_url`'s writer is never blocked by an in-flight request.
    pub fn config(&self) -> Config {
        self.cfg.read().expect("config lock poisoned").clone()
    }

    /// Validate and persist a new backend URL for subsequent calls (does NOT
    /// itself write to `auth_state` — the caller decides when a chosen-but-
    /// unsubmitted URL becomes durable, see `commands::auth::finish_enroll`).
    pub fn set_backend_url(&self, url: String) -> Result<String, String> {
        let normalized = Config::validate_backend_url(&url)?;
        let mut guard = self.cfg.write().expect("config lock poisoned");
        guard.backend_url = normalized.clone();
        Ok(normalized)
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
