'use strict';

/**
 * Label sheets (E.43): one label per record or CSV row on a fixed grid,
 * centred on the sheet, as many sheets as it takes.
 */

const { validateLabelsPayload, buildLabelsHtml, cellsOf, normaliseGrid } = require('../src/flow/labels');

const L7160 = { columns: 3, rows: 7, widthMm: 63.5, heightMm: 38.1, gapXMm: 2.5, gapYMm: 0 };

const label = (name) => [
  { type: 'paragraph', text: name },
  { type: 'barcode', symbology: 'code128', value: 'SKU-1' },
];

const payload = (labels, extra = {}) => ({ sheet: { preset: 'a4' }, grid: L7160, labels, ...extra });

describe('validateLabelsPayload', () => {
  test('accepts a sheet of labels', () => {
    expect(validateLabelsPayload(payload([label('Example Bakery')]))).toBeNull();
  });

  test('refuses a grid that does not fit the sheet', () => {
    expect(validateLabelsPayload(payload([label('a')], { grid: { ...L7160, rows: 8 } }))).toBe('The labels do not fit on the sheet');
  });

  test('refuses an unknown sheet and a bad grid', () => {
    expect(validateLabelsPayload(payload([label('a')], { sheet: { preset: 'a3' } }))).toMatch(/"sheet"/);
    expect(validateLabelsPayload(payload([label('a')], { grid: { ...L7160, columns: 1.5 } }))).toMatch(/grid.columns/);
    expect(validateLabelsPayload(payload([label('a')], { grid: { ...L7160, widthMm: 2 } }))).toMatch(/grid.widthMm/);
  });

  test('refuses blocks a label cannot hold, and checks each block as the flowing mode does', () => {
    expect(validateLabelsPayload(payload([[{ type: 'pageBreak' }]]))).toMatch(/"labels\[0\]\[0\].type"/);
    expect(validateLabelsPayload(payload([label('a'), [{ type: 'barcode', symbology: 'qr', value: 'x' }]]))).toMatch(
      /"labels\[1\]\[0\].symbology"/
    );
    expect(validateLabelsPayload(payload([]))).toMatch(/non-empty/);
  });

  test('refuses a theme colour that is not hex', () => {
    expect(validateLabelsPayload(payload([label('a')], { theme: { accent: 'red' } }))).toMatch(/hex/);
  });
});

describe('the grid', () => {
  test('is centred on the sheet, matching the stock to within a millimetre', () => {
    const cells = cellsOf({ widthMm: 210, heightMm: 297 }, normaliseGrid(L7160));
    expect(cells).toHaveLength(21);
    expect(cells[0].x).toBeCloseTo(7.25, 2); // L7160: 7 mm
    expect(cells[0].y).toBeCloseTo(15.15, 2); // L7160: 15 mm
    expect(cells[1].x).toBeCloseTo(7.25 + 66, 2);
    expect(cells[3].y).toBeCloseTo(15.15 + 38.1, 2);
  });

  test('uses the column gap between rows when no row gap is given', () => {
    const g = normaliseGrid({ columns: 2, rows: 2, widthMm: 50, heightMm: 20, gapXMm: 4 });
    expect(g.gapYMm).toBe(4);
  });
});

describe('buildLabelsHtml', () => {
  test('fills one label per entry and starts a new sheet when one is full', () => {
    const labels = Array.from({ length: 23 }, (_, i) => label(`Label ${i + 1}`));
    const html = buildLabelsHtml(payload(labels));
    expect(html.match(/class="flow-page flow-sheet"/g)).toHaveLength(2);
    expect(html.match(/class="flow-label"/g)).toHaveLength(23);
    expect(html).toContain('Label 23');
    expect(html).toContain('@page { size: 210mm 297mm');
  });

  test('escapes text and sets the direction', () => {
    const html = buildLabelsHtml(payload([[{ type: 'paragraph', text: '<b>مخبز</b>' }]], { direction: 'rtl' }));
    expect(html).toContain('dir="rtl"');
    expect(html).toContain('lang="ar"');
    expect(html).not.toContain('<b>مخبز</b>');
  });

  test('a break run is a line break, and must carry the newline its text spells', () => {
    const address = {
      type: 'paragraph',
      text: 'Example Clinic\nRainbow Street 21',
      runs: [{ text: 'Example Clinic', bold: true }, { text: '\n', break: true }, { text: 'Rainbow Street 21' }],
    };
    expect(validateLabelsPayload(payload([[address]]))).toBeNull();
    expect(buildLabelsHtml(payload([[address]]))).toMatch(/Example Clinic.*<\/strong><br>.*Rainbow Street 21/);

    const wrong = { ...address, text: 'Example Clinic Rainbow Street 21', runs: [address.runs[0], { text: ' ', break: true }, address.runs[2]] };
    expect(validateLabelsPayload(payload([[wrong]]))).toMatch(/must be "\\n" for a break/);
  });

  test('places labels with logical properties, so an Arabic sheet starts at the right', () => {
    const html = buildLabelsHtml(payload([label('a')]));
    expect(html).toMatch(/inset-inline-start:7\.25mm;top:15\.15mm/);
  });
});

describe('POST /render/labels', () => {
  const SECRET = 'b'.repeat(32);
  let request;
  let app;
  let renderHtmlToPdf;

  beforeEach(() => {
    jest.resetModules();
    process.env.RENDER_SHARED_SECRET = SECRET;
    process.env.PORT = '8131';
    jest.doMock('../src/renderer', () => ({
      renderToPdf: jest.fn(async () => Buffer.from('%PDF-1.4\n%%EOF')),
      getBrowser: jest.fn(async () => ({})),
      shutdown: jest.fn(async () => {}),
    }));
    jest.doMock('../src/flow/render', () => ({
      renderFlowToPdf: jest.fn(),
      renderHtmlToPdf: jest.fn(async () => ({
        pdf: Buffer.from('%PDF-1.4\nlabels\n%%EOF'),
        pagination: { pageCount: 2, frontMatterPages: 0, problems: [], clipped: 1 },
      })),
    }));
    // eslint-disable-next-line global-require
    request = require('supertest');
    // eslint-disable-next-line global-require
    ({ app } = require('../src/server'));
    // eslint-disable-next-line global-require
    ({ renderHtmlToPdf } = require('../src/flow/render'));
  });

  afterEach(() => {
    jest.resetModules();
    delete process.env.RENDER_SHARED_SECRET;
  });

  test('renders the sheets and says how many labels were cut', async () => {
    const res = await request(app).post('/render/labels').set('X-Render-Secret', SECRET).send(payload([label('a')]));
    expect(res.status).toBe(200);
    expect(res.headers['content-type']).toMatch(/application\/pdf/);
    expect(res.headers['x-render-page-count']).toBe('2');
    expect(res.headers['x-render-labels-clipped']).toBe('1');
    expect(renderHtmlToPdf).toHaveBeenCalledTimes(1);
  });

  test('is 401 without the secret and 422 for a bad payload', async () => {
    expect((await request(app).post('/render/labels').send(payload([label('a')]))).status).toBe(401);
    const bad = await request(app).post('/render/labels').set('X-Render-Secret', SECRET).send(payload([]));
    expect(bad.status).toBe(422);
    expect(renderHtmlToPdf).not.toHaveBeenCalled();
  });
});
