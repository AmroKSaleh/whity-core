'use strict';

/**
 * LABEL SHEETS (E.43): a sheet of identical labels in a fixed grid, one label
 * per record or CSV row, as many sheets as it takes.
 *
 * A label is a few of the flowing mode's blocks in a fixed box. Nothing
 * flows between labels or sheets, so there is no paginator: each sheet is one
 * printed page with the labels placed absolutely, the grid centred on it (the
 * editor's page view draws it the same way). A label whose content is taller
 * than the label is cut at its edge; how many were cut comes back in the
 * result so the caller can say so.
 *
 * Payload:
 *   { sheet: { preset: 'a4'|'letter' } | { widthMm, heightMm },
 *     grid: { columns, rows, widthMm, heightMm, gapXMm, gapYMm },
 *     labels: [ [block, ...], ... ],   // one array of blocks per label
 *     direction?, lang?, title?, theme? }
 */

const {
  validateBlocks,
  normaliseTheme,
  isPlainObject,
  asFiniteNumber,
  PAGE_PRESETS,
  THEME_COLOURS,
  HEX_COLOUR,
} = require('./document');
const { renderBlock, mm, FLOW_CSS, escapeHtml, DEFAULT_FONT_STACK } = require('./html');

/** Every label of one print; at 65 a sheet that is about 150 sheets. */
const MAX_LABELS = Number(process.env.RENDER_LABELS_MAX || 10000);
const MAX_LABEL_BLOCKS = 40;
const MAX_PAYLOAD_BYTES = Number(process.env.RENDER_FLOW_MAX_BYTES || 20 * 1024 * 1024);
const SHEET_PRESETS = { a4: PAGE_PRESETS.a4, letter: PAGE_PRESETS.letter };

/** What a label can hold: no page breaks, letterheads, questions or front matter. */
const LABEL_BLOCK_TYPES = new Set(['heading', 'paragraph', 'table', 'figure', 'qr', 'barcode', 'math', 'facts', 'fields', 'list', 'divider', 'spacer']);

/** The inner padding of a label, as in the editor (2 mm). */
const LABEL_PAD_MM = 2;

function resolveSheet(sheet) {
  if (!isPlainObject(sheet)) return null;
  if (sheet.preset !== undefined) {
    return SHEET_PRESETS[String(sheet.preset).toLowerCase()] ?? null;
  }
  const widthMm = asFiniteNumber(sheet.widthMm);
  const heightMm = asFiniteNumber(sheet.heightMm);
  if (widthMm === null || heightMm === null || widthMm < 50 || heightMm < 50 || widthMm > 500 || heightMm > 500) return null;
  return { widthMm, heightMm };
}

function inRange(value, min, max) {
  const n = asFiniteNumber(value);
  return n !== null && n >= min && n <= max;
}

/** To a hundredth of a millimetre, so sums of decimals print as 15.15mm, not 15.150000000000006mm. */
const round = (n) => Math.round(n * 100) / 100;

/** Where each label sits on a sheet, in mm from the top start corner. */
function cellsOf(sheet, grid) {
  const left = (sheet.widthMm - grid.columns * grid.widthMm - (grid.columns - 1) * grid.gapXMm) / 2;
  const top = (sheet.heightMm - grid.rows * grid.heightMm - (grid.rows - 1) * grid.gapYMm) / 2;
  const cells = [];
  for (let r = 0; r < grid.rows; r += 1) {
    for (let c = 0; c < grid.columns; c += 1) {
      cells.push({ x: round(left + c * (grid.widthMm + grid.gapXMm)), y: round(top + r * (grid.heightMm + grid.gapYMm)) });
    }
  }
  return cells;
}

/** @returns {string|null} the first problem with the payload, or null. */
function validateLabelsPayload(payload) {
  if (!isPlainObject(payload)) return 'Request body must be a JSON object';
  const sheet = resolveSheet(payload.sheet);
  if (sheet === null) return '"sheet" must be { preset: "a4" | "letter" } or widthMm/heightMm between 50 and 500';

  const { grid } = payload;
  if (!isPlainObject(grid)) return '"grid" must be an object';
  if (!inRange(grid.columns, 1, 10) || Math.trunc(grid.columns) !== Number(grid.columns)) return '"grid.columns" must be a whole number 1-10';
  if (!inRange(grid.rows, 1, 30) || Math.trunc(grid.rows) !== Number(grid.rows)) return '"grid.rows" must be a whole number 1-30';
  if (!inRange(grid.widthMm, 10, 500) || !inRange(grid.heightMm, 10, 500)) return '"grid.widthMm" and "grid.heightMm" must be 10-500';
  for (const key of ['gapXMm', 'gapYMm']) {
    if (grid[key] !== undefined && !inRange(grid[key], 0, 50)) return `"grid.${key}" must be 0-50`;
  }
  const g = normaliseGrid(grid);
  const cells = cellsOf(sheet, g);
  const last = cells[cells.length - 1];
  if (cells[0].x < -0.01 || cells[0].y < -0.01 || last.x + g.widthMm > sheet.widthMm + 0.01 || last.y + g.heightMm > sheet.heightMm + 0.01) {
    return 'The labels do not fit on the sheet';
  }

  if (payload.direction !== undefined && payload.direction !== 'rtl' && payload.direction !== 'ltr') {
    return '"direction" must be "rtl" or "ltr"';
  }
  if (payload.theme !== undefined) {
    if (!isPlainObject(payload.theme)) return '"theme" must be an object of colours';
    for (const [key, value] of Object.entries(payload.theme)) {
      // A label sheet takes only the print colour (E.43, E.44).
      if (!THEME_COLOURS.includes(key)) return `"theme.${key}" is not a theme colour; use ${THEME_COLOURS.join(', ')}`;
      if (typeof value !== 'string' || !HEX_COLOUR.test(value)) return `"theme.${key}" must be a hex colour such as #032230`;
    }
  }

  const { labels } = payload;
  if (!Array.isArray(labels) || labels.length === 0) return '"labels" must be a non-empty array of labels';
  if (labels.length > MAX_LABELS) return `"labels" exceeds the render service's hard limit (${MAX_LABELS} labels)`;
  for (let n = 0; n < labels.length; n += 1) {
    const blocks = labels[n];
    if (!Array.isArray(blocks)) return `"labels[${n}]" must be an array of blocks`;
    if (blocks.length > MAX_LABEL_BLOCKS) return `"labels[${n}]" has more than ${MAX_LABEL_BLOCKS} blocks`;
    for (let i = 0; i < blocks.length; i += 1) {
      if (isPlainObject(blocks[i]) && !LABEL_BLOCK_TYPES.has(blocks[i].type)) {
        return `"labels[${n}][${i}].type" must be one of: ${[...LABEL_BLOCK_TYPES].join(', ')}`;
      }
    }
    const err = validateBlocks(blocks, `labels[${n}]`);
    if (err !== null) return err;
  }

  if (Buffer.byteLength(JSON.stringify(payload), 'utf8') > MAX_PAYLOAD_BYTES) {
    return `Labels payload exceeds the render service's hard size limit (${MAX_PAYLOAD_BYTES} bytes)`;
  }
  return null;
}

function normaliseGrid(grid) {
  return {
    columns: Math.trunc(Number(grid.columns)),
    rows: Math.trunc(Number(grid.rows)),
    widthMm: Number(grid.widthMm),
    heightMm: Number(grid.heightMm),
    gapXMm: asFiniteNumber(grid.gapXMm) ?? 0,
    gapYMm: asFiniteNumber(grid.gapYMm) ?? asFiniteNumber(grid.gapXMm) ?? 0,
  };
}

/** Run once the fonts are in: count the labels cut at their edge, and signal ready. */
const READY_JS = `
(function () {
  function done() {
    var clipped = 0;
    document.querySelectorAll('.flow-label-body').forEach(function (body) {
      if (body.scrollHeight > body.clientHeight + 1) clipped += 1;
    });
    window.__FLOW_RESULT__ = {
      pageCount: document.querySelectorAll('.flow-page').length,
      frontMatterPages: 0,
      problems: [],
      clipped: clipped,
    };
    window.__FLOW_READY__ = true;
  }
  (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(done, done);
})();
`;

/** A block as renderBlock expects it; labels have no numbering or cross-references. */
function labelBlock(block) {
  const out = { anchorId: '', number: '', label: '', ...block };
  if (block.type === 'heading') out.level = Math.min(6, Math.max(1, Math.trunc(Number(block.level)) || 1));
  return out;
}

function buildLabelsHtml(payload) {
  const direction = payload.direction === 'rtl' ? 'rtl' : 'ltr';
  const lang = typeof payload.lang === 'string' && payload.lang !== '' ? payload.lang : direction === 'rtl' ? 'ar' : 'en';
  const sheet = resolveSheet(payload.sheet);
  const grid = normaliseGrid(payload.grid);
  const cells = cellsOf(sheet, grid);
  const perSheet = cells.length;
  const theme = isPlainObject(payload.theme) ? normaliseTheme(payload.theme) : null;
  const themeCss = theme && theme.accent ? `  --flow-accent: ${theme.accent};\n` : '';

  const sheets = [];
  for (let start = 0; start < payload.labels.length; start += perSheet) {
    const labels = payload.labels.slice(start, start + perSheet).map((blocks, i) => {
      const cell = cells[i];
      const body = blocks.map((block) => renderBlock(labelBlock(block), direction)).join('\n');
      return (
        `<div class="flow-label" style="inset-inline-start:${mm(cell.x)};top:${mm(cell.y)};` +
        `width:${mm(grid.widthMm)};height:${mm(grid.heightMm)}">` +
        `<div class="flow-label-body">${body}</div></div>`
      );
    });
    sheets.push(`<div class="flow-page flow-sheet">\n${labels.join('\n')}\n</div>`);
  }

  return `<!doctype html>
<html lang="${escapeHtml(lang)}" dir="${direction}">
<head>
<meta charset="utf-8">
<title>${escapeHtml(typeof payload.title === 'string' ? payload.title : '')}</title>
<style>
:root {
  --flow-page-width: ${mm(sheet.widthMm)};
  --flow-page-height: ${mm(sheet.heightMm)};
  --flow-font: ${DEFAULT_FONT_STACK};
${themeCss}}
@page { size: ${mm(sheet.widthMm)} ${mm(sheet.heightMm)}; margin: 0; }
</style>
<style>${FLOW_CSS}</style>
<style>
.flow-label { position: absolute; box-sizing: border-box; padding: ${mm(LABEL_PAD_MM)}; overflow: hidden; font-size: 9.5pt; line-height: 1.35; }
.flow-label-body { height: 100%; overflow: hidden; }
.flow-label-body > :first-child { margin-top: 0; }
.flow-label-body > * { margin-bottom: 0.6mm; }
</style>
</head>
<body${theme ? ' class="flow-themed"' : ''}>
<div id="flow-pages">
${sheets.join('\n')}
</div>
<script>${READY_JS}</script>
</body>
</html>`;
}

module.exports = { validateLabelsPayload, buildLabelsHtml, cellsOf, normaliseGrid, MAX_LABELS, LABEL_BLOCK_TYPES };
