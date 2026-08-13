//! Runtime configuration: which Whity backend to talk to, and the per-OS
//! platform label sent when enrolling a device.
//!
//! The backend URL is THE knob for pointing a build at local dev, staging, or a
//! customer's instance, and it resolves in two layers:
//!
//!   * **Compile time** — `build.rs` bakes a default from the build
//!     environment, then `.env`, then the pinned production URL. This is what a
//!     shipped installer uses: it has no shell to read env vars from.
//!   * **Runtime** — `WHITY_BACKEND_URL` in the process environment overrides
//!     the baked value (e.g. `$env:WHITY_BACKEND_URL="http://localhost:8000"`
//!     to point a dev build at the local stack without recompiling).

/// Backend used when `WHITY_BACKEND_URL` is unset at runtime — resolved at
/// compile time by `build.rs` (see its docs for the precedence chain).
const DEFAULT_BACKEND_URL: &str = env!("WHITY_DEFAULT_BACKEND_URL");

#[derive(Clone)]
pub struct Config {
    /// Base origin, no trailing slash (e.g. `https://whity.jameedium.org`).
    pub backend_url: String,
    /// Device platform label: "windows" | "macos" | "linux".
    pub platform: &'static str,
}

impl Config {
    pub fn from_env() -> Self {
        let backend_url = std::env::var("WHITY_BACKEND_URL")
            .ok()
            .map(|s| s.trim().to_string())
            .filter(|s| !s.is_empty())
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
