# Core Updates

How a Whity deployment learns about and applies a new core release
(WC-172). The CHECK is automated; the APPLY is a deliberate, manual,
operator-driven runbook — no deployment self-mutates.

## Versioning

- `Whity\Core\CoreVersion::VERSION` is the single source of truth
  (plain `MAJOR.MINOR.PATCH`).
- `GET /api/health` reports it as `version`, so any deployment's running
  version is readable remotely.
- A deployment has THREE versions, and they can disagree:

  | What | Where to read it | Note |
  | --- | --- | --- |
  | Core (backend) | `GET /api/health` → `version` | unauthenticated |
  | Plugin SDK contract | `GET /api/v1/platform/version` → `sdk_version` | system-tenant admin |
  | Frontend bundle | `GET /web-build` → `core_version` | unauthenticated, served by the WEB tier |

  `/web-build` is answered by the Next.js service, not the backend, because
  only the process that loaded a bundle knows which bundle it loaded (an
  image deployment's backend has no `.next` at all). **`/web-build`'s
  `core_version` and `/api/health`'s `version` must match.** They are the
  two halves of one deployment; when they differ, the UI is stale even
  though the API upgraded — see the verification step in the runbook below.
- Releases are git tags `v<VERSION>` on `main`. The release workflow
  (`.github/workflows/release.yml`) **refuses** a tag that does not match
  `CoreVersion::VERSION`, re-runs the full backend suite on the tagged
  commit, pushes **both** container images to GHCR under the same tags —
  the API `ghcr.io/<repo>` and the UI `ghcr.io/<repo>/web`, each as
  `:vX.Y.Z` and `:latest` — and creates the GitHub Release with generated
  notes.
- The two images are one release: they are built by the same gated job and
  carry identical tags, so a deployment upgrades them as a pair. Running a
  `v0.3.0` API against a `v0.2.0` UI is not a supported configuration.

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

The same check is available over HTTP, for operators who have no shell on
their own deployment (`settings:manage` in the SYSTEM tenant — version state
describes the whole deployment, so it is not a tenant admin's to read):

```bash
GET /api/v1/platform/version         # core_version, sdk_version, php_version — local, no network
GET /api/v1/platform/version/latest  # the same comparison update:check runs
```

`/latest` answers `200` for every verdict it can reach, including
`status: "check_failed"` — "could not tell" is information, and an HTTP error
would be read as "up to date" by anything naive. Statuses are
`up_to_date`, `update_available`, `ahead`, `no_releases`, `check_failed`;
`update_available` (boolean) is the yes/no if you do not want to hard-code
the vocabulary.

Both routes are READ-ONLY. There is deliberately no "apply" button: applying
means fetch + checkout + migrate + rebuild for a source deployment and
something entirely different for an image deployment (whose running container
cannot replace its own immutable image). The apply step stays the runbook
below.

## Applying an update (operators) — the manual runbook

For a compose-based deployment (the per-product deployment anatomy in
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

   (Container-image deployments instead pull `ghcr.io/<repo>:v<VERSION>`
   **and** `ghcr.io/<repo>/web:v<VERSION>` and update both compose/image
   references — the UI ships as its own image.)

3. **Run migrations** (also applies any new migrations from installed
   plugins): `php public/index.php migrate run` — in compose,
   `docker exec <app>_frankenphp php public/index.php migrate run`.

4. **Regenerate the deployment's OpenAPI spec** (core route changes AND
   installed plugins): `php public/index.php generate:openapi`.

5. **Rebuild the frontend bundle.** This step is not optional and is not
   implied by a restart. `next start` serves whatever is already in
   `web/.next`; a release that touched `web/` or `packages/` ships nothing
   to users until the bundle is rebuilt:

   ```bash
   npm ci                              # in the deployment checkout, only if
                                       # package-lock.json changed (root
                                       # install — this is an npm workspace)
   docker compose -p <app> restart web
   ```

   provided the web service's command is `./scripts/start-web.sh`, which
   rebuilds when the built commit differs from the checked-out one. A start
   command that builds only when `.next/BUILD_ID` is ABSENT is the bug this
   step exists for — it asks "does a build exist?", never "is it THIS
   build?", so a restart after a checkout silently re-serves the old bundle.
   To force a build regardless: `WHITY_FORCE_BUILD=1`.

   If you need to clear `.next` by hand and it is a NAMED VOLUME, remove its
   CONTENTS, never the directory:

   ```bash
   docker compose -p <app> run --rm web sh -c 'rm -rf .next/* .next/.[!.]*'
   ```

   `rm -rf .next` fails with "Device or resource busy" even in a fresh
   one-off container — the volume is mounted AT that path, so the directory
   itself cannot be unlinked.

6. **Rebuild/restart the workers** so the new code serves:

   ```bash
   docker compose -p <app> up -d --build --force-recreate frankenphp
   ```

   (`--build` matters when the Dockerfile or PHP dependencies changed.)

7. **Verify BOTH tiers.** `/api/health` alone is not a success criterion —
   it reports the backend only, and has passed while the UI was hundreds of
   commits stale:

   ```bash
   curl -s http://<host>/api/health   | jq '{status, version}'
   curl -s http://<host>/web-build    | jq '{core_version, commit, built_at}'
   ```

   - `status` is `ok` and `version` is the new version;
   - `/web-build`'s `core_version` **equals** `/api/health`'s `version`;
   - `commit` is the commit you checked out, and `built_at` is from this
     update, not the previous one;
   - `update:check` (or `GET /api/v1/platform/version/latest`) reports up
     to date.

   A `core_version` that lags `version` means the frontend was not rebuilt —
   go back to step 5. Worth wiring into monitoring: comparing those two
   fields is the cheapest alarm for a half-applied update.

## Rolling back

Check out the previous tag and restart (steps 5–6). Migrations are
backward-compatible and non-destructive by project policy, so the previous
code runs against the newer schema; rolling the schema itself back uses
`migrate rollback` (mind the global-LIFO caveat documented in
[Plugin-Distribution.md](./Plugin-Distribution.md)) or the step-1 backup.
