# SLOs & alerting (WC-f)

The service-level objectives for a whity-core deployment and the alert templates
that enforce them. The templates live in [`ops/alerting/`](../../ops/alerting/)
and are **activate-by-config**: drop them into your Prometheus/Alertmanager
stack, fill in your URLs + webhook, and reload. No app change is required to
start — the SLIs come from signals whity-core already exposes.

## Service level indicators (what we measure)

whity-core exposes no `/metrics` endpoint yet, so we measure from the outside by
**blackbox-probing `GET /api/health`** — which exercises the full path a user
hits (proxy → TLS → FrankenPHP worker → DB ping) and returns a structured
health snapshot (`status`, `db_connected`, `version`, worker/memory/uptime).

| SLI | Source | Signal |
|---|---|---|
| **Availability** | blackbox probe of `/api/health` | `probe_success == 1` **and** HTTP `200` (HTTP `503` = degraded: worker up, DB down) |
| **Latency (coarse)** | same probe | `probe_duration_seconds` on the cheapest route — an early-warning proxy until per-route histograms exist |
| **Backup freshness** | `.last-success` marker from `scripts/backup-db.sh` → node_exporter textfile | `whity_backup_last_success_timestamp_seconds` |
| **TLS validity** | probe | `probe_ssl_earliest_cert_expiry` |

## Service level objectives (the targets)

Starting targets for the first release — tune to the contract you actually
commit to, then align each alert's `for:` / threshold in
[`prometheus-rules.yml`](../../ops/alerting/prometheus-rules.yml).

| Objective | Target | Window | Error budget |
|---|---|---|---|
| **Availability** — `/api/health` returns 200 | **99.5%** | 30 days | ~3h 39m/month |
| **Health latency** — avg probe RTT | **< 1s** | 5-min avg | — |
| **Backup freshness (RPO)** — successful backup exists | **< 26h old** | continuous | see [Backups](Backups-and-Restore.md) |
| **Restore (RTO)** — restore-to-clean duration | tracked by the [restore drill](Backups-and-Restore.md) | per drill | — |

99.5% (not 99.9%) is a deliberately honest starting point for a young service:
commit to what a single-node deployment can actually hold, publish the budget,
and tighten it once you have the redundancy to back a higher number.

## Alerts (what pages)

From [`prometheus-rules.yml`](../../ops/alerting/prometheus-rules.yml):

| Alert | Severity | Fires when |
|---|---|---|
| `WhityHealthProbeDown` | critical | probe fails for >2m (unreachable) |
| `WhityHealthDegraded` | critical | HTTP 503 for >2m (worker up, DB ping failing) |
| `WhityHealthUnexpectedStatus` | warning | non-200/503 for >5m |
| `WhityHealthLatencyHigh` | warning | avg probe RTT >1s over 5m, for >10m |
| `WhityTlsCertExpiringSoon` | warning | cert expires in <14d |
| `WhityBackupStale` | critical | no successful backup in >26h |
| `WhityBackupMetricMissing` | warning | backup metric absent for >1h (exporter/cron down) |

Splitting **down** (probe failed) from **degraded** (HTTP 503) matters: they need
different first moves — the app process vs. its database — and blackbox alone
can't tell them apart unless you accept 503 as a completed probe, which the
[blackbox module](../../ops/alerting/blackbox-and-scrape.example.yml) does.

## Wiring it up

1. **Probe** — deploy `blackbox_exporter`; add the `whity_health` module and the
   `whity-health` scrape job from
   [`blackbox-and-scrape.example.yml`](../../ops/alerting/blackbox-and-scrape.example.yml)
   (set your staging + prod URLs and the exporter address).
2. **Backup metric** — cron
   [`backup-freshness-textfile.sh`](../../ops/alerting/backup-freshness-textfile.sh)
   a few minutes after the backup cron, into the node_exporter textfile dir.
3. **Rules** — load `prometheus-rules.yml`; verify with
   `promtool check rules ops/alerting/prometheus-rules.yml`.
4. **Routing** — apply
   [`alertmanager-route.example.yml`](../../ops/alerting/alertmanager-route.example.yml)
   and mount your notification webhook at the `url_file` path (never commit it).

### Error tracking (complementary)

Exceptions are captured out-of-band by the error-tracker seam (set
`ERROR_TRACKER_DSN` / `SENTRY_DSN`; see the observability config). Metrics/alerts
answer *"is it up and within budget?"*; the error tracker answers *"what threw,
with which tenant, request id, and loaded plugin versions?"* — run both.

## Roadmap

A first-class `/metrics` endpoint (per-route request rate, error ratio, latency
histograms) would let availability/latency be measured from real traffic rather
than a synthetic probe, and enable a true request-error-ratio SLO. Tracked as
future work; the blackbox approach here is the pragmatic day-one baseline.
