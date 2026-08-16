//! Runtime configuration: which Whity backend to talk to, and the per-OS
//! platform label sent when enrolling a device.
//!
//! The backend URL is THE knob for pointing a build at local dev, staging, or a
//! customer's instance, and it resolves in three layers (highest wins):
//!
//!   1. **Runtime env** — `WHITY_BACKEND_URL` in the process environment
//!      (e.g. `$env:WHITY_BACKEND_URL="http://localhost:8000"` to point a dev
//!      build at the local stack without recompiling). Always wins — the
//!      dev/ops escape hatch.
//!   2. **User-chosen, persisted** — the server picked on the login screen
//!      (WC-server-select), stored in `auth_state.server_url` and read at
//!      startup by `lib.rs` before this `Config` is constructed.
//!   3. **Compile time** — `build.rs` bakes a default from the build
//!      environment, then `.env`, then the pinned production URL. This is
//!      what a shipped installer falls back to before the user has ever
//!      chosen a server: it has no shell to read env vars from.

/// Backend used when nothing else overrides it — resolved at compile time by
/// `build.rs` (see its docs for the precedence chain).
const DEFAULT_BACKEND_URL: &str = env!("WHITY_DEFAULT_BACKEND_URL");

#[derive(Clone)]
pub struct Config {
    /// Base origin, no trailing slash (e.g. `https://whity.jameedium.org`).
    pub backend_url: String,
    /// Device platform label: "windows" | "macos" | "linux".
    pub platform: &'static str,
}

impl Config {
    /// Runtime-env override only (layer 1) — kept for callers/tests that don't
    /// have a stored server URL to consider. Equivalent to `resolve(None)`.
    pub fn from_env() -> Self {
        Self::resolve(None)
    }

    /// Full three-layer resolution: runtime env > `stored_server_url` (from
    /// `auth_state.server_url`) > the compile-time default.
    pub fn resolve(stored_server_url: Option<String>) -> Self {
        let backend_url = std::env::var("WHITY_BACKEND_URL")
            .ok()
            .and_then(non_empty)
            .or_else(|| stored_server_url.and_then(non_empty))
            .unwrap_or_else(|| DEFAULT_BACKEND_URL.to_string());

        Self {
            backend_url: backend_url.trim_end_matches('/').to_string(),
            platform: platform_label(),
        }
    }

    /// The versioned API base, e.g. `https://whity.jameedium.org/api/v1`.
    pub fn api_base(&self) -> String {
        format!("{}/api/v1", self.backend_url)
    }

    /// Validate a user-supplied backend URL (the login screen's Server field):
    /// non-empty, `http(s)://` scheme, trailing slash trimmed. Deliberately
    /// minimal — mirrors this crate's existing hand-rolled string parsing
    /// (`build.rs`'s `.env` reader) rather than pulling in a URL crate for one
    /// prefix check.
    pub fn validate_backend_url(raw: &str) -> Result<String, String> {
        let trimmed = raw.trim();
        if trimmed.is_empty() {
            return Err("Server URL is required.".to_string());
        }
        if !trimmed.starts_with("http://") && !trimmed.starts_with("https://") {
            return Err("Server URL must start with http:// or https://".to_string());
        }
        Ok(trimmed.trim_end_matches('/').to_string())
    }
}

fn non_empty(s: String) -> Option<String> {
    let s = s.trim().to_string();
    (!s.is_empty()).then_some(s)
}

fn platform_label() -> &'static str {
    if cfg!(target_os = "windows") {
        "windows"
    } else if cfg!(target_os = "macos") {
        "macos"
    } else {
        "linux"
    }
}
