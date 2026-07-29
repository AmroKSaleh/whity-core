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

**Offline-first sync (WC-desktop-sync)**: the template now implements the real
multi-device sync this note used to defer — a cached device login, a versioned
two-way sync against the server, and field-level conflict resolution. Reads still
hit local SQLite (instant, offline); the sync engine reconciles in the
background. See [Offline-first sync](#offline-first-sync) below.

**Alternative to hand-rolled commands**: this template writes one Rust
command per operation (`list_items`/`get_item`/`save_item`) for compile-time
type safety end to end. If your app has many entities and you'd rather trade
that safety for less boilerplate,
[`tauri-plugin-sql`](https://github.com/tauri-apps/tauri-plugin-sql) (the
official community SQL plugin) lets the frontend execute SQL directly against
a managed connection without a dedicated command per query. Either is valid —
if you're already using `tauri-plugin-sql` elsewhere, there's no need to
migrate to match this template.

## Offline-first sync

The DemoCatalog pilot is wired end-to-end as an **offline-first, syncing** desktop
app (WC-desktop-sync). Everything is local-first — every read and write hits local
SQLite immediately, online or not — and a sync engine reconciles with the server.

**Backend URL.** Set `WHITY_BACKEND_URL` (default `http://localhost:8000`) to point
a build at dev / staging / a customer instance — the one knob, read in `config.rs`.

**Auth — cached device login (`src-tauri/src/auth/`).** The app enrolls once
(interactive login → `POST /api/v1/devices`) and stores the long-lived device
credential in the **OS keychain** (`keyring`: Windows Credential Manager / macOS
Keychain / Linux Secret Service). The short-lived access token stays in memory. On
start/reconnect it exchanges the credential for a fresh session
(`POST /api/v1/devices/token`).

**Offline lock (`auth/lock.rs`).** The server setting `auth.desktop_login_max_hours`
(per-tenant, default 72h) is echoed on every exchange. If more than that elapses
since the last successful online auth, the app **locks even offline** and requires
re-authenticating online — `auth_lock_state` reports it; the UI shows a
`LockedScreen`.

**Local store (`src-tauri/src/db/`).** Schema is versioned via `PRAGMA user_version`
(migrations v1→v5). Each row carries sync metadata (`client_uuid`, `server_id`,
`version`/`base_version`, `sync_state`, `dirty`, `deleted` tombstone). Mid-edit
**drafts** autosave to `item_drafts` (never synced).

**Sync engine (`src-tauri/src/sync/`).** `sync_now` runs a cycle:
- **push** dirty rows — create (idempotent on `client_uuid`), update (optimistic
  `baseVersion` → `409` on a stale edit), soft-delete;
- **pull** the server changes feed by an opaque cursor, applying non-conflicting
  changes and propagating tombstones;
- **conflicts** (a push 409, or a pull that finds the server ahead of a dirty row)
  are parked in `item_conflicts` as mine/theirs snapshots and surfaced via
  `list_conflicts`; the user resolves each field (mine/theirs/custom) in the shared
  `ConflictResolver`, and `resolve_conflict` rebases + re-queues the merge.

The server side lives in whity-core: the configurable TTL setting and the
DemoCatalog sync API (version, idempotent create, soft-delete, changes feed).

**Reusability.** The sync-metadata column set + the engine are the pattern to copy
for your own entities — DemoCatalog is the worked example, exactly like the printer
command is the worked native-crate example.

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

### Offline-sync stack — verified

- Rust unit tests cover the schema migrations (v1→v5), the offline-lock logic,
  the drafts + soft-delete repos, and the conflict-resolution repo.
- A live integration test drives the sync engine against a real backend end to
  end: device enroll → push → pull on a fresh client → concurrent-edit `409` →
  conflict parked → resolve → re-sync. The shared sync UI (`UnsyncedBanner` /
  `ConflictResolver` / `LockedScreen`) is jest-tested (incl. Arabic-bidi content).
- `npm run tauri build` on **Windows 11 (x86_64-pc-windows-msvc)** compiled the
  full stack — including the added `reqwest` (rustls) and `keyring` crates —
  clean, and produced both Windows installers: **MSI ~5.4 MB**, **NSIS setup
  ~4.0 MB** (release `.exe` ~13.6 MB — up from the printer-only ~10.5 MB baseline
  for the sync / HTTP / keychain crates).

### Known gaps — not yet verified

- **Windows.** Not yet built/run natively. Printing especially is the most
  OS-divergent capability here (Print Spooler vs. CUPS vs. Core Graphics) —
  don't assume the Linux verification above generalizes. Building natively
  requires the MSVC C++ build tools (`Microsoft.VisualStudio.2022.BuildTools`
  with the `Microsoft.VisualStudio.Workload.VCTools` component), which need
  an elevated (Administrator) install — that blocked an attempt to verify
  this from an unattended session. Whoever picks this up next: install Rust
  (`rustup`) + the VS Build Tools workload above, then
  `cd templates/tauri-desktop && npm install && npm run tauri build`, and
  specifically exercise the printer command against a real Windows printer
  (or "Microsoft Print to PDF").
- **macOS.** Deliberately deferred, not attempted at all yet — a distinct
  follow-up stage, not scheduled as part of this pass.
- **Linux keychain build dep.** The `keyring` Linux backend talks to the Secret
  Service over D-Bus, so a Linux build/run needs `libdbus-1-dev` (add it to the
  Tauri Linux system deps above). Windows/macOS use their native keystores with
  no extra dep.
- **Sync UI wiring + its interactive verification.** The sync *logic* is verified
  (unit + live integration against the server), but mounting the shared
  `UnsyncedBanner` / `ConflictResolver` / `LockedScreen` into the running window —
  and confirming the on-screen UX (enroll → offline-past-TTL lock → two-client
  conflict → resolve) — is a follow-up that needs the app running.
- **Deferred sync-engine enhancements.** Sync currently runs on an explicit
  `sync_now` (a frontend interval + online-listener drives it). A Rust background
  scheduler with connectivity detection, and generalizing the engine behind a
  `SyncableResource` trait for multi-entity reuse, are scoped follow-ups.
  (Per-row retry/backoff — a flaky/invalid push backs off and never aborts the
  cycle — is implemented.)
- **Packaging size / startup time.** Not measured on any platform — depends
  on the platform builds above existing first. Once a real `tauri build`
  output exists (Windows installer, macOS `.app`/`.dmg`, or the Linux
  AppImage/deb), report actual installer size and cold-start time here rather
  than leaving each downstream consumer to measure it independently.
