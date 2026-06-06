# Load-Test Baseline (WC-32)

Reference numbers for `load-tests/smoke.js` run against the **staging stack**
(`docker-compose.staging.yml`). Re-run after meaningful backend changes and
compare; large regressions in req/s or p95 warrant investigation.

## How this baseline was captured

```bash
# Staging stack up on alt ports (frankenphp :8100, postgres :5433)
docker compose -p whity-staging -f docker-compose.staging.yml \
  --env-file .env.staging up -d --build
./scripts/init-staging-db.sh                       # migrate + seed

# k6 via the grafana/k6 Docker image (no repo dependency), on the staging network
VUS=10 DURATION=30s ADMIN_PASSWORD=<seed-admin-pw> ./load-tests/run.sh
```

## Environment

| Item | Value |
| --- | --- |
| Target | `docker-compose.staging.yml` (FrankenPHP 1 / PHP 8.4 + PostgreSQL 15-alpine) |
| App URL (in-network) | `http://frankenphp:80` (host `http://localhost:8100`) |
| `APP_ENV` | `staging` (prod-like; JWT/ENCRYPTION fail-fast guards engaged) |
| `FRANKENPHP_WORKERS` | 8 |
| `MAX_REQUESTS` | 500 (graceful worker recycling) |
| Host | Docker Desktop on Windows 11, engine 29.5.2, 20 CPUs / ~7.6 GiB allocated |
| k6 image | `grafana/k6:latest` |
| Workload | 10 VUs, constant, 30s; each iteration = health + (login -> admin/stats) + roles |

## Results (10 VUs, 30s)

Two consecutive runs; numbers were stable across both.

| Metric | Run 1 | Run 2 |
| --- | --- | --- |
| Total HTTP requests | 2057 | 2105 |
| Throughput (`http_reqs`) | **67.7 req/s** | **68.9 req/s** |
| Iterations completed | 514 | 526 |
| `http_req_duration` avg | 146.9 ms | 144.0 ms |
| `http_req_duration` p90 | 400.5 ms | 388.3 ms |
| `http_req_duration` **p95** | **431.9 ms** | **418.3 ms** |
| `http_req_duration` max | 882.6 ms | 771.9 ms |
| `http_req_failed` (**error rate**) | **0.00%** | **0.00%** |
| Checks succeeded | 100% (2570/2570) | 100% (2630/2630) |
| `login_duration` avg / p95 | 410.9 / 540.1 ms | 402.7 / 539.5 ms |

Per-scenario failure rates: `health_failed` 0%, `auth_flow_failed` 0%,
`rbac_failed` 0% on both runs. All thresholds passed.

Container resource use under load: frankenphp ~66 MiB, postgres ~56 MiB.

## Reading the numbers

- **`login_duration` dominates latency.** `POST /api/login` does a bcrypt
  `password_verify`, which is intentionally CPU-expensive (~0.4s here). It is the
  tallest pole in p95 — the public `GET /api/health` and the cookie-authenticated
  `GET /api/admin/stats` / `GET /api/roles` calls are far cheaper (median
  ~50 ms). A realistic client logs in once and reuses the cookie, so a
  login-on-every-iteration workload is a deliberately pessimistic stress of the
  auth path.
- **0% errors** at 10 VUs means the 8-worker pool absorbed this concurrency with
  headroom; `MAX_REQUESTS=500` recycled workers mid-run without dropping requests
  (the `/api/health` `uptime_seconds` resets confirm graceful recycling).
- **Thresholds** (`http_req_failed < 1%`, overall `p95 < 800ms`) are the
  pass/fail gate. k6 exits non-zero if a threshold is breached, so `run.sh` can
  gate CI later.

## Regression triage

- Throughput drops or p95 climbs without a code reason -> check worker count
  (`FRANKENPHP_WORKERS`), DB pool knobs (`DB_MAX_LIFETIME`, `DB_PING_INTERVAL`),
  and host contention (don't run the dev demo and a heavy staging load at once).
- Non-zero `http_req_failed` -> inspect `docker logs whity_staging_frankenphp`
  for worker boot failures (e.g. missing `vendor/`) or DB connectivity (503 from
  `/api/health` means `db_connected:false`).
