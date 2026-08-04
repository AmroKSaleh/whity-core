'use strict';

const { validatePayload } = require('../src/limits');

const minimalTemplate = () => ({
  page: { widthMm: 50, heightMm: 25, marginMm: 2, background: '#fff' },
  placeholders: [],
  pages: [{ id: 'p1', elements: [] }],
});

describe('validatePayload', () => {
  test('accepts a minimal valid payload', () => {
    expect(validatePayload({ template: minimalTemplate() })).toBeNull();
  });

  test('accepts dataRows as flat objects', () => {
    expect(validatePayload({ template: minimalTemplate(), dataRows: [{ a: '1' }, { a: '2' }] })).toBeNull();
  });

  test('rejects a non-object payload', () => {
    expect(validatePayload('nope')).toMatch(/JSON object/);
    expect(validatePayload(null)).toMatch(/JSON object/);
    expect(validatePayload([])).toMatch(/JSON object/);
  });

  test('rejects a missing/invalid template', () => {
    expect(validatePayload({})).toMatch(/template/);
    expect(validatePayload({ template: 'nope' })).toMatch(/template/);
  });

  test('rejects a template missing page dimensions', () => {
    expect(validatePayload({ template: { pages: [{ id: 'p1', elements: [] }] } })).toMatch(/page/);
  });

  test('rejects a template with no pages', () => {
    const t = minimalTemplate();
    t.pages = [];
    expect(validatePayload({ template: t })).toMatch(/pages/);
  });

  test('rejects dataRows that are not an array', () => {
    expect(validatePayload({ template: minimalTemplate(), dataRows: 'nope' })).toMatch(/dataRows/);
  });

  test('rejects dataRows entries that are not flat objects', () => {
    expect(validatePayload({ template: minimalTemplate(), dataRows: [['a', 'b']] })).toMatch(/flat objects/);
  });

  test('rejects too many dataRows', () => {
    const dataRows = Array.from({ length: 3 }, (_, i) => ({ i: String(i) }));
    process.env.RENDER_HARD_MAX_ROWS = '2';
    jest.resetModules();
    // eslint-disable-next-line global-require
    const { validatePayload: revalidate } = require('../src/limits');
    expect(revalidate({ template: minimalTemplate(), dataRows })).toMatch(/hard limit/);
    delete process.env.RENDER_HARD_MAX_ROWS;
  });

  test('rejects an oversized template', () => {
    process.env.RENDER_HARD_MAX_TEMPLATE_BYTES = '10';
    jest.resetModules();
    // eslint-disable-next-line global-require
    const { validatePayload: revalidate } = require('../src/limits');
    expect(revalidate({ template: minimalTemplate() })).toMatch(/size limit/);
    delete process.env.RENDER_HARD_MAX_TEMPLATE_BYTES;
  });
});
