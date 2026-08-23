# whity_render — server-side PDF/PNG export microservice (ADR 0012 /
# WC-docdesigner Track 2). Node + Puppeteer(-core) loading the SAME React
# renderer the browser preview uses (web/components/documents/print-document.tsx)
# via headless Chromium, so exported output stays pixel-parity with the
# on-screen designer preview instead of a second hand-rolled renderer.
#
# Build from the REPO ROOT (this Dockerfile reaches outside render-service/ to
# bundle in the portable renderer source it does not own):
#   docker build -f render-service/Dockerfile -t whity-render:dev .
# `render-service/Dockerfile.dockerignore` (not the root .dockerignore) shapes
# this build's context — see that file's header comment.
#
# Published by .github/workflows/release.yml as `ghcr.io/<repo>/render`, under
# the SAME tags as the app and web images and from the same gated job, because
# a render tier is only correct against the core release it was built from —
# see docs/wiki/Document-Render-Service.md.
#
# Two stages: `build` installs ALL deps (incl. esbuild, a devDependency) and
# bundles the harness; `runtime` installs prod-only deps and copies in just the
# bundle output + server source, so the shipped image carries no TypeScript
# tooling.

FROM node:22-bookworm-slim AS build
WORKDIR /workspace/render-service

COPY render-service/package.json render-service/package-lock.json ./
RUN npm ci

COPY render-service/scripts ./scripts
COPY render-service/harness ./harness
# The portable renderer source, bundled in from elsewhere in the monorepo —
# see scripts/build-harness.js's header comment for the exact import graph.
COPY web/components/documents /workspace/web/components/documents
COPY web/lib/documents /workspace/web/lib/documents
COPY packages/ui/src /workspace/packages/ui/src
COPY packages/features/src /workspace/packages/features/src

RUN npm run build:harness

# The image's IDENTITY, baked in beside the bundle it describes.
#
# This is the THIRD published image of one release (app / web / render) and the
# only one whose whole reason for existing is parity with the other two: the
# bundle above is compiled from web/ + packages/ source, so a render container
# left a release behind keeps answering 200 while producing PDFs that no longer
# match the designer preview — the exact drift ADR 0012 exists to prevent, and
# the exact drift that is invisible without something to compare.
#
# Neither input can be derived inside the build: the context is a copied tree
# with no .git, and src/ is excluded from it apart from the one file re-included
# below. Same shape as web/Dockerfile, for the same reason. Both stay in the
# BUILD stage — only the resulting dist/build-info.json crosses into `runtime`.
#
# Placed after build:harness on purpose: a new commit then invalidates this
# layer alone and the (slow) bundle build stays cached.
ARG WHITY_BUILD_COMMIT=""
ENV WHITY_BUILD_COMMIT=$WHITY_BUILD_COMMIT
COPY src/Core/CoreVersion.php /workspace/src/Core/CoreVersion.php
RUN npm run build:info

FROM node:22-bookworm-slim AS runtime

# System Chromium + fonts baked into the image, including Arabic (Noto Naskh /
# Noto Sans Arabic — hard cross-cutting RTL requirement across this app;
# without them Arabic text prints as tofu). Debian's `fonts-noto-core` package
# already bundles both — verified: it installs
# /usr/share/fonts/truetype/noto/Noto{Naskh,Sans}Arabic-{Regular,Bold}.ttf.
# puppeteer-core (not puppeteer) is used specifically so npm never tries to
# download its OWN Chromium — this apt-installed binary is the only one.
RUN apt-get update && apt-get install -y --no-install-recommends \
      chromium \
      fonts-noto-core \
      fontconfig \
    && fc-cache -f \
    && rm -rf /var/lib/apt/lists/*

ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium
ENV NODE_ENV=production

WORKDIR /app

COPY render-service/package.json render-service/package-lock.json ./
RUN npm ci --omit=dev

COPY render-service/src ./src
COPY --from=build /workspace/render-service/dist ./dist

EXPOSE 8130

CMD ["node", "src/server.js"]
