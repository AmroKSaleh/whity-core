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
  | Plugin SDK contract | `GET /api/health` → `sdk_version` | unauthenticated |
  | | `GET /api/v1/platform/version` → `sdk_version` | system-tenant admin; same value |
  | Frontend bundle | `GET /web-build` → `core_version` | unauthenticated, served by the WEB tier |

  A version is not an identity, which is why there is a fourth thing to read.
  `version` is a **constant in the source**: it changes only on a release bump,
  so between releases it is the same string on every commit and it moves with
  the code whether or not the code was deployed. **`GET /api/build`**
  (unauthenticated, #1049) answers the question `version` cannot — which
  *commit* the backend is running, when its workers booted, whether the
  checkout on disk has moved underneath them, and how many migrations are
  still pending. It is the backend's counterpart to `/web-build`, named the
  same way where it means the same thing, so the two documents diff directly.

  The SDK contract version is on the PUBLIC probe deliberately: a plugin that
  stops loading after an upgrade is nearly always this number moving, and that
  is diagnosed from monitoring rather than from an admin session. See the
  disclosure note on `src/Api/HealthApiHandler.php` — it is one line to remove
  if an operator disagrees, and nothing in the product reads it from there.

  `/web-build` is answered by the Next.js service, not the backend, because
  only the process that loaded a bundle knows which bundle it loaded (an
  image deployment's backend has no `.next` at all). **`/web-build`'s
  `core_version` and `/api/health`'s `version` must match.** They are the
  two halves of one deployment; when they differ, the UI is stale even
  though the API upgraded — see the verification step in the runbook below.
- Releases are git tags `v<VERSION>` on `main`. The release workflow
  (`.github/workflows/release.yml`) **refuses** a tag that does not match
  `CoreVersion::VERSION`, re-runs the full backend suite on the tagged
  commit, pushes **all three** container images to GHCR under the same tags —
  the API `ghcr.io/<repo>`, the UI `ghcr.io/<repo>/web` and the document
  render tier `ghcr.io/<repo>/render`, each as `:vX.Y.Z` and `:latest` — and
  creates the GitHub Release with generated notes.
- The three images are one release: they are built by the same gated job and
  carry identical tags, so a deployment upgrades them as a set. Running a
  `v0.3.0` API against a `v0.2.0` UI is not a supported configuration.
- The API and UI are both required; **`/render` is optional to run** (it is
  behind a Compose profile — see
  [Document-Render-Service.md](./Document-Render-Service.md)) but is published
  unconditionally, because it is the one image an operator cannot build from
  the others: its build context spans the app repo *and* the web source.
  A deployment that runs it must run it at the tag matching the other two —
  the renderer inside it is compiled from that release's `web/` and
  `packages/` source, so an older render tier exports documents that no longer
  match the designer preview while looking perfectly healthy.
  `GET /health` on the render service reports the `core_version` it was built
  from, which is the cheapest way to catch that.

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

> ### One-time: the Postgres image changed, and an existing database needs a REINDEX
>
> The `postgres` service moved from `postgres:15-alpine` to
> `pgvector/pgvector:pg15`, so a plugin can declare a vector column for
> similarity search. Same Postgres 15, same data directory — it starts on your
> existing volume without a dump.
>
> **But it is a different libc, and that silently changes text ordering.**
> Alpine is musl; this image is Debian/glibc. On identical data in an
> `en_US.utf8` database:
>
> | | `ORDER BY` result |
> |---|---|
> | musl (old image) | `Banana, Cherry, apple, date` |
> | glibc (new image) | `apple, Banana, Cherry, date` |
>
> **Postgres does not warn**, because musl records no collation version for it
> to compare against. So every index on a text column is built to an ordering
> the server no longer uses, and queries that rely on one can return wrong rows
> while looking healthy.
>
> Run this once, after the first start on the new image:
>
> ```bash
> docker exec <app>_postgres psql -U <user> -d <db> -c 'REINDEX DATABASE <db>;'
> ```
>
> A **fresh** database is unaffected — there is nothing built under the old
> collation. Only an existing volume needs it.
>
> The extension is available, not enabled: nothing creates it for you, and a
> deployment that wants no vector columns carries none. A plugin that needs one
> runs `CREATE EXTENSION IF NOT EXISTS vector` in its own migration.

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
   references — the UI ships as its own image. Deployments running the
   optional render profile pull `ghcr.io/<repo>/render:v<VERSION>` in the
   same step; leaving it behind is a silent export-drift bug, not an
   outage.)

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

   **Which of these you need depends on how your web tier runs, and this
   repository ships no compose service that rebuilds for you** (#1138). Earlier
   revisions of this page said the rebuild was automatic "provided the web
   service's command is `./scripts/start-web.sh`" — a protection conditional on
   configuration nothing here supplies, which reads as a guarantee. The two
   real shapes:

   - **Running a PUBLISHED IMAGE** (`ghcr.io/…/whity-core/web`, as
     `docker-compose.staging-remote.yml` does). The bundle is baked in at build
     time and its identity is checked in `web/Dockerfile` and again by the
     release smoke job. There is nothing to rebuild: pull the new tag. A
     `git pull` in a checkout changes nothing about what that container serves.

   - **Running from a SOURCE CHECKOUT.** Nothing rebuilds unless you arrange
     it. `scripts/start-web.sh` is the piece that does — it compares the built
     commit against the checked-out one and rebuilds on a mismatch — but you
     must actually make it the start command; no compose file here does. To
     force a build regardless: `WHITY_FORCE_BUILD=1`.

   A start command that builds only when `.next/BUILD_ID` is ABSENT is the bug
   this step exists for — it asks "does a build exist?", never "is it THIS
   build?", so a restart after a checkout silently re-serves the old bundle.

   **`npm ci` is not optional when the lockfile moved, and its absence does not
   announce itself.** A checkout whose dependencies were never installed cannot
   build at all, and `start-web.sh` will loop retrying — the failure is in the
   web log, not on the page, which keeps serving the previous bundle. Verify
   the result rather than assuming it (below).

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
   curl -s http://<host>/api/build    | jq '{commit, source, checkout_commit, booted_at, pending_migration_count}'
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

   **`/api/build` is what makes steps 3 and 6 checkable at all** (#1049).
   `version` is a constant in the source: it is identical across every commit
   between releases, so it cannot tell a backend running today's checkout from
   one running a three-week-old one, and it says nothing about the schema.
   Read three things there:

   - `commit` is the checkout the WORKERS ARE RUNNING, frozen at boot. It
     equals `/web-build`'s `commit` on a deployment where both tiers were
     updated together.
   - `commit` **must equal `checkout_commit`** on a source deployment.
     `checkout_commit` is read from disk per request, so when the two differ
     the code was updated and the workers were never restarted — step 6 did
     not happen, or did not take. Workers never recycle (no `max_requests`),
     so the old build serves indefinitely and `/api/health` stays green. In an
     image deployment `checkout_commit` is `null` and there is nothing to
     compare — `booted_at` is the field to read instead.
   - `pending_migration_count` **must be 0.** Anything else means step 4 did
     not run or did not finish: the code is new and the schema is not, which
     500s the first query against a table the update added. Staging was found
     15 migrations behind its own checkout in exactly this state, by hand,
     weeks later.

   `source` says where `commit` came from — `build` (baked into the image at
   build time), `checkout` (read from `.git` at worker boot), or `unknown`. A
   deployment reporting `unknown` cannot answer any of the above; for an image
   that means it was built without `--build-arg WHITY_BUILD_COMMIT=<sha>`.

   **If the render profile is running, it is a third tier to check** — and the
   one whose staleness is least visible, since a stale render container is
   healthy and merely exports the previous release's layout:

   ```bash
   curl -s http://<render-host>:8130/health | jq '{status, core_version, commit}'
   ```

   Its `core_version` must equal the other two. See
   [Document-Render-Service.md](./Document-Render-Service.md).

## Rolling back

Check out the previous tag and restart (steps 5–6). Migrations are
backward-compatible and non-destructive by project policy, so the previous
code runs against the newer schema; rolling the schema itself back uses
`migrate rollback` (mind the global-LIFO caveat documented in
[Plugin-Distribution.md](./Plugin-Distribution.md)) or the step-1 backup.
