'use strict';

/**
 * `whity_render` HTTP server (ADR 0012 / WC-docdesigner Track 2).
 *
 * A minimal INTERNAL API — never exposed publicly, called only by whity-core's
 * DocumentRenderApiHandler over the compose network:
 *
 *   GET  /health  — liveness probe (no auth; no secrets in the response). Also
 *                   reports this image's IDENTITY (core_version + commit, from
 *                   dist/build-info.json) so a render container that has
 *                   drifted from the core it serves is visible from outside
 *                   the box — see scripts/write-build-info.js — plus the
 *                   BROWSER it was built around and the boot-time capability
 *                   probe's verdict (#1134), which is the other half of the
 *                   same question: the Dockerfile installs Chromium unpinned,
 *                   so the browser can move without this repository moving.
 *   POST /render  — {template, dataRows?, sheet?, blocks?} -> raw PDF bytes.
 *                   Requires the `X-Render-Secret` header to match
 *                   RENDER_SHARED_SECRET (>= 32 chars) exactly.
 *
 * The harness bundle (built by scripts/build-harness.js into dist/harness/)
 * is served statically at /_harness/ so Puppeteer can navigate to it over
 * plain HTTP on localhost — no secrets live there, it is just the bundled
 * renderer UI.
 */

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const express = require('express');
const rateLimit = require('express-rate-limit');

const { renderToPdf, shutdown } = require('./renderer');
const { validatePayload } = require('./limits');
const { renderFlowToPdf } = require('./flow/render');
const { validateFlowPayload } = require('./flow/document');
const { readRecordedBrowser, versionFromUserAgent, versionsAgree } = require('./browser-info');
const {
  runCapabilityProbe,
  STATUS_DEGRADED,
  STATUS_ERROR,
  STATUS_PENDING,
  STATUS_NOT_RUN,
} = require('./capability-probe');

const PORT = Number(process.env.PORT || 8130);
const SHARED_SECRET = process.env.RENDER_SHARED_SECRET || '';
const HARNESS_DIR = path.join(__dirname, '..', 'dist', 'harness');

/**
 * Whether a REQUIRED capability-probe failure refuses flow renders (#1134).
 *
 * Default on: the flowing mode's correctness rests on exactly the behaviours
 * the required probes assert, and its own paginator already refuses rather
 * than emit a document whose page numbers it cannot vouch for
 * (src/flow/render.js). A 503 naming the probe that disagreed is a better
 * outcome than a plausible-looking, wrongly-paginated hundred-page PDF.
 *
 * Overridable, because a gate with no escape hatch is itself a new way to be
 * down: if this ever mis-fires, an operator needs to be able to keep rendering
 * while the probe is fixed, rather than waiting for a release.
 */
const FLOW_REQUIRES_CAPABILITIES = process.env.RENDER_FLOW_REQUIRE_CAPABILITIES !== 'false';

// Rate limiting for POST /render: each call drives a full headless-Chromium
// page load, so even a trusted-but-misbehaving caller (a retry storm, a bug
// in the core PHP client) can exhaust CPU/memory with unbounded concurrent
// renders. A single-process in-memory store (express-rate-limit's default) is
// adequate for this service's actual deployment shape (a single instance
// behind the shared-secret gate, never exposed publicly, never horizontally
// scaled today) — the limit/window are env-overridable so an operator can
// tune them without a code change; swap in a shared store (e.g. Redis) if
// this service is ever run as multiple replicas.
const renderRateLimiter = rateLimit({
  windowMs: Number(process.env.RENDER_RATE_LIMIT_WINDOW_MS || 60_000),
  limit: Number(process.env.RENDER_RATE_LIMIT_MAX || 30),
  standardHeaders: true,
  legacyHeaders: false,
  message: { error: 'Too many render requests; try again shortly' },
});

if (SHARED_SECRET.length < 32) {
  // Not fatal — /health must still work so `docker compose` healthchecks and
  // manual troubleshooting are possible — but /render refuses every request
  // until this is fixed (see requireSharedSecret below).
  // eslint-disable-next-line no-console
  console.warn(
    '[whity_render] RENDER_SHARED_SECRET is unset or shorter than 32 chars; ' +
      'every POST /render will be refused until it is configured.'
  );
}

const app = express();
app.disable('x-powered-by');
app.use(express.json({ limit: '25mb' }));

/**
 * This build's identity, frozen at build time by scripts/write-build-info.js.
 *
 * Read once at boot, not per request: it cannot change while the process
 * lives, and /health is the endpoint an orchestrator hammers.
 *
 * Absent only when the service is run straight from a checkout without
 * `npm run build:info` (an image build always produces it, and the build fails
 * if it cannot). Reported as nulls in that case rather than omitted, so a
 * consumer reads "this build does not know" from the same field shape instead
 * of having to distinguish a missing key from an old service.
 */
const BUILD_INFO = (() => {
  try {
    const raw = fs.readFileSync(path.join(__dirname, '..', 'dist', 'build-info.json'), 'utf8');
    const parsed = JSON.parse(raw);
    return {
      core_version: parsed.core_version || null,
      commit: parsed.commit || null,
    };
  } catch {
    return { core_version: null, commit: null };
  }
})();

/**
 * The BROWSER this image was built around, frozen at build time by
 * scripts/write-browser-info.js and read once here for the same reason
 * BUILD_INFO is (#1134).
 *
 * `source: "unknown"` with null fields when the service is run from a checkout
 * that never ran `npm run build:browser-info`. An image build always produces
 * it and fails if it cannot.
 */
const RECORDED_BROWSER = readRecordedBrowser();

/**
 * The boot-time capability probe's verdict (src/capability-probe.js).
 *
 * `not_run` until `start()` kicks it off — which is deliberate: the tests
 * import `app` directly and must not launch a real Chromium, and a probe that
 * never ran gates nothing.
 */
let capabilityReport = {
  status: STATUS_NOT_RUN,
  required_failures: [],
  notable: [],
  unknown: [],
  running_banner: null,
  checked_at: null,
  ms: null,
  phases: null,
  error: null,
  results: [],
};

/** Resolves when the probe has landed; null while it has never been started. */
let capabilityPromise = null;

/**
 * How long `POST /render/flow` will wait for a probe that has not landed yet,
 * before rendering ungated.
 *
 * Defaults to the probe's own ceiling, so in every normal deployment the gate
 * is in force by the time a request could be affected by it. It exists for the
 * abnormal one: a probe wedged somewhere without a timeout of its own must not
 * take the flow endpoint with it.
 */
const CAPABILITY_WAIT_MS = Number(
  process.env.RENDER_FLOW_CAPABILITY_WAIT_MS || process.env.RENDER_PROBE_TIMEOUT_MS || 60000
);

/** Resolve when the probe lands, or when the wait runs out — whichever first. */
function waitForCapabilityReport() {
  if (!capabilityPromise) {
    return Promise.resolve();
  }

  return Promise.race([
    capabilityPromise.catch(() => {}),
    new Promise((resolve) => {
      // `unref()` so a pending wait can never hold the process open at
      // shutdown; the server closes on SIGTERM regardless of this timer.
      const timer = setTimeout(resolve, CAPABILITY_WAIT_MS);
      if (typeof timer.unref === 'function') {
        timer.unref();
      }
    }),
  ]);
}

/** The browser identity, both readings, for GET /health. */
function browserReport() {
  const runningVersion = versionFromUserAgent(capabilityReport.running_banner);

  return {
    ...RECORDED_BROWSER,
    // What the browser answered when the probe launched it, versus what the
    // build recorded. Two fields that disagree mean the binary under the
    // recorded path is not the one this image installed — a fact a single
    // field cannot state. Same shape as BuildIdentity's frozen-vs-on-disk
    // commit pair on the PHP side.
    running_version: runningVersion,
    running_banner: capabilityReport.running_banner,
    running_matches_build: versionsAgree(RECORDED_BROWSER.version, runningVersion),
  };
}

/**
 * `status` stays `"ok"` whatever the probe found, and that is on purpose.
 *
 * It is the LIVENESS field: the compose healthcheck, the release smoke job and
 * HealthProbe::probeRender() all read this endpoint to answer "is the render
 * tier up", and a container whose browser gained a CSS feature is up. The
 * capability verdict is its own field, where a monitor can look for it
 * deliberately instead of having liveness silently redefined underneath it.
 *
 * The full result matrix is included rather than summarised behind a second
 * endpoint: this is the place the service already reports about itself, it is
 * a couple of kilobytes, and an operator explaining a pagination change should
 * not have to discover a second URL to see what was measured.
 */
app.get('/health', (_req, res) => {
  res.status(200).json({
    status: 'ok',
    ...BUILD_INFO,
    browser: browserReport(),
    capabilities: capabilityReport,
  });
});

app.use('/_harness', express.static(HARNESS_DIR));

/** Constant-time shared-secret check (timing-attack-resistant). */
function isAuthorized(req) {
  if (SHARED_SECRET.length < 32) {
    return false;
  }
  const provided = req.get('x-render-secret') || '';
  const a = Buffer.from(provided, 'utf8');
  const b = Buffer.from(SHARED_SECRET, 'utf8');
  if (a.length !== b.length) {
    return false;
  }
  return crypto.timingSafeEqual(a, b);
}

app.post('/render', renderRateLimiter, async (req, res) => {
  if (!isAuthorized(req)) {
    res.status(401).json({ error: 'Unauthorized' });
    return;
  }

  const validationError = validatePayload(req.body);
  if (validationError) {
    res.status(422).json({ error: validationError });
    return;
  }

  try {
    const harnessUrl = `http://127.0.0.1:${PORT}/_harness/index.html`;
    const pdf = await renderToPdf(req.body, { harnessUrl });
    res.status(200);
    res.set('Content-Type', 'application/pdf');
    res.send(pdf);
  } catch (err) {
    // eslint-disable-next-line no-console
    console.error('[whity_render] render failed:', err && err.stack ? err.stack : err);
    res.status(500).json({ error: 'Render failed' });
  }
});

/**
 * The FLOWING render mode (#1072, first half).
 *
 * A separate route, not a flag on `/render`, because the two take genuinely
 * different bodies: `/render` takes a designer TEMPLATE of positioned
 * elements and prints one page per template page; this takes a CONTENT TREE
 * with no positions and decides for itself how many pages it becomes. A mode
 * flag on one endpoint would mean one validator answering for two payload
 * shapes and one handler branching on it — and the branch that mattered would
 * be the one nobody exercised, because `documents.render_enabled` defaults to
 * false. Separate routes keep `/render`'s request path byte-for-byte what it
 * was.
 *
 * Answers the PDF bytes, plus the pagination facts in response headers: how
 * many pages the document turned out to be, and how many of those are
 * generated front matter. A caller cannot know either in advance — that is
 * the definition of a flowing document — and both are needed to check that
 * what came back is what was asked for.
 *
 * Shares `renderRateLimiter` with `POST /render` rather than getting its own,
 * because the resource being protected is the same one: a single Chromium
 * instance. Two independent windows would let a caller drive twice the
 * concurrent renders by alternating endpoints.
 */
app.post('/render/flow', renderRateLimiter, async (req, res) => {
  if (!isAuthorized(req)) {
    res.status(401).json({ error: 'Unauthorized' });
    return;
  }

  const validationError = validateFlowPayload(req.body);
  if (validationError) {
    res.status(422).json({ error: validationError });
    return;
  }

  // The boot probe is awaited HERE and nowhere else (#1134). The flowing mode
  // is the one caller whose correctness depends on how this browser fragments
  // content, so it is the one that waits for the answer; `/health` reports
  // `pending` meanwhile and `POST /render` never waits at all.
  //
  // The wait is BOUNDED. An unbounded one would turn a probe that hangs into
  // renders that hang, which is the outage this change is not allowed to
  // create; a request that gives up waiting proceeds ungated, because not
  // knowing has never been evidence that anything changed.
  await waitForCapabilityReport();

  if (FLOW_REQUIRES_CAPABILITIES && capabilityReport.status === STATUS_DEGRADED) {
    // Named, not generic: the whole point is that the operator can attribute
    // this to the browser rather than hunting a change in this repository.
    res.status(503).json({
      error:
        'Flow rendering is refused: this browser no longer behaves the way the paginator ' +
        'requires. See GET /health -> capabilities.required_failures.',
      required_failures: capabilityReport.required_failures,
    });
    return;
  }

  try {
    const { pdf, pagination } = await renderFlowToPdf(req.body);
    res.status(200);
    res.set('Content-Type', 'application/pdf');
    res.set('X-Render-Page-Count', String(pagination.pageCount));
    res.set('X-Render-Front-Matter-Pages', String(pagination.frontMatterPages));
    res.set('X-Render-Paginate-Ms', String(pagination.paginateMs));
    res.send(pdf);
  } catch (err) {
    // eslint-disable-next-line no-console
    console.error('[whity_render] flow render failed:', err && err.stack ? err.stack : err);
    res.status(500).json({ error: 'Render failed' });
  }
});

/**
 * Say, once, in the log, which browser this container is running (#1134).
 *
 * The Dockerfile installs `chromium` with no version constraint, so this is
 * the line that turns "the PDFs changed and nothing in git did" into an
 * attributable fact. It is printed even when nothing was recorded, because
 * "this build cannot say" is itself the thing an operator needs to know.
 */
function logRecordedBrowser() {
  if (RECORDED_BROWSER.source === 'unknown') {
    // eslint-disable-next-line no-console
    console.warn(
      '[whity_render] browser: NOT RECORDED — dist/browser-info.json is missing or unreadable. ' +
        'An image build writes it (npm run build:browser-info); a bare checkout does not.'
    );
    return;
  }

  // eslint-disable-next-line no-console
  console.log(
    `[whity_render] browser: ${RECORDED_BROWSER.version}` +
      (RECORDED_BROWSER.package_version ? ` (apt ${RECORDED_BROWSER.package_version})` : '') +
      ` at ${RECORDED_BROWSER.executable}, recorded ${RECORDED_BROWSER.recorded_at}`
  );
}

/**
 * Kick off the capability probe WITHOUT blocking `listen()`.
 *
 * The browser launch dominates the probe's cost and is entirely
 * environment-dependent (sub-second on a Linux host; tens of seconds on a
 * Docker Desktop VM waiting out dbus timeouts), so nothing is allowed to wait
 * on it that does not have to. `/health` answers immediately with
 * `capabilities.status: "pending"`.
 *
 * WHAT HAPPENS WHEN THE PROBE ITSELF CANNOT RUN
 *
 * The service starts anyway, `status` becomes `"error"`, and NOTHING is gated.
 * That is a decision, not an oversight. The probe is a detector; a detector
 * that can stop the service converts a diagnostic into an outage, and a crash
 * loop is strictly harder to diagnose than a running container whose /health
 * says the probe could not run. A browser that genuinely cannot launch already
 * fails every render with a logged stack and a 500 — pre-empting that with a
 * dead container adds no information and removes the endpoint you would use to
 * find out.
 */
function startCapabilityProbe() {
  capabilityReport = { ...capabilityReport, status: STATUS_PENDING };

  capabilityPromise = runCapabilityProbe()
    .then((report) => {
      capabilityReport = report;
      logCapabilityReport(report);
      return report;
    })
    .catch((err) => {
      // runCapabilityProbe() does not reject; this is belt-and-braces so a
      // future refactor cannot turn a probe bug into an unhandled rejection.
      capabilityReport = {
        ...capabilityReport,
        status: STATUS_ERROR,
        error: String((err && err.message) || err),
      };
      // eslint-disable-next-line no-console
      console.error('[whity_render] capability probe threw:', err);
      return capabilityReport;
    });

  return capabilityPromise;
}

function logCapabilityReport(report) {
  /* eslint-disable no-console */
  if (report.status === STATUS_ERROR) {
    console.error(
      `[whity_render] capability probe COULD NOT RUN (${report.ms} ms): ${report.error}. ` +
        'The service is up and nothing is gated; renders may still fail for the same reason.'
    );
    return;
  }

  console.log(
    `[whity_render] capability probe: ${report.status} in ${report.ms} ms` +
      (report.phases ? ` (launch ${report.phases.launch_ms} ms of it)` : '') +
      (report.running_banner ? ` — ${report.running_banner}` : '') +
      (report.unknown.length > 0 ? `; could not determine: ${report.unknown.join(', ')}` : '')
  );

  for (const line of report.notable) {
    console.warn(`[whity_render] browser capability CHANGED — ${line}`);
  }

  if (report.notable.length > 0) {
    console.warn(
      '[whity_render] Nothing is broken by the above and nothing is gated on it. A capability ' +
        'that ARRIVED may make the in-page paginator unnecessary; see #1134 and ' +
        'docs/wiki/Document-Render-Service.md.'
    );
  }

  if (report.status === STATUS_DEGRADED) {
    console.error(
      '[whity_render] ' + '='.repeat(70) + '\n' +
        '[whity_render] THIS BROWSER NO LONGER BEHAVES THE WAY THE PAGINATOR REQUIRES.\n' +
        report.required_failures.map((line) => `[whity_render]   - ${line}`).join('\n') +
        '\n[whity_render] POST /render/flow is refused with 503' +
        (FLOW_REQUIRES_CAPABILITIES ? '' : ' — EXCEPT that the gate is disabled by RENDER_FLOW_REQUIRE_CAPABILITIES=false') +
        '.\n[whity_render] The fixed-canvas POST /render is NOT gated; check its output by hand.\n' +
        '[whity_render] ' + '='.repeat(70)
    );
  }
  /* eslint-enable no-console */
}

function start() {
  logRecordedBrowser();

  const server = app.listen(PORT, () => {
    // eslint-disable-next-line no-console
    console.log(`[whity_render] listening on :${PORT}`);
  });

  startCapabilityProbe();

  const stop = async (signal) => {
    // eslint-disable-next-line no-console
    console.log(`[whity_render] received ${signal}, shutting down`);
    server.close();
    await shutdown();
    process.exit(0);
  };
  process.on('SIGTERM', () => stop('SIGTERM'));
  process.on('SIGINT', () => stop('SIGINT'));

  return server;
}

if (require.main === module) {
  start();
}

// `startCapabilityProbe` is exported so tests can drive the gate with a mocked
// probe instead of a real Chromium — the gate is the part of #1134 that can
// refuse traffic, so it is the part that most needs proving.
module.exports = { app, start, startCapabilityProbe };
