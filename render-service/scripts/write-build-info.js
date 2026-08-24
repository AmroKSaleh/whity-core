#!/usr/bin/env node
'use strict';

/**
 * Freezes this build's IDENTITY into dist/build-info.json, which src/server.js
 * reports on GET /health.
 *
 * The render tier is a THIRD published image (`ghcr.io/<repo>/render`) that has
 * to stay in lockstep with the core it renders for: ADR 0012's whole premise is
 * that exported PDFs come from the SAME renderer source the designer draws
 * with, so a render container one release behind the app silently produces
 * output that no longer matches the preview. Identical tags make the pairing
 * correct at deploy time; this file makes it CHECKABLE afterwards, from
 * outside the box, the same way `/web-build` makes it checkable for the UI.
 *
 * The version is read from `src/Core/CoreVersion.php` — the single source of
 * truth every other tier derives from — rather than passed in as a build arg,
 * so a locally built image is as honest about itself as a released one.
 * The commit cannot be derived that way (an image build context is a copied
 * tree with no .git), so it arrives as WHITY_BUILD_COMMIT exactly as it does
 * for the web image.
 *
 * Missing/unparseable version is FATAL here rather than a null field at
 * runtime: v0.2.2 shipped a /web-build that answered 200 with every
 * identifying field empty, which reads as "working" to anything checking it.
 * Failing in the build is one line to read; failing in a deployment nobody is
 * watching is not.
 */

const fs = require('node:fs');
const path = require('node:path');

const RENDER_SERVICE_ROOT = path.resolve(__dirname, '..');
const REPO_ROOT = path.resolve(RENDER_SERVICE_ROOT, '..');
const CORE_VERSION_PHP = path.join(REPO_ROOT, 'src', 'Core', 'CoreVersion.php');
const OUT_FILE = path.join(RENDER_SERVICE_ROOT, 'dist', 'build-info.json');

function readCoreVersion() {
  let source;
  try {
    source = fs.readFileSync(CORE_VERSION_PHP, 'utf8');
  } catch (err) {
    throw new Error(
      `cannot read ${CORE_VERSION_PHP} (${err.code || err.message}). ` +
        'An image build must COPY src/Core/CoreVersion.php into the build stage — ' +
        'see render-service/Dockerfile and Dockerfile.dockerignore.'
    );
  }

  // Anchored on the constant, not on any `MAJOR.MINOR.PATCH` in the file: that
  // docblock cites version numbers in prose, and matching one of those would
  // produce a wrong answer that still looks like a version.
  const match = /VERSION\s*=\s*'([0-9]+\.[0-9]+\.[0-9]+)'/.exec(source);
  if (!match) {
    throw new Error(`no CoreVersion::VERSION constant found in ${CORE_VERSION_PHP}`);
  }
  return match[1];
}

function main() {
  const info = {
    core_version: readCoreVersion(),
    commit: process.env.WHITY_BUILD_COMMIT || null,
  };

  fs.mkdirSync(path.dirname(OUT_FILE), { recursive: true });
  fs.writeFileSync(OUT_FILE, JSON.stringify(info, null, 2) + '\n', 'utf8');

  console.log(`[build-info] wrote ${OUT_FILE}: ${JSON.stringify(info)}`);
}

try {
  main();
} catch (err) {
  console.error('[build-info] failed:', err && err.message ? err.message : err);
  process.exit(1);
}
