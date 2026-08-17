# Whity Tauri Desktop Template

A working Tauri v2 + React + TypeScript desktop app boilerplate wired to
Whity's shared UI/feature packages (`@amroksaleh/ui`, `@amroksaleh/features`,
`@amroksaleh/tokens`) — start here instead of `create-tauri-app`'s stock
scaffold when building a Whity-based desktop product.

**Scope: desktop only** (Windows / macOS / Linux). Tauri's mobile targets are
deliberately unused here and this template is not a mobile starting point —
**mobile is Flutter**, consuming the shared design tokens
(`flutter/whity_tokens`) and re-implementing the same adapter interfaces in
Dart. There is no React mobile client.

It demonstrates, end to end, the three things every downstream desktop app
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
3. **Running real whity plugins offline** (see [The offline PHP plugin
   host](#the-offline-php-plugin-host) below): a bundled FrankenPHP process
   runs **unmodified** whity plugin PHP code — the same plugin code the
   server runs — inside the desktop app itself, with a Rust-side bridge so
   plugin code can reach native hardware (printers today).

## Getting started

```bash
npm install
npm run tauri dev
```

This opens a desktop window with a sidebar (Home / Demo Catalog / Printer
demo / Plugins). The Demo Catalog list/create/edit flow persists to a
real SQLite file in your OS's per-app data directory (see `src-tauri/src/db.rs`)
— close the app and reopen it, your data is still there. The Plugins screen
reports on the offline FrankenPHP process described below — what's actually
loaded from the last automatic server sync, not a manual install control (see
[The offline PHP plugin host](#the-offline-php-plugin-host)).

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
it, and give each client its own implementation of that interface — the web
app in React against a server API, a Flutter mobile app in Dart against the
same backend.

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

**Backend URL.** `WHITY_BACKEND_URL` is the one knob for pointing a build at dev /
staging / a customer instance. It resolves in two layers: `build.rs` bakes a
compile-time default (build environment, else `.env` — copy `.env.example` —, else
the pinned `https://whity.jameedium.org`), and the same variable in the process
environment overrides it at runtime. The baked value is what matters for a shipped
installer: it is launched from a shortcut, with no shell to read env vars from. For
local work against the dev stack:
`$env:WHITY_BACKEND_URL="http://localhost:8000"; npm run tauri dev`.

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

## The offline PHP plugin host

Real product features in whity live as **plugins** — PHP classes under
`plugins/` implementing `Whity\Sdk\PluginInterface` — that normally only run
inside whity-core's own server. This template bundles a **FrankenPHP**
process (`templates/tauri-desktop/php-host/`) that runs real, **unmodified**
plugin PHP code fully offline, so a plugin written once for the server also
runs on desktop with zero changes — no server-side hand-porting required.

```
Rust (src-tauri/src/php_host/) ──spawns──▶ FrankenPHP ──serves──▶ php-host/
   │                                                                 │
   ├─ sidecar.rs      restart-on-crash supervisor, Windows Job Object│  discovers & loads
   ├─ native_bridge.rs  loopback HTTP server, secret-checked         │  plugins/*, runs their
   └─ proxy.rs        Rust → FrankenPHP HTTP client (php_request)    │  migrations, registers
                                                                     │  their routes/hooks
                              plugin code calls back into Rust ◀────┘  for native hardware
```

**`php-host/plugins/` ships empty.** Earlier revisions of this template
vendored four example plugins (`DemoCatalog`, `HelloWorld`, `UiKitShowcase`,
`PrintDemo`) here to prove the loader works for arbitrary plugin code before
real sync existed. They're gone now that plugin sync is real and mandatory
(see below) — a bundled example plugin would always load regardless of what
the connected tenant's actual catalog says, which is exactly the divergence
mandatory sync exists to prevent. `php-host/plugins/` remains the *bundled,
read-only* root (`PLUGINS_ROOT`) for a fork that genuinely wants an
always-on local plugin shipped in the installer; it's just empty by default.

**Real plugins arrive via mandatory server sync, not this bundled root.**
Every successful login reconciles this device's plugins to exactly match the
connected backend's catalog — installing what's missing, updating what's
stale, removing what's revoked — into a second, writable root
(`WHITY_DOWNLOADED_PLUGINS_ROOT`) that the Rust sidecar sets automatically.
There is no manual install control anywhere in the UI; see
`src-tauri/src/plugins/reconcile.rs` and `src-tauri/src/commands/post_login.rs`.
The `/plugins` screen only reports the outcome of the last sync and what's
currently loaded.

**The host is a real, generic loader, not a fixed allowlist**:

- **Discovery.** Both plugin roots are scanned at boot and any class
  implementing `PluginInterface` is picked up automatically — bundled first,
  then downloaded, so a downloaded plugin can never shadow a bundled one (set
  `WHITY_PLUGINS` to an explicit comma-separated FQCN list instead, if you
  want to pin exactly what ships). A plugin declaring an incompatible
  `PluginRequirementsInterface` constraint is quarantined (logged, skipped)
  rather than crashing the whole host — check `GET /__whity/plugins` for the
  loaded/quarantined list.
- **Hooks.** `getHooks()` subscriptions really fire, with the same
  priority-ordered dispatch and per-plugin error boundary as the real server
  (a generic exception is swallowed and logged; `HookVetoException` is the
  one sanctioned exception that propagates).
- **RBAC.** Every request is authorized against a real, single-device
  `PermissionResolver` — not an implicit super-user. The default device role
  (`admin`) is granted every permission any loaded plugin declares; set
  `WHITY_DEVICE_ROLE` to a narrower seeded role to deliberately test your
  plugin's 403 path offline.
- **SQLite dialect shim** (`SqliteCompatPdo`): plugin migrations are written
  for Postgres. The shim rewrites `SERIAL PRIMARY KEY` → `INTEGER PRIMARY KEY`
  and adds a `NOW()` UDF — deliberately narrow; a plugin using `JSONB`,
  `gen_random_uuid()`, or `RETURNING` in DDL needs a new rule added.

**Writing a plugin that works here**: if it already follows the SDK contract
(`whity/plugin-sdk`), it should just work. Prove it before you ship, without
needing a running FrankenPHP process at all, by extending
`Whity\Sdk\Testing\OfflinePluginHostConformanceTestCase` (see `sdk/README.md`)
in your plugin's own test suite — it catches exactly the class of bug this
template's own development surfaced (a migration using `SERIAL`, a route
requiring a permission the plugin never declared, a hook that throws) before
it ships, not after.

**`php-host/sdk/src` is a vendored copy of the repo's `sdk/src`, and must stay
byte-identical to it.** A device has no network and no Composer, so the contract
types a plugin `implements` have to already be on disk beside the host — hence
the copy. It is re-vendored by hand, which is exactly how it fell three SDK
releases behind in #849 while the reference plugin adopted two interfaces the
device had never heard of. `scripts/ci-vendored-sdk-parity.php` is now the gate:
it compares the two trees and boots every in-tree plugin against this one, and
it runs in `automated-tests.yml`, `release.yml`, and the desktop release
workflow. Change `sdk/src` and this copy in the SAME commit — the guard tells
you how if you forget.

**Setup**: `scripts/setup-php-runtime.ps1` (Windows — downloads the pinned,
checksum-verified FrankenPHP release) / `scripts/setup-php-runtime-linux.sh`
(Linux — compiles a curated static binary via Docker) fetch the FrankenPHP
runtime into `src-tauri/resources/frankenphp/` before `npm run tauri dev`/`build`.
Windows downloads a prebuilt binary; Linux compiles one with only the
extensions this app needs (`pdo_sqlite`, `sqlite3`, `mbstring`) — see the
scripts' own comments for the full per-platform story. macOS is not yet
spiked (no Mac available in this environment).

**Known gap — data doesn't yet round-trip.** The PHP host owns its own
SQLite file (`whity-offline.sqlite`), completely separate from the Rust sync
engine's `whity-desktop.sqlite` described above. A row created through a
plugin's offline routes today lives only in the PHP host's database — it does
not reach the real server, unlike the Rust-hand-ported DemoCatalog sync flow.
Reconciling the two is a real, harder, deliberately deferred follow-up (the
two schemas are structurally different: one is a client-side sync queue, the
other a server-schema clone).

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

- Rust unit tests cover the schema migrations (v1→v6), the offline-lock logic,
  the drafts + soft-delete repos, the conflict-resolution repo, the push
  retry/backoff, and the scheduler's connectivity classification + status reads.
- A live integration test drives the sync engine against a real backend end to
  end: device enroll → push → pull on a fresh client → concurrent-edit `409` →
  conflict parked → resolve → re-sync.
- Sync runs in a Rust BACKGROUND LOOP (`sync/scheduler.rs`) on its OWN WAL
  connection, so a cycle's network I/O never blocks the UI connection's reads. It
  reconciles at startup, on an interval (45 s; 15 s while offline), on a debounced
  local write, and on a manual `sync_now`; owns connectivity from the
  credential-exchange outcome; and emits `sync:status` events the UI subscribes to
  instead of polling. A successful background exchange also resets the offline-lock
  clock, so a reachable app auto-recovers from a TTL lock. Verified live on Windows
  against a local backend: creating an item pushed it to the server on its own —
  no manual sync — with the server row, the local `synced` state, the advanced
  pull cursor, and the refreshed offline-auth clock all confirmed.
- The shared sync UI (`UnsyncedBanner` / `ConflictResolver` / `LockedScreen`) is
  jest-tested (incl. Arabic-bidi content) **and mounted in the running app**
  (`sync-controller-tauri.ts` + `app-state-provider.tsx`). The on-screen flow was
  verified live on Windows: enroll → local-first save + auto-push → offline banner
  → reconnect drain → a real two-writer `409` through the field-level resolver →
  offline-TTL lock → credential re-login.
- `npm run tauri build` on **Windows 11 (x86_64-pc-windows-msvc)** compiled the
  full stack — including the added `reqwest` (rustls) and `keyring` crates —
  clean, and produced both Windows installers: **MSI ~5.4 MB**, **NSIS setup
  ~4.0 MB** (release `.exe` ~13.6 MB — up from the printer-only ~10.5 MB baseline
  for the sync / HTTP / keychain crates). The app also runs natively: the window
  launches, the first-run SQLite DB is created under
  `%APPDATA%\com.whity.tauri-desktop-template\`, and printing was exercised
  against the live Windows Print Spooler (a real job — 10 printers enumerated,
  default resolved, `print_text` returned `Ok`), not just compiled.

### Known gaps — not yet verified

- **macOS.** Deliberately deferred, not attempted at all yet — a distinct
  follow-up stage, not scheduled as part of this pass. (Windows and Linux are
  verified above; macOS is the one untried platform.)
- **Linux keychain build dep.** The `keyring` Linux backend talks to the Secret
  Service over D-Bus, so a Linux build/run needs `libdbus-1-dev` (add it to the
  Tauri Linux system deps above). Windows/macOS use their native keystores with
  no extra dep.
- **Generalizing the engine (`SyncableResource`).** The sync engine is currently
  DemoCatalog-specific; extracting a `SyncableResource` trait — so a new synced
  entity is just a new table with the standard sync columns + a trait impl — is a
  scoped follow-up. (The Rust background sync loop, connectivity detection, and
  per-row retry/backoff are implemented — see the sync stack above.)
- **Packaging size / startup on macOS + Linux.** Windows is measured above
  (installer sizes; ~0.5 s first-run / ~60 ms warm launch on Windows 11). The
  macOS `.app`/`.dmg` and Linux AppImage/deb sizes + cold-start remain
  unmeasured — they depend on those platform builds existing first; report the
  actual numbers here once they do, rather than leaving each downstream consumer
  to measure independently.
