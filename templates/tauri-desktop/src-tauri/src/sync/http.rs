//! Blocking HTTP calls speaking the sync wire contract (WC-desktop-sync /
//! PR-A2), generalized (WC-sync-generalize) to any REST base path (not tied
//! to `resource::ResourceDescriptor` — these functions never touch a table
//! name or a domain-column list, only a URL) and to an OPTIONAL bearer token:
//! `token: None` is what `sync::bridge` needs when the "server" on one leg of
//! a relay is actually the local PHP host, whose routes take no bearer token
//! at all (see `php_host::proxy`). Every call otherwise carries the caller's
//! token; responses are the `{data: item}` / `{data:[...], cursor, hasMore}`
//! / `409 {serverItem}` envelopes the wire contract defines.

use reqwest::blocking::{Client, RequestBuilder};
use reqwest::StatusCode;
use serde::Deserialize;

use super::SyncRow;

/// A classified HTTP failure so the engine can react: `Unauthorized` aborts the
/// cycle (needs re-auth), `Retryable` (network / 5xx / 429) backs the row off and
/// retries later, `Permanent` (other 4xx — e.g. validation) is surfaced and not
/// retried on a short cycle.
#[derive(Debug)]
pub enum HttpError {
    Unauthorized,
    Retryable(String),
    Permanent(String),
}

impl std::fmt::Display for HttpError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            HttpError::Unauthorized => write!(f, "unauthorized"),
            HttpError::Retryable(m) | HttpError::Permanent(m) => write!(f, "{m}"),
        }
    }
}

/// Classify a non-success response: server errors + rate-limits are transient;
/// other 4xx are permanent (retrying won't help).
fn classify(ctx: &str, status: StatusCode, text: &str) -> HttpError {
    let msg = format!("{ctx} failed ({}): {}", status.as_u16(), truncate(text));
    if status.is_server_error() || status == StatusCode::TOO_MANY_REQUESTS {
        HttpError::Retryable(msg)
    } else {
        HttpError::Permanent(msg)
    }
}

/// Outcome of a conditional write (update/delete).
#[derive(Debug)]
pub enum WriteOutcome {
    /// The write applied; carries the new server row.
    Applied(SyncRow),
    /// Optimistic-concurrency mismatch; carries the current server row.
    Conflict(SyncRow),
    /// The row no longer exists server-side.
    NotFound,
}

/// A page of the changes feed.
#[derive(Debug)]
pub struct ChangesPage {
    pub items: Vec<SyncRow>,
    pub cursor: String,
    pub has_more: bool,
}

#[derive(Deserialize)]
struct DataEnvelope {
    data: SyncRow,
}

#[derive(Deserialize)]
struct ConflictEnvelope {
    #[serde(rename = "serverItem")]
    server_item: SyncRow,
}

#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct ChangesEnvelope {
    data: Vec<SyncRow>,
    cursor: String,
    has_more: bool,
}

/// Attach a bearer token when present; the PHP-host leg of a bridge relay
/// passes `None` (php-host's `RbacGate` needs no bearer token — see
/// `php_host::proxy`).
fn authed(req: RequestBuilder, token: Option<&str>) -> RequestBuilder {
    let req = req.header("X-Requested-With", "XMLHttpRequest");
    match token {
        Some(t) => req.bearer_auth(t),
        None => req,
    }
}

/// GET the changes feed since `cursor` (tombstones included), one page.
pub fn fetch_changes(
    client: &Client,
    api_base: &str,
    token: Option<&str>,
    base_path: &str,
    cursor: &str,
    limit: u32,
) -> Result<ChangesPage, HttpError> {
    let url = format!("{api_base}{base_path}?updatedSince={cursor}&includeDeleted=1&limit={limit}");
    let resp = authed(client.get(url), token).send().map_err(net_err)?;

    let status = resp.status();
    if status == StatusCode::UNAUTHORIZED {
        return Err(HttpError::Unauthorized);
    }
    let text = body_text(resp)?;
    if !status.is_success() {
        return Err(classify("changes feed", status, &text));
    }
    let env: ChangesEnvelope = serde_json::from_str(&text)
        .map_err(|e| HttpError::Permanent(format!("invalid changes response: {e}")))?;

    Ok(ChangesPage { items: env.data, cursor: env.cursor, has_more: env.has_more })
}

/// POST create — idempotent on `client_uuid` (201 created / 200 replay both
/// return the row). `domain` carries the resource's domain fields.
pub fn create(
    client: &Client,
    api_base: &str,
    token: Option<&str>,
    base_path: &str,
    client_uuid: &str,
    domain: &serde_json::Map<String, serde_json::Value>,
) -> Result<SyncRow, HttpError> {
    let mut body = domain.clone();
    body.insert("clientUuid".to_string(), serde_json::Value::String(client_uuid.to_string()));

    let resp = authed(client.post(format!("{api_base}{base_path}")), token).json(&body).send().map_err(net_err)?;

    let status_code = resp.status();
    if status_code == StatusCode::UNAUTHORIZED {
        return Err(HttpError::Unauthorized);
    }
    let text = body_text(resp)?;
    if !status_code.is_success() {
        return Err(classify("create", status_code, &text));
    }
    let env: DataEnvelope = serde_json::from_str(&text)
        .map_err(|e| HttpError::Permanent(format!("invalid create response: {e}")))?;

    Ok(env.data)
}

/// PATCH update with optimistic `baseVersion` — 200 applied / 409 conflict.
pub fn update(
    client: &Client,
    api_base: &str,
    token: Option<&str>,
    base_path: &str,
    server_id: i64,
    base_version: i64,
    domain: &serde_json::Map<String, serde_json::Value>,
) -> Result<WriteOutcome, HttpError> {
    let mut body = domain.clone();
    body.insert("baseVersion".to_string(), serde_json::Value::from(base_version));

    let resp = authed(client.patch(format!("{api_base}{base_path}/{server_id}")), token)
        .header("If-Match", base_version.to_string())
        .json(&body)
        .send()
        .map_err(net_err)?;

    write_outcome(resp, "update")
}

/// DELETE (soft-delete) with optimistic `baseVersion` — 200 applied / 409 conflict.
pub fn delete(
    client: &Client,
    api_base: &str,
    token: Option<&str>,
    base_path: &str,
    server_id: i64,
    base_version: i64,
) -> Result<WriteOutcome, HttpError> {
    let resp = authed(client.delete(format!("{api_base}{base_path}/{server_id}")), token)
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
            .map_err(|e| HttpError::Permanent(format!("invalid {ctx} conflict response: {e}")))?;
        return Ok(WriteOutcome::Conflict(env.server_item));
    }
    if !status.is_success() {
        return Err(classify(ctx, status, &text));
    }
    let env: DataEnvelope = serde_json::from_str(&text)
        .map_err(|e| HttpError::Permanent(format!("invalid {ctx} response: {e}")))?;

    Ok(WriteOutcome::Applied(env.data))
}

fn net_err(e: reqwest::Error) -> HttpError {
    // A failed send (DNS/connect/timeout) is transient → retry with backoff.
    HttpError::Retryable(format!("network error reaching the server: {e}"))
}

fn body_text(resp: reqwest::blocking::Response) -> Result<String, HttpError> {
    resp.text()
        .map_err(|e| HttpError::Retryable(format!("failed reading response: {e}")))
}

fn truncate(s: &str) -> String {
    if s.len() > 200 {
        format!("{}…", &s[..200])
    } else {
        s.to_string()
    }
}
