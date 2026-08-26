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

function renderTable(block, direction) {
  const parts = [];
  parts.push(`<figure class="flow-table" data-flow-unit="table" data-anchor="${escapeHtml(block.anchorId)}">`);
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
  const heightAttr = Number.isFinite(block.heightMm) ? ` style="height:${mm(block.heightMm)}"` : '';
  return (
    `<figure class="flow-figure" data-flow-unit="figure" data-anchor="${escapeHtml(block.anchorId)}">` +
    `<div class="flow-figure-image"${heightAttr}><img src="${escapeHtml(block.src)}" alt="${escapeHtml(block.alt || '')}"></div>` +
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

function renderBlock(block, direction) {
  switch (block.type) {
    case 'heading': {
      const number = block.number ? `<span class="flow-heading-number"><bdi>${escapeHtml(block.number)}</bdi></span> ` : '';
      return (
        `<h${block.level} class="flow-heading flow-heading-${block.level}" ` +
        `data-flow-unit="heading" data-level="${block.level}" data-anchor="${escapeHtml(block.anchorId)}">` +
        `${number}${isolateForeignRuns(block.text, direction)}</h${block.level}>`
      );
    }
    case 'paragraph': {
      const align = block.align === 'center' || block.align === 'end' ? ` flow-align-${block.align}` : '';
      return `<p class="flow-paragraph${align}" data-flow-unit="paragraph">${isolateForeignRuns(block.text, direction)}</p>`;
    }
    case 'table':
      return renderTable(block, direction);
    case 'figure':
      return renderFigure(block, direction);
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
