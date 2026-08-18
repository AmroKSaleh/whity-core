# Desktop app self-update

How the Tauri desktop app (`templates/tauri-desktop`) checks for and applies
an update to ITSELF — the installed binary — separately from how it syncs
plugins. Related: [Desktop plugin releases](Desktop-Plugin-Releases.md),
[Core update check](Core-Update.md), [Offline PHP plugin host](../../templates/tauri-desktop/README.md#the-offline-php-plugin-host).

---

## The two halves

**Serving** (already shipped): `Whity\Api\DesktopAppUpdateApiHandler`,
migrations `099`/`100`, and the route in `public/index.php`:

- `GET /api/desktop-app-updates/latest?target=...&current_version=...` — the
  server decides whether a newer release exists (Tauri's "dynamic update
  check" protocol), returning `204 No Content` when the caller is
  current/ahead/unrecognised, or the exact
  `{version, notes, pub_date, url, signature}` shape `tauri-plugin-updater`
  expects when a newer release is available.

Gated by `desktop-app-updates:read` over the standard RBAC route pipeline,
using the same device bearer token issued by `POST /api/v1/devices/token` —
no new auth mechanism. `desktop_app_releases` is a **global** catalog in v1
(no tenant scoping), same posture as `desktop_plugin_releases`.

This document covers the **producing** half — how a release actually gets
built, signed, and registered. Nothing here changes the desktop app's own
code (see `src-tauri/src/self_update.rs` for that side).

## Why this checks BEFORE plugin sync

A desktop plugin package assumes a compatible app runtime. On every
successful login, the app checks for a self-update first; if one is found and
applied, the process relaunches on the new binary and plugin sync happens
naturally on that binary's own next login — the same login/enrollment hook
that would have run plugin sync never gets there in the outdated process,
because the process has already torn itself down to relaunch. See
`src-tauri/src/commands/post_login.rs`.

## Signing

Updates are verified via [minisign](https://jedisct1.github.io/minisign/),
the scheme `tauri-plugin-updater` uses. **The signing keypair must be
generated and custodied by a human — this cannot be scripted or committed:**

```
npx tauri signer generate
```

- The **private** key (+ passphrase, if set) becomes the
  `TAURI_SIGNING_PRIVATE_KEY` / `TAURI_SIGNING_PRIVATE_KEY_PASSWORD` GitHub
  Actions secrets — write-only once set, so also store it in a real secrets
  vault. Losing it means no future release can ever be accepted as an update
  by any copy already signed under it.
- The **public** key goes into `templates/tauri-desktop/src-tauri/tauri.conf.json`'s
  `plugins.updater.pubkey` — a build-time trust anchor, correctly static.

## Cutting a release

CI (`.github/workflows/tauri-desktop-release.yml`, triggered by pushing a
`tauri-desktop-v*` tag) builds, signs, and publishes the installer + its
`.sig` to a GitHub Release for that tag — **Windows only for now**, the only
platform this template's README documents as run-verified.

Registering the resulting release against a specific deployment's database is
that deployment's own step (CI cannot reach into a customer's database):

```
php bin/desktop-app-release \
  --version=0.2.0 --target=windows-x86_64 \
  --url=https://github.com/OWNER/REPO/releases/download/tauri-desktop-v0.2.0/whity-desktop_0.2.0_x64.msi \
  --signature-file=./whity-desktop_0.2.0_x64.msi.sig
```

`(version, target)` is unique; re-running for the same pair without `--force`
refuses rather than silently overwriting a live release.
