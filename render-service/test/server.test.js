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
    expect(res.body).toEqual({ status: 'ok' });
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
});
