//! Worked example of the "add a crate for custom native functionality"
//! pattern this boilerplate exists to demonstrate: printing is something a
//! plain web app cannot do, so it's implemented as a Rust crate (`printers`)
//! plus one `#[tauri::command]`, called from the frontend exactly like the
//! DemoCatalog commands (see printer-demo.tsx).
//!
//! To add your OWN native capability, follow this same recipe:
//!   1. Add the crate to Cargo.toml's [dependencies].
//!   2. Write a `#[tauri::command] fn your_command(...) -> Result<T, String>`.
//!   3. Register it in lib.rs's `tauri::generate_handler![...]` list.
//!   4. Call it from the frontend via `invoke("your_command", { ... })`.
//!
//! LIMITATION (deliberate, for this demo): this only demonstrates "print raw
//! bytes at the printer's default settings" — no paper size, DPI, or
//! orientation control, because the `printers` crate's cross-platform
//! abstraction doesn't expose those controls. If your app needs precise
//! physical output (e.g. forms or labels at exact dimensions), don't reach
//! for a higher-level abstraction crate at all — go straight to the
//! platform's own print API instead: Windows GDI/Print Spooler, macOS Core
//! Graphics, Linux CUPS raw mode. Each of those is its own
//! `#[tauri::command]`, following the same 4-step recipe above.

use printers::common::base::job::PrinterJobOptions;
use printers::get_default_printer;

/// Print `text` to the OS default printer. Returns the printer's name on
/// success. Shared by the direct `print_text` Tauri command (below) and the
/// native-bridge HTTP endpoint (`php_host::native_bridge`) that the bundled
/// PHP plugin host calls into — one implementation, two callers.
pub fn print_text_impl(text: &str) -> Result<String, String> {
    let printer = get_default_printer().ok_or_else(|| "No default printer configured".to_string())?;

    // `Converter` (a field of PrinterJobOptions) is a private type of the
    // `printers` crate, so build on its own `none()` default rather than
    // naming it directly.
    let options = PrinterJobOptions {
        name: Some("Whity Tauri template print job"),
        ..PrinterJobOptions::none()
    };

    printer
        .print(text.as_bytes(), options)
        // PrintersError doesn't implement Display, only Debug.
        .map_err(|e| format!("Failed to print: {e:?}"))?;

    Ok(printer.name)
}

/// Print `text` to the OS default printer. Returns the printer's name on
/// success, so the frontend can show what it printed to.
#[tauri::command]
pub fn print_text(text: String) -> Result<String, String> {
    print_text_impl(&text)
}
