# Deployment Guide

This guide describes the atomic deployment system and rollback procedures for Whity Core.

## Overview

Whity Core uses an atomic deployment system to ensure zero-downtime updates and safe rollbacks. The system is tenant-isolated, meaning deployments can be applied and rolled back for specific tenants without affecting others.

## Deployment Process

The deployment process follows these steps:

1. **Staging**: New code is uploaded/staged in a temporary directory on the server.
2. **Apply**: The `DeploymentManager` is triggered via the API.
   - A new version directory is created for the tenant in `storage/deployments/{tenant_id}/{version}`.
   - State is tracked as `pending` in the database.
   - Schema migrations (if any) are executed within a database transaction.
   - Files are moved atomically into the version directory.
   - State is updated to `applied`.
3. **Atomic Swap**: The application (FrankenPHP) loads the latest `applied` version for the tenant.

## Rollback Procedures

### Automatic Rollback
If a deployment fails during the `apply` phase (e.g., a migration fails), the system automatically:
- Rolls back the database transaction.
- Deletes any temporary files.
- Marks the deployment as `failed`.

### Manual Rollback
If a deployed version has issues, an administrator can trigger a rollback to the previous version:
- **API Endpoint**: `POST /api/deployments/rollback`
- The system identifies the previous `applied` version and restores it as the current active version.
- Database state is updated to reflect the rollback.

## API Reference

### Apply Deployment
`POST /api/deployments/apply`
Payload:
```json
{
  "version": "v1.1.0",
  "source_path": "/path/to/staged/code"
}
```

### Rollback Deployment
`POST /api/deployments/rollback`
(No payload required, uses tenant context)

### Deployment Status
`GET /api/deployments/status`
Returns the recent deployment history for the tenant.

### Migration Rollback
`POST /api/migrations/rollback`
Payload:
```json
{
  "migration_name": "006_create_deployment_tables"
}
```

## Safety Guarantees

- **Atomicity**: Deployments use database transactions and atomic filesystem operations.
- **Isolation**: Tenant A's deployment operations never affect Tenant B.
- **Data Integrity**: Migrations are rolled back automatically on failure.

## Staging & Load Testing (WC-32)

An isolated, prod-like staging stack and a k6 load-testing harness ship with the
repo. The staging stack runs on **non-default host ports** so it never collides
with the dev demo (`:8000` / `:5432`).

### Staging stack

`docker-compose.staging.yml` brings up FrankenPHP + PostgreSQL under a distinct
project name (`whity-staging`), distinct volume (`postgres_data_staging`), and
alt ports: **frankenphp `:8100`, postgres `:5433`**. It is prod-like —
`APP_ENV=staging` (not `development`), so the `JWT_SECRET` (>= 32 chars) and
`ENCRYPTION_KEY` fail-fast guards in `public/index.php` engage. Secrets, the
explicit `CORS_ALLOWED_ORIGINS`, and `INITIAL_*_PASSWORD` seed creds are supplied
via an env file.

```bash
# 1. Configure secrets (do NOT commit the populated .env.staging)
cp .env.staging.example .env.staging        # edit secrets; JWT/ENCRYPTION >= 32 chars

# 2. Bring it up (build + start). Note: in a git worktree without vendor/,
#    copy it first (cp -r <main-checkout>/vendor ./vendor) so the workers boot.
docker compose -p whity-staging -f docker-compose.staging.yml \
  --env-file .env.staging up -d --build
#    or:  make staging-up

# 3. Migrate + seed
./scripts/init-staging-db.sh                 # or: make staging-init

# 4. Verify
curl http://localhost:8100/api/health        # -> 200 {"status":"ok",...}
curl -i http://localhost:8100/api/admin/stats # -> 401 (auth required)

# Tear down (keep data):  docker compose -p whity-staging -f docker-compose.staging.yml down
# Tear down (wipe data):  make staging-down ARGS=-v
```

### Load testing

A k6 harness lives under `load-tests/` and runs from the `grafana/k6` Docker
image (no repo dependency). It exercises `GET /api/health`, the
`POST /api/login` -> authenticated `GET /api/admin/stats` flow, and the
RBAC-gated `GET /api/roles`.

Auth-flow notes (WC-160):

- `POST /api/login` (and the other auth POSTs) require the CSRF defense header
  `X-Requested-With: XMLHttpRequest`; the same header is required on any
  cookie-authenticated state-changing request. The k6 script already sends it —
  add it to any hand-rolled `curl` login flow.
- Outside `APP_ENV=development` auth cookies carry the `Secure` flag, so
  cookie-based auth flows only work over HTTPS. The staging stack serves plain
  HTTP on :8100, which means strict cookie jars (k6, `curl -c/-b`) will not
  replay the auth cookie against it — run cookie-auth flows against an
  HTTPS-terminated deployment, or a stack started with `APP_ENV=development`.

```bash
VUS=10 DURATION=30s ADMIN_PASSWORD='<staging-admin-pw>' ./load-tests/run.sh
#  or:  make load-test
```

See `load-tests/README.md` for full usage and `load-tests/BASELINE.md` for
committed baseline numbers (throughput, p95 latency, error rate).

### CI: staging-deploy workflow

`.github/workflows/staging-deploy.yml` builds the FrankenPHP image on push to
`main` (or manual `workflow_dispatch`) and has a **gated** deploy job. The build
job always runs; the deploy job is skipped (stays green) unless deployment is
explicitly armed, so the workflow never fails for lack of a real staging host.

To arm the deploy job, configure these under
**Settings > Secrets and variables > Actions**:

| Kind | Name | Purpose |
| --- | --- | --- |
| Variable | `STAGING_DEPLOY_ENABLED` | Set to `true` to arm the deploy job. |
| Variable | `STAGING_HOST` | Staging host/DNS (parameterizes deploy + environment URL). |
| Secret | `STAGING_SSH_KEY` | Deploy credential for the staging host. |
| Secret | `STAGING_JWT_SECRET` | JWT signing secret (>= 32 chars; enforced by the workflow). |
| Secret | `STAGING_ENCRYPTION_KEY` | TOTP secret-encryption key. |
| Secret | `STAGING_DB_PASSWORD` | Staging database password. |

Until a real host is provisioned, the deploy step is a documented placeholder
showing the intended rollout (registry push -> compose up -> migrate/seed ->
`/api/health` smoke check).
