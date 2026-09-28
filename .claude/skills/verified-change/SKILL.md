---
name: verified-change
description: The procedure for landing a change in whity-core, and the traps that make a check report success without having checked anything. Load before starting any change that will become a PR — a re-gate, a guard, a migration, a doc fix, a dependency bump. Covers the worktree discipline on this shared checkout, running PHP (Docker only), the throwaway Postgres for anything touching the database, how to prove a guard can fail, and verifying a merge on develop rather than from the merge report.
---

# Landing a change in whity-core

This encodes a recipe that works and, more importantly, the specific ways it
silently does not. Every trap below was hit for real. They are mechanical, not
matters of judgement, which is why they are worth writing down: judgement varies
by task, and these do not.

## The one-line summary

**Every check must read back the state it claims about, and you must know it can
fail.** Most of what follows is that sentence applied to a particular tool.

---

## 1. Work in a worktree, never the main checkout

`C:\Projects\Whity\whity-core` is a **shared single-HEAD checkout**. Other
sessions move its HEAD under you, and its working tree is routinely tens of
commits stale.

```bash
cd /c/Projects/Whity/whity-core
git fetch origin develop -q
git worktree add -b <type>/<slug> "C:/Projects/Whity/wt-<short>" origin/develop
```

**Use a Windows-style path for the worktree.** With `MSYS_NO_PATHCONV=1` set,
git takes `/c/Projects/...` literally and creates the worktree at
`C:\c\Projects\...` while your files go somewhere else entirely.

**Never read the main checkout's files to learn what is on develop.** Read the
ref:

```bash
git show 'origin/develop:path/to/file'          # correct
cat /c/Projects/Whity/whity-core/path/to/file   # stale, and has caused a
                                                # false "I found a second bug"
```

Never use bare `git stash` here — the stack is shared.

---

## 2. PHP runs only in Docker

There is no `php` on this host. A piped invocation will report success while
never executing.

```bash
MSYS_NO_PATHCONV=1 docker run --rm \
  -v /c/Projects/Whity/wt-<short>:/app \
  -v /c/Projects/Whity/whity-core/vendor:/app/vendor \
  -w /app whity-staging-frankenphp:latest \
  php vendor/bin/phpunit --no-coverage <paths>
```

`composer` is **not** in that image. Use `composer:2` for dependency work.

**PHPStan:** run it the way CI does, via the paths and memory limit in
`composer.json`'s `phpstan` script (`--memory-limit=1G`). Invoking the binary
directly inherits a 256M default and the workers *crash* rather than analyse —
which reports as "2 errors" that have nothing to do with your change.
Analysing a **single file** also produces false `Function … not found` errors;
if you must, run the same command on the unmodified file from `develop` as a
control before believing anything.

---

## 3. Never point an unfiltered phpunit at a live database

`vendor/bin/phpunit` with no filter **truncates tables**. `whity_staging_frankenphp`
has `DB_HOST` pointing at the live staging database. This has destroyed it twice.

Scope phpunit to paths, or use the throwaway stack below.

---

## 4. Anything touching the database: use the throwaway Postgres

`docker/ci-local/` has a Postgres with no published port and tmpfs storage — it
cannot be confused with staging.

```bash
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
CF="C:/Projects/Whity/wt-<short>/docker/ci-local/docker-compose.yml"
export CI_LOCAL_REPO="C:/Projects/Whity/wt-<short>"

docker compose -f "$CF" up -d pg
# wait for {{.Health}} == healthy, then:
docker compose -f "$CF" run --rm \
  -e DB_HOST=pg -e DB_PORT=5432 -e DB_NAME=whity_core \
  -e DB_USER=whity -e DB_PASSWORD=whity_dev \
  ci sh -c 'composer install -q --no-interaction --no-progress; php bin/whity-cli migrate run'
```

Tear down with `docker compose -f "$CF" down -v` and **confirm** staging is
untouched afterwards.

### A permission re-gate is a lockout risk, not an error risk

Gating a route on a slug nobody holds does not throw. It 403s every caller
including the administrator the gate was written for, on the day it ships. So:

1. `scripts/ci-permission-holder-guard.php --dsn=… --user=… --password=…`
   against the migrated database. It fails closed without one, deliberately.
2. **Then the question the holder guard does not answer**: does the role the
   *caller* actually holds have the slug? Query `role_permissions` directly.

### Grant by capability, never to a role by name

`ci-grant-by-role-name-guard` grandfathers 27 old migrations and permits no new
ones. More importantly, granting to `admin` literally means a deployment with a
renamed administrative role **silently loses** the capability on upgrade (#834).
Copy migration **110**, not the older `grant_*_to_admin` ones.

### Prove `down()` reverses

`migrate rollback` steps one migration at a time and plugin migrations sit above
yours, so it rolls back the wrong thing. Drive the class directly in a throwaway
script (`Database::connect()`, not `new Database()`), assert the counts, delete
the script before committing.

---

## 5. Writing a guard: prove it can FAIL

A guard whose failure path has never run is an empty grep result you have decided
to trust. Test **every** direction:

- the tree as it is today → passes
- a new violation appears → fails, and names it
- a grandfathered entry gets fixed → fails, demanding the allowlist shrink
- **the scanner matches nothing at all → exits non-zero, not 0**

That last one is the one people skip. "Everything was fixed" and "my regex
stopped matching the code style" produce an identical empty result, and only one
is good news.

Keep allowlists keyed by something **stable** (a route, a filename) — never line
numbers, which move on unrelated edits until someone deletes the list.

---

## 6. The traps that make a check lie

| Trap | What you see | What to do |
|---|---|---|
| `cmd \| tail` | exit code is `tail`'s, always 0 | redirect to a file, read `$?` unpiped, or `${PIPESTATUS[0]}` |
| Quoted heredoc with `\` or `'` | backslashes eaten; anchors silently fail to match | write the script with the Write tool, then run it |
| CRLF files | `\n`-joined anchors never match | normalise to `\n`, patch, write back as CRLF; preserve the BOM |
| `grep` for a token | matches it inside a **comment** | classify on real usage (`^import`, actual calls) |
| `grep` for a phrase | line-based; a phrase wrapping a line never matches | flatten first, or read the block |
| `--is-ancestor` after a squash merge | reports NOT merged | verify by **content** on `origin/develop` |
| GitHub checks API right after `update-branch` | zero checks for ~4 min | wait before concluding; do not force-push to "kick" it |
| A `sed`/`replace` with no assertion | reports success, changed nothing | assert `count == 1` before replacing; read the file back |

**Report from what came back, never from what you intended to write.**

---

## 7. Before opening the PR

Run what applies. Check the *real* exit code of each.

- `php -l` on every changed PHP file
- the relevant phpunit paths (scoped — see §3)
- `scripts/ci-*.php` guards: tenant-predicate, db-bool, grant-by-role-name,
  role-name-route, playwright-orphan-specs
- frontend: `tsc --noEmit -p web/tsconfig.json`, `eslint`, and **jest from
  `web/`** — the config is `web/jest.config.mjs`, and from the repo root jest
  finds no config and falls back to plain Babel, which cannot parse TypeScript
- touched a `t()` call or moved one between files? `bin/whity-cli i18n:extract`.
  A `t()` that changes file keeps working at runtime and **silently stops being
  extractable** — no other check sees it, and an `@i18n-keys` block does not fix
  it (it declares keys, not call-site domains)
- added a route or schema? regenerate `public/openapi.json` **in CI**, never
  locally — the generator drives the live router and a locally-installed plugin
  will publish its routes into core's public contract

---

## 8. PR and merge

`gh pr create` returns GraphQL 500s here. Use the REST API:

```bash
gh api repos/AmroKSaleh/whity-core/pulls -X POST \
  -f title='…' -f head='<branch>' -f base=develop -F body=@<file>
```

`develop` is **strict** with auto-merge disabled: one PR per full CI cycle, and
every merge puts the others behind. `gh pr merge` exits non-zero even on success
(its local checkout cannot switch to a branch a worktree holds) — **read the PR's
own state afterwards**, never the command's exit code.

`UNSTABLE` is mergeable. It means path-filtered jobs were skipped, which is
normal; waiting for `CLEAN` can wait forever. Gate on `mergeable == MERGEABLE`
plus no failing check.

**A develop → main release PR merges with a MERGE COMMIT, never a squash**, and
its head branch must not be deleted. Verify afterwards that the merge commit has
two parents.

After merging, **verify on `origin/develop`** — not from the merge script's
report.

---

## 9. Clean up

Remove the worktree, prune, and delete the directory. Tear down any throwaway
compose stack with `-v`.

If you linked `node_modules` as junctions, remove them with `cmd /c rmdir` —
`rm -rf` follows a junction and will delete the **target's** contents.

---

## 10. When the task itself looks wrong, stop

The most valuable outcomes are often not the change that was asked for:

- a re-gate abandoned because the feature turned out dormant, uncalled and
  unfinished — minting permanent permission slugs for it would have been debt
- an issue reporting "43 violations" corrected to "none of these is a hazard,
  and 27 are already guarded by a different tool"
- a recommendation to delete four test files reversed after checking their
  target pages still exist

Say what the evidence shows, file the finding, and do the part that is clearly
right. Efficiently doing mis-specified work is the failure mode this whole file
exists to make less likely.
