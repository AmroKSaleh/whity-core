# Changelog

All notable changes to Whity Core are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
uses tag-based releases (see the `v*` tags in the repository).

## [Unreleased]

### Added
- Plugin SDK 1.16 — `Whity\Sdk\Rbac\PermissionResolver`: a read-only contract, registered in the service container by both the HTTP and CLI entry points, that lets a plugin ask the host for an authorization decision *inside* a handler instead of re-deriving it in hand-written SQL. It wraps the same delegation-aware `RoleChecker` the RBAC middleware enforces with, so the two can never give different answers to the same question. See [`docs/wiki/PERMISSION_SYSTEM.md`](docs/wiki/PERMISSION_SYSTEM.md).
- i18n admin management: `languages:manage` (create/update a language, system-tenant only) and `translations:manage` (create/update/delete a translation row) — `POST`/`PATCH /api/languages`, `GET`/`POST /api/translations`, `PATCH`/`DELETE /api/translations/{id}`. A translation row's tenant scope follows the System-Tenant Context convention: a regular tenant writes only its own override (a global/foreign row is 404), the system tenant writes only the system default (a per-tenant override is 422).
- Plugin marketplace: `whity-plugin-store` (in the companion `whity-plugins` repository) — a token-gated catalog server other Whity Core deployments can browse and install from.
- `POST /api/plugins/install-from-store` — fetch a plugin package from a trusted, allowlisted store and install it through the same hardened pipeline as a manual upload (SSRF-guarded).
- Admin **Plugin Store** page (`/admin/plugins/store`) — browse, search, and install plugins from a trusted store, with a token-mint convenience action.
- `plugins.store_allowed_hosts` global setting — the operator allowlist gating which store hosts are trusted for install-from-store.

### Fixed
- The CLI kernel enforced a **different authorization policy** from the HTTP API: `BaseCommand::setupKernel()` built its `RoleChecker` without a delegation resolver, so a permission held only through a live, non-revoked delegation opened a route over HTTP and was invisible over the CLI. It now mirrors `public/index.php` exactly (delegation-unaware *bounding* checker for the no-transitive-re-delegation invariant; delegation-aware checker for enforcement).
- `\Whity\app()` raised an `\ArgumentCountError` — an `\Error`, so uncatchable via the documented `catch (\Exception)` — when asked for an unregistered service whose constructor takes arguments, turning a plugin's guarded lookup into a 500. It now reflects first and throws a catchable `\RuntimeException` naming the unwired service; auto-instantiation stays limited to concrete, argument-free classes so the container can never improvise a security service.
- `EnforceTenantIsolation` now exposes a narrow, anchored exemption for the plugin store's public read routes (catalog browse, registry index, token-gated download) without loosening any other route.
- Query-string parameters read via `parse_url($request->getPath(), PHP_URL_QUERY)` were silently empty at runtime (FrankenPHP strips the query from the request path) in `PersonsApiHandler` and the tenant-isolation query-based declared-target check; both now read `$_GET` as the runtime source.
- The Plugin Store admin page now surfaces the actual backend error message on a failed install or browse, instead of one generic message for every failure reason.
- Accessibility: `Input` now associates its label via `htmlFor` and sets `aria-invalid`/`aria-describedby` for validation errors; `Table` headers carry a `scope` attribute. See [`docs/wiki/A11Y.md`](docs/wiki/A11Y.md).

### Security
- **TOTP replay**: a captured valid 2FA code could authenticate a second, independent login within its validity window (`AuthHandler::handle2fa()` had no anti-replay state). Fixed with a per-profile anti-replay floor (`profiles.two_factor_last_used_step`, migration 080) atomically advanced on each accepted code, mirroring the existing backup-code single-use burn.
- **Refresh token reuse**: a stolen refresh token could be replayed to mint new token pairs indefinitely. Each successful refresh now immediately revokes the token it consumed (added to `revoked_tokens`); any later reuse of that same token is detected, logged as a security incident (`auth.refresh_token_reuse_detected`), and rejected with 401.
- **`ENCRYPTION_KEY` strength**: the documented ">= 32 chars outside development" convention (already enforced for `JWT_SECRET`/`RENDER_SHARED_SECRET`) was never actually checked for `ENCRYPTION_KEY` — only emptiness was rejected. New `EncryptionKeyGuard` closes the gap for both consumers (`TotpService::resolveEncryptionKey()`, `EncryptedSecretStore::fromEnv()`).
- Added `docs/wiki/Threat-Model.md` (STRIDE) and `docs/wiki/Pen-Test-Scoping-Brief.md` from an adversarial internal security audit; re-verified the tenant-isolation invariant across every table added since the prior 2026-07-20 investigation, with no drift found.

## [0.1.0] - 2026-06-12

Initial tagged baseline.
