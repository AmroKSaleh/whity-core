# Changelog

All notable changes to Whity Core are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
uses tag-based releases (see the `v*` tags in the repository).

## [Unreleased]

### Added
- Plugin marketplace: `whity-plugin-store` (in the companion `whity-plugins` repository) — a token-gated catalog server other Whity Core deployments can browse and install from.
- `POST /api/plugins/install-from-store` — fetch a plugin package from a trusted, allowlisted store and install it through the same hardened pipeline as a manual upload (SSRF-guarded).
- Admin **Plugin Store** page (`/admin/plugins/store`) — browse, search, and install plugins from a trusted store, with a token-mint convenience action.
- `plugins.store_allowed_hosts` global setting — the operator allowlist gating which store hosts are trusted for install-from-store.

### Fixed
- `EnforceTenantIsolation` now exposes a narrow, anchored exemption for the plugin store's public read routes (catalog browse, registry index, token-gated download) without loosening any other route.
- Query-string parameters read via `parse_url($request->getPath(), PHP_URL_QUERY)` were silently empty at runtime (FrankenPHP strips the query from the request path) in `PersonsApiHandler` and the tenant-isolation query-based declared-target check; both now read `$_GET` as the runtime source.
- The Plugin Store admin page now surfaces the actual backend error message on a failed install or browse, instead of one generic message for every failure reason.

### Security
- **TOTP replay**: a captured valid 2FA code could authenticate a second, independent login within its validity window (`AuthHandler::handle2fa()` had no anti-replay state). Fixed with a per-profile anti-replay floor (`profiles.two_factor_last_used_step`, migration 080) atomically advanced on each accepted code, mirroring the existing backup-code single-use burn.
- **`ENCRYPTION_KEY` strength**: the documented ">= 32 chars outside development" convention (already enforced for `JWT_SECRET`/`RENDER_SHARED_SECRET`) was never actually checked for `ENCRYPTION_KEY` — only emptiness was rejected. New `EncryptionKeyGuard` closes the gap for both consumers (`TotpService::resolveEncryptionKey()`, `EncryptedSecretStore::fromEnv()`).
- Added `docs/wiki/Threat-Model.md` (STRIDE) and `docs/wiki/Pen-Test-Scoping-Brief.md` from an adversarial internal security audit; re-verified the tenant-isolation invariant across every table added since the prior 2026-07-20 investigation, with no drift found.

## [0.1.0] - 2026-06-12

Initial tagged baseline.
