# Core Updates

How a Whity deployment learns about and applies a new core release
(WC-172). The CHECK is automated; the APPLY is a deliberate, manual,
operator-driven runbook — no deployment self-mutates.

## Versioning

- `Whity\Core\CoreVersion::VERSION` is the single source of truth
  (plain `MAJOR.MINOR.PATCH`).
- `GET /api/health` reports it as `version`, so any deployment's running
  version is readable remotely.
- Releases are git tags `v<VERSION>` on `main`. The release workflow
  (`.github/workflows/release.yml`) **refuses** a tag that does not match
  `CoreVersion::VERSION`, re-runs the full backend suite on the tagged
  commit, pushes the container image to GHCR
  (`ghcr.io/<repo>:vX.Y.Z` and `:latest`), and creates the GitHub Release
  with generated notes.

## Cutting a release (maintainers)

1. In a normal PR: bump `src/Core/CoreVersion.php`, merge to `main` (CI
   green as always).
2. Tag the merge commit and push the tag:

   ```bash
   git tag v<VERSION> && git push origin v<VERSION>
   ```

3. The Release workflow does the rest. If the verify job fails on the
   tag/version mismatch, the tag was cut against the wrong commit — delete
   the tag, fix, re-tag.

## Checking for updates (operators)

```bash
php public/index.php update:check
```

- Compares the running `CoreVersion` against the latest GitHub release of
  the canonical repo (override the release stream with
  `WHITY_UPDATE_REPO=owner/name` in the environment — forks/mirrors).
- Exit codes are script-friendly: `0` up to date (or running ahead of the
  latest release), `1` update available, `2` check failed (network/rate
  limit — the command degrades gracefully, never a stack trace). A cron
  wrapper alerting on exit 1 is the cheapest "update notifier".

## Applying an update (operators) — the manual runbook

For a compose-based deployment (the dev/KeyHub/Elmak-style anatomy in
[Plugin-Distribution.md](./Plugin-Distribution.md)):

1. **Back up the database** first; migrations are non-destructive by
   policy, but policy is not a backup:

   ```bash
   docker exec <app>_postgres pg_dump -U whity whity_core > backup-$(date +%F).sql
   ```

2. **Fetch and check out the release** in the deployment checkout:

   ```bash
   git fetch --tags origin
   git checkout v<VERSION>
   composer install --no-dev   # if the release changed dependencies
   ```

   (Container-image deployments instead pull
   `ghcr.io/<repo>:v<VERSION>` and update their compose/image reference.)

3. **Run migrations** (also applies any new migrations from installed
   plugins): `php public/index.php migrate run` — in compose,
   `docker exec <app>_frankenphp php public/index.php migrate run`.

4. **Regenerate the deployment's OpenAPI spec** (core route changes AND
   installed plugins): `php public/index.php generate:openapi`.

5. **Rebuild/restart the workers** so the new code serves:

   ```bash
   docker compose -p <app> up -d --build --force-recreate frankenphp
   ```

   (`--build` matters when the Dockerfile or PHP dependencies changed.)

6. **Verify**: `curl http://<host>/api/health` must report the new
   `version` with `status: ok`, and `update:check` reports up to date.

## Rolling back

Check out the previous tag and restart (step 5). Migrations are
backward-compatible and non-destructive by project policy, so the previous
code runs against the newer schema; rolling the schema itself back uses
`migrate rollback` (mind the global-LIFO caveat documented in
[Plugin-Distribution.md](./Plugin-Distribution.md)) or the step-1 backup.
