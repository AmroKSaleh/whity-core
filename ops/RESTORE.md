# Restoring the database

**Last drilled: 2026-09-11. Restore took 239 seconds from a cold container.**

A backup nobody has restored is a hypothesis. This procedure has been executed
end to end and the numbers below are measured, not estimated — so during an
incident you are following something that has worked rather than something that
looked reasonable when it was written.

## What the drill proved

A backup taken at 13:13 restored into an empty PostgreSQL container and matched
production exactly:

| | Restored | Live | |
|---|---|---|---|
| tables | 171 | 171 | ✅ |
| tenants | 4 | 4 | ✅ |
| invoices | 5 | 5 | ✅ |
| documents | 15 | 15 | ✅ |
| migrations | 240 | 240 | ✅ |
| audit_log | 365 | 366 | one row written after the snapshot |

That last row is the point-in-time boundary, not data loss: exactly one audit
event was created on live after the backup ran. **A restore recovers everything
up to the moment the dump started, and nothing after it.** Plan for that gap.

Timings, cold:

- container ready: **~15s**
- restore: **~239s**
- total: **~4 minutes** for a 1.6 MB gzip / ~10 MB SQL

## The procedure

```bash
BACKUP=/c/Projects/Whity/backups/whity_core_YYYYMMDD-HHMMSS.sql.gz

# 1. Verify the archive BEFORE trusting it.
gzip -t "$BACKUP" && echo "archive intact"

# 2. A throwaway server. No published port and no named volume, so a mistake
#    here cannot reach anything real.
docker run -d --name whity_restore_drill \
  -e POSTGRES_USER=whity -e POSTGRES_PASSWORD=drill-only -e POSTGRES_DB=whity_core \
  pgvector/pgvector:pg15

# 3. WAIT FOR A REAL QUERY, not pg_isready. See the warning below.
until docker exec whity_restore_drill psql -U whity -d whity_core -tAc 'SELECT 1' >/dev/null 2>&1
do sleep 2; done

# 4. Restore.
gzip -dc "$BACKUP" | docker exec -i whity_restore_drill psql -U whity -d whity_core

# 5. Compare against live before believing it.
docker exec whity_restore_drill psql -U whity -d whity_core -tAc "
  SELECT (SELECT count(*) FROM information_schema.tables WHERE table_schema='public')
      ||' '||(SELECT count(*) FROM tenants)
      ||' '||(SELECT count(*) FROM core_schema_migrations);"

# 6. Clean up.
docker rm -f -v whity_restore_drill
```

## Two traps this drill fell into, so you do not

**`pg_isready` lies during startup.** The official image starts Postgres, runs
initdb, *shuts it down*, and starts it again. `pg_isready` reports ready during
that first phase, so a restore fired then dies with `FATAL: the database system
is shutting down` — and psql exits before writing anything. The first run of
this drill restored **nothing** and reported success. Wait for a query that
actually returns a row.

**psql writes `psql: error:`, not `ERROR`.** A check for `^ERROR` in the output
finds nothing and declares an all-clear over a restore that wrote zero tables.
Count rows in the restored database; do not grade the log.

Both failures produced a confident success message. The only check that could
not be fooled was counting tables afterwards.

## Restoring for real

Into the live container, when the data is already gone:

```bash
docker exec whity_staging_postgres psql -U whity -d postgres -c \
  "DROP DATABASE whity_core; CREATE DATABASE whity_core OWNER whity;"
gzip -dc "$BACKUP" | docker exec -i whity_staging_postgres psql -U whity -d whity_core
docker restart whity_staging_frankenphp
```

**Take a fresh dump of the broken database first**, even if it looks empty. A
wrong diagnosis is recoverable; a wrong diagnosis plus an overwritten original
is not.

## What is not covered

The dump is `--no-owner --no-privileges` and carries schema plus data only. It
does **not** contain: the `.env.staging` secrets, uploaded files outside the
database, or the Docker volumes themselves. Losing the host means restoring
those separately — they are not in this archive, and nothing here will warn you
at the moment you assume they are.
