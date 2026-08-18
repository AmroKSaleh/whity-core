# Desktop plugin releases

How a "desktop plugin" — a source-obfuscated PHP package the Tauri desktop app
downloads and runs in its offline FrankenPHP host — is built and catalogued.

Related: [Offline PHP plugin host](../../templates/tauri-desktop/README.md#the-offline-php-plugin-host),
[Plugin Development](Plugin-Development.md), [Package Releases](Package-Releases.md).

---

## The two halves

The **serving** half already ships (see `Whity\Api\DesktopPluginsApiHandler`,
migrations `097`/`098`, and the routes in `public/index.php`):

- `GET /api/desktop-plugins` — the catalog, grouped by plugin, newest first.
- `GET /api/desktop-plugins/{name}/versions/{version}/download` — the raw zip.

Both are gated by the `desktop-plugins:read` permission over the standard RBAC
route pipeline, using the same device bearer token issued by
`POST /api/v1/devices/token`. The `desktop_plugin_releases` table is a **global**
catalog in v1 — every authenticated device on the instance sees every release;
per-tenant entitlement is a deferred follow-up.

This document covers the **producing** half — the pipeline that turns a plugin's
source into a catalogued release. Nothing here changes the desktop app.

## What the device requires (the package contract)

The Rust installer validates every download **before** touching disk; a package
that fails any of these is silently rejected:

1. **SHA-256** of the downloaded bytes matches the catalog row.
2. **Exactly one top-level directory**, whose name equals the plugin name
   (case-sensitive) and matches `^[A-Za-z0-9_-]+$`.
3. **Size guards** (mirrored from `Whity\Core\PluginInstaller`): ≤ 32 MiB total,
   ≤ 2000 entries, ≤ 16 MiB per uncompressed entry, ≤ 64 MiB uncompressed,
   compression ratio ≤ 200:1.
4. The extracted plugin is **valid, unmodified-behaviour PHP** implementing
   `Whity\Sdk\PluginInterface`, autoloaded by the device's per-directory PSR-4
   loader (top-level directory name → namespace root).

Because the device resolves classes **by file path** from the directory name,
class / namespace / method names and file locations are load-bearing and are
**never** altered by obfuscation.

## Obfuscation: source-level only

`Whity\Core\DesktopPlugins\PluginObfuscator` performs only transforms that are
provably behaviour-preserving on this loader:

- **Comment/docblock stripping** (always) — removes authored intent.
- **Local-variable renaming** (default) — only in "clean" scopes (no nested
  closures, no `compact`/`extract`/variable-variables/`global`); parameters and
  captured variables are never renamed. See `LocalVariableRenamer`.
- **String-literal encoding** (`--encode-strings`, off by default) — rewrites
  `'x'` to `\base64_decode('…')`, skipping constant-expression contexts.

The output is re-parsed before it is emitted; a transform that would produce
un-parseable PHP aborts the build (fail-closed). This is **not** a bytecode
encoder (ionCube/SourceGuardian) — that was ruled out because it would require
bundling a proprietary loader extension into every platform's FrankenPHP build.

## Cutting a release

```
php bin/desktop-plugin-release \
  --source templates/tauri-desktop/php-host/plugins/HelloWorld \
  --version 1.2.0
  # --name defaults to the source directory's basename
```

The pipeline (`Whity\Core\DesktopPlugins\DesktopPluginReleaseService`):

1. Obfuscates every `.php` file in the source.
2. Zips it under a single top-level directory named `{name}`.
3. Computes the SHA-256 and size **once, from the final zip on disk**.
4. Writes it to `{name}/{version}/package.zip` under the handler's storage
   directory (`storage/desktop-plugins`), re-verifying the checksum after the
   move so it can never drift from the served bytes.
5. Inserts the `desktop_plugin_releases` row.

`(plugin_name, version)` is unique and a release is **immutable** — re-releasing
a version is refused unless you pass `--force` (to re-cut a botched build).

Useful flags: `--storage <dir>`, `--encode-strings`, `--no-rename-locals`,
`--force`, `--no-db` (build + store the package and print the row it *would*
insert, without touching the database), `--db-dsn`/`--db-user`/`--db-password`
(target a specific database instead of the app's `DB_*` environment).

## Requirements

The pipeline uses `nikic/php-parser` (a dev dependency) and so runs in a dev/CI
environment where dev dependencies are installed — not from the production
runtime image, which is `--no-dev`. Releases are cut in CI/dev, not on the box
serving traffic.
