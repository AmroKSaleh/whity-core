//! Local HTTP server, run on its own OS thread, that the bundled PHP plugin
//! host calls into when a plugin needs native hardware (printers now,
//! scanners later). Loopback-only (`127.0.0.1`), auth'd with a fresh
//! per-launch secret — mirrors the shared-secret pattern whity-core's own
//! render-service already uses (`X-Render-Secret` / `timingSafeEqual`).
//!
//! Deliberately blocking (`tiny_http` on a plain `std::thread`), not
//! axum+tokio: this crate has no async runtime today (`reqwest::blocking`,
//! `sync::scheduler`'s plain-thread loop), and one low-throughput local
//! endpoint doesn't justify introducing a second, inconsistent execution
//! style.

use crate::commands::printer::print_text_impl;
use serde::{Deserialize, Serialize};
use std::thread;
use tiny_http::{Header, Method, Response, Server};
use uuid::Uuid;

/// Handle to the running native bridge: the port it's listening on and the
/// per-launch secret the PHP host must send on every request (via env var
/// at spawn time — never hardcoded, never written to disk).
pub struct NativeBridgeHandle {
    pub port: u16,
    pub secret: String,
}

#[derive(Deserialize)]
struct PrintRequest {
    text: String,
}

#[derive(Serialize)]
struct BridgeResponseBody {
    ok: bool,
    #[serde(skip_serializing_if = "Option::is_none")]
    printer: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    error: Option<String>,
}

/// Bind a fresh OS-assigned loopback port, generate a fresh secret, and start
/// serving on a dedicated thread. Returns immediately; the server runs for
/// the lifetime of the app process (no explicit shutdown needed — it's a
/// thread inside this process, torn down for free on process exit).
pub fn spawn() -> Result<NativeBridgeHandle, String> {
    let server =
        Server::http("127.0.0.1:0").map_err(|e| format!("failed to bind native bridge: {e}"))?;
    let port = server
        .server_addr()
        .to_ip()
        .ok_or_else(|| "native bridge has no local IP address".to_string())?
        .port();
    let secret = Uuid::new_v4().to_string();

    let thread_secret = secret.clone();
    thread::Builder::new()
        .name("whity-native-bridge".into())
        .spawn(move || run_loop(server, &thread_secret))
        .map_err(|e| format!("failed to start native bridge thread: {e}"))?;

    Ok(NativeBridgeHandle { port, secret })
}

fn run_loop(server: Server, secret: &str) {
    for request in server.incoming_requests() {
        handle_request(request, secret);
    }
}

fn handle_request(request: tiny_http::Request, secret: &str) {
    if !is_authorized(&request, secret) {
        respond_json(
            request,
            401,
            &BridgeResponseBody { ok: false, printer: None, error: Some("unauthorized".into()) },
        );
        return;
    }

    match (request.method(), request.url()) {
        (Method::Post, "/native/print") => handle_print(request),
        _ => respond_json(
            request,
            404,
            &BridgeResponseBody { ok: false, printer: None, error: Some("not found".into()) },
        ),
    }
}

fn handle_print(mut request: tiny_http::Request) {
    let mut body = String::new();
    if request.as_reader().read_to_string(&mut body).is_err() {
        respond_json(
            request,
            400,
            &BridgeResponseBody { ok: false, printer: None, error: Some("invalid body".into()) },
        );
        return;
    }

    let text = match serde_json::from_str::<PrintRequest>(&body) {
        Ok(parsed) => parsed.text,
        Err(_) => {
            respond_json(
                request,
                400,
                &BridgeResponseBody {
                    ok: false,
                    printer: None,
                    error: Some("text is required".into()),
                },
            );
            return;
        }
    };

    match print_text_impl(&text) {
        Ok(printer) => respond_json(
            request,
            200,
            &BridgeResponseBody { ok: true, printer: Some(printer), error: None },
        ),
        Err(e) => respond_json(
            request,
            500,
            &BridgeResponseBody { ok: false, printer: None, error: Some(e) },
        ),
    }
}

/// Constant-time secret comparison against the `X-Native-Bridge-Secret`
/// header — same idiom as render-service's `X-Render-Secret`, so a missing
/// or mismatched header can't be distinguished from a near-miss via timing.
fn is_authorized(request: &tiny_http::Request, secret: &str) -> bool {
    let provided = request
        .headers()
        .iter()
        .find(|h| h.field.equiv("X-Native-Bridge-Secret"))
        .map(|h| h.value.as_str());

    match provided {
        Some(value) => constant_time_eq(value.as_bytes(), secret.as_bytes()),
        None => false,
    }
}

fn constant_time_eq(a: &[u8], b: &[u8]) -> bool {
    if a.len() != b.len() {
        return false;
    }
    let mut diff = 0u8;
    for (x, y) in a.iter().zip(b.iter()) {
        diff |= x ^ y;
    }
    diff == 0
}

fn respond_json(request: tiny_http::Request, status: u16, body: &BridgeResponseBody) {
    let json = serde_json::to_string(body)
        .unwrap_or_else(|_| "{\"ok\":false,\"error\":\"serialization failed\"}".to_string());
    let content_type = Header::from_bytes(&b"Content-Type"[..], &b"application/json"[..])
        .expect("static header is valid");
    let response = Response::from_string(json)
        .with_status_code(status)
        .with_header(content_type);
    let _ = request.respond(response);
}
