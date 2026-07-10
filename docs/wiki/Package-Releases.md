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

## PHP SDK (`whity/plugin-sdk`)

The plugin SDK is a Composer package versioned by git tag (semver). Tag a
release (`vX.Y.Z`) when its public surface changes; plugin authors pin a range.
Keep it backward-compatible within a major so existing plugins keep working.
