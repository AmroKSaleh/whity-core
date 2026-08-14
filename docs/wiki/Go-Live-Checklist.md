# Production Go-Live Checklist

A gate you run **before** exposing a Whity-Core deployment to real traffic, and again on every subsequent production release. Each item is pass/fail — do not go live with an unchecked **[BLOCKER]**. Items are grouped by phase; commands assume the container is `whity_frankenphp` and Postgres is reachable as the `postgres` service (adjust for your topology).

> Scope: the core platform runtime (FrankenPHP + PostgreSQL + Next.js web + cron). Downstream product plugins ship their own go-live steps on top of this.

Related: [Deployment Guide](DEPLOYMENT_GUIDE.md) · [Cron Operations](Cron-Operations.md) · [Core Update](Core-Update.md) · [Architecture](Architecture.md)

---

## 1. Secrets & configuration (fail-closed)

- [ ] **[BLOCKER] `JWT_SECRET` set to a real ≥32-char secret.** The runtime fast-fails on a missing/short secret (`JwtSecretGuard`). Never reuse the dev/CI value.
- [ ] **[BLOCKER] `ENCRYPTION_KEY` set to a real ≥32-char key** (encrypts TOTP secrets and other at-rest secrets). Losing/rotating it invalidates encrypted data — store it in your secret manager, not in the image.
- [ ] **[BLOCKER] Initial account passwords set explicitly** — `INITIAL_ADMIN_PASSWORD`, `INITIAL_USER_PASSWORD`, `INITIAL_SYSTEM_ADMIN_PASSWORD`, `INITIAL_SUPERUSER_PASSWORD`. If any is unset, `InitialPassword` generates a random one and prints it **once** to stdout/stderr — acceptable for recovery, but set them deliberately for a known bootstrap. Rotate immediately after first login. These apply at account **creation** only: setting one for an account that already exists is inert (the seeder says so and refuses to rewrite a live credential) — change an existing password through the admin UI.
- [ ] **[BLOCKER] `INITIAL_SYSTEM_ADMIN_EMAIL` set to a mailbox you control**, *before the first* `migrate run`. The default, `system@whity.local`, is unroutable: no password reset and no verification mail can ever reach it, and it names the vendor rather than your organisation. On an install that already exists, setting it and re-running `migrate run` (or `seed`) **moves** the existing account — same profile, same password, same tenant-0 admin membership. See [retiring the bootstrap account](#retiring-the-bootstrap-administrator) below.
- [ ] **[BLOCKER] `APP_ENV=production`.** Not `development`. In development the one-shot `db-init` auto-seeds demo accounts and the `Secure` cookie flag is dropped — both wrong for production. Outside development the seeder also refuses to create `admin@example.com` / `user@example.com` / `superuser@example.com` at all, so a production seed cannot materialise a demo credential by accident.
- [ ] **DB connection vars** correct and pointing at the production database: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`. The app reads from `$_ENV` (a `.env` file is loaded by the CLI bootstrap for `migrate`/`seed`).
- [ ] **Secrets are injected at runtime** (orchestrator secret store / env), **never baked into the image** or committed. Confirm `git grep` finds no real secret in the repo.
- [ ] **`TRUSTED_PROXY` / client-IP config** set so `X-Forwarded-For` is only trusted from your ingress — audit IPs and rate-limit keys must not be spoofable.

## 2. Database

- [ ] **[BLOCKER] Migrations applied and idempotent:** `php public/index.php migrate run` completes clean; running it a second time is a no-op. Verify on the real engine (Postgres), not SQLite.
- [ ] **Schema matches the release** — no pending/failed migration in `core_schema_migrations`.
- [ ] **Seed policy decided.** Production does **not** auto-seed (that's a development-only `db-init` behavior). Note that `migrate run` **alone** already creates the bootstrap administrator — `seed` is not required for a working install. If you run `seed` anyway it is idempotent and adds the default tenant and the notification-template baseline; the `*@example.com` demo accounts stay out unless you pass `--with-fixtures`, which production should not.
- [ ] **`shared_store` table present** — the rate-limiter (`DatabaseSharedStore`) INSERTs into it on every request; a missing table 500s the whole app. It's created by migration `032`; confirmed by a clean `migrate run`.
- [ ] **[BLOCKER] Automated, encrypted, scheduled backups armed** with a tested retention policy and a backup-success alert. **A rehearsed restore has been performed** (restore to a clean stack, migrate/verify) with known RPO/RTO. *(Backup/restore automation is tracked separately — do not go live without it for a sovereign deployment.)*
- [ ] **Connection pool tuning** reviewed for the worker count (`DB_MAX_LIFETIME` / liveness throttle) — see the performance/capacity guidance.

## 3. Security hardening

- [ ] **[BLOCKER] HTTPS/TLS terminated** in front of the app; HTTP redirects to HTTPS.
- [ ] **[BLOCKER] Session cookies are `Secure` + `HttpOnly` + `SameSite=Lax`.** `HttpOnly`/`SameSite` are always set; `Secure` is added automatically **unless `APP_ENV=development`** — so confirm `APP_ENV=production` **and** you're serving over HTTPS.
- [ ] **[BLOCKER] CORS allowlist locked to your real origin(s)** (`Cors.php`) — no wildcard, no dev origins. A non-allowlisted `Origin` must not be reflected, and credentials must only be allowed for allowlisted origins.
- [ ] **CSRF defense intact** — state-changing auth POSTs require `X-Requested-With: XMLHttpRequest`; non-browser clients use the token/bearer mode instead. Confirm the web app sends the header and the guard is wired.
- [ ] **[BLOCKER] Tenant isolation gate green** — `php scripts/ci-tenant-predicate-guard.php` passes (every query on a tenant-owned table carries a `tenant_id` predicate or a justified `@tenant-guard-ignore`). This is the #1 platform risk.
- [ ] **No internal error detail leaks to clients** — 4xx/5xx bodies are generic; exceptions/stack traces are logged server-side only.
- [ ] **System tenant (id 0) accounts** reviewed — the superuser/system-admin bootstrap credentials are rotated and access is restricted.
- [ ] **Bootstrap administrator retired** once a named human administrator exists — see below.
- [ ] **Dependency audits clean** — `composer audit` and `npm audit --audit-level=high` pass (gated in CI). No known-vulnerable dependency ships.

### Retiring the bootstrap administrator

The bootstrap account exists so that a fresh install has *something* to sign in with. Once a real, named administrator holds the tenant-0 admin role, the bootstrap account is a standing credential nobody owns. Retire it — do not delete it, because the audit trail references it.

**Deactivate it.** Set the profile's account status to `inactive`:

```http
PATCH /api/users/{profileId}
{ "accountStatus": "inactive" }
```

(`CorePermissions::USERS_WRITE`, same gate as every other field on that endpoint.) `profiles.status` is a **global** switch, not a per-tenant one: `AuthHandler` refuses the login of any profile whose status is `inactive` — with a generic `401 Invalid credentials`, deliberately indistinguishable from a wrong password so the account cannot be enumerated. Nothing else in the platform resolves the bootstrap account by address, so deactivating it removes the login and leaves history intact.

Order matters. Before you deactivate:

1. A second account **already holds an active tenant-0 admin membership** — deactivate the only tenant-0 administrator and nobody can administer the platform.
2. That account has a **verified, routable** primary email, so it can complete a password reset.

To undo, `PATCH` the same field back to `"active"`.

Verify afterwards that the bootstrap address can no longer authenticate:

```bash
curl -si https://<host>/api/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"<bootstrap address>","password":"<INITIAL_SYSTEM_ADMIN_PASSWORD>"}' | head -1
# HTTP/1.1 401 Unauthorized
```

## 4. Build & release

- [ ] **[BLOCKER] Release image built and smoke-tested** — the tagged GHCR image boots against a throwaway Postgres, runs migrate+seed, and serves `GET /api/health` = 200 before the GitHub Release is published (enforced by the `smoke` job in `release.yml`).
- [ ] **Worker mode enabled in the runtime** — `FRANKENPHP_CONFIG="worker /app/public/index.php <N>"`, `SERVER_NAME` set, `auto_https` configured for your TLS setup. (A plain `docker run` without `FRANKENPHP_CONFIG` will NOT run in worker mode — the project Caddyfile is only bind-mounted in dev compose.)
- [ ] **Generated artifacts committed and drift-free** — `public/openapi.json` matches `CoreApiSchemas` (`generate:openapi`) and `web/lib/api/schema.d.ts` matches it (`generate:api`). CI's drift gates confirm this.
- [ ] **Tag matches `CoreVersion::VERSION`** — enforced by `release.yml`'s verify job.
- [ ] **Worker recycling configured** — `MAX_REQUESTS` / memory limits set so persistent workers recycle before leaking (FrankenPHP worker-pool safety).

## 5. Observability & operations

- [ ] **`GET /api/health` returns 200 with `db_connected: true`** from behind the load balancer; the LB liveness/readiness probe targets it. `/api/health`, `/api/version`, `/api/openapi.json` are public and rate-limit-exempt.
- [ ] **Structured request logging on** — one start/end record per request with request-id, method, path, status, latency, and `tenant_id` context; `X-Request-Id` echoed. *(Tracked separately if not yet wired.)*
- [ ] **Error tracking wired** — uncaught exceptions reach an error tracker tagged with `request_id` / `tenant_id` / `release`. *(Tracked separately.)*
- [ ] **SLIs/SLOs defined + alerts routed** — availability, p95 latency, error rate, audit-write-failure rate; alert rules fire to a real notification channel. *(Tracked separately.)*
- [ ] **[BLOCKER] Cron/scheduler running** — the `cron` service (or your scheduler) executes `schedule:run` on its tick so recurring jobs (retention, scheduled tasks) actually run. See [Cron Operations](Cron-Operations.md). Confirm the container is up and ticking, not just present.
- [ ] **Audit trail verified** — a test action produces an audit row with the correct actor and `tenant_id`. See [Audit Trail](AUDIT_TRAIL.md).
- [ ] **Operator runbook available** — health-degradation triage, worker exhaustion/recycle, DB reconnect, key rotation, token-revocation incident response, log locations, escalation. *(Tracked separately.)*

## 6. Performance / capacity

- [ ] **Load thresholds met** — the concurrent multi-tenant load scenario (k6) hits the target p95 / sustained RPS with **zero cross-tenant leakage** and correct worker-recycle behavior under contention, against a realistically-sized dataset. *(Tracked separately.)*
- [ ] **Capacity plan reviewed** — worker count vs CPU, `MAX_REQUESTS`/memory tradeoffs, DB pool sizing, bcrypt cost vs login latency.

## 7. Go-live smoke (on the live stack, before opening traffic)

- [ ] Health endpoint 200 over HTTPS through the real ingress.
- [ ] Log in as the bootstrap admin over HTTPS; confirm `Secure`+`HttpOnly` cookies are set (or token-mode returns body tokens for non-browser clients).
- [ ] Perform one permissioned write and confirm it succeeds and is audit-logged; confirm a cross-tenant read is rejected.
- [ ] Rotate the initial bootstrap passwords now that access is confirmed.
- [ ] Confirm CORS: a request from a non-allowlisted origin is refused; the real frontend origin works.

## 8. Post-launch (first 24–48h)

- [ ] Watch error-tracker + SLO dashboards for the first traffic; confirm no auth/tenant-isolation anomalies.
- [ ] Confirm the first scheduled backup ran and is restorable.
- [ ] Confirm crons executed on schedule.
- [ ] Confirm worker memory is stable (no leak/creep) under real load.

---

### How to use this file
Copy the checklist into the release ticket for each go-live, fill in the `[ ]` boxes with evidence (command output, dashboard links, ticket refs), and require every `[BLOCKER]` checked before opening traffic. Items marked *(Tracked separately)* depend on ops-tier work that must land before a production launch — link the corresponding tasks/PRs as they complete.
