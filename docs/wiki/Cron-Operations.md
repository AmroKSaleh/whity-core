# Cron Operations Guide

This guide describes the cron jobs and maintenance tasks for Whity Core.

## Overview

Whity Core includes several automated maintenance commands that should be scheduled via cron to keep the system healthy and performant.

## Available Cron Commands

### Revoked Tokens Cleanup

**Command**: `php /var/www/whity/public/index.php revoked-tokens:cleanup`

**Purpose**: Deletes expired JWT revocation entries from the `revoked_tokens` table.

**Why**: The revocation table grows over time as tokens are revoked. Expired entries can safely be deleted since they can no longer be used. This keeps the table small and query performance fast.

**Recommended Schedule**: `0 2 * * * php /var/www/whity/public/index.php revoked-tokens:cleanup`

**Schedule Explanation**:
- `0` = minute 0
- `2` = hour 2 (2:00 AM)
- `*` = every day of month
- `*` = every month
- `*` = every day of week

This runs daily at 2:00 AM UTC (off-peak time).

**Expected Output**: `Cleaned {count} expired revocation entries`

**Retention policy**: a revocation row only needs to outlive the token it
revokes. Once `expires_at` is in the past the underlying token is already dead
(the `exp`/epoch checks reject it without consulting this table), so the row is
safe to delete. Pruning runs daily, so at steady state the table holds only
**not-yet-expired revocations plus any recently-expired rows still awaiting the
next cron pass** — it never grows without bound. The delete uses
`WHERE expires_at < CURRENT_TIMESTAMP` (standard SQL, portable across PostgreSQL
and SQLite) and is backed by `idx_revoked_tokens_expires_at` (migration 011), so
it stays cheap even on a large table.

**`revoked_tokens` is a sanctioned GLOBAL table**: a JWT `jti` is unique
platform-wide, so the table has **no `tenant_id` column** and the cleanup delete
intentionally carries **no tenant predicate** — by design, not by omission. It is
listed in `\Whity\Core\Tenant\SanctionedGlobalTables`, the single source of
truth the tenant-predicate guard consults. See
[TENANT_ISOLATION](TENANT_ISOLATION.md).

> The cleanup behaviour is verified end-to-end (delete expired / retain
> non-expired / report count) on a real SQL engine — SQLite locally and
> PostgreSQL in CI — by `tests/Commands/RevokedTokensCleanupCommandTest.php`, and
> the supporting indexes + UNIQUE `jti` constraint are pinned against regression
> by `tests/Database/MigrationSchemaTest.php`.

### Form Upload Sweep

**Command**: `php /var/www/whity/public/index.php form-uploads:sweep [--ttl=86400] [--limit=500]`

**Purpose**: Deletes form attachments that nobody ever submitted — both the
`form_uploads` row and the object in storage.

**Why**: a `file` answer's bytes are written **before** the submission exists.
They have to be: a person attaches the file while they are still filling the form
in. So every abandoned form — a closed tab, a passed deadline, a wrong file
picked and replaced — leaves an object in a tenant's storage that no row will
ever reference. On a form opened to the public, the party abandoning the most
forms is a stranger, so "just accept the cost" is a bill with an
attacker-controlled magnitude. This job is the other half of the upload feature,
not an optimisation.

**Recommended Schedule**: `30 3 * * * php /var/www/whity/public/index.php form-uploads:sweep`

**Expected Output**:
`Swept {n} unclaimed form uploads (TTL 86400s); {m} objects could not be deleted`

**Retention policy**: an upload is deleted when `claimed_at IS NULL` **and**
`created_at` is older than the TTL (24 hours by default). A row in that state is
unreachable by construction: the only path that could ever reference it is
`FormUploadRepository::claim()`, which refuses an already-claimed row, and a
submission claims its uploads inside the same transaction that writes the
`document_artifacts` row. So a swept upload can never be one a document depends
on. The TTL is generous on purpose — it has to outlast somebody who attaches a
paper, goes to find the co-author list, and comes back after lunch.

**Read the second number.** `objects could not be deleted` counts files whose
row was removed but whose bytes the storage backend refused to delete. Those
bytes are now costing money and **no later sweep will find them**, because the
row that named them is gone. A non-zero value there is a storage-backend problem
to investigate, not noise. The exit code stays 0 so a scheduler does not alert on
every run; the number is the signal.

**Ordering is deliberate**: rows are deleted first, objects second. The reverse
would leave a claimable row pointing at bytes that are gone — a
`document_artifacts` row minted over an empty address, reporting success and
404ing the first time anybody opens the evidence. A leaked object costs money; a
claimable row with no bytes costs the truth of the record.

**It builds the same storage driver the uploads used** — the per-tenant routing
driver, not the platform default. A sweep holding only the default would delete
the rows of an entitled tenant's uploads and then fail to find the objects in
that tenant's own bucket, reporting a successful sweep while storage kept
growing.

## How it is scheduled

The cleanup is **genuinely wired into the running stack**, not just documented:

- **Dev / demo (`docker-compose.yml`)**: a dedicated `cron` service runs the
  command on a daily loop. It is an opt-in profile (kept out of the default
  stack so `docker compose up --wait` does not gate readiness on a long-running
  maintenance loop). Start it with `docker compose --profile cron up -d` and
  inspect it with `docker compose --profile cron logs cron`.
- **Staging / production**: schedule the SAME command via the host crontab (or
  the orchestrator's scheduler — Kubernetes `CronJob`, systemd timer, etc.)
  using the crontab entry below. The `docker-compose.staging.yml` stack expects
  the deploy environment to register this schedule.

The manual crontab setup below remains valid for any host-cron deployment.

## Setup Instructions

### 1. Verify Command Works

Before adding to cron, test the command manually:

```bash
php /var/www/whity/public/index.php revoked-tokens:cleanup
```

You should see output like: `Cleaned 5 expired revocation entries`

### 2. Add to Crontab

Edit the root crontab (or appropriate user):

```bash
crontab -e
```

Add the revoked tokens cleanup job:

```cron
# Whity Core Maintenance Tasks

# Clean expired revoked tokens daily at 2:00 AM UTC
0 2 * * * php /var/www/whity/public/index.php revoked-tokens:cleanup >> /var/log/whity-cleanup.log 2>&1

# Delete form attachments nobody ever submitted, daily at 3:30 AM UTC
30 3 * * * php /var/www/whity/public/index.php form-uploads:sweep >> /var/log/whity-cleanup.log 2>&1
```

### 3. Verify Cron Setup

Check that the job is registered:

```bash
crontab -l
```

Monitor the logs:

```bash
tail -f /var/log/whity-cleanup.log
```

## Monitoring and Troubleshooting

### Cron Job Not Running?

1. Verify cron daemon is running: `systemctl status cron`
2. Check crontab syntax: `crontab -l`
3. Check system logs: `journalctl -u cron`
4. Verify PHP path: `which php`
5. Verify file permissions: `ls -la /var/www/whity/public/index.php`

### Cleanup Deleting Too Many Rows?

If the cleanup is removing many rows at once:
- This is normal if tokens have been accumulating
- Expired entries are safe to delete
- Cleanup will be faster in future runs as the backlog is cleared
- No user impact as the tokens are already expired and unusable

### Database Connection Issues?

If the command fails with database errors:
1. Verify `.env` file has correct database credentials
2. Ensure database server is running
3. Check database user has DELETE permission on revoked_tokens table
4. Test database connection: `php /var/www/whity/public/index.php migrate status`

## Future Cron Jobs

Additional maintenance commands may be added in future releases. Check the CLI help for available commands:

```bash
php /var/www/whity/public/index.php
```
