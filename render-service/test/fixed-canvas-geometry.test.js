'use strict';

/**
 * The fixed-canvas mode's geometry, pinned (#1072).
 *
 * Adding a flowing render mode must not move a fixed-canvas render by a
 * millimetre. That mode is what the document designer produces and what
 * verification-code stamping (#1036) composes into, so its output is expected
 * to be byte-for-byte the same shape it was before: one PDF page per template
 * page, at the template's exact millimetre size, with all four margins zero
 * and `printBackground` on.
 *
 * "I did not mean to change it" is not evidence. This test drives the real
 * `renderToPdf` against a mocked Chromium and asserts the exact `@page` rule
 * it injects and the exact options it hands `page.pdf()`. If a later change
 * adds a margin, switches on `displayHeaderFooter`, or drops
 * `preferCSSPageSize`, this fails and names the value that moved.
 */

const PAGE_CSS_CALLS = [];
const PDF_CALLS = [];
const GOTO_CALLS = [];

jest.mock('puppeteer-core', () => ({
  launch: jest.fn(async () => ({
    newPage: async () => ({
      evaluateOnNewDocument: async () => {},
      goto: async (url, opts) => {
        GOTO_CALLS.push({ url, opts });
      },
      addStyleTag: async (arg) => {
        PAGE_CSS_CALLS.push(arg);
      },
      waitForFunction: async () => {},
      pdf: async (options) => {
        PDF_CALLS.push(options);
        return Buffer.from('%PDF-1.4\nmock\n%%EOF');
      },
      close: async () => {},
    }),
    close: async () => {},
  })),
}));

const { renderToPdf, shutdown } = require('../src/renderer');

const template = (overrides = {}) => ({
  version: 2,
  page: { widthMm: 100, heightMm: 60, marginMm: 2, background: '#ffffff' },
  placeholders: [],
  pages: [{ id: 'p1', elements: [] }],
  ...overrides,
});

describe('fixed-canvas renderToPdf geometry', () => {
  beforeEach(() => {
    PAGE_CSS_CALLS.length = 0;
    PDF_CALLS.length = 0;
    GOTO_CALLS.length = 0;
  });

  afterAll(async () => {
    await shutdown();
  });

  test('injects the template page size in exact mm with a zero margin', async () => {
    await renderToPdf({ template: template(), dataRows: [{}] }, { harnessUrl: 'http://127.0.0.1:8130/_harness/index.html' });
    expect(PAGE_CSS_CALLS).toEqual([{ content: '@page { size: 100mm 60mm; margin: 0; }' }]);
  });

  test('a tiled sheet uses the SHEET size, not the template size', async () => {
    await renderToPdf(
      {
        template: template(),
        dataRows: [{}],
        sheet: { enabled: true, sheetWidthMm: 210, sheetHeightMm: 297 },
      },
      { harnessUrl: 'http://127.0.0.1:8130/_harness/index.html' }
    );
    expect(PAGE_CSS_CALLS).toEqual([{ content: '@page { size: 210mm 297mm; margin: 0; }' }]);
  });

  test('page.pdf() is called with exactly the historical option set', async () => {
    await renderToPdf({ template: template(), dataRows: [{}] }, { harnessUrl: 'http://127.0.0.1:8130/_harness/index.html' });
    expect(PDF_CALLS).toHaveLength(1);
    const options = PDF_CALLS[0];
    expect(options.printBackground).toBe(true);
    expect(options.preferCSSPageSize).toBe(true);
    expect(options.margin).toEqual({ top: '0mm', right: '0mm', bottom: '0mm', left: '0mm' });
    // The flowing mode draws its running header and footer in the document.
    // If this ever becomes true for the FIXED mode, Chromium starts reserving
    // header/footer bands out of a page that has no margin to give.
    expect(options.displayHeaderFooter).toBeUndefined();
    expect(options.headerTemplate).toBeUndefined();
    expect(options.footerTemplate).toBeUndefined();
    // No width/height option: the injected @page rule is what decides the
    // physical size, which is the whole point of preferCSSPageSize.
    expect(options.width).toBeUndefined();
    expect(options.height).toBeUndefined();
    expect(options.format).toBeUndefined();
    expect(options.scale).toBeUndefined();
    expect(Object.keys(options).sort()).toEqual(['margin', 'preferCSSPageSize', 'printBackground', 'timeout']);
  });

  test('it still navigates to the harness bundle rather than setting content', async () => {
    await renderToPdf({ template: template(), dataRows: [{}] }, { harnessUrl: 'http://127.0.0.1:8130/_harness/index.html' });
    expect(GOTO_CALLS).toEqual([
      { url: 'http://127.0.0.1:8130/_harness/index.html', opts: { waitUntil: 'load', timeout: expect.any(Number) } },
    ]);
  });
});
