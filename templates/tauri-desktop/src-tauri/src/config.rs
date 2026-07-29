//! Runtime configuration: which Whity backend to talk to, and the per-OS
//! platform label sent when enrolling a device.
//!
//! The backend URL is env-overridable via `WHITY_BACKEND_URL` — this is THE knob
//! for pointing a build at local dev, staging, or a customer's instance without
//! recompiling (e.g. `WHITY_BACKEND_URL=https://whity.jameedium.org`).

/// Backend used when `WHITY_BACKEND_URL` is unset — the standard local dev stack.
const DEFAULT_BACKEND_URL: &str = "http://localhost:8000";

#[derive(Clone)]
pub struct Config {
    /// Base origin, no trailing slash (e.g. `http://localhost:8000`).
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

    /// The versioned API base, e.g. `http://localhost:8000/api/v1`.
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
