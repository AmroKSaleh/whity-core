# Cron-tick Scheduler (`schedule:run`)

The scheduler (WC-scheduler) runs recurring jobs on cron schedules. It is a thin,
tenant-scoped layer over the durable job queue: a `schedule:run` process ticks
once per minute, and any **due** `scheduled_jobs` row is enqueued onto the queue
(WC-queue) to be run by the `queue:work` worker.

## Model

- **`scheduled_jobs` registry** (migration `069`, tenant-owned): one row per
  recurring job — `name` (the job handler to enqueue), `cron_expression`,
  `payload`, `queue`, `enabled`, `last_run_at`, `next_run_at`. `UNIQUE(tenant_id,
  name)` makes registration an idempotent upsert.
- **`next_run_at`-driven**: on each run the scheduler advances `next_run_at` to
  the cron's next occurrence. A tick claims rows where `enabled AND next_run_at
  <= now`, so a missed minute **catches up once** (it does not replay every
  missed minute).

## Exactly-once per minute, across workers

Multiple `schedule:run` processes (replicas, or one alongside staging/prod) are
safe. Each tick acquires a per-minute lock via the shared store:
`increment('scheduler:tick:<YYYYMMDDHHMM>', ttl)`. The **first** incrementer gets
counter `1` and owns that minute's tick; every other worker sees `>= 2` and
skips. A per-`(schedule, minute)` idempotency key on the enqueue is a
belt-and-suspenders second line of defence.

All schedule times are evaluated in **UTC**. (Per-tenant timezones are a future
refinement.)

## Cron syntax

Standard 5-field cron: `minute hour day-of-month month day-of-week`
(`0-59 0-23 1-31 1-12 0-6`, where `0`/`7` = Sunday). Each field supports the
wildcard, step values, ranges (`a-b`), range-steps, and comma lists. The
day-of-month / day-of-week fields follow the Vixie-cron rule: when **both** are
restricted, a time matches if **either** matches. Parsed by
`Whity\Core\Scheduler\CronExpression` (no external dependency).

Examples: `*/5 * * * *` (every 5 min) · `0 9-17 * * 1-5` (hourly 09:00–17:00,
Mon–Fri) · `0 3 1 * *` (03:00 on the 1st) · `30 0 * * 0` (00:30 Sundays).

## Running

```bash
php public/index.php schedule:run --once            # one tick (cron-style)
php public/index.php schedule:run --max-runtime=3600 # loop, recycle hourly (supervised)
```
Options: `--sleep=<s>` (between ticks, default 60), `--lock-ttl=<s>` (per-minute
lock TTL, default 300), `--job-retention=<s>` (completed-job GC window, default
86400).

The opt-in `scheduler` compose service runs the loop:
`docker compose --profile scheduler up`.

## Built-in retention GC

Every owned tick also prunes retained **completed** jobs older than
`--job-retention` (`JobRepository::pruneCompleted`), so the durable queue's
retained results (from the `/api/jobs` API) do not accumulate forever.

## Registering a schedule (programmatic)

```php
$repo = new Whity\Core\Scheduler\ScheduledJobRepository($pdo);
$repo->register($tenantId, 'reports.nightly', '0 2 * * *', ['scope' => 'all'], ['queue' => 'reports']);
```
The named job's handler must be registered with the `JobRegistry` (via
`CoreJobs` or a plugin) so the worker can run it. See [JOBS_API.md](JOBS_API.md)
and the durable-queue worker (`queue:work`).
