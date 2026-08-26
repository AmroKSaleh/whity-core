'use strict';

/**
 * `POST /render/flow` request plumbing, with the renderer mocked (#1072).
 *
 * Mirrors test/server.test.js in shape and for the same reason: this proves
 * auth, validation and the response contract, not the render. The render
 * itself is proved against real Chromium in the render-tier CI job, where the
 * resulting PDF is opened and its page numbers are checked.
 *
 * The other thing pinned here is that the FIXED-canvas route is untouched: the
 * two modes are separate endpoints backed by separate modules, and a request
 * to one must never reach the other's renderer.
 */

const SECRET = 'a'.repeat(32);

describe('whity_render flow route', () => {
  let request;
  let app;
  let renderFlowToPdf;
  let renderToPdf;

  const pagination = {
    pageCount: 132,
    frontMatterPages: 10,
    bodyPages: 122,
    frontMatterPasses: 2,
    anchors: { 't-1': 12 },
    overflow: [],
    paginateMs: 1487,
  };

  beforeEach(() => {
    jest.resetModules();
    process.env.RENDER_SHARED_SECRET = SECRET;
    process.env.PORT = '8130';

    jest.doMock('../src/renderer', () => ({
      renderToPdf: jest.fn(async () => Buffer.from('%PDF-1.4\nfixed\n%%EOF')),
      getBrowser: jest.fn(async () => ({})),
      shutdown: jest.fn(async () => {}),
    }));
    jest.doMock('../src/flow/render', () => ({
      renderFlowToPdf: jest.fn(async () => ({
        pdf: Buffer.from('%PDF-1.4\nflow\n%%EOF'),
        pagination,
      })),
    }));

    // eslint-disable-next-line global-require
    request = require('supertest');
    // eslint-disable-next-line global-require
    ({ app } = require('../src/server'));
    // eslint-disable-next-line global-require
    ({ renderFlowToPdf } = require('../src/flow/render'));
    // eslint-disable-next-line global-require
    ({ renderToPdf } = require('../src/renderer'));
  });

  afterEach(() => {
    jest.resetModules();
    delete process.env.RENDER_SHARED_SECRET;
  });

  const payload = () => ({
    direction: 'rtl',
    page: { preset: 'a4', margin: { topMm: 25, rightMm: 20, bottomMm: 25, leftMm: 20 } },
    frontMatter: [{ kind: 'contents' }],
    content: [
      { type: 'heading', level: 1, text: 'قسم' },
      { type: 'paragraph', text: 'نص' },
    ],
  });

  test('without the secret header it is 401 and the renderer is never called', async () => {
    const res = await request(app).post('/render/flow').send(payload());
    expect(res.status).toBe(401);
    expect(renderFlowToPdf).not.toHaveBeenCalled();
  });

  test('with the wrong secret it is 401', async () => {
    const res = await request(app)
      .post('/render/flow')
      .set('X-Render-Secret', 'b'.repeat(32))
      .send(payload());
    expect(res.status).toBe(401);
    expect(renderFlowToPdf).not.toHaveBeenCalled();
  });

  test('an invalid payload is 422 with a reason, and the renderer is never called', async () => {
    const res = await request(app)
      .post('/render/flow')
      .set('X-Render-Secret', SECRET)
      .send({ content: [] });
    expect(res.status).toBe(422);
    expect(res.body.error).toMatch(/must not be empty/);
    expect(renderFlowToPdf).not.toHaveBeenCalled();
  });

  test('a valid payload returns the PDF bytes', async () => {
    const res = await request(app)
      .post('/render/flow')
      .set('X-Render-Secret', SECRET)
      .send(payload())
      .buffer()
      .parse((r, cb) => {
        const chunks = [];
        r.on('data', (c) => chunks.push(c));
        r.on('end', () => cb(null, Buffer.concat(chunks)));
      });
    expect(res.status).toBe(200);
    expect(res.headers['content-type']).toBe('application/pdf');
    expect(res.body.toString('utf8')).toContain('%PDF-1.4');
    expect(renderFlowToPdf).toHaveBeenCalledTimes(1);
  });

  // A caller cannot know how many pages a flowing document becomes — that is
  // the definition of one — so the answer has to say.
  test('the response reports the page count and the front-matter length', async () => {
    const res = await request(app).post('/render/flow').set('X-Render-Secret', SECRET).send(payload());
    expect(res.headers['x-render-page-count']).toBe('132');
    expect(res.headers['x-render-front-matter-pages']).toBe('10');
    expect(res.headers['x-render-paginate-ms']).toBe('1487');
  });

  test('a render failure is a generic 500 that leaks nothing', async () => {
    jest.resetModules();
    process.env.RENDER_SHARED_SECRET = SECRET;
    jest.doMock('../src/renderer', () => ({
      renderToPdf: jest.fn(),
      getBrowser: jest.fn(),
      shutdown: jest.fn(),
    }));
    jest.doMock('../src/flow/render', () => ({
      renderFlowToPdf: jest.fn(async () => {
        throw new Error('Flow pagination overran page 71 (content overruns the page box by 84px)');
      }),
    }));
    // eslint-disable-next-line global-require
    const { app: freshApp } = require('../src/server');
    const spy = jest.spyOn(console, 'error').mockImplementation(() => {});
    const res = await require('supertest')(freshApp)
      .post('/render/flow')
      .set('X-Render-Secret', SECRET)
      .send(payload());
    expect(res.status).toBe(500);
    expect(res.body).toEqual({ error: 'Render failed' });
    expect(JSON.stringify(res.body)).not.toMatch(/overran/);
    spy.mockRestore();
  });

  test('the two modes never cross: a flow request does not reach the fixed renderer', async () => {
    await request(app).post('/render/flow').set('X-Render-Secret', SECRET).send(payload());
    expect(renderToPdf).not.toHaveBeenCalled();
  });

  test('the two modes never cross: a fixed request does not reach the flow renderer', async () => {
    await request(app)
      .post('/render')
      .set('X-Render-Secret', SECRET)
      .send({
        template: {
          page: { widthMm: 50, heightMm: 25, marginMm: 2, background: '#fff' },
          placeholders: [],
          pages: [{ id: 'p1', elements: [] }],
        },
        dataRows: [{}],
      });
    expect(renderFlowToPdf).not.toHaveBeenCalled();
    expect(renderToPdf).toHaveBeenCalledTimes(1);
  });

  // A flow payload is not a template payload. Posting one to the fixed route
  // must be refused by that route's own validator rather than half-rendered.
  test('a flow payload posted to /render is 422', async () => {
    const res = await request(app).post('/render').set('X-Render-Secret', SECRET).send(payload());
    expect(res.status).toBe(422);
    expect(renderToPdf).not.toHaveBeenCalled();
  });
});
