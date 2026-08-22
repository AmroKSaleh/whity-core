//! Blocking HTTP calls to the Whity backend's device-auth endpoints. Done in
//! Rust (not the webview) so the device credential + access token never enter
//! JS. Every function returns `Result<_, String>` with a human-readable message
//! the command layer surfaces to the UI.

use reqwest::blocking::{Client, Response};
use serde::{Deserialize, Serialize};
use serde_json::json;

use crate::config::Config;

/// Outcome of a login attempt — mirrors the backend's multi-step auth.
pub enum LoginOutcome {
    /// A full session (single tenant, no 2FA) — carries the bearer access token.
    Session { access_token: String },
    /// 2FA is required; complete it with `temp_token` (a follow-up handles this).
    Requires2fa { temp_token: String },
    /// The profile holds ACTIVE memberships in more than one tenant, so the
    /// backend issued NO session and is waiting for a choice (ADR 0005 §6).
    /// Carries the list to present AND the short-lived token that completes it.
    RequiresTenantSelection {
        selection_token: Option<String>,
        memberships: Vec<TenantMembership>,
    },
}

/// One tenant the signed-in profile may complete the login into.
///
/// Deserialized from the backend's snake_case JSON, re-serialized to the
/// webview in camelCase (see the command layer's `EnrollResult`) — hence the
/// hand-rolled parse below rather than a `Deserialize` derive that would have
/// to agree with both casings at once.
///
/// `tenant_id` 0 is the SYSTEM tenant and is deliberately NOT special-cased
/// here. Per `AuthHandler::handleSelectTenant()`, a caller who genuinely holds
/// an active tenant-0 membership selecting it is legitimate system authority;
/// the escalation that was closed is a multi-membership profile having tenant 0
/// silently AUTO-picked. So this client renders every membership as an equal
/// choice and never chooses on the operator's behalf — not even when there is
/// one obviously-likely candidate. The server re-validates the choice regardless.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct TenantMembership {
    pub tenant_id: i64,
    /// Display name; may be empty if the tenant row carries none. May be
    /// Arabic — the UI renders it with `dir="auto"`.
    pub tenant_name: String,
    /// The profile's role in that tenant ("" when the membership has no role).
    pub role: String,
}

/// The session minted by completing a tenant selection.
pub struct SelectedSession {
    pub access_token: String,
    /// The email the server echoes for the chosen session. Authoritative over
    /// whatever was typed at the credentials step; `None` only if a backend
    /// omits `user.email`, in which case the caller falls back to the typed one.
    pub email: Option<String>,
}

/// Why `select_tenant` failed, split so the UI can tell a RETRYABLE lapse apart
/// from a hard failure.
pub enum SelectTenantError {
    /// HTTP 401. The 300-second selection token expired, was never issued, or
    /// the membership was revoked while the picker sat on screen. All three are
    /// fixed the same way — sign in again — so this must never surface as a
    /// dead end.
    Lapsed,
    /// Anything else (network, other 4xx/5xx); the message is shown as-is.
    Failed(String),
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
        // Bounded waits so an unreachable/slow backend fails the sync cycle
        // quickly (surfacing the offline/"Sync now" banner) instead of freezing
        // the UI on a hung request. The engine's per-row backoff then retries.
        .connect_timeout(std::time::Duration::from_secs(5))
        .timeout(std::time::Duration::from_secs(30))
        .build()
        .map_err(|e| format!("failed to build HTTP client: {e}"))
}

/// Build the blocking client used by `remote_request` (the remote peer of the
/// local `php_request`). Same timeouts/user-agent as `build_client`, but with
/// redirects DISABLED (`Policy::none()`): a desktop client has no browser
/// cookie jar, so an auth/SSO 3xx must surface as its real status (302/401)
/// rather than being transparently followed into an HTML login page that would
/// masquerade as a `200` and break the JSON contract the admin APIs speak.
pub fn build_remote_client() -> Result<Client, String> {
    Client::builder()
        .user_agent("whity-tauri-desktop-template")
        .connect_timeout(std::time::Duration::from_secs(5))
        .timeout(std::time::Duration::from_secs(30))
        .redirect(reqwest::redirect::Policy::none())
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
        return Ok(LoginOutcome::RequiresTenantSelection {
            selection_token,
            memberships: parse_memberships(body.get("memberships")),
        });
    }
    Err("login: unexpected response shape".to_string())
}

/// Read the login prompt's `memberships` array.
///
/// Lenient by design: a malformed or missing list must not turn a recoverable
/// "pick a tenant" into a failed login, and a single unreadable entry must not
/// hide the others. Only `tenant_id` is required — a nameless tenant still gets
/// rendered (by id) rather than silently dropped from the operator's choices.
fn parse_memberships(value: Option<&serde_json::Value>) -> Vec<TenantMembership> {
    let Some(rows) = value.and_then(|v| v.as_array()) else {
        return Vec::new();
    };
    rows.iter().filter_map(parse_membership).collect()
}

fn parse_membership(row: &serde_json::Value) -> Option<TenantMembership> {
    let tenant_id = row.get("tenant_id")?.as_i64()?;
    Some(TenantMembership {
        tenant_id,
        tenant_name: row
            .get("tenant_name")
            .and_then(|v| v.as_str())
            .unwrap_or_default()
            .to_string(),
        role: row.get("role").and_then(|v| v.as_str()).unwrap_or_default().to_string(),
    })
}

/// POST /auth/select-tenant in token mode — complete a multi-membership login
/// by naming the chosen tenant, and receive the session it mints.
///
/// The server re-validates that the caller STILL holds an active membership in
/// `tenant_id` before minting anything (see `AuthHandler::handleSelectTenant()`),
/// so this call carries no authority of its own: it forwards a choice the
/// operator made and the short-lived token that binds it to their login.
pub fn select_tenant(
    client: &Client,
    cfg: &Config,
    selection_token: &str,
    tenant_id: i64,
) -> Result<SelectedSession, SelectTenantError> {
    let resp = client
        .post(format!("{}/auth/select-tenant", cfg.api_base()))
        .header("X-Auth-Mode", "token")
        .header("X-Requested-With", "XMLHttpRequest")
        .json(&json!({ "selection_token": selection_token, "tenant_id": tenant_id }))
        .send()
        .map_err(|e| SelectTenantError::Failed(net_err(e)))?;

    let status = resp.status();
    let text = resp
        .text()
        .map_err(|e| SelectTenantError::Failed(format!("tenant selection: failed to read response: {e}")))?;
    let body = serde_json::from_str::<serde_json::Value>(&text).ok();

    // 401 is the whole retryable family: expired/absent selection token, or a
    // membership revoked mid-flow. Distinguished HERE so the command layer can
    // send the operator back to the credentials step instead of a dead end.
    if status == reqwest::StatusCode::UNAUTHORIZED {
        return Err(SelectTenantError::Lapsed);
    }
    if !status.is_success() {
        let detail = body
            .as_ref()
            .and_then(|v| v.get("error"))
            .and_then(|v| v.as_str())
            .map(str::to_string)
            .unwrap_or_else(|| truncate(&text));
        return Err(SelectTenantError::Failed(format!(
            "tenant selection failed ({}): {}",
            status.as_u16(),
            detail
        )));
    }

    let body = body.ok_or_else(|| {
        SelectTenantError::Failed("tenant selection: invalid response (not JSON)".to_string())
    })?;
    let access_token = body
        .get("access_token")
        .and_then(|v| v.as_str())
        .ok_or_else(|| {
            SelectTenantError::Failed("tenant selection: response carried no session".to_string())
        })?
        .to_string();
    let email = body
        .get("user")
        .and_then(|u| u.get("email"))
        .and_then(|v| v.as_str())
        .map(str::to_string);

    Ok(SelectedSession { access_token, email })
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

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    /// The exact shape `AuthHandler::requireTenantSelection()` emits in token
    /// mode, including the system tenant — which is a choice like any other
    /// here, never filtered out and never picked automatically.
    #[test]
    fn reads_the_backend_membership_list_verbatim() {
        let body = json!({
            "requires_tenant_selection": true,
            "selection_token": "tok",
            "memberships": [
                { "tenant_id": 0, "tenant_name": "System", "role": "system_admin" },
                { "tenant_id": 4, "tenant_name": "شركة الأمل", "role": "admin" },
            ],
        });

        let parsed = parse_memberships(body.get("memberships"));

        assert_eq!(
            parsed,
            vec![
                TenantMembership {
                    tenant_id: 0,
                    tenant_name: "System".to_string(),
                    role: "system_admin".to_string(),
                },
                TenantMembership {
                    tenant_id: 4,
                    tenant_name: "شركة الأمل".to_string(),
                    role: "admin".to_string(),
                },
            ]
        );
    }

    /// A nameless/roleless tenant is still a tenant the operator may hold
    /// authority in — render it by id rather than dropping it from the choices.
    #[test]
    fn keeps_entries_missing_a_name_or_role() {
        let parsed = parse_memberships(Some(&json!([{ "tenant_id": 9 }])));

        assert_eq!(
            parsed,
            vec![TenantMembership { tenant_id: 9, tenant_name: String::new(), role: String::new() }]
        );
    }

    /// One unreadable entry must not hide the readable ones: dropping the whole
    /// list would strand a login that the server is willing to complete.
    #[test]
    fn skips_only_the_entries_it_cannot_read() {
        let parsed = parse_memberships(Some(&json!([
            { "tenant_name": "no id" },
            "not an object",
            { "tenant_id": "3" },
            { "tenant_id": 3, "tenant_name": "Acme", "role": "admin" },
        ])));

        assert_eq!(parsed.len(), 1);
        assert_eq!(parsed[0].tenant_id, 3);
    }

    /// An absent or non-array `memberships` yields an empty list, not a panic —
    /// the caller still has the selection token and reports the empty case.
    #[test]
    fn tolerates_an_absent_or_malformed_list() {
        assert!(parse_memberships(None).is_empty());
        assert!(parse_memberships(Some(&json!(null))).is_empty());
        assert!(parse_memberships(Some(&json!("nope"))).is_empty());
    }

    /// The webview contract: snake_case in from the backend, camelCase out to
    /// JS (`auth-client.ts`'s `TenantMembership`).
    #[test]
    fn serializes_to_the_camel_case_the_webview_expects() {
        let json = serde_json::to_string(&TenantMembership {
            tenant_id: 0,
            tenant_name: "System".to_string(),
            role: "system_admin".to_string(),
        })
        .unwrap();

        assert_eq!(json, r#"{"tenantId":0,"tenantName":"System","role":"system_admin"}"#);
    }
}
