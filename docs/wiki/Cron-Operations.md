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

## How it is scheduled

The cleanup is **genuinely wired into the running stack**, not just documented:

- **Dev / demo (`docker-compose.yml`)**: a dedicated `cron` service runs the
  command on a daily loop. Inspect it with `docker compose logs cron`.
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
