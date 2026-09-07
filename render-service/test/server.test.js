'use strict';

/**
 * Server-level tests for the whity_render HTTP API, with the Puppeteer
 * renderer MOCKED (no real Chromium involved — this suite verifies the
 * request/auth/validation plumbing, not the actual render). The real
 * Puppeteer round-trip (a genuine PDF from real Chromium, exercising Arabic
 * text + math + rich-text + barcode/QR) is proven separately by a real Docker
 * build/run, not by a fast unit test — see the PHP-side
 * DocumentRenderServiceDockerTest and the task's local verification steps.
 */

const SECRET = 'a'.repeat(32);

describe('whity_render server', () => {
  let request;
  let app;
  let renderToPdf;

  beforeEach(() => {
    jest.resetModules();
    process.env.RENDER_SHARED_SECRET = SECRET;
    process.env.PORT = '8130';

    jest.doMock('../src/renderer', () => ({
      renderToPdf: jest.fn(async () => Buffer.from('%PDF-1.4\nfake\n%%EOF')),
      shutdown: jest.fn(async () => {}),
    }));

    // eslint-disable-next-line global-require
    request = require('supertest');
    // eslint-disable-next-line global-require
    ({ app } = require('../src/server'));
    // eslint-disable-next-line global-require
    ({ renderToPdf } = require('../src/renderer'));
  });

  afterEach(() => {
    jest.resetModules();
    delete process.env.RENDER_SHARED_SECRET;
  });

  const minimalTemplate = () => ({
    page: { widthMm: 50, heightMm: 25, marginMm: 2, background: '#fff' },
    placeholders: [],
    pages: [{ id: 'p1', elements: [] }],
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

  test('POST /render without the secret header is 401', async () => {
    const res = await request(app).post('/render').send({ template: minimalTemplate() });
    expect(res.status).toBe(401);
    expect(renderToPdf).not.toHaveBeenCalled();
  });

  test('POST /render with the wrong secret is 401', async () => {
    const res = await request(app)
      .post('/render')
      .set('X-Render-Secret', 'wrong-secret-wrong-secret-wrong-secret')
      .send({ template: minimalTemplate() });
    expect(res.status).toBe(401);
    expect(renderToPdf).not.toHaveBeenCalled();
  });

  test('POST /render with the correct secret and a valid payload returns a PDF', async () => {
    const res = await request(app)
      .post('/render')
      .set('X-Render-Secret', SECRET)
      .send({ template: minimalTemplate(), dataRows: [{ a: '1' }] });

    expect(res.status).toBe(200);
    expect(res.headers['content-type']).toMatch(/application\/pdf/);
    expect(Buffer.from(res.body).toString('utf8')).toMatch(/^%PDF-/);
    expect(renderToPdf).toHaveBeenCalledTimes(1);
  });

  test('POST /render rejects a malformed template with 422 and never calls the renderer', async () => {
    const res = await request(app)
      .post('/render')
      .set('X-Render-Secret', SECRET)
      .send({ template: { not: 'a template' } });

    expect(res.status).toBe(422);
    expect(renderToPdf).not.toHaveBeenCalled();
  });

  test('POST /render surfaces a renderer failure as a generic 500', async () => {
    renderToPdf.mockRejectedValueOnce(new Error('boom: internal Puppeteer detail'));

    const res = await request(app)
      .post('/render')
      .set('X-Render-Secret', SECRET)
      .send({ template: minimalTemplate() });

    expect(res.status).toBe(500);
    expect(JSON.stringify(res.body)).not.toMatch(/boom/);
  });

  test('POST /render is refused when RENDER_SHARED_SECRET is not configured', async () => {
    jest.resetModules();
    delete process.env.RENDER_SHARED_SECRET;
    jest.doMock('../src/renderer', () => ({
      renderToPdf: jest.fn(async () => Buffer.from('%PDF-1.4\nfake\n%%EOF')),
      shutdown: jest.fn(async () => {}),
    }));
    // eslint-disable-next-line global-require
    const { app: unconfiguredApp } = require('../src/server');

    const res = await request(unconfiguredApp)
      .post('/render')
      .set('X-Render-Secret', 'anything-at-all-anything-at-all')
      .send({ template: minimalTemplate() });

    expect(res.status).toBe(401);
  });

  test('POST /render rate-limits after RENDER_RATE_LIMIT_MAX requests in the window', async () => {
    jest.resetModules();
    process.env.RENDER_SHARED_SECRET = SECRET;
    process.env.RENDER_RATE_LIMIT_MAX = '2';
    jest.doMock('../src/renderer', () => ({
      renderToPdf: jest.fn(async () => Buffer.from('%PDF-1.4\nfake\n%%EOF')),
      shutdown: jest.fn(async () => {}),
    }));
    // eslint-disable-next-line global-require
    const { app: limitedApp } = require('../src/server');

    const send = () =>
      request(limitedApp).post('/render').set('X-Render-Secret', SECRET).send({ template: minimalTemplate() });

    const first = await send();
    const second = await send();
    const third = await send();

    expect(first.status).toBe(200);
    expect(second.status).toBe(200);
    expect(third.status).toBe(429);
    expect(third.headers['retry-after']).toBeDefined();

    delete process.env.RENDER_RATE_LIMIT_MAX;
  });
});
