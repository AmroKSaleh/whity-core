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
 *                   the box — see scripts/write-build-info.js.
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

const PORT = Number(process.env.PORT || 8130);
const SHARED_SECRET = process.env.RENDER_SHARED_SECRET || '';
const HARNESS_DIR = path.join(__dirname, '..', 'dist', 'harness');

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

app.get('/health', (_req, res) => {
  res.status(200).json({ status: 'ok', ...BUILD_INFO });
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

function start() {
  const server = app.listen(PORT, () => {
    // eslint-disable-next-line no-console
    console.log(`[whity_render] listening on :${PORT}`);
  });

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

module.exports = { app, start };
