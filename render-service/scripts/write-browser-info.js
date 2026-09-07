#!/usr/bin/env node
'use strict';

/**
 * Freezes the BROWSER this image was built around into dist/browser-info.json,
 * which src/server.js reports on GET /health (#1134).
 *
 * WHY THIS EXISTS
 *
 * The Dockerfile installs `chromium` with no version constraint, so every
 * rebuild picks up whatever Debian bookworm ships that day. That is a
 * deliberate choice — see the long comment above the apt-get in
 * render-service/Dockerfile — but it means the single most behaviour-defining
 * dependency of this service can change without one line of this repository
 * changing with it. The flowing mode's paginator
 * (src/flow/assets/paginate.js) measures and fragments content against the
 * browser's own layout, so a Chromium upgrade can change how a hundred-page
 * document paginates, or start tripping the refuse-on-disagreement guard, with
 * nothing in `git log` to explain it.
 *
 * A version number baked into the image does not stop that. It makes it
 * ATTRIBUTABLE: the operator staring at a document that paginates differently
 * this week can diff `/health` between the two images and see the browser
 * moved, instead of hunting a change that was never made here.
 *
 * WHY IT RUNS IN THE `runtime` STAGE, AND NOT BESIDE write-build-info.js
 *
 * `scripts/write-build-info.js` answers "which release is this" from
 * src/Core/CoreVersion.php, in the `build` stage, where the browser is not
 * installed. The browser only exists in `runtime`, after the apt-get, so this
 * has to be a second, later step. It writes into the SAME dist/ directory,
 * after the build stage's dist/ has been copied in, so the two facts about
 * this image sit side by side and are reported from one endpoint.
 *
 * WHY IT IS NOT DERIVED AT RUNTIME INSTEAD
 *
 * It partly is — the boot probe launches the browser and reports the version
 * it answers with (`running_version`). But a value read at runtime describes
 * the binary present NOW, which is exactly the thing that can have been
 * swapped underneath a container; the baked file describes what the build
 * installed and nothing the container does afterwards can change it. Both are
 * reported, for the same reason BuildIdentity reports the frozen commit and
 * the on-disk one separately (src/Core/BuildIdentity.php): two fields that
 * disagree are a fact, and a single field cannot state it.
 *
 * FATAL ON FAILURE, like write-build-info.js. Inside an image build the
 * browser IS installed, so "cannot determine a version" means the build is
 * already wrong. A null field written now is a null field read at 3am by
 * someone trying to explain a render, which is the shape of a check that
 * reports success without looking.
 */

const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const RENDER_SERVICE_ROOT = path.resolve(__dirname, '..');
const OUT_FILE = path.join(RENDER_SERVICE_ROOT, 'dist', 'browser-info.json');

/** The binary the service will actually launch — see src/renderer.js. */
const EXECUTABLE = process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium';

/** The apt package the Dockerfile installs, for the dpkg reading. */
const PACKAGE = process.env.WHITY_RENDER_BROWSER_PACKAGE || 'chromium';

/**
 * A marketing version (`151.0.7922.173`), or null.
 *
 * Rejects anything that is not a dotted numeric version rather than passing it
 * through: `unknown`, an empty string or a truncated banner would all reach an
 * operator's diff looking exactly like an answer. Same rule, and the same
 * reason, as BuildIdentity::normalizeCommit().
 */
function normalizeVersion(value) {
  if (typeof value !== 'string') {
    return null;
  }
  const trimmed = value.trim();
  return /^[0-9]+(\.[0-9]+){1,3}$/.test(trimmed) ? trimmed : null;
}

function run(file, args) {
  try {
    return execFileSync(file, args, { encoding: 'utf8', timeout: 15000, stdio: ['ignore', 'pipe', 'ignore'] });
  } catch {
    return null;
  }
}

/**
 * `Chromium 151.0.7922.173 built on Debian GNU/Linux 12 (bookworm)` — the
 * browser's own answer, which is the one that matters: it is the binary that
 * will be launched, not merely the package that claims to provide it.
 */
function readBanner() {
  const out = run(EXECUTABLE, ['--version']);
  return out === null ? null : out.trim();
}

/**
 * `151.0.7922.173-1~deb12u1` — the dpkg version, which carries the Debian
 * revision the marketing version drops. That suffix is the part that moves on
 * a security rebuild of the same upstream release, so it is the finer-grained
 * drift signal of the two.
 */
function readPackageVersion() {
  const out = run('dpkg-query', ['-W', '-f=${Version}', PACKAGE]);
  return out === null || out.trim() === '' ? null : out.trim();
}

function main() {
  const banner = readBanner();
  const packageVersion = readPackageVersion();

  // The banner first (it is the binary speaking), then the dpkg version with
  // its Debian revision stripped. Best-effort order, not a fallback chain that
  // hides a failure: if neither can answer, nothing is written.
  const version =
    normalizeVersion(banner === null ? null : (/([0-9]+(?:\.[0-9]+){1,3})/.exec(banner) || [])[1]) ??
    normalizeVersion(packageVersion === null ? null : (/^([0-9]+(?:\.[0-9]+){1,3})/.exec(packageVersion) || [])[1]);

  if (version === null) {
    throw new Error(
      `could not determine a browser version: \`${EXECUTABLE} --version\` and ` +
        `\`dpkg-query -W ${PACKAGE}\` both failed to produce one. This step runs in the ` +
        'image build, where the browser IS installed — see render-service/Dockerfile.'
    );
  }

  const info = {
    version,
    package_version: packageVersion,
    banner,
    executable: EXECUTABLE,
    recorded_at: new Date().toISOString(),
  };

  fs.mkdirSync(path.dirname(OUT_FILE), { recursive: true });
  fs.writeFileSync(OUT_FILE, JSON.stringify(info, null, 2) + '\n', 'utf8');

  // eslint-disable-next-line no-console
  console.log(`[browser-info] wrote ${OUT_FILE}: ${JSON.stringify(info)}`);
}

try {
  main();
} catch (err) {
  // eslint-disable-next-line no-console
  console.error('[browser-info] failed:', err && err.message ? err.message : err);
  process.exit(1);
}
