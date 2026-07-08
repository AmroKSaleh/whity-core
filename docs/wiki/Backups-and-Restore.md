# Database backups & restore (WC-d)

Automated, encrypted, retained PostgreSQL backups for a sovereign deployment,
plus a rehearsed restore procedure. The scripts are **activate-by-config**: they
do nothing until you set the environment below.

## Backup — `scripts/backup-db.sh`

Runs **on the deployment host** (where the DB lives), scheduled by the host's
cron / the compose `cron` service — *not* in CI (GitHub can't reach a sovereign
DB). It runs `pg_dump | gzip`, encrypts the result at rest (AES-256), optionally
uploads to S3-compatible storage, prunes old local copies, and writes
`${BACKUP_DIR}/.last-success` for a freshness metric/alert.

| Env | Purpose | Default |
|---|---|---|
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASSWORD` | source database (same as the app) | host/5432/whity_core |
| `BACKUP_ENCRYPTION_KEY` | AES-256 passphrase — **set this** (≥32 chars) | *(unset ⇒ NOT encrypted, warns)* |
| `BACKUP_DIR` | local staging dir | `/var/backups/whity` |
| `BACKUP_RETENTION_DAYS` | local prune horizon | `14` |
| `BACKUP_S3_BUCKET` | upload target (needs the `aws` CLI) | *(unset ⇒ local only)* |
| `BACKUP_S3_ENDPOINT` | endpoint for S3-compatible stores (MinIO, R2, …) | *(AWS default)* |

**Schedule it** (host crontab example — nightly at 02:30):

```cron
30 2 * * *  cd /opt/whity && BACKUP_ENCRYPTION_KEY=… BACKUP_S3_BUCKET=… \
            DB_USER=… DB_PASSWORD=… bash scripts/backup-db.sh >> /var/log/whity-backup.log 2>&1
```

**Alert on staleness**: page if `${BACKUP_DIR}/.last-success` is older than a day
(or if the cron exit code is non-zero — the script exits non-zero on any failure).

## Restore — `scripts/restore-db.sh <backup-file>`

Restores a backup into the database named by `DB_NAME` — point that at a **clean
restore target / standby**, never blindly at production. Decrypts `.enc` backups
with `BACKUP_ENCRYPTION_KEY`. After it returns, run migrations and verify:

```sh
# pull from S3 first if that is where the backup lives, then:
DB_NAME=whity_restore BACKUP_ENCRYPTION_KEY=… bash scripts/restore-db.sh whity_….sql.gz.enc
DB_NAME=whity_restore php public/index.php migrate run     # must be a no-op
DB_NAME=whity_restore php public/index.php migrate status  # must be clean
```

## Rehearsed drill (RTO/RPO)

`.github/workflows/backup-restore-drill.yml` runs the real backup→restore
round-trip (encryption on) against a throwaway PostgreSQL weekly, on demand, and
on any PR that touches the scripts — asserting the restored copy has the seeded
data and is migration-consistent, and printing the restore duration (RTO).

- **RPO** is set by the backup *cadence* (nightly cron ⇒ ≤24h). Tighten by
  scheduling more frequently and/or enabling WAL archiving (future work).
- **RTO** is the restore duration the drill reports, plus your host's provision +
  DNS cutover time. Re-run the drill after any schema/scale change.

Practice a real restore-to-clean-stack (download from S3 → restore → migrate →
smoke `/api/health`) before relying on it in an incident.
