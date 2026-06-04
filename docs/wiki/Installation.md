# Installation

## Requirements

- PHP 8.4 (the platform target — see `Dockerfile`, pinned to `dunglas/frankenphp:1-php8.4`)
- Composer
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

### 2. Start the stack

```bash
docker compose up --build
```

The app is served on `http://localhost:8000` (mapped from container port 80).

### 3. Run database migrations

Migrations are run via the CLI entry point in `public/index.php`:

```bash
docker compose exec frankenphp php public/index.php migrate
```

Other CLI commands: `seed`, `generate:openapi`, `revoked-tokens:cleanup`.

### 4. Web UI (optional)

The Next.js UI lives in `web/` and proxies `/api/*` to the backend. See `web/README.md`.

### 5. Add plugins

Create a plugin directory under `plugins/` containing a class that implements
`Whity\Core\PluginInterface`. See [Plugin Development](Plugin-Development.md).

## Learn the system

- [Architecture](Architecture.md) — request lifecycle, plugins, RBAC, multi-tenancy, schema, deployment.
- [Sprint 1 Setup Guide](Sprint-1-Setup.md) — detailed local development walkthrough.

See [CONTRIBUTING.md](../../CONTRIBUTING.md) for contribution guidelines.
