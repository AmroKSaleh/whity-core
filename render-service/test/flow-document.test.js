'use strict';

/**
 * Flow payload validation and numbering (#1072).
 *
 * Numbering is the part worth pinning: it is assigned by the RENDERER, in
 * document order, and the generated cross-reference lists are built from the
 * same assignment. If numbering and the index could ever disagree, every page
 * number in a contents list would point at the wrong item while looking
 * perfectly well-formed.
 */

const {
  validateFlowPayload,
  normaliseFlowDocument,
  resolvePage,
  PAGE_PRESETS,
} = require('../src/flow/document');

const minimal = (overrides = {}) => ({
  content: [{ type: 'paragraph', text: 'body' }],
  ...overrides,
});

describe('validateFlowPayload', () => {
  test('accepts a minimal payload', () => {
    expect(validateFlowPayload(minimal())).toBeNull();
  });

  test('rejects a non-object body', () => {
    expect(validateFlowPayload([])).toMatch(/must be a JSON object/);
    expect(validateFlowPayload(null)).toMatch(/must be a JSON object/);
  });

  test('rejects missing or empty content', () => {
    expect(validateFlowPayload({})).toMatch(/"content" must be an array/);
    expect(validateFlowPayload({ content: [] })).toMatch(/must not be empty/);
  });

  test('rejects an unknown block type', () => {
    expect(validateFlowPayload({ content: [{ type: 'video' }] })).toMatch(/content\[0\]\.type/);
  });

  test('rejects a heading level outside 1-6', () => {
    expect(validateFlowPayload({ content: [{ type: 'heading', level: 0, text: 'x' }] })).toMatch(/level/);
    expect(validateFlowPayload({ content: [{ type: 'heading', level: 7, text: 'x' }] })).toMatch(/level/);
    expect(validateFlowPayload({ content: [{ type: 'heading', level: 2.5, text: 'x' }] })).toMatch(/level/);
  });

  test('rejects a page spec with neither a preset nor a size', () => {
    expect(validateFlowPayload(minimal({ page: { margin: {} } }))).toMatch(/preset/);
  });

  test('rejects an unknown preset', () => {
    expect(validateFlowPayload(minimal({ page: { preset: 'a3' } }))).toMatch(/page\.preset/);
  });

  test('rejects a direction other than rtl or ltr', () => {
    expect(validateFlowPayload(minimal({ direction: 'auto' }))).toMatch(/"direction"/);
  });

  // A flowing render must not reach the network. A remote figure src would
  // make every render an outbound fetch from inside the render tier.
  test('rejects a figure whose src is not a data: URI', () => {
    expect(
      validateFlowPayload({ content: [{ type: 'figure', src: 'https://example.test/a.png' }] })
    ).toMatch(/must be a data: URI/);
    expect(validateFlowPayload({ content: [{ type: 'figure', src: 'data:image/png;base64,AA' }] })).toBeNull();
  });

  test('rejects malformed table rows', () => {
    expect(validateFlowPayload({ content: [{ type: 'table', rows: {} }] })).toMatch(/rows/);
    expect(validateFlowPayload({ content: [{ type: 'table', rows: ['a'] }] })).toMatch(/arrays of cell values/);
  });

  // `PAGE_PRESETS[name]` reaches Object.prototype, so an INHERITED name would
  // otherwise pass validation and then resolve to something with no widthMm —
  // an A4 page silently handed to a caller who asked for something else.
  test('rejects an inherited property name as a preset', () => {
    for (const name of ['constructor', 'toString', 'hasOwnProperty', '__proto__']) {
      expect(validateFlowPayload(minimal({ page: { preset: name } }))).toMatch(/page\.preset/);
    }
  });

  test('rejects an unknown front-matter kind', () => {
    expect(validateFlowPayload(minimal({ frontMatter: [{ kind: 'glossary' }] }))).toMatch(/frontMatter\[0\]\.kind/);
  });
});

describe('resolvePage', () => {
  test('defaults to A4 with sane margins', () => {
    const page = resolvePage(undefined);
    expect(page.widthMm).toBe(PAGE_PRESETS.a4.widthMm);
    expect(page.heightMm).toBe(PAGE_PRESETS.a4.heightMm);
    expect(page.margin).toEqual({ top: 25, right: 20, bottom: 25, left: 20 });
  });

  test('a preset wins over explicit dimensions', () => {
    const page = resolvePage({ preset: 'letter', widthMm: 1, heightMm: 1 });
    expect(page.widthMm).toBeCloseTo(215.9);
  });

  // Margins that leave no content box would fragment forever: nothing ever
  // fits, so every unit is pushed onto a fresh page.
  test('clamps margins that would leave no content box', () => {
    const page = resolvePage({
      preset: 'a4',
      margin: { topMm: 400, leftMm: 400, rightMm: 400, bottomMm: 400 },
    });
    expect(page.widthMm - page.margin.left - page.margin.right).toBeGreaterThan(0);
    expect(page.heightMm - page.margin.top - page.margin.bottom).toBeGreaterThan(0);
  });
});

describe('normaliseFlowDocument numbering', () => {
  const doc = () =>
    normaliseFlowDocument({
      direction: 'ltr',
      content: [
        { type: 'heading', level: 1, text: 'One' },
        { type: 'table', caption: 'first table', columns: ['a'], rows: [['1']] },
        { type: 'heading', level: 2, text: 'One point one' },
        { type: 'figure', src: 'data:image/svg+xml;base64,AA', caption: 'first figure' },
        { type: 'heading', level: 2, text: 'One point two' },
        { type: 'heading', level: 3, text: 'Deep' },
        { type: 'heading', level: 1, text: 'Two' },
        { type: 'table', caption: 'second table', columns: ['a'], rows: [['1']] },
        { type: 'figure', src: 'data:image/svg+xml;base64,AA', caption: 'second figure' },
      ],
    });

  test('numbers headings hierarchically and resets deeper counters', () => {
    const numbers = doc()
      .content.filter((b) => b.type === 'heading')
      .map((b) => b.number);
    expect(numbers).toEqual(['1', '1.1', '1.2', '1.2.1', '2']);
  });

  test('numbers tables and figures in document order, in their own sequences', () => {
    const d = doc();
    expect(d.content.filter((b) => b.type === 'table').map((b) => b.number)).toEqual(['1', '2']);
    expect(d.content.filter((b) => b.type === 'figure').map((b) => b.number)).toEqual(['1', '2']);
  });

  test('every numbered block gets an anchor id, and the index uses the same ids', () => {
    const d = doc();
    const anchors = d.content.filter((b) => b.anchorId).map((b) => b.anchorId);
    expect(new Set(anchors).size).toBe(anchors.length);
    expect(d.index.tables.map((t) => t.anchorId)).toEqual(['t-1', 't-2']);
    expect(d.index.figures.map((f) => f.anchorId)).toEqual(['f-1', 'f-2']);
    for (const entry of [...d.index.headings, ...d.index.tables, ...d.index.figures]) {
      expect(anchors).toContain(entry.anchorId);
    }
  });

  test('an unnumbered heading takes no number and is left out of the contents list', () => {
    const d = normaliseFlowDocument({
      content: [
        { type: 'heading', level: 1, text: 'Landmark', unnumbered: true },
        { type: 'heading', level: 1, text: 'Real one' },
      ],
    });
    expect(d.content[0].number).toBe('');
    expect(d.content[1].number).toBe('1');
    expect(d.index.headings.map((h) => h.text)).toEqual(['Real one']);
  });

  test('inContents: false keeps a heading numbered but out of the list', () => {
    const d = normaliseFlowDocument({
      content: [
        { type: 'heading', level: 1, text: 'Listed' },
        { type: 'heading', level: 1, text: 'Unlisted', inContents: false },
        { type: 'heading', level: 1, text: 'Also listed' },
      ],
    });
    expect(d.content.map((b) => b.number)).toEqual(['1', '2', '3']);
    expect(d.index.headings.map((h) => h.text)).toEqual(['Listed', 'Also listed']);
  });

  test('a table or figure without a caption is still numbered but not listed', () => {
    const d = normaliseFlowDocument({
      content: [
        { type: 'table', columns: ['a'], rows: [['1']] },
        { type: 'table', caption: 'listed', columns: ['a'], rows: [['1']] },
      ],
    });
    expect(d.content.map((b) => b.number)).toEqual(['1', '2']);
    expect(d.index.tables.map((t) => t.number)).toEqual(['2']);
  });

  // The heading level indexes the counter array, and this function is callable
  // without the validator having run. It must clamp rather than write outside.
  test('an out-of-range heading level cannot write outside the counter array', () => {
    const d = normaliseFlowDocument({
      content: [
        { type: 'heading', level: 99, text: 'far too deep' },
        { type: 'heading', level: -4, text: 'far too shallow' },
        { type: 'heading', level: 'not a number', text: 'not a number at all' },
      ],
    });
    expect(d.content.map((b) => b.level)).toEqual([6, 1, 1]);
    for (const heading of d.content) {
      expect(heading.number).toMatch(/^[0-9.]+$/);
    }
  });

  test('rtl defaults to Arabic labels and lang, ltr to English', () => {
    const rtl = normaliseFlowDocument({ direction: 'rtl', content: [{ type: 'paragraph', text: 'x' }] });
    expect(rtl.lang).toBe('ar');
    expect(rtl.labels.table).toBe('جدول');
    expect(normaliseFlowDocument({ content: [{ type: 'paragraph', text: 'x' }] }).labels.table).toBe('Table');
  });

  test('caller-supplied labels override the defaults without dropping the rest', () => {
    const d = normaliseFlowDocument({
      direction: 'ltr',
      labels: { table: 'Exhibit' },
      content: [{ type: 'table', caption: 'c', columns: ['a'], rows: [['1']] }],
    });
    expect(d.content[0].label).toBe('Exhibit 1');
    expect(d.labels.figure).toBe('Figure');
  });

  test('front-matter titles fall back to the direction default', () => {
    const d = normaliseFlowDocument({
      direction: 'rtl',
      frontMatter: [{ kind: 'tables' }, { kind: 'contents', title: 'مخصص' }],
      content: [{ type: 'paragraph', text: 'x' }],
    });
    expect(d.frontMatter[0].title).toBe('قائمة الجداول');
    expect(d.frontMatter[1].title).toBe('مخصص');
  });
});

/**
 * The verification-code block (#1036's codes, extended to the flowing mode).
 *
 * Not general-purpose furniture: this is the one mark on a document that
 * attests to where the document came from. So what is pinned is that it draws a
 * REAL code, that its human-readable fallback survives an RTL document intact,
 * and that the refusals are the ones which stop an unscannable lattice reaching
 * paper looking like the genuine article.
 */
describe('the verification-code block', () => {
  const { buildFlowHtml } = require('../src/flow/html');

  const withQr = (qr) => normaliseFlowDocument(minimal({ content: [{ type: 'qr', ...qr }] }));

  test('accepts a code with a value and a reference', () => {
    expect(
      validateFlowPayload(
        minimal({ content: [{ type: 'qr', value: 'https://example.test/verify/x', reference: 'AAAA-BBBB' }] })
      )
    ).toBeNull();
  });

  test('refuses a code with nothing to encode', () => {
    expect(validateFlowPayload(minimal({ content: [{ type: 'qr', value: '' }] })))
      .toMatch(/must be the non-empty string the code encodes/);
  });

  test('refuses a value too long to scan reliably off paper', () => {
    // The limit that matters is not what the encoder accepts but what a phone
    // camera resolves from a printed page. Past this the code still ENCODES; it
    // just stops being readable, which produces a document carrying a mark that
    // looks like a verification code and scans as nothing.
    expect(validateFlowPayload(minimal({ content: [{ type: 'qr', value: 'x'.repeat(513) }] })))
      .toMatch(/stops being reliably scannable/);
  });

  test('refuses a non-string reference', () => {
    expect(validateFlowPayload(minimal({ content: [{ type: 'qr', value: 'ok', reference: 7 }] })))
      .toMatch(/reference" must be a string/);
  });

  test('draws a real barcode as inline SVG rather than a raster', () => {
    // Resolution-free on purpose: a bitmap scaled to a print resolution nobody
    // knows in advance is how a code that scanned in review stops scanning on
    // paper.
    const html = buildFlowHtml(withQr({ value: 'https://example.test/verify/abc' }));

    expect(html).toContain('data-flow-unit="qr"');
    expect(html).toContain('<svg');
  });

  test('isolates the printed reference inside a right-to-left document', () => {
    // `9F2A-4C11-8B03` is a left-to-right token in an RTL paragraph. Without a
    // bidi isolate the hyphens resolve with the surrounding Arabic and the
    // groups print in the wrong order — which reads as a typo and IS a wrong
    // code, typed into the verification page by somebody who could not scan.
    const doc = normaliseFlowDocument({
      direction: 'rtl',
      content: [{ type: 'qr', value: 'https://example.test/v/x', reference: '9F2A-4C11-8B03' }],
    });

    expect(buildFlowHtml(doc)).toMatch(/flow-qr-reference">[^<]*<bdi>/);
  });

  test('keeps the code and its reference on one page', () => {
    // A barcode split across a page boundary scans as nothing. The paginator
    // already moves unsplittable units whole; this pins the CSS that stops a
    // browser's own break heuristics separating the code from the reference
    // that is supposed to stand in for it.
    const html = buildFlowHtml(withQr({ value: 'https://example.test/v/x', reference: 'AAAA-BBBB' }));

    expect(html).toMatch(/\.flow-qr\s*\{[^}]*break-inside:\s*avoid/);
  });
});
