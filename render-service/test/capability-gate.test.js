'use strict';

/**
 * What the boot-time capability probe is allowed to DO to traffic (#1134).
 *
 * The probe's judgement is tested next door in capability-probe.test.js. This
 * is the other half: a probe that can refuse requests is a probe that can
 * cause an outage, so every path where it does and does not is pinned here.
 *
 * The four rules, and why each one is the way round it is:
 *
 *   - A REQUIRED failure refuses `POST /render/flow` with a 503 that NAMES the
 *     probe. The flowing mode's page numbers are only as good as the browser's
 *     fragmentation; refusing is the same call its own paginator already makes
 *     when its checks disagree (src/flow/render.js).
 *   - It does NOT touch `POST /render`. #1134 says not to change the
 *     fixed-canvas path, and that mode's failure mode — a PDF at the wrong
 *     physical size — is visible in the first document anyone opens, unlike a
 *     hundred-page document numbered wrongly.
 *   - The probe FAILING TO RUN gates nothing. A detector that can stop the
 *     service converts a diagnostic into an outage.
 *   - The gate is overridable. A gate with no escape hatch is itself a new way
 *     to be down.
 */

const SECRET = 'a'.repeat(32);

const flowPayload = () => ({
  direction: 'ltr',
  page: { preset: 'a4', margin: { topMm: 25, rightMm: 20, bottomMm: 25, leftMm: 20 } },
  content: [{ type: 'paragraph', text: 'hello' }],
});

const fixedPayload = () => ({
  template: {
    version: 2,
    page: { widthMm: 50, heightMm: 25, marginMm: 2, background: '#fff' },
    placeholders: [],
    pages: [{ id: 'p1', elements: [] }],
  },
  dataRows: [{}],
});

const report = (overrides) => ({
  status: 'ok',
  required_failures: [],
  notable: [],
  unknown: [],
  running_banner: 'HeadlessChrome/151.0.7922.173',
  checked_at: '2026-08-31T12:00:00.000Z',
  ms: 412,
  phases: { launch_ms: 300, layout_ms: 30, geometry_ms: 50, paged_media_ms: 32 },
  error: null,
  results: [],
  ...overrides,
});

const DEGRADED = report({
  status: 'degraded',
  required_failures: ['range-client-rects-per-line: … — 1 rects for 3 line boxes (ordered: true)'],
});

/**
 * Boot the server with a mocked probe result and drive it as `start()` would,
 * without a real Chromium and without binding a port.
 */
function boot(probeResult, env = {}) {
  jest.resetModules();
  process.env.RENDER_SHARED_SECRET = SECRET;
  process.env.PORT = '8130';
  for (const [key, value] of Object.entries(env)) {
    process.env[key] = value;
  }

  jest.doMock('../src/renderer', () => ({
    renderToPdf: jest.fn(async () => Buffer.from('%PDF-1.4\nfixed\n%%EOF')),
    getBrowser: jest.fn(async () => ({})),
    shutdown: jest.fn(async () => {}),
  }));
  jest.doMock('../src/flow/render', () => ({
    renderFlowToPdf: jest.fn(async () => ({
      pdf: Buffer.from('%PDF-1.4\nflow\n%%EOF'),
      pagination: { pageCount: 3, frontMatterPages: 0, paginateMs: 12 },
    })),
  }));

  // The real module's constants, with only the probe RUN replaced: a mock that
  // also invented the status strings could pass while the server compared
  // against different ones.
  // eslint-disable-next-line global-require
  const realProbe = jest.requireActual('../src/capability-probe');
  jest.doMock('../src/capability-probe', () => ({
    ...realProbe,
    runCapabilityProbe: jest.fn(async () => probeResult),
  }));

  // eslint-disable-next-line global-require
  const request = require('supertest');
  // eslint-disable-next-line global-require
  const { app, startCapabilityProbe } = require('../src/server');
  // eslint-disable-next-line global-require
  const { renderFlowToPdf } = require('../src/flow/render');

  return { request, app, startCapabilityProbe, renderFlowToPdf };
}

beforeEach(() => {
  // The probe reports itself loudly on purpose (that is most of the point of
  // #1134); silenced here so a passing run reads as a passing run.
  jest.spyOn(console, 'log').mockImplementation(() => {});
  jest.spyOn(console, 'warn').mockImplementation(() => {});
  jest.spyOn(console, 'error').mockImplementation(() => {});
});

afterEach(() => {
  jest.resetModules();
  delete process.env.RENDER_SHARED_SECRET;
  delete process.env.RENDER_FLOW_REQUIRE_CAPABILITIES;
  jest.restoreAllMocks();
});

describe('GET /health reports what the probe found', () => {
  test('before start(), the probe has not run and says so rather than claiming health', async () => {
    const { request, app } = boot(report());
    const res = await request(app).get('/health');
    expect(res.status).toBe(200);
    expect(res.body.capabilities.status).toBe('not_run');
    expect(res.body.capabilities.checked_at).toBeNull();
  });

  test('after the probe lands, /health carries its verdict and the browser it ran against', async () => {
    const { request, app, startCapabilityProbe } = boot(report());
    await startCapabilityProbe();

    const res = await request(app).get('/health');
    expect(res.body.capabilities.status).toBe('ok');
    expect(res.body.capabilities.ms).toBe(412);
    expect(res.body.browser.running_version).toBe('151.0.7922.173');
    expect(res.body.browser.running_banner).toBe('HeadlessChrome/151.0.7922.173');
  });

  // Liveness must not be silently redefined: the compose healthcheck, the
  // release smoke job and HealthProbe::probeRender() all read this endpoint to
  // answer "is the render tier up", and a degraded container IS up.
  test('a degraded probe does not change the top-level liveness fields', async () => {
    const { request, app, startCapabilityProbe } = boot(DEGRADED);
    await startCapabilityProbe();

    const res = await request(app).get('/health');
    expect(res.status).toBe(200);
    expect(res.body.status).toBe('ok');
    expect(res.body.capabilities.status).toBe('degraded');
    expect(res.body.capabilities.required_failures).toHaveLength(1);
  });

  // A bare checkout never ran `npm run build:browser-info`. The fields are
  // still PRESENT so a consumer reads "this build does not know" from the same
  // shape, rather than telling a missing key apart from an older service.
  test('the browser fields are always present, even when nothing was recorded', async () => {
    const { request, app } = boot(report());
    const res = await request(app).get('/health');
    for (const key of ['version', 'package_version', 'executable', 'recorded_at', 'source', 'running_version']) {
      expect(res.body.browser).toHaveProperty(key);
    }
  });
});

describe('a REQUIRED capability failure', () => {
  test('refuses POST /render/flow with a 503 that names the probe', async () => {
    const { request, app, startCapabilityProbe, renderFlowToPdf } = boot(DEGRADED);
    await startCapabilityProbe();

    const res = await request(app).post('/render/flow').set('X-Render-Secret', SECRET).send(flowPayload());

    expect(res.status).toBe(503);
    expect(res.body.required_failures).toEqual(DEGRADED.required_failures);
    expect(res.body.error).toContain('capabilities.required_failures');
    expect(renderFlowToPdf).not.toHaveBeenCalled();
  });

  // #1134's own constraint: do not change the fixed-canvas render path.
  test('does NOT touch POST /render', async () => {
    const { request, app, startCapabilityProbe } = boot(DEGRADED);
    await startCapabilityProbe();

    const res = await request(app).post('/render').set('X-Render-Secret', SECRET).send(fixedPayload());
    expect(res.status).toBe(200);
    expect(res.headers['content-type']).toContain('application/pdf');
  });

  test('is still refused before auth or validation would have passed', async () => {
    const { request, app, startCapabilityProbe } = boot(DEGRADED);
    await startCapabilityProbe();

    // Unauthenticated is still 401 — the gate does not become an oracle for
    // whether a secret was right.
    const res = await request(app).post('/render/flow').send(flowPayload());
    expect(res.status).toBe(401);
  });

  test('is lifted by RENDER_FLOW_REQUIRE_CAPABILITIES=false, and /health still says degraded', async () => {
    const { request, app, startCapabilityProbe, renderFlowToPdf } = boot(DEGRADED, {
      RENDER_FLOW_REQUIRE_CAPABILITIES: 'false',
    });
    await startCapabilityProbe();

    const res = await request(app).post('/render/flow').set('X-Render-Secret', SECRET).send(flowPayload());
    expect(res.status).toBe(200);
    expect(renderFlowToPdf).toHaveBeenCalled();

    const health = await request(app).get('/health');
    expect(health.body.capabilities.status).toBe('degraded');
  });
});

describe('everything short of a required failure gates nothing', () => {
  test.each([
    ['the probe could not run at all', report({ status: 'error', error: 'Failed to launch the browser process' })],
    ['a required probe could not be measured', report({ status: 'inconclusive', unknown: ['print-one-page-per-forced-break'] })],
    [
      'a capability ARRIVED that the paginator exists because of',
      report({ status: 'notable', notable: ['css-target-counter: NOW TRUE — …'] }),
    ],
    ['a capability nothing uses went away', report({ status: 'notable', notable: ['css-named-pages: NO LONGER TRUE — …'] })],
  ])('%s', async (_label, probeResult) => {
    const { request, app, startCapabilityProbe, renderFlowToPdf } = boot(probeResult);
    await startCapabilityProbe();

    const res = await request(app).post('/render/flow').set('X-Render-Secret', SECRET).send(flowPayload());
    expect(res.status).toBe(200);
    expect(renderFlowToPdf).toHaveBeenCalled();
  });

  test('a probe that rejects outright still leaves the service serving', async () => {
    jest.resetModules();
    process.env.RENDER_SHARED_SECRET = SECRET;

    jest.doMock('../src/renderer', () => ({
      renderToPdf: jest.fn(async () => Buffer.from('%PDF')),
      getBrowser: jest.fn(async () => ({})),
      shutdown: jest.fn(async () => {}),
    }));
    jest.doMock('../src/flow/render', () => ({
      renderFlowToPdf: jest.fn(async () => ({
        pdf: Buffer.from('%PDF-1.4\nflow\n%%EOF'),
        pagination: { pageCount: 1, frontMatterPages: 0, paginateMs: 1 },
      })),
    }));
    // eslint-disable-next-line global-require
    const realProbe = jest.requireActual('../src/capability-probe');
    jest.doMock('../src/capability-probe', () => ({
      ...realProbe,
      runCapabilityProbe: jest.fn(async () => {
        throw new Error('probe blew up');
      }),
    }));

    jest.spyOn(console, 'error').mockImplementation(() => {});

    // eslint-disable-next-line global-require
    const request = require('supertest');
    // eslint-disable-next-line global-require
    const { app, startCapabilityProbe } = require('../src/server');

    await startCapabilityProbe();

    const health = await request(app).get('/health');
    expect(health.status).toBe(200);
    expect(health.body.capabilities.status).toBe('error');
    expect(health.body.capabilities.error).toContain('probe blew up');

    const res = await request(app).post('/render/flow').set('X-Render-Secret', SECRET).send(flowPayload());
    expect(res.status).toBe(200);
  });
});

describe('the flow route waits for the probe rather than racing it', () => {
  test('a request arriving while the probe is still running gets the verdict, not a pass', async () => {
    let release;
    const pending = new Promise((resolve) => {
      release = resolve;
    });

    jest.resetModules();
    process.env.RENDER_SHARED_SECRET = SECRET;
    // eslint-disable-next-line global-require
    const realProbe = jest.requireActual('../src/capability-probe');
    jest.doMock('../src/renderer', () => ({
      renderToPdf: jest.fn(async () => Buffer.from('%PDF')),
      getBrowser: jest.fn(async () => ({})),
      shutdown: jest.fn(async () => {}),
    }));
    jest.doMock('../src/flow/render', () => ({
      renderFlowToPdf: jest.fn(async () => ({
        pdf: Buffer.from('%PDF-1.4\nflow\n%%EOF'),
        pagination: { pageCount: 1, frontMatterPages: 0, paginateMs: 1 },
      })),
    }));
    jest.doMock('../src/capability-probe', () => ({
      ...realProbe,
      runCapabilityProbe: jest.fn(() => pending.then(() => DEGRADED)),
    }));

    // eslint-disable-next-line global-require
    const supertest = require('supertest');
    // eslint-disable-next-line global-require
    const booted = require('../src/server');
    jest.spyOn(console, 'error').mockImplementation(() => {});

    booted.startCapabilityProbe();

    const inFlight = supertest(booted.app)
      .post('/render/flow')
      .set('X-Render-Secret', SECRET)
      .send(flowPayload());

    // The request is in flight while the probe is pending; releasing the probe
    // with a degraded verdict must produce the 503, not a render that slipped
    // through the window.
    release();

    const res = await inFlight;
    expect(res.status).toBe(503);
  });

  // The other half of that: waiting must be BOUNDED. An unbounded wait would
  // turn a probe wedged without a timeout of its own into flow renders that
  // hang — a new outage source, which is exactly what this must not be. A
  // request that gives up waiting proceeds ungated, because not knowing has
  // never been evidence that anything changed.
  test('a probe that never lands does not hang the route; the request renders ungated', async () => {
    jest.resetModules();
    process.env.RENDER_SHARED_SECRET = SECRET;
    process.env.RENDER_FLOW_CAPABILITY_WAIT_MS = '50';

    jest.doMock('../src/renderer', () => ({
      renderToPdf: jest.fn(async () => Buffer.from('%PDF')),
      getBrowser: jest.fn(async () => ({})),
      shutdown: jest.fn(async () => {}),
    }));
    jest.doMock('../src/flow/render', () => ({
      renderFlowToPdf: jest.fn(async () => ({
        pdf: Buffer.from('%PDF-1.4\nflow\n%%EOF'),
        pagination: { pageCount: 1, frontMatterPages: 0, paginateMs: 1 },
      })),
    }));
    // eslint-disable-next-line global-require
    const realProbe = jest.requireActual('../src/capability-probe');
    jest.doMock('../src/capability-probe', () => ({
      ...realProbe,
      runCapabilityProbe: jest.fn(() => new Promise(() => {})),
    }));

    // eslint-disable-next-line global-require
    const supertest = require('supertest');
    // eslint-disable-next-line global-require
    const booted = require('../src/server');
    booted.startCapabilityProbe();

    const res = await supertest(booted.app)
      .post('/render/flow')
      .set('X-Render-Secret', SECRET)
      .send(flowPayload());

    expect(res.status).toBe(200);

    const health = await supertest(booted.app).get('/health');
    expect(health.body.capabilities.status).toBe('pending');

    delete process.env.RENDER_FLOW_CAPABILITY_WAIT_MS;
  });
});
