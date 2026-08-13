//! Bridge to a bundled PHP runtime (FrankenPHP) that hosts real whity plugin
//! code offline. Two pieces coexist here, mirroring the direction each side
//! talks:
//!   - `native_bridge`: an HTTP server Rust runs so PHP plugin code can reach
//!     native hardware (printers now, scanners later).
//!   - `sidecar`: spawns and supervises the FrankenPHP child process that
//!     serves plugin routes back to Rust.
//!
//! See templates/tauri-desktop's plan doc for the full design.

#[cfg(target_os = "windows")]
pub mod job_object;
pub mod native_bridge;
pub mod proxy;
pub mod sidecar;

use tauri::AppHandle;

/// Managed app state combining both halves: the native bridge Rust runs, and
/// the FrankenPHP sidecar it's paired with.
pub struct PhpHostHandle {
    pub bridge: native_bridge::NativeBridgeHandle,
    pub sidecar: sidecar::PhpSidecarHandle,
}

impl PhpHostHandle {
    pub fn is_ready(&self) -> bool {
        self.sidecar.is_ready()
    }

    pub fn shutdown(&self) {
        self.sidecar.shutdown();
    }
}

/// Start the native bridge, then spawn the FrankenPHP sidecar pointed at it.
pub fn init(app: AppHandle) -> Result<PhpHostHandle, String> {
    let bridge = native_bridge::spawn()?;
    let sidecar = sidecar::spawn(app, &bridge)?;

    Ok(PhpHostHandle { bridge, sidecar })
}
