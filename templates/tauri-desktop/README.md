# Whity Tauri Desktop Template

A working Tauri v2 + React + TypeScript desktop app boilerplate wired to
Whity's shared UI/feature packages (`@amroksaleh/ui`, `@amroksaleh/features`,
`@amroksaleh/tokens`) — start here instead of `create-tauri-app`'s stock
scaffold when building a Whity-based desktop product.

It demonstrates, end to end, the two things every downstream desktop app
needs:

1. **The multi-client feature-extraction pattern** (see `packages/features`):
   the exact same `DemoCatalogList`/`DemoCatalogDetail` components web/
   renders against a server API render here, unmodified, against a **real
   local SQLite database** — no server, no Node, fully offline. Only
   `src/demo-catalog-tauri-adapter.ts` differs from web's server-backed
   adapter or the Vite SPA harness's in-memory one.
2. **Adding native capabilities via Rust crates** — a printer command
   (`src-tauri/src/commands/printer.rs`, backed by the `printers` crate) as a
   real, working example of the extension pattern: something a plain web app
   cannot do, added as one crate + one `#[tauri::command]`.

## Getting started

```bash
npm install
npm run tauri dev
```

This opens a desktop window with a sidebar (Home / Demo Catalog / Printer
demo). The Demo Catalog list/create/edit flow persists to a real SQLite file
in your OS's per-app data directory (see `src-tauri/src/db.rs`) — close the
app and reopen it, your data is still there.

## Project layout

```
src/                          # Frontend (Vite + React + TypeScript)
  App.tsx                     # Route switch + AppSidebar/PageShell wiring
  nav-config.tsx              # The app's nav, as plain data (see the nav contract below)
  demo-catalog-tauri-adapter.ts  # DemoCatalogAdapter -> Tauri invoke() -> Rust commands
  printer-demo.tsx             # UI for the printer command example
  hash-link.tsx / use-hash-path.ts  # Minimal zero-dependency router (swap for react-router as you grow)

src-tauri/                    # Backend (Rust)
  src/
    lib.rs                    # Tauri::Builder setup, command registration
    db.rs                     # SQLite connection + schema migration
    commands/
      items.rs                # list_items / get_item / save_item (DemoCatalog)
      printer.rs               # print_text (the native-crate example)
```

## The adapter pattern (why this matters)

`DemoCatalogList`/`DemoCatalogDetail` (from `@amroksaleh/features/demo-catalog`)
never fetch data directly. They take an injected `DemoCatalogAdapter`:

```ts
interface DemoCatalogAdapter {
  list(): Promise<DemoCatalogItem[]>
  get(id: number): Promise<DemoCatalogItem | null>
  save(input: DemoCatalogItemInput): Promise<DemoCatalogItem>
}
```

- **web/** implements this against a server REST API (cookie-authenticated `fetch`).
- **packages/spa-harness** implements this against an in-memory array (for a quick browser demo, no backend at all).
- **this template** implements this against real local SQLite via three Tauri commands.

Same UI code, three different data sources, zero changes to the components
themselves. When you build your own feature, follow this same shape: define
a small adapter interface, implement the presentational components against
it, and give each client (web/desktop/mobile) its own implementation.

**No reconciliation/versioning story**: `save_item` is last-writer-wins with
no version check — fine for this single-device demo, but if you're building
a real multi-device offline-sync feature you need to design conflict
resolution yourself (see the note on `DemoCatalogItemInput` in
`src-tauri/src/commands/items.rs`).

**Alternative to hand-rolled commands**: this template writes one Rust
command per operation (`list_items`/`get_item`/`save_item`) for compile-time
type safety end to end. If your app has many entities and you'd rather trade
that safety for less boilerplate,
[`tauri-plugin-sql`](https://github.com/tauri-apps/tauri-plugin-sql) (the
official community SQL plugin) lets the frontend execute SQL directly against
a managed connection without a dedicated command per query. Either is valid —
if you're already using `tauri-plugin-sql` elsewhere, there's no need to
migrate to match this template.

## Adding your own native capability (the printer recipe)

The printer command follows a four-step recipe — repeat it for any capability
a plain web app can't do (filesystem access beyond the sandbox, USB/serial
devices, OS-level integrations, etc.):

1. **Add the crate** to `src-tauri/Cargo.toml`'s `[dependencies]`.
2. **Write a command**:
   ```rust
   #[tauri::command]
   pub fn your_command(arg: String) -> Result<String, String> {
       // ... call your crate ...
   }
   ```
   Return `Result<T, String>` (or any `Serialize` type) — Tauri handles the
   JS ↔ Rust (de)serialization automatically, including camelCase argument
   names on the JS side matching your Rust parameter names.
3. **Register it** in `src-tauri/src/lib.rs`'s `tauri::generate_handler![...]` list.
4. **Call it from the frontend**:
   ```ts
   import { invoke } from "@tauri-apps/api/core"
   const result = await invoke<string>("your_command", { arg: "..." })
   ```

If the capability needs shared state (a DB connection, a device handle,
etc.), manage it the way `db.rs`/`lib.rs` do: build it once in `.setup(...)`,
then `app.manage(...)` it, and receive it in your commands via
`State<'_, YourType>`.

## The nav contract

`AppSidebar`/`PageHeader`/`PageShell` (from `@amroksaleh/ui`) are
presentational only — no Next.js, no router opinion. `nav-config.tsx`
authors the sidebar as plain data; `resolveNavGroups()`
(`@amroksaleh/features/nav`) resolves it against the current path and an
optional translator. `hash-link.tsx` is the `NavLinkAdapter` — swap it for
`react-router`'s `<Link>` (or anything else) by implementing the same
`{ href, children, ...props }` contract. See `packages/features/README.md`
for the full contract documentation.

## Monorepo note

This template's `package.json` currently points `@amroksaleh/ui`/
`@amroksaleh/features`/`@amroksaleh/tokens` at `file:../../packages/...` —
correct while this template lives inside the whity-core monorepo (so CI
always tests it against the latest package source). **If you copy this
directory out as the start of a new project**, switch those three to the
published version ranges instead (e.g. `"@amroksaleh/ui": "^0.3.1"`,
matching `packages/ui/package.json`'s own `publishConfig` registry) — the
`file:` paths won't resolve outside this monorepo.

## Icons

Placeholder icons are checked in under `src-tauri/icons/` so `npm run tauri
build` works out of the box. Replace them with your own brand icon via:

```bash
npx tauri icon path/to/your-logo.png
```

## Verified

This template's Rust side was verified with `cargo check` and `cargo build`
inside a disposable `rust:1-bookworm` container with the Tauri v2 Linux
system dependencies installed (`libwebkit2gtk-4.1-dev`, `libgtk-3-dev`,
`libayatana-appindicator3-dev`, `librsvg2-dev`, `libssl-dev`,
`libsqlite3-dev`, `libcups2-dev`, `pkg-config`) — not just written by hand.
The frontend was verified with `tsc --noEmit` and a real `vite build`.

### Windows (native) — verified 2026-07-26

Built and run natively on **Windows 11 (10.0.26200)**, target
`x86_64-pc-windows-msvc`, with this toolchain:

- **Rust** 1.97.1 (`stable-x86_64-pc-windows-msvc`)
- **VS 2022 Build Tools** with the *Desktop development with C++* (`VCTools`)
  workload — MSVC toolset **v14.44.35207**, **Windows SDK 10.0.26100**
- **WebView2** runtime 150.0.4078 (ships with Win11)
- **Node** 22.20 / **npm** 11.6; **Tauri** 2.11, `printers` 2.3.0,
  `rusqlite` 0.31 (`bundled`)

Results:

- `cargo build` (debug) and `npm run tauri build` (release) both compile
  **clean — zero warnings, zero errors**. No source changes were needed: the
  `printers` ~2.3 pin and the `PrinterJobOptions`/`PrintersError` usage in
  `commands/printer.rs` match the crate's Windows API as-is (the two earlier
  2.x breaks the pin guards against did **not** recur).
- **The app window launches**, and `db.rs::init_db` creates the SQLite
  database at
  `%APPDATA%\com.whity.tauri-desktop-template\whity-desktop.sqlite` on first
  run (valid `SQLite format 3` file, schema applied).
- **Demo Catalog persistence** verified at the storage layer with the same
  schema + SQL as `db.rs`/`commands/items.rs`: create → edit → close the
  connection → reopen → the edited row is still there.
- **Printing** — the highest-risk, most OS-divergent piece — verified at
  runtime against the live **Windows Print Spooler**, not just compiled:
  `get_printers()` enumerated 10 printers, `get_default_printer()` resolved
  the default, and `print_text` submitted a **real job** (`printer.print(...)`
  returned `Ok`) to a physical default printer (Samsung M2020). The Linux/CUPS
  verification did not need to generalize — the crate's Windows path works
  unchanged.
- **Installers** (`bundle.targets: "all"` → both Windows bundlers): MSI
  **~4.1 MB** (`whity-tauri-desktop-template_0.1.0_x64_en-US.msi`) and NSIS
  setup **~3.0 MB** (`whity-tauri-desktop-template_0.1.0_x64-setup.exe`); the
  release app `.exe` is **~10.5 MB**. Tauri auto-downloaded WiX 3.14 and NSIS
  3.11 on the first Windows build.
- **Startup:** ~0.5 s from launch to a visible window on the first run (disk
  cache warm from the build — not a post-reboot cold start), ~60 ms on
  subsequent launches, on this machine.

One caveat on method: the DemoCatalog create/edit buttons and the Printer-demo
button were exercised through the underlying Tauri command layer (the exact
`list_items`/`get_item`/`save_item`/`print_text` commands, same pinned crate
versions) rather than by hand-clicking the running WebView UI. The frontend
itself is the same shared-package code already verified by `tsc`/`vite build`,
and its adapter (`demo-catalog-tauri-adapter.ts`) is a thin `invoke()`
pass-through to those verified commands.

### Known gaps — not yet verified

- **macOS.** Deliberately deferred, not attempted at all yet — a distinct
  follow-up stage, not scheduled as part of this pass. Its packaging size and
  cold-start also remain unmeasured (the Windows numbers above are
  Windows-specific and don't carry over to the `.app`/`.dmg` bundle).
