# Load Testing (WC-32)

A k6-based load-testing harness for whity-core. **Zero repository dependency** —
k6 runs from the official `grafana/k6` Docker image; nothing is added to
`composer.json` or `package.json`.

## Seeding a realistic dataset first (WC-35)

A meaningful load/pagination/graph-rendering test needs more than the
bootstrap `admin@example.com` / `user@example.com` fixtures. Before pointing
k6 at a stack, bulk-insert a parameterized, deterministic multi-tenant dataset
with the `scale:seed` CLI command:

```bash
# Preview the row counts first — no database writes.
whity-cli scale:seed --dry-run --tenants=50 --users-per-tenant=200

# Then actually seed it.
whity-cli scale:seed --tenants=50 --users-per-tenant=200 --scale=2
```

The same `--seed` (default 42) plus the same shape flags always produce the
exact same dataset — tenants, OU hierarchies, tenant-scoped roles, users, and
a family-relations graph of persons/edges — so results are reproducible
across runs and machines; re-running with identical flags is idempotent
(nothing is duplicated). See `whity-cli scale:seed --help`, or
`src/Database/ScaleSeeder/ScaleSeederConfig.php` for the full flag list and
defaults.

## Contents

| File | Purpose |
| --- | --- |
| `smoke.js` | k6 script: health, login -> admin/stats auth flow, RBAC-gated `/api/roles`. |
| `run.sh` | Runner that wraps `docker run grafana/k6` on the staging network. |
| `BASELINE.md` | Committed baseline numbers (req/s, p95, error rate) + how to read them. |

## Prerequisites

1. The **staging stack** is up (see the repo `docker-compose.staging.yml` and the
   "Staging & load testing" section of `docs/wiki/DEPLOYMENT_GUIDE.md`):

   ```bash
   cp .env.staging.example .env.staging        # then set real secrets
   docker compose -p whity-staging -f docker-compose.staging.yml \
     --env-file .env.staging up -d --build
   ./scripts/init-staging-db.sh                 # migrate + seed
   curl http://localhost:8100/api/health        # expect HTTP 200
   ```

2. The Docker network the stack created exists (default `whity-staging_default`).
   The k6 container joins it and reaches the app at `http://frankenphp:80`.

## Running

```bash
# Defaults: 10 VUs, 30s, against the staging network.
./load-tests/run.sh

# Heavier run:
VUS=50 DURATION=2m ./load-tests/run.sh

# Pass the staging admin seed password (matches INITIAL_ADMIN_PASSWORD):
ADMIN_PASSWORD='your-staging-admin-pw' ./load-tests/run.sh
```

Or invoke k6 directly (equivalent to what `run.sh` does):

```bash
docker run --rm -i --network=whity-staging_default \
  -e BASE_URL=http://frankenphp:80 \
  -e ADMIN_EMAIL=admin@example.com \
  -e ADMIN_PASSWORD='your-staging-admin-pw' \
  -e VUS=20 -e DURATION=30s \
  grafana/k6 run - < load-tests/smoke.js
```

There is also a convenience Makefile target: `make load-test`
(honours `VUS`, `DURATION`, `ADMIN_PASSWORD`, `NETWORK`).

### Targeting something other than the staging network

If you want to hit the app over the published host port instead of the Docker
network (e.g. it runs elsewhere), point `BASE_URL` at it and use host networking
or `host.docker.internal`:

```bash
docker run --rm -i \
  -e BASE_URL=http://host.docker.internal:8100 \
  -e ADMIN_PASSWORD='your-staging-admin-pw' \
  grafana/k6 run - < load-tests/smoke.js
```

## Configuration knobs

| Env | Default | Meaning |
| --- | --- | --- |
| `BASE_URL` | `http://frankenphp:80` | Target base URL (in-network service name). |
| `ADMIN_EMAIL` | `admin@example.com` | Seeded admin login. |
| `ADMIN_PASSWORD` | `staging_admin_pw_change_me` (run.sh) / `admin123` (script) | Seeded admin password. |
| `VUS` | `10` | Concurrent virtual users. |
| `DURATION` | `30s` | Test duration (`30s`, `2m`, ...). |
| `NETWORK` | `whity-staging_default` | Docker network the k6 container joins (run.sh). |
| `SCRIPT` | `load-tests/smoke.js` | k6 script to run (run.sh). |

## Reading results

k6 prints a `TOTAL RESULTS` block. The headline metrics:

- **`http_reqs`** — total requests and the trailing **req/s** throughput.
- **`http_req_duration`** — latency distribution; watch **`p(95)`**.
- **`http_req_failed`** — overall HTTP **error rate** (the pass/fail gate).
- **`login_duration`** (custom) — isolates the bcrypt-bound login cost.
- **`health_failed` / `auth_flow_failed` / `rbac_failed`** (custom) — per-scenario
  failure rates.

### Thresholds and exit code

`smoke.js` declares thresholds (`http_req_failed < 1%`, overall `p95 < 800ms`,
and `< 1%` per scenario). If any threshold is breached, **k6 exits non-zero** —
so `run.sh` is CI-gateable as-is. Tune the thresholds in `smoke.js` to your
hardware/SLOs.

See `BASELINE.md` for committed reference numbers and regression-triage notes.
