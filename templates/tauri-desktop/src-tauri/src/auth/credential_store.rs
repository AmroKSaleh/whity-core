//! OS-native secure storage for the long-lived (90-day) device credential, via
//! the `keyring` crate — Windows Credential Manager / macOS Keychain / Linux
//! Secret Service. This is the ONLY secret persisted to disk; the short-lived
//! access token stays in memory (see `AuthManager`). Its blast radius is thus
//! just the credential, which the server can revoke (per-device or via a
//! password-change epoch bump).
//!
//! NOTE (documented in the README): this protects the credential at rest via the
//! OS keystore, but the local SQLite DB itself is not encrypted — layer SQLCipher
//! if at-rest confidentiality of local *data* is required. Orthogonal to this.

use keyring::Entry;

const SERVICE: &str = "com.whity.tauri-desktop-template";
const CREDENTIAL_ACCOUNT: &str = "device-credential";

fn entry() -> Result<Entry, String> {
    Entry::new(SERVICE, CREDENTIAL_ACCOUNT).map_err(|e| format!("keychain unavailable: {e}"))
}

/// Store (or replace) the device credential.
pub fn store(credential: &str) -> Result<(), String> {
    entry()?
        .set_password(credential)
        .map_err(|e| format!("failed to store device credential: {e}"))
}

/// Load the stored device credential, or `None` if the device isn't enrolled.
pub fn load() -> Result<Option<String>, String> {
    match entry()?.get_password() {
        Ok(credential) => Ok(Some(credential)),
        Err(keyring::Error::NoEntry) => Ok(None),
        Err(e) => Err(format!("failed to read device credential: {e}")),
    }
}

/// Remove the stored credential (logout / un-enroll). Idempotent.
pub fn clear() -> Result<(), String> {
    match entry()?.delete_credential() {
        Ok(()) | Err(keyring::Error::NoEntry) => Ok(()),
        Err(e) => Err(format!("failed to clear device credential: {e}")),
    }
}
