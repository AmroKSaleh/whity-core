'use strict';

/**
 * BOOT-TIME BROWSER CAPABILITY PROBE (#1134).
 *
 * ── Why ──────────────────────────────────────────────────────────────────────
 *
 * `render-service/Dockerfile` installs `chromium` unpinned. The flowing mode's
 * paginator (src/flow/assets/paginate.js) is ~840 lines of measuring and
 * fragmenting content against the browser's own layout, and it is guarded by a
 * refuse-on-disagreement check rather than by a specification. Every number it
 * rests on was measured against one browser build. When a rebuild moves that
 * build, the failure is not a crash: it is a hundred-page document that
 * paginates differently, or a guard that begins refusing renders that used to
 * succeed — with nothing in this repository's history to attribute it to.
 *
 * This asserts the behaviours the paginator actually relies on, once, at
 * container start, so a browser that no longer behaves the way the design
 * assumes says so at boot instead of at render time.
 *
 * ── The rule that decides what is FATAL ──────────────────────────────────────
 *
 * A probe is REQUIRED only when the paginator's arithmetic is WRONG without
 * it. Everything else is INFORMATIONAL — reported, logged loudly, never a
 * reason to stop serving. Three consequences of that rule are worth stating
 * outright, because each is a place where the obvious choice is the wrong one:
 *
 *  1. THE MISSING FEATURES ARE NOT REQUIRED TO STAY MISSING. `target-counter()`,
 *     `string-set`/`string()` and `position: running()` are absent on Chromium
 *     151, and their absence is the entire reason this service paginates for
 *     itself. If a future Chromium implements them, that is an OPPORTUNITY —
 *     a simpler design becomes available — and it must not take the render
 *     tier down. Their arrival is `notable`, and it names #1134 in the log so
 *     whoever sees it knows what it unlocks.
 *
 *  2. THE PAGED-MEDIA FEATURES THAT DO WORK ARE NOT REQUIRED EITHER. `@page`
 *     margin boxes, `@page :first`, `@page :left`/`:right` and named pages all
 *     work on Chromium 151 (recorded in docs/wiki/Document-Render-Service.md),
 *     and NEITHER render mode uses any of them — the flowing mode prints at
 *     `margin: 0` and draws its own bands in-document. Failing a boot on a
 *     feature nothing depends on would turn a diagnostic into precisely the
 *     new outage source this change must not become.
 *
 *  3. "CANNOT DETERMINE" IS NEVER A FAILURE. A probe that could not get an
 *     answer — an unparseable PDF, a browser that would not launch — reports
 *     `unknown`. Disagreement and ignorance are different findings and only
 *     one of them means something changed.
 *
 * ── Probing at a level where the answer is real ──────────────────────────────
 *
 * `CSS.supports()` LIES HERE. It returns **true** for
 * `target-counter(attr(href), page)` on Chromium 151 while the declaration is
 * dropped at computed-value time and nothing is printed. Verified again by
 * this probe, which records what `CSS.supports` claims beside what the
 * computed style actually says, so the trap is visible in the report rather
 * than only in a wiki paragraph someone has to find.
 *
 * So every probe here answers at a level where the answer is real: computed
 * style for the property-level questions, and the BYTES OF A RENDERED PDF for
 * the print-level ones. Nothing is decided from a parse check.
 *
 * ── What is deliberately NOT probed ──────────────────────────────────────────
 *
 * `@page` margin boxes and `counter(page)`/`counter(pages)`. Both only manifest
 * as TEXT in printed output, and reading that text back honestly needs a
 * font-aware PDF parser — `pdfjs-dist` is a devDependency and is not in the
 * runtime image. The available shortcut (asking the CSSOM whether the at-rule
 * parsed) is exactly the `CSS.supports` class of answer this file refuses to
 * take. Since nothing in either render mode uses them, the right move is to
 * leave them unprobed and say so, rather than to ship a check that reports
 * success without looking.
 *
 * ── Cost, and where it is paid ───────────────────────────────────────────────
 *
 * Three page loads and two `page.pdf()` calls on trivial documents — a few
 * hundred milliseconds of actual work; the browser LAUNCH dominates and is
 * environment-dependent. So the probe never blocks `listen()`: the server is
 * accepting requests immediately and `/health` reports `pending` until the
 * probe lands. `POST /render/flow` awaits it, because that is the one caller
 * whose correctness the answer bears on.
 *
 * ── Its own browser, not the shared one ──────────────────────────────────────
 *
 * The probe launches a browser of its own and closes it, rather than warming
 * the memoised instance in src/renderer.js. Two reasons:
 *
 *   - A detector must not be able to cause the outage it detects. The shared
 *     instance is held for the life of the process; a probe that wedged or
 *     crashed it would take every subsequent render with it.
 *   - docs/wiki/Document-Render-Service.md records idle memory as ~32 MB,
 *     "Chromium not launched yet". Warming the shared instance at boot would
 *     silently make every deployed-but-unused render container carry ~250 MB,
 *     and operators size hosts off that table.
 *
 * It launches with the SAME executable and the SAME flags the render path uses
 * (imported from src/renderer.js, not restated) — a probe run under different
 * flags is measuring a browser this service does not run.
 */

const puppeteer = require('puppeteer-core');

const { CHROMIUM_LAUNCH_ARGS, chromiumExecutablePath } = require('./renderer');

/** CSS's own definition: 96px to the inch, 25.4mm to the inch. */
const PX_PER_MM = 96 / 25.4;

/** PostScript points to millimetres — a PDF `/MediaBox` is in points. */
const PT_PER_MM = 72 / 25.4;

/**
 * How far a printed page box may sit from its `@page size` before it counts as
 * wrong, in points.
 *
 * Chromium quantises layout to 1/64 px, so A4 comes out as 594.96 x 841.92 pt
 * rather than the arithmetic 595.28 x 841.89 — a third of a point, and not a
 * bug. The failure this probe is looking for is categorical: `preferCSSPageSize`
 * being ignored and the page falling back to Letter (612 x 792) or to A4 with
 * default margins, which is off by tens of points. 2 pt discriminates those
 * without failing on rounding.
 */
const MEDIA_BOX_TOLERANCE_PT = 2;

const PROBE_TIMEOUT_MS = Number(process.env.RENDER_PROBE_TIMEOUT_MS || 60000);

/** Verdicts. `pending`/`not_run` are set by the server, not by this module. */

/** Every probe answered, and every answer matched what was recorded. */
const STATUS_OK = 'ok';

/** An INFORMATIONAL behaviour changed. Nothing is gated; see summarise(). */
const STATUS_NOTABLE = 'notable';

/**
 * A REQUIRED probe could not get an answer.
 *
 * Distinct from `ok` on purpose, and it is the status this file exists to
 * avoid getting wrong: a probe that measured nothing and reported success is
 * the exact shape of a check that never looked. Distinct from `degraded` too —
 * nothing is gated, because ignorance is not evidence that anything changed.
 */
const STATUS_INCONCLUSIVE = 'inconclusive';

/** A REQUIRED behaviour disagreed. `POST /render/flow` is refused. */
const STATUS_DEGRADED = 'degraded';

/** The probe itself could not run. Nothing is gated — see src/server.js. */
const STATUS_ERROR = 'error';
const STATUS_PENDING = 'pending';
const STATUS_NOT_RUN = 'not_run';

/* ------------------------------------------------------------------ PDF ---- */

/**
 * The page boxes of a PDF, read out of the raw bytes.
 *
 * Not a PDF parser and not trying to be one: it finds every `/MediaBox` and
 * cross-checks the count against the page tree's `/Count`. When the two
 * disagree — or a future Chromium compresses the page tree into object streams
 * where neither is findable — this answers `parsed: false`, which every caller
 * treats as `unknown` rather than as a failure. Being unable to read the PDF is
 * not evidence that the browser changed.
 *
 * `pdfjs-dist` would do this properly, but it is a devDependency and the
 * runtime image installs `--omit=dev`; a boot probe that only works outside the
 * shipped image is not a boot probe.
 *
 * @param {Buffer} buffer
 * @returns {{parsed: boolean, pageCount: number|null, mediaBoxes: Array<{widthPt: number, heightPt: number}>}}
 */
function parsePdfPageBoxes(buffer) {
  const empty = { parsed: false, pageCount: null, mediaBoxes: [] };

  if (!Buffer.isBuffer(buffer) || buffer.length === 0) {
    return empty;
  }

  const text = buffer.toString('latin1');

  if (!text.startsWith('%PDF-')) {
    return empty;
  }

  const boxes = [];
  const boxPattern = /\/MediaBox\s*\[\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\]/g;
  let match = boxPattern.exec(text);
  while (match !== null) {
    boxes.push({
      widthPt: Number(match[3]) - Number(match[1]),
      heightPt: Number(match[4]) - Number(match[2]),
    });
    match = boxPattern.exec(text);
  }

  if (boxes.length === 0) {
    return empty;
  }

  const counts = [];
  const countPattern = /\/Count\s+(\d+)/g;
  let countMatch = countPattern.exec(text);
  while (countMatch !== null) {
    counts.push(Number(countMatch[1]));
    countMatch = countPattern.exec(text);
  }

  // The page tree root's `/Count` is the largest one in a document with no
  // outline; a disagreement with the MediaBox tally means this reading is not
  // trustworthy, so it declines to answer rather than picking a side.
  if (counts.length > 0 && Math.max(...counts) !== boxes.length) {
    return empty;
  }

  return { parsed: true, pageCount: boxes.length, mediaBoxes: boxes };
}

/** Whether a printed page box matches a millimetre size, within tolerance. */
function boxMatchesMm(box, widthMm, heightMm) {
  if (!box) {
    return false;
  }
  return (
    Math.abs(box.widthPt - widthMm * PT_PER_MM) <= MEDIA_BOX_TOLERANCE_PT &&
    Math.abs(box.heightPt - heightMm * PT_PER_MM) <= MEDIA_BOX_TOLERANCE_PT
  );
}

function describeBox(box) {
  if (!box) {
    return 'no page';
  }
  return `${(box.widthPt / PT_PER_MM).toFixed(1)}x${(box.heightPt / PT_PER_MM).toFixed(1)}mm`;
}

/* -------------------------------------------------------------- documents -- */

/**
 * The DOM-layout document. Two independent paragraphs of the same shape: one
 * is measured, the other is destroyed by the `extractContents()` probe, so the
 * destructive check cannot perturb the measurements.
 */
const LAYOUT_HTML = `<!doctype html>
<html><head><meta charset="utf-8"><style>
  html, body { margin: 0; padding: 0 }
  .probe-para { width: 180px; font: 16px/24px sans-serif; text-align: justify }
  #probe-mm { position: absolute; visibility: hidden; width: 100mm; height: 0 }
</style></head><body>
<div class="probe-para" id="probe-measure">aaa bbb ccc ddd eee fff ggg hhh iii jjj kkk lll mmm nnn ooo ppp qqq rrr</div>
<div class="probe-para" id="probe-split">aaa bbb ccc ddd eee fff ggg hhh iii jjj kkk lll mmm nnn ooo ppp qqq rrr</div>
<h1 id="probe-heading">heading</h1>
<a id="probe-link" href="#probe-heading">link</a>
<p id="probe-para">para</p>
<div id="probe-mm"></div>
</body></html>`;

/**
 * The PRINT-GEOMETRY document — required probes.
 *
 * Mirrors `.flow-page` from src/flow/assets/flow.css exactly: a positioned,
 * overflow-hidden box at the full physical page size carrying
 * `break-after: page`, under `@page { size: ...; margin: 0 }`. That is the one
 * contract the flowing mode's whole approach rests on — the paginator has
 * already decided what goes on each page, and Chromium's only remaining job is
 * to print one box per sheet at the size the CSS asked for.
 *
 * Uniform page size on purpose: the informational `@page :first` / named-page
 * probes live in a SEPARATE document, so a feature going away there can never
 * cascade into a required page-count failure here.
 */
const PRINT_GEOMETRY_HTML = `<!doctype html>
<html><head><meta charset="utf-8"><style>
  html, body { margin: 0; padding: 0 }
  @page { size: 210mm 297mm; margin: 0 }
  .probe-page {
    position: relative; overflow: hidden;
    width: 210mm; height: 297mm;
    break-after: page; page-break-after: always;
  }
  .probe-page:last-child { break-after: auto; page-break-after: auto }
</style></head><body>
<div class="probe-page">1</div><div class="probe-page">2</div><div class="probe-page">3</div>
</body></html>`;

/**
 * The PAGED-MEDIA document — informational probes.
 *
 * `@page :first` and named pages both change a page's SIZE, which is readable
 * from `/MediaBox` alone: no text extraction, no font parsing, and a real
 * rendered-output answer rather than a parse check. Boxes are small enough to
 * fit whichever page size they land on, so an unsupported feature changes a
 * page's dimensions and never its page count.
 */
const PAGED_MEDIA_HTML = `<!doctype html>
<html><head><meta charset="utf-8"><style>
  html, body { margin: 0; padding: 0 }
  @page { size: 210mm 297mm; margin: 0 }
  @page :first { size: 148mm 210mm }
  @page whityprobewide { size: 297mm 210mm }
  .probe-box { width: 90mm; height: 90mm; break-after: page; page-break-after: always }
  .probe-box.wide { page: whityprobewide }
  .probe-box:last-child { break-after: auto; page-break-after: auto }
</style></head><body>
<div class="probe-box">1</div><div class="probe-box wide">2</div><div class="probe-box">3</div>
</body></html>`;

/**
 * The two paged-media facts that manifest ONLY as printed text: a margin box,
 * and `counter(page)` / `counter(pages)` inside one.
 *
 * Deliberately NOT probed at boot. Reading them back honestly needs a PDF text
 * extractor, and `pdfjs-dist` is a devDependency absent from the runtime image
 * — shipping a parser into production to serve a diagnostic is a poor trade.
 * The CSSOM shortcut is not an option either: it is the same class of lie as
 * `CSS.supports('content', 'target-counter(…)')`, which answers `true` for a
 * declaration Chromium drops at computed-value time.
 *
 * So this fixture is rendered by the IMAGE's browser (which is the only thing
 * that can) and verified on the CI runner (which is the only thing with a
 * parser). See `scripts/render-paged-media-probe.js` and
 * `scripts/verify-paged-media-text.js`.
 *
 * The margin is non-zero on purpose. `margin: 0` is right for the fixed-canvas
 * mode and leaves nowhere to draw a margin box — which is the whole reason
 * #1072 gives for why running headers and footers were unavailable.
 *
 * Three blocks, so the count is a number a mistake could not coincidentally
 * produce: a footer reading "1 of 1" on every page is a failure this catches,
 * and would look plausible with a single-page fixture.
 */
const PAGED_MEDIA_TEXT_HTML = `<!doctype html>
<html><head><meta charset="utf-8"><style>
  html, body { margin: 0; padding: 0 }
  @page {
    size: 210mm 297mm;
    margin: 20mm;
    @bottom-center { content: counter(page) " of " counter(pages); }
  }
  .probe-block { height: 200mm; break-after: page; page-break-after: always }
  .probe-block:last-child { break-after: auto; page-break-after: auto }
</style></head><body>
<div class="probe-block">A</div><div class="probe-block">B</div><div class="probe-block">C</div>
</body></html>`;

/** How many pages {@link PAGED_MEDIA_TEXT_HTML} must produce. */
const PAGED_MEDIA_TEXT_PAGES = 3;

/* ------------------------------------------------------- in-page measuring -- */

/**
 * Runs INSIDE the browser. Returns raw measurements only — every verdict is
 * reached in Node, where it can be unit-tested without a browser.
 *
 * Serialised to the page by `page.evaluate`, so it must close over nothing.
 */
/* istanbul ignore next -- executed in the browser, not in this process */
function measureInPage() {
  var out = {};

  var measure = document.getElementById('probe-measure');
  var lineHeightPx = parseFloat(getComputedStyle(measure).lineHeight);
  out.lineHeightPx = lineHeightPx;
  out.elementHeightPx = measure.getBoundingClientRect().height;

  // (1) One client rect per LINE BOX. `splitParagraph()` treats each rect as a
  // line and each rect's `bottom` as that line's baseline-ish bottom.
  var contents = document.createRange();
  contents.selectNodeContents(measure);
  var rects = Array.prototype.filter.call(contents.getClientRects(), function (r) {
    return r.height > 0.5;
  });
  out.lineRectCount = rects.length;
  out.lineRectsOrdered = true;
  for (var i = 1; i < rects.length; i += 1) {
    if (!(rects[i].top >= rects[i - 1].top - 0.5) || !(rects[i].bottom > rects[i - 1].bottom + 0.5)) {
      out.lineRectsOrdered = false;
    }
  }

  // (2) A PREFIX range's bottom grows monotonically with its end offset, and
  // the full prefix reaches the last line. This is the invariant the binary
  // search in `findOffsetForLine()` searches over; without it that search
  // converges on an arbitrary offset and paragraphs split in the wrong place.
  var text = measure.firstChild;
  var bottoms = [];
  var prefix = document.createRange();
  for (var offset = 1; offset <= text.data.length; offset += 1) {
    prefix.setStart(measure, 0);
    prefix.setEnd(text, offset);
    var rect = prefix.getBoundingClientRect();
    bottoms.push(rect.height === 0 ? null : rect.bottom);
  }
  var seen = [];
  out.prefixMonotonic = true;
  var previous = -Infinity;
  for (var b = 0; b < bottoms.length; b += 1) {
    if (bottoms[b] === null) {
      continue;
    }
    if (bottoms[b] < previous - 0.5) {
      out.prefixMonotonic = false;
    }
    previous = bottoms[b];
    if (seen.indexOf(Math.round(bottoms[b] * 10)) === -1) {
      seen.push(Math.round(bottoms[b] * 10));
    }
  }
  out.prefixDistinctBottoms = seen.length;
  var lastBottom = bottoms.length > 0 ? bottoms[bottoms.length - 1] : null;
  var elementBottom = measure.getBoundingClientRect().bottom;
  out.prefixReachesLastLine =
    lastBottom !== null && Math.abs(lastBottom - elementBottom) <= lineHeightPx;

  // (3) `Range.extractContents()` actually MOVES the prefix out. This is how a
  // split paragraph's head is built; if it ever cloned instead of moved, the
  // text would print twice and the page it was measured for would be wrong.
  var splitEl = document.getElementById('probe-split');
  var splitText = splitEl.firstChild;
  var cut = Math.floor(splitText.data.length / 2);
  var whole = splitText.data;
  var cutRange = document.createRange();
  cutRange.setStart(splitEl, 0);
  cutRange.setEnd(splitText, cut);
  var head = splitEl.cloneNode(false);
  head.appendChild(cutRange.extractContents());
  out.extractHeadMatches = head.textContent === whole.slice(0, cut);
  out.extractTailMatches = splitEl.textContent === whole.slice(cut);

  // (4) The millimetre, as this browser lays it out. Everything the paginator
  // computes is in this coordinate system.
  var mmProbe = document.getElementById('probe-mm');
  var mmWidth = mmProbe.getBoundingClientRect().width;
  out.pxPerMm = mmWidth > 0 ? mmWidth / 100 : null;

  // (5) The paged-media features whose ABSENCE is why the paginator exists,
  // read at computed-value time — the level where the answer is real. See the
  // `CSS.supports` warning in this file's header and in the wiki.
  var style = document.createElement('style');
  style.textContent =
    '#probe-link::after { content: target-counter(attr(href), page); }' +
    '#probe-heading { string-set: whityprobe content(); }' +
    '#probe-para::before { content: string(whityprobe); }' +
    '#probe-para { position: running(whityprobehdr); }';
  document.head.appendChild(style);

  out.cssSupportsTargetCounter =
    typeof CSS !== 'undefined' && typeof CSS.supports === 'function'
      ? CSS.supports('content', 'target-counter(attr(href), page)')
      : null;
  out.computedTargetCounter = getComputedStyle(document.getElementById('probe-link'), '::after').content;
  out.computedStringSet = getComputedStyle(document.getElementById('probe-heading')).getPropertyValue('string-set');
  out.computedStringFunction = getComputedStyle(document.getElementById('probe-para'), '::before').content;
  out.computedRunningPosition = getComputedStyle(document.getElementById('probe-para')).position;

  return out;
}

/* ------------------------------------------------------------- assembling -- */

/**
 * One probe result.
 *
 * `expected` is what Chromium 151 was measured doing and what
 * docs/wiki/Document-Render-Service.md records — `false` means the feature is
 * expected to be ABSENT. `observed` is null when the probe could not get an
 * answer, which is `unknown` and never a failure.
 */
function result(id, title, required, expected, observed, detail) {
  const verdict = observed === null ? 'unknown' : observed === expected ? 'as-recorded' : 'changed';
  return { id, title, required, expected, observed, verdict, detail };
}

/** Whether a computed `content` value carries something real. */
function contentIsSubstantive(value) {
  return typeof value === 'string' && value.trim() !== '' && value !== 'none' && value !== 'normal';
}

/**
 * Turn raw measurements into probe results.
 *
 * Pure, and exported, so every verdict in this file is testable without a
 * browser — the classification is the part that decides whether a container
 * refuses flow renders, and it should not need Chromium to be checked.
 *
 * @param {object|null} layout Output of measureInPage(), or null if it failed.
 * @param {{parsed: boolean, pageCount: number|null, mediaBoxes: Array}|null} geometry
 * @param {{parsed: boolean, pageCount: number|null, mediaBoxes: Array}|null} pagedMedia
 */
function buildResults(layout, geometry, pagedMedia) {
  const results = [];
  const l = layout || {};

  /* -- Required: the DOM-layout invariants the paginator's arithmetic uses -- */

  const expectedLines =
    typeof l.elementHeightPx === 'number' && typeof l.lineHeightPx === 'number' && l.lineHeightPx > 0
      ? Math.round(l.elementHeightPx / l.lineHeightPx)
      : null;
  results.push(
    result(
      'range-client-rects-per-line',
      'Range.getClientRects() returns one rect per line box',
      true,
      true,
      layout === null || expectedLines === null
        ? null
        : l.lineRectCount === expectedLines && expectedLines >= 2 && l.lineRectsOrdered === true,
      layout === null
        ? 'not measured'
        : `${l.lineRectCount} rects for ${expectedLines} line boxes (ordered: ${l.lineRectsOrdered})`
    )
  );

  results.push(
    result(
      'range-prefix-bottom-monotonic',
      'a prefix Range bottom grows monotonically and reaches the last line',
      true,
      true,
      layout === null
        ? null
        : l.prefixMonotonic === true && l.prefixDistinctBottoms >= 2 && l.prefixReachesLastLine === true,
      layout === null
        ? 'not measured'
        : `monotonic: ${l.prefixMonotonic}, ${l.prefixDistinctBottoms} distinct bottoms, reaches last line: ${l.prefixReachesLastLine}`
    )
  );

  results.push(
    result(
      'range-extract-contents-moves-text',
      'Range.extractContents() moves the prefix out of the paragraph',
      true,
      true,
      layout === null ? null : l.extractHeadMatches === true && l.extractTailMatches === true,
      layout === null ? 'not measured' : `head: ${l.extractHeadMatches}, tail: ${l.extractTailMatches}`
    )
  );

  /* -- Required: the print-geometry contract both render modes rest on ----- */

  const geometryParsed = geometry !== null && geometry.parsed === true;
  results.push(
    result(
      'print-one-page-per-forced-break',
      'break-after: page yields exactly one PDF page per page box',
      true,
      true,
      geometryParsed ? geometry.pageCount === 3 : null,
      geometryParsed ? `${geometry.pageCount} PDF pages for 3 page boxes` : 'PDF page tree not readable'
    )
  );

  results.push(
    result(
      'print-honours-css-page-size',
      'preferCSSPageSize prints at the @page size in exact millimetres',
      true,
      true,
      geometryParsed
        ? geometry.mediaBoxes.length > 0 && geometry.mediaBoxes.every((box) => boxMatchesMm(box, 210, 297))
        : null,
      geometryParsed
        ? `page boxes: ${geometry.mediaBoxes.map(describeBox).join(', ')} (expected 210.0x297.0mm)`
        : 'PDF page tree not readable'
    )
  );

  /* -- Informational: the coordinate system --------------------------------- */

  results.push(
    result(
      'css-mm-at-96dpi',
      'a CSS millimetre lays out at the 96dpi ratio',
      false,
      true,
      typeof l.pxPerMm === 'number' && l.pxPerMm > 0
        ? Math.abs(l.pxPerMm - PX_PER_MM) / PX_PER_MM < 0.005
        : null,
      typeof l.pxPerMm === 'number' ? `${l.pxPerMm.toFixed(6)} px/mm (CSS: ${PX_PER_MM.toFixed(6)})` : 'not measured'
    )
  );

  /* -- Informational: the ABSENCES that justify the paginator's existence --- */

  results.push(
    result(
      'css-target-counter',
      'CSS target-counter() resolves a cross-reference page number',
      false,
      false,
      layout === null ? null : contentIsSubstantive(l.computedTargetCounter),
      layout === null
        ? 'not measured'
        : `computed ::after content is ${JSON.stringify(l.computedTargetCounter)}; ` +
          `CSS.supports() claims ${l.cssSupportsTargetCounter} (it lies here — see #1134)`
    )
  );

  results.push(
    result(
      'css-string-set',
      'CSS string-set captures a running section name',
      false,
      false,
      layout === null ? null : typeof l.computedStringSet === 'string' && l.computedStringSet.trim() !== '',
      layout === null ? 'not measured' : `computed string-set is ${JSON.stringify(l.computedStringSet)}`
    )
  );

  results.push(
    result(
      'css-string-function',
      'CSS string() prints a captured running section name',
      false,
      false,
      layout === null ? null : contentIsSubstantive(l.computedStringFunction),
      layout === null ? 'not measured' : `computed ::before content is ${JSON.stringify(l.computedStringFunction)}`
    )
  );

  results.push(
    result(
      'css-position-running',
      'CSS position: running() moves an element into a page margin box',
      false,
      false,
      layout === null ? null : typeof l.computedRunningPosition === 'string' && l.computedRunningPosition !== 'static',
      layout === null ? 'not measured' : `computed position is ${JSON.stringify(l.computedRunningPosition)}`
    )
  );

  /* -- Informational: paged-media features that work and nothing uses ------- */

  const pagedParsed = pagedMedia !== null && pagedMedia.parsed === true && pagedMedia.mediaBoxes.length === 3;

  results.push(
    result(
      'css-page-first-pseudo',
      '@page :first gives the first page its own size',
      false,
      true,
      pagedParsed ? boxMatchesMm(pagedMedia.mediaBoxes[0], 148, 210) : null,
      pagedParsed
        ? `first page is ${describeBox(pagedMedia.mediaBoxes[0])} (expected 148.0x210.0mm)`
        : 'PDF page tree not readable'
    )
  );

  results.push(
    result(
      'css-named-pages',
      'a named @page gives a marked element its own page size',
      false,
      true,
      pagedParsed ? boxMatchesMm(pagedMedia.mediaBoxes[1], 297, 210) : null,
      pagedParsed
        ? `named page is ${describeBox(pagedMedia.mediaBoxes[1])} (expected 297.0x210.0mm)`
        : 'PDF page tree not readable'
    )
  );

  return results;
}

/**
 * The verdict over a set of results.
 *
 * `degraded` requires a REQUIRED probe to have disagreed — not to have been
 * unanswerable. `notable` covers every informational change in both directions:
 * a feature that went away, and a feature that arrived. The second is not a
 * problem, it is news, and it is the news that would let this service delete
 * ~840 lines of paginator.
 */
function summarise(results) {
  const requiredFailures = results.filter((r) => r.required && r.verdict === 'changed');
  const notable = results.filter((r) => !r.required && r.verdict === 'changed');
  const unknown = results.filter((r) => r.verdict === 'unknown');
  const requiredUnknown = unknown.filter((r) => r.required);

  const status =
    requiredFailures.length > 0
      ? STATUS_DEGRADED
      : requiredUnknown.length > 0
        ? STATUS_INCONCLUSIVE
        : notable.length > 0
          ? STATUS_NOTABLE
          : STATUS_OK;

  return {
    status,
    required_failures: requiredFailures.map((r) => `${r.id}: ${r.title} — ${r.detail}`),
    notable: notable.map(
      (r) => `${r.id}: ${r.expected ? 'NO LONGER TRUE' : 'NOW TRUE'} — ${r.title} — ${r.detail}`
    ),
    unknown: unknown.map((r) => r.id),
  };
}

/* ---------------------------------------------------------------- driver -- */

/** One page, loaded, measured, closed. */
async function withPage(browser, html, fn) {
  const page = await browser.newPage();
  try {
    await page.setContent(html, { waitUntil: 'load', timeout: PROBE_TIMEOUT_MS });
    return await fn(page);
  } finally {
    await page.close().catch(() => {});
  }
}

/**
 * Run the probe against a browser of this module's own.
 *
 * Never throws: an error anywhere becomes a report with `status: "error"`. The
 * probe is a detector, and a detector that can take the service down converts
 * a diagnostic into an outage — see the note in src/server.js about what
 * happens when this cannot run.
 *
 * @param {{launch?: Function}} [deps] Injection seam for tests.
 * @returns {Promise<object>} The report, always.
 */
async function runCapabilityProbe(deps = {}) {
  const launch = deps.launch || ((options) => puppeteer.launch(options));
  const startedAt = Date.now();
  let browser = null;

  try {
    browser = await launch({
      executablePath: chromiumExecutablePath(),
      headless: true,
      args: CHROMIUM_LAUNCH_ARGS,
      timeout: PROBE_TIMEOUT_MS,
      protocolTimeout: PROBE_TIMEOUT_MS,
    });

    // Reported per phase, not just as a total. The launch dominates and is
    // entirely environmental — sub-second on a Linux host, tens of seconds on
    // a Docker Desktop VM waiting out dbus timeouts — so an operator asking
    // "why did my container take a minute to report" needs to see WHICH part
    // took it before concluding anything about this code.
    const phases = { launch_ms: Date.now() - startedAt };

    const runningBanner = await browser.version().catch(() => null);

    const layoutStartedAt = Date.now();
    const layout = await withPage(browser, LAYOUT_HTML, (page) => page.evaluate(measureInPage));
    phases.layout_ms = Date.now() - layoutStartedAt;

    const geometryStartedAt = Date.now();
    const geometry = await withPage(browser, PRINT_GEOMETRY_HTML, async (page) =>
      parsePdfPageBoxes(
        Buffer.from(
          await page.pdf({
            printBackground: true,
            preferCSSPageSize: true,
            margin: { top: '0mm', right: '0mm', bottom: '0mm', left: '0mm' },
            timeout: PROBE_TIMEOUT_MS,
          })
        )
      )
    );
    phases.geometry_ms = Date.now() - geometryStartedAt;

    const pagedMediaStartedAt = Date.now();
    const pagedMedia = await withPage(browser, PAGED_MEDIA_HTML, async (page) =>
      parsePdfPageBoxes(
        Buffer.from(
          await page.pdf({
            printBackground: true,
            preferCSSPageSize: true,
            margin: { top: '0mm', right: '0mm', bottom: '0mm', left: '0mm' },
            timeout: PROBE_TIMEOUT_MS,
          })
        )
      )
    );

    phases.paged_media_ms = Date.now() - pagedMediaStartedAt;

    const results = buildResults(layout, geometry, pagedMedia);

    return {
      ...summarise(results),
      running_banner: runningBanner,
      checked_at: new Date().toISOString(),
      ms: Date.now() - startedAt,
      phases,
      error: null,
      results,
    };
  } catch (err) {
    return {
      status: STATUS_ERROR,
      required_failures: [],
      notable: [],
      unknown: [],
      running_banner: null,
      checked_at: new Date().toISOString(),
      ms: Date.now() - startedAt,
      phases: null,
      error: String((err && err.message) || err),
      results: [],
    };
  } finally {
    if (browser) {
      await browser.close().catch(() => {});
    }
  }
}

module.exports = {
  runCapabilityProbe,
  PAGED_MEDIA_TEXT_HTML,
  PAGED_MEDIA_TEXT_PAGES,
  parsePdfPageBoxes,
  buildResults,
  summarise,
  boxMatchesMm,
  PX_PER_MM,
  PT_PER_MM,
  MEDIA_BOX_TOLERANCE_PT,
  STATUS_OK,
  STATUS_NOTABLE,
  STATUS_INCONCLUSIVE,
  STATUS_DEGRADED,
  STATUS_ERROR,
  STATUS_PENDING,
  STATUS_NOT_RUN,
};
