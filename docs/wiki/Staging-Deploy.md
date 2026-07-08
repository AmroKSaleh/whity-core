# Staging deploy (WC-e)

Continuous deploy of the published release image to a staging host, over SSH.
It is **activate-by-config**: nothing runs until the staging secrets are set, so
merging the workflow never turns the pipeline red on a repo with no staging box.

## What it does

`.github/workflows/deploy-staging.yml`, on a successful **Release** run (or a
manual dispatch with a chosen tag):

1. copies [`docker-compose.staging-remote.yml`](../../docker-compose.staging-remote.yml) to the host,
2. writes `.env.staging` on the host from the `STAGING_ENV` secret (mode 0600),
3. `docker compose pull` the release image `ghcr.io/<repo>:<tag>`,
4. `docker compose up -d`,
5. runs `php public/index.php migrate run` inside the app container (idempotent;
   it does **not** seed — a redeploy never mutates staging data),
6. polls `GET /api/health` on the host until `200`, and **fails the run** if it
   never goes healthy (dumping the app logs).

Unlike `docker-compose.staging.yml` (builds from a bind-mounted checkout, for
local use), the remote compose runs the **pre-built release image** — the exact
artifact `release.yml` builds and smoke-tests — with no source or Caddyfile mount.

## Activation

Set these repository **secrets** (Settings → Secrets and variables → Actions):

| Secret | Purpose |
|---|---|
| `STAGING_SSH_HOST` | host / IP of the staging box (**presence of this arms the workflow**) |
| `STAGING_SSH_USER` | ssh user |
| `STAGING_SSH_KEY` | that user's **private** key (PEM) |
| `STAGING_ENV` | the full contents of `.env.staging` — copy `.env.staging.example`, fill in **real** secrets (JWT/ENCRYPTION ≥32 chars) |

Optional:

| Secret | Default | Purpose |
|---|---|---|
| `STAGING_SSH_PORT` | `22` | ssh port |
| `STAGING_DEPLOY_DIR` | `/opt/whity-staging` | where the compose file + `.env.staging` live on the host |
| `STAGING_APP_PORT` | `8100` | host port the app binds (and the health check polls) |
| `STAGING_REGISTRY_USER` / `STAGING_REGISTRY_TOKEN` | — | GHCR `read:packages` creds, only if the image is **private** |

### Host prerequisites

- Docker Engine + the Compose plugin.
- The deploy dir exists and is writable by `STAGING_SSH_USER`
  (`sudo mkdir -p /opt/whity-staging && sudo chown $USER /opt/whity-staging`).
- Port `STAGING_APP_PORT` is free; terminate TLS at a reverse proxy in front of it.

### First deploy

Migrations run automatically, but **seeding is one-time and manual** (so a
redeploy never overwrites data). After the first successful deploy:

```sh
cd /opt/whity-staging
docker compose -p whity-staging -f docker-compose.staging-remote.yml \
  --env-file .env.staging exec -T frankenphp php public/index.php seed
```

## Triggering

- **Automatic:** every successful `Release` run deploys `:latest`.
- **Manual:** Actions → *Deploy to staging* → *Run workflow* → set an image tag
  (e.g. `v1.2.3`) to roll staging forward or back to a specific release.
