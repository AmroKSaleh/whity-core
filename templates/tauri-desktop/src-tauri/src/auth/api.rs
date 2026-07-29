//! Blocking HTTP calls to the Whity backend's device-auth endpoints. Done in
//! Rust (not the webview) so the device credential + access token never enter
//! JS. Every function returns `Result<_, String>` with a human-readable message
//! the command layer surfaces to the UI.

use reqwest::blocking::{Client, Response};
use serde::Deserialize;
use serde_json::json;

use crate::config::Config;

/// Outcome of a login attempt — mirrors the backend's multi-step auth.
pub enum LoginOutcome {
    /// A full session (single tenant, no 2FA) — carries the bearer access token.
    Session { access_token: String },
    /// 2FA is required; complete it with `temp_token` (a follow-up handles this).
    Requires2fa { temp_token: String },
    /// The profile has multiple tenants; a selection is required.
    RequiresTenantSelection { selection_token: Option<String> },
}

#[derive(Deserialize)]
pub struct EnrolledDevice {
    pub id: i64,
    pub credential: String,
    pub expires_at: String,
}

#[derive(Deserialize)]
pub struct ExchangedSession {
    pub access_token: String,
    /// The tenant's resolved desktop-login TTL, echoed by a PR-A1 backend. Absent
    /// on an older backend → the client falls back to its own default. The client
    /// derives its offline-lock window from this + the local auth clock, so the
    /// backend's companion `desktop_login_expires_at` isn't parsed here.
    #[serde(default)]
    pub desktop_login_max_seconds: Option<i64>,
}

/// Build the shared blocking HTTP client (reused for every call).
pub fn build_client() -> Result<Client, String> {
    Client::builder()
        .user_agent("whity-tauri-desktop-template")
        .build()
        .map_err(|e| format!("failed to build HTTP client: {e}"))
}

/// POST /login in token mode. Branches on the response shape.
pub fn login(
    client: &Client,
    cfg: &Config,
    email: &str,
    password: &str,
) -> Result<LoginOutcome, String> {
    let resp = client
        .post(format!("{}/login", cfg.api_base()))
        .header("X-Auth-Mode", "token")
        .header("X-Requested-With", "XMLHttpRequest")
        .json(&json!({ "email": email, "password": password }))
        .send()
        .map_err(net_err)?;

    let status = resp.status();
    let body: serde_json::Value = resp
        .json()
        .map_err(|e| format!("login: invalid response: {e}"))?;

    if !status.is_success() {
        return Err(format!(
            "login failed ({}): {}",
            status.as_u16(),
            body.get("error").and_then(|v| v.as_str()).unwrap_or("unknown error")
        ));
    }
    if let Some(token) = body.get("access_token").and_then(|v| v.as_str()) {
        return Ok(LoginOutcome::Session { access_token: token.to_string() });
    }
    if body.get("requires_2fa").and_then(|v| v.as_bool()).unwrap_or(false) {
        let temp_token = body
            .get("temp_token")
            .and_then(|v| v.as_str())
            .unwrap_or_default()
            .to_string();
        return Ok(LoginOutcome::Requires2fa { temp_token });
    }
    if body
        .get("requires_tenant_selection")
        .and_then(|v| v.as_bool())
        .unwrap_or(false)
    {
        let selection_token = body
            .get("selection_token")
            .and_then(|v| v.as_str())
            .map(str::to_string);
        return Ok(LoginOutcome::RequiresTenantSelection { selection_token });
    }
    Err("login: unexpected response shape".to_string())
}

/// POST /devices — enroll this device, returning the long-lived credential.
pub fn register_device(
    client: &Client,
    cfg: &Config,
    access_token: &str,
    name: &str,
    platform: &str,
) -> Result<EnrolledDevice, String> {
    let resp = client
        .post(format!("{}/devices", cfg.api_base()))
        .bearer_auth(access_token)
        .header("X-Requested-With", "XMLHttpRequest")
        .json(&json!({ "name": name, "platform": platform }))
        .send()
        .map_err(net_err)?;
    parse_or_err(resp, "device registration")
}

/// POST /devices/token — exchange the credential for a fresh access session.
pub fn exchange(client: &Client, cfg: &Config, credential: &str) -> Result<ExchangedSession, String> {
    let resp = client
        .post(format!("{}/devices/token", cfg.api_base()))
        .bearer_auth(credential)
        .header("X-Requested-With", "XMLHttpRequest")
        .send()
        .map_err(net_err)?;
    parse_or_err(resp, "credential exchange")
}

/// POST /auth/logout — best-effort server-side revocation of the access token.
pub fn logout(client: &Client, cfg: &Config, access_token: &str) -> Result<(), String> {
    let resp = client
        .post(format!("{}/auth/logout", cfg.api_base()))
        .bearer_auth(access_token)
        .header("X-Requested-With", "XMLHttpRequest")
        .send()
        .map_err(net_err)?;
    if resp.status().is_success() {
        Ok(())
    } else {
        Err(format!("logout failed ({})", resp.status().as_u16()))
    }
}

fn net_err(e: reqwest::Error) -> String {
    format!("network error reaching the server: {e}")
}

fn parse_or_err<T: for<'de> Deserialize<'de>>(resp: Response, ctx: &str) -> Result<T, String> {
    let status = resp.status();
    let text = resp
        .text()
        .map_err(|e| format!("{ctx}: failed to read response: {e}"))?;
    if !status.is_success() {
        let detail = serde_json::from_str::<serde_json::Value>(&text)
            .ok()
            .and_then(|v| v.get("error").and_then(|e| e.as_str()).map(str::to_string))
            .unwrap_or_else(|| truncate(&text));
        return Err(format!("{ctx} failed ({}): {}", status.as_u16(), detail));
    }
    serde_json::from_str(&text).map_err(|e| format!("{ctx}: invalid response: {e}"))
}

fn truncate(s: &str) -> String {
    if s.len() > 200 {
        format!("{}…", &s[..200])
    } else {
        s.to_string()
    }
}
