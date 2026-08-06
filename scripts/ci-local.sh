#!/usr/bin/env bash
#
# Run the GitHub-Actions PHP jobs locally, in Docker, step for step.
#
# There is no `php` on the Windows host, so the only way to check a backend
# change before pushing has been to open a PR and wait ~20 minutes for
# "Unit, static analysis & plugin smoke (SQLite)" to fail. This runs the same
# commands, in the same order, in a Linux container.
#
#   scripts/ci-local.sh unit     # the SQLite job  (phpunit+coverage, phpstan, guards)
#   scripts/ci-local.sh pg       # the Postgres job (migrate/seed, Integration, Security)
#   scripts/ci-local.sh all      # both, unit first
#   scripts/ci-local.sh phpstan  # just PHPStan — the usual culprit, ~seconds warm
#   scripts/ci-local.sh shell    # a shell in the runner
#
# Flags:
#   --clean   run against a pristine `git archive` of HEAD instead of the
#             working copy. Use this before pushing: the main checkout carries
#             untracked, gitignored files (a local plugins/PluginStore/) that
#             PHPStan analyses and CI never sees, producing ~16 phantom errors.
#   --fresh   rebuild the runner image and discard the vendor/phpstan volumes.
#
# Every step mirrors .github/workflows/automated-tests.yml. When that workflow
# changes, change this too — a local runner that has drifted from CI is worse
# than none, because it produces confident false greens.
set -euo pipefail

# Docker Desktop wants a Windows path (C:/x); Git Bash hands us an MSYS one
# (/c/x). Every path handed to `docker` — the -f compose file included — has to
# be converted, or the daemon resolves it somewhere else entirely.
to_host_path() {
  if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else printf '%s' "$1"; fi
}

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$HERE/.." && pwd)"
COMPOSE_FILE="$(to_host_path "$REPO_ROOT/docker/ci-local/docker-compose.yml")"

# All docker calls go through this. The MSYS_* vars are set PER CALL, never
# exported: globally they also stop Git Bash converting git's own arguments
# (`git -C /tmp/x` then reaches git.exe as a path it cannot resolve). Here they
# stop it mangling container-side paths (`-e FOO=/app/x` would otherwise become
# `-e FOO=C:/Program Files/Git/app/x`).
dc() {
  MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' docker compose -f "$COMPOSE_FILE" "$@"
}

JOB=""
USE_CLEAN=0
USE_FRESH=0
CLEAN_WORKTREE=""

for arg in "$@"; do
  case "$arg" in
    unit|pg|all|phpstan|shell) JOB="$arg" ;;
    --clean) USE_CLEAN=1 ;;
    --fresh) USE_FRESH=1 ;;
    *) echo "unknown argument: $arg" >&2; exit 2 ;;
  esac
done
[ -n "$JOB" ] || { sed -n '3,28p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 2; }

log()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31m✗ %s\033[0m\n' "$*"; exit 1; }

cleanup() {
  local code=$?
  dc down --remove-orphans >/dev/null 2>&1 || true
  # Never leave the CI .env or a half-written spec behind in a real checkout.
  if [ -n "${CI_LOCAL_SRC:-}" ] && [ "$USE_CLEAN" -eq 0 ]; then
    rm -f "$CI_LOCAL_SRC/.env.ci-local" "$CI_LOCAL_SRC/public/openapi.json.ci-local-orig"
  fi
  if [ -n "$CLEAN_WORKTREE" ] && [ -d "$CLEAN_WORKTREE" ]; then
    rm -rf "$(dirname "$CLEAN_WORKTREE")" 2>/dev/null || true
  fi
  exit $code
}
trap cleanup EXIT

# --- what the container sees as /app -----------------------------------------
if [ "$USE_CLEAN" -eq 1 ]; then
  CLEAN_WORKTREE="$(mktemp -d)/ci-local-src"
  mkdir -p "$CLEAN_WORKTREE"
  log "Exporting a pristine tree of HEAD (tracked files only)"
  # `git archive`, not `git worktree add`: an archive is exactly the tracked
  # tree CI checks out — no untracked files, and no .git pointing at a host
  # path the container cannot see.
  #
  # core.autocrlf=false is load-bearing on Windows. This repo has no
  # .gitattributes and the global setting is autocrlf=true, so a plain archive
  # rewrites every LF blob to CRLF. The ubuntu runner checks out LF, and PHP
  # writes LF, so a CRLF tree makes the OpenAPI drift check report a stale spec
  # on a perfectly clean checkout.
  git -C "$REPO_ROOT" -c core.autocrlf=false -c core.eol=lf archive HEAD \
    | tar -x -C "$CLEAN_WORKTREE"
  CI_LOCAL_SRC="$CLEAN_WORKTREE"
else
  CI_LOCAL_SRC="$REPO_ROOT"
fi
# CI_LOCAL_REPO is consumed by docker-compose.yml as the /app bind source, so
# it must be a path the Docker daemon understands.
export CI_LOCAL_REPO="$(to_host_path "$CI_LOCAL_SRC")"

if [ "$USE_FRESH" -eq 1 ]; then
  log "Discarding cached volumes and rebuilding the runner image"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  dc build --no-cache ci
fi

# Run a command in the runner. Extra env is passed as KEY=VALUE arguments
# BEFORE the command, mirroring a step's `env:` block.
run_in_ci() {
  local envs=()
  while [[ "${1:-}" == *=* ]]; do envs+=(-e "$1"); shift; done
  dc run --rm --no-deps "${envs[@]}" ci "$@"
}

run_in_ci_with_pg() {
  local envs=()
  while [[ "${1:-}" == *=* ]]; do envs+=(-e "$1"); shift; done
  dc run --rm "${envs[@]}" ci "$@"
}

install_deps() {
  log "Install Dependencies (composer install)"
  run_in_ci composer install --prefer-dist --no-progress --no-interaction
}

# ---------------------------------------------------------------------------
# Job: Unit, static analysis & plugin smoke (SQLite)
# ---------------------------------------------------------------------------
job_unit() {
  install_deps

  log "Composer security audit"
  run_in_ci composer audit --no-interaction --abandoned=report

  # NOTE: no PHPUNIT_PG_DSN here, deliberately. SchemaFromMigrations::make()
  # switches to a real Postgres schema when that variable is set, which is the
  # postgres job's path — leaking it into this job would silently stop
  # exercising the SQLite path CI actually runs here.
  log "Run PHPUnit with coverage"
  run_in_ci vendor/bin/phpunit --coverage-clover coverage.xml --coverage-text

  log "Enforce coverage floor"
  run_in_ci php scripts/coverage-check.php coverage.xml

  # CI does `generate:openapi` then `git diff --exit-code`. Git is not usable
  # inside the container (in a worktree, /app/.git is a FILE pointing at a host
  # path that is not mounted), so compare the file against a copy taken before
  # regenerating — same assertion, no git.
  log "OpenAPI spec drift check"
  #
  # The comparison ignores CR: a Windows working copy legitimately holds the
  # spec with CRLF (core.autocrlf=true) while the generator always writes LF,
  # and CI — which only ever sees LF — would not flag that. PHPUnit's
  # AdminSchemasTest normalises the same way for the same reason.
  cp "$CI_LOCAL_SRC/public/openapi.json" "$CI_LOCAL_SRC/public/openapi.json.ci-local-orig"
  run_in_ci php public/index.php generate:openapi
  if ! diff -q <(tr -d '\r' < "$CI_LOCAL_SRC/public/openapi.json.ci-local-orig") \
               <(tr -d '\r' < "$CI_LOCAL_SRC/public/openapi.json") >/dev/null; then
    mv "$CI_LOCAL_SRC/public/openapi.json.ci-local-orig" "$CI_LOCAL_SRC/public/openapi.json"
    fail "public/openapi.json is stale — run generate:openapi, commit it, and regenerate web/lib/api/schema.d.ts (cd web && npm run generate:api)"
  fi
  rm -f "$CI_LOCAL_SRC/public/openapi.json.ci-local-orig"

  job_phpstan

  log "Plugin-load smoke"
  run_in_ci php scripts/ci-plugin-smoke.php

  log "Tenant-predicate guard"
  run_in_ci php scripts/ci-tenant-predicate-guard.php

  log "Plugin tenant-isolation conformance (HelloWorld)"
  run_in_ci php scripts/ci-plugin-tenant-conformance.php
}

job_phpstan() {
  log "Run PHPStan (src tests plugins sdk)"
  if [ "$USE_CLEAN" -eq 0 ]; then
    printf '\033[0;33m    note: running against the working copy; untracked files are analysed too.\n'
    printf '          Use --clean for a CI-faithful result.\033[0m\n'
  fi
  run_in_ci vendor/bin/phpstan analyse src tests plugins sdk --memory-limit=512M
}

# ---------------------------------------------------------------------------
# Job: Migrations + Integration + Security on real PostgreSQL
# ---------------------------------------------------------------------------
PG_ENV=(
  "PHPUNIT_PG_DSN=pgsql:host=pg;port=5432;dbname=whity_core"
  "PHPUNIT_PG_USER=whity"
  "PHPUNIT_PG_PASSWORD=whity_dev"
  "JWT_SECRET=ci_jwt_secret_min_32_chars_for_hs256_aaaa"
  "ENCRYPTION_KEY=ci_encryption_key_min_32_chars_bbbbbbbb"
)

job_pg() {
  install_deps

  # The workflow writes a .env for the CLI bootstrap. Write it inside the
  # container only — dropping a .env into the host checkout would clobber a
  # developer's real one. DB_HOST is the compose service name, not localhost.
  log "Write .env for the CLI bootstrap (container-local)"
  local env_file='APP_ENV=development
DB_HOST=pg
DB_PORT=5432
DB_NAME=whity_core
DB_USER=whity
DB_PASSWORD=whity_dev
JWT_SECRET=ci_jwt_secret_min_32_chars_for_hs256_aaaa
ENCRYPTION_KEY=ci_encryption_key_min_32_chars_bbbbbbbb
INITIAL_ADMIN_PASSWORD=admin123
INITIAL_USER_PASSWORD=user123
INITIAL_SYSTEM_ADMIN_PASSWORD=systemadmin123
INITIAL_SUPERUSER_PASSWORD=superuser123'

  # /app is a bind mount, so write .env to a tmpfs path and symlink? No —
  # public/index.php reads /app/.env. Write it, then remove it in cleanup.
  # Guard against destroying an existing developer .env.
  if [ -e "$CI_LOCAL_SRC/.env" ] && [ "$USE_CLEAN" -eq 0 ]; then
    fail "$CI_LOCAL_SRC/.env already exists — re-run with --clean so the CI .env cannot overwrite yours"
  fi
  printf '%s\n' "$env_file" > "$CI_LOCAL_SRC/.env"

  log "Run migrations on real PostgreSQL"
  run_in_ci_with_pg "${PG_ENV[@]}" php public/index.php migrate run

  log "Seed on real PostgreSQL"
  run_in_ci_with_pg "${PG_ENV[@]}" php public/index.php seed

  log "Re-run migrations (idempotency on PostgreSQL)"
  run_in_ci_with_pg "${PG_ENV[@]}" php public/index.php migrate run

  log "Run Integration suite on real PostgreSQL"
  run_in_ci_with_pg "${PG_ENV[@]}" vendor/bin/phpunit --testsuite Integration

  log "Run Security suite on real PostgreSQL"
  run_in_ci_with_pg "${PG_ENV[@]}" vendor/bin/phpunit --testsuite Security

  rm -f "$CI_LOCAL_SRC/.env"
}

case "$JOB" in
  unit)    job_unit ;;
  pg)      job_pg ;;
  all)     job_unit; job_pg ;;
  phpstan) install_deps; job_phpstan ;;
  shell)   install_deps; run_in_ci_with_pg "${PG_ENV[@]}" bash ;;
esac

log "✓ ${JOB} passed"
