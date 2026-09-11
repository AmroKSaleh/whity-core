/**
 * Tests for the external watchdog.
 *
 * A MONITOR IS THE WORST THING TO LEAVE UNTESTED, because its failure mode is
 * silence and silence is what it looks like when everything is fine. Every
 * assertion below was checked against a deliberately broken version of the
 * code first — a test that passes with the behaviour removed is testing
 * nothing, which is the shape of bug this whole watchdog was written after.
 *
 * Run with: node --test ops/watchdog/test/
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { __test__ } from '../src/index.js';

const { humanDuration, timingSafeEqual, config, checkTarget, checkHeartbeats, handleRequest, renderStatusPage } = __test__;

// ── a KV stand-in ───────────────────────────────────────────────────────────

function makeEnv(overrides = {}) {
  const store = new Map();
  const sent = [];

  return {
    env: {
      WATCHDOG_STATE: {
        async get(key, opts) {
          const raw = store.get(key);
          if (raw === undefined) return null;
          return opts?.type === 'json' ? JSON.parse(raw) : raw;
        },
        async put(key, value) {
          store.set(key, value);
        },
      },
      TELEGRAM_BOT_TOKEN: 'test-token',
      TELEGRAM_CHAT_ID: '1234',
      HEARTBEAT_SECRET: 'shhh',
      ...overrides,
    },
    store,
    sent,
  };
}

/**
 * Match the Telegram API by HOSTNAME, not by substring.
 *
 * `String(url).includes('api.telegram.org')` also matches
 * `https://example.test/?x=api.telegram.org`, so a probe target that merely
 * mentioned the host would be answered by the Telegram stub and the test would
 * quietly assert the wrong thing. CodeQL flags this as incomplete URL
 * sanitization and is right to.
 */
function isTelegram(url) {
  try {
    return new URL(String(url)).hostname === 'api.telegram.org';
  } catch {
    return false;
  }
}

/** Capture Telegram sends without touching the network. */
function captureAlerts(sent) {
  globalThis.fetch = async (url, init) => {
    if (isTelegram(url)) {
      sent.push(JSON.parse(init.body).text);
      return new Response('{"ok":true}', { status: 200 });
    }
    throw new Error(`unexpected fetch to ${url}`);
  };
}

/** Make the next probe of `url` return a given status, or throw. */
function stubProbe(sent, behaviour) {
  globalThis.fetch = async (url, init) => {
    if (isTelegram(url)) {
      sent.push(JSON.parse(init.body).text);
      return new Response('{"ok":true}', { status: 200 });
    }
    if (behaviour === 'throw') throw new Error('connection refused');
    return new Response('body', { status: behaviour });
  };
}

const CFG = { failuresBeforeAlert: 2, probeTimeoutMs: 500, backupMaxAgeHours: 26, summaryIntervalHours: 168 };
const TARGET = { name: 'api', url: 'https://example.test/api/health' };

// ── the thing that actually broke ───────────────────────────────────────────

test('a 503 from /api/health counts as DOWN, not as a reachable service', async () => {
  const { env, sent } = makeEnv();
  stubProbe(sent, 503);

  const first = await checkTarget(env, CFG, TARGET);
  assert.equal(first.ok, false, '503 must not be treated as healthy');
});

test('one failure is not enough to alert; two consecutive failures are', async () => {
  const { env, sent } = makeEnv();
  stubProbe(sent, 503);

  await checkTarget(env, CFG, TARGET);
  assert.equal(sent.length, 0, 'a single blip must not page anyone');

  await checkTarget(env, CFG, TARGET);
  assert.equal(sent.length, 1, 'the second consecutive failure must alert');
  assert.match(sent[0], /is DOWN/);
  assert.match(sent[0], /HTTP 503/, 'the alert must carry the status that caused it');
});

test('a sustained outage alerts ONCE, not on every tick', async () => {
  const { env, sent } = makeEnv();
  stubProbe(sent, 503);

  for (let i = 0; i < 10; i++) await checkTarget(env, CFG, TARGET);

  assert.equal(sent.length, 1, 'repeating the same alert is how a monitor gets muted');
});

test('recovery is announced, and reports the outage from its FIRST failure', async () => {
  const { env, sent } = makeEnv();
  stubProbe(sent, 503);

  await checkTarget(env, CFG, TARGET);
  await checkTarget(env, CFG, TARGET);
  sent.length = 0;

  stubProbe(sent, 200);
  await checkTarget(env, CFG, TARGET);

  assert.equal(sent.length, 1);
  assert.match(sent[0], /recovered/);
  assert.match(sent[0], /Down for/, 'an outage report without a duration is half an answer');
});

test('an intermittent failure does not latch: one failure then success stays quiet', async () => {
  const { env, sent } = makeEnv();
  stubProbe(sent, 503);
  await checkTarget(env, CFG, TARGET);

  stubProbe(sent, 200);
  await checkTarget(env, CFG, TARGET);

  assert.equal(sent.length, 0, 'no alert was sent, so no recovery notice is owed either');
});

test('an unreachable host is DOWN and says so distinctly from an error status', async () => {
  const { env, sent } = makeEnv();
  stubProbe(sent, 'throw');

  await checkTarget(env, CFG, TARGET);
  await checkTarget(env, CFG, TARGET);

  assert.equal(sent.length, 1);
  assert.match(sent[0], /unreachable/, 'refused and 503 send an operator to different places');
});

// ── the dead-man's switch ───────────────────────────────────────────────────

test('a backup that has NEVER reported is distinguished from one that went stale', async () => {
  const { env, sent } = makeEnv();
  captureAlerts(sent);

  await checkHeartbeats(env, CFG);

  assert.equal(sent.length, 1);
  // The distinction is the point, so assert BOTH halves of it: this message
  // must say "never", and must NOT use the wording reserved for a job that was
  // working and stopped. Matching one phrase would pass if both states
  // collapsed to the same text, which is the bug this test exists to catch.
  assert.match(sent[0], /never/, 'a job that never ran must say so');
  assert.doesNotMatch(sent[0], /overdue/, 'never-ran and went-stale send an operator on different hunts');
});

test('silence past the window raises the alarm — the failure a report-reader cannot see', async () => {
  const { env, store, sent } = makeEnv();
  captureAlerts(sent);

  const twentySevenHoursAgo = Date.now() - 27 * 3600 * 1000;
  store.set('beat:backup', JSON.stringify({ at: twentySevenHoursAgo }));

  await checkHeartbeats(env, CFG);

  assert.equal(sent.length, 1);
  assert.match(sent[0], /overdue/);
});

test('a fresh backup is silent, and alerts once when it later goes overdue', async () => {
  const { env, store, sent } = makeEnv();
  captureAlerts(sent);

  store.set('beat:backup', JSON.stringify({ at: Date.now() - 3600 * 1000 }));
  await checkHeartbeats(env, CFG);
  assert.equal(sent.length, 0, 'a healthy backup must say nothing');

  store.set('beat:backup', JSON.stringify({ at: Date.now() - 30 * 3600 * 1000 }));
  await checkHeartbeats(env, CFG);
  await checkHeartbeats(env, CFG);
  assert.equal(sent.length, 1, 'overdue alerts once, not on every run');
});

test('a FIRST-EVER report is not announced as "reporting again"', async () => {
  const { env, store, sent } = makeEnv();
  captureAlerts(sent);

  // Never reported -> the "has never reported" warning.
  await checkHeartbeats(env, CFG);
  assert.match(sent[0], /never/);
  sent.length = 0;

  // Now it reports for the very first time. It was not working and did not
  // stop, so "again" would be a false account of what happened — and the
  // distinction the warning drew must survive into the all-clear.
  store.set('beat:backup', JSON.stringify({ at: Date.now() }));
  await checkHeartbeats(env, CFG);

  assert.equal(sent.length, 1);
  assert.match(sent[0], /first time/);
  assert.doesNotMatch(sent[0], /again/, 'it had never started, so it cannot be starting again');
});

test('a backup that starts reporting again is announced', async () => {
  const { env, store, sent } = makeEnv();
  captureAlerts(sent);

  store.set('beat:backup', JSON.stringify({ at: Date.now() - 30 * 3600 * 1000 }));
  await checkHeartbeats(env, CFG);
  sent.length = 0;

  store.set('beat:backup', JSON.stringify({ at: Date.now() }));
  await checkHeartbeats(env, CFG);

  assert.equal(sent.length, 1);
  assert.match(sent[0], /reporting again/);
});

// ── configuration ───────────────────────────────────────────────────────────

test('an unparseable PROBE_TARGETS yields no targets rather than a crash', () => {
  const cfg = config({ PROBE_TARGETS: 'not json' });
  assert.deepEqual(cfg.targets, []);
});

test('malformed targets are dropped, well-formed ones survive', () => {
  const cfg = config({
    PROBE_TARGETS: JSON.stringify([{ name: 'ok', url: 'https://x.test' }, { name: 'no-url' }, null]),
  });
  assert.equal(cfg.targets.length, 1);
  assert.equal(cfg.targets[0].name, 'ok');
});

test('a nonsense numeric var falls back to the default instead of disabling a check', () => {
  // NaN compares false against everything, so a typo here would silently mean
  // "never alert" — the failure mode this whole component exists to prevent.
  const cfg = config({ FAILURES_BEFORE_ALERT: 'two' });
  assert.equal(cfg.failuresBeforeAlert, 2);

  const negative = config({ BACKUP_MAX_AGE_HOURS: '-5' });
  assert.equal(negative.backupMaxAgeHours, 26);
});

// ── the heartbeat secret ────────────────────────────────────────────────────

test('heartbeat comparison rejects wrong secrets, including prefixes', () => {
  assert.equal(timingSafeEqual('abc123', 'abc123'), true);
  assert.equal(timingSafeEqual('abc123', 'abc124'), false);
  assert.equal(timingSafeEqual('abc', 'abc123'), false, 'a prefix must not authenticate');
  assert.equal(timingSafeEqual('', ''), true);
  assert.equal(timingSafeEqual(undefined, 'abc'), false);
});

// ── the alert hub ───────────────────────────────────────────────────────────
//
// Added after 2026-09-10, when two critical unauthenticated RCE advisories were
// found live on the public deployment. No probe could have seen them — a
// vulnerable server answers 200 exactly like a patched one — so something
// outside the Worker has to be able to raise an alarm through it.

test('an authenticated caller can raise an alert', async () => {
  const { env, sent } = makeEnv();
  captureAlerts(sent);

  const res = await handleRequest(
    new Request('https://w.test/alert', {
      method: 'POST',
      headers: { authorization: 'Bearer shhh', 'content-type': 'application/json' },
      body: JSON.stringify({ source: 'npm audit', text: 'next 16.3.1: 2 critical advisories' }),
    }),
    env
  );

  assert.equal(res.status, 200);
  assert.deepEqual(await res.json(), { delivered: true });
  assert.equal(sent.length, 1);
  assert.match(sent[0], /npm audit/, 'the alert must say who raised it');
  assert.match(sent[0], /2 critical advisories/);
});

test('a Telegram rejection is reported as a failure, not as "sent"', async () => {
  const { env, sent } = makeEnv();

  // Telegram answers HTTP 200 with `ok: false` for a bad chat id, a revoked
  // token, or a bot the user has blocked. Treating that as delivered would
  // make this endpoint claim success without checking — the exact failure the
  // watchdog exists to end, reproduced inside it.
  globalThis.fetch = async () =>
    new Response(JSON.stringify({ ok: false, description: 'Bad Request: chat not found' }), { status: 200 });

  const res = await handleRequest(
    new Request('https://w.test/alert', {
      method: 'POST',
      headers: { authorization: 'Bearer shhh', 'content-type': 'application/json' },
      body: JSON.stringify({ source: 'ci', text: 'something broke' }),
    }),
    env
  );

  assert.equal(res.status, 502, 'a caller using curl --fail must find out');
  const body = await res.json();
  assert.equal(body.delivered, false);
  assert.match(body.reason, /chat not found/, 'the reason must survive to the caller');
  assert.equal(sent.length, 0);
});

test('an unconfigured Telegram is reported rather than silently dropped', async () => {
  const { env } = makeEnv({ TELEGRAM_CHAT_ID: '' });

  const res = await handleRequest(
    new Request('https://w.test/alert', {
      method: 'POST',
      headers: { authorization: 'Bearer shhh', 'content-type': 'application/json' },
      body: JSON.stringify({ source: 'ci', text: 'something broke' }),
    }),
    env
  );

  assert.equal(res.status, 502);
  assert.match((await res.json()).reason, /not configured/);
});

test('an UNAUTHENTICATED caller cannot raise an alert', async () => {
  const { env, sent } = makeEnv();
  captureAlerts(sent);

  for (const headers of [{}, { authorization: 'Bearer wrong' }, { authorization: 'Bearer shh' }]) {
    const res = await handleRequest(
      new Request('https://w.test/alert', {
        method: 'POST',
        headers: { ...headers, 'content-type': 'application/json' },
        body: JSON.stringify({ source: 'spam', text: 'ignore me' }),
      }),
      env
    );
    assert.equal(res.status, 401);
  }

  // An open alert endpoint is worse than none: anyone who found the URL could
  // drown the real alarms in noise until they are muted.
  assert.equal(sent.length, 0, 'nothing must reach Telegram without the secret');
});

test('an alert with no text is refused rather than sent empty', async () => {
  const { env, sent } = makeEnv();
  captureAlerts(sent);

  const res = await handleRequest(
    new Request('https://w.test/alert', {
      method: 'POST',
      headers: { authorization: 'Bearer shhh', 'content-type': 'application/json' },
      body: JSON.stringify({ source: 'ci' }),
    }),
    env
  );

  assert.equal(res.status, 400);
  assert.equal(sent.length, 0);
});

test('an authenticated heartbeat is recorded; an unauthenticated one is not', async () => {
  const { env, store, sent } = makeEnv();
  captureAlerts(sent);

  const bad = await handleRequest(
    new Request('https://w.test/beat/backup', { method: 'POST', headers: { authorization: 'Bearer nope' } }),
    env
  );
  assert.equal(bad.status, 401);
  assert.equal(store.has('beat:backup'), false, 'an open endpoint would let anyone silence the backup alarm');

  const ok = await handleRequest(
    new Request('https://w.test/beat/backup', { method: 'POST', headers: { authorization: 'Bearer shhh' } }),
    env
  );
  assert.equal(ok.status, 200);
  assert.equal(store.has('beat:backup'), true);
});

// ── the public status page ──────────────────────────────────────────────────
//
// Its one job is to be believable. A status page that claims health from data
// it stopped collecting is the September outage again, better presented — so
// the staleness tests below matter more than the happy path.

const PAGE_CFG = { ...CFG, targets: [{ name: 'api', url: 'https://x.test/api/health' }] };

async function pageText(env, cfg = PAGE_CFG) {
  const res = await renderStatusPage(env, cfg);
  return { res, html: await res.text() };
}

test('a healthy, freshly-checked deployment reads as operational', async () => {
  const { env, store } = makeEnv();
  store.set('meta:last-run', JSON.stringify({ at: Date.now() - 60_000 }));
  store.set('state:api', JSON.stringify({ alertedStatus: 'up' }));
  store.set('beat:backup', JSON.stringify({ at: Date.now() - 3600 * 1000 }));

  const { res, html } = await pageText(env);
  assert.equal(res.status, 200);
  assert.match(html, /All systems operational/);
  assert.match(html, /Healthy/);
});

test('STALE DATA IS NEVER REPORTED AS OPERATIONAL', async () => {
  const { env, store } = makeEnv();
  // Everything it last saw was fine — and it stopped looking an hour ago.
  store.set('meta:last-run', JSON.stringify({ at: Date.now() - 3600 * 1000 }));
  store.set('state:api', JSON.stringify({ alertedStatus: 'up' }));
  store.set('beat:backup', JSON.stringify({ at: Date.now() }));

  const { html } = await pageText(env);
  assert.doesNotMatch(html, /All systems operational/, 'old readings must not be presented as current');
  assert.match(html, /Status unknown/);
  assert.match(html, /not current/);
});

test('a watchdog that has never run says so instead of showing green', async () => {
  const { env } = makeEnv();
  const { html } = await pageText(env);
  assert.doesNotMatch(html, /All systems operational/);
  assert.match(html, /Status unknown/);
});

test('one component down is a partial outage; all down is a major one', async () => {
  const twoTargets = { ...CFG, targets: [{ name: 'api', url: 'https://x.test/a' }, { name: 'web', url: 'https://x.test/b' }] };

  const partial = makeEnv();
  partial.store.set('meta:last-run', JSON.stringify({ at: Date.now() }));
  partial.store.set('state:api', JSON.stringify({ alertedStatus: 'down', downSince: Date.now() - 600_000 }));
  partial.store.set('state:web', JSON.stringify({ alertedStatus: 'up' }));
  const p = await pageText(partial.env, twoTargets);
  assert.match(p.html, /Partial outage/);
  assert.match(p.html, /for 10m/, 'an outage without its duration is half an answer');

  const major = makeEnv();
  major.store.set('meta:last-run', JSON.stringify({ at: Date.now() }));
  major.store.set('state:api', JSON.stringify({ alertedStatus: 'down', downSince: Date.now() }));
  major.store.set('state:web', JSON.stringify({ alertedStatus: 'down', downSince: Date.now() }));
  const m = await pageText(major.env, twoTargets);
  assert.match(m.html, /Major outage/);
});

test('an overdue backup shows as overdue, not merely absent', async () => {
  const { env, store } = makeEnv();
  store.set('meta:last-run', JSON.stringify({ at: Date.now() }));
  store.set('state:api', JSON.stringify({ alertedStatus: 'up' }));
  store.set('beat:backup', JSON.stringify({ at: Date.now() - 40 * 3600 * 1000 }));

  const { html } = await pageText(env);
  assert.match(html, /Overdue/);
});

test('the page is never cached — a stale green outliving its outage is the bug', async () => {
  const { env, store } = makeEnv();
  store.set('meta:last-run', JSON.stringify({ at: Date.now() }));
  const { res } = await pageText(env);
  assert.match(res.headers.get('cache-control') || '', /no-store/);
});

test('internal key names never reach the page', async () => {
  const { env, store } = makeEnv();
  store.set('meta:last-run', JSON.stringify({ at: Date.now() }));
  store.set('state:api', JSON.stringify({ alertedStatus: 'up' }));

  const { html } = await pageText(env);
  assert.doesNotMatch(html, /state:api|beat:backup|meta:last-run/, 'KV keys are not a user-facing vocabulary');
  assert.doesNotMatch(html, /HEARTBEAT_SECRET|TELEGRAM/i, 'nothing secret may appear on a public page');
  assert.match(html, /API/, 'components get human labels');
});

// ── formatting ──────────────────────────────────────────────────────────────

test('durations read the way a person would say them', () => {
  assert.equal(humanDuration(45_000), '45s');
  assert.equal(humanDuration(90_000), '2m');
  assert.equal(humanDuration(3 * 3600 * 1000), '3h');
  assert.equal(humanDuration(3.5 * 3600 * 1000), '3h 30m');
  assert.equal(humanDuration(50 * 3600 * 1000), '2d 2h');
});
