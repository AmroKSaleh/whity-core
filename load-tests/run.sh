#!/bin/bash
#
# WC-32 load-test runner. Wraps the Grafana k6 Docker image so there is zero
# repository dependency (no k6/Locust in composer.json or package.json).
#
# It runs load-tests/smoke.js inside a k6 container attached to the staging
# Docker network, so the script reaches the app at http://frankenphp:80.
#
# Usage:
#   ./load-tests/run.sh                       # defaults: 10 VUs, 30s
#   VUS=50 DURATION=1m ./load-tests/run.sh
#   NETWORK=whity-staging_default ADMIN_PASSWORD=secret ./load-tests/run.sh
#
# Env knobs:
#   NETWORK         docker network to join     (default whity-staging_default)
#   BASE_URL        in-network target URL       (default http://frankenphp:80)
#   ADMIN_EMAIL     seeded admin login          (default admin@example.com)
#   ADMIN_PASSWORD  seeded admin password       (default staging_admin_pw_change_me)
#   VUS             virtual users               (default 10)
#   DURATION        test duration               (default 30s)
#   SCRIPT          k6 script to run            (default load-tests/smoke.js)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

NETWORK=${NETWORK:-whity-staging_default}
BASE_URL=${BASE_URL:-http://frankenphp:80}
ADMIN_EMAIL=${ADMIN_EMAIL:-admin@example.com}
ADMIN_PASSWORD=${ADMIN_PASSWORD:-staging_admin_pw_change_me}
VUS=${VUS:-10}
DURATION=${DURATION:-30s}
SCRIPT=${SCRIPT:-"${SCRIPT_DIR}/smoke.js"}

echo ">> k6 load test"
echo "   network=${NETWORK} base_url=${BASE_URL} vus=${VUS} duration=${DURATION}"
echo "   script=${SCRIPT}"
echo

# `-i` streams the script in over stdin so nothing needs mounting.
docker run --rm -i \
  --network="${NETWORK}" \
  -e BASE_URL="${BASE_URL}" \
  -e ADMIN_EMAIL="${ADMIN_EMAIL}" \
  -e ADMIN_PASSWORD="${ADMIN_PASSWORD}" \
  -e VUS="${VUS}" \
  -e DURATION="${DURATION}" \
  grafana/k6 run - < "${SCRIPT}"
