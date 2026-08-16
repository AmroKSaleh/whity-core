//! Whole-app self-update (WC-app-self-update), checked BEFORE every plugin
//! sync (see `commands::post_login`) since a downloaded plugin package
//! assumes a compatible app runtime.
//!
//! The endpoint and the bearer token are both set at the RUNTIME call site
//! via `app.updater_builder()`, not `tauri.conf.json`'s static config: this
//! app's backend URL is itself runtime-configurable per device (a user can
//! point their device at a different server on the login screen — see
//! `config::Config`), and the access token is always short-lived and
//! re-exchanged, so neither can be baked in at build time. `tauri.conf.json`
//! still needs the minisign PUBLIC key (`plugins.updater.pubkey`) — that one
//! genuinely is a build-time trust anchor.

use tauri::AppHandle;
use tauri_plugin_updater::UpdaterExt;

use crate::config::Config;

pub enum SelfUpdateOutcome {
    UpToDate,
    /// An update was found, downloaded, and installed; `app.request_restart()`
    /// has already been called. That call returns immediately (it signals the
    /// restart and lets Tauri's normal event loop carry it out — see the
    /// comment at its call site for why this is the right one to use instead
    /// of the diverging `app.restart()`), so this variant is what actually
    /// tells the caller "the process is about to go away, stop here."
    Relaunching,
}

/// Check the connected backend's desktop-app-updates endpoint for this
/// platform target; if a newer signed release exists, download, verify and
/// install it, then relaunch. Failures are logged and treated as `UpToDate`
/// — a self-update check must never block login or plugin sync.
pub fn check_and_apply(app: &AppHandle, cfg: &Config, access_token: &str) -> SelfUpdateOutcome {
    match check_and_apply_inner(app, cfg, access_token) {
        Ok(outcome) => outcome,
        Err(e) => {
            eprintln!("[self_update] check/apply failed (continuing as up to date): {e}");
            SelfUpdateOutcome::UpToDate
        }
    }
}

fn check_and_apply_inner(
    app: &AppHandle,
    cfg: &Config,
    access_token: &str,
) -> Result<SelfUpdateOutcome, String> {
    // `{{target}}`/`{{current_version}}` are substituted by the updater
    // plugin itself before the request is sent — see
    // `Whity\Api\DesktopAppUpdateApiHandler` for the server side that reads
    // them back out as `target`/`current_version` query params.
    let endpoint_url = format!(
        "{}/desktop-app-updates/latest?target={{{{target}}}}&current_version={{{{current_version}}}}",
        cfg.api_base()
    );
    let endpoint = endpoint_url
        .parse()
        .map_err(|e| format!("invalid self-update endpoint URL: {e}"))?;

    let updater = app
        .updater_builder()
        .endpoints(vec![endpoint])
        .map_err(|e| format!("failed to configure the self-update endpoint: {e}"))?
        .header("Authorization", format!("Bearer {access_token}"))
        .map_err(|e| format!("failed to attach the self-update auth header: {e}"))?
        .build()
        .map_err(|e| format!("failed to build the updater: {e}"))?;

    let maybe_update = tauri::async_runtime::block_on(updater.check()).map_err(|e| format!("update check failed: {e}"))?;

    let Some(update) = maybe_update else {
        return Ok(SelfUpdateOutcome::UpToDate);
    };

    tauri::async_runtime::block_on(update.download_and_install(|_chunk_length, _content_length| {}, || {}))
        .map_err(|e| format!("update download/install failed: {e}"))?;

    // `request_restart()` (not `restart()`) — reliably triggers
    // RunEvent::ExitRequested/Exit even called from this background thread,
    // so lib.rs's existing ExitRequested handler (php_host.shutdown()) still
    // runs before the process actually exits. It returns immediately rather
    // than blocking; the caller's early-return on `Relaunching` (see
    // `commands::post_login::spawn_after_login`) is what skips this
    // invocation's plugin sync — the relaunched instance syncs plugins on
    // its own next login.
    app.request_restart();
    Ok(SelfUpdateOutcome::Relaunching)
}
