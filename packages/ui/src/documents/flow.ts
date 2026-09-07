/**
 * The FLOWING document content model — the blocks a document mode template
 * holds, and the exact shape `POST /render/flow` accepts.
 *
 * ONE VOCABULARY, SEVERAL FAÇADES (#1186)
 * ---------------------------------------
 * These types are not a new model. They are the TypeScript spelling of the
 * vocabulary the render service already validates in
 * `render-service/src/flow/document.js` — the same list its `BLOCK_TYPES`
 * enumerates and its `renderBlock()` switches on.
 *
 * That renderer list is the single source of truth about what a document can
 * contain. The SDK's `Whity\Sdk\Render\FlowDocument` is the PROGRAMMATIC façade
 * onto it, deliberately narrower: it can emit six of the seven types, because a
 * plugin may not author a `qr` — "a plugin cannot print a document that looks
 * verified and resolves to nothing". The editor is the HUMAN façade, and this
 * file is its spelling of the same vocabulary.
 *
 * WHY NOT A RICHER EDITOR MODEL. Because the printer is the constraint. A block
 * the renderer does not know cannot print, whatever model holds it, and the
 * only choices then are to degrade silently or to maintain a mapping guard
 * forever. This codebase has paid for that twice recently — a block that
 * rendered on the canvas and as a blank hole in the PDF (#1162), and
 * `[object Object]` reaching a customer's document (#1164).
 *
 * A richer EDITOR is still fine: snippets, block templates, reordering, styling
 * presets and the `/` inserter are all authoring convenience over this
 * vocabulary and cost nothing. Only a new PRINTED PRIMITIVE costs a renderer
 * change — correctly, because it is one.
 */

/** Levels the renderer accepts; anything else is refused with a 422. */
export type FlowHeadingLevel = 1 | 2 | 3 | 4 | 5 | 6;

/**
 * Per-block LAYOUT: the space around a block, how it behaves at a page
 * boundary, and how wide it is (#1186).
 *
 * Mirrors what `render-service/src/flow/document.js` validates, key for key.
 * These are not editor conveniences applied on top of the printed result —
 * every one of them is read by the renderer, and a key it does not read has no
 * business being here.
 *
 * WHY THESE ARE NOT ON EVERY BLOCK. `pageBreak` and `spacer` deliberately do
 * NOT extend this, and the renderer refuses the keys on them rather than
 * ignoring them: a page break is a boundary rather than a thing on the page,
 * and a spacer already IS vertical space. Keeping that out of the type means
 * the editor cannot offer a control the printer would reject.
 */
export interface FlowBlockLayout {
  /** Millimetres of space above the block. Absent means the type's own. */
  spaceBeforeMm?: number;
  /** Millimetres of space below the block. */
  spaceAfterMm?: number;
  /** Start this block on a fresh page. */
  breakBefore?: boolean;
  /** Do not let a page break fall between this block and the next. */
  keepWithNext?: boolean;
  /** Do not let a page break fall inside this block. */
  keepTogether?: boolean;
  /**
   * Width as a percentage of the content column.
   *
   * A PERCENTAGE, not millimetres, and that is the answer to "how should this
   * behave on narrower paper": the column is derived from the page box, so 50%
   * is half a column on A4 and half a column on A5 with nothing restated. A
   * millimetre width would need re-authoring per paper size and would silently
   * overflow the ones nobody re-authored it for.
   */
  widthPercent?: number;
}

/**
 * The largest space a block may ask for, in mm — half of A4's height.
 *
 * Mirrors `RENDER_FLOW_MAX_BLOCK_SPACE_MM`. Past this a "space" is a page break
 * expressed badly, and the renderer says so with a message pointing at the
 * thing that says it exactly.
 */
export const MAX_BLOCK_SPACE_MM = 148;

/** The narrowest a block may be made, as a percentage of the content column. */
export const MIN_BLOCK_WIDTH_PERCENT = 20;

/** The blocks that carry a box, and so accept {@link FlowBlockLayout}. */
export type FlowBoxedBlock =
  | FlowHeadingBlock
  | FlowParagraphBlock
  | FlowTableBlock
  | FlowFigureBlock;

/**
 * Does this block carry a box, and so accept layout?
 *
 * A TYPE PREDICATE rather than a boolean, so a caller that has checked cannot
 * then read `spaceBeforeMm` off a page break. Written as a plain boolean first,
 * it compiled at the call site and TypeScript refused every property access
 * after it — which is the check doing its job: the renderer refuses those keys
 * too, and a UI that could reach them would be offering settings the printer
 * rejects.
 */
export function blockAcceptsLayout(block: FlowBlock): block is FlowBoxedBlock {
  return block.type !== 'pageBreak' && block.type !== 'spacer';
}

export interface FlowHeadingBlock extends FlowBlockLayout {
  type: 'heading';
  level: FlowHeadingLevel;
  text: string;
  /** Excluded from the generated table of contents when false. */
  inContents?: boolean;
  /** Skips the renderer's automatic numbering. */
  unnumbered?: boolean;
}

export interface FlowParagraphBlock extends FlowBlockLayout {
  type: 'paragraph';
  text: string;
  /**
   * `start` is the default and is left unset rather than written out — the
   * renderer only emits an alignment class for `center` and `end`, so storing
   * `start` would be a value that never means anything.
   */
  align?: 'center' | 'end';
}

export interface FlowTableBlock extends FlowBlockLayout {
  type: 'table';
  /** Header cells. Absent means the first row is data, not headings. */
  columns?: string[];
  rows: string[][];
  caption?: string;
}

export interface FlowFigureBlock extends FlowBlockLayout {
  type: 'figure';
  /** A data URI. The renderer refuses a remote source — see its own note. */
  dataUri: string;
  caption?: string;
}

export interface FlowPageBreakBlock {
  type: 'pageBreak';
}

export interface FlowSpacerBlock {
  type: 'spacer';
  heightMm: number;
}

/**
 * The blocks an AUTHOR may add.
 *
 * `qr` is deliberately absent, exactly as it is from `FlowDocument`. A
 * verification code is the platform's to mint against a document's own id; a
 * person choosing to place one would be choosing to print a code that resolves
 * to nothing. The renderer still knows the type — it draws the one the host
 * places — which is why the renderer's vocabulary is a superset of both
 * façades rather than equal to either.
 */
export type FlowBlock =
  | FlowHeadingBlock
  | FlowParagraphBlock
  | FlowTableBlock
  | FlowFigureBlock
  | FlowPageBreakBlock
  | FlowSpacerBlock;

export type FlowBlockType = FlowBlock['type'];

/** The authorable types, in the order the inserter offers them. */
export const FLOW_BLOCK_TYPES: readonly FlowBlockType[] = [
  'heading',
  'paragraph',
  'table',
  'figure',
  'pageBreak',
  'spacer',
];

/** The flowing half of a template: content plus the document-level front matter. */
export interface FlowContent {
  blocks: FlowBlock[];
  /** Generated table of contents, when the author wants one. */
  contents?: { title?: string; maxLevel?: FlowHeadingLevel };
  listOfTables?: { title?: string };
  listOfFigures?: { title?: string };
  header?: { start?: string; center?: string; end?: string };
  footer?: { start?: string; center?: string; end?: string };
}

/**
 * A new block of `type`, with the minimum the renderer will accept.
 *
 * Every default here is a value that VALIDATES. A block inserted from the
 * palette must be renderable the instant it exists — an editor that lets you
 * add something the printer then refuses is the failure this whole design is
 * arranged to prevent, and "the user will fill it in" is not a defence when
 * the refusal arrives as a 422 at print time.
 */
export function newFlowBlock(type: FlowBlockType): FlowBlock {
  switch (type) {
    case 'heading':
      return { type, level: 2, text: '' };
    case 'paragraph':
      return { type, text: '' };
    case 'table':
      // One header and one body row: an empty table is valid to the renderer
      // and useless to look at, and a table you have to populate before it
      // looks like a table is a worse first impression than one you edit.
      return { type, columns: ['', ''], rows: [['', '']] };
    case 'figure':
      // A 1x1 transparent PNG. The renderer requires a data URI and refuses a
      // remote one, so a placeholder is the only honest empty state — an empty
      // string would be a block that exists and cannot print.
      return {
        type,
        dataUri:
          'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
      };
    case 'pageBreak':
      return { type };
    case 'spacer':
      return { type, heightMm: 10 };
    default: {
      const exhaustive: never = type;
      throw new Error(`Unknown flow block type: ${String(exhaustive)}`);
    }
  }
}

/** A one-line summary of a block, for the outline and the layers list. */
export function flowBlockSummary(block: FlowBlock): string {
  switch (block.type) {
    case 'heading':
      return block.text;
    case 'paragraph':
      return block.text;
    case 'table':
      return `${block.rows.length} × ${block.columns?.length ?? block.rows[0]?.length ?? 0}`;
    case 'figure':
      return block.caption ?? '';
    case 'pageBreak':
      return '';
    case 'spacer':
      return `${block.heightMm}mm`;
    default: {
      const exhaustive: never = block;
      return String(exhaustive);
    }
  }
}

/**
 * The largest image a `figure` block will accept, in bytes of the SOURCE file.
 *
 * A CLIENT-SIDE PRE-FLIGHT, not the rule. The server is authoritative: it caps
 * a whole template at `documents.render_max_template_bytes` (2 MB by default,
 * per-tenant configurable), and the render service caps the payload again. This
 * exists so an author is told "that image is too big" while choosing it, rather
 * than having the save refused later by a limit that names bytes rather than
 * the picture they just picked.
 *
 * WHY THIS NUMBER. A data URI is base64, which inflates by about a third, so a
 * 700 KB file lands near 950 KB inside the template — roughly half the default
 * budget, which leaves room for the rest of the document and for a second
 * image. It is deliberately well under the server limit rather than equal to
 * it: a client guard that matched exactly would pass files the encoded template
 * then fails on.
 *
 * Overridable via `FlowEditor`'s `maxFigureBytes` so a deployment that raised
 * the server setting can raise this to match.
 */
export const DEFAULT_MAX_FIGURE_BYTES = 700 * 1024;

/**
 * Image types a figure accepts.
 *
 * SVG IS ABSENT ON PURPOSE and it is not an oversight about vectors. An SVG can
 * carry script, and this value becomes a `data:` URI that the designer renders
 * in the browser and the render service renders in headless Chromium —
 * `element-content.tsx` already refuses script-carrying SVG data URIs on the
 * canvas side for exactly this reason. Raster only, so the bytes cannot execute.
 */
export const FIGURE_MIME_TYPES: readonly string[] = [
  'image/png',
  'image/jpeg',
  'image/gif',
  'image/webp',
];

/** Why a chosen file was refused, or null when it is acceptable. */
export type FigureRejection = 'type' | 'size' | null;

/**
 * Judge a chosen file BEFORE reading it.
 *
 * Separated from the component so the rule is testable without a DOM file
 * picker, and so both checks happen before any bytes are read — reading a
 * 40 MB file into memory to then refuse it is work nobody asked for.
 */
export function judgeFigureFile(
  file: { type: string; size: number },
  maxBytes: number = DEFAULT_MAX_FIGURE_BYTES
): FigureRejection {
  if (!FIGURE_MIME_TYPES.includes(file.type)) return 'type';
  if (file.size > maxBytes) return 'size';
  return null;
}

/**
 * Reshaping a `table` block — adding and removing rows and columns.
 *
 * A table used to be frozen at the shape `newFlowBlock` created: two columns,
 * one row, forever. Every cell was editable, which is what made the limit
 * costly — the block looked like a working table and only revealed it could not
 * grow when somebody had a third row to enter.
 *
 * THE INVARIANT THESE EXIST TO KEEP is that the table stays RECTANGULAR: every
 * row holds exactly `columns.length` cells. It is easy to add a column by
 * pushing one heading and forgetting the body, and the result is a table that
 * validates (the renderer only checks that rows are arrays), renders with the
 * last column silently missing from every row, and looks like a styling bug
 * rather than lost data. So these operate on the whole block, never on one
 * list, and there are tests asserting rectangularity after each.
 */

/** A table block, narrowed. */
export type FlowTable = Extract<FlowBlock, { type: 'table' }>;

/** How many columns a table has, counting the body when there is no header. */
export function tableColumnCount(block: FlowTable): number {
  return block.columns?.length ?? block.rows[0]?.length ?? 0;
}

export function addTableRow(block: FlowTable): FlowTable {
  const width = tableColumnCount(block);
  return { ...block, rows: [...block.rows, Array.from({ length: width }, () => '')] };
}

/**
 * Remove a row, keeping at least one.
 *
 * A table with no rows is valid to the renderer and prints as a header with
 * nothing under it — or, with no header either, as nothing at all. Refusing the
 * last removal keeps the block visible and re-fillable instead of leaving an
 * invisible entry in the document's reading order.
 */
export function removeTableRow(block: FlowTable, index: number): FlowTable {
  if (block.rows.length <= 1) return block;
  return { ...block, rows: block.rows.filter((_, i) => i !== index) };
}

/** Add a column: one heading AND one cell in every row, or the table goes ragged. */
export function addTableColumn(block: FlowTable): FlowTable {
  return {
    ...block,
    columns: block.columns === undefined ? undefined : [...block.columns, ''],
    rows: block.rows.map((r) => [...r, '']),
  };
}

/** Remove a column from the heading AND from every row, keeping at least one. */
export function removeTableColumn(block: FlowTable, index: number): FlowTable {
  if (tableColumnCount(block) <= 1) return block;
  return {
    ...block,
    columns: block.columns?.filter((_, i) => i !== index),
    rows: block.rows.map((r) => r.filter((_, i) => i !== index)),
  };
}
