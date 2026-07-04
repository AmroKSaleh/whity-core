# Contributing to Whity Core

Thank you for contributing! This guide covers everything you need to set up a
development environment, follow the project's conventions, and get a change
merged. Read it in full before your first contribution.

For the high-level design, see [README.md](README.md) and the
[Architecture wiki](docs/wiki/Architecture.md). For plugin authoring
specifically, see [Plugin Development](docs/wiki/Plugin-Development.md). For how
work is planned and tracked — the Instruction Set and the Tasker flow (`WC-XX`
numbering, task lifecycle, parallel execution) — see
[Development Workflow](docs/wiki/Development-Workflow.md).

## Table of Contents

- [License](#license)
- [Development Environment Setup](#development-environment-setup)
- [Git Workflow](#git-workflow)
- [Code Standards](#code-standards)
- [Testing Requirements](#testing-requirements)
- [Pull Request Process](#pull-request-process)
- [Architecture Decision Records (ADRs)](#architecture-decision-records-adrs)
- [Architecture Principles (Quick Reference)](#architecture-principles-quick-reference)
- [Further Reading](#further-reading)

## License

By contributing, you agree your code is licensed under **AGPL v3.0 + Commons
Clause** (see [LICENSE](LICENSE)). Security issues must **not** be filed as
public issues — follow [SECURITY.md](SECURITY.md) instead.

## Development Environment Setup

Whity Core runs on **Docker** (FrankenPHP + PostgreSQL). There is **no assumed
native PHP install** — all backend commands run inside the `whity_frankenphp`
container, and CI installs PHP 8.4 only to run the test suite. You do not need
PHP on your host machine to develop the backend.

### Prerequisites

- Docker + Docker Compose
- Node.js 22 and npm (CI uses Node 22 — for the `web/` Next.js frontend)
- `make` (optional but convenient — see the [Makefile](Makefile))

### 1. Configure environment variables

Copy the example env file and adjust as needed:

```bash
cp .env.example .env
```

The variables consumed by [`docker-compose.yml`](docker-compose.yml) and
[`bin/whity-cli`](bin/whity-cli) are:

| Variable           | Purpose                                                              | Dev default                            |
| ------------------ | -------------------------------------------------------------------- | -------------------------------------- |
| `APP_ENV`          | Application environment (`development`, `production`, ...).          | `development`                          |
| `DB_USER`          | PostgreSQL user.                                                     | `whity`                                |
| `DB_PASSWORD`      | PostgreSQL password.                                                 | `whity_dev`                            |
| `DB_NAME`          | Database name.                                                       | `whity_core`                           |
| `DB_HOST`          | DB host (`postgres` inside Docker; `localhost` from the host).       | `postgres`                             |
| `DB_PORT`          | PostgreSQL port.                                                     | `5432`                                 |
| `JWT_SECRET`       | Secret used to sign/verify JWTs. **Required outside development.**   | `dev_secret_key_change_in_production`  |
| `ENCRYPTION_KEY`   | AES-256-CBC key for stored TOTP 2FA secrets. **Must be identical** across setup/confirm/login. **Required outside development.** | `dev_encryption_key_change_in_production` |
| `CORS_ALLOWED_ORIGINS` | Comma-separated browser-origin allowlist. The request `Origin` is reflected (with credentials) only on an exact match — never `*`. | `http://localhost:3000` |
| `WORKER_MEMORY_LIMIT_MB` | Per-worker memory ceiling; crossing ~90% triggers graceful worker recycling. | `128` |
| `INITIAL_ADMIN_PASSWORD` / `INITIAL_USER_PASSWORD` / `INITIAL_SYSTEM_ADMIN_PASSWORD` | Seed/system-admin passwords. If unset, a random password is generated and printed **once** to the log — there is no hardcoded fallback. | _(random)_ |
| `INITIAL_SUPERUSER_PASSWORD` | Seeds the system-tenant (id 0) superuser `superuser@example.com` — a system-tenant admin that can manage global base roles and every tenant. Same random-if-unset / no-hardcoded-fallback behaviour. | _(random)_ |

Outside `APP_ENV=development`, the app **fails fast** if `JWT_SECRET` or
`ENCRYPTION_KEY` is unset/empty, or if `JWT_SECRET` is shorter than 32
characters. Never commit real secrets — `.env` is git-ignored.

Connection-pooling knobs for the persistent FrankenPHP workers
(`DB_CONNECT_TIMEOUT`, `DB_MAX_LIFETIME`, `DB_PING_INTERVAL`) and worker tuning
(`FRANKENPHP_WORKERS`, `FRANKENPHP_TIMEOUT`, `MAX_REQUESTS`) are documented
inline in `docker-compose.yml`; the defaults are fine for local development.

### 2. Start the stack

Using `make` (preferred):

```bash
make backend   # docker-compose up (FrankenPHP on :8000 + PostgreSQL on :5432)
make setup     # docker-compose up -d, then initialize the DB (scripts/init-db.sh)
make dev       # start backend AND the Next.js frontend together (dev-server.sh)
make help      # list all targets
```

Or directly:

```bash
docker-compose up        # foreground
docker-compose up -d     # detached
```

The backend is served at <http://localhost:8000> and exposes a health check at
`http://localhost:8000/api/health`.

### 3. Initialize the database (migrations + seed)

`make setup` and `make db-init` run [`scripts/init-db.sh`](scripts/init-db.sh),
which creates the database, runs migrations, and seeds default data inside the
running container. The underlying commands are:

```bash
# Run pending migrations (inside the FrankenPHP container)
docker exec whity_frankenphp php bin/whity-cli migrate run

# Seed default data
docker exec whity_frankenphp php public/index.php seed
```

> `php bin/whity-cli migrate run` and `php public/index.php migrate run` are
> equivalent entry points (both dispatch to `Whity\Cli\CliRunner`). See the
> [CLI Reference](docs/wiki/CLI_REFERENCE.md) for `migrate status`,
> `migrate rollback`, and the `plugin` / `tenant` commands.

Core migrations are tracked in the `core_schema_migrations` table; plugin
migrations must use the standard `schema_migrations` table (see
[Architecture Principles](#architecture-principles-quick-reference)).

### 4. Frontend (Next.js) setup

The web client lives in [`web/`](web). It is **Next.js 16 + React 19** — newer
than most training data — so always read the relevant guide in
`web/node_modules/next/dist/docs/` before writing frontend code, as noted in
[`web/AGENTS.md`](web/AGENTS.md).

The JS side is an **npm workspace** (`web` + the shared UI library
`packages/ui`, published as `@amroksaleh/ui`). There is a **single lockfile at
the repo root** — always `npm ci` at the **root**, never inside `web/`. A
`web/`-level install leaves `web/node_modules/next` absent and Turbopack fails
with `couldn't find next/package.json`. If you have a stale `web/node_modules`
from before the workspace migration, delete it and reinstall at the root.

```bash
npm ci                    # clean install of ALL workspaces, from the repo root
npm run dev -w web        # dev server on http://localhost:3000
# Component galleries (Storybook), decoupled from the app:
npm run storybook -w @amroksaleh/ui   # UI primitives  → :6006
npm run storybook -w web              # app components → :7007
```

The frontend proxies its relative `/api/*` calls to the backend at
`http://localhost:8000` via the catch-all route handler
(`web/app/api/[...path]/route.ts`), so the backend stack must be running for the
UI to work. See [`web/README.md`](web/README.md) for seeded test accounts and
known-bug notes.

## Git Workflow

### Branch naming

Branches follow `type/WC-XX-short-description`, where `WC-XX` is the tracking
issue/task ID:

```
feature/WC-7-memory-management
docs/WC-31-contributing-guide
fix/WC-42-tenant-leak
```

Common `type` prefixes: `feature`, `fix`, `docs`, `refactor`, `test`, `chore`.
Branch off the latest `origin/main`.

### Commit messages

Use the format **`WC-XX: verb + what changed`** — imperative mood, present
tense, scoped to the task:

```
WC-31: expand CONTRIBUTING.md into a full contributor guide
WC-7: implement reflection-based class loader with interface validation
WC-6: fix PHPStan static analysis errors and class loading bug
```

**Hard project rule:** **never** add `Co-authored-by` trailers or any AI / tool
attribution (e.g. "Generated with ...") to commits or PR descriptions. Commits
are authored solely by the human contributor.

### Keeping your branch current

Keep your branch up to date with `main` before opening or updating a PR. Prefer
rebasing onto `origin/main` so history stays linear:

```bash
git fetch origin
git rebase origin/main
```

If a long-lived branch is hard to rebase, a merge from `main` is acceptable, but
do not merge `main` into the branch repeatedly to avoid resolving conflicts.
Never force-push to `main`; force-push your own feature branch only after a
rebase (`git push --force-with-lease`).

## Code Standards

### PHP (backend)

- **`declare(strict_types=1);`** at the top of every PHP file.
- **PSR-12** coding style.
- **PHPStan at level 8** must pass over the full CI scope —
  `vendor/bin/phpstan analyse src tests plugins sdk` (config in `phpstan.neon`).
  Fix the root cause — do not suppress errors with broad ignores.
- **PHPDoc on public APIs**, especially to document array shapes, generics, and
  `@throws` that the native type system cannot express.
- **Typed exceptions** — throw specific exception classes, not bare
  `\Exception`/`\RuntimeException`, so callers can catch precisely.
- **FrankenPHP worker safety** — workers stay alive in memory across requests.
  **Never** hold request state in `static` properties or other process-global
  state; it will leak between requests (and across tenants). Build a fresh
  instance per request.
- **Never bypass `TenantContext`** — every SQL statement touching a
  tenant-owned table must carry an explicit `tenant_id` predicate derived from
  `TenantContext` (there is no query-rewriting layer; the predicate IS the
  boundary, and `CrossTenantRejectionRealEngineTest` proves it per table).
  `TenantContext` is read-only in handler code and is reset by the framework
  after each response; do not call `reset()` yourself.
- **No direct database access in API handlers** beyond the sanctioned
  query/repository layer, and every query must be tenant-scoped (see
  [Tenant Isolation](docs/wiki/TENANT_ISOLATION.md)).
- **The tenant predicate is CI-enforced.** A static guard
  (`scripts/ci-tenant-predicate-guard.php`) fails the build if a
  `SELECT`/`UPDATE`/`DELETE` touches a tenant-owned table without a `tenant_id`
  predicate. A genuinely platform-wide table must be registered in
  `src/Core/Tenant/SanctionedGlobalTables.php`; a deliberate one-off unscoped
  query needs a reasoned `// @tenant-guard-ignore: <reason>` annotation.

### TypeScript (frontend)

- **Strict types** — `strict: true` is on in `web/tsconfig.json`. Do **not** use
  `any`; prefer precise types, `unknown` + narrowing, or generics.
- **ESLint** must pass: `cd web && npm run lint` (config in
  `web/eslint.config.mjs`, extending `eslint-config-next`).
- **React 19 / Next.js 16** — this is **not** the Next.js most references
  assume. Per [`web/AGENTS.md`](web/AGENTS.md), read
  `web/node_modules/next/dist/docs/` before writing frontend code and heed
  deprecation notices.

### Clean code over backward-compatibility (no legacy)

Whity Core has **no production deployments**, so there is no installed base to
stay compatible with. Prefer making the codebase *correct and clean* over
preserving old behavior:

- Delete dead code and finish half-built features instead of working around
  them.
- Drop compatibility shims — no alias methods or dual-accept code paths; pick
  one canonical API and update every caller.
- Migrations are a **clean, consolidated set** (each table created in its final
  form). When the schema changes, prefer editing the relevant migration over
  layering a patch-migration; there is no deployed database to upgrade
  incrementally. Keep `up()` idempotent and `down()` correct, and prove
  schema-equivalence if you rewrite history.
- Don't add "existing deployments must…" caveats — they don't apply.

The quality bar still holds: clean means tested and verified (real-engine tests,
green CI), not unverified.

### Generated artifacts — keep them in sync

Several committed files are generated and **drift-gated in CI** — regenerate and
commit them in the same PR as the change that affects them, or the build fails:

- **OpenAPI spec** — `public/openapi.json` is generated from the live PHP router.
  After any route/schema change run `php public/index.php generate:openapi`; CI
  fails if the committed spec differs from a fresh generation (and the PHPUnit
  `OpenApiSpecDriftTest` asserts the same).
- **Typed API client** — `web/lib/api/schema.d.ts` is generated from
  `public/openapi.json`. After the spec changes run
  `cd web && npm run generate:api`; CI fails on drift. So a single API change
  touches three files: the handler/route, `public/openapi.json`, and
  `web/lib/api/schema.d.ts`.
- **Design tokens** — styling variables come from `base.json` via the token
  pipeline; run `cd web && npm run tokens:check` (CI runs it). Never hand-edit a
  generated token output or use raw hex/RGB in components.

## Testing Requirements

Both **unit and integration tests are mandatory** for changes that touch
behavior. The full suite must be **100% green before merge** — CI runs PHPUnit
with `failOnRisky`/`failOnWarning` enabled (see `phpunit.xml`), so warnings and
risky tests fail the build.

### Security-critical coverage

Integration tests **must** verify:

- **RBAC route protection** — protected routes require the correct permission
  and return a structured `403` to users without it. See
  `tests/Integration/RbacRouteEnforcementTest.php` for the canonical pattern.
- **Tenant isolation** — one tenant can never read or mutate another tenant's
  data. See `tests/Integration/TenantDataIsolationTest.php` and
  `tests/Security/` (`CrossTenantAccessTest`, `TenantWorkerLeakageTest`,
  `RequestIsolationTest`).

### Prefer real-engine tests for data-layer logic

For anything that exercises SQL semantics, **prefer real-engine tests
(in-memory SQLite, or PostgreSQL) over a fully mocked `PDO`.** Mocked PDO does
not enforce real SQL behavior and has hidden production bugs in this codebase:
`tests/Api/RolesApiHandlerRealEngineTest.php` documents two defects that mocked
PDO masked (numeric permission ids resolving to zero permissions, and
API-created roles being undeletable). When a test asserts how a query behaves,
run it against a genuine engine.

**Verify data-layer / migration / auth changes on real PostgreSQL.** The SQLite
suite masks Postgres-only bugs (reserved words, DDL/type/cast, `rowCount()` on a
`SELECT`). CI's `postgres-integration` job runs `migrate run` + `seed` + a second
idempotent `migrate run` against a real PostgreSQL service; for every new
tenant-owned table, extend `CrossTenantRejectionRealEngineTest` (it runs on the
real engine).

### Running backend tests

Run inside the FrankenPHP container (there is no native PHP requirement on your
host). CI uses PHP 8.4, so match that locally:

```bash
# Full PHPUnit suite
docker exec whity_frankenphp vendor/bin/phpunit

# PHPStan static analysis (level 8, same scope as CI)
docker exec whity_frankenphp vendor/bin/phpstan analyse src tests plugins sdk
```

`make test` is a convenience target that runs `php vendor/bin/phpunit
--no-coverage`. If you prefer a throwaway container instead of the running
stack, the same commands work under `php:8.4-cli` with the repo mounted, e.g.:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.4-cli vendor/bin/phpunit
```

(`composer install` must have been run first so `vendor/` exists.)

### Running frontend tests

```bash
cd web
npm test            # Jest unit/component tests (web/__tests__)
npm run test:e2e    # Playwright E2E suite (web/e2e)
npm run test:e2e:ui # Playwright interactive UI mode
```

The Playwright E2E suite (`web/e2e/`) drives the real admin UI against the live
backend (Chromium). The backend stack **must already be running** at
`http://localhost:8000`; Playwright starts the frontend dev server itself on
port `3010`. Install the browser binary once with
`npx playwright install chromium`. See [`web/README.md`](web/README.md) for
seeded accounts and shared-database discipline.

## Pull Request Process

1. **Push your branch** and open a PR against `main`:

   ```bash
   git push -u origin type/WC-XX-short-description
   gh pr create --base main --title "WC-XX: Description"
   ```

2. **PR title** uses the same format as commits: **`WC-XX: Description`**.

3. **PR body** should explain *what* changed and *why*, call out anything
   reviewers should focus on, note testing performed, and reference the tracking
   issue (e.g. `Relates to #1` / `Closes #9`). Do **not** add AI/tool attribution.

4. **CI must be green — all jobs.** Two workflows run on every PR to `main`;
   merge only after every job passes (never merge on a red or still-running
   check):

   - [`automated-tests.yml`](.github/workflows/automated-tests.yml):
     - **Unit, static analysis & plugin smoke (SQLite)** — `phpunit`, the
       OpenAPI-spec drift check, `phpstan analyse src tests plugins sdk`, the
       plugin-load smoke, the tenant-predicate guard, and the plugin
       tenant-isolation conformance check.
     - **Migrations + seed on real PostgreSQL** — migrate / seed / idempotent
       re-migrate against a real Postgres service.
     - **Frontend types, tests & generated-artifact drift** (Node 22) — the
       typed-client (`schema.d.ts`) drift check, the design-token drift check,
       the shadcn registry build, `tsc --noEmit`, ESLint (zero errors), the
       production `next build`, and Jest.
   - [`e2e.yml`](.github/workflows/e2e.yml): the **Playwright multi-role suite
     across 3 shards**, run against the full Docker stack. Treat e2e as required
     for any change to request handling, middleware, auth, SQL, the worker
     pipeline, `docker-compose`, or plugins — unit tests built from a `Request`
     object can mask worker/integration behavior.

5. **Code review** — address review feedback by pushing follow-up commits (or a
   clean rebase). Verify suggestions technically rather than applying them
   blindly; push back with reasoning when something is wrong.

6. **PR checklist:**

   - [ ] `declare(strict_types=1)` in new PHP files; PSR-12 followed.
   - [ ] No `static`/process-global request state (FrankenPHP worker safety).
   - [ ] RBAC permission checks included for any data operation.
   - [ ] Tenant isolation preserved (every query on a tenant-owned table has
         an explicit `TenantContext`-derived `tenant_id` predicate; no
         cross-tenant leakage — extend `CrossTenantRejectionRealEngineTest`
         for new tables).
   - [ ] Unit + integration tests added/updated and passing locally.
   - [ ] Integration tests cover RBAC route protection and tenant isolation
         where relevant; data-layer logic uses real-engine tests.
   - [ ] PHPStan (level 8, `src tests plugins sdk`) clean; frontend `tsc` +
         `npm run lint` clean if `web/` changed.
   - [ ] Generated artifacts regenerated and committed if affected:
         `public/openapi.json` (`generate:openapi`) and `web/lib/api/schema.d.ts`
         (`npm run generate:api`) on API changes; design tokens
         (`npm run tokens:check`) on styling changes.
   - [ ] Data-layer / migration / auth changes verified on real PostgreSQL; new
         tenant-owned tables added to `CrossTenantRejectionRealEngineTest`.
   - [ ] No hardcoded secrets/values; config via env vars.
   - [ ] No `Co-authored-by` / AI attribution in commits or PR body.

7. **Merge policy** — a task is only **Done** once its PR is **merged** to
   `main`. Opening the PR is not completion.

## Architecture Decision Records (ADRs)

Significant, hard-to-reverse decisions are recorded as ADRs in
[`docs/adr/`](docs/adr). Copy the template at
[`docs/adr/0000-template.md`](docs/adr/0000-template.md) to a new file named
`NNNN-short-title.md` (incrementing the number) and submit it in the PR that
implements the decision.

**Write an ADR when** a change:

- introduces or removes a framework, dependency, datastore, or external service;
- establishes a cross-cutting convention (e.g. how tenant scoping is enforced,
  how plugins register permissions, error/exception strategy);
- changes a public contract, API shape, or the plugin interface;
- chooses between competing approaches where the trade-offs matter and future
  contributors will ask "why was it done this way?".

Routine bug fixes, refactors, and small features do **not** need an ADR.

## Architecture Principles (Quick Reference)

These are non-negotiable. See [SECURITY.md](SECURITY.md),
[Architecture](docs/wiki/Architecture.md), and
[Tenant Isolation](docs/wiki/TENANT_ISOLATION.md) for the full rationale.

### 1. Stateless controllers (CRITICAL)

FrankenPHP keeps workers alive in memory. **Never use static request state:**

```php
// GOOD — fresh per request
class TaskController extends BaseController {
    public function store(Request $request): Response {
        return Task::create($request->validated());
    }
}

// WRONG — leaks between requests (and across tenants)!
class TaskController extends BaseController {
    private static array $cache = [];  // FORBIDDEN
}
```

### 2. RBAC enforcement

Every protected operation verifies permissions at the handler boundary. The
checker takes the user id, the `resource:action` permission, and the tenant id,
and resolves the user's **effective** permissions — their direct role plus the
role hierarchy plus roles inherited through their organizational unit (and its
ancestor OUs):

```php
if (!$roleChecker->hasPermission($userId, 'tasks:update', $tenantId)) {
    // structured 403
}
```

### 3. Tenant isolation

All queries filter by tenant with an explicit predicate bound from
`TenantContext` — this hand-written predicate is the query-level isolation
mechanism (WC-161 removed the never-wired `ScopesToTenant` rewriter):

```php
// CORRECT — explicit tenant scope
$sql = 'SELECT * FROM tasks WHERE id = ? AND tenant_id = ?';
$db->query($sql, [$id, TenantContext::getTenantId()]);

// WRONG — no tenant filter; leaks data across tenants!
$db->query('SELECT * FROM tasks WHERE id = ?', [$id]);
```

`INSERT`s set `tenant_id` explicitly from `TenantContext`; `UPDATE`/`DELETE`
include `WHERE tenant_id = ?`. The system tenant (id 0) follows the
platform-wide `if ($tenantId === 0) { unscoped } else { scoped }` convention.
Cross-tenant rejection is proven per table on a real engine by
`tests/Integration/CrossTenantRejectionRealEngineTest.php` — extend it when
adding a tenant-owned table. Never query or write `core_schema_migrations`
from a plugin — plugins use the standard `schema_migrations` table.

### 4. Plugins, not forks

Extend the framework via plugins implementing `Whity\Sdk\PluginInterface` (from
the standalone `whity/plugin-sdk` package — there is no `Whity\Core` plugin
alias). A plugin may declare optional SDK/host **version constraints**
(`PluginRequirementsInterface::getSdkConstraint()` / `getCoreConstraint()`); the
loader **quarantines** an incompatible or malformed plugin instead of letting it
run. Plugin authors should follow
[Plugin Development](docs/wiki/Plugin-Development.md), the
[Hook System](docs/wiki/HOOK_SYSTEM.md), and the
[Permission System](docs/wiki/PERMISSION_SYSTEM.md). Hook payloads must contain
only scalar data — never model instances or DB connections.

**Plugin-repo hygiene:** product/customer plugins live in their own repositories
and are dropped into `plugins/` at deploy time — they are **never** committed to
whity-core. The only plugins in this repo are the reference/example plugins that
document the SDK and exercise it in CI (`plugins/HelloWorld/`,
`plugins/ExamplePlugin.php`); `plugins/.gitignore` enforces this. Extend the
existing examples rather than adding new ones.

## Further Reading

- [Development Workflow](docs/wiki/Development-Workflow.md) — the Instruction Set + Tasker flow (how work moves from "ready" to "merged")
- [Architecture](docs/wiki/Architecture.md) — design principles and runtime model
- [Plugin Development](docs/wiki/Plugin-Development.md) — building plugins
- [Hook System](docs/wiki/HOOK_SYSTEM.md) — registering and using hooks
- [Permission System](docs/wiki/PERMISSION_SYSTEM.md) — how permissions work
- [Tenant Isolation](docs/wiki/TENANT_ISOLATION.md) — tenant scoping and isolation
- [CLI Reference](docs/wiki/CLI_REFERENCE.md) — `whity-cli` commands
- [SECURITY.md](SECURITY.md) — security requirements and vulnerability reporting
