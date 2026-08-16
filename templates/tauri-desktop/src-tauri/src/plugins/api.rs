//! Blocking HTTP calls to the chosen Whity backend's desktop-plugins
//! endpoints — same blocking-reqwest + bearer-token style as `auth::api`, not
//! a separate mechanism. Every function returns `Result<_, String>` with a
//! human-readable message the command layer surfaces to the UI.

use reqwest::blocking::Client;
use serde::Deserialize;

use super::CatalogEntry;
use crate::config::Config;

#[derive(Deserialize)]
struct CatalogResponse {
    data: Vec<CatalogEntry>,
}

/// GET {api_base}/desktop-plugins — this tenant's entitled plugin catalog.
pub fn fetch_catalog(client: &Client, cfg: &Config, access_token: &str) -> Result<Vec<CatalogEntry>, String> {
    let resp = client
        .get(format!("{}/desktop-plugins", cfg.api_base()))
        .bearer_auth(access_token)
        .header("X-Requested-With", "XMLHttpRequest")
        .send()
        .map_err(net_err)?;

    let status = resp.status();
    let text = resp
        .text()
        .map_err(|e| format!("desktop plugin catalog: failed to read response: {e}"))?;
    if !status.is_success() {
        return Err(format!(
            "desktop plugin catalog failed ({}): {}",
            status.as_u16(),
            truncate(&text)
        ));
    }

    let parsed: CatalogResponse =
        serde_json::from_str(&text).map_err(|e| format!("desktop plugin catalog: invalid response: {e}"))?;
    Ok(parsed.data)
}

/// GET {api_base}/desktop-plugins/{name}/versions/{version}/download — raw
/// package bytes, size-capped at `max_bytes`. Checks `Content-Length` before
/// reading (fast reject on an oversized package) and the ACTUAL length after
/// (a `Content-Length` header is caller-suppliable, not proof) — mirrors the
/// server's own `PluginInstaller::MAX_UPLOAD_BYTES` defense-in-depth.
///
/// `name`/`version` are interpolated into the URL path; callers MUST validate
/// them first (see `installer::validate_name`/`validate_path_segment`) — this
/// function does not re-validate.
pub fn download_package(
    client: &Client,
    cfg: &Config,
    access_token: &str,
    name: &str,
    version: &str,
    max_bytes: u64,
) -> Result<Vec<u8>, String> {
    let url = format!(
        "{}/desktop-plugins/{}/versions/{}/download",
        cfg.api_base(),
        name,
        version
    );
    let resp = client
        .get(url)
        .bearer_auth(access_token)
        .header("X-Requested-With", "XMLHttpRequest")
        .send()
        .map_err(net_err)?;

    let status = resp.status();
    if !status.is_success() {
        return Err(format!("plugin download failed ({})", status.as_u16()));
    }

    if let Some(len) = resp.content_length() {
        if len > max_bytes {
            return Err(format!(
                "the plugin package exceeds the maximum allowed size ({max_bytes} bytes)"
            ));
        }
    }

    let bytes = resp
        .bytes()
        .map_err(|e| format!("plugin download: failed to read response: {e}"))?;
    if bytes.len() as u64 > max_bytes {
        return Err(format!(
            "the plugin package exceeds the maximum allowed size ({max_bytes} bytes)"
        ));
    }

    Ok(bytes.to_vec())
}

fn net_err(e: reqwest::Error) -> String {
    format!("network error reaching the server: {e}")
}

fn truncate(s: &str) -> String {
    if s.len() > 200 {
        format!("{}…", &s[..200])
    } else {
        s.to_string()
    }
}
