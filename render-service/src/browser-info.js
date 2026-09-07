'use strict';

/**
 * WHICH BROWSER IS THIS IMAGE ACTUALLY RUNNING (#1134).
 *
 * `render-service/Dockerfile` installs `chromium` unpinned, so the browser is
 * the one input to this service that can change without anything in this
 * repository changing. This module is the reading half of making that visible:
 * `scripts/write-browser-info.js` freezes what the build installed into
 * `dist/browser-info.json`, and this reads it back at boot for `GET /health`.
 *
 * Deliberately the same shape as src/Core/BuildIdentity.php on the PHP side
 * (#1049), rather than a second convention:
 *
 *   - captured at BUILD time, read at RUNTIME — because the honest answer to
 *     "what did this image install" cannot be recovered later by asking the
 *     container to look at itself;
 *   - a `source` field saying WHERE the answer came from, reported beside the
 *     value rather than inferred from its presence;
 *   - `unknown` with null fields as a first-class answer. A plausible-looking
 *     wrong version is worse than no version: an operator diffing two images
 *     to explain a pagination change cannot tell a confident lie from the
 *     truth, and this whole issue exists because a silent change had nothing
 *     to attribute it to.
 *
 * The one thing this has that BuildIdentity does not need is a second reading
 * of the SAME fact: `running_version` is what the browser answers when the
 * boot probe launches it (src/capability-probe.js). The baked value describes
 * what the build installed; the running value describes the binary that is
 * there now. They differ when someone has mounted a different browser over
 * the recorded path — which is a fact one field cannot state, so both are
 * reported, exactly as BuildIdentity reports the frozen commit beside the
 * on-disk one.
 */

const fs = require('node:fs');
const path = require('node:path');

/** Read from the build-time file baked into the image. */
const SOURCE_BUILD = 'build';

/** Nothing could be established; every field is null. */
const SOURCE_UNKNOWN = 'unknown';

const DEFAULT_FILE = path.join(__dirname, '..', 'dist', 'browser-info.json');

/**
 * A dotted numeric version, or null.
 *
 * Same rule as scripts/write-browser-info.js, applied again on the way IN:
 * the file is written inside the image build, but nothing stops a bind mount
 * from replacing it, and a field that has to be trusted is a field that has to
 * be validated at the boundary.
 */
function normalizeVersion(value) {
  if (typeof value !== 'string') {
    return null;
  }
  const trimmed = value.trim();
  return /^[0-9]+(\.[0-9]+){1,3}$/.test(trimmed) ? trimmed : null;
}

/** A free-text field, trimmed, or null when it is absent or empty. */
function normalizeText(value) {
  if (typeof value !== 'string') {
    return null;
  }
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

/** Nothing established. Every field null so a monitor gets no answer rather
 * than a wrong one. */
function unknown() {
  return {
    version: null,
    package_version: null,
    banner: null,
    executable: null,
    recorded_at: null,
    source: SOURCE_UNKNOWN,
  };
}

/**
 * The baked `dist/browser-info.json`, or an `unknown` identity.
 *
 * A file that exists but cannot name a VERSION collapses to `unknown` rather
 * than to a `build`-sourced identity carrying nulls — the honest reading of
 * "the build wrote a file it could not fill in" is that the build told us
 * nothing. v0.2.2 shipped a `/web-build` that answered 200 with every field
 * empty and read as "working" to everything checking it; that shape must not
 * be reachable here either.
 *
 * Absent entirely when the service is run straight from a checkout without
 * `npm run build:browser-info` — an image build always produces it, and the
 * build fails if it cannot.
 *
 * @param {string} [file] Path to the baked file (overridable for tests).
 */
function readRecordedBrowser(file = DEFAULT_FILE) {
  let parsed;
  try {
    parsed = JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch {
    return unknown();
  }

  if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return unknown();
  }

  const version = normalizeVersion(parsed.version);
  if (version === null) {
    return unknown();
  }

  return {
    version,
    package_version: normalizeText(parsed.package_version),
    banner: normalizeText(parsed.banner),
    executable: normalizeText(parsed.executable),
    recorded_at: normalizeText(parsed.recorded_at),
    source: SOURCE_BUILD,
  };
}

/**
 * The marketing version inside whatever `browser.version()` answered
 * (`HeadlessChrome/151.0.7922.173`, `Chrome/151.0.7922.173`), or null.
 *
 * The product name is dropped on purpose: it is `Chrome` here and
 * `HeadlessChrome` under a different launch mode for the same binary, so
 * comparing the raw strings would report drift on a change that is not one.
 * The full string is kept alongside as `running_banner`.
 */
function versionFromUserAgent(value) {
  if (typeof value !== 'string') {
    return null;
  }
  const match = /([0-9]+(?:\.[0-9]+){1,3})/.exec(value);
  return match === null ? null : normalizeVersion(match[1]);
}

/**
 * Whether the browser that answered at runtime is the one the build recorded.
 *
 * Null — not `false` — when either side is unknown: "we cannot tell" and "they
 * disagree" are different findings and only one of them is worth waking up
 * for.
 */
function versionsAgree(recordedVersion, runningVersion) {
  if (recordedVersion === null || runningVersion === null) {
    return null;
  }
  return recordedVersion === runningVersion;
}

module.exports = {
  SOURCE_BUILD,
  SOURCE_UNKNOWN,
  DEFAULT_FILE,
  readRecordedBrowser,
  versionFromUserAgent,
  versionsAgree,
};
