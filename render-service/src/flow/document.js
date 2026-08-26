'use strict';

/**
 * The FLOWING-document payload: validation, normalisation and numbering
 * (#1072, first half).
 *
 * This is the second of the render tier's two modes and it shares nothing
 * with the first. The fixed-canvas mode (`POST /render`, src/renderer.js)
 * takes a designer TEMPLATE — pages of absolutely-placed, mm-positioned
 * elements — and prints one PDF page per template page at `margin: 0`. It is
 * correct for what it serves and is not touched by any of this.
 *
 * The flowing mode (`POST /render/flow`) takes a CONTENT TREE — headings,
 * paragraphs, tables, figures — with no positions at all, and lets the
 * renderer decide how many pages it becomes. Everything that makes such a
 * document a document rather than a long web page is therefore a property of
 * the RENDERED result and not of the input:
 *
 *   - how many pages there are,
 *   - which page each table and each figure landed on,
 *   - what the running header says at the top of page 74,
 *   - and consequently every page number in the contents list.
 *
 * Numbering (Table 1..N, Figure 1..M, heading 2.3.1) is assigned HERE, in
 * document order, rather than being read off the payload. A caller that
 * numbered its own tables would have to re-number them every time it inserted
 * one, and any disagreement between its numbering and the generated lists
 * would be invisible until someone read the printed document. The caller
 * supplies content; the renderer supplies numbers.
 */

const MAX_BLOCKS = Number(process.env.RENDER_FLOW_MAX_BLOCKS || 20000);
const MAX_TABLE_ROWS = Number(process.env.RENDER_FLOW_MAX_TABLE_ROWS || 5000);
/* Kept BELOW express's own 25 MiB JSON body limit (src/server.js) on purpose.
 * A cap above it would never be the thing that fires: express would answer 413
 * with its own message first, and a caller sent a figure too many would be
 * told "payload too large" by a layer that knows nothing about figures. */
const MAX_PAYLOAD_BYTES = Number(process.env.RENDER_FLOW_MAX_BYTES || 20 * 1024 * 1024);

/** Page presets, in mm. A caller may also give explicit widthMm/heightMm. */
const PAGE_PRESETS = {
  a4: { widthMm: 210, heightMm: 297 },
  a5: { widthMm: 148, heightMm: 210 },
  letter: { widthMm: 215.9, heightMm: 279.4 },
  legal: { widthMm: 215.9, heightMm: 355.6 },
};

const DEFAULT_MARGIN_MM = { top: 25, right: 20, bottom: 25, left: 20 };

const FRONT_MATTER_KINDS = new Set(['contents', 'tables', 'figures']);

const BLOCK_TYPES = new Set([
  'heading',
  'paragraph',
  'table',
  'figure',
  'pageBreak',
  'spacer',
]);

/**
 * Default caption/label words. Overridable per payload so the caller picks
 * its own wording (and its own language) without this file knowing any of it.
 */
const DEFAULT_LABELS = {
  ltr: {
    contents: 'Contents',
    tables: 'List of tables',
    figures: 'List of figures',
    table: 'Table',
    figure: 'Figure',
    continued: '(continued)',
    pageOf: 'Page {{page}} of {{pages}}',
  },
  rtl: {
    contents: 'المحتويات',
    tables: 'قائمة الجداول',
    figures: 'قائمة الأشكال',
    table: 'جدول',
    figure: 'شكل',
    continued: '(تابع)',
    pageOf: 'صفحة {{page}} من {{pages}}',
  },
};

function isPlainObject(value) {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function asFiniteNumber(value) {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

/**
 * Validate a flow payload's SHAPE and size.
 *
 * Returns an error string (the 422 body) or null. Deliberately mirrors
 * src/limits.js's `validatePayload` in style: a flat, first-error-wins check
 * that never throws, so the server can answer 422 with something a caller can
 * act on rather than 500 with a stack trace.
 *
 * @param {unknown} payload
 * @returns {string|null}
 */
function validateFlowPayload(payload) {
  if (!isPlainObject(payload)) {
    return 'Request body must be a JSON object';
  }

  const { page, content } = payload;

  if (page !== undefined) {
    if (!isPlainObject(page)) {
      return '"page" must be an object';
    }
    if (page.preset !== undefined && !PAGE_PRESETS[String(page.preset).toLowerCase()]) {
      return `"page.preset" must be one of: ${Object.keys(PAGE_PRESETS).join(', ')}`;
    }
    if (page.preset === undefined) {
      if (asFiniteNumber(page.widthMm) === null || asFiniteNumber(page.heightMm) === null) {
        return '"page" must have a "preset" or numeric widthMm/heightMm';
      }
    }
    if (page.margin !== undefined && !isPlainObject(page.margin)) {
      return '"page.margin" must be an object of topMm/rightMm/bottomMm/leftMm';
    }
  }

  if (payload.direction !== undefined && payload.direction !== 'rtl' && payload.direction !== 'ltr') {
    return '"direction" must be "rtl" or "ltr"';
  }

  if (!Array.isArray(content)) {
    return '"content" must be an array of blocks';
  }
  if (content.length === 0) {
    return '"content" must not be empty';
  }
  if (content.length > MAX_BLOCKS) {
    return `"content" exceeds the render service's hard limit (${MAX_BLOCKS} blocks)`;
  }

  for (let i = 0; i < content.length; i += 1) {
    const block = content[i];
    if (!isPlainObject(block)) {
      return `"content[${i}]" must be an object`;
    }
    if (!BLOCK_TYPES.has(block.type)) {
      return `"content[${i}].type" must be one of: ${[...BLOCK_TYPES].join(', ')}`;
    }
    if (block.type === 'heading') {
      const level = asFiniteNumber(block.level);
      if (level === null || level < 1 || level > 6 || Math.trunc(level) !== level) {
        return `"content[${i}].level" must be a whole number 1-6`;
      }
      if (typeof block.text !== 'string') {
        return `"content[${i}].text" must be a string`;
      }
    }
    if (block.type === 'paragraph' && typeof block.text !== 'string') {
      return `"content[${i}].text" must be a string`;
    }
    if (block.type === 'table') {
      if (!Array.isArray(block.rows)) {
        return `"content[${i}].rows" must be an array of row arrays`;
      }
      if (block.rows.length > MAX_TABLE_ROWS) {
        return `"content[${i}].rows" exceeds the render service's hard limit (${MAX_TABLE_ROWS} rows)`;
      }
      for (const row of block.rows) {
        if (!Array.isArray(row)) {
          return `"content[${i}].rows" entries must be arrays of cell values`;
        }
      }
      if (block.columns !== undefined && !Array.isArray(block.columns)) {
        return `"content[${i}].columns" must be an array`;
      }
    }
    if (block.type === 'figure') {
      if (typeof block.src !== 'string' || block.src === '') {
        return `"content[${i}].src" must be a non-empty data: URI`;
      }
      if (!block.src.startsWith('data:')) {
        // A flowing render must not reach the network: an http(s) figure src
        // would turn every render into an outbound fetch from inside the
        // render tier, which is both an SSRF surface and a source of
        // non-deterministic output. Callers embed their images.
        return `"content[${i}].src" must be a data: URI (remote images are not fetched)`;
      }
    }
  }

  if (payload.frontMatter !== undefined) {
    if (!Array.isArray(payload.frontMatter)) {
      return '"frontMatter" must be an array';
    }
    for (let i = 0; i < payload.frontMatter.length; i += 1) {
      const entry = payload.frontMatter[i];
      if (!isPlainObject(entry) || !FRONT_MATTER_KINDS.has(entry.kind)) {
        return `"frontMatter[${i}].kind" must be one of: ${[...FRONT_MATTER_KINDS].join(', ')}`;
      }
    }
  }

  const bytes = Buffer.byteLength(JSON.stringify(payload), 'utf8');
  if (bytes > MAX_PAYLOAD_BYTES) {
    return `Flow payload exceeds the render service's hard size limit (${MAX_PAYLOAD_BYTES} bytes)`;
  }

  return null;
}

/** Resolve the physical page box, in mm. */
function resolvePage(page) {
  const source = isPlainObject(page) ? page : {};
  const preset = source.preset ? PAGE_PRESETS[String(source.preset).toLowerCase()] : null;
  const widthMm = preset ? preset.widthMm : asFiniteNumber(source.widthMm);
  const heightMm = preset ? preset.heightMm : asFiniteNumber(source.heightMm);
  const margin = isPlainObject(source.margin) ? source.margin : {};

  const resolved = {
    widthMm: widthMm === null ? PAGE_PRESETS.a4.widthMm : widthMm,
    heightMm: heightMm === null ? PAGE_PRESETS.a4.heightMm : heightMm,
    margin: {
      top: asFiniteNumber(margin.topMm) ?? DEFAULT_MARGIN_MM.top,
      right: asFiniteNumber(margin.rightMm) ?? DEFAULT_MARGIN_MM.right,
      bottom: asFiniteNumber(margin.bottomMm) ?? DEFAULT_MARGIN_MM.bottom,
      left: asFiniteNumber(margin.leftMm) ?? DEFAULT_MARGIN_MM.left,
    },
  };

  // A margin box that leaves no content box at all would fragment forever
  // (nothing ever fits, so every unit is pushed to a fresh page). Refuse to
  // produce that shape; clamp instead, so a caller that typed 150 for a
  // 25 mm margin gets a readable document rather than a hung render.
  const maxSide = resolved.widthMm / 2 - 5;
  const maxVertical = resolved.heightMm / 2 - 5;
  resolved.margin.left = Math.max(0, Math.min(resolved.margin.left, maxSide));
  resolved.margin.right = Math.max(0, Math.min(resolved.margin.right, maxSide));
  resolved.margin.top = Math.max(0, Math.min(resolved.margin.top, maxVertical));
  resolved.margin.bottom = Math.max(0, Math.min(resolved.margin.bottom, maxVertical));

  return resolved;
}

/**
 * Assign document-order numbering to headings, tables and figures, and build
 * the cross-reference index the generated front-matter lists are rendered
 * from.
 *
 * Every numbered thing gets an `anchorId`. That id is the ONLY link between a
 * front-matter entry and the item it points at: the paginator records which
 * page each anchor landed on, and the entry's page number is read back out of
 * that record. Nothing downstream matches on caption text.
 *
 * @param {object} payload
 * @returns {{page: object, direction: string, lang: string, labels: object,
 *            header: object, footer: object, frontMatter: object[],
 *            content: object[], index: {headings: object[], tables: object[], figures: object[]}}}
 */
function normaliseFlowDocument(payload) {
  const direction = payload.direction === 'rtl' ? 'rtl' : 'ltr';
  const labels = { ...DEFAULT_LABELS[direction], ...(isPlainObject(payload.labels) ? payload.labels : {}) };
  const page = resolvePage(payload.page);

  const headingCounters = [0, 0, 0, 0, 0, 0];
  let tableNumber = 0;
  let figureNumber = 0;

  const headings = [];
  const tables = [];
  const figures = [];
  const content = [];

  for (const raw of payload.content) {
    const block = { ...raw };

    if (block.type === 'heading') {
      const level = block.level;
      headingCounters[level - 1] += 1;
      for (let i = level; i < headingCounters.length; i += 1) {
        headingCounters[i] = 0;
      }
      block.number = headingCounters.slice(0, level).join('.');
      block.anchorId = `h-${headings.length + 1}`;
      // Only numbered levels reach the contents list; a caller can mark a
      // heading `inContents: false` for a running sub-head it does not want
      // listed, and `unnumbered: true` for a front-matter-style title.
      if (block.unnumbered) {
        headingCounters[level - 1] -= 1;
        block.number = '';
      }
      const listed = block.inContents !== false && !block.unnumbered;
      if (listed) {
        headings.push({
          anchorId: block.anchorId,
          level,
          number: block.number,
          text: block.text,
        });
      }
    }

    if (block.type === 'table') {
      tableNumber += 1;
      block.number = String(tableNumber);
      block.anchorId = `t-${tableNumber}`;
      block.label = `${labels.table} ${tableNumber}`;
      if (block.caption) {
        tables.push({
          anchorId: block.anchorId,
          number: block.number,
          label: block.label,
          text: block.caption,
        });
      }
    }

    if (block.type === 'figure') {
      figureNumber += 1;
      block.number = String(figureNumber);
      block.anchorId = `f-${figureNumber}`;
      block.label = `${labels.figure} ${figureNumber}`;
      if (block.caption) {
        figures.push({
          anchorId: block.anchorId,
          number: block.number,
          label: block.label,
          text: block.caption,
        });
      }
    }

    content.push(block);
  }

  const frontMatter = (Array.isArray(payload.frontMatter) ? payload.frontMatter : []).map((entry) => ({
    kind: entry.kind,
    title: typeof entry.title === 'string' && entry.title !== '' ? entry.title : labels[entry.kind],
    // A contents list normally stops at level 3; deeper heads are structure,
    // not navigation.
    maxLevel: asFiniteNumber(entry.maxLevel) ?? 3,
  }));

  return {
    page,
    direction,
    lang: typeof payload.lang === 'string' && payload.lang !== '' ? payload.lang : (direction === 'rtl' ? 'ar' : 'en'),
    labels,
    header: isPlainObject(payload.header) ? payload.header : null,
    footer: isPlainObject(payload.footer) ? payload.footer : { center: labels.pageOf },
    title: typeof payload.title === 'string' ? payload.title : '',
    frontMatter,
    content,
    index: { headings, tables, figures },
  };
}

module.exports = {
  validateFlowPayload,
  normaliseFlowDocument,
  resolvePage,
  PAGE_PRESETS,
  DEFAULT_LABELS,
  DEFAULT_MARGIN_MM,
  MAX_BLOCKS,
  MAX_TABLE_ROWS,
  MAX_PAYLOAD_BYTES,
};
