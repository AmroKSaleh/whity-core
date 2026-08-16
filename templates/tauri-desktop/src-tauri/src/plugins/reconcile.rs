//! Diff-and-converge: reconcile this device's installed plugin set to exactly
//! match the connected backend's catalog (WC-plugin-sync). Runs on every
//! successful online login (see `commands::post_login`) — there is no manual
//! install/uninstall path in the frontend anymore; the server's catalog is
//! the single source of truth for what a device runs.
//!
//! Kept Tauri-agnostic (blocking reqwest, `Result<_, String>`, no
//! `AppHandle`/`State`) — same layering as `installer`/`api`. The
//! Tauri-state-aware glue (spawning this off the login flow, restarting
//! FrankenPHP, persisting the outcome) lives one layer up in
//! `commands::post_login`.

use std::path::Path;

use reqwest::blocking::Client;
use serde::{Deserialize, Serialize};

use super::{api, installer, CatalogEntry, InstallOutcome};
use crate::config::Config;

/// One plugin's install/update/remove that failed during a reconcile pass.
/// Reported, never fatal — one bad plugin must not block the rest of the
/// device's plugin set from converging. `Deserialize` too: `db::plugin_sync_repo`
/// round-trips a `Vec<PluginSyncFailure>` through the `plugin_sync_state` row
/// as JSON, so the Plugins page can show the last pass's failures without a
/// fresh sync.
#[derive(Serialize, Deserialize, Clone, Debug, PartialEq)]
#[serde(rename_all = "camelCase")]
pub struct PluginSyncFailure {
    pub name: String,
    pub message: String,
}

#[derive(Default)]
pub struct ReconcileOutcome {
    pub installed: Vec<InstallOutcome>,
    pub updated: Vec<InstallOutcome>,
    pub removed: Vec<String>,
    pub failed: Vec<PluginSyncFailure>,
}

impl ReconcileOutcome {
    /// Whether anything actually changed on disk — the caller only needs to
    /// restart FrankenPHP when this is true (a no-op pass, the common case
    /// once a device is already converged, should never trigger a reload).
    pub fn changed(&self) -> bool {
        !self.installed.is_empty() || !self.updated.is_empty() || !self.removed.is_empty()
    }
}

/// Fetch the catalog and converge `plugins_root` to match it: install
/// anything missing, update anything whose installed version doesn't match
/// the catalog's latest, remove anything installed that the catalog no
/// longer lists (a revoked entitlement). Only a catalog-fetch failure is a
/// hard `Err` — nothing to converge against without it. Every per-plugin
/// install/update/remove failure is collected into `failed` and does not
/// stop the rest of the pass.
pub fn reconcile(
    client: &Client,
    cfg: &Config,
    access_token: &str,
    plugins_root: &Path,
) -> Result<ReconcileOutcome, String> {
    let catalog = api::fetch_catalog(client, cfg, access_token)?;
    let installed_names = installer::list_installed(plugins_root)?;

    let mut outcome = ReconcileOutcome::default();

    for entry in &catalog {
        let Some(latest) = latest_version(entry) else {
            // A catalog entry with no resolvable version is a data problem
            // on the server, not something a device can act on — skip it
            // rather than failing the whole pass.
            continue;
        };

        let is_installed = installed_names.iter().any(|n| n == &entry.name);
        if !is_installed {
            match installer::install(client, cfg, access_token, plugins_root, &entry.name, &latest.version, &latest.sha256) {
                Ok(result) => outcome.installed.push(result),
                Err(message) => outcome.failed.push(PluginSyncFailure { name: entry.name.clone(), message }),
            }
            continue;
        }

        // Missing/corrupt marker also forces an update — self-healing back
        // to a known-good state rather than leaving an unverifiable install.
        let current_version = installer::installed_version(plugins_root, &entry.name);
        if current_version.as_deref() != Some(latest.version.as_str()) {
            match installer::update(client, cfg, access_token, plugins_root, &entry.name, &latest.version, &latest.sha256) {
                Ok(result) => outcome.updated.push(result),
                Err(message) => outcome.failed.push(PluginSyncFailure { name: entry.name.clone(), message }),
            }
        }
    }

    for name in &installed_names {
        if catalog.iter().any(|entry| &entry.name == name) {
            continue;
        }
        match installer::uninstall(plugins_root, name) {
            Ok(()) => outcome.removed.push(name.clone()),
            Err(message) => outcome.failed.push(PluginSyncFailure { name: name.clone(), message }),
        }
    }

    Ok(outcome)
}

fn latest_version(entry: &CatalogEntry) -> Option<&super::CatalogVersion> {
    entry.versions.iter().find(|v| v.version == entry.latest_version)
}
