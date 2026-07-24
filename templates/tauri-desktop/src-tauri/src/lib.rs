mod commands;
mod db;

use commands::items::Db;
use std::sync::Mutex;
use tauri::Manager;

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_opener::init())
        .setup(|app| {
            let connection = db::init_db(app.handle())?;
            app.manage(Db(Mutex::new(connection)));
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            commands::items::list_items,
            commands::items::get_item,
            commands::items::save_item,
            commands::printer::print_text,
        ])
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
