'use strict';

/**
 * Server-level tests for the whity_render HTTP API, with the Puppeteer
 * renderer MOCKED (no real Chromium involved — this suite verifies the
 * health/auth/rate-limit plumbing, not the actual render). The real Puppeteer
 * round-trip (a genuine PDF from real Chromium) is proven separately by a real
 * Docker build/run, not by a fast unit test — see the render-tier CI job.
 *
 * The flow route's own contract is in test/flow-server.test.js; the shared
 * secret and the rate limiter are exercised through it here because every
 * POST route shares them.
 */

const SECRET = 'a'.repeat(32);

const flowPayload = () => ({
  direction: 'ltr',
  page: { preset: 'a4', margin: { topMm: 25, rightMm: 20, bottomMm: 25, leftMm: 20 } },
  content: [{ type: 'paragraph', text: 'hello' }],
});

/** Load the server with the browser and the flow renderer mocked. */
function loadServer() {
  jest.doMock('../src/renderer', () => ({
    getBrowser: jest.fn(async () => ({})),
    shutdown: jest.fn(async () => {}),
  }));
  jest.doMock('../src/flow/render', () => ({
    renderFlowToPdf: jest.fn(async () => ({
      pdf: Buffer.from('%PDF-1.4\nfake\n%%EOF'),
      pagination: { pageCount: 1, frontMatterPages: 0, paginateMs: 1 },
    })),
    renderHtmlToPdf: jest.fn(),
  }));
  // eslint-disable-next-line global-require
  const { app } = require('../src/server');
  // eslint-disable-next-line global-require
  const { renderFlowToPdf } = require('../src/flow/render');
  return { app, renderFlowToPdf };
}

describe('whity_render server', () => {
  let request;
  let app;
  let renderFlowToPdf;

  beforeEach(() => {
    jest.resetModules();
    process.env.RENDER_SHARED_SECRET = SECRET;
    process.env.PORT = '8130';

    // eslint-disable-next-line global-require
    request = require('supertest');
    ({ app, renderFlowToPdf } = loadServer());
  });

  afterEach(() => {
    jest.resetModules();
    delete process.env.RENDER_SHARED_SECRET;
  });

  test('GET /health returns 200 with no auth required', async () => {
    const res = await request(app).get('/health');
    expect(res.status).toBe(200);
    expect(res.body.status).toBe('ok');
  });

  // The identity fields are always PRESENT, even when this checkout has never
  // run `npm run build:info` (dist/build-info.json absent -> nulls). A consumer
  // comparing the render tier's core_version against the app's must be able to
  // read "this build does not know" from the same field shape rather than
  // having to tell a missing key apart from an older service.
  test('GET /health always carries the build-identity fields', async () => {
    const res = await request(app).get('/health');
    expect(res.body).toHaveProperty('core_version');
    expect(res.body).toHaveProperty('commit');
  });

  // The BROWSER is the other half of the same question (#1134): the Dockerfile
  // installs Chromium unpinned, so it can move without this repository moving,
  // and the flowing mode's paginator is measured against whatever it is. Same
  // always-present rule as the fields above — a checkout that never ran
  // `npm run build:browser-info` reports `source: "unknown"` with nulls, not a
  // missing key. The probe's verdict is `not_run` until start() kicks it off;
  // what it then gates is pinned in test/capability-gate.test.js.
  test('GET /health always carries the browser identity and the capability verdict', async () => {
    const res = await request(app).get('/health');
    for (const key of ['version', 'package_version', 'executable', 'recorded_at', 'source', 'running_version']) {
      expect(res.body.browser).toHaveProperty(key);
    }
    // `build` when this checkout happens to hold a baked file, `unknown`
    // otherwise — never a third thing, and never a missing key.
    expect(['build', 'unknown']).toContain(res.body.browser.source);
    expect(res.body.browser.running_version).toBeNull();
    expect(res.body.capabilities.status).toBe('not_run');
  });

  test('the harness mount is gone', async () => {
    const res = await request(app).get('/_harness/index.html');
    expect(res.status).toBe(404);
  });

  test('a render route is refused when RENDER_SHARED_SECRET is not configured', async () => {
    jest.resetModules();
    delete process.env.RENDER_SHARED_SECRET;
    const { app: unconfiguredApp, renderFlowToPdf: unconfiguredRender } = loadServer();

    const res = await request(unconfiguredApp)
      .post('/render/flow')
      .set('X-Render-Secret', 'anything-at-all-anything-at-all')
      .send(flowPayload());

    expect(res.status).toBe(401);
    expect(unconfiguredRender).not.toHaveBeenCalled();
  });

  test('a render route is refused when RENDER_SHARED_SECRET is shorter than 32 characters', async () => {
    jest.resetModules();
    process.env.RENDER_SHARED_SECRET = 'short-secret';
    const { app: weakApp } = loadServer();

    const res = await request(weakApp).post('/render/flow').set('X-Render-Secret', 'short-secret').send(flowPayload());

    expect(res.status).toBe(401);
  });

  test('a secret of the right length but the wrong value is 401', async () => {
    const res = await request(app).post('/render/flow').set('X-Render-Secret', 'b'.repeat(32)).send(flowPayload());
    expect(res.status).toBe(401);
    expect(renderFlowToPdf).not.toHaveBeenCalled();
  });

  test('the render routes rate-limit after RENDER_RATE_LIMIT_MAX requests in the window, across routes', async () => {
    jest.resetModules();
    process.env.RENDER_SHARED_SECRET = SECRET;
    process.env.RENDER_RATE_LIMIT_MAX = '2';
    const { app: limitedApp } = loadServer();

    const flow = () => request(limitedApp).post('/render/flow').set('X-Render-Secret', SECRET).send(flowPayload());

    const first = await flow();
    const second = await flow();
    // One limiter for one Chromium: the third request is refused even on the
    // other route.
    const third = await request(limitedApp).post('/render/labels').set('X-Render-Secret', SECRET).send({});

    expect(first.status).toBe(200);
    expect(second.status).toBe(200);
    expect(third.status).toBe(429);
    expect(third.headers['retry-after']).toBeDefined();

    delete process.env.RENDER_RATE_LIMIT_MAX;
  });
});
