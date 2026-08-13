#!/usr/bin/env bash
# Builds a curated static FrankenPHP Linux binary (only pdo_sqlite, sqlite3,
# mbstring compiled in — this app needs nothing else) via Docker, and stages
# it under src-tauri/resources/frankenphp-linux/. Unlike the Windows recipe
# (setup-php-runtime.ps1, which downloads a prebuilt dynamically-linked
# release), this one COMPILES PHP from source using FrankenPHP's own
# static-php-cli-based build tooling — there is no prebuilt curated-extension
# release to just download for Linux.
#
# Prerequisites: Docker with Linux container support (Docker Desktop's
# "desktop-linux" context, or native Docker on Linux), and a GITHUB_TOKEN in
# the environment (the build's own source-fetch step needs it to avoid
# GitHub API rate limits — `gh auth token` works if you have the GitHub CLI
# authenticated).
#
# VERIFIED WORKING END TO END (not just documented): built this exact recipe,
# ran the resulting binary against this template's real php-host/ app inside
# a bare `alpine` container (proving true static self-containment — no other
# packages installed), and confirmed DemoCatalog CRUD + a clean PrintDemo 503
# (no native bridge in that test) both work identically to the Windows build.
#
# The build surfaced a REAL bug (not hypothetical): running with FrankenPHP's
# default one-worker-per-CPU-core pool, every worker independently runs
# MigrationRunner on first boot, and concurrent migration attempts raced —
# first as a "UNIQUE constraint failed" crash (now handled gracefully in
# MigrationRunner.php), then as SQLite "database is locked" errors even after
# that fix (SQLite still serializes writers even in WAL mode). The real fix,
# already applied, is pinning FrankenPHP to exactly one worker
# (`--worker <path>,1`, see src-tauri/src/php_host/sidecar.rs) — a single
# desktop user never needs more than one anyway. This script's own
# instructions below don't need to pin worker count themselves since nothing
# here runs the binary long-term; whatever spawns it for real must.

set -euo pipefail

FRANKENPHP_VERSION="1.12.7"
PHP_EXTENSIONS="pdo_sqlite,sqlite3,mbstring"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="$(mktemp -d)"
DEST="${REPO_ROOT}/src-tauri/resources/frankenphp-linux"

if [ -z "${GITHUB_TOKEN:-}" ]; then
	if command -v gh >/dev/null 2>&1; then
		GITHUB_TOKEN="$(gh auth token)"
	else
		echo "GITHUB_TOKEN is not set and the gh CLI is not available to derive one." >&2
		echo "Set GITHUB_TOKEN (a plain GitHub PAT is fine — no special scopes needed)." >&2
		exit 1
	fi
fi
export GITHUB_TOKEN

echo "Cloning FrankenPHP v${FRANKENPHP_VERSION}..."
# Clone the exact TAG shallowly (not the default branch) — build-static.sh
# does its own `git checkout` internally (checking out "v<version>" when
# FRANKENPHP_VERSION looks like a dotted version number), which only
# succeeds if that ref already exists locally. A plain `--depth 1` clone of
# the default branch does NOT include this tag and fails with
# "pathspec 'v1.12.7' did not match any file(s)" — confirmed live.
git clone --depth 1 --branch "v${FRANKENPHP_VERSION}" https://github.com/php/frankenphp.git "${BUILD_DIR}"

echo "Building (this compiles PHP from source — expect 10-15+ minutes, longer on a cold Docker build-cache)..."
(
	cd "${BUILD_DIR}"
	docker buildx bake --load \
		--set static-builder-musl.platform=linux/amd64 \
		--set static-builder-musl.args.FRANKENPHP_VERSION="${FRANKENPHP_VERSION}" \
		--set static-builder-musl.args.PHP_EXTENSIONS="${PHP_EXTENSIONS}" \
		static-builder-musl
)

echo "Extracting the built binary..."
rm -rf "${DEST}"
mkdir -p "${DEST}"
container_id="$(docker create dunglas/frankenphp:static-builder-musl)"
docker cp "${container_id}:/go/src/app/dist/frankenphp-linux-x86_64" "${DEST}/frankenphp"
docker rm "${container_id}" >/dev/null
chmod +x "${DEST}/frankenphp"

rm -rf "${BUILD_DIR}"

echo "Static FrankenPHP binary staged at ${DEST}/frankenphp ($(du -h "${DEST}/frankenphp" | cut -f1))"
echo "Fully self-contained (verified against a bare 'alpine' container with no other packages) —"
echo "unlike Windows, no accompanying DLLs or php.ini are needed at all."
echo
echo "To reclaim disk space, the ~3.4GB build image can be removed with:"
echo "  docker rmi dunglas/frankenphp:static-builder-musl"
