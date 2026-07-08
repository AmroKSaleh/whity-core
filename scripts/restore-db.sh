#!/usr/bin/env bash
#
# WC-d: restore a PostgreSQL backup produced by backup-db.sh.
#
# Usage: restore-db.sh <backup-file>
#   <backup-file>  a local path to a *.sql.gz (plain) or *.sql.gz.enc (encrypted)
#                  backup. Pull it from S3 first if that is where it lives.
#
# Restores INTO the database named by DB_NAME — point that at a RESTORE TARGET
# (a clean/standby DB), never blindly at production. The caller is responsible
# for the target existing and being empty. After this returns, run
# `php public/index.php migrate run` and verify (the restore drill does both).
#
#   DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD   (the restore TARGET)
#   BACKUP_ENCRYPTION_KEY                         (required iff the file is .enc)
set -euo pipefail

src="${1:?usage: restore-db.sh <backup-file>}"
[ -f "$src" ] || { echo "[restore] backup file not found: ${src}" >&2; exit 1; }

DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:?DB_NAME (restore target) is required}"
DB_USER="${DB_USER:?DB_USER is required}"
export PGPASSWORD="${DB_PASSWORD:?DB_PASSWORD is required}"
ENC_KEY="${BACKUP_ENCRYPTION_KEY:-}"

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

case "$src" in
    *.enc)
        [ -n "$ENC_KEY" ] || { echo "[restore] ${src} is encrypted but BACKUP_ENCRYPTION_KEY is unset" >&2; exit 1; }
        echo "[restore] decrypting + decompressing ${src}"
        openssl enc -d -aes-256-cbc -pbkdf2 -in "$src" -pass "pass:${ENC_KEY}" | gunzip > "$tmp"
        ;;
    *.gz)
        echo "[restore] decompressing ${src}"
        gunzip -c "$src" > "$tmp"
        ;;
    *)
        cp "$src" "$tmp"
        ;;
esac

echo "[restore] restoring into ${DB_NAME}@${DB_HOST}:${DB_PORT}"
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 -q < "$tmp"
echo "[restore] done. Next: run migrations and verify."
