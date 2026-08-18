//! Desktop authentication (WC-desktop-sync): enroll this device against the
//! Whity backend, cache the long-lived credential in the OS keychain, and
//! exchange it for short-lived access sessions. The orchestration that touches
//! the DB (recording `auth_state`) lives in `commands/auth.rs`; this module owns
//! the HTTP client + in-memory access token.

pub mod api;
pub mod credential_store;
pub mod lock;

use std::sync::{Arc, Mutex};

use crate::config::Config;

/// Managed auth state: the backend config (fixed for the process lifetime —
/// see `config.rs`'s two-layer resolution; SHARED via `Arc` with
/// `sync::scheduler`'s background loop purely so both read the exact same
/// resolved value, not because either ever mutates it), a reused blocking
/// HTTP client, and the current access token held IN MEMORY ONLY (never
/// persisted — only the 90-day credential is, in the keychain).
pub struct AuthManager {
    cfg: Arc<Config>,
    client: reqwest::blocking::Client,
    /// A second client used only by `remote_request`: identical timeouts, but
    /// with redirects disabled (see `api::build_remote_client`). Kept separate
    /// because a client's redirect policy is fixed at build time, and the
    /// enroll/exchange/sync calls on `client` still want the default policy.
    remote_client: reqwest::blocking::Client,
    access: Mutex<Option<String>>,
}

impl AuthManager {
    pub fn new(cfg: Arc<Config>) -> Result<Self, String> {
        Ok(Self {
            client: api::build_client()?,
            remote_client: api::build_remote_client()?,
            cfg,
            access: Mutex::new(None),
        })
    }

    pub fn client(&self) -> &reqwest::blocking::Client {
        &self.client
    }

    /// The redirect-disabled client backing `remote_request`.
    pub fn remote_client(&self) -> &reqwest::blocking::Client {
        &self.remote_client
    }

    /// A cheap owned clone of the resolved config.
    pub fn config(&self) -> Config {
        self.cfg.as_ref().clone()
    }

    /// Cache the current access token (replacing any previous).
    pub fn set_access(&self, token: String) {
        *self.access.lock().expect("auth access mutex poisoned") = Some(token);
    }

    /// Take (and clear) the cached access token — used on logout to revoke it.
    pub fn take_access(&self) -> Option<String> {
        self.access.lock().expect("auth access mutex poisoned").take()
    }

    /// A clone of the current cached access token, if any. `remote_request`
    /// reads this to authenticate as the enrolled device WITHOUT a round trip;
    /// it only re-exchanges (see `refresh_access`) when the token is absent or
    /// the server rejects it as stale.
    pub fn access_token(&self) -> Option<String> {
        self.access.lock().expect("auth access mutex poisoned").clone()
    }

    /// Exchange the keychain device credential for a fresh access token, cache
    /// it, and return it — the SAME credential-exchange the sync/login path uses
    /// (`api::exchange`), reused so `remote_request` never invents a second auth
    /// mechanism. Errors if this device isn't enrolled (no stored credential) or
    /// the exchange fails (offline / revoked credential). Deliberately does NOT
    /// touch the offline-lock clock in `auth_state`: advancing that stays the
    /// job of the explicit `auth_login` a reconnect triggers, not a side effect
    /// of an admin API call.
    pub fn refresh_access(&self) -> Result<String, String> {
        let credential = credential_store::load()?
            .ok_or_else(|| "not enrolled — enroll a device first".to_string())?;
        let session = api::exchange(&self.client, &self.cfg, &credential)?;
        let token = session.access_token.clone();
        self.set_access(session.access_token);
        Ok(token)
    }
}
