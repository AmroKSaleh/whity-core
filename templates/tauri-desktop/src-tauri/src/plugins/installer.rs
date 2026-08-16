//! Download -> verify -> extract -> commit pipeline for a plugin package
//! fetched from the chosen backend's desktop-plugins endpoint. Mirrors the
//! server's own `PluginInstaller.php` guards — zip-slip/zip-bomb extraction
//! limits, a filesystem-safe name allowlist, atomic stage-then-rename commit
//! — but runs entirely in Rust: unlike the server, the desktop host has no
//! isolated PHP subprocess to introspect an untrusted package in, so instead
//! it trusts the SHA-256 the caller already fetched from the catalog (the
//! tenant's own already-authenticated backend, not an arbitrary upload).
//!
//! Lands ENABLED, not disabled-then-explicit-enable: there is no admin-
//! approval workflow on a single-user desktop, and (PHP side)
//! `PluginRequirementsGate::gateAndOrder()` already quarantines a broken or
//! incompatible plugin without crashing boot — the same safety net a
//! "disabled" landing would give, without a second install step.

use std::io::Read;
use std::path::{Path, PathBuf};

use reqwest::blocking::Client;
use sha2::{Digest, Sha256};
use uuid::Uuid;
use zip::ZipArchive;

use super::{api, InstallOutcome};
use crate::config::Config;

/// Same cap the server enforces on an uploaded/store-fetched package
/// (`PluginInstaller::MAX_UPLOAD_BYTES` in whity-core) — a client-side mirror
/// that fails fast rather than buffering something huge; the server's own
/// limit is the actual security boundary.
pub const MAX_PACKAGE_BYTES: u64 = 33_554_432; // 32 MiB

/// Same four zip-bomb guards as `PluginInstaller::safeExtract()`.
const MAX_ZIP_ENTRIES: usize = 2_000;
const MAX_ENTRY_UNCOMPRESSED_BYTES: u64 = 16_777_216; // 16 MiB
const MAX_TOTAL_UNCOMPRESSED_BYTES: u64 = 67_108_864; // 64 MiB
const MAX_COMPRESSION_RATIO: u64 = 200;
const RATIO_MIN_COMPRESSED_BYTES: u64 = 256;

/// Download, verify, extract and atomically install one plugin version into
/// `plugins_root` (the writable `plugins-downloaded/` dir — see
/// `php_host::PhpHostHandle::plugins_root()`). Does NOT restart FrankenPHP;
/// the caller (`commands::plugins::plugin_install`) does that on success.
pub fn install(
    client: &Client,
    cfg: &Config,
    access_token: &str,
    plugins_root: &Path,
    name: &str,
    version: &str,
    expected_sha256: &str,
) -> Result<InstallOutcome, String> {
    validate_name(name)?;
    validate_path_segment(version).map_err(|_| "Invalid plugin version.".to_string())?;

    let dest = plugins_root.join(name);
    if dest.exists() {
        return Err(format!(
            "Plugin '{name}' is already installed (no overwrite/upgrade in this version)."
        ));
    }

    let bytes = api::download_package(client, cfg, access_token, name, version, MAX_PACKAGE_BYTES)?;
    verify_checksum(&bytes, expected_sha256)?;

    let stage_root = plugins_root.join(format!(".tmp-{}", Uuid::new_v4()));
    std::fs::create_dir_all(&stage_root).map_err(|e| format!("failed to create a staging directory: {e}"))?;

    let result = (|| -> Result<(), String> {
        safe_extract(&bytes, &stage_root)?;
        let extracted_name = single_top_level_dir(&stage_root)?;
        if extracted_name != name {
            return Err(format!(
                "the package's top-level directory ('{extracted_name}') does not match the requested plugin name ('{name}')"
            ));
        }
        // Re-check right before commit: another install could have raced in
        // during the network round trip / extraction above.
        if dest.exists() {
            return Err(format!(
                "Plugin '{name}' is already installed (no overwrite/upgrade in this version)."
            ));
        }
        std::fs::rename(stage_root.join(&extracted_name), &dest)
            .map_err(|e| format!("failed to install the plugin: {e}"))?;
        Ok(())
    })();

    // Clean up the staging parent either way: on success only the now-empty
    // `.tmp-*` dir remains (its one child was renamed OUT to `dest`); on
    // failure this removes whatever was partially extracted.
    let _ = std::fs::remove_dir_all(&stage_root);
    result?;

    Ok(InstallOutcome {
        name: name.to_string(),
        version: version.to_string(),
    })
}

/// Same allowlist as `PluginInstaller::NAME_PATTERN` server-side — the name
/// becomes a directory under `plugins_root`.
fn validate_name(name: &str) -> Result<(), String> {
    let valid = !name.is_empty() && name.chars().all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '-');
    if !valid {
        return Err("Invalid plugin name — must match ^[A-Za-z0-9_-]+$".to_string());
    }
    Ok(())
}

/// Looser check for a value interpolated into a URL path segment and an error
/// message (the version string): no path separators or traversal, printable
/// ASCII only. Not the authoritative validator (the server's own regex
/// already ran server-side against this exact value) — this just keeps a
/// value that only ever came from the server's catalog response out of harm's
/// way before it's reused here.
fn validate_path_segment(s: &str) -> Result<(), String> {
    let safe = !s.is_empty()
        && s != "."
        && s != ".."
        && !s.contains(['/', '\\'])
        && s.chars().all(|c| c.is_ascii_graphic());
    if !safe {
        return Err("invalid value".to_string());
    }
    Ok(())
}

fn verify_checksum(bytes: &[u8], expected_hex: &str) -> Result<(), String> {
    let mut hasher = Sha256::new();
    hasher.update(bytes);
    let actual = hex_encode(&hasher.finalize());
    if !actual.eq_ignore_ascii_case(expected_hex) {
        return Err("Downloaded package failed checksum verification.".to_string());
    }
    Ok(())
}

fn hex_encode(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}

/// Two-pass safe extraction mirroring `PluginInstaller::safeExtract()`: pass 1
/// validates every entry (path safety + the zip-bomb limits above) and writes
/// nothing; pass 2 writes, with each resolved path re-anchored under the
/// canonicalized stage dir.
fn safe_extract(bytes: &[u8], stage_root: &Path) -> Result<(), String> {
    let mut archive =
        ZipArchive::new(std::io::Cursor::new(bytes)).map_err(|e| format!("the package could not be opened as a zip: {e}"))?;

    let entry_count = archive.len();
    if entry_count == 0 {
        return Err("the package is empty.".to_string());
    }
    if entry_count > MAX_ZIP_ENTRIES {
        return Err("the package contains too many entries.".to_string());
    }

    // Pass 1: inspect-only.
    let mut total_uncompressed: u64 = 0;
    let mut total_compressed: u64 = 0;
    for i in 0..entry_count {
        let entry = archive
            .by_index(i)
            .map_err(|e| format!("a package entry could not be read: {e}"))?;
        let name = entry.name().to_string();
        assert_safe_entry_name(&name)?;

        if name.ends_with('/') {
            continue; // directory entry, no payload
        }
        let uncompressed = entry.size();
        if uncompressed > MAX_ENTRY_UNCOMPRESSED_BYTES {
            return Err("a package entry is too large when uncompressed.".to_string());
        }
        total_uncompressed += uncompressed;
        total_compressed += entry.compressed_size();
        if total_uncompressed > MAX_TOTAL_UNCOMPRESSED_BYTES {
            return Err("the package is too large when fully uncompressed.".to_string());
        }
    }
    if total_compressed >= RATIO_MIN_COMPRESSED_BYTES && total_uncompressed / total_compressed > MAX_COMPRESSION_RATIO {
        return Err("the package's compression ratio exceeds the safe limit.".to_string());
    }

    // Pass 2: write, re-anchored under the canonicalized stage root.
    let anchor =
        std::fs::canonicalize(stage_root).map_err(|e| format!("the staging directory is unavailable: {e}"))?;

    for i in 0..entry_count {
        let mut entry = archive
            .by_index(i)
            .map_err(|e| format!("a package entry could not be read: {e}"))?;
        let name = entry.name().to_string();
        let target = resolve_entry_target(&anchor, &name)?;

        if name.ends_with('/') {
            std::fs::create_dir_all(&target).map_err(|e| format!("failed to create a directory in the package: {e}"))?;
            continue;
        }
        if let Some(parent) = target.parent() {
            std::fs::create_dir_all(parent).map_err(|e| format!("failed to create a directory in the package: {e}"))?;
        }
        let mut contents = Vec::with_capacity(entry.size() as usize);
        entry
            .read_to_end(&mut contents)
            .map_err(|e| format!("a package entry could not be extracted: {e}"))?;
        std::fs::write(&target, contents).map_err(|e| format!("a package entry could not be written: {e}"))?;
    }

    Ok(())
}

/// Reject any entry name that could escape the extraction root (zip-slip) —
/// same structural checks as `PluginInstaller::assertSafeEntryPath()`.
fn assert_safe_entry_name(name: &str) -> Result<(), String> {
    if name.is_empty() {
        return Err("the package contains an entry with an empty name.".to_string());
    }
    if name.starts_with('/') || name.contains('\\') {
        return Err("the package contains an unsafe (absolute) entry path.".to_string());
    }
    let bytes = name.as_bytes();
    if bytes.len() >= 2 && bytes[0].is_ascii_alphabetic() && bytes[1] == b':' {
        return Err("the package contains an unsafe (drive-letter) entry path.".to_string());
    }
    if name.split('/').any(|segment| segment == "..") {
        return Err("the package contains an unsafe (traversal) entry path.".to_string());
    }
    Ok(())
}

/// Resolve + re-anchor an entry's on-disk target, failing closed if the
/// normalized path would land outside `anchor` — belt-and-braces alongside
/// `assert_safe_entry_name()`.
fn resolve_entry_target(anchor: &Path, name: &str) -> Result<PathBuf, String> {
    let mut resolved = anchor.to_path_buf();
    for segment in name.split('/') {
        if segment.is_empty() || segment == "." {
            continue;
        }
        resolved.push(segment);
    }
    if !resolved.starts_with(anchor) {
        return Err("a package entry resolves outside the extraction root.".to_string());
    }
    Ok(resolved)
}

/// The single top-level directory name inside the extracted stage — a
/// package with zero or more than one top-level entry (or a top-level entry
/// that isn't a directory) is rejected.
fn single_top_level_dir(stage_root: &Path) -> Result<String, String> {
    let entries: Vec<_> = std::fs::read_dir(stage_root)
        .map_err(|e| format!("failed to read the extracted package: {e}"))?
        .filter_map(|e| e.ok())
        .collect();

    if entries.len() != 1 || !entries[0].path().is_dir() {
        return Err("the package must contain exactly one top-level plugin directory.".to_string());
    }

    entries[0]
        .file_name()
        .into_string()
        .map_err(|_| "the package's top-level directory name is not valid UTF-8.".to_string())
}
