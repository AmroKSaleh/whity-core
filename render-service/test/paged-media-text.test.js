'use strict';

const {
  judgePagedMediaText,
} = require('../scripts/verify-paged-media-text');
const {
  PAGED_MEDIA_TEXT_HTML,
  PAGED_MEDIA_TEXT_PAGES,
} = require('../src/capability-probe');

/**
 * The verdict half of the paged-media text check (#1134).
 *
 * The extraction half needs a browser and a PDF parser in two different places,
 * so it runs in CI. The RULES do not need either, and a rule that can only be
 * exercised by rendering is a rule nobody exercises — so they live in a pure
 * function and are pinned here.
 *
 * Each case below is a real way the check can be wrong, not a permutation for
 * its own sake. The one that matters most is `1 of 1` on every page: it is what
 * a browser that draws margin boxes but stops resolving `counter(pages)` would
 * produce, and it looks entirely plausible on a printed page.
 */
describe('judgePagedMediaText', () => {
  const good = ['A 1 of 3', 'B 2 of 3', 'C 3 of 3'];

  it('passes when every page names itself and the correct total', () => {
    const verdict = judgePagedMediaText(good, 3);

    expect(verdict).toEqual({ ok: true, pages: 3, failures: [] });
  });

  it('fails when the margin box drew nothing at all', () => {
    // The capability this whole script exists to check: no footer, no box.
    const verdict = judgePagedMediaText(['A', 'B 2 of 3', 'C 3 of 3'], 3);

    expect(verdict.ok).toBe(false);
    expect(verdict.failures[0]).toMatch(/page 1: no "N of M" footer/);
  });

  it('fails when counter(page) is not tracking', () => {
    const verdict = judgePagedMediaText(['A 1 of 3', 'B 1 of 3', 'C 3 of 3'], 3);

    expect(verdict.ok).toBe(false);
    expect(verdict.failures).toHaveLength(1);
    expect(verdict.failures[0]).toMatch(/page 2: footer says page 1/);
  });

  it('fails when counter(pages) is not resolving — the plausible-looking one', () => {
    // A browser that drew the box but resolved the total to the page it was on
    // prints "1 of 1", "2 of 2", "3 of 3". Page 3 is CORRECT by coincidence,
    // which is exactly why the fixture has three pages rather than one.
    const verdict = judgePagedMediaText(['A 1 of 1', 'B 2 of 2', 'C 3 of 3'], 3);

    expect(verdict.ok).toBe(false);
    expect(verdict.failures).toHaveLength(2);
    expect(verdict.failures[0]).toMatch(/page 1: footer says 1 total, document has 3/);
    expect(verdict.failures[1]).toMatch(/page 2: footer says 2 total, document has 3/);
  });

  it('reports a changed page count before grading the footers', () => {
    // If the fixture stopped breaking where it used to, every footer assertion
    // is about a different document and the count is the finding worth naming.
    const verdict = judgePagedMediaText(['A 1 of 1'], 3);

    expect(verdict.ok).toBe(false);
    expect(verdict.failures[0]).toMatch(/expected 3 page\(s\), got 1/);
  });

  it('tolerates the extractor splitting the footer across text items', () => {
    // pdfjs emits one item per run, and the footer is joined with spaces, so
    // "3" / "of" / "3" arrives as "3 of 3" — but a run boundary mid-number
    // would not, and that would be a real extraction bug rather than a
    // rendering one. This pins the spacing the joiner actually produces.
    const verdict = judgePagedMediaText(['A  1   of   3', 'B 2 of 3', 'C 3 of 3'], 3);

    expect(verdict.ok).toBe(true);
  });
});

describe('the fixture the verdict is about', () => {
  it('asks for a margin box, a page counter and a total', () => {
    // Guards the pairing: the assertions above are meaningless if the fixture
    // stops requesting the features they check for.
    expect(PAGED_MEDIA_TEXT_HTML).toContain('@bottom-center');
    expect(PAGED_MEDIA_TEXT_HTML).toContain('counter(page)');
    expect(PAGED_MEDIA_TEXT_HTML).toContain('counter(pages)');
  });

  it('leaves room to draw one', () => {
    // `margin: 0` is correct for the fixed-canvas mode and leaves nowhere for a
    // margin box — the exact reason #1072 gives for running headers being
    // unavailable. A fixture that inherited it would report "no box drew" on a
    // browser that supports them perfectly well.
    expect(PAGED_MEDIA_TEXT_HTML).toMatch(/@page\s*\{[^}]*margin:\s*20mm/);
  });

  it('produces enough pages that a wrong total cannot pass by coincidence', () => {
    expect(PAGED_MEDIA_TEXT_PAGES).toBeGreaterThan(2);
    expect((PAGED_MEDIA_TEXT_HTML.match(/probe-block/g) || []).length - 2)
      .toBe(PAGED_MEDIA_TEXT_PAGES);
  });
});
