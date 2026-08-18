//! Frontend-facing command proxying to the enrolled REMOTE whity-core instance
//! — the remote peer of `php_request` (which hits the LOCAL bundled FrankenPHP
//! host). Server-owned admin surfaces (Roles, Users, the plugin catalog) live
//! on the backend the device enrolled against, not in an offline plugin, so
//! they need a transport that:
//!
//!   * forwards to the configured remote base URL (`config::Config::backend_url`,
//!     the same instance `sync`/`auth` already talk to),
//!   * authenticates as the enrolled device via the in-memory access token
//!     (NOT a browser cookie — a desktop app has no cookie jar), re-exchanging
//!     the keychain credential once on a `401` in case the cached token went
//!     stale (`AuthManager::refresh_access`),
//!   * does NOT follow 3xx transparently (its client is built with
//!     `Policy::none()` — see `auth::api::build_remote_client`), so an
//!     auth/SSO redirect surfaces as its real status instead of being chased
//!     into an HTML login page.
//!
//! The response shape mirrors `php_request`'s `PhpResponse { status, body }`
//! exactly, so the frontend adapters consume both transports identically.

use std::collections::HashMap;

use serde::Serialize;
use tauri::State;

use crate::auth::AuthManager;

/// The `{ status, body }` envelope — byte-for-byte the shape `php_host::proxy::
/// PhpResponse` returns, so a TS adapter can be pointed at either transport
/// without a shape change.
#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
pub struct RemoteResponse {
    pub status: u16,
    pub body: serde_json::Value,
}

/// Proxy `method path` to the enrolled remote instance, authenticated as this
/// device. `path` is an absolute path from the backend origin (e.g.
/// `/api/v1/roles`, `/api/v1/me/capabilities`, `/api/v1/desktop-plugins`);
/// it is appended to `backend_url` verbatim. `headers`, when present, are added
/// on top of the internal `X-Requested-With: XMLHttpRequest` marker (a caller
/// may override it).
#[tauri::command]
pub fn remote_request(
    auth: State<'_, AuthManager>,
    method: String,
    path: String,
    body: Option<serde_json::Value>,
    headers: Option<HashMap<String, String>>,
) -> Result<RemoteResponse, String> {
    let cfg = auth.config();
    let client = auth.remote_client();

    // Authenticate with the cached token when we have one; otherwise mint one
    // now (which errors distinctly if the device isn't enrolled). We remember
    // whether the token was cached, because only a CACHED token that 401s is
    // worth a refresh-and-retry — a token we just minted and that is already
    // rejected won't be fixed by minting another.
    let cached = auth.access_token();
    let token = match cached.clone() {
        Some(t) => t,
        None => auth.refresh_access()?,
    };

    let response = send(client, &cfg.backend_url, &method, &path, body.as_ref(), headers.as_ref(), &token)?;

    if response.status == 401 && cached.is_some() {
        // The cached token was stale — re-exchange the keychain credential once
        // and retry. A refresh failure (offline / revoked) leaves the original
        // 401 to surface, so the shared page shows a real auth error.
        if let Ok(fresh) = auth.refresh_access() {
            return send(client, &cfg.backend_url, &method, &path, body.as_ref(), headers.as_ref(), &fresh);
        }
    }

    Ok(response)
}

/// One authenticated round trip. Body parsing mirrors `proxy::forward` exactly:
/// empty → `null`, valid JSON → parsed, anything else → the raw text as a JSON
/// string (never an error — the caller inspects `status`).
fn send(
    client: &reqwest::blocking::Client,
    backend_url: &str,
    method: &str,
    path: &str,
    body: Option<&serde_json::Value>,
    headers: Option<&HashMap<String, String>>,
    token: &str,
) -> Result<RemoteResponse, String> {
    let http_method: reqwest::Method = method
        .parse()
        .map_err(|_| format!("invalid HTTP method: {method}"))?;
    let url = format!("{backend_url}{path}");

    let mut request = client
        .request(http_method, url)
        .header("X-Requested-With", "XMLHttpRequest")
        .bearer_auth(token);
    if let Some(extra) = headers {
        for (name, value) in extra {
            request = request.header(name, value);
        }
    }
    if let Some(json_body) = body {
        request = request.json(json_body);
    }

    let response = request
        .send()
        .map_err(|e| format!("network error reaching the server: {e}"))?;
    let status = response.status().as_u16();
    let text = response
        .text()
        .map_err(|e| format!("failed to read response: {e}"))?;
    let parsed_body = if text.is_empty() {
        serde_json::Value::Null
    } else {
        serde_json::from_str(&text).unwrap_or(serde_json::Value::String(text))
    };

    Ok(RemoteResponse { status, body: parsed_body })
}
