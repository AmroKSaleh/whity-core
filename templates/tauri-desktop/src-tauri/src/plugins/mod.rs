//! Downloadable plugin catalog + install pipeline (WC-desktop-plugins): the
//! desktop fetches its tenant's entitled "desktop plugin" releases — source-
//! obfuscated PHP packages, still plain PHP that runs unmodified on the
//! already-bundled FrankenPHP — from the SAME backend the user authenticates
//! against, using the same device bearer access token already used for sync
//! (see `crate::sync`). No separate store/allowlist concept: the chosen
//! backend (`crate::config::Config`) IS the plugin source.
//!
//! `api` holds the HTTP calls; `installer` holds the download/verify/extract/
//! commit pipeline that lands a package into the writable
//! `plugins-downloaded/` root (`crate::php_host::PhpHostHandle::plugins_root()`).

pub mod api;
pub mod installer;
pub mod reconcile;

use serde::{Deserialize, Serialize};

/// One version of a catalogued plugin — mirrors the shape
/// `Whity\Api\DesktopPluginsApiHandler::catalog()` returns per entry
/// (`c:\Projects\whity-core\src\Api\DesktopPluginsApiHandler.php`).
#[derive(Deserialize, Serialize, Clone, Debug)]
#[serde(rename_all = "camelCase")]
pub struct CatalogVersion {
    pub version: String,
    pub sha256: String,
    pub size_bytes: u64,
    pub released_at: String,
}

/// A plugin's full catalog entry (every known version, most recent first).
#[derive(Deserialize, Serialize, Clone, Debug)]
#[serde(rename_all = "camelCase")]
pub struct CatalogEntry {
    pub name: String,
    pub latest_version: String,
    pub versions: Vec<CatalogVersion>,
}

/// What a successful install produced — returned to the frontend so it can
/// confirm which plugin/version just landed.
#[derive(Serialize, Clone, Debug)]
#[serde(rename_all = "camelCase")]
pub struct InstallOutcome {
    pub name: String,
    pub version: String,
}
