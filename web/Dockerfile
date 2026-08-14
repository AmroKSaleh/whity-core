# whity-web — the Next.js frontend, published as its OWN release image
# (WHIT-588). Before this existed, a deployment assembled purely from published
# release artifacts got an API and no user interface: release.yml built only the
# PHP image, so docker-compose.staging-remote.yml had nothing to reference and
# `/api/health` went green with nobody able to log in.
#
# WHY A SECOND IMAGE rather than folding the frontend into the root Dockerfile:
# the two tiers are different runtimes with different process models — a
# FrankenPHP worker pool serving public/ and a Node server running `next start`.
# One container would need a supervisor to keep two unrelated processes alive
# and would tie the two tiers' restart, scaling and rollback to each other,
# while the release tags already give us the coupling that actually matters:
# both images are built by the same gated job and carry the same tag, so a
# deployment can never mix a v0.2.0 API with a v0.1.0 UI.
#
# Build from the REPO ROOT — `web` is an npm workspace that imports packages/*
# and shares the single root lockfile, so nothing smaller than the repo root
# installs:
#   docker build -f web/Dockerfile -t whity-web:dev .
# `web/Dockerfile.dockerignore` (not the root .dockerignore) shapes this
# build's context — see that file's header comment for why it is mandatory.
#
# Node 22 is the version CI validates the frontend against (the `web` job in
# .github/workflows/automated-tests.yml and release.yml).
#
# Two stages: `build` installs the whole workspace — the Next/TypeScript
# toolchain lives in devDependencies — and produces .next; `runtime` inherits
# that tree with devDependencies pruned away, because `next` itself is a runtime
# dependency but everything that produced the build is not.

FROM node:22-bookworm-slim AS build

# Deliberately NOT NODE_ENV=production in this stage: that would make `npm ci`
# skip the devDependencies that ARE the build toolchain.
ENV NEXT_TELEMETRY_DISABLED=1

WORKDIR /app

# Manifests before sources so the dependency layer is cached independently of
# frontend churn. EVERY workspace the root package.json declares has to be here
# — including web's own manifest, ahead of its source: a workspace missing from
# the tree does not fail `npm ci`, it silently drops that workspace's
# dependencies and the failure surfaces much later as `next: not found`.
# templates/ contributes only its package.json (Dockerfile.dockerignore drops
# the rest); packages/ arrives whole because web consumes @amroksaleh/ui as
# source, not as a build artifact.
COPY package.json package-lock.json ./
COPY packages packages
COPY templates templates
COPY web/package.json web/package.json
RUN npm ci

COPY web web

# The same command the release `web` job gates on: the `prebuild` hook
# regenerates the shadcn registry, then `next build` compiles.
RUN npm run build --workspace web

# Strip the toolchain from the tree the runtime stage inherits.
RUN npm prune --omit=dev

FROM node:22-bookworm-slim AS runtime

ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1

WORKDIR /app

# The whole pruned workspace, not just web/: dependencies are hoisted to the
# root node_modules and the workspace packages resolve through symlinks back
# into packages/, so the tree cannot be split apart. Owned by `node` because
# `next start` writes its runtime caches under .next/.
COPY --from=build --chown=node:node /app /app

# Nothing here needs root, and the node image already ships this user.
USER node

# The backend origin is NOT baked in: web/lib/backend-url.ts reads
# WHITY_BACKEND_URL from the process environment on every call (never a
# NEXT_PUBLIC_* value, which Next would inline at build time), so this one
# published image serves every deployment. The orchestrator supplies it — see
# the `web` service in docker-compose.staging-remote.yml.
WORKDIR /app/web
EXPOSE 3000

CMD ["/app/node_modules/.bin/next", "start"]
