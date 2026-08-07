# Pen-Test Scoping Brief

Scoping material for an **external, professional** penetration-testing engagement against Whity Core. This document is a briefing pack — an architecture summary, the trust boundaries an engagement should target, and a record of what an internal adversarial audit (WC-security-audit) already checked, found, and fixed, so a paid engagement spends its time on genuinely open questions rather than re-discovering closed ones.

**This document does not substitute for a professional penetration test.** It was produced by an internal, code-level (plus limited dynamic) review; it has not been reviewed by an independent third party, and several items below are explicitly flagged as needing exactly that.

See [Threat-Model](Threat-Model.md) for the full STRIDE analysis this brief summarizes, and [TENANT_ISOLATION](TENANT_ISOLATION.md) / [Architecture](Architecture.md) / [PERMISSION_SYSTEM](PERMISSION_SYSTEM.md) for the underlying architecture docs.

## Architecture summary

Whity Core is a PHP 8.4 / FrankenPHP (persistent-worker) platform behind a Next.js frontend, backed by one shared PostgreSQL database for all tenants (logical isolation via `tenant_id`, not per-tenant databases). Plugins are discovered and loaded in-process (reflection-based, no sandboxing). Two adjacent compute surfaces exist: `whity_render` (an internal-only Node/Puppeteer service for PDF rendering, reached over the compose network with a shared secret) and the plugin marketplace (`whity-plugins`, an external HTTPS service reached via an operator-configured host allowlist). Authentication is JWT-based (HS256, access + refresh + several narrower token types), with TOTP-based 2FA, backup codes, self-service password-reset, and an admin-approved 2FA-recovery flow. See [Architecture](Architecture.md) for the full request-lifecycle and plugin-loading detail.

## In scope for an external engagement

| Area | What to target | What this audit already checked (see notes) |
| --- | --- | --- |
| **Tenant isolation** | Attempt cross-tenant reads/writes across every listed table (`src/Core/Tenant/TenantOwnedTables.php`) via crafted ids, the `X-Tenant-Id` header, and the `tenant_id` query/path selectors; attempt to reach the two intentionally-global admin queues (password-reset, 2FA-recovery) with a request id belonging to a profile with no membership in the caller's tenant | Reviewed all ~35 tenant-owned tables + both global-with-JOIN-scoped-queue tables; re-ran the real-engine cross-tenant rejection suite; **also live-attacked** a throwaway instance (seeded a second tenant + a resource owned by it, then attempted to read it as tenant A's admin both plainly and via `X-Tenant-Id`) — 404 and 403 respectively, no leak — see [Threat-Model §1](Threat-Model.md#1-the-tenant-boundary-i--t--the-platforms-1-risk) |
| **Authz / IDOR** | Systematically fuzz every RBAC-gated `{id}` route (templates, blocks, users, roles, OUs, delegations, notifications, the approval queues) with a valid token for an *unrelated* account/tenant | Spot-checked ~10 handlers (code review) plus one live cross-tenant attempt (document templates, see above); consistent id+scope-bound-query pattern, 404-not-403 on out-of-scope ids; **not exhaustively fuzzed** — recommend automated fuzzing of the full route catalogue (`GET /api/openapi.json`) as a first engagement task |
| **JWT handling** | Attempt `alg: none`, algorithm confusion, signature stripping, expired/not-yet-valid claims, `jti`/`token_epoch` bypass, cross-token-type reuse (e.g. presenting a `refresh` token where `access` is expected) | Reviewed `JwtParser`/`TokenValidator`; algorithm is hard-pinned via the library's `Key` object. **Live-attacked**: forged `alg:none` (empty and junk signature) and `alg:HS256`-signed-with-a-wrong-32-char-secret tokens carrying an admin payload, sent as Bearer tokens against an admin-only route on a throwaway instance — all three came back 401, same as no token. Cross-token-type reuse and expired/nbf edge cases were reviewed at the code level only, not live-fuzzed — see [Threat-Model §3](Threat-Model.md#3-the-authsession-boundary) |
| **2FA / TOTP** | Replay a captured valid code; brute-force codes/backup codes; race concurrent requests with the same code | **Replay found and fixed** in this pass (migration 080 + `AuthHandler::consumeTotpStep()`) — reproduced and confirmed **live** (enrolled 2FA, logged in, submitted the same code twice to `/api/v1/login/2fa`: 200 then 200 before the fix, 200 then 401 after), not just in the test suite. Recommend the engagement additionally verify the fix under real concurrency/load (this pass validated correctness, not a concurrent race), and separately fuzz the login-throttle boundary conditions |
| **CSRF/CORS** | Attempt a cross-site form/XHR against every state-changing cookie-authenticated endpoint; attempt to widen the CORS allowlist via header injection or origin-reflection tricks | `CsrfGuard` + `Cors` reviewed and **live-tested** on a throwaway instance: a cookie-authenticated `POST /logout` without `X-Requested-With` → 403; an `OPTIONS` preflight from a non-allowlisted `Origin` → no `Access-Control-Allow-Origin` reflected. Recommend the engagement still probe the Next.js reverse-proxy layer (`web/app/api/[...path]/route.ts`) directly, which this pass reviewed only at the code level |
| **Plugin sandbox / marketplace** | Install (or get installed) a plugin whose handler calls `TenantContext::reset()`/`setTenantId()`/`setSystemMode()`, resolves `\Whity\app(Database::class)`, or makes an arbitrary outbound network call — confirm the blast radius is "full host compromise", then separately attempt to abuse the marketplace SSRF surface (DNS rebinding against the allowlisted-host guard, redirect chains, IPv6/decimal/octal IP-literal bypasses of `HttpFetcher`'s public-IP filter) | **Static/code-level analysis only in this pass** — the trust-boundary reality (no runtime sandbox) is documented with exact call-site evidence in [Threat-Model §4](Threat-Model.md#4-the-plugin-boundary-e---elevation-of-privilege-first), but no live malicious-plugin PoC was executed; **this is the single highest-value item for the external engagement** |
| **SSO/OIDC** | Attempt state/nonce reuse or omission, IdP discovery-document poisoning, JWKS `kid` confusion, mixing tenant-scoped and operator-global IdP configs | `OidcEngine`/`JwksProvider` reviewed at the code level only; no live IdP was exercised in this pass |
| **Secrets** | Attempt to obtain `JWT_SECRET`/`ENCRYPTION_KEY`/`RENDER_SHARED_SECRET`/DB credentials via any disclosure vector (error messages, logs, debug endpoints, timing); verify a deployment cannot boot with the shipped example secret values | `ENCRYPTION_KEY` length-enforcement gap found and fixed this pass — **live-confirmed**: a throwaway instance booted with `APP_ENV=production` + a short `ENCRYPTION_KEY` now fails to start at all (worker never reaches `frankenphp_handle_request()`), where before the fix it would have booted silently. Generic-error convention spot-checked (no `$e->getMessage()` reaches a client response in the sample reviewed) — recommend a full sweep of every handler's exception paths, which this pass sampled rather than exhaustively audited |
| **Input validation / injection** | SQLi, NoSQL-equivalent, header injection, path traversal (plugin zip extraction, branding asset upload), template/markdown injection in the document designer, XSS in stored notification/document content rendered client-side | Sampled ~10 handlers for parameterized queries and generic errors — clean; **not** exhaustively fuzzed; the document designer's rich-text/markdown rendering and the plugin zip-extraction path (`PluginInstaller`) are flagged as the highest-value injection-adjacent targets given they parse complex, potentially attacker-influenced structured input |
| **Rate limiting / DoS** | Verify the platform/IP/tenant/principal rate-limit tiers actually throttle under load; attempt to exhaust the render service (each render is a full headless-Chromium page load) | Reviewed configuration and defaults only; no load test performed |

## Out of scope / not applicable

- The `whity_render` service's internal HTTP call deliberately has no public-IP/SSRF guard — this is correct for an internal-only, operator-configured, same-compose-network target, not a gap. An engagement should confirm it is genuinely unreachable from outside the deployment network, not attempt to "fix" the missing guard.
- No physical/social-engineering testing is contemplated by this brief.
- No third-party dependency CVE scanning was performed as part of this pass (recommend `composer audit` / `npm audit` as a routine, separate check, distinct from this architectural review).

## Confirmed findings from the internal pass (already fixed — do not re-report as new)

| Finding | Fix | Verification |
| --- | --- | --- |
| TOTP codes replayable within their validity window (no anti-replay floor on `AuthHandler::handle2fa()`) | New `profiles.two_factor_last_used_step` column (migration 080) + atomic single-use-burn guard (`AuthHandler::consumeTotpStep()`), mirroring the existing backup-code burn pattern | `tests/Integration/TotpReplayRealEngineTest.php` — reproduced the bug pre-fix (same code, two logins, both 200), confirmed the fix (second attempt now 401; a genuinely later, unconsumed step still authenticates) |
| `ENCRYPTION_KEY`'s documented ≥32-char minimum was never enforced (only emptiness was checked) | New `EncryptionKeyGuard` (mirrors the pre-existing `JwtSecretGuard`), wired into both `TotpService::resolveEncryptionKey()` and `EncryptedSecretStore::fromEnv()` | Unit tests for the guard + both call sites; full backend test suite (3654+ tests) and PHPStan (0 new baseline entries beyond the expected count bump) still pass |

## Lower-confidence observations (flagged, not fixed — worth a professional second opinion)

- **Plugin runtime trust boundary.** Documented for the first time (was previously implicit/undocumented) rather than newly discovered as a "bug": a loaded plugin is full-trust PHP code with no sandbox. No code change was made — closing this properly is a sandboxing feature (separate process/runtime, capability-scoped DB access, egress allowlisting), not a bug fix, and is out of scope for this pass. Recommend the external engagement treat this as confirmed-by-design and focus on (a) a live PoC of the blast radius, and (b) whether `plugins:upload`/marketplace-admin permission grants are scoped as tightly as "equivalent to root" implies they should be.
- **Known example secrets are long enough to pass the strength guards.** `.env.example`'s placeholder `JWT_SECRET`/`ENCRYPTION_KEY` values happen to be ≥32 characters, so a deployment that copies them verbatim into production would pass every automated check while using a publicly-known secret. Not fixed in this pass (would need a deploy-time / CI check against the known literal values, a different kind of control than the length guard); flagged for follow-up.
- **DNS-rebind TOCTOU on `HttpFetcher`'s SSRF guard.** Already documented in the code as an accepted limitation (the guard resolves DNS once to check the IP range, the actual fetch resolves again) given the guarded URLs are operator-configured (SSO issuer, marketplace host), not raw user input. Worth an independent confirmation that the risk calculus still holds given the marketplace flow in particular involves a *slightly* less trusted actor (an operator picking a store host) than pure first-party configuration.
- **The dev-only `database.sqlite` file committed at the repo root.** Tracked in git since an early commit (`#74`), currently 0 bytes, not referenced anywhere in application code (grep-confirmed). Almost certainly vestigial scaffolding rather than a live artifact, and poses no disclosed-data risk today (it has only ever been empty across its history) — noted here only because a tracked, occasionally-writable-looking DB file is the kind of thing a pen-tester should independently confirm is inert before moving on.

## How to reproduce this audit's verification locally

```bash
# PHP tests (SQLite path) inside the project's dev image
docker run --rm -v <worktree>:/app -w //app whity-core:dev php vendor/bin/phpunit --no-coverage

# PHPStan
docker run --rm -v <worktree>:/app -w //app whity-core:dev php vendor/bin/phpstan analyse src tests plugins sdk --memory-limit=512M

# Tenant-isolation guards
docker run --rm -v <worktree>:/app -w //app whity-core:dev php scripts/ci-tenant-predicate-guard.php
docker run --rm -v <worktree>:/app -w //app whity-core:dev php scripts/ci-plugin-tenant-conformance.php

# Real-PostgreSQL Integration + Security suites (throwaway DB — never the shared
# staging containers): spin up a fresh postgres:15-alpine container, run
# `php public/index.php migrate run && php public/index.php seed` against it,
# then run PHPUnit with PHPUNIT_PG_DSN/PHPUNIT_PG_USER/PHPUNIT_PG_PASSWORD set
# and --testsuite Integration / --testsuite Security, exactly as
# .github/workflows/automated-tests.yml's `test-integration-pg` job does.
```

## Contact / disclosure

Vulnerabilities found during an engagement should be routed through the process in [`SECURITY.md`](../../SECURITY.md) (private email, 48-hour acknowledgment target, 7-day patch target), not filed as public issues.
