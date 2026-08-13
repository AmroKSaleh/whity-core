//! Rust -> FrankenPHP HTTP client backing the `php_request` Tauri command.
//! Plain `reqwest::blocking` (already a crate dependency) — no new HTTP
//! client needed for one loopback call per invoke().

use crate::php_host::PhpHostHandle;
use serde::Serialize;
use std::time::Duration;

#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
pub struct PhpResponse {
    pub status: u16,
    pub body: serde_json::Value,
}

pub fn forward(
    php_host: &PhpHostHandle,
    method: &str,
    path: &str,
    body: Option<serde_json::Value>,
) -> Result<PhpResponse, String> {
    let client = reqwest::blocking::Client::builder()
        .timeout(Duration::from_secs(10))
        .build()
        .map_err(|e| format!("failed to build http client: {e}"))?;

    let http_method: reqwest::Method = method
        .parse()
        .map_err(|_| format!("invalid HTTP method: {method}"))?;
    let url = format!("http://127.0.0.1:{}{}", php_host.sidecar.port(), path);

    let mut request = client.request(http_method, &url);
    if let Some(json_body) = &body {
        request = request.json(json_body);
    }

    let response = request.send().map_err(|e| format!("php-host request failed: {e}"))?;
    let status = response.status().as_u16();
    let text = response.text().map_err(|e| format!("failed to read php-host response: {e}"))?;
    let parsed_body = if text.is_empty() {
        serde_json::Value::Null
    } else {
        serde_json::from_str(&text).unwrap_or(serde_json::Value::String(text))
    };

    Ok(PhpResponse { status, body: parsed_body })
}
