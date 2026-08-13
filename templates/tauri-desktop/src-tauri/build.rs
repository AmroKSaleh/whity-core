//! Tauri's own codegen, plus one extra step: resolving the default backend URL
//! at COMPILE time and baking it into the binary.
//!
//! Reading `WHITY_BACKEND_URL` only at runtime is not enough for a shipped
//! desktop app — an installed `.exe` is launched from a Start-menu shortcut,
//! not a shell, so whatever is compiled in IS the backend for that build. This
//! resolves it once, here, in precedence order:
//!
//!   1. `WHITY_BACKEND_URL` in the build environment (CI, per-customer builds)
//!   2. `WHITY_BACKEND_URL` in `.env` next to `package.json` (gitignored;
//!      copy `.env.example` and edit)
//!   3. `FALLBACK_BACKEND_URL` below
//!
//! A runtime `WHITY_BACKEND_URL` still overrides the baked value (see
//! `config.rs`) — that stays the quick knob for developers with a shell.

use std::path::{Path, PathBuf};

/// The instance a build points at when nothing overrides it. Public on purpose
/// (it is in the committed `.env.example` too): it is a URL, not a secret.
const FALLBACK_BACKEND_URL: &str = "https://whity.jameedium.org";

fn main() {
    bake_backend_url();
    tauri_build::build()
}

fn bake_backend_url() {
    // The template root — the directory holding package.json and .env — is one
    // level up from src-tauri/.
    let dotenv = PathBuf::from(std::env::var("CARGO_MANIFEST_DIR").expect("CARGO_MANIFEST_DIR"))
        .join("..")
        .join(".env");

    println!("cargo:rerun-if-env-changed=WHITY_BACKEND_URL");
    // Only when it exists: cargo re-runs a build script on EVERY build if a
    // rerun-if-changed path is missing, which would recompile this crate each
    // time on the (common) fresh clone with no .env. Trade-off: creating a
    // .env where there was none is picked up on the next rebuild of this
    // crate, not instantly — `cargo clean -p whity-tauri-desktop-template`
    // forces it.
    if dotenv.exists() {
        println!("cargo:rerun-if-changed={}", dotenv.display());
    }

    let url = std::env::var("WHITY_BACKEND_URL")
        .ok()
        .and_then(non_empty)
        .or_else(|| dotenv_value(&dotenv, "WHITY_BACKEND_URL"))
        .unwrap_or_else(|| FALLBACK_BACKEND_URL.to_string());

    println!("cargo:rustc-env=WHITY_DEFAULT_BACKEND_URL={url}");
}

/// Deliberately narrow `.env` reader: `KEY=VALUE`, `#` comments, optionally
/// quoted values, last assignment wins. No `export `, no interpolation, no
/// multi-line values — this reads one URL, so pulling in a dotenv crate as a
/// build dependency would cost more than it buys.
fn dotenv_value(path: &Path, key: &str) -> Option<String> {
    let contents = std::fs::read_to_string(path).ok()?;
    contents.lines().rev().find_map(|line| {
        let line = line.trim();
        if line.is_empty() || line.starts_with('#') {
            return None;
        }
        let (k, v) = line.split_once('=')?;
        if k.trim() != key {
            return None;
        }
        non_empty(v.trim().trim_matches(['"', '\'']).to_string())
    })
}

fn non_empty(s: String) -> Option<String> {
    let s = s.trim().to_string();
    (!s.is_empty()).then_some(s)
}
