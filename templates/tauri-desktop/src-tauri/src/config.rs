//! Runtime configuration: which Whity backend to talk to, and the per-OS
//! platform label sent when enrolling a device.
//!
//! The backend URL is fixed per BUILD, not per device — this app is meant to
//! be portable to a different whity-core instance by changing exactly one
//! setting (`WHITY_BACKEND_URL` in `.env`, next to `package.json`) and
//! rebuilding, never by a user picking a server at runtime (there is no
//! "change server" control anywhere in the UI; see `app-state-provider.tsx`'s
//! `EnrollForm`). It resolves in two layers (highest wins):
//!
//!   1. **Runtime env** — `WHITY_BACKEND_URL` in the process environment
//!      (e.g. `$env:WHITY_BACKEND_URL="http://localhost:8000"` to point a dev
//!      build at the local stack without recompiling). The dev/ops escape
//!      hatch — irrelevant to a shipped installer, which has no shell to read
//!      env vars from.
//!   2. **Compile time** — `build.rs` bakes a default from the build
//!      environment, then `.env`, then the pinned production URL. This is
//!      what every shipped installer actually runs on.

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
    /// The two-layer resolution above: runtime env > the compile-time default.
    pub fn resolve() -> Self {
        let backend_url = std::env::var("WHITY_BACKEND_URL")
            .ok()
            .and_then(non_empty)
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
