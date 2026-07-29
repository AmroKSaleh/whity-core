//! Blocking HTTP calls to the DemoCatalog sync API (WC-desktop-sync / PR-A2).
//! Every call carries the caller's `Bearer` access token; responses are the
//! `{data: item}` / `{data:[...], cursor, hasMore}` / `409 {serverItem}`
//! envelopes the backend returns.

use reqwest::blocking::Client;
use reqwest::StatusCode;
use serde::Deserialize;

use super::ServerItem;

/// A distinguishable HTTP failure: `Unauthorized` triggers a token refresh +
/// retry by the caller; everything else is surfaced as-is.
#[derive(Debug)]
pub enum HttpError {
    Unauthorized,
    Other(String),
}

impl std::fmt::Display for HttpError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            HttpError::Unauthorized => write!(f, "unauthorized"),
            HttpError::Other(m) => write!(f, "{m}"),
        }
    }
}

/// Outcome of a conditional write (update/delete).
#[derive(Debug)]
pub enum WriteOutcome {
    /// The write applied; carries the new server row.
    Applied(ServerItem),
    /// Optimistic-concurrency mismatch; carries the current server row.
    Conflict(ServerItem),
    /// The row no longer exists server-side.
    NotFound,
}

/// A page of the changes feed.
#[derive(Debug)]
pub struct ChangesPage {
    pub items: Vec<ServerItem>,
    pub cursor: String,
    pub has_more: bool,
}

#[derive(Deserialize)]
struct DataEnvelope {
    data: ServerItem,
}

#[derive(Deserialize)]
struct ConflictEnvelope {
    #[serde(rename = "serverItem")]
    server_item: ServerItem,
}

#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct ChangesEnvelope {
    data: Vec<ServerItem>,
    cursor: String,
    has_more: bool,
}

/// GET the changes feed since `cursor` (tombstones included), one page.
pub fn fetch_changes(
    client: &Client,
    api_base: &str,
    token: &str,
    cursor: &str,
    limit: u32,
) -> Result<ChangesPage, HttpError> {
    let url = format!(
        "{api_base}/demo-catalog/items?updatedSince={cursor}&includeDeleted=1&limit={limit}"
    );
    let resp = client
        .get(url)
        .bearer_auth(token)
        .header("X-Requested-With", "XMLHttpRequest")
        .send()
        .map_err(net_err)?;

    let status = resp.status();
    if status == StatusCode::UNAUTHORIZED {
        return Err(HttpError::Unauthorized);
    }
    let text = body_text(resp)?;
    if !status.is_success() {
        return Err(HttpError::Other(format!("changes feed failed ({}): {}", status.as_u16(), truncate(&text))));
    }
    let env: ChangesEnvelope = serde_json::from_str(&text)
        .map_err(|e| HttpError::Other(format!("invalid changes response: {e}")))?;

    Ok(ChangesPage { items: env.data, cursor: env.cursor, has_more: env.has_more })
}

/// POST create — idempotent on `client_uuid` (201 created / 200 replay both
/// return the row).
pub fn create(
    client: &Client,
    api_base: &str,
    token: &str,
    client_uuid: &str,
    name: &str,
    description: Option<&str>,
    status: &str,
) -> Result<ServerItem, HttpError> {
    let resp = client
        .post(format!("{api_base}/demo-catalog/items"))
        .bearer_auth(token)
        .header("X-Requested-With", "XMLHttpRequest")
        .json(&serde_json::json!({
            "clientUuid": client_uuid,
            "name": name,
            "description": description,
            "status": status,
        }))
        .send()
        .map_err(net_err)?;

    let status_code = resp.status();
    if status_code == StatusCode::UNAUTHORIZED {
        return Err(HttpError::Unauthorized);
    }
    let text = body_text(resp)?;
    if !status_code.is_success() {
        return Err(HttpError::Other(format!("create failed ({}): {}", status_code.as_u16(), truncate(&text))));
    }
    let env: DataEnvelope = serde_json::from_str(&text)
        .map_err(|e| HttpError::Other(format!("invalid create response: {e}")))?;

    Ok(env.data)
}

/// PATCH update with optimistic `baseVersion` — 200 applied / 409 conflict.
pub fn update(
    client: &Client,
    api_base: &str,
    token: &str,
    server_id: i64,
    base_version: i64,
    name: &str,
    description: Option<&str>,
    status: &str,
) -> Result<WriteOutcome, HttpError> {
    let resp = client
        .patch(format!("{api_base}/demo-catalog/items/{server_id}"))
        .bearer_auth(token)
        .header("X-Requested-With", "XMLHttpRequest")
        .header("If-Match", base_version.to_string())
        .json(&serde_json::json!({
            "name": name,
            "description": description,
            "status": status,
            "baseVersion": base_version,
        }))
        .send()
        .map_err(net_err)?;

    write_outcome(resp, "update")
}

/// DELETE (soft-delete) with optimistic `baseVersion` — 200 applied / 409 conflict.
pub fn delete(
    client: &Client,
    api_base: &str,
    token: &str,
    server_id: i64,
    base_version: i64,
) -> Result<WriteOutcome, HttpError> {
    let resp = client
        .delete(format!("{api_base}/demo-catalog/items/{server_id}"))
        .bearer_auth(token)
        .header("X-Requested-With", "XMLHttpRequest")
        .header("If-Match", base_version.to_string())
        .send()
        .map_err(net_err)?;

    write_outcome(resp, "delete")
}

fn write_outcome(resp: reqwest::blocking::Response, ctx: &str) -> Result<WriteOutcome, HttpError> {
    let status = resp.status();
    if status == StatusCode::UNAUTHORIZED {
        return Err(HttpError::Unauthorized);
    }
    if status == StatusCode::NOT_FOUND {
        return Ok(WriteOutcome::NotFound);
    }
    let text = body_text(resp)?;
    if status == StatusCode::CONFLICT {
        let env: ConflictEnvelope = serde_json::from_str(&text)
            .map_err(|e| HttpError::Other(format!("invalid {ctx} conflict response: {e}")))?;
        return Ok(WriteOutcome::Conflict(env.server_item));
    }
    if !status.is_success() {
        return Err(HttpError::Other(format!("{ctx} failed ({}): {}", status.as_u16(), truncate(&text))));
    }
    let env: DataEnvelope = serde_json::from_str(&text)
        .map_err(|e| HttpError::Other(format!("invalid {ctx} response: {e}")))?;

    Ok(WriteOutcome::Applied(env.data))
}

fn net_err(e: reqwest::Error) -> HttpError {
    HttpError::Other(format!("network error: {e}"))
}

fn body_text(resp: reqwest::blocking::Response) -> Result<String, HttpError> {
    resp.text().map_err(|e| HttpError::Other(format!("failed reading response: {e}")))
}

fn truncate(s: &str) -> String {
    if s.len() > 200 {
        format!("{}…", &s[..200])
    } else {
        s.to_string()
    }
}
