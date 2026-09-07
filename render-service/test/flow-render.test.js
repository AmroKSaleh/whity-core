'use strict';

/**
 * The flowing renderer's refusal contract, and its page geometry (#1072).
 *
 * Chromium is faked here — the real round-trip is proved in the render-tier CI
 * job, where the resulting PDF is opened and its page numbers checked. What
 * this pins is what the orchestration does with what the paginator tells it,
 * because that is where a wrong document either gets refused or gets shipped.
 *
 * The refusals matter more than the success. A flowing document's whole value
 * is that its cross-references are right; a contents list of ninety
 * plausible-looking wrong numbers is a worse artefact than none, and it looks
 * completely typeset. So anything that makes the recorded page numbers
 * untrustworthy has to fail the render rather than return bytes.
 */

const mockPdfCalls = [];
const mockContentCalls = [];

let mockFlowResult;
let mockFlowError;

jest.mock('../src/renderer', () => ({
  getBrowser: jest.fn(async () => ({
    newPage: async () => ({
      setContent: async (html, opts) => {
        mockContentCalls.push({ html, opts });
      },
      waitForFunction: async () => {},
      evaluate: async () => ({ error: mockFlowError, result: mockFlowResult }),
      pdf: async (options) => {
        mockPdfCalls.push(options);
        return Buffer.from('%PDF-1.4\nflow\n%%EOF');
      },
      close: async () => {},
    }),
  })),
  renderToPdf: jest.fn(),
  shutdown: jest.fn(),
}));

const { renderFlowToPdf } = require('../src/flow/render');

const goodResult = () => ({
  pageCount: 132,
  frontMatterPages: 10,
  bodyPages: 122,
  frontMatterPasses: 2,
  frontMatterEntriesChecked: 294,
  anchors: { 't-1': 12, 'f-1': 13 },
  problems: [],
  paginateMs: 1487,
});

const payload = () => ({
  direction: 'rtl',
  title: 'مستند',
  page: { preset: 'a4', margin: { topMm: 25, rightMm: 20, bottomMm: 25, leftMm: 20 } },
  footer: { center: 'صفحة {{page}} من {{pages}}' },
  frontMatter: [{ kind: 'contents' }],
  content: [
    { type: 'heading', level: 1, text: 'قسم SEC-001' },
    { type: 'paragraph', text: 'نص' },
  ],
});

describe('renderFlowToPdf', () => {
  beforeEach(() => {
    mockPdfCalls.length = 0;
    mockContentCalls.length = 0;
    mockFlowResult = goodResult();
    mockFlowError = null;
  });

  test('returns the bytes and the pagination record on success', async () => {
    const { pdf, pagination } = await renderFlowToPdf(payload());
    expect(pdf.toString('utf8')).toContain('%PDF-1.4');
    expect(pagination.pageCount).toBe(132);
    expect(pagination.frontMatterPages).toBe(10);
    expect(pagination.timings.totalMs).toBeGreaterThanOrEqual(0);
  });

  // The document is paginated by the time Chromium sees it: one page box per
  // output page, at the exact physical size. Chromium's only job is to print
  // each box onto one sheet, which is why this is `margin: 0` — the document's
  // real margins are the inset of the content box inside those boxes, and that
  // inset is what leaves room for the running header and footer.
  test('prints at margin 0 with the CSS page size, and never with displayHeaderFooter', async () => {
    await renderFlowToPdf(payload());
    expect(mockPdfCalls).toHaveLength(1);
    expect(mockPdfCalls[0].printBackground).toBe(true);
    expect(mockPdfCalls[0].preferCSSPageSize).toBe(true);
    expect(mockPdfCalls[0].margin).toEqual({ top: '0mm', right: '0mm', bottom: '0mm', left: '0mm' });
    expect(mockPdfCalls[0].displayHeaderFooter).toBeUndefined();
  });

  test('the generated page carries the requested geometry and direction', async () => {
    await renderFlowToPdf(payload());
    const { html } = mockContentCalls[0];
    expect(html).toContain('dir="rtl"');
    expect(html).toContain('--flow-page-width: 210mm');
    expect(html).toContain('--flow-margin-top: 25mm');
    expect(html).toContain('@page { size: 210mm 297mm; margin: 0; }');
  });

  test('refuses when the paginator overran a page box', async () => {
    mockFlowResult = { ...goodResult(), problems: [{ page: 71, reason: 'content overruns the page box by 84px' }] };
    await expect(renderFlowToPdf(payload())).rejects.toThrow(/page 71.*overruns/);
  });

  // What a non-converged front-matter loop looks like: the list was printed
  // against a length the document does not have, so every number in it is
  // stale by the difference.
  test('refuses when a printed front-matter number disagrees with the recorded page', async () => {
    mockFlowResult = {
      ...goodResult(),
      problems: [{ page: 1, reason: 'front-matter entry prints page 17 but the anchor is recorded on 11', anchor: 'h-1' }],
    };
    await expect(renderFlowToPdf(payload())).rejects.toThrow(/prints page 17 but the anchor is recorded on 11/);
  });

  test('reports how many problems there were, not just the first', async () => {
    mockFlowResult = {
      ...goodResult(),
      problems: [
        { page: 1, reason: 'first' },
        { page: 2, reason: 'second' },
        { page: 3, reason: 'third' },
      ],
    };
    await expect(renderFlowToPdf(payload())).rejects.toThrow(/and 2 more/);
  });

  test('refuses when pagination threw inside the page', async () => {
    mockFlowError = 'TypeError: cannot read properties of null';
    await expect(renderFlowToPdf(payload())).rejects.toThrow(/Flow pagination failed in the page/);
  });

  test('refuses when the page signalled ready without a result', async () => {
    mockFlowResult = null;
    await expect(renderFlowToPdf(payload())).rejects.toThrow(/without producing a result/);
  });

  test('refuses a zero-page document', async () => {
    mockFlowResult = { ...goodResult(), pageCount: 0 };
    await expect(renderFlowToPdf(payload())).rejects.toThrow(/produced no pages/);
  });

  test('no PDF is produced by any of the refusals', async () => {
    mockFlowResult = { ...goodResult(), problems: [{ page: 3, reason: 'nope' }] };
    await expect(renderFlowToPdf(payload())).rejects.toThrow();
    expect(mockPdfCalls).toHaveLength(0);
  });
});
