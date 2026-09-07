'use strict';

/**
 * Turns a normalised flow document (see ./document.js) into the ONE HTML page
 * headless Chromium is handed (#1072, first half).
 *
 * Deliberately plain, server-generated HTML rather than a React bundle: the
 * fixed-canvas mode's harness (harness/entry.tsx + scripts/build-harness.js)
 * exists to keep exported output pixel-identical to the on-screen designer
 * preview, because that mode renders a design someone drew. A flowing
 * document has no on-screen designer to be identical to, so bundling it
 * through the same pipeline would buy nothing and would put a second consumer
 * on the harness build — the single most fragile part of this service. This
 * mode therefore shares no code path, no bundle and no CSS with the
 * fixed-canvas one; the only thing they share is the Chromium instance.
 *
 * Two things in here are load-bearing and easy to get quietly wrong:
 *
 * 1. BIDI ISOLATION. Every run that is not in the document's base direction —
 *    a Latin identifier inside an Arabic sentence, and above all a page
 *    number at the end of a contents entry — is wrapped in `<bdi>`. Without
 *    it, the Unicode bidi algorithm resolves the neutral characters BETWEEN
 *    two Latin runs in an RTL paragraph as part of one merged left-to-right
 *    run, so "Table 34 …… 78" reorders on screen to "78 …… 34" and the
 *    document is wrong in a way that looks typeset. `<bdi>` is exactly the
 *    element for this: it opens an isolate, so the run inside it can neither
 *    absorb nor be absorbed by its neighbours.
 *
 * 2. GEOMETRY IN MILLIMETRES. The page box, its margins and the header/footer
 *    bands are all expressed in mm and converted once, in the browser, at the
 *    CSS 96dpi ratio. Everything the paginator measures is then in the same
 *    coordinate system as the PDF it becomes.
 */

const fs = require('node:fs');
const path = require('node:path');

const ASSET_DIR = path.join(__dirname, 'assets');

/** Read once at module load — these are shipped files, not user input. */
const FLOW_CSS = fs.readFileSync(path.join(ASSET_DIR, 'flow.css'), 'utf8');
const PAGINATE_JS = fs.readFileSync(path.join(ASSET_DIR, 'paginate.js'), 'utf8');
/* The SAME module this file uses for its own escaping and isolation, shipped
 * into the page so the paginator's generated front matter and running heads
 * apply an identical rule. See src/flow/bidi.js. */
const BIDI_JS = fs.readFileSync(require.resolve('../flow/bidi.js'), 'utf8');

/** The default stack. Noto Naskh/Sans Arabic are baked into the image
 * (Dockerfile: fonts-noto-core), so Arabic shapes rather than printing tofu. */
const DEFAULT_FONT_STACK =
  "'Noto Naskh Arabic', 'Noto Sans Arabic', 'DejaVu Sans', 'Liberation Sans', Arial, sans-serif";

const { escapeHtml, isolateForeignRuns } = require('./bidi');
const { QR_SIZE_MM } = require('./document');

/* Already a dependency of this service for the fixed-canvas mode's barcode
 * element; the flowing mode draws its verification code with the same encoder
 * so two modes of one product cannot print two different barcodes for one
 * token. */
const bwipjs = require('bwip-js');

/**
 * Escape a JSON string for embedding inside a <script> block. `</` is the
 * only sequence that can terminate a script element early; the HTML-comment
 * openers are the only others the parser treats specially in there. Neither
 * substitution can corrupt the JSON, because both are escaped forms JSON
 * already understands.
 */
function escapeJsonForScript(value) {
  return JSON.stringify(value)
    .replace(/<\//g, '<\\/')
    .replace(/<!--/g, '<\\!--')
    .replace(/\u2028/g, '\\u2028')
    .replace(/\u2029/g, '\\u2029');
}

function mm(value) {
  return `${Number(value)}mm`;
}

/**
 * The per-block LAYOUT a caller asked for (#1186), as the fragments a block's
 * root element needs.
 *
 * SPACING GOES THROUGH A CUSTOM PROPERTY, NOT AN INLINE MARGIN, and that is
 * load-bearing rather than stylistic. `.flow-page-content > :first-child` sets
 * `margin-top: 0` so a unit's leading air does not push the first thing on a
 * page below the page's own top margin — the paginator measures with that rule
 * in force, so what it measures is what prints. An inline `margin-top` beats a
 * class rule on specificity, so an author's spacing would have overridden it
 * and every page beginning with a spaced block would have started lower than
 * every other page.
 *
 * WIDTH IS EMITTED AS THE PERCENTAGE IT WAS GIVEN. The content column comes
 * from the page box, so the same document is laid out correctly on A4 and on
 * A5 without the width being restated — which is what "behaves on narrower
 * paper" has to mean for something that is printed rather than scrolled.
 *
 * The break hints are DATA ATTRIBUTES because the paginator, not CSS, decides
 * pages here: it measures every unit and packs the page boxes itself, so
 * `break-inside: avoid` is advice Chromium never gets to act on.
 */
function blockLayout(block) {
  const classes = [];
  const styles = [];
  const attrs = [];

  const before = Number(block.spaceBeforeMm);
  if (Number.isFinite(before) && before > 0) {
    classes.push('flow-space-before');
    styles.push(`--flow-space-before:${mm(before)}`);
  }
  const after = Number(block.spaceAfterMm);
  if (Number.isFinite(after) && after > 0) {
    classes.push('flow-space-after');
    styles.push(`--flow-space-after:${mm(after)}`);
  }

  const width = Number(block.widthPercent);
  if (Number.isFinite(width) && width > 0 && width < 100) {
    classes.push('flow-width');
    styles.push(`--flow-width:${width}%`);
  }

  if (block.breakBefore === true) attrs.push(' data-break-before="1"');
  if (block.keepWithNext === true) attrs.push(' data-keep-with-next="1"');
  if (block.keepTogether === true) attrs.push(' data-keep-together="1"');

  return {
    cls: classes.length > 0 ? ` ${classes.join(' ')}` : '',
    style: styles.length > 0 ? styles.join(';') : '',
    attrs: attrs.join(''),
  };
}

/** Merge the layout's custom properties with a style a block already needed. */
function styleAttr(layoutStyle, own) {
  const parts = [own, layoutStyle].filter(Boolean);
  return parts.length > 0 ? ` style="${parts.join(';')}"` : '';
}

function renderTable(block, direction) {
  const parts = [];
  const lay = blockLayout(block);
  parts.push(
    `<figure class="flow-table${lay.cls}" data-flow-unit="table" ` +
    `data-anchor="${escapeHtml(block.anchorId)}"${lay.attrs}${styleAttr(lay.style, '')}>`
  );
  if (block.caption) {
    parts.push(
      `<figcaption class="flow-caption flow-table-caption">` +
        `<span class="flow-caption-label">${isolateForeignRuns(block.label, direction)}</span>` +
        `<span class="flow-caption-sep">&#8202;&#8212;&#8202;</span>` +
        `<span class="flow-caption-text">${isolateForeignRuns(block.caption, direction)}</span>` +
        `</figcaption>`
    );
  }
  parts.push('<table><thead><tr>');
  const columns = Array.isArray(block.columns) ? block.columns : [];
  for (const column of columns) {
    parts.push(`<th scope="col">${isolateForeignRuns(column, direction)}</th>`);
  }
  parts.push('</tr></thead><tbody>');
  for (const row of block.rows) {
    parts.push('<tr>');
    for (const cell of row) {
      parts.push(`<td>${isolateForeignRuns(cell, direction)}</td>`);
    }
    parts.push('</tr>');
  }
  parts.push('</tbody></table></figure>');
  return parts.join('');
}

function renderFigure(block, direction) {
  const heightOwn = Number.isFinite(block.heightMm) ? `height:${mm(block.heightMm)}` : '';
  const lay = blockLayout(block);
  return (
    `<figure class="flow-figure${lay.cls}" data-flow-unit="figure" ` +
    `data-anchor="${escapeHtml(block.anchorId)}"${lay.attrs}${styleAttr(lay.style, '')}>` +
    `<div class="flow-figure-image"${heightOwn ? ` style="${heightOwn}"` : ''}><img src="${escapeHtml(block.src)}" alt="${escapeHtml(block.alt || '')}"></div>` +
    (block.caption
      ? `<figcaption class="flow-caption flow-figure-caption">` +
        `<span class="flow-caption-label">${isolateForeignRuns(block.label, direction)}</span>` +
        `<span class="flow-caption-sep">&#8202;&#8212;&#8202;</span>` +
        `<span class="flow-caption-text">${isolateForeignRuns(block.caption, direction)}</span>` +
        `</figcaption>`
      : '') +
    `</figure>`
  );
}

/**
 * The platform's verification code: a QR, and the reference printed beneath it.
 *
 * DRAWN AS INLINE SVG, not a raster. A QR is a lattice of hard edges, and a
 * bitmap scaled to a print resolution nobody knows in advance is exactly how a
 * code that scanned in review stops scanning on paper. SVG is resolution-free,
 * so the printer decides the dot size.
 *
 * The reference underneath is not decoration either: it is the fallback for
 * every reader who cannot scan — a photocopy, a fax, a phone with no camera
 * permission, a document read aloud over a phone call — and it is what somebody
 * types into the verification page when the code will not read.
 *
 * A FAILURE HERE DOES NOT FAIL THE DOCUMENT. If the encoder throws, the block
 * degrades to the reference text alone and the render continues. Losing a
 * hundred-page submission over one barcode would be the wrong trade, and the
 * degraded form still carries the one thing a person can act on.
 */
function renderQr(block, direction) {
  let svg = '';
  try {
    svg = bwipjs.toSVG({
      bcid: 'qrcode',
      text: block.value,
      // Error correction M: the standard trade for print, and enough that a
      // fold or a coffee ring through a corner still reads.
      eclevel: 'M',
      scale: 3,
      includetext: false,
    });
  } catch (err) {
    // eslint-disable-next-line no-console
    console.error('[whity_render] verification code could not be encoded:', err && err.message ? err.message : err);
  }

  return (
    `<div class="flow-qr" data-flow-unit="qr">` +
    (svg ? `<div class="flow-qr-code" style="width:${mm(QR_SIZE_MM)};height:${mm(QR_SIZE_MM)}">${svg}</div>` : '') +
    (block.reference
      // The reference is a machine-shaped token (`9F2A-4C11-8B03`) and stays
      // left-to-right inside an Arabic document, hence the isolation — without
      // it the hyphens resolve with the surrounding RTL text and the groups
      // print in the wrong order, which looks like a typo and is a wrong code.
      ? `<div class="flow-qr-reference">${isolateForeignRuns(block.reference, direction)}</div>`
      : '') +
    `</div>`
  );
}

function renderBlock(block, direction) {
  switch (block.type) {
    case 'heading': {
      const number = block.number ? `<span class="flow-heading-number"><bdi>${escapeHtml(block.number)}</bdi></span> ` : '';
      const lay = blockLayout(block);
      return (
        `<h${block.level} class="flow-heading flow-heading-${block.level}${lay.cls}" ` +
        `data-flow-unit="heading" data-level="${block.level}" data-anchor="${escapeHtml(block.anchorId)}"` +
        `${lay.attrs}${styleAttr(lay.style, '')}>` +
        `${number}${isolateForeignRuns(block.text, direction)}</h${block.level}>`
      );
    }
    case 'paragraph': {
      const align = block.align === 'center' || block.align === 'end' ? ` flow-align-${block.align}` : '';
      const lay = blockLayout(block);
      return (
        `<p class="flow-paragraph${align}${lay.cls}" data-flow-unit="paragraph"` +
        `${lay.attrs}${styleAttr(lay.style, '')}>` +
        `${isolateForeignRuns(block.text, direction)}</p>`
      );
    }
    case 'table':
      return renderTable(block, direction);
    case 'figure':
      return renderFigure(block, direction);
    case 'qr':
      return renderQr(block, direction);
    case 'pageBreak':
      return '<div class="flow-page-break" data-flow-unit="pageBreak"></div>';
    case 'spacer':
      return `<div class="flow-spacer" data-flow-unit="spacer" style="height:${mm(Number(block.heightMm) || 5)}"></div>`;
    default:
      return '';
  }
}

/**
 * Build the complete HTML document.
 *
 * @param {ReturnType<typeof import('./document').normaliseFlowDocument>} doc
 * @returns {string}
 */
function buildFlowHtml(doc) {
  const fontStack = doc.fontFamily || DEFAULT_FONT_STACK;
  const body = doc.content.map((block) => renderBlock(block, doc.direction)).join('\n');

  // Everything the browser-side paginator needs: geometry, the running
  // header/footer templates, the front-matter spec, and the cross-reference
  // index. The index carries anchor ids only — the page numbers it will be
  // printed with do not exist yet.
  const bootData = {
    page: doc.page,
    direction: doc.direction,
    labels: doc.labels,
    title: doc.title,
    header: doc.header,
    footer: doc.footer,
    frontMatter: doc.frontMatter,
    index: doc.index,
  };

  return `<!doctype html>
<html lang="${escapeHtml(doc.lang)}" dir="${escapeHtml(doc.direction)}">
<head>
<meta charset="utf-8">
<title>${escapeHtml(doc.title)}</title>
<style>
:root {
  --flow-page-width: ${mm(doc.page.widthMm)};
  --flow-page-height: ${mm(doc.page.heightMm)};
  --flow-margin-top: ${mm(doc.page.margin.top)};
  --flow-margin-right: ${mm(doc.page.margin.right)};
  --flow-margin-bottom: ${mm(doc.page.margin.bottom)};
  --flow-margin-left: ${mm(doc.page.margin.left)};
  --flow-font: ${fontStack};
}
@page { size: ${mm(doc.page.widthMm)} ${mm(doc.page.heightMm)}; margin: 0; }
</style>
<style>${FLOW_CSS}</style>
</head>
<body>
<div id="flow-source" aria-hidden="true">
${body}
</div>
<div id="flow-pages"></div>
<script>window.__FLOW_DOC__ = ${escapeJsonForScript(bootData)};</script>
<script>${BIDI_JS}</script>
<script>${PAGINATE_JS}</script>
</body>
</html>`;
}

module.exports = { buildFlowHtml, isolateForeignRuns, escapeHtml, DEFAULT_FONT_STACK };
