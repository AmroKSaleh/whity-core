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

## [0.1.0] - 2026-06-12

Initial tagged baseline.
