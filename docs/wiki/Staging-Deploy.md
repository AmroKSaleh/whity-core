# Staging deploy (WC-e)

Continuous deploy of the published release images to a staging host, over SSH.
It is **activate-by-config**: nothing runs until the staging secrets are set, so
merging the workflow never turns the pipeline red on a repo with no staging box.

## What it does

`.github/workflows/deploy-staging.yml`, on a successful **Release** run (or a
manual dispatch with a chosen tag):

1. copies [`docker-compose.staging-remote.yml`](../../docker-compose.staging-remote.yml) to the host,
2. writes `.env.staging` on the host from the `STAGING_ENV` secret (mode 0600),
3. `docker compose pull` **both** release images at the same tag — the API
   `ghcr.io/<repo>:<tag>` and the UI `ghcr.io/<repo>/web:<tag>`,
4. `docker compose up -d`,
5. runs `php public/index.php migrate run` inside the app container (idempotent;
   it does **not** seed — a redeploy never mutates staging data),
6. polls `GET /api/health` **and** `GET /login` on the host until both return
   `200`, and **fails the run** if either never does (dumping that tier's logs).

The UI gate is there because `/api/health` alone once reported a perfectly
healthy deploy of a stack that shipped no frontend at all (WHIT-588): "the
deploy worked" and "a human can log in" are two facts, and only the first was
being measured.

Unlike `docker-compose.staging.yml` (builds from a bind-mounted checkout, for
local use), the remote compose runs the **pre-built release images** — the exact
artifacts `release.yml` builds and smoke-tests — with no source or Caddyfile mount.

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
| `STAGING_APP_PORT` | `8100` | host port the API binds (and the health check polls) |
| `STAGING_WEB_PORT` | `3100` | host port the UI binds (and the `/login` check polls) |
| `STAGING_REGISTRY_USER` / `STAGING_REGISTRY_TOKEN` | — | GHCR `read:packages` creds, only if the images are **private** |

### Host prerequisites

- Docker Engine + the Compose plugin.
- The deploy dir exists and is writable by `STAGING_SSH_USER`
  (`sudo mkdir -p /opt/whity-staging && sudo chown $USER /opt/whity-staging`).
- Ports `STAGING_APP_PORT` and `STAGING_WEB_PORT` are free; terminate TLS at a
  reverse proxy in front of them. Point the proxy's public origin at the **web**
  port — the UI proxies `/api/*` to the backend itself over the compose network,
  so the API port does not need to be published to the internet.

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
