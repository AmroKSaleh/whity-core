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
