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
const { QR_SIZE_MM, MAX_FIELD_BOXES, MAX_ANSWER_LINES, BARCODE_SYMBOLOGIES } = require('./document');

/* Already a dependency of this service for the fixed-canvas mode's barcode
 * element; the flowing mode draws its verification code with the same encoder
 * so two modes of one product cannot print two different barcodes for one
 * token. */
const bwipjs = require('bwip-js');
const katex = require('katex');

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

/**
 * The inline content of a text-bearing block: its runs if it has them, its
 * plain text if it does not (WHIT-681).
 *
 * EVERY run still goes through `isolateForeignRuns`, which is the whole
 * difficulty. The obvious implementation — escape the run, wrap it in
 * `<strong>` — silently drops bidi isolation, so a bolded Latin serial inside
 * an Arabic sentence stops being isolated the moment somebody bolds it. The
 * isolate therefore sits INSIDE the mark, around the text, exactly where it
 * sits when there is no mark at all.
 *
 * Nesting is link > strong > em > isolated text. The order matters only for
 * output stability, but a stable order is what makes the rendering testable.
 *
 * `href` is the one value here that is interpreted rather than displayed, so
 * it is escaped as an attribute AND its scheme has already been allow-listed
 * by `validateFlowPayload`. Both halves are deliberate: validation keeps
 * `javascript:` out of a document that a real browser loads, and escaping
 * keeps a quote in a legitimate URL from closing the attribute.
 */
function inlineContent(block, direction) {
  const runs = Array.isArray(block.runs) ? block.runs : null;
  if (runs === null || runs.length === 0) {
    return isolateForeignRuns(block.text, direction);
  }

  let out = '';
  for (const run of runs) {
    if (run.break === true) {
      out += '<br>';
      continue;
    }
    let piece = isolateForeignRuns(run.text, direction);
    if (run.italic) piece = `<em>${piece}</em>`;
    if (run.bold) piece = `<strong>${piece}</strong>`;
    if (run.href) piece = `<a href="${escapeHtml(run.href)}">${piece}</a>`;
    out += piece;
  }
  return out;
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

/** E.40: a barcode, ink only, with its value under it when asked. */
function renderBarcode(block, direction) {
  let svg = '';
  try {
    svg = bwipjs.toSVG({ bcid: BARCODE_SYMBOLOGIES[block.symbology], text: block.value, scale: 2, height: 12, includetext: false });
  } catch (err) {
    // A value the symbology can't encode (an EAN-13 with letters) prints as
    // its text, which still reads, rather than failing the whole document.
    // eslint-disable-next-line no-console
    console.error('[whity_render] barcode could not be encoded:', err && err.message ? err.message : err);
  }
  const text = block.showText !== false || !svg ? `<div class="flow-barcode-text">${isolateForeignRuns(block.value, direction)}</div>` : '';
  return `${unitOpen(block, 'barcode')}${svg ? `<div class="flow-barcode-code">${svg}</div>` : ''}${text}</div>`;
}

/**
 * E.42: a formula. KaTeX writes MathML, which Chromium lays out itself, so
 * the PDF needs no KaTeX fonts or stylesheet. A formula that doesn't parse
 * prints as its source.
 */
function renderMath(block) {
  let math = '';
  try {
    math = katex.renderToString(block.tex, { output: 'mathml', displayMode: true, throwOnError: true, trust: false });
  } catch {
    math = `<code>${escapeHtml(block.tex)}</code>`;
  }
  return `${unitOpen(block, 'math')}${math}</div>`;
}

/* ---------------------------------------------------------------- forms
 *
 * The blocks a printed form needs. Each is one paginator unit (its
 * data-flow-unit is its own type, and the paginator splits only paragraphs
 * and tables), so a question never parts from its choices at a page break.
 * Every caller string goes through isolateForeignRuns, which escapes it and
 * isolates runs in the other direction: an Arabic stem with "12 V" in it
 * prints the 12 before the V.
 */

/**
 * A count from the payload, clamped to [1, max] where it is used. Validation
 * already refuses anything outside the range; repeating the bound here means a
 * caller of buildFlowHtml that skipped validation still cannot ask for a
 * string of any length it likes.
 */
function repeatBounded(markup, value, max) {
  const n = Math.trunc(Number(value));
  const count = Number.isFinite(n) && n > 1 ? n : 1;
  let out = '';
  // The loop's own bound is the constant, not the payload's number.
  for (let i = 0; i < max; i += 1) {
    if (i >= count) break;
    out += markup;
  }
  return out;
}

function unitOpen(block, type, extraClass) {
  const lay = blockLayout(block);
  return (
    `<div class="flow-${type}${extraClass ? ` ${extraClass}` : ''}${lay.cls}" data-flow-unit="${type}"` +
    `${lay.attrs}${styleAttr(lay.style, '')}>`
  );
}

function renderFacts(block, direction) {
  const items = block.items
    .map(
      (item) =>
        `<div class="flow-fact"><span class="flow-fact-label">${isolateForeignRuns(item.label, direction)}</span>` +
        `<span class="flow-fact-value">${isolateForeignRuns(item.value, direction)}</span></div>`
    )
    .join('');
  return `${unitOpen(block, 'facts', `flow-facts-${block.items.length}`)}${items}</div>`;
}

function renderFields(block, direction) {
  const title = block.title ? `<div class="flow-panel-title">${isolateForeignRuns(block.title, direction)}</div>` : '';
  const fields = block.fields
    .map((field) => {
      const full = field.span === 'full' ? ' flow-field-full' : '';
      const input =
        field.kind === 'boxes'
          ? `<span class="flow-field-boxes">${repeatBounded('<span class="flow-field-box"></span>', field.boxes, MAX_FIELD_BOXES)}</span>`
          : '<span class="flow-field-line"></span>';
      return `<div class="flow-field${full}"><span class="flow-field-label">${isolateForeignRuns(field.label, direction)}</span>${input}</div>`;
    })
    .join('');
  return `${unitOpen(block, 'fields', 'flow-panel flow-panel-outline')}${title}<div class="flow-field-grid">${fields}</div></div>`;
}

function renderList(block, direction) {
  const tone = block.tone || 'outline';
  const title = block.title ? `<div class="flow-panel-title">${isolateForeignRuns(block.title, direction)}</div>` : '';
  const tag = block.ordered ? 'ol' : 'ul';
  const items = block.items.map((item) => `<li>${isolateForeignRuns(item, direction)}</li>`).join('');
  return `${unitOpen(block, 'list', `flow-panel flow-panel-${tone}`)}${title}<${tag} class="flow-list-items">${items}</${tag}></div>`;
}

function renderAnswer(answer, direction) {
  if (!answer) return '';
  if (answer.kind === 'lines') {
    return `<div class="flow-answer-lines">${repeatBounded('<span class="flow-answer-line"></span>', answer.lines, MAX_ANSWER_LINES)}</div>`;
  }
  const width = Number.isFinite(Number(answer.widthMm)) ? Number(answer.widthMm) : 60;
  const unit = answer.unit ? `<span class="flow-answer-unit">${isolateForeignRuns(answer.unit, direction)}</span>` : '';
  return `<div class="flow-answer-box" style="width:${mm(width)}">${unit}</div>`;
}

/*
 * A choice takes its direction from its own first strong character
 * (dir="auto"): a choice that is only "5 Ω" on an Arabic paper is a Latin run
 * on its own, and in the paper's direction it printed as "Ω 5".
 */
function renderQuestion(block, direction) {
  const number =
    block.number !== undefined && block.number !== ''
      ? `<span class="flow-question-number"><bdi>${escapeHtml(String(block.number))}</bdi></span>`
      : '<span class="flow-question-number flow-question-number-none"></span>';
  const marks = block.marks ? `<span class="flow-question-marks">${isolateForeignRuns(block.marks, direction)}</span>` : '<span></span>';
  let choices = '';
  if (block.choices) {
    const columns = Number(block.choices.columns) || 1;
    choices =
      `<div class="flow-choices flow-choices-${columns}">` +
      block.choices.items
        .map(
          (item) =>
            `<span class="flow-choice"><span class="flow-choice-mark">${item.label ? isolateForeignRuns(item.label, direction) : ''}</span>` +
            `<span class="flow-choice-text" dir="auto">${isolateForeignRuns(item.text, direction)}</span></span>`
        )
        .join('') +
      '</div>';
  }
  const note = block.note ? `<div class="flow-question-note">${isolateForeignRuns(block.note, direction)}</div>` : '';
  const answer = renderAnswer(block.answer, direction);
  const bodyHtml = choices || note || answer ? `<div class="flow-question-body">${choices}${note}${answer}</div>` : '';
  return (
    `${unitOpen(block, 'question')}${number}` +
    `<span class="flow-question-text">${isolateForeignRuns(block.text, direction)}</span>${marks}${bodyHtml}</div>`
  );
}

function renderDivider(block, direction) {
  const text = block.text ? `<span class="flow-divider-text">${isolateForeignRuns(block.text, direction)}</span>` : '';
  return `${unitOpen(block, 'divider')}${text}</div>`;
}

/** The first page's letterhead: start side, then end side (logical, so it mirrors in Arabic). */
function renderLetterhead(block, direction) {
  const side = (part, cls) => {
    if (!part) return `<div class="${cls}"></div>`;
    const image = part.image
      ? `<img class="flow-letterhead-logo" src="${escapeHtml(part.image.src)}" alt="${escapeHtml(part.image.alt || '')}"` +
        `${part.image.heightMm ? ` style="height:${Number(part.image.heightMm)}mm"` : ''}>`
      : '';
    const name = part.name ? `<div class="flow-letterhead-name">${isolateForeignRuns(part.name, direction)}</div>` : '';
    const title = part.title ? `<div class="flow-letterhead-title">${isolateForeignRuns(part.title, direction)}</div>` : '';
    const lines = (part.lines || []).map((l) => `<div class="flow-letterhead-line">${isolateForeignRuns(l, direction)}</div>`).join('');
    return `<div class="${cls}">${image}${name}${title}${lines}</div>`;
  };
  return `${unitOpen(block, 'letterhead')}${side(block.start, 'flow-letterhead-start')}${side(block.end, 'flow-letterhead-end')}</div>`;
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
        `${number}${inlineContent(block, direction)}</h${block.level}>`
      );
    }
    case 'paragraph': {
      const align = block.align === 'center' || block.align === 'end' ? ` flow-align-${block.align}` : '';
      const lay = blockLayout(block);
      return (
        `<p class="flow-paragraph${align}${lay.cls}" data-flow-unit="paragraph"` +
        `${lay.attrs}${styleAttr(lay.style, '')}>` +
        `${inlineContent(block, direction)}</p>`
      );
    }
    case 'table':
      return renderTable(block, direction);
    case 'figure':
      return renderFigure(block, direction);
    case 'qr':
      return renderQr(block, direction);
    case 'facts':
      return renderFacts(block, direction);
    case 'fields':
      return renderFields(block, direction);
    case 'list':
      return renderList(block, direction);
    case 'question':
      return renderQuestion(block, direction);
    case 'divider':
      return renderDivider(block, direction);
    case 'letterhead':
      return renderLetterhead(block, direction);
    case 'barcode':
      return renderBarcode(block, direction);
    case 'math':
      return renderMath(block);
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
  // The theme: validated hex colours only (document.js), written as the custom
  // properties flow.css reads. Absent keys leave the stylesheet's defaults,
  // which are the colours this mode always printed in.
  const theme = doc.theme
    ? Object.entries(doc.theme)
        .map(([key, value]) => `  --flow-${key}: ${value};\n`)
        .join('') +
      // Table and answer-line rules follow the theme's line colour only when
      // it names one; a theme that sets just an accent keeps the darker grid.
      (doc.theme.line ? `  --flow-grid: ${doc.theme.line};\n` : '')
    : '';

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
${theme}}
@page { size: ${mm(doc.page.widthMm)} ${mm(doc.page.heightMm)}; margin: 0; }
</style>
<style>${FLOW_CSS}</style>
</head>
<body${doc.theme ? ' class="flow-themed"' : ''}>
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

module.exports = { buildFlowHtml, renderBlock, mm, FLOW_CSS, isolateForeignRuns, escapeHtml, DEFAULT_FONT_STACK };
