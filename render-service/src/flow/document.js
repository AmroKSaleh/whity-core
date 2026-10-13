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
/* The largest space a block may ask for above or below itself, in mm.
 * Half of A4's height. Past that a "space" is not spacing, it is a page break
 * expressed badly — and one that the paginator would have to fragment around
 * on every page it touched. A caller that wants the next block on a fresh page
 * has `breakBefore` and the `pageBreak` block to say so exactly. */
const MAX_BLOCK_SPACE_MM = Number(process.env.RENDER_FLOW_MAX_BLOCK_SPACE_MM || 148);
/* The narrowest a block may be made, as a percentage of the content column.
 * Below about a fifth a paragraph is a column of single words, and a table is
 * unreadable rather than merely small. */
const MIN_BLOCK_WIDTH_PERCENT = Number(process.env.RENDER_FLOW_MIN_BLOCK_WIDTH_PERCENT || 20);

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
  // The platform's verification code (#1036 for the fixed-canvas mode, extended
  // to this one). Drawn here rather than embedded as a `figure` by the caller
  // because the caller has no barcode encoder and this service already does —
  // and because an image the caller supplies is an image the caller chose,
  // which is the wrong property for the one mark on a document that is supposed
  // to attest to where it came from.
  'qr',
  // The blocks a printed FORM needs (an exam paper above all): a row of key
  // facts, fields a person fills in by hand, a list in a panel, a question with
  // its answer space, and a divider. Each is one unit to the paginator - never
  // split across a page - because half a question on one page and its choices
  // on the next is a misprint.
  'facts',
  'fields',
  'list',
  'question',
  'divider',
]);

/** The colours a caller may theme, each a CSS custom property `--flow-<key>`. */
const THEME_KEYS = ['ink', 'muted', 'accent', 'line', 'tint'];
const HEX_COLOUR = /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;

const RUNNING_SLOTS = ['start', 'center', 'end'];
const SLOT_KEYS = new Set(['text', 'subtext', 'image', 'badge']);

/* Embedded raster or vector only. `data:text/html` and friends would turn an
 * <img> into something else entirely, and an http(s) src would be an outbound
 * fetch from inside the render tier (see `figure`). */
const IMAGE_DATA_URI = /^data:image\/(?:png|jpeg|webp|svg\+xml)[;,]/;
/* A value that is not a number would otherwise become NaN, and `length > NaN`
 * is never true - a typo in the environment would remove the cap entirely. */
const MAX_IMAGE_BYTES = Number.isFinite(Number(process.env.RENDER_FLOW_MAX_IMAGE_BYTES))
  && Number(process.env.RENDER_FLOW_MAX_IMAGE_BYTES) > 0
  ? Number(process.env.RENDER_FLOW_MAX_IMAGE_BYTES)
  : 512 * 1024;
/* A running-head image lives in the page margin; past this it is not a logo,
 * it is a second page header that the margin cannot hold. The real limit is
 * the page's own margin (see validateRunningFit); this is the ceiling. */
const MAX_SLOT_IMAGE_MM = 30;
/* How much of a margin band the furniture may use: the band is drawn this far
 * from the content box, and needs a little air on the paper edge too. */
const RUNNING_BAND_ALLOWANCE_MM = 6;

/* The form blocks are never split across a page, so each cap is what still
 * FITS one page of A4 with ordinary margins. A larger value would pass
 * validation and then fail pagination with nothing the caller could act on. */
const MAX_FACTS = 6;
const MAX_FIELDS = 24;
const MAX_FIELD_BOXES = 40;
const MAX_LIST_ITEMS = 30;
const MAX_CHOICES = 12;
const MAX_ANSWER_LINES = 25;
const LIST_TONES = new Set(['plain', 'outline', 'tint']);
const FIELD_KINDS = new Set(['line', 'boxes']);
const ANSWER_KINDS = new Set(['lines', 'box']);

/**
 * How large the QR is drawn, in millimetres.
 *
 * Fixed rather than caller-supplied: a verification code has one job, which is
 * to be scannable, and every knob here is a way to produce one that is not.
 * 30 mm at the module's default error correction reads reliably from a phone at
 * arm's length on a 200-dpi office print.
 */
const QR_SIZE_MM = Number(process.env.RENDER_FLOW_QR_SIZE_MM || 30);

/**
 * Longest string a verification code will encode.
 *
 * Well inside QR's own capacity, and that is the point: the limit that matters
 * is not what the encoder accepts but what a phone camera resolves off paper.
 * A verification URL is a domain plus a token — nothing legitimate approaches
 * this.
 */
const MAX_QR_VALUE_LENGTH = 512;

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

/**
 * Whether `value` names one of the presets — as an OWN property.
 *
 * A bare `PAGE_PRESETS[name]` lookup reaches Object.prototype, so
 * `preset: "constructor"` would pass validation and then resolve to something
 * with no widthMm, silently giving an A4 page to a caller who asked for
 * something else. Inherited names are not presets.
 */
function isKnownPreset(value) {
  if (typeof value !== 'string' && typeof value !== 'number') {
    return false;
  }
  return Object.prototype.hasOwnProperty.call(PAGE_PRESETS, String(value).toLowerCase());
}

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
/**
 * Blocks that carry no box of their own, so the LAYOUT keys are meaningless on
 * them.
 *
 * A page break is a boundary, not a thing on the page; a spacer IS vertical
 * space, so asking for space around it is asking the same question twice.
 * Refused rather than accepted-and-ignored: an editor that offers a margin
 * control on a page break and a renderer that quietly drops it produce a
 * setting somebody can change forever with no effect.
 */
const BOXLESS_BLOCK_TYPES = new Set(['pageBreak', 'spacer']);

/**
 * Per-block LAYOUT: the space around it, how it behaves at a page boundary,
 * and how wide it is (#1186).
 *
 * These are the properties an author reaches for after the words are right —
 * "keep this heading with its table", "this figure is half a column wide",
 * "start the appendix on a new page". None of them existed: spacing was fixed
 * per block TYPE in the stylesheet, break behaviour was fixed in the
 * paginator, and width was always the full column.
 *
 * WIDTH IS A PERCENTAGE, and that is the whole of the answer to "how should it
 * behave on narrower paper". The content column is derived from the page box,
 * so a block set to 50% is half a column on A4 and half a column on A5 without
 * anybody restating it. A millimetre width would have to be re-authored for
 * every paper size and would silently overflow the ones it was not written
 * for.
 *
 * @returns {string|null} An error message, or null when the block is fine.
 */
function validateBlockLayout(block, i) {
  const boxless = BOXLESS_BLOCK_TYPES.has(block.type);

  for (const key of ['spaceBeforeMm', 'spaceAfterMm']) {
    if (block[key] === undefined) continue;
    if (boxless) {
      return `"content[${i}].${key}" has no meaning on a ${block.type} block`;
    }
    const mm = asFiniteNumber(block[key]);
    if (mm === null || mm < 0) {
      return `"content[${i}].${key}" must be a number of millimetres, zero or more`;
    }
    if (mm > MAX_BLOCK_SPACE_MM) {
      return `"content[${i}].${key}" exceeds ${MAX_BLOCK_SPACE_MM}mm; use a page break to start further down`;
    }
  }

  for (const key of ['breakBefore', 'keepWithNext', 'keepTogether']) {
    if (block[key] !== undefined && typeof block[key] !== 'boolean') {
      return `"content[${i}].${key}" must be true or false`;
    }
  }

  if (block.widthPercent !== undefined) {
    if (boxless) {
      return `"content[${i}].widthPercent" has no meaning on a ${block.type} block`;
    }
    const pct = asFiniteNumber(block.widthPercent);
    if (pct === null || pct < MIN_BLOCK_WIDTH_PERCENT || pct > 100) {
      return `"content[${i}].widthPercent" must be between ${MIN_BLOCK_WIDTH_PERCENT} and 100`;
    }
  }

  return null;
}

function isString(value) {
  return typeof value === 'string';
}

function optionalString(value) {
  return value === undefined || typeof value === 'string';
}

function wholeIn(value, min, max) {
  const n = asFiniteNumber(value);
  return n !== null && Math.trunc(n) === n && n >= min && n <= max;
}

/** An embedded image as a slot carries it: {src, alt?, heightMm?}. */
function validateImage(image, at) {
  if (!isPlainObject(image)) {
    return `"${at}" must be an object with a data: URI "src"`;
  }
  if (typeof image.src !== 'string' || !IMAGE_DATA_URI.test(image.src)) {
    return `"${at}.src" must be a data:image/png, jpeg, webp or svg+xml URI (remote images are not fetched)`;
  }
  if (image.src.length > MAX_IMAGE_BYTES) {
    return `"${at}.src" exceeds ${MAX_IMAGE_BYTES} bytes`;
  }
  if (!optionalString(image.alt)) {
    return `"${at}.alt" must be a string`;
  }
  if (image.heightMm !== undefined) {
    const h = asFiniteNumber(image.heightMm);
    if (h === null || h < 2 || h > MAX_SLOT_IMAGE_MM) {
      return `"${at}.heightMm" must be between 2 and ${MAX_SLOT_IMAGE_MM}`;
    }
  }
  return null;
}

/**
 * A running head or foot: up to three slots, each a string (tokens such as
 * {{page}} are filled per page) or {text, subtext, image, badge}.
 */
function validateRunning(spec, name) {
  if (spec === undefined || spec === null) return null;
  // Lenient where it always was: before slot objects existed, any header
  // object was passed through, and stored templates may carry a slot name or a
  // number this mode ignores. Those are still ignored (unknown names) or
  // printed as text (numbers) - only the new OBJECT form is checked strictly.
  if (!isPlainObject(spec)) return null;
  for (const key of RUNNING_SLOTS) {
    const slot = spec[key];
    const at = `${name}.${key}`;
    if (slot === undefined || slot === null || typeof slot !== 'object' || Array.isArray(slot)) continue;
    for (const k of Object.keys(slot)) {
      if (!SLOT_KEYS.has(k)) {
        return `"${at}.${k}" is not a slot key; use text, subtext, image or badge`;
      }
    }
    if (!optionalString(slot.text) || !optionalString(slot.subtext)) {
      return `"${at}.text" and "${at}.subtext" must be strings`;
    }
    if (slot.image !== undefined) {
      const err = validateImage(slot.image, `${at}.image`);
      if (err) return err;
    }
    if (slot.badge !== undefined) {
      if (!isPlainObject(slot.badge) || !isString(slot.badge.value) || !optionalString(slot.badge.label)) {
        return `"${at}.badge" must be {label?, value} strings`;
      }
    }
  }
  return null;
}

/**
 * A running-head image must fit the margin band it is drawn in. The bands are
 * not measured by the paginator, so an image taller than the margin would run
 * off the paper and the render would still succeed.
 */
function validateRunningFit(payload) {
  if (payload.page !== undefined && !isPlainObject(payload.page)) return null;
  const margin = resolvePage(payload.page).margin;
  const bands = [
    ['header', margin.top],
    ['footer', margin.bottom],
  ];
  for (const [name, marginMm] of bands) {
    const spec = payload[name];
    if (!isPlainObject(spec)) continue;
    for (const key of RUNNING_SLOTS) {
      const slot = spec[key];
      if (!isPlainObject(slot) || !isPlainObject(slot.image)) continue;
      const h = asFiniteNumber(slot.image.heightMm) ?? 10;
      const room = Math.max(0, marginMm - RUNNING_BAND_ALLOWANCE_MM);
      if (h > room) {
        return `"${name}.${key}.image.heightMm" (${h}mm) does not fit the ${name === 'header' ? 'top' : 'bottom'} margin; ` +
          `give the page a ${name === 'header' ? 'topMm' : 'bottomMm'} of at least ${Math.ceil(h + RUNNING_BAND_ALLOWANCE_MM)}`;
      }
    }
  }
  return null;
}

/** The form blocks' own shape checks; null when the block is fine. */
function validateFormBlock(block, i) {
  const at = `content[${i}]`;
  if (block.type === 'facts') {
    if (!Array.isArray(block.items) || block.items.length === 0 || block.items.length > MAX_FACTS) {
      return `"${at}.items" must list 1-${MAX_FACTS} facts`;
    }
    for (const item of block.items) {
      if (!isPlainObject(item) || !isString(item.label) || !isString(item.value)) {
        return `"${at}.items" entries must be {label, value} strings`;
      }
    }
  }
  if (block.type === 'fields') {
    if (!optionalString(block.title)) return `"${at}.title" must be a string`;
    if (!Array.isArray(block.fields) || block.fields.length === 0 || block.fields.length > MAX_FIELDS) {
      return `"${at}.fields" must list 1-${MAX_FIELDS} fields`;
    }
    for (const field of block.fields) {
      if (!isPlainObject(field) || !isString(field.label)) {
        return `"${at}.fields" entries must have a string "label"`;
      }
      if (field.kind !== undefined && !FIELD_KINDS.has(field.kind)) {
        return `"${at}.fields[].kind" must be line or boxes`;
      }
      if (field.kind === 'boxes' && !wholeIn(field.boxes, 1, MAX_FIELD_BOXES)) {
        return `"${at}.fields[].boxes" must be a whole number 1-${MAX_FIELD_BOXES}`;
      }
      if (field.span !== undefined && field.span !== 'full' && field.span !== 'half') {
        return `"${at}.fields[].span" must be full or half`;
      }
    }
  }
  if (block.type === 'list') {
    if (!optionalString(block.title)) return `"${at}.title" must be a string`;
    if (block.tone !== undefined && !LIST_TONES.has(block.tone)) {
      return `"${at}.tone" must be one of: ${[...LIST_TONES].join(', ')}`;
    }
    if (block.ordered !== undefined && typeof block.ordered !== 'boolean') {
      return `"${at}.ordered" must be true or false`;
    }
    if (!Array.isArray(block.items) || block.items.length === 0 || block.items.length > MAX_LIST_ITEMS || !block.items.every(isString)) {
      return `"${at}.items" must be 1-${MAX_LIST_ITEMS} strings`;
    }
  }
  if (block.type === 'question') {
    if (!isString(block.text) || block.text === '') return `"${at}.text" must be the question's text`;
    if (block.number !== undefined && !isString(block.number) && asFiniteNumber(block.number) === null) {
      return `"${at}.number" must be a string or a number`;
    }
    if (!optionalString(block.marks) || !optionalString(block.note)) {
      return `"${at}.marks" and "${at}.note" must be strings`;
    }
    if (block.choices !== undefined) {
      const c = block.choices;
      if (!isPlainObject(c)) return `"${at}.choices" must be an object`;
      if (c.columns !== undefined && !wholeIn(c.columns, 1, 4)) {
        return `"${at}.choices.columns" must be 1-4`;
      }
      if (!Array.isArray(c.items) || c.items.length === 0 || c.items.length > MAX_CHOICES) {
        return `"${at}.choices.items" must list 1-${MAX_CHOICES} choices`;
      }
      for (const item of c.items) {
        if (!isPlainObject(item) || !isString(item.text) || !optionalString(item.label)) {
          return `"${at}.choices.items" entries must be {label?, text} strings`;
        }
      }
    }
    if (block.answer !== undefined) {
      const a = block.answer;
      if (!isPlainObject(a) || !ANSWER_KINDS.has(a.kind)) {
        return `"${at}.answer.kind" must be lines or box`;
      }
      if (a.kind === 'lines' && !wholeIn(a.lines, 1, MAX_ANSWER_LINES)) {
        return `"${at}.answer.lines" must be a whole number 1-${MAX_ANSWER_LINES}`;
      }
      if (a.kind === 'box') {
        if (!optionalString(a.unit)) return `"${at}.answer.unit" must be a string`;
        if (a.widthMm !== undefined) {
          const w = asFiniteNumber(a.widthMm);
          if (w === null || w < 15 || w > 180) return `"${at}.answer.widthMm" must be between 15 and 180`;
        }
      }
    }
  }
  if (block.type === 'divider' && !optionalString(block.text)) {
    return `"${at}.text" must be a string`;
  }
  return null;
}

function validateFlowPayload(payload) {
  if (!isPlainObject(payload)) {
    return 'Request body must be a JSON object';
  }

  const { page, content } = payload;

  if (page !== undefined) {
    if (!isPlainObject(page)) {
      return '"page" must be an object';
    }
    if (page.preset !== undefined && !isKnownPreset(page.preset)) {
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

  if (payload.theme !== undefined) {
    if (!isPlainObject(payload.theme)) {
      return '"theme" must be an object of colours';
    }
    for (const [key, value] of Object.entries(payload.theme)) {
      if (!THEME_KEYS.includes(key)) {
        return `"theme.${key}" is not a theme colour; use ${THEME_KEYS.join(', ')}`;
      }
      // Hex only: the value is written into a stylesheet, and a colour NAME
      // is one short step from a value that closes the rule.
      if (typeof value !== 'string' || !HEX_COLOUR.test(value)) {
        return `"theme.${key}" must be a hex colour such as #032230`;
      }
    }
  }

  for (const name of ['header', 'footer']) {
    const err = validateRunning(payload[name], name);
    if (err) return err;
  }
  const fitError = validateRunningFit(payload);
  if (fitError) return fitError;

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
    const layoutError = validateBlockLayout(block, i);
    if (layoutError !== null) {
      return layoutError;
    }
    const formError = validateFormBlock(block, i);
    if (formError !== null) {
      return formError;
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
    if (block.type === 'qr') {
      if (typeof block.value !== 'string' || block.value === '') {
        return `"content[${i}].value" must be the non-empty string the code encodes`;
      }
      if (block.value.length > MAX_QR_VALUE_LENGTH) {
        // A QR's capacity is finite and its readability degrades into an
        // unscannable lattice well before the encoder refuses outright.
        // Refusing here names the reason; letting it through produces a printed
        // document carrying a mark that looks like a verification code and
        // scans as nothing.
        return `"content[${i}].value" exceeds ${MAX_QR_VALUE_LENGTH} characters, beyond which the code stops being reliably scannable`;
      }
      if (block.reference !== undefined && typeof block.reference !== 'string') {
        return `"content[${i}].reference" must be a string`;
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
  const preset = isKnownPreset(source.preset) ? PAGE_PRESETS[String(source.preset).toLowerCase()] : null;
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
      // Clamped here as well as validated in validateFlowPayload, because this
      // value indexes an array and the two functions are separately callable:
      // a level that arrived in a payload must not be able to write outside the
      // counter array just because someone called the normaliser directly.
      const level = Math.min(6, Math.max(1, Math.trunc(Number(block.level)) || 1));
      block.level = level;
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
    header: isPlainObject(payload.header) ? normaliseRunning(payload.header) : null,
    footer: isPlainObject(payload.footer) ? normaliseRunning(payload.footer) : { center: labels.pageOf },
    theme: isPlainObject(payload.theme) ? normaliseTheme(payload.theme) : null,
    title: typeof payload.title === 'string' ? payload.title : '',
    frontMatter,
    content,
    index: { headings, tables, figures },
  };
}

/** Only the known keys, only valid hex: what reaches the stylesheet. */
function normaliseTheme(theme) {
  const out = {};
  for (const key of THEME_KEYS) {
    if (typeof theme[key] === 'string' && HEX_COLOUR.test(theme[key])) {
      out[key] = theme[key];
    }
  }
  return Object.keys(out).length > 0 ? out : null;
}

/**
 * Running slots as the paginator reads them: a string stays a string; an
 * object keeps only the keys it may carry, so nothing unvalidated rides along
 * into the page.
 */
function normaliseRunning(spec) {
  const out = {};
  for (const key of RUNNING_SLOTS) {
    const slot = spec[key];
    if (typeof slot === 'string') {
      out[key] = slot;
    } else if (typeof slot === 'number' && Number.isFinite(slot)) {
      out[key] = String(slot);
    } else if (isPlainObject(slot)) {
      const clean = {};
      if (typeof slot.text === 'string') clean.text = slot.text;
      if (typeof slot.subtext === 'string') clean.subtext = slot.subtext;
      if (isPlainObject(slot.image) && typeof slot.image.src === 'string' && IMAGE_DATA_URI.test(slot.image.src)) {
        clean.image = { src: slot.image.src, alt: typeof slot.image.alt === 'string' ? slot.image.alt : '' };
        const h = asFiniteNumber(slot.image.heightMm);
        if (h !== null) clean.image.heightMm = Math.max(2, Math.min(MAX_SLOT_IMAGE_MM, h));
      }
      if (isPlainObject(slot.badge) && typeof slot.badge.value === 'string') {
        clean.badge = { label: typeof slot.badge.label === 'string' ? slot.badge.label : '', value: slot.badge.value };
      }
      out[key] = clean;
    }
  }
  return out;
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
  MAX_QR_VALUE_LENGTH,
  QR_SIZE_MM,
  MAX_FIELD_BOXES,
  MAX_ANSWER_LINES,
};
