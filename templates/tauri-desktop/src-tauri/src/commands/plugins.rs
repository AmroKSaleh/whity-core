//! Frontend-facing commands for the downloadable plugin catalog/install flow
//! (WC-desktop-plugins). Both commands do a FRESH credential exchange before
//! calling the backend — mirroring `sync::scheduler::run_cycle` — since
//! `AuthManager`'s cached access token is only refreshed on `auth_enroll`/
//! `auth_login` and may be stale by the time a user browses plugins hours
//! later.

use tauri::State;

use crate::auth::{api as auth_api, credential_store, AuthManager};
use crate::php_host::PhpHostHandle;
use crate::plugins::{api, installer, CatalogEntry, InstallOutcome};

/// List this tenant's entitled desktop plugin catalog.
#[tauri::command]
pub fn plugin_catalog(auth: State<'_, AuthManager>) -> Result<Vec<CatalogEntry>, String> {
    let access_token = fresh_access_token(&auth)?;
    api::fetch_catalog(auth.client(), &auth.config(), &access_token)
}

/// Download, verify and install one plugin version, then reload FrankenPHP so
/// it picks it up (the worker only discovers plugins once, at boot).
#[tauri::command]
pub fn plugin_install(
    auth: State<'_, AuthManager>,
    php_host: State<'_, PhpHostHandle>,
    name: String,
    version: String,
    expected_sha256: String,
) -> Result<InstallOutcome, String> {
    let access_token = fresh_access_token(&auth)?;
    let outcome = installer::install(
        auth.client(),
        &auth.config(),
        &access_token,
        php_host.plugins_root(),
        &name,
        &version,
        &expected_sha256,
    )?;
    php_host.restart_php();
    Ok(outcome)
}

/// Exchange the stored device credential for a fresh access token — the same
/// pattern `sync::scheduler::run_cycle` uses (see `src/sync/scheduler.rs`),
/// since `AuthManager`'s cached access token is only refreshed on enroll/login.
fn fresh_access_token(auth: &AuthManager) -> Result<String, String> {
    let credential = credential_store::load()?.ok_or_else(|| "not enrolled — enroll a device first".to_string())?;
    let session = auth_api::exchange(auth.client(), &auth.config(), &credential)?;
    Ok(session.access_token)
}
