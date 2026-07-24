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

use printers::common::base::job::PrinterJobOptions;
use printers::get_default_printer;

/// Print `text` to the OS default printer. Returns the printer's name on
/// success, so the frontend can show what it printed to.
#[tauri::command]
pub fn print_text(text: String) -> Result<String, String> {
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
