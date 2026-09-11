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

  // How many days the status-page history bar covers. 90 is the convention
  // on public status pages; the bucket document stays tiny at that size.
  historyDays: 90,
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
    historyDays: num('HISTORY_DAYS', DEFAULTS.historyDays),
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

  await recordHistory(env, cfg, results);
  await checkHeartbeats(env, cfg);
  await maybeSendSummary(env, cfg, results);

  // WHEN this ran, recorded last so it only advances on a run that completed.
  // The public status page leads with the age of this timestamp: a page that
  // says "all systems operational" from data three hours old is the same lie
  // as a monitor that cannot report, just better presented.
  await writeJson(env, 'meta:last-run', { at: Date.now() });
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

    // WRITE ONLY ON CHANGE. Cloudflare KV allows 1,000 writes a day on the free
    // plan, and this Worker was writing one key per target per run plus
    // meta:last-run — 2,880 a day at the */2 schedule, which is nearly three
    // times the allowance. A healthy target whose state is identical to last
    // time has nothing to record, so the steady state now costs no writes at
    // all and the budget is spent on changes, which are the only thing anyone
    // reads this store to learn.
    const unchanged =
      state.alertedStatus === 'up' && (state.consecutiveFailures || 0) === 0 && !state.downSince;

    if (!unchanged) {
      await writeJson(env, key, {
        alertedStatus: 'up',
        consecutiveFailures: 0,
        downSince: null,
        lastOkAt: Date.now(),
      });
    }
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

// ── history ─────────────────────────────────────────────────────────────────

/** UTC day key. One bucket per calendar day, per target. */
function dayKey(at = Date.now()) {
  return new Date(at).toISOString().slice(0, 10);
}

/**
 * Record one day per target: was it observed, and did anything fail.
 *
 * DELIBERATELY NOT AN UPTIME PERCENTAGE. Computing one honestly needs a write
 * on every check, which at a two-minute schedule is thousands a day against a
 * 1,000-a-day allowance — and it would be a percentage of the checks that
 * happened to run, not of the day, which is a different and more flattering
 * number than it appears. A day is therefore recorded as one of three things:
 * observed and clean, observed with a failure, or NOT OBSERVED.
 *
 * That third state is the important one. The checks here do not run reliably —
 * the Cloudflare cron fires erratically — so there will be gaps, and a gap is
 * the absence of evidence rather than evidence of health. Drawing it green
 * would be the same lie as a status page reporting "operational" from readings
 * it stopped collecting.
 *
 * COSTS ONE WRITE PER TARGET PER DAY in the healthy case: the bucket is only
 * written when the day is new, or when the first failure of that day flips it.
 */
async function recordHistory(env, cfg, results) {
  const today = dayKey();
  const keepDays = cfg.historyDays;

  for (const r of results) {
    const key = `history:${r.name}`;
    const doc = (await readJson(env, key)) || { days: {} };
    const existing = doc.days[today];

    // Nothing new: the day is already recorded, and already at least as bad as
    // this check. Skipping the write is the whole point of the shape.
    if (existing !== undefined && (existing === 'down' || r.ok)) continue;

    doc.days[today] = r.ok ? 'up' : 'down';

    // Prune outside the window so the document cannot grow without bound.
    const cutoff = dayKey(Date.now() - keepDays * 86400 * 1000);
    for (const d of Object.keys(doc.days)) {
      if (d < cutoff) delete doc.days[d];
    }

    await writeJson(env, key, doc);
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
    // THE RECOVERY HAS TO KEEP THE DISTINCTION THE ALARM MADE. "Never reported"
    // and "went stale" are deliberately different warnings, because they send
    // an operator on different hunts — and collapsing them back into one
    // recovery message undoes that. Observed on 2026-09-11: a backup that had
    // never once reported to this watchdog was announced as "reporting again",
    // which says it had been working and stopped. It had never started.
    const firstEver = state.alertedStatus === 'missing';
    await notify(
      env,
      firstEver
        ? `✅ *${name} is reporting for the first time*\nLast success ${humanDuration(age)} ago. The dead-man's switch is now armed.`
        : `✅ *${name} is reporting again*\nLast success ${humanDuration(age)} ago.`
    );
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

  // GET / — the public status page.
  //
  // Served from the Worker, from KV, on a hostname that resolves through
  // Cloudflare's edge. It shares nothing with the systems it reports on: not
  // the database, not Docker, not the tunnel, not the machine. The status page
  // that already ships inside whity-core reads the same database that goes
  // down, which is why it showed nothing at all through a three-hour outage.
  if (request.method === 'GET' && (url.pathname === '/' || url.pathname === '/index.html')) {
    return renderStatusPage(env, config(env));
  }

  return new Response('not found\n', { status: 404 });
}

// ── the public status page ──────────────────────────────────────────────────

const STALE_AFTER_MS = 15 * 60 * 1000;

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

/** Human-facing labels, so the page never shows an internal key name. */
const LABELS = {
  api: 'API',
  web: 'Application',
  site: 'Website',
};

async function renderStatusPage(env, cfg) {
  const lastRun = await readJson(env, 'meta:last-run');
  const dataAge = lastRun?.at ? Date.now() - lastRun.at : null;

  // THE FIRST THING ESTABLISHED, BEFORE ANY COMPONENT IS REPORTED. If the
  // checks stopped running, every "operational" below is a claim about the
  // past dressed up as the present, so the page says so instead of reassuring.
  const stale = dataAge === null || dataAge > STALE_AFTER_MS;

  const components = [];
  for (const target of cfg.targets) {
    const state = await readJson(env, `state:${target.name}`);
    const history = await readJson(env, `history:${target.name}`);
    components.push({
      name: LABELS[target.name] || target.name,
      status: state?.alertedStatus ?? 'unknown',
      downSince: state?.alertedStatus === 'down' ? state?.downSince ?? null : null,
      days: history?.days ?? {},
    });
  }

  const beat = await readJson(env, 'beat:backup');
  const backupAge = beat?.at ? Date.now() - beat.at : null;
  const backupOk = backupAge !== null && backupAge <= cfg.backupMaxAgeHours * 3600 * 1000;

  const anyDown = components.some((c) => c.status === 'down');
  const anyUnknown = components.some((c) => c.status === 'unknown');
  const allDown = components.length > 0 && components.every((c) => c.status === 'down');

  let overall;
  if (stale) overall = { kind: 'stale', text: 'Status unknown' };
  else if (allDown) overall = { kind: 'down', text: 'Major outage' };
  else if (anyDown) overall = { kind: 'down', text: 'Partial outage' };
  else if (anyUnknown) overall = { kind: 'stale', text: 'Status incomplete' };
  else overall = { kind: 'ok', text: 'All systems operational' };

  const dot = (kind) => `<span class="dot ${kind}" aria-hidden="true"></span>`;
  const kindOf = (s) => (s === 'up' ? 'ok' : s === 'down' ? 'down' : 'stale');
  const wordOf = (s) => (s === 'up' ? 'Operational' : s === 'down' ? 'Down' : 'Unknown');

  /**
   * The history bar: one segment per day, oldest on the left.
   *
   * THREE STATES, NOT TWO. A day with no recorded check is drawn as a GAP, not
   * as green. The checks here do not run reliably, so an unobserved day is the
   * absence of evidence rather than evidence of health — and colouring it green
   * would be the same untruth as a status page reporting "operational" from
   * readings it stopped collecting. Every other honest decision on this page
   * falls apart if the bar quietly invents uptime.
   */
  const historyBar = (days) => {
    const segs = [];
    let observed = 0;
    let bad = 0;

    for (let i = cfg.historyDays - 1; i >= 0; i--) {
      const d = dayKey(Date.now() - i * 86400 * 1000);
      const v = days[d];
      const cls = v === 'up' ? 'ok' : v === 'down' ? 'down' : 'gap';
      if (v) observed++;
      if (v === 'down') bad++;
      const label = v === 'up' ? 'no failures' : v === 'down' ? 'failure recorded' : 'not observed';
      segs.push(`<i class="seg ${cls}" title="${d} — ${label}"></i>`);
    }

    const summary =
      observed === 0
        ? 'No history yet'
        : `${observed} day${observed === 1 ? '' : 's'} observed · ${bad === 0 ? 'no failures' : `${bad} with failures`}`;

    return `<div class="bar" role="img" aria-label="${escapeHtml(summary)} over the last ${cfg.historyDays} days">${segs.join('')}</div>
            <div class="barfoot"><span>${cfg.historyDays} days ago</span><span>${escapeHtml(summary)}</span><span>today</span></div>`;
  };

  const rows = components
    .map(
      (c) => `
      <li class="row">
        <div class="rowtop">
          <span class="label">${escapeHtml(c.name)}</span>
          <span class="state ${kindOf(c.status)}">${dot(kindOf(c.status))}${wordOf(c.status)}${
            c.downSince ? ` <span class="since">for ${escapeHtml(humanDuration(Date.now() - c.downSince))}</span>` : ''
          }</span>
        </div>
        ${historyBar(c.days)}
      </li>`
    )
    .join('');

  const backupRow = `
      <li class="row">
        <span class="label">Backups</span>
        <span class="state ${backupAge === null ? 'stale' : backupOk ? 'ok' : 'down'}">
          ${dot(backupAge === null ? 'stale' : backupOk ? 'ok' : 'down')}${
            backupAge === null ? 'Never reported' : backupOk ? 'Healthy' : 'Overdue'
          }
        </span>
      </li>`;

  const html = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Whity Status</title>
<style>
  :root{
    --bg:#f4f5f7; --card:#ffffff; --ink:#16202e; --soft:#5a6878; --line:#e2e6eb;
    --ok:#1c7a4e; --down:#b1402a; --stale:#8a6a15;
  }
  @media (prefers-color-scheme: dark){
    :root{ --bg:#0f141b; --card:#161d26; --ink:#e6eaf0; --soft:#9aa6b5; --line:#252e3a;
           --ok:#5cba85; --down:#e0805f; --stale:#d3a64a; }
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);
       font:16px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;
       -webkit-font-smoothing:antialiased}
  .wrap{max-width:660px;margin-inline:auto;padding:clamp(1.5rem,5vw,3.5rem) 1.25rem 3rem}
  h1{font-size:1rem;font-weight:600;letter-spacing:.02em;margin:0 0 1.5rem;color:var(--soft)}
  .banner{background:var(--card);border:1px solid var(--line);border-radius:8px;
          padding:1.4rem 1.5rem;display:flex;align-items:center;gap:.85rem}
  .banner strong{font-size:1.3rem;font-weight:600;line-height:1.2}
  .dot{width:10px;height:10px;border-radius:50%;display:inline-block;flex:none}
  .dot.ok{background:var(--ok)} .dot.down{background:var(--down)} .dot.stale{background:var(--stale)}
  .banner .dot{width:13px;height:13px}
  ul{list-style:none;margin:1.25rem 0 0;padding:0;background:var(--card);
     border:1px solid var(--line);border-radius:8px}
  .row{padding:1rem 1.5rem 1.05rem;border-top:1px solid var(--line)}
  .row:first-child{border-top:0}
  .rowtop{display:flex;justify-content:space-between;align-items:center;gap:1rem}
  /* One segment per day. flex lets 90 of them share the width at any size,
     and min-width keeps them touchable rather than hairlines on a phone. */
  .bar{display:flex;gap:2px;margin-top:.7rem;height:26px}
  .seg{flex:1 1 0;min-width:2px;border-radius:2px;display:block}
  .seg.ok{background:var(--ok)}
  .seg.down{background:var(--down)}
  /* A day nobody looked at is NOT green. Deliberately inert and low-contrast,
     so a gap reads as "no data" rather than as a result. */
  .seg.gap{background:var(--line)}
  .barfoot{display:flex;justify-content:space-between;gap:1rem;margin-top:.45rem;
           font-size:.74rem;color:var(--soft)}
  .barfoot span:nth-child(2){text-align:center}
  .label{font-weight:500}
  .state{display:inline-flex;align-items:center;gap:.5rem;font-size:.92rem;color:var(--soft)}
  .state.ok{color:var(--ok)} .state.down{color:var(--down)} .state.stale{color:var(--stale)}
  .since{color:var(--soft)}
  .note{margin-top:1.25rem;padding:1rem 1.25rem;background:var(--card);
        border:1px solid var(--line);border-radius:8px;font-size:.86rem;color:var(--soft)}
  .warn{border-color:var(--stale)}
  footer{margin-top:1.5rem;font-size:.8rem;color:var(--soft)}
  a{color:inherit}
</style>
</head>
<body>
<div class="wrap">
  <h1>WHITY STATUS</h1>

  <div class="banner">
    ${dot(overall.kind)}<strong>${escapeHtml(overall.text)}</strong>
  </div>

  <ul>${rows}${backupRow}</ul>

  ${
    stale
      ? `<div class="note warn"><strong>These readings are not current.</strong> The last completed check was
         ${dataAge === null ? 'never' : escapeHtml(humanDuration(dataAge)) + ' ago'}, so the statuses above
         describe the past rather than now. Treat them as unknown.</div>`
      : `<div class="note">Checked ${escapeHtml(humanDuration(dataAge))} ago.</div>`
  }

  <div class="note">
    This page is hosted independently of the systems it reports on &mdash; separate
    infrastructure, separate database, separate network path. It stays up when they do not,
    which is the only condition under which a status page is worth reading.
  </div>

  <footer>Whity &middot; <a href="/status">JSON</a></footer>
</div>
</body>
</html>`;

  return new Response(html, {
    status: 200,
    headers: {
      'content-type': 'text/html; charset=utf-8',
      // Never cache a status page. A cached "operational" outliving the outage
      // it was captured before is the failure this exists to prevent.
      'cache-control': 'no-store, max-age=0',
    },
  });
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
export const __test__ = { humanDuration, timingSafeEqual, config, probeOnce, checkTarget, checkHeartbeats, handleRequest, renderStatusPage, recordHistory, dayKey };
