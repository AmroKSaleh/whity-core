# whity_render — server-side PDF export microservice (ADR 0012). Node +
# Puppeteer(-core) driving headless Chromium to print the document editor's
# documents (POST /render/flow: server-generated HTML, paginated in the browser
# by src/flow/assets/paginate.js) and label sheets (POST /render/labels). No
# front-end bundle is built or shipped: the old fixed-canvas designer and its
# React harness were removed in editor M4c.
#
# Build from the REPO ROOT (the build stage reads one file from outside
# render-service/, src/Core/CoreVersion.php):
#   docker build -f render-service/Dockerfile -t whity-render:dev .
# `render-service/Dockerfile.dockerignore` (not the root .dockerignore) shapes
# this build's context — see that file's header comment.
#
# Published by .github/workflows/release.yml as `ghcr.io/<repo>/render`, under
# the SAME tags as the app and web images and from the same gated job, because
# a render tier is only correct against the core release it was built from —
# see docs/wiki/Document-Render-Service.md.
#
# Two stages: `build` only writes dist/build-info.json (it needs the core's
# CoreVersion.php and the build-info script); `runtime` installs prod-only deps
# and copies in that one file + the server source, so neither the PHP file nor
# the build script ships in the image.

FROM node:22-bookworm-slim AS build
WORKDIR /workspace/render-service

COPY render-service/package.json ./
COPY render-service/scripts/write-build-info.js ./scripts/write-build-info.js

# The image's IDENTITY, baked in at build time.
#
# This is the THIRD published image of one release (app / web / render): a
# render container left a release behind keeps answering 200 while printing
# with whatever the older release's flow renderer did, which is invisible
# without something to compare.
#
# Neither input can be derived inside the build: the context is a copied tree
# with no .git, and src/ is excluded from it apart from the one file re-included
# below. Same shape as web/Dockerfile, for the same reason. Both stay in the
# BUILD stage — only the resulting dist/build-info.json crosses into `runtime`.
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
#
# ─── `chromium` IS DELIBERATELY UNPINNED. READ THIS BEFORE PINNING IT. ───────
#
# Every rebuild takes whatever Debian bookworm ships that day. That matters
# more here than in most services: the flowing mode's paginator
# (src/flow/assets/paginate.js) measures and fragments content against the
# browser's own layout, guarded by a refuse-on-disagreement check rather than
# by a specification, and every number its design rests on was measured against
# one build. A Chromium upgrade arriving through a rebuild can change
# fragmentation, and the failure is not a crash — it is a long document that
# paginates differently, or a guard that starts refusing renders that used to
# succeed.
#
# `chromium=151.0.7922.173-1~deb12u1` would make the build reproducible and
# would BREAK the day Debian rotates that version out for a security update,
# which it does routinely. Trading silent behaviour drift for periodic hard
# build failures on a security rebuild is a trade a maintainer should make
# deliberately; #1134 chose instead to make the drift VISIBLE and LOUD:
#
#   - `npm run build:browser-info` (below) freezes the installed version into
#     the image, and GET /health reports it beside the core version — so an
#     operator diffing two images can SEE that the browser moved.
#   - src/capability-probe.js asserts, at container start, the behaviours the
#     paginator actually relies on, and says so at boot rather than at render
#     time.
#
# If you do decide to pin, pin here AND update docs/wiki/Document-Render-Service.md,
# because the wiki's measured-facts table is what the probe's expectations are
# calibrated against.
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

# The BROWSER's identity, recorded after the dist/ from the build stage has
# landed so both facts about this image sit in one directory (#1134).
#
# This cannot live in the `build` stage beside `npm run build:info`: the
# browser is installed here, in `runtime`, and nowhere else. One script file is
# copied rather than the whole scripts/ directory, so the shipped image still
# carries no build tooling. Last, so a change to it invalidates nothing above.
COPY render-service/scripts/write-browser-info.js ./scripts/write-browser-info.js
RUN npm run build:browser-info

EXPOSE 8130

CMD ["node", "src/server.js"]
