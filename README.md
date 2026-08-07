# Whity Core

**Open-source, white-labelable multi-tenant platform framework**

Whity Core is a foundation for building data-driven, multi-tenant applications. It pairs a [FrankenPHP](https://frankenphp.dev/) worker runtime with a logical multi-tenant data model, a hot-loadable plugin system, and a permission-mesh RBAC layer — plus a token-driven design system and a Next.js admin UI.

> **License:** AGPL-3.0 + Commons Clause — free for non-commercial use. See [License](#license).

---

## What it is

A single Whity Core deployment serves many tenants from **one shared PostgreSQL database with logical isolation**: every tenant-scoped row carries a `tenant_id`, and isolation is enforced at three layers — the `EnforceTenantIsolation` HTTP middleware (rejects cross-tenant requests before they reach a handler), explicit `tenant_id` predicates bound from `TenantContext` in every handler/repository query (proven per table by a real-engine cross-tenant rejection suite), and the `TenantContext` request lifecycle (resolved from the JWT, locked for the request, reset between requests on persistent workers). A reserved **system tenant** (`tenant_id = 0`) holds platform-level authority.

Domain logic ships as **plugins** dropped into `/plugins/` — discovered, loaded, and (de)registered on the next request with no restart, each wrapped in an error boundary and lifecycle state machine so a faulty plugin can't take down the host.

## Features

- **Logical multi-tenancy** — `tenant_id` isolation enforced at middleware, query-trait, and context layers; system tenant for platform operations.
- **Plugin hot-loading & lifecycle** — drop-in `/plugins/`, discovered via reflection, hot-reloaded on file change, per-plugin error isolation, and `discovered → loaded → active → failed → disabled` lifecycle with a runtime management API (`/api/plugins`).
- **RBAC permission mesh** — `resource:action` permission registry (core + plugin), role hierarchy with permission inheritance, **organizational-unit role inheritance** (roles flow down the OU parent chain), and route-level enforcement.
- **Authentication & 2FA** — JWT in httpOnly cookies, TOTP two-factor with encrypted secrets and recovery codes.
- **Resilient data layer** — worker-scoped PostgreSQL connection manager with health-check, transparent reconnect, and bounded connection lifetime.
- **Operational safety** — graceful worker recycling on a configurable memory ceiling, `/api/health` endpoint reporting worker/memory/DB status (200 healthy, 503 degraded).
- **Design system** — OKLCH design tokens (light + dark, white-label-overridable per tenant) generated from a single source to CSS, JSON, and Dart; shadcn/Radix component library on Tailwind v4.
- **OpenAPI** — schema generated from the routing layer for client/type generation.
- **Tested** — 3695+ PHPUnit tests (with real-engine SQLite coverage for data-layer logic), PHPStan, and 162 Playwright E2E tests.

## Architecture

```
            Web / Mobile / Desktop clients
                       │  HTTP/REST (JSON, httpOnly-cookie JWT)
                       ▼
        FrankenPHP worker pool  (public/index.php, :8000)
          │  HttpKernel pipeline
          ├─ EnforceTenantIsolation   (TenantContext::resolve from JWT)
          ├─ RbacMiddleware           (route role/permission enforcement)
          ├─ Router                   (core + plugin routes)
          └─ PluginLoader             (reflection discovery + hot-reload + lifecycle)
                       │
                       ▼
        PostgreSQL 15  — single shared DB, logical tenant_id isolation
          (worker-scoped connection pool · explicit tenant_id query predicates)
```

See **[docs/wiki/Architecture.md](docs/wiki/Architecture.md)** for the full request lifecycle, ER diagram, and deployment topology.

## Technology stack

| Layer | Technology |
|-------|-----------|
| Backend runtime | PHP 8.4, FrankenPHP persistent workers |
| Database | PostgreSQL 15 |
| Web client | Next.js 16 (App Router), React 19, TypeScript, Tailwind CSS v4, shadcn/Radix UI |
| Design tokens | OKLCH → CSS / JSON / Dart (generated) |
| 2FA | `spomky-labs/otphp` (TOTP) |
| Mobile / Desktop | Flutter (Dart) — design tokens only (`flutter/whity_tokens`) · Tauri (Rust) — reference desktop template with offline-first sync, delivered (`templates/tauri-desktop`) |

## Quick start

Requires Docker (Desktop or Engine) and Node.js for the web UI. **No local PHP is needed** — the backend, tests, and CLI all run inside the `php:8.4`/FrankenPHP containers.

```bash
git clone https://github.com/AmroKSaleh/whity-core.git
cd whity-core

# Bring up FrankenPHP + PostgreSQL, then migrate + seed
make setup                # docker compose up -d + scripts/init-db.sh

# (or manually)
docker compose up -d
docker exec whity_frankenphp php public/index.php migrate run
docker exec whity_frankenphp php public/index.php seed

# Frontend (Next.js admin UI) on :3000
cd web && npm install && npm run dev
```

- Backend API: <http://localhost:8000> · Health: <http://localhost:8000/api/health>
- Web UI: <http://localhost:3000>
- Seeded accounts (dev): `admin@example.com` / `admin123`, `user@example.com` / `user123`
  (passwords are env-configurable; absent an env value a random one is generated and printed once — see Configuration).

## Configuration

Environment variables (defaults shown are for development; **production fails fast** on a missing/weak `JWT_SECRET` or `ENCRYPTION_KEY`):

| Variable | Purpose | Default (dev) |
|----------|---------|---------------|
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` | PostgreSQL connection | `postgres` / `5432` / `whity_core` / `whity` / `whity_dev` |
| `JWT_SECRET` | JWT signing secret (≥ 32 chars required outside development) | dev fallback |
| `ENCRYPTION_KEY` | AES key for stored TOTP 2FA secrets (required outside development) | dev fallback |
| `CORS_ALLOWED_ORIGINS` | Comma-separated allowlist (credentials reflected only on match) | `http://localhost:3000` |
| `APP_ENV` | `development` relaxes the prod guards above | `development` |
| `FRANKENPHP_WORKERS` / `MAX_REQUESTS` | Worker pool size / requests before recycle | `8` / `500` |
| `WORKER_MEMORY_LIMIT_MB` | Memory ceiling that triggers graceful worker recycling | `128` |
| `DB_CONNECT_TIMEOUT` / `DB_MAX_LIFETIME` / `DB_PING_INTERVAL` | Connection-pool tuning (seconds) | `5` / `1800` / `5` |
| `INITIAL_ADMIN_PASSWORD` / `INITIAL_USER_PASSWORD` / `INITIAL_SYSTEM_ADMIN_PASSWORD` | Seed passwords (random if unset) | — |
| `INITIAL_SUPERUSER_PASSWORD` | Seeds the system-tenant (id 0) superuser `superuser@example.com`, which can manage global base roles and every tenant (random if unset) | — |

See [.env.example](.env.example).

## CLI

Run inside the FrankenPHP container (`docker exec whity_frankenphp php …`):

```bash
php public/index.php migrate run        # run pending migrations
php public/index.php migrate rollback   # roll back the last migration
php public/index.php seed               # seed default tenant/roles/users
php public/index.php generate:openapi   # regenerate public/openapi.json

php bin/whity-cli migrate|plugin|tenant # status/manage migrations, plugins, tenants
```

## Project structure

```
whity-core/
├── public/index.php          Entry point + FrankenPHP worker loop + route wiring
├── src/
│   ├── Core/                 PluginLoader, lifecycle, Router, Hooks, RBAC registry, TenantContext
│   ├── Auth/                 JWT, RoleChecker, TOTP/2FA, cookies
│   ├── Http/                 HttpKernel, RbacMiddleware, EnforceTenantIsolation
│   ├── Api/                  REST handlers (users, roles, permissions, tenants, OUs, plugins, health, 2FA)
│   ├── Database/             Connection manager, Seeder
│   ├── Cli/ · Console/       CLI + OpenAPI generator
│   └── design/tokens/        base.json → generated CSS/JSON/Dart
├── plugins/                  Hot-loadable plugins (+ HelloWorld example)
├── database/migrations/      Schema migrations
├── tests/                    PHPUnit (Unit, Integration, Security, real-engine)
├── web/                      Next.js admin UI (+ Playwright E2E in web/e2e/)
└── docs/wiki/                Architecture, RBAC, tenancy, plugins, design system
```

## Testing

The PHP toolchain lives only in Docker (there is no `php` on a typical Windows
dev box), so `scripts/ci-local.sh` runs the GitHub-Actions PHP jobs locally,
step for step, in a Linux container that carries the same PHP 8.4 + extension
set + `pcov` the workflow uses.

```bash
scripts/ci-local.sh phpstan        # just PHPStan (~2 min warm) — the usual culprit
scripts/ci-local.sh unit           # the "Unit, static analysis & plugin smoke (SQLite)" job:
                                   #   phpunit+coverage, coverage floor, OpenAPI drift,
                                   #   phpstan, plugin smoke, tenant guards
scripts/ci-local.sh pg             # the "Migrations + Integration + Security on real
                                   #   PostgreSQL" job, against a throwaway Postgres
scripts/ci-local.sh all --clean    # both, against a pristine export of HEAD

# Web (runs natively, no container needed)
cd web && npx tsc --noEmit && npx jest
cd web && npm run test:e2e         # E2E, against a running stack
```

Pass `--clean` before pushing: it runs against a `git archive` of HEAD, so
untracked-but-gitignored files (e.g. a local `plugins/PluginStore/`) cannot
add errors CI will never see. `--fresh` rebuilds the image and drops the
cached `vendor/` + PHPStan volumes.

`scripts/ci-local.sh` mirrors `.github/workflows/automated-tests.yml` — when
that workflow changes, update the script in the same PR. A local runner that
has drifted from CI is worse than none, because it reports confident false
greens.

CI runs these same jobs plus Playwright E2E on every PR.

## Documentation

- [Architecture](docs/wiki/Architecture.md) — runtime, request lifecycle, ER diagram, deployment
- [Permission System](docs/wiki/PERMISSION_SYSTEM.md) · [Tenant Isolation](docs/wiki/TENANT_ISOLATION.md) · [Hook System](docs/wiki/HOOK_SYSTEM.md)
- [Plugin Development](docs/wiki/Plugin-Development.md) — build a plugin (with the `HelloWorld` example)
- [Design System](docs/wiki/Design-System-Overview.md) · [Component Library](docs/wiki/Component-Library.md) · [UI Patterns](docs/wiki/UI-Patterns.md)
- [Installation](docs/wiki/Installation.md) · [CLI Reference](docs/wiki/CLI_REFERENCE.md) · [Deployment Guide](docs/wiki/DEPLOYMENT_GUIDE.md) · [Go-Live Checklist](docs/wiki/Go-Live-Checklist.md) — the project's own pass/fail gate for what's still outstanding before a production launch
- [CONTRIBUTING.md](CONTRIBUTING.md) · [SECURITY.md](SECURITY.md)

## Roadmap

Core platform — **delivered**: FrankenPHP runtime, plugin hot-loading + lifecycle, RBAC (registry, hierarchy, OU inheritance), multi-tenant isolation, feature flagging system, 2FA, design system, developer docs, an E2E test suite, the organizational-unit hierarchy visualizer (tree + graph views in the OU Management Hub), self-service user profile management, and the family-relations module (Persons/Relations graph — [ADR 0002](docs/adr/0002-family-relations-management.md)).

Under consideration: a maintained first-party mobile client — Flutter currently ships only the design-tokens package (`flutter/whity_tokens`) for downstream apps to consume, not a Whity-authored mobile UI. Desktop is no longer planned as Electron: `templates/tauri-desktop` already delivers a working Tauri (Rust) reference app with offline-first sync. See [open issues](https://github.com/AmroKSaleh/whity-core/issues).

Embedding an n8n workflow engine was **deferred, not adopted** — [ADR 0008](docs/adr/0008-defer-n8n-external-automation-surface.md) reframes automation around MCP (any orchestrator that speaks MCP, including n8n, can already call every permission-gated API route).

## License

**AGPL-3.0 + [Commons Clause](LICENSE)** — free for non-profit, internal, educational, research, and open-source use. Commercial use (SaaS, paid hosting, reselling, white-labeling for profit) is restricted.

For commercial licensing: **amroksaleh@gmail.com**

## Getting help

- 🐛 [Issues](https://github.com/AmroKSaleh/whity-core/issues) · 💬 [Discussions](https://github.com/AmroKSaleh/whity-core/discussions) · 🔒 [SECURITY.md](SECURITY.md)
