//! Fires an automatic plugin sync after every successful online login
//! (WC-plugin-sync) — see `plugins::reconcile` for the diff/converge logic
//! this just wires up to the auth flow and the frontend. Runs fire-and-forget
//! on a background OS thread: a multi-plugin sync plus a FrankenPHP restart
//! (up to the existing 30s readiness-poll ceiling in `php_host::sidecar`) is
//! too slow to sit inside `auth_enroll`'s/`auth_login`'s awaited command
//! response. NOT hooked into `sync::scheduler`'s 45s/15s background
//! heartbeat — that's a connectivity probe, not a login, and reconciling
//! every cycle would mean a catalog fetch (and possibly a restart) every
//! 45 seconds even when nothing on the server changed.

use std::time::{SystemTime, UNIX_EPOCH};

use tauri::{AppHandle, Emitter, Manager};

use crate::auth::AuthManager;
use crate::db::Db;
use crate::php_host::PhpHostHandle;
use crate::plugins::reconcile::{self, PluginSyncFailure};
use crate::self_update::{self, SelfUpdateOutcome};

#[derive(serde::Serialize, Clone)]
#[serde(rename_all = "camelCase", tag = "state")]
pub enum PluginSyncEvent {
    Syncing,
    Synced {
        installed: usize,
        updated: usize,
        removed: usize,
        failed: Vec<PluginSyncFailure>,
    },
    Failed {
        message: String,
    },
}

/// Reconcile this device's plugins against the connected backend's catalog,
/// then restart FrankenPHP only if something actually changed. Plugin-sync
/// failure must NEVER fail the login/enroll command itself — a down catalog
/// endpoint must not lock a user out of an app they already have installed;
/// it's surfaced via `plugin-sync:status` and `plugin_sync_state` instead.
pub fn spawn_after_login(app: AppHandle, auth: &AuthManager, access_token: String) {
    let client = auth.client().clone();
    let cfg = auth.config();

    let _ = std::thread::Builder::new().name("whity-post-login".into()).spawn(move || {
        // Self-update runs FIRST and strictly before plugin sync: a plugin
        // package assumes a compatible app runtime. If an update is found,
        // `check_and_apply` downloads, installs, and relaunches the process
        // (which exits and never returns in practice) — the process tearing
        // itself down IS what skips this invocation's plugin sync; the
        // relaunched instance syncs plugins on its own next login.
        if let SelfUpdateOutcome::Relaunching = self_update::check_and_apply(&app, &cfg, &access_token) {
            return;
        }

        let _ = app.emit("plugin-sync:status", PluginSyncEvent::Syncing);

        let plugins_root = app.state::<PhpHostHandle>().plugins_root().to_path_buf();

        match reconcile::reconcile(&client, &cfg, &access_token, &plugins_root) {
            Ok(outcome) => {
                if outcome.changed() {
                    app.state::<PhpHostHandle>().restart_php();
                }

                if let Ok(conn) = app.state::<Db>().0.lock() {
                    let _ = crate::db::plugin_sync_repo::record_success(&conn, now_epoch(), &outcome);
                }

                let _ = app.emit(
                    "plugin-sync:status",
                    PluginSyncEvent::Synced {
                        installed: outcome.installed.len(),
                        updated: outcome.updated.len(),
                        removed: outcome.removed.len(),
                        failed: outcome.failed,
                    },
                );
            }
            Err(message) => {
                if let Ok(conn) = app.state::<Db>().0.lock() {
                    let _ = crate::db::plugin_sync_repo::record_failure(&conn, &message);
                }
                let _ = app.emit("plugin-sync:status", PluginSyncEvent::Failed { message });
            }
        }
    });
}

fn now_epoch() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0)
}
