//! Frontend-facing commands proxying to the bundled PHP plugin host. One
//! generic `php_request(method, path, body)` rather than per-route commands:
//! plugin routes are runtime data the PHP loader decides, so a fixed enum of
//! Rust commands would force a rebuild every time a plugin adds a route.

use crate::php_host::{proxy, PhpHostHandle};
use tauri::State;

#[tauri::command]
pub fn php_request(
    php_host: State<'_, PhpHostHandle>,
    method: String,
    path: String,
    body: Option<serde_json::Value>,
) -> Result<proxy::PhpResponse, String> {
    if !php_host.is_ready() {
        // Exact sentinel string the frontend matches on to show a loading
        // state rather than a generic error toast.
        return Err("php-host-not-ready".to_string());
    }

    proxy::forward(&php_host, &method, &path, body)
}

#[tauri::command]
pub fn php_host_status(php_host: State<'_, PhpHostHandle>) -> bool {
    php_host.is_ready()
}
