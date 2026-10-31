'use strict';

/**
 * A printed letter's first page and running header (the document editor's page
 * view, E.7): the letterhead is one block at the top of the content, and the
 * running header starts on page 2 (`fromPage`) so page 1 doesn't carry both.
 */

const { validateFlowPayload, normaliseFlowDocument } = require('../src/flow/document');
const { buildFlowHtml } = require('../src/flow/html');

const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

const doc = (content, extra = {}) => ({ content, ...extra });

function body(payload) {
  const out = buildFlowHtml(normaliseFlowDocument(payload));
  const start = out.indexOf('<div id="flow-source"');
  const end = out.indexOf('<div id="flow-pages">');
  return out.slice(start, end);
}

const LETTERHEAD = {
  type: 'letterhead',
  start: { image: { src: PNG, alt: 'Example Bakery' }, name: 'Example Bakery', lines: ['Mecca Street 112, Amman · Tax no. 0123456'] },
  end: { title: 'Payment reminder', lines: ['Ref INV-1042', '29 October 2026'] },
};

describe('letterhead', () => {
  test('renders both sides as one unit, escaped and isolated', () => {
    const payload = doc([
      { ...LETTERHEAD, end: { title: '<b>Reminder</b>', lines: ['Ref INV-1042'] } },
      { type: 'paragraph', text: 'Dear Example Clinic,' },
    ]);
    expect(validateFlowPayload(payload)).toBeNull();
    const html = body(payload);
    expect(html).toContain('data-flow-unit="letterhead"');
    expect(html).toContain('flow-letterhead-start');
    expect(html).toContain('flow-letterhead-end');
    expect(html).toContain('Example Bakery');
    expect(html).toContain('&lt;b&gt;Reminder&lt;/b&gt;');
    expect(html).not.toContain('<b>Reminder</b>');
    expect(html).toContain(`src="${PNG}"`);
  });

  test('the title is ink without a print colour and the print colour with one (6.3)', () => {
    // flow.css: the title reads --flow-title, falling back to ink. The accent's
    // own default is transparent, so without this fallback it printed nothing.
    const plain = buildFlowHtml(normaliseFlowDocument(doc([LETTERHEAD])));
    expect(plain).not.toContain('--flow-title:');
    expect(plain).toContain('color: var(--flow-title, var(--flow-ink));');

    const branded = buildFlowHtml(normaliseFlowDocument(doc([LETTERHEAD], { theme: { accent: '#b74c05' } })));
    expect(branded).toContain('--flow-title: #b74c05;');
  });

  test('either side may be left out', () => {
    expect(validateFlowPayload(doc([{ type: 'letterhead', end: { title: 'Statement' } }]))).toBeNull();
    expect(body(doc([{ type: 'letterhead', end: { title: 'Statement' } }]))).toContain('class="flow-letterhead-start"></div>');
  });

  test('refuses a logo that is not embedded, too many lines and non-strings', () => {
    expect(validateFlowPayload(doc([{ ...LETTERHEAD, start: { image: { src: 'https://example.com/logo.png' } } }]))).toMatch(/image/);
    expect(validateFlowPayload(doc([{ ...LETTERHEAD, start: { lines: Array(7).fill('x') } }]))).toMatch(/up to 6/);
    expect(validateFlowPayload(doc([{ ...LETTERHEAD, end: { title: 42 } }]))).toMatch(/must be strings/);
    expect(validateFlowPayload(doc([{ ...LETTERHEAD, end: 'Payment reminder' }]))).toMatch(/must be an object/);
  });
});

describe('fromPage on a running band', () => {
  test('is carried through to the paginator', () => {
    const normalised = normaliseFlowDocument(doc([{ type: 'paragraph', text: 'x' }], { header: { end: 'Payment reminder · continued', fromPage: 2 } }));
    expect(normalised.header.fromPage).toBe(2);
  });

  test('1 or none means every page, as before', () => {
    expect(normaliseFlowDocument(doc([{ type: 'paragraph', text: 'x' }], { header: { end: 'x', fromPage: 1 } })).header.fromPage).toBeUndefined();
    expect(normaliseFlowDocument(doc([{ type: 'paragraph', text: 'x' }], { header: { end: 'x' } })).header.fromPage).toBeUndefined();
  });

  test('refuses a page that is not a whole number from 1', () => {
    for (const fromPage of [0, 1.5, -2, 'two']) {
      expect(validateFlowPayload(doc([{ type: 'paragraph', text: 'x' }], { header: { end: 'x', fromPage } }))).toMatch(/fromPage/);
    }
  });
});

describe('barcode and maths (E.40, E.42)', () => {
  test('a barcode draws its symbology with the value under it', () => {
    const payload = doc([{ type: 'barcode', symbology: 'code128', value: 'INV-1042', showText: true }]);
    expect(validateFlowPayload(payload)).toBeNull();
    const html = body(payload);
    expect(html).toContain('data-flow-unit="barcode"');
    expect(html).toContain('<svg');
    expect(html).toContain('INV-1042');
  });

  test('a value the symbology cannot encode prints as text', () => {
    const html = body(doc([{ type: 'barcode', symbology: 'ean13', value: 'not digits', showText: false }]));
    expect(html).not.toContain('<svg');
    expect(html).toContain('not digits');
  });

  test('refuses an unknown symbology and an empty value', () => {
    expect(validateFlowPayload(doc([{ type: 'barcode', symbology: 'pdf417', value: 'x' }]))).toMatch(/symbology/);
    expect(validateFlowPayload(doc([{ type: 'barcode', symbology: 'code39', value: '' }]))).toMatch(/value/);
  });

  test('a formula prints as MathML, and one that does not parse as its source', () => {
    expect(body(doc([{ type: 'math', tex: String.raw`\frac{a}{b}` }]))).toContain('<math');
    const broken = body(doc([{ type: 'math', tex: String.raw`\frac{a` }]));
    expect(broken).not.toContain('<math');
    expect(broken).toContain(String.raw`\frac{a`);
  });

  test('refuses an empty formula', () => {
    expect(validateFlowPayload(doc([{ type: 'math', tex: '' }]))).toMatch(/tex/);
  });
});
