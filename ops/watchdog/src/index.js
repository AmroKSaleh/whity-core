/**
 * The external watchdog — a Cloudflare Worker that watches a Whity deployment
 * from OUTSIDE it.
 *
 * WHY THIS EXISTS, AND WHY IT CANNOT LIVE ON THE BOX.
 * --------------------------------------------------
 * whity-core already ships a health collector (`health:watch`) and a public
 * status page, and on 2026-09-09 they failed in the only way that mattered: a
 * host restart left the database down, the collector DID detect it and logged
 * every failed pass for three hours — and could tell nobody, because the only
 * place it can write is the database that was missing, and the status page
 * reads from that same database.
 *
 * A monitor whose escalation path shares a failure domain with the thing it
 * monitors cannot report that thing failing. No amount of work inside the
 * deployment fixes that; the alarm has to be rung from somewhere the outage
 * cannot reach. That is this Worker's whole reason for being: it runs on
 * Cloudflare's edge, holds its state in KV, and touches nothing on the host.
 *
 * WHAT IT WATCHES
 * ---------------
 *  1. LIVENESS, by probing the deployment over the public internet. It probes
 *     BOTH tiers, because the 2026-09-09 outage was invisible from one of them:
 *     the Next.js tier happily answered 200 for three hours while every API
 *     call behind it failed. Probing only the homepage would have reported a
 *     healthy site throughout the outage. `/api/health` is the honest probe —
 *     it returns 503 with `db_connected: false` when the database is
 *     unreachable — and reporting the two separately makes the alert itself
 *     diagnostic: "web up, api down" names the failure on arrival.
 *
 *  2. BACKUP FRESHNESS, as a DEAD-MAN'S SWITCH. The backup job pings this
 *     Worker after each success; the alarm fires on SILENCE. This is the only
 *     shape that catches the actual 2026-09-09 failure, where the scheduled
 *     backup did not run at all: a check that inspects a report cannot notice a
 *     report that was never made, so absence has to be the trigger.
 *
 * ALERTS FIRE ON TRANSITIONS, NOT ON TICKS. A monitor that repeats itself every
 * two minutes gets muted, and a muted monitor is an absent one. This alerts
 * when a target goes down, and again when it recovers (with how long it was
 * out) — and says nothing in between.
 *
 * NOTHING HERE IS HARDCODED that a deployment might reasonably want different:
 * probe targets, the consecutive-failure threshold, timeouts, the backup
 * staleness window and the liveness-summary interval are all configuration,
 * with defaults chosen to be sensible rather than mandatory.
 */

/**
 * Defaults for every tunable. Overridable per deployment via `[vars]` in
 * wrangler.toml, so a deployment with a slower link or a twice-daily backup
 * changes configuration rather than code.
 */
const DEFAULTS = {
  // Two consecutive failures before crying wolf. One probe can fail for
  // reasons that are nobody's problem — a dropped packet, an edge hiccup, a
  // worker restarting mid-request. With a 2-minute cron this still means the
  // alert lands within about five minutes of a real outage, against three
  // hours of silence for the incident this was built after.
  failuresBeforeAlert: 2,

  // A probe that hangs must not hold the whole run. Well under the cron
  // interval, so a stuck target cannot delay the checks that follow it.
  probeTimeoutMs: 10000,

  // Nightly backup plus slack for a late start. Matches the 26h threshold the
  // repo's existing WhityBackupStale Prometheus rule already uses — one number
  // to reason about, not two that drift apart.
  backupMaxAgeHours: 26,

  // A weekly "still watching" note. WHY AT ALL: every check here is silent
  // when healthy, which is indistinguishable from a Worker whose cron trigger
  // was removed, whose account expired, or that was never deployed. Without a
  // positive signal, this monitor's own death is silent — the exact failure it
  // was built to end. Weekly is rare enough not to become noise.
  summaryIntervalHours: 168,
};

export default {
  async scheduled(event, env, ctx) {
    ctx.waitUntil(runChecks(env));
  },

  async fetch(request, env) {
    return handleRequest(request, env);
  },
};

// ── configuration ───────────────────────────────────────────────────────────

function config(env) {
  const num = (name, fallback) => {
    const raw = env[name];
    if (raw === undefined || raw === null || raw === '') return fallback;
    const parsed = Number(raw);
    // A typo in a var must not silently disable a check by parsing to NaN and
    // comparing false against everything.
    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
  };

  let targets;
  try {
    targets = JSON.parse(env.PROBE_TARGETS || '[]');
  } catch {
    targets = [];
  }

  return {
    targets: Array.isArray(targets) ? targets.filter((t) => t && t.name && t.url) : [],
    failuresBeforeAlert: num('FAILURES_BEFORE_ALERT', DEFAULTS.failuresBeforeAlert),
    probeTimeoutMs: num('PROBE_TIMEOUT_MS', DEFAULTS.probeTimeoutMs),
    backupMaxAgeHours: num('BACKUP_MAX_AGE_HOURS', DEFAULTS.backupMaxAgeHours),
    summaryIntervalHours: num('SUMMARY_INTERVAL_HOURS', DEFAULTS.summaryIntervalHours),
  };
}

// ── the scheduled run ───────────────────────────────────────────────────────

async function runChecks(env) {
  const cfg = config(env);

  if (cfg.targets.length === 0) {
    // Loud rather than quiet: a watchdog with nothing to watch looks identical
    // to a healthy one, and would report success forever.
    console.error('watchdog: PROBE_TARGETS is empty or unparseable — nothing is being watched');
  }

  const results = [];
  for (const target of cfg.targets) {
    results.push(await checkTarget(env, cfg, target));
  }

  await checkHeartbeats(env, cfg);
  await maybeSendSummary(env, cfg, results);
}

/**
 * Probe one target and alert only when its state CHANGES.
 */
async function checkTarget(env, cfg, target) {
  const key = `state:${target.name}`;
  const state = (await readJson(env, key)) || {
    alertedStatus: 'up',
    consecutiveFailures: 0,
    downSince: null,
  };

  const probe = await probeOnce(target.url, cfg.probeTimeoutMs, target.expectStatus);

  if (probe.ok) {
    if (state.alertedStatus === 'down') {
      const outage = state.downSince ? humanDuration(Date.now() - state.downSince) : 'an unknown period';
      await notify(
        env,
        `✅ *${target.name} recovered*\n${target.url}\nDown for ${outage}.`
      );
    }
    await writeJson(env, key, {
      alertedStatus: 'up',
      consecutiveFailures: 0,
      downSince: null,
      lastOkAt: Date.now(),
    });
    return { name: target.name, ok: true };
  }

  const failures = (state.consecutiveFailures || 0) + 1;
  // The moment of the FIRST failure, not of the alert. Otherwise every reported
  // outage is short by the threshold window, which is the one number an
  // operator reads the alert to learn.
  const downSince = state.downSince || Date.now();

  const shouldAlert = failures >= cfg.failuresBeforeAlert && state.alertedStatus !== 'down';

  await writeJson(env, key, {
    alertedStatus: shouldAlert ? 'down' : state.alertedStatus,
    consecutiveFailures: failures,
    downSince,
    lastOkAt: state.lastOkAt ?? null,
  });

  if (shouldAlert) {
    await notify(
      env,
      `🔴 *${target.name} is DOWN*\n${target.url}\n${probe.detail}\n` +
        `Failed ${failures} checks in a row.`
    );
  }

  return { name: target.name, ok: false, detail: probe.detail };
}

/**
 * A single HTTP probe.
 *
 * Treats a connection failure and an error status as the same kind of event —
 * both mean "a user asking for this right now does not get it" — but reports
 * them differently, because "connection refused" and "503 degraded" send an
 * operator to different places.
 */
async function probeOnce(url, timeoutMs, expectStatus) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetch(url, {
      signal: controller.signal,
      redirect: 'manual',
      headers: { 'user-agent': 'whity-watchdog/1' },
      // The point is to observe the origin, not Cloudflare's memory of it.
      cf: { cacheTtl: 0, cacheEverything: false },
    });

    const expected = expectStatus || 200;
    if (response.status !== expected) {
      let hint = '';
      try {
        const body = await response.text();
        if (body) hint = ` — ${body.slice(0, 200)}`;
      } catch {
        // A body we cannot read does not make the status less true.
      }
      return { ok: false, detail: `HTTP ${response.status} (expected ${expected})${hint}` };
    }

    return { ok: true };
  } catch (error) {
    const reason = error?.name === 'AbortError' ? `no response in ${timeoutMs}ms` : String(error?.message || error);
    return { ok: false, detail: `unreachable: ${reason}` };
  } finally {
    clearTimeout(timer);
  }
}

// ── the dead-man's switch ───────────────────────────────────────────────────

/**
 * Alert on SILENCE from a job that is supposed to report in.
 *
 * Two distinct states, deliberately, mirroring the repo's existing
 * WhityBackupStale / WhityBackupMetricMissing split: a job that HAS reported
 * and then stopped is a job that broke, while one that has NEVER reported is
 * usually one that was never wired up. Collapsing them into "stale" sends an
 * operator hunting for a regression in something that never ran.
 */
async function checkHeartbeats(env, cfg) {
  const name = 'backup';
  const beat = await readJson(env, `beat:${name}`);
  const stateKey = `beatstate:${name}`;
  const state = (await readJson(env, stateKey)) || { alertedStatus: 'ok' };

  const maxAgeMs = cfg.backupMaxAgeHours * 3600 * 1000;

  if (!beat || !beat.at) {
    if (state.alertedStatus !== 'missing') {
      await notify(
        env,
        `⚠️ *No ${name} has ever reported in*\n` +
          `The watchdog has never received a ${name} heartbeat, so either the job is not ` +
          `wired up to send one or it has never succeeded.`
      );
      await writeJson(env, stateKey, { alertedStatus: 'missing' });
    }
    return;
  }

  const age = Date.now() - beat.at;

  if (age > maxAgeMs) {
    if (state.alertedStatus !== 'stale') {
      await notify(
        env,
        `🔴 *${name} is overdue*\n` +
          `Last success ${humanDuration(age)} ago (expected within ${cfg.backupMaxAgeHours}h).\n` +
          `The job did not run, or ran and failed.`
      );
      await writeJson(env, stateKey, { alertedStatus: 'stale' });
    }
    return;
  }

  if (state.alertedStatus !== 'ok') {
    await notify(env, `✅ *${name} is reporting again*\nLast success ${humanDuration(age)} ago.`);
    await writeJson(env, stateKey, { alertedStatus: 'ok' });
  }
}

// ── the liveness summary ────────────────────────────────────────────────────

/**
 * Proof that the watchdog itself is alive. See DEFAULTS.summaryIntervalHours
 * for why a monitor that is silent when healthy needs one.
 */
async function maybeSendSummary(env, cfg, results) {
  const key = 'meta:last-summary';
  const last = await readJson(env, key);
  const dueAfterMs = cfg.summaryIntervalHours * 3600 * 1000;

  if (last && last.at && Date.now() - last.at < dueAfterMs) return;

  // Do not announce "all is well" on the first run after deploy, before a
  // single check has happened — that would be a claim with nothing behind it.
  if (!last) {
    await writeJson(env, key, { at: Date.now() });
    return;
  }

  const beat = await readJson(env, 'beat:backup');
  const lines = results.map((r) => `${r.ok ? '✅' : '🔴'} ${r.name}`).join('\n');
  const backupLine = beat?.at
    ? `✅ backup — last success ${humanDuration(Date.now() - beat.at)} ago`
    : '⚠️ backup — never reported';

  await notify(env, `👋 *Watchdog still watching*\n${lines}\n${backupLine}`);
  await writeJson(env, key, { at: Date.now() });
}

// ── the HTTP surface ────────────────────────────────────────────────────────

async function handleRequest(request, env) {
  const url = new URL(request.url);

  // POST /beat/<name> — a job reporting a success.
  //
  // AUTHENTICATED, and that is not ceremony. An open heartbeat endpoint lets
  // anybody who finds the URL silence the backup alarm forever by sending a
  // beat, which turns the safety net into a thing that reports success while
  // nothing is being backed up.
  if (request.method === 'POST' && url.pathname.startsWith('/beat/')) {
    const provided = (request.headers.get('authorization') || '').replace(/^Bearer\s+/i, '');
    if (!env.HEARTBEAT_SECRET || !timingSafeEqual(provided, env.HEARTBEAT_SECRET)) {
      return new Response('unauthorized\n', { status: 401 });
    }

    const name = url.pathname.slice('/beat/'.length).replace(/[^a-z0-9_-]/gi, '');
    if (!name) return new Response('bad heartbeat name\n', { status: 400 });

    await writeJson(env, `beat:${name}`, { at: Date.now() });
    return new Response('ok\n', { status: 200 });
  }

  // POST /alert — let anything outside this Worker raise an alarm through it.
  //
  // WHY THIS EXISTS. On 2026-09-10 two critical unauthenticated RCE advisories
  // were found live on the public deployment. Nothing detected them: they were
  // published against a dependency version that had not changed, so no build
  // ran, no test failed, and no probe could see it — a vulnerable server
  // answers 200 exactly like a patched one. They surfaced only because an
  // unrelated pull request happened to run `npm audit`.
  //
  // A liveness probe structurally cannot catch that. What can is a scheduled
  // job re-checking known advisories against unchanged code — and that job
  // needs somewhere to shout. This endpoint is that somewhere, so there is one
  // alerting path to configure and audit rather than one per source.
  if (request.method === 'POST' && url.pathname === '/alert') {
    const provided = (request.headers.get('authorization') || '').replace(/^Bearer\s+/i, '');
    if (!env.HEARTBEAT_SECRET || !timingSafeEqual(provided, env.HEARTBEAT_SECRET)) {
      return new Response('unauthorized\n', { status: 401 });
    }

    let payload;
    try {
      payload = await request.json();
    } catch {
      return new Response('expected a JSON body\n', { status: 400 });
    }

    const source = String(payload?.source ?? 'unknown').slice(0, 60);
    const text = String(payload?.text ?? '').slice(0, 3000);
    if (!text) return new Response('an alert with no text says nothing\n', { status: 400 });

    // Deliberately NOT deduplicated the way probe alerts are. A caller here has
    // already decided this is worth sending; suppressing it on the Worker's own
    // judgement would drop the one message somebody needed.
    const result = await notify(env, `🚨 *${source}*\n${text}`);

    // REPORT WHAT ACTUALLY HAPPENED. Answering "sent" whether or not Telegram
    // accepted it would make this endpoint a thing that claims success without
    // checking — and a caller that trusted it would believe somebody had been
    // told when nobody had. The status code carries it too, so a shell script
    // using `curl --fail` finds out without parsing anything.
    return Response.json(result, { status: result.delivered ? 200 : 502 });
  }

  // POST /run — force a check cycle now, instead of waiting for the cron.
  //
  // Earns its place twice. It is the only way to tell "the checks are broken"
  // apart from "the schedule is not firing" — two failures that look identical
  // from outside, since both leave the state empty and say nothing. And it lets
  // a change be verified on deploy rather than on faith, which for a component
  // whose healthy state is silence is the difference between working and
  // merely appearing to.
  //
  // Authenticated, because a forced run sends real alerts.
  if (request.method === 'POST' && url.pathname === '/run') {
    const provided = (request.headers.get('authorization') || '').replace(/^Bearer\s+/i, '');
    if (!env.HEARTBEAT_SECRET || !timingSafeEqual(provided, env.HEARTBEAT_SECRET)) {
      return new Response('unauthorized\n', { status: 401 });
    }

    try {
      // Awaited rather than handed to waitUntil: the caller asked for a result,
      // and a run whose errors vanish into the background is exactly what made
      // this endpoint necessary.
      await runChecks(env);
      return Response.json({ ran: true });
    } catch (error) {
      return Response.json({ ran: false, error: String(error?.stack || error) }, { status: 500 });
    }
  }

  // GET /status — for a human wondering what this thing currently believes.
  // Unauthenticated and deliberately thin: it publishes no URL, no secret and
  // no detail beyond up/down, which is already visible to anyone who can load
  // the site itself.
  if (request.method === 'GET' && url.pathname === '/status') {
    const cfg = config(env);
    const out = { targets: {}, backup: null };

    for (const target of cfg.targets) {
      const state = await readJson(env, `state:${target.name}`);
      out.targets[target.name] = state ? state.alertedStatus : 'unknown';
    }

    const beat = await readJson(env, 'beat:backup');
    out.backup = beat?.at ? { lastSuccessAgeSeconds: Math.round((Date.now() - beat.at) / 1000) } : null;

    return Response.json(out);
  }

  return new Response('not found\n', { status: 404 });
}

// ── plumbing ────────────────────────────────────────────────────────────────

async function readJson(env, key) {
  try {
    return await env.WATCHDOG_STATE.get(key, { type: 'json' });
  } catch {
    return null;
  }
}

async function writeJson(env, key, value) {
  await env.WATCHDOG_STATE.put(key, JSON.stringify(value));
}

/**
 * Constant-time-ish comparison for the heartbeat secret. Length is compared
 * first and leaks only that, which is not the secret.
 */
function timingSafeEqual(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string' || a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

function humanDuration(ms) {
  const seconds = Math.max(0, Math.round(ms / 1000));
  if (seconds < 60) return `${seconds}s`;
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `${minutes}m`;
  const hours = Math.floor(minutes / 60);
  const remainder = minutes % 60;
  if (hours < 24) return remainder ? `${hours}h ${remainder}m` : `${hours}h`;
  const days = Math.floor(hours / 24);
  return `${days}d ${hours % 24}h`;
}

/**
 * Send to Telegram.
 *
 * A failure to deliver is logged and swallowed: an alert we could not send
 * must not abort the run and take the remaining checks — and the recording of
 * their state — down with it.
 */
async function notify(env, text) {
  if (!env.TELEGRAM_BOT_TOKEN || !env.TELEGRAM_CHAT_ID) {
    console.error('watchdog: telegram is not configured; alert dropped:', text);
    return { delivered: false, reason: 'telegram is not configured' };
  }

  try {
    const response = await fetch(`https://api.telegram.org/bot${env.TELEGRAM_BOT_TOKEN}/sendMessage`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        chat_id: env.TELEGRAM_CHAT_ID,
        text,
        parse_mode: 'Markdown',
        disable_web_page_preview: true,
      }),
    });

    // TELEGRAM ANSWERS 200 WITH `ok: false`. A transport-level check alone
    // would call a rejected message delivered — wrong chat id, a revoked
    // token, a bot blocked by the user — which is the failure this component
    // exists to end, reproduced inside it.
    const body = await response.json().catch(() => null);
    if (!response.ok || !body?.ok) {
      const reason = body?.description || `HTTP ${response.status}`;
      console.error('watchdog: telegram rejected the alert:', reason);
      return { delivered: false, reason };
    }

    return { delivered: true };
  } catch (error) {
    const reason = String(error?.message || error);
    console.error('watchdog: telegram unreachable', reason);
    return { delivered: false, reason };
  }
}

// Exported for the unit tests, which exercise the state machine directly
// rather than through a live Worker.
export const __test__ = { humanDuration, timingSafeEqual, config, probeOnce, checkTarget, checkHeartbeats, handleRequest };
