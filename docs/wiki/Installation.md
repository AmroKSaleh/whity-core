# Installation

## Requirements

- PHP 8.4 (the platform target — see `Dockerfile`, pinned to `dunglas/frankenphp:1-php8.4`)
- Composer (run on the **host** before `docker compose up` — see step 2)
- FrankenPHP (persistent worker mode)
- PostgreSQL 15 (the only supported database; `Whity\Database\Database` builds a `pgsql:` DSN)
- Docker + Docker Compose (recommended local setup)

## Quick Start (Docker)

The recommended way to run Whity Core locally is via Docker Compose, which starts PostgreSQL and the FrankenPHP app together.

### 1. Configure environment

Copy `.env.example` to `.env` (if present) and set the database and secret values. The FrankenPHP service reads these (see `docker-compose.yml`):

- `DB_USER`, `DB_PASSWORD`, `DB_NAME`, `DB_HOST`, `DB_PORT`
- `JWT_SECRET` — required outside `APP_ENV=development`
- `ENCRYPTION_KEY` — required outside `APP_ENV=development` (AES-256-CBC key for stored TOTP 2FA secrets)
- Optional pooling: `DB_CONNECT_TIMEOUT`, `DB_MAX_LIFETIME`, `DB_PING_INTERVAL`
- Worker tuning: `FRANKENPHP_WORKERS`, `FRANKENPHP_TIMEOUT`, `MAX_REQUESTS`

### 2. Install PHP dependencies on the host — before starting the stack

```bash
composer install
```

**Do this first.** The dev image (the `base` target in `Dockerfile`) deliberately
ships **no `vendor/`**, and `docker-compose.yml` bind-mounts your checkout over
`/app`. So the container runs against whatever `vendor/` your working copy has —
and if that is nothing, `db-init` exits `255` with `vendor/autoload.php not
found` and the stack never comes up. The error names the missing file rather
than the missing step, which is why this is easy to lose time on.

Only the `release` image target runs `composer install` for you; local
development does not.

#### On Windows: mirror the `sdk` path repository instead of symlinking it

```bash
COMPOSER_MIRROR_PATH_REPOS=1 composer install
```

The SDK is consumed through a Composer **path repository** (`{"type": "path",
"url": "sdk"}` in `composer.json`), which Composer satisfies by symlinking
`vendor/whity/plugin-sdk` to `../../sdk/`. A symlink created on a Windows host
does not survive the bind mount into a Linux container, so every
`Whity\Sdk\*` class fails to autoload inside the stack.

Setting `COMPOSER_MIRROR_PATH_REPOS=1` makes Composer **copy** the SDK into
`vendor/` instead. The trade-off is that SDK edits no longer appear in `vendor/`
automatically — re-run `composer install` after changing anything under `sdk/`.

You can confirm which you have:

```bash
ls -la vendor/whity/          # symlink → plugin-sdk -> ../../sdk/
                              # mirrored → plugin-sdk/ as a real directory
```

### 3. Start the stack

```bash
docker compose up --build
```

The app is served on `http://localhost:8000` (mapped from container port 80).

### 4. Run database migrations

Migrations are run via the CLI entry point in `public/index.php`:

```bash
docker compose exec frankenphp php public/index.php migrate
```

Migrating alone creates the **bootstrap administrator** — the system-tenant (id 0)
account you first sign in with. Its address comes from `INITIAL_SYSTEM_ADMIN_EMAIL`
and its password from `INITIAL_SYSTEM_ADMIN_PASSWORD`. Set both *before* this step:
the default address, `system@whity.local`, is unroutable, so it can never receive a
password reset. Once a named administrator exists, retire the bootstrap account —
see the [Go-Live Checklist](Go-Live-Checklist.md).

Other CLI commands: `seed`, `generate:openapi`, `revoked-tokens:cleanup`.

### 5. Web UI (optional)

The Next.js UI lives in `web/` and proxies `/api/*` to the backend. See `web/README.md`.

### 6. Add plugins

Create a plugin directory under `plugins/` containing a class that implements
`Whity\Sdk\PluginInterface`. See [Plugin Development](Plugin-Development.md).

## Learn the system

- [Architecture](Architecture.md) — request lifecycle, plugins, RBAC, multi-tenancy, schema, deployment.
- [Sprint 1 Setup Guide](Sprint-1-Setup.md) — detailed local development walkthrough.

See [CONTRIBUTING.md](../../CONTRIBUTING.md) for contribution guidelines.

## Updating

See [Core-Update.md](./Core-Update.md): check with
`php public/index.php update:check`, apply with the manual runbook
(backup → checkout the release tag → migrate → regenerate spec → restart).
