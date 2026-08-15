#!/bin/sh
#
# Start the Next.js web tier, rebuilding first when the built bundle does not
# match the checked-out commit (WHIT-587).
#
# THE BUG THIS REPLACES
#
# A deployment's web start command used to be, in effect:
#
#     [ -f .next/BUILD_ID ] || npm run build
#     npm run start
#
# which asks "does A build exist?" — never "is it THIS build?". So a restart
# after `git checkout v0.2.0` re-served the previous bundle, silently. One real
# update did exactly that: /api/health reported the new version, the runbook
# called it a success, and the UI a user saw was 268 frontend commits behind.
#
# Since next.config.ts pins BUILD_ID to the commit, the comparison is now exact.
# When either side is unknown (no git metadata, e.g. a source tarball) the build
# is FORCED rather than skipped: rebuilding something already current costs
# minutes, while serving a stale bundle costs an outage nobody can see.
#
# Usage (from the repo root, or set WEB_DIR):
#
#     ./scripts/start-web.sh
#
# Env:
#   WEB_DIR          the Next app directory            (default: ./web)
#   WHITY_FORCE_BUILD=1  rebuild unconditionally
#
# It is safe to run on every boot: the common case (bundle matches checkout)
# skips straight to `next start`.

set -e

WEB_DIR=${WEB_DIR:-web}
cd "$WEB_DIR"

built_id=""
if [ -f .next/BUILD_ID ]; then
    built_id=$(cat .next/BUILD_ID)
fi

head_commit=$(git rev-parse HEAD 2>/dev/null || true)

if [ "${WHITY_FORCE_BUILD:-}" = "1" ]; then
    reason="WHITY_FORCE_BUILD is set"
elif [ -z "$built_id" ]; then
    reason="no build exists yet"
elif [ -z "$head_commit" ]; then
    # No git metadata to compare against. Not knowing is not the same as
    # matching, and the failure mode of guessing "matching" is invisible.
    reason="cannot read the checked-out commit — rebuilding rather than trusting the existing bundle"
elif [ "$built_id" != "$head_commit" ]; then
    reason="bundle was built from ${built_id}, checkout is ${head_commit}"
else
    reason=""
fi

if [ -n "$reason" ]; then
    echo "[web] rebuilding: ${reason}"
    npm run build
else
    echo "[web] bundle matches the checkout (${head_commit}) — starting without a rebuild"
fi

exec npm run start
