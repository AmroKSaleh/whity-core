'use strict';

/**
 * Puppeteer orchestration for the FLOWING render mode (#1072, first half).
 *
 * Deliberately a separate file from src/renderer.js. The two modes share one
 * thing — the Chromium instance, which is expensive and must stay shared —
 * and nothing else: not the harness, not the stylesheet, not the page
 * geometry, not the readiness signal. Keeping them apart is what makes it
 * true, rather than merely intended, that adding a flowing mode cannot move a
 * fixed-canvas render by a millimetre.
 *
 * The geometry, in one place so it is checkable:
 *
 *   `@page { size: WxH mm; margin: 0 }` and `page.pdf({ margin: 0 })`, exactly
 *   as the fixed-canvas mode does — because by the time Chromium sees this
 *   document, pagination has ALREADY happened. The paginator has emitted one
 *   `.flow-page` element per output page at the exact physical size, so
 *   Chromium's only job is to print each box onto one sheet. The document's
 *   real margins are the inset of the content box inside those boxes, which is
 *   what leaves room for the running header and footer to be DRAWN, in the
 *   margin, in CSS, in the document's own direction.
 *
 *   Puppeteer's own `displayHeaderFooter` is not used, and that is a choice
 *   rather than an omission — see the note at the bottom of this file.
 */

const { getBrowser } = require('../renderer');
const { normaliseFlowDocument } = require('./document');
const { buildFlowHtml } = require('./html');

const NAV_TIMEOUT_MS = Number(process.env.RENDER_FLOW_NAV_TIMEOUT_MS || 30000);
/* Pagination is real work on a real DOM — the whole document is measured and
 * packed — and it scales with the document, not with the request. A
 * hundred-and-twenty-page document takes seconds, not milliseconds, so this
 * ceiling is generous and env-overridable rather than borrowed from the
 * fixed-canvas mode's 20 s, which was sized for a single designed page. */
const READY_TIMEOUT_MS = Number(process.env.RENDER_FLOW_READY_TIMEOUT_MS || 180000);
const PDF_TIMEOUT_MS = Number(process.env.RENDER_FLOW_PDF_TIMEOUT_MS || 180000);

/**
 * Render one flow payload to PDF bytes plus the pagination record.
 *
 * @param {object} payload The raw request body (already shape-validated).
 * @returns {Promise<{pdf: Buffer, pagination: {pageCount: number, frontMatterPages: number,
 *          bodyPages: number, frontMatterPasses: number, anchors: Record<string, number>,
 *          paginateMs: number}}>}
 */
async function renderFlowToPdf(payload) {
  const doc = normaliseFlowDocument(payload);
  const html = buildFlowHtml(doc);

  const browser = await getBrowser();
  const page = await browser.newPage();
  const timings = {};
  const startedAt = Date.now();
  try {
    await page.setContent(html, { waitUntil: 'load', timeout: NAV_TIMEOUT_MS });
    timings.loadMs = Date.now() - startedAt;

    const paginateStartedAt = Date.now();
    await page.waitForFunction('window.__FLOW_READY__ === true', { timeout: READY_TIMEOUT_MS });
    timings.paginateMs = Date.now() - paginateStartedAt;

    const outcome = await page.evaluate(() => ({
      error: window.__FLOW_ERROR__ || null,
      result: window.__FLOW_RESULT__ || null,
    }));

    if (outcome.error) {
      throw new Error(`Flow pagination failed in the page: ${outcome.error}`);
    }
    if (!outcome.result) {
      throw new Error('Flow pagination signalled ready without producing a result');
    }
    if (outcome.result.pageCount < 1) {
      throw new Error('Flow pagination produced no pages');
    }
    if (Array.isArray(outcome.result.problems) && outcome.result.problems.length > 0) {
      // Refusing here is the point. Either a unit was placed where it does not
      // fit — so at least one recorded page number is a claim about a layout
      // that did not happen — or a front-matter entry prints a number that
      // disagrees with where its anchor was recorded, which is what a
      // non-converged front-matter loop looks like. Both make every
      // cross-reference after them suspect, and a contents list of
      // plausible-looking wrong numbers is a worse outcome than no contents
      // list, so this fails the render instead of shipping one.
      const first = outcome.result.problems[0];
      throw new Error(
        `Flow pagination problem on page ${first.page}: ${first.reason}` +
          (outcome.result.problems.length > 1 ? ` (and ${outcome.result.problems.length - 1} more)` : '')
      );
    }

    const pdfStartedAt = Date.now();
    const pdf = await page.pdf({
      printBackground: true,
      preferCSSPageSize: true,
      margin: { top: '0mm', right: '0mm', bottom: '0mm', left: '0mm' },
      timeout: PDF_TIMEOUT_MS,
    });
    timings.pdfMs = Date.now() - pdfStartedAt;
    timings.totalMs = Date.now() - startedAt;

    return { pdf: Buffer.from(pdf), pagination: { ...outcome.result, timings } };
  } finally {
    await page.close().catch(() => {});
  }
}

module.exports = { renderFlowToPdf };

/*
 * WHY `displayHeaderFooter` IS NOT USED
 *
 * #1072 notes that Puppeteer supports `displayHeaderFooter` with
 * `headerTemplate`/`footerTemplate` and that it is unused today because
 * `margin: 0` leaves nowhere to draw it. Both halves are true; the conclusion
 * that a flowing mode should therefore switch it on is not, and the reasons
 * are worth writing down rather than rediscovering.
 *
 *   1. Chromium renders those templates in a SEPARATE document with its own
 *      default stylesheet, no access to the page's CSS custom properties, and
 *      a default `font-size: 0` that every user of the feature has to work
 *      around inline. Everything the document knows about itself — its font
 *      stack, its direction, its colours — has to be restated there.
 *
 *   2. It cannot say what page 74 is ABOUT. A running head worth printing
 *      names the section, and the template is given only `pageNumber`,
 *      `totalPages`, `title`, `url` and `date`. There is no seam through which
 *      a per-page value could be passed.
 *
 *   3. Its templates are LTR documents. Getting an Arabic running head to sit
 *      correctly means restating `dir="rtl"` and the isolation rules inside a
 *      context nothing else in this service can see or test.
 *
 *   4. It would fight the page geometry. Chromium reserves the header/footer
 *      bands out of the PDF margin, and this mode deliberately prints at
 *      `margin: 0` with the page box drawn in CSS, so the two would be
 *      measuring against different boxes.
 *
 * Drawing the bands in the document instead costs nothing (they are absolutely
 * positioned inside each page box, in the margin, where a running head goes),
 * gives them the document's own font, direction and bidi isolation, and lets
 * them carry `{{section}}` — which is the reason to have running heads at all.
 * The requirement the issue is really making — REAL MARGINS, and running
 * headers and footers that appear on every page — is met; the mechanism it
 * guessed at is not the one that meets it.
 */
