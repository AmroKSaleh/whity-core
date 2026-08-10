# Releasing packages

How the publishable packages in this monorepo are versioned and released.

Related: [Development Workflow](Development-Workflow.md), [Component Library](Component-Library.md).

---

## The model: one repo, independently-versioned packages

Whity is a **monorepo**, not a set of split repos — the backend and the web app
are bound by the OpenAPI contract, so changing an endpoint, regenerating the
typed client, and updating the UI happen in **one atomic PR** with one CI run.

But the genuinely reusable pieces are **published packages with their own
versions**, released on their own cadence:

| Package | Path | Registry | Notes |
|---|---|---|---|
| `@amroksaleh/ui` | `packages/ui` | GitHub Packages | shared React component library |
| `@amroksaleh/features` | `packages/features` | GitHub Packages | client-safe feature UI (adapter pattern, nav contract, sync UI) for non-Next clients (Tauri/Vite SPA, Flutter) |
| `@amroksaleh/tokens` | `packages/tokens` | GitHub Packages | design tokens (CSS vars, Dart/Flutter export) |
| `whity/plugin-sdk` | `sdk` | Composer | PHP SDK for plugin authors |
| `web` | `web` | — (private) | the reference app; deployed, never published |

The backend (`amroksaleh/whity-core`) ships as a Docker image, not a package.

## How a JS package release works (changesets)

Versioning is driven by [changesets](https://github.com/changesets/changesets),
so every version bump is **intentional and reviewed**, and each package moves on
its own semver.

1. **Make your change** to `packages/ui` (or another publishable package) in a
   normal PR.
2. **Record the release intent** — from the repo root:
   ```bash
   npm run changeset
   ```
   Pick the package(s) and the bump (patch / minor / major) and write a one-line
   summary. This adds a small markdown file under `.changeset/`. Commit it with
   your PR. (A change with no user-facing package impact needs no changeset.)
3. **Apply the bumps** when you're ready to cut a release — from root:
   ```bash
   npm run version-packages
   ```
   This consumes the pending changesets, bumps each affected `package.json`, and
   writes/updates its `CHANGELOG.md`. Commit the result.
4. **Merge to `main`.** The `Publish @amroksaleh/ui` workflow runs on any push
   touching `packages/ui`, but it is **version-gated**: it publishes only when
   the `package.json` version isn't already in the registry, otherwise it skips
   with a notice. So a merge with a fresh version publishes; a merge that didn't
   bump the version is a clean no-op (never a red build).

> The `web` app is listed in `.changeset` `ignore` — it is private and deployed,
> so it is never versioned or published.

## Why version-gating matters

`npm publish` fails with a `409 Conflict` if the version already exists. Running
it unconditionally on every `packages/ui` change turned `main` red whenever a
change didn't bump the version. The workflow now checks the registry first and
only publishes a genuinely new version — bumps release, incidental edits don't.

## Consuming a package from a DOWNSTREAM repo (not this monorepo)

Everything above is how a package gets *published*. A separate repo (a native
desktop client, or any other downstream product) that wants to `npm install
@amroksaleh/ui` (or `features`/`tokens`) needs to *authenticate to GitHub
Packages first* — **this is required even though the packages are public.**
Unlike npmjs.org, `npm.pkg.github.com` requires a token on every request,
including reads of public packages. Skipping this step is what makes
`npm install` fail with a 404 as if the package didn't exist.

### One-time: local developer machine

Add to `~/.npmrc` (per-user, not committed):

```
@amroksaleh:registry=https://npm.pkg.github.com
//npm.pkg.github.com/:_authToken=${GH_PACKAGES_TOKEN}
```

Then export `GH_PACKAGES_TOKEN` (e.g. in your shell profile) as a **classic**
GitHub PAT scoped to **`read:packages`** only. Generate one at
[github.com/settings/tokens](https://github.com/settings/tokens) — a
fine-grained PAT does not currently support the Packages API, so it must be a
classic token. For a team (not one person's token going stale on offboarding),
generate it from a shared machine/bot account instead of an individual's.

### CI (a downstream repo's GitHub Actions)

Don't put a PAT in another repo's secrets — grant that repo direct read access
to the package instead, and its own auto-provided `GITHUB_TOKEN` will work:

1. Open the package's page on GitHub (under the publishing account/org that
   owns `@amroksaleh` — these are user-owned packages here, so
   `github.com/users/<owner>/packages/npm/<name>`).
2. **Package settings → Manage Actions access → Add repository** — add the
   downstream repo, role **Read**.
3. Repeat per package (`ui`, `features`, `tokens` — each is a separate grant).
4. In the downstream workflow, configure npm the same way `setup-node` does it
   in this repo's own publish workflows: `registry-url:
   https://npm.pkg.github.com`, `scope: @amroksaleh`, and
   `NODE_AUTH_TOKEN: ${{ secrets.GITHUB_TOKEN }}` (the default token — no new
   secret to create or rotate).

### Troubleshooting

- `404 Not Found` on `npm install` → no token configured at all (the most
  common case — GitHub Packages 404s an unauthenticated request rather than
  ever serving public package data anonymously).
- `403 … You need at least read:packages scope` → a token IS present but
  lacks the `read:packages` scope (e.g. a PAT created for something else, or a
  fine-grained PAT — switch to a classic PAT with that scope).
- Confirm what's actually published (needs a `read:packages`-scoped token):
  `gh api users/<owner>/packages/npm/<name>/versions`.

## PHP SDK (`whity/plugin-sdk`)

The plugin SDK is a Composer package versioned by git tag (semver). Tag a
release (`vX.Y.Z`) when its public surface changes; plugin authors pin a range.
Keep it backward-compatible within a major so existing plugins keep working.
