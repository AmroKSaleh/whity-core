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

#[cfg(test)]
mod integration {
    //! End-to-end check of the Document Designer's data path against the LIVE
    //! backend this device is enrolled against, over the exact transport the
    //! app uses (`super::send` + a real exchanged access token).
    //!
    //! `#[ignore]` so a plain `cargo test` skips it, and additionally gated on
    //! `WHITY_DESIGNER_E2E=1`: it CREATES AND DELETES a template on whatever
    //! instance this device enrolled against, which is a real tenant's data.
    //! Two locks rather than one, because the blast radius is somebody else's
    //! database and the device credential makes it effortless to reach.
    //!
    //! Requires an enrolled device (the credential comes from the OS keychain,
    //! exactly as `AuthManager` gets it — no password is read or stored here).
    //! Run with:
    //!   $env:WHITY_DESIGNER_E2E="1"; cargo test designer_round_trip -- --ignored --nocapture
    use super::*;
    use crate::auth::{api, credential_store};
    use crate::config::Config;
    use uuid::Uuid;

    fn opted_in() {
        assert_eq!(
            std::env::var("WHITY_DESIGNER_E2E").as_deref(),
            Ok("1"),
            "refusing to write to a live backend without WHITY_DESIGNER_E2E=1"
        );
    }

    /// A template payload the designer itself would submit: the v2 shape, one
    /// empty page, named so it is obvious in a list that it is a test artifact.
    fn probe_template(name: &str) -> serde_json::Value {
        serde_json::json!({
            "name": name,
            "data": {
                "version": 2,
                "name": name,
                "page": { "widthMm": 100, "heightMm": 100, "marginMm": 0, "background": "#ffffff" },
                "placeholders": [],
                "pages": [{ "id": "p1", "elements": [] }]
            }
        })
    }

    #[test]
    #[ignore = "writes to the live backend this device is enrolled against (WHITY_DESIGNER_E2E=1)"]
    fn designer_round_trip() {
        opted_in();

        let cfg = Config::resolve();
        let client = api::build_remote_client().expect("remote client");
        let credential = credential_store::load()
            .expect("keychain read")
            .expect("this device is not enrolled — run the app and sign in first");
        let token = api::exchange(&client, &cfg, &credential)
            .expect("exchange the device credential for a session")
            .access_token;

        let call = |method: &str, path: &str, body: Option<serde_json::Value>| {
            send(&client, &cfg.backend_url, method, path, body.as_ref(), None, &token)
                .unwrap_or_else(|e| panic!("{method} {path} failed: {e}"))
        };

        // 1. LIST — the call the designer makes on open.
        let listed = call("GET", "/api/v1/document-templates", None);
        assert_eq!(listed.status, 200, "list templates: {:?}", listed.body);
        let before = listed.body["data"].as_array().expect("data array").len();

        // 2. CREATE.
        let name = format!("e2e-designer-check-{}", Uuid::new_v4());
        let created = call("POST", "/api/v1/document-templates", Some(probe_template(&name)));
        assert!(
            (200..300).contains(&created.status),
            "create template: {} {:?}",
            created.status,
            created.body
        );
        let id = created.body["data"]["id"].as_i64().expect("created id");

        // 3. READ BACK — proves the canvas document survives the round trip,
        //    which is the whole point: `data` is a JSONB column holding the
        //    entire client object verbatim.
        let fetched = call("GET", &format!("/api/v1/document-templates/{id}"), None);
        assert_eq!(fetched.status, 200, "fetch template: {:?}", fetched.body);
        assert_eq!(fetched.body["data"]["name"], serde_json::json!(name));
        assert_eq!(fetched.body["data"]["data"]["version"], serde_json::json!(2));
        assert_eq!(fetched.body["data"]["data"]["pages"][0]["id"], serde_json::json!("p1"));

        // 4. It is in the collection the designer lists.
        let relisted = call("GET", "/api/v1/document-templates", None);
        assert_eq!(relisted.body["data"].as_array().unwrap().len(), before + 1);

        // 5. DELETE — cleanup is part of the test, not an afterthought: this
        //    runs against a real tenant and must leave nothing behind.
        let deleted = call("DELETE", &format!("/api/v1/document-templates/{id}"), None);
        assert!(
            (200..300).contains(&deleted.status),
            "delete template: {} {:?}",
            deleted.status,
            deleted.body
        );

        let after = call("GET", "/api/v1/document-templates", None);
        assert_eq!(
            after.body["data"].as_array().unwrap().len(),
            before,
            "the probe template was left behind"
        );

        // 6. The blocks collection the Palette reads.
        let blocks = call("GET", "/api/v1/document-blocks", None);
        assert_eq!(blocks.status, 200, "list blocks: {:?}", blocks.body);
        assert!(blocks.body["data"].is_array(), "blocks envelope is {{data: [...]}}");

        println!(
            "round trip OK against {} — {} template(s), {} block(s) visible",
            cfg.backend_url,
            before,
            blocks.body["data"].as_array().unwrap().len()
        );
    }
}
