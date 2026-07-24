You are working in AmroKSaleh/Elmak-Desktop (a Tauri v2 + React + Vite desktop
app, currently the stock create-tauri-app scaffold — tailwindcss v4 already
added as a dev dependency, but not yet wired to any shared Whity UI package).

whity-core (a sibling private repo, AmroKSaleh/whity-core) just shipped a new
boilerplate at `templates/tauri-desktop/` that this repo should either adopt
directly or be diffed against. It demonstrates:

1. **@amroksaleh/ui + @amroksaleh/features wired into a real Tauri app** —
   `AppSidebar`/`PageHeader`/`PageShell` (presentational, zero framework
   opinion) plus a nav contract (`@amroksaleh/features/nav`:
   `NavConfig`/`resolveNavGroups`/`NavLinkAdapter`) that takes a
   client-supplied nav config + an injectable link component (no
   `next/navigation` anywhere).
2. **A data-source-agnostic feature pattern**: `DemoCatalogList`/
   `DemoCatalogDetail` (`@amroksaleh/features/demo-catalog`) never fetch
   directly — they take an injected `DemoCatalogAdapter` (`list`/`get`/
   `save`). web/ implements it against a server API; the new template
   implements the SAME interface against real local SQLite via three Tauri
   commands (`src-tauri/src/commands/items.rs`, `rusqlite` with the
   `bundled` feature — no system sqlite dependency). Read
   `templates/tauri-desktop/README.md`'s "adapter pattern" section for the
   full write-up.
3. **A worked example of adding native capabilities via Rust crates**: a
   printer command (`src-tauri/src/commands/printer.rs`, built on the
   `printers` crate) that prints to the OS default printer — a real,
   compiling example (verified via `cargo check`/`cargo build` in a
   `rust:1-bookworm` container with the Tauri v2 Linux deps installed), not
   just a stub. The README documents the 4-step recipe for adding your own
   (add crate → `#[tauri::command]` fn → register in
   `generate_handler![...]` → call via `invoke()` from the frontend).

**What we want from you:**

- Read `templates/tauri-desktop/` (Cargo.toml, `src-tauri/src/`, `src/`, and
  the README) in whity-core and compare it against this repo's current
  scaffold and whatever Elmak-Desktop-specific work already exists here.
- Tell us: does this pattern fit what Elmak-Desktop needs, or did you already
  solve any of this differently (nav, adapter wiring, local storage, native
  integrations) in ways the shared template should learn from instead? We'd
  rather converge on whichever approach is actually better, not force this
  one through by default.
- If it fits: propose (don't just silently execute) how Elmak-Desktop would
  adopt it — wiring `@amroksaleh/ui`/`@amroksaleh/features`/
  `@amroksaleh/tokens` in (published npm versions via GitHub Packages, not
  the template's in-monorepo `file:` links — see the README's "Monorepo
  note"), and where Elmak's own domain features (not exams/roster/etc. —
  confirm the actual real feature set with whoever owns this repo) would
  plug into the same adapter pattern.
- Flag anything in the whity-core template that looks wrong, incomplete, or
  desktop-hostile (packaging size, startup time, platform quirks on
  Windows/macOS specifically — the verification build only ran on Linux) so
  it can be fixed at the source rather than re-discovered per-product.

Don't assume you have write access to whity-core — treat it as a reference
to read, not modify, unless explicitly asked to contribute back to it.
