mod auth;
mod commands;
mod config;
mod db;
mod php_host;
mod plugins;
mod self_update;
mod sync;

use db::Db;
use std::sync::{Arc, Mutex, RwLock};
use tauri::Manager;

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_shell::init())
        .plugin(tauri_plugin_updater::Builder::new().build())
        .setup(|app| {
            // DB opens (and migrates, incl. v7's auth_state.server_url) BEFORE
            // Config is resolved — a previously chosen backend URL lives there.
            let connection = db::open(app.handle())?;
            let stored_server_url = db::auth_repo::get_server_url(&connection)?;
            let cfg = config::Config::resolve(stored_server_url);
            // Shared with sync::scheduler's background loop so a runtime
            // set_backend_url call (the login screen's Server field) reaches
            // both the next auth command AND the next sync tick.
            let shared_cfg = Arc::new(RwLock::new(cfg));
            app.manage(Db(Mutex::new(connection)));
            app.manage(auth::AuthManager::new(shared_cfg.clone())?);

            // Background sync loop on its OWN WAL connection so a cycle's network
            // I/O never blocks the UI connection's reads (see sync::scheduler).
            let sync_conn = db::open_sync_connection(app.handle())?;
            let sync_handle = sync::scheduler::spawn(app.handle().clone(), shared_cfg, sync_conn)?;
            app.manage(sync_handle);

            // Bundled PHP plugin host: a native bridge (Rust -> hardware) plus
            // the FrankenPHP sidecar serving real whity plugins offline
            // (see php_host/).
            let php_host = php_host::init(app.handle().clone())?;
            app.manage(php_host);

            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            commands::items::list_items,
            commands::items::get_item,
            commands::items::save_item,
            commands::items::delete_item,
            commands::drafts::save_draft,
            commands::drafts::get_draft,
            commands::drafts::discard_draft,
            commands::auth::auth_enroll,
            commands::auth::auth_login,
            commands::auth::auth_logout,
            commands::auth::auth_status,
            commands::auth::auth_lock_state,
            commands::auth::get_backend_url,
            commands::auth::set_backend_url,
            commands::sync::sync_now,
            commands::sync::get_sync_status,
            commands::sync::list_conflicts,
            commands::sync::resolve_conflict,
            commands::printer::print_text,
            commands::php_host::php_request,
            commands::php_host::php_host_status,
            commands::plugins::plugin_sync_status,
        ])
        .build(tauri::generate_context!())
        .expect("error while building tauri application")
        .run(|app_handle, event| {
            if let tauri::RunEvent::ExitRequested { .. } = event {
                if let Some(php_host) = app_handle.try_state::<php_host::PhpHostHandle>() {
                    php_host.shutdown();
                }
            }
        });
}
