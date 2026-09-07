'use strict';

/**
 * The boot-time capability probe's JUDGEMENT (#1134).
 *
 * The probe's measurements come from a real browser; its verdicts do not, and
 * the verdicts are the part that can refuse traffic. So the classification is
 * a pure function over raw measurements and it is tested here without
 * Chromium — which also means these assertions still mean something on a
 * machine where the browser cannot launch at all.
 *
 * Three properties are what this suite is really for, because getting any of
 * them wrong turns a diagnostic into an outage or into a lie:
 *
 *   1. A capability that ARRIVES is never a failure. `target-counter()` is
 *      absent today and that absence is why the paginator exists; a future
 *      Chromium implementing it is news worth a loud log line, not a reason to
 *      stop rendering.
 *   2. A capability nothing uses going away is not a failure either.
 *   3. "Could not determine" is never reported as success. A probe that
 *      measured nothing and said `ok` would be the exact shape of the checks
 *      this repository keeps getting burned by.
 *
 * The MEASURED baseline below is the real output of these probes against
 * Chromium 151.0.7922.173 in the shipped image — not invented values.
 */

const {
  parsePdfPageBoxes,
  boxMatchesMm,
  buildResults,
  summarise,
  PX_PER_MM,
  STATUS_OK,
  STATUS_NOTABLE,
  STATUS_INCONCLUSIVE,
  STATUS_DEGRADED,
} = require('../src/capability-probe');

/** What measureInPage() actually returned on Chromium 151.0.7922.173. */
const MEASURED_LAYOUT = () => ({
  lineHeightPx: 24,
  elementHeightPx: 72,
  lineRectCount: 3,
  lineRectsOrdered: true,
  prefixMonotonic: true,
  prefixDistinctBottoms: 3,
  prefixReachesLastLine: true,
  extractHeadMatches: true,
  extractTailMatches: true,
  // Chromium quantises layout to 1/64 px, so its millimetre is 3.779375 rather
  // than the arithmetic 3.7795275. That is not drift, and the probe must not
  // call it drift.
  pxPerMm: 3.779375,
  cssSupportsTargetCounter: true,
  computedTargetCounter: 'none',
  computedStringSet: '',
  computedStringFunction: 'none',
  computedRunningPosition: 'static',
});

/** 210x297mm as Chromium actually emits it: 594.95996 x 841.91998 pt. */
const A4 = { widthPt: 594.95996, heightPt: 841.91998 };
const A5 = { widthPt: 420, heightPt: 594.95996 };
const A4_LANDSCAPE = { widthPt: 841.91998, heightPt: 594.95996 };

const MEASURED_GEOMETRY = () => ({ parsed: true, pageCount: 3, mediaBoxes: [A4, A4, A4] });
const MEASURED_PAGED_MEDIA = () => ({ parsed: true, pageCount: 3, mediaBoxes: [A5, A4_LANDSCAPE, A4] });

const byId = (results) => Object.fromEntries(results.map((r) => [r.id, r]));

describe('parsePdfPageBoxes', () => {
  const pdf = (body) => Buffer.from(`%PDF-1.4\n${body}\n%%EOF`, 'latin1');

  test('reads one box per page and cross-checks the page tree count', () => {
    const parsed = parsePdfPageBoxes(
      pdf(
        '<< /Type /Pages /Count 3 >> ' +
          '<< /Type /Page /MediaBox [0 0 594.95996 841.91998] >> ' +
          '<< /Type /Page /MediaBox [0 0 594.95996 841.91998] >> ' +
          '<< /Type /Page /MediaBox [0 0 594.95996 841.91998] >>'
      )
    );
    expect(parsed.parsed).toBe(true);
    expect(parsed.pageCount).toBe(3);
    expect(parsed.mediaBoxes).toHaveLength(3);
    expect(parsed.mediaBoxes[0].widthPt).toBeCloseTo(594.95996, 4);
  });

  test('boxes are returned in document order, so page 1 is page 1', () => {
    const parsed = parsePdfPageBoxes(
      pdf('/Count 2 /MediaBox [0 0 420 594.95996] /MediaBox [0 0 841.91998 594.95996]')
    );
    expect(parsed.mediaBoxes.map((b) => Math.round(b.widthPt))).toEqual([420, 842]);
  });

  test('a non-zero box origin is handled: the SIZE is the difference', () => {
    const parsed = parsePdfPageBoxes(pdf('/Count 1 /MediaBox [10 20 430 614.95996]'));
    expect(parsed.mediaBoxes[0].widthPt).toBeCloseTo(420, 4);
    expect(parsed.mediaBoxes[0].heightPt).toBeCloseTo(594.95996, 4);
  });

  // Declining to answer is the whole design: every caller turns `parsed:false`
  // into `unknown`, and `unknown` never gates anything. A Chromium that starts
  // compressing its page tree into object streams must degrade to "cannot
  // tell", not to "the browser changed".
  test.each([
    ['not a PDF at all', Buffer.from('hello')],
    ['an empty buffer', Buffer.alloc(0)],
    ['a non-buffer', null],
    ['a PDF with no MediaBox anywhere', Buffer.from('%PDF-1.4\n<< /Type /Pages /Count 3 >>\n%%EOF')],
  ])('declines to answer for %s', (_label, input) => {
    expect(parsePdfPageBoxes(input)).toEqual({ parsed: false, pageCount: null, mediaBoxes: [] });
  });

  test('declines to answer when /Count and the box tally disagree', () => {
    expect(parsePdfPageBoxes(pdf('/Count 7 /MediaBox [0 0 420 595]')).parsed).toBe(false);
  });
});

describe('boxMatchesMm', () => {
  test("accepts Chromium's 1/64-px rounding of A4", () => {
    expect(boxMatchesMm(A4, 210, 297)).toBe(true);
  });

  test('rejects the failure that actually happens: a fall back to US Letter', () => {
    expect(boxMatchesMm({ widthPt: 612, heightPt: 792 }, 210, 297)).toBe(false);
  });

  test('rejects a landscape/portrait swap rather than matching on area', () => {
    expect(boxMatchesMm(A4_LANDSCAPE, 210, 297)).toBe(false);
  });

  test('a missing page is not a match', () => {
    expect(boxMatchesMm(undefined, 210, 297)).toBe(false);
  });
});

describe('buildResults against the measured Chromium 151 baseline', () => {
  const results = buildResults(MEASURED_LAYOUT(), MEASURED_GEOMETRY(), MEASURED_PAGED_MEDIA());

  test('every probe agrees with what the wiki records', () => {
    const changed = results.filter((r) => r.verdict !== 'as-recorded');
    expect(changed.map((r) => `${r.id} (${r.verdict}): ${r.detail}`)).toEqual([]);
  });

  test('the verdict is a clean ok', () => {
    expect(summarise(results).status).toBe(STATUS_OK);
  });

  test('exactly the five load-bearing behaviours are REQUIRED', () => {
    expect(results.filter((r) => r.required).map((r) => r.id).sort()).toEqual([
      'print-honours-css-page-size',
      'print-one-page-per-forced-break',
      'range-client-rects-per-line',
      'range-extract-contents-moves-text',
      'range-prefix-bottom-monotonic',
    ]);
  });

  // The paginator exists BECAUSE these are missing. Requiring them to stay
  // missing would mean a browser improvement takes the render tier down.
  test('the three absences that justify the paginator are informational, and expected absent', () => {
    const map = byId(results);
    for (const id of ['css-target-counter', 'css-string-set', 'css-string-function', 'css-position-running']) {
      expect(map[id].required).toBe(false);
      expect(map[id].expected).toBe(false);
      expect(map[id].observed).toBe(false);
    }
  });

  // Nothing in either render mode uses these; a boot failure over one would be
  // a new outage source, which is precisely what #1134 must not create.
  test('the paged-media features that work but nothing uses are informational', () => {
    const map = byId(results);
    expect(map['css-page-first-pseudo'].required).toBe(false);
    expect(map['css-named-pages'].required).toBe(false);
    expect(map['css-mm-at-96dpi'].required).toBe(false);
  });

  // The documented trap, carried in the report itself rather than only in a
  // wiki paragraph someone has to find.
  test("the target-counter detail records that CSS.supports() claimed otherwise", () => {
    const detail = byId(results)['css-target-counter'].detail;
    expect(detail).toContain('CSS.supports() claims true');
    expect(detail).toContain('"none"');
  });

  test("Chromium's 1/64-px millimetre is not reported as drift", () => {
    expect(byId(results)['css-mm-at-96dpi'].observed).toBe(true);
    expect(PX_PER_MM).toBeCloseTo(3.7795275, 6);
  });
});

describe('buildResults when the browser has changed', () => {
  test('one merged rect instead of one per line is a REQUIRED failure', () => {
    const layout = { ...MEASURED_LAYOUT(), lineRectCount: 1 };
    const results = buildResults(layout, MEASURED_GEOMETRY(), MEASURED_PAGED_MEDIA());
    expect(byId(results)['range-client-rects-per-line'].verdict).toBe('changed');
    const verdict = summarise(results);
    expect(verdict.status).toBe(STATUS_DEGRADED);
    expect(verdict.required_failures.join(' ')).toContain('range-client-rects-per-line');
  });

  test('a prefix range whose bottom stops being monotonic is a REQUIRED failure', () => {
    const results = buildResults(
      { ...MEASURED_LAYOUT(), prefixMonotonic: false },
      MEASURED_GEOMETRY(),
      MEASURED_PAGED_MEDIA()
    );
    expect(summarise(results).status).toBe(STATUS_DEGRADED);
  });

  test('more than one PDF page per page box is a REQUIRED failure', () => {
    const results = buildResults(
      MEASURED_LAYOUT(),
      { parsed: true, pageCount: 6, mediaBoxes: [A4, A4, A4, A4, A4, A4] },
      MEASURED_PAGED_MEDIA()
    );
    expect(byId(results)['print-one-page-per-forced-break'].verdict).toBe('changed');
    expect(summarise(results).status).toBe(STATUS_DEGRADED);
  });

  test('preferCSSPageSize being ignored is a REQUIRED failure', () => {
    const letter = { widthPt: 612, heightPt: 792 };
    const results = buildResults(
      MEASURED_LAYOUT(),
      { parsed: true, pageCount: 3, mediaBoxes: [letter, letter, letter] },
      MEASURED_PAGED_MEDIA()
    );
    expect(byId(results)['print-honours-css-page-size'].verdict).toBe('changed');
    expect(summarise(results).status).toBe(STATUS_DEGRADED);
  });

  // The case this whole classification exists for.
  test('target-counter() ARRIVING is notable, not degraded, and nothing is gated', () => {
    const layout = { ...MEASURED_LAYOUT(), computedTargetCounter: '"12"' };
    const verdict = summarise(buildResults(layout, MEASURED_GEOMETRY(), MEASURED_PAGED_MEDIA()));
    expect(verdict.status).toBe(STATUS_NOTABLE);
    expect(verdict.required_failures).toEqual([]);
    expect(verdict.notable.join(' ')).toContain('css-target-counter: NOW TRUE');
  });

  test('a paged-media feature nothing uses going away is notable, not degraded', () => {
    const results = buildResults(MEASURED_LAYOUT(), MEASURED_GEOMETRY(), {
      parsed: true,
      pageCount: 3,
      mediaBoxes: [A4, A4, A4],
    });
    const verdict = summarise(results);
    expect(verdict.status).toBe(STATUS_NOTABLE);
    expect(verdict.required_failures).toEqual([]);
    expect(verdict.notable.join(' ')).toContain('css-page-first-pseudo: NO LONGER TRUE');
    expect(verdict.notable.join(' ')).toContain('css-named-pages: NO LONGER TRUE');
  });
});

describe('buildResults when something could not be measured', () => {
  test('an unreadable PDF makes the print probes unknown, never failures', () => {
    const results = buildResults(MEASURED_LAYOUT(), { parsed: false, pageCount: null, mediaBoxes: [] }, null);
    const map = byId(results);
    expect(map['print-one-page-per-forced-break'].verdict).toBe('unknown');
    expect(map['print-honours-css-page-size'].verdict).toBe('unknown');
    expect(summarise(results).required_failures).toEqual([]);
  });

  // The property that matters most: a probe that measured nothing must not
  // report success.
  test('an unknown REQUIRED probe is `inconclusive`, not `ok`', () => {
    const verdict = summarise(
      buildResults(MEASURED_LAYOUT(), { parsed: false, pageCount: null, mediaBoxes: [] }, MEASURED_PAGED_MEDIA())
    );
    expect(verdict.status).toBe(STATUS_INCONCLUSIVE);
    expect(verdict.unknown).toEqual(['print-one-page-per-forced-break', 'print-honours-css-page-size']);
  });

  test('nothing measured at all is inconclusive, and every probe says so', () => {
    const results = buildResults(null, null, null);
    expect(results.every((r) => r.verdict === 'unknown')).toBe(true);
    expect(summarise(results).status).toBe(STATUS_INCONCLUSIVE);
  });

  test('an unknown INFORMATIONAL probe alone does not colour the verdict', () => {
    const results = buildResults(MEASURED_LAYOUT(), MEASURED_GEOMETRY(), null);
    const verdict = summarise(results);
    expect(verdict.status).toBe(STATUS_OK);
    expect(verdict.unknown).toEqual(['css-page-first-pseudo', 'css-named-pages']);
  });
});
