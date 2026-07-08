#!/usr/bin/env bash
#
# WC-f: export DB-backup freshness as a Prometheus metric.
#
# scripts/backup-db.sh writes ${BACKUP_DIR}/.last-success (a UTC timestamp line)
# after every successful backup. This turns that marker into the metric the
# WhityBackupStale / WhityBackupMetricMissing rules alert on:
#
#   whity_backup_last_success_timestamp_seconds  <unix-seconds>
#
# Run it from cron a few minutes AFTER the backup cron, writing into the
# node_exporter textfile-collector directory so node_exporter scrapes it:
#
#   */15 * * * *  BACKUP_DIR=/var/backups/whity \
#     TEXTFILE_DIR=/var/lib/node_exporter/textfile_collector \
#     bash /opt/whity/ops/alerting/backup-freshness-textfile.sh
#
# Writes atomically (temp + mv) so node_exporter never reads a half-written file.
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/whity}"
TEXTFILE_DIR="${TEXTFILE_DIR:-/var/lib/node_exporter/textfile_collector}"
marker="${BACKUP_DIR}/.last-success"
out="${TEXTFILE_DIR}/whity_backup.prom"

mkdir -p "$TEXTFILE_DIR"
tmp="$(mktemp "${out}.XXXX")"
trap 'rm -f "$tmp"' EXIT

{
    echo '# HELP whity_backup_last_success_timestamp_seconds Unix time of the last successful whity-core DB backup.'
    echo '# TYPE whity_backup_last_success_timestamp_seconds gauge'
    if [ -f "$marker" ]; then
        # The marker holds an ISO-8601 UTC stamp (e.g. 20260706T023000Z); convert
        # to epoch seconds. `date -d` handles it on GNU coreutils (Linux hosts).
        raw="$(head -n1 "$marker")"
        if epoch="$(date -u -d "$raw" +%s 2>/dev/null)"; then
            echo "whity_backup_last_success_timestamp_seconds ${epoch}"
        else
            # Fall back to the marker file's own mtime if the contents don't parse.
            echo "whity_backup_last_success_timestamp_seconds $(stat -c %Y "$marker")"
        fi
    fi
    # If the marker is absent we emit no sample, so `absent()` fires
    # WhityBackupMetricMissing rather than reporting a misleading 0.
} > "$tmp"

mv "$tmp" "$out"
trap - EXIT
