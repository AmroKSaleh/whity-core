'use strict';

/**
 * Per-block LAYOUT: the space around a block, how it behaves at a page
 * boundary, and how wide it is (#1186).
 *
 * These are the properties an author reaches for once the words are right —
 * "keep this heading with its table", "this figure is half a column",
 * "start the appendix on a new page". None of them existed. Spacing was fixed
 * per block TYPE in the stylesheet, break behaviour was fixed in the
 * paginator, and every block was the full column width.
 *
 * Two things below are worth more than the rest.
 *
 * SPACING IS EMITTED AS A CUSTOM PROPERTY, NEVER AN INLINE MARGIN. The
 * stylesheet resets `margin-top` on the first unit of a page so leading air
 * does not push it below the page's own top margin, and an inline margin would
 * outrank that class rule — so every page that happened to begin with a spaced
 * block would start lower than every other page. It shows up only on the pages
 * where the break falls, which is the hardest kind of defect to see.
 *
 * WIDTH IS A PERCENTAGE, and that is the whole answer to "how should this
 * behave on narrower paper". The content column is derived from the page box,
 * so 50% is half a column on A4 and half a column on A5 with nothing restated.
 * A millimetre width would have to be re-authored per paper size and would
 * silently overflow the sizes nobody re-authored it for.
 */

const { validateFlowPayload } = require('../src/flow/document');
const { buildFlowHtml } = require('../src/flow/html');

const withBlock = (block) => ({ content: [{ type: 'paragraph', text: 'body' }, block] });

/**
 * The rendered HTML for a single-block document, WITHOUT the inlined stylesheet
 * and paginator.
 *
 * `buildFlowHtml` inlines both, and both mention every class and data attribute
 * this file asserts about — so a naive "must not contain" would match the CSS
 * rule rather than the block, and would pass whatever the block markup said.
 * Stripping them first is what makes the negative assertions mean anything.
 */
function html(block) {
  return rawHtml(block)
    .replace(/<style[\s\S]*?<\/style>/g, '')
    .replace(/<script[\s\S]*?<\/script>/g, '');
}

function rawHtml(block) {
  return buildFlowHtml({
    page: { widthMm: 210, heightMm: 297, margin: { top: 20, right: 20, bottom: 20, left: 20 } },
    direction: 'ltr',
    lang: 'en',
    labels: {},
    header: null,
    footer: null,
    frontMatter: [],
    content: [block],
    index: { headings: [], tables: [], figures: [] },
  });
}

describe('validating the layout keys', () => {
  test('a block with no layout keys is still fine', () => {
    expect(validateFlowPayload(withBlock({ type: 'paragraph', text: 'x' }))).toBeNull();
  });

  test('accepts spacing, break hints and a width', () => {
    expect(
      validateFlowPayload(
        withBlock({
          type: 'paragraph',
          text: 'x',
          spaceBeforeMm: 6,
          spaceAfterMm: 0,
          breakBefore: true,
          keepWithNext: true,
          keepTogether: false,
          widthPercent: 50,
        })
      )
    ).toBeNull();
  });

  test('refuses a negative or non-numeric space', () => {
    expect(validateFlowPayload(withBlock({ type: 'paragraph', text: 'x', spaceBeforeMm: -1 }))).toMatch(
      /spaceBeforeMm/
    );
    expect(validateFlowPayload(withBlock({ type: 'paragraph', text: 'x', spaceAfterMm: 'wide' }))).toMatch(
      /spaceAfterMm/
    );
  });

  /**
   * Past half a page a "space" is a page break expressed badly — and one the
   * paginator would have to fragment around on every page it touched. The
   * message points at the thing that says it exactly.
   */
  test('refuses a space larger than half a page, and says what to use instead', () => {
    const error = validateFlowPayload(withBlock({ type: 'paragraph', text: 'x', spaceBeforeMm: 200 }));
    expect(error).toMatch(/page break/i);
  });

  test('refuses a non-boolean break hint', () => {
    expect(validateFlowPayload(withBlock({ type: 'paragraph', text: 'x', keepWithNext: 'yes' }))).toMatch(
      /keepWithNext/
    );
  });

  test('refuses a width outside the readable range', () => {
    expect(validateFlowPayload(withBlock({ type: 'figure', src: 'data:,x', widthPercent: 5 }))).toMatch(
      /widthPercent/
    );
    expect(validateFlowPayload(withBlock({ type: 'figure', src: 'data:,x', widthPercent: 120 }))).toMatch(
      /widthPercent/
    );
    expect(validateFlowPayload(withBlock({ type: 'figure', src: 'data:,x', widthPercent: 100 }))).toBeNull();
  });

  /**
   * REFUSED, NOT IGNORED. A page break is a boundary rather than a thing on the
   * page, and a spacer IS vertical space. Accepting the key and dropping it
   * would give an editor a control somebody could change forever with no
   * effect — the defect this whole line of work keeps turning up.
   */
  test('refuses layout on blocks that have no box', () => {
    expect(validateFlowPayload(withBlock({ type: 'pageBreak', spaceBeforeMm: 5 }))).toMatch(/pageBreak/);
    expect(validateFlowPayload(withBlock({ type: 'spacer', heightMm: 5, widthPercent: 50 }))).toMatch(
      /spacer/
    );
  });
});

describe('what reaches the HTML', () => {
  test('a plain block carries no layout of any kind', () => {
    const out = html({ type: 'paragraph', text: 'body' });
    expect(out).not.toMatch(/flow-space-before|flow-space-after|flow-width/);
    expect(out).not.toMatch(/data-keep-with-next|data-keep-together|data-break-before/);
  });

  /**
   * THE ONE THAT MATTERS. A custom property, not `style="margin-top:6mm"`.
   * An inline margin beats `.flow-page-content > :first-child { margin-top: 0 }`
   * on specificity, so the reset that keeps a page starting where every other
   * page starts would stop working — on exactly the pages where a spaced block
   * lands first.
   */
  test('spacing is a custom property and never an inline margin', () => {
    const out = html({ type: 'paragraph', text: 'body', spaceBeforeMm: 6 });

    expect(out).toContain('--flow-space-before:6mm');
    expect(out).toContain('flow-space-before');
    expect(out).not.toMatch(/style="[^"]*margin-top/);
  });

  test('space after is emitted the same way', () => {
    const out = html({ type: 'paragraph', text: 'body', spaceAfterMm: 4 });
    expect(out).toContain('--flow-space-after:4mm');
    expect(out).not.toMatch(/style="[^"]*margin-bottom/);
  });

  test('zero spacing emits nothing rather than a rule that changes nothing', () => {
    const out = html({ type: 'paragraph', text: 'body', spaceBeforeMm: 0 });
    expect(out).not.toContain('flow-space-before');
  });

  test('a width is emitted as the percentage it was given', () => {
    const out = html({ type: 'figure', src: 'data:image/png;base64,AA', widthPercent: 50, anchorId: 'f-1' });
    expect(out).toContain('--flow-width:50%');
    expect(out).toContain('flow-width');
  });

  /** Full width is the default, so saying it explicitly would add a rule that does nothing. */
  test('a hundred per cent emits no width', () => {
    const out = html({ type: 'figure', src: 'data:image/png;base64,AA', widthPercent: 100, anchorId: 'f-1' });
    expect(out).not.toContain('flow-width');
  });

  test('break hints reach the paginator as data attributes', () => {
    const out = html({
      type: 'paragraph',
      text: 'body',
      breakBefore: true,
      keepWithNext: true,
      keepTogether: true,
    });

    expect(out).toContain('data-break-before="1"');
    expect(out).toContain('data-keep-with-next="1"');
    expect(out).toContain('data-keep-together="1"');
  });

  test('a false hint is absent rather than emitted as zero', () => {
    const out = html({ type: 'paragraph', text: 'body', keepWithNext: false });
    expect(out).not.toContain('data-keep-with-next');
  });

  test('layout works on every block type that has a box', () => {
    for (const block of [
      { type: 'heading', level: 1, text: 'H', anchorId: 'h-1' },
      { type: 'paragraph', text: 'p' },
      { type: 'table', rows: [['a']], columns: ['A'], anchorId: 't-1' },
      { type: 'figure', src: 'data:image/png;base64,AA', anchorId: 'f-1' },
    ]) {
      const out = html({ ...block, spaceBeforeMm: 3, keepWithNext: true });
      expect(out).toContain('--flow-space-before:3mm');
      expect(out).toContain('data-keep-with-next="1"');
    }
  });

  /** A figure's own height must survive alongside the layout custom properties. */
  test('a figure keeps its height when it also carries layout', () => {
    const out = html({
      type: 'figure',
      src: 'data:image/png;base64,AA',
      heightMm: 40,
      spaceBeforeMm: 5,
      anchorId: 'f-1',
    });

    expect(out).toContain('height:40mm');
    expect(out).toContain('--flow-space-before:5mm');
  });
});
