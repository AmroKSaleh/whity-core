# Bundled plugins root (`PLUGINS_ROOT`)

This directory ships empty. It's the **bundled, read-only** plugin root —
scanned at boot alongside the writable, server-synced root (see
`src-tauri/src/plugins/reconcile.rs`), bundled-first so a downloaded plugin
can never shadow one shipped here.

Real plugins reach a device automatically: every successful login reconciles
this device's plugins to exactly match the connected backend's catalog, with
no manual install control anywhere in the UI. Dropping a plugin directory in
*here* instead is for a fork that genuinely wants an always-on local plugin
baked into the installer, independent of any server — see
`README.md#the-offline-php-plugin-host` at the template root for the full
loader/discovery contract a plugin dropped in here needs to satisfy.
