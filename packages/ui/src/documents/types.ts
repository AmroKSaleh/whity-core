/**
 * Document/label designer model (WC-doceditor).
 *
 * A template has TWO possible bodies, and `mode` says which one it is being
 * edited as (#1186):
 *
 *   - CANVAS (`pages`, the original and the default): an absolute-positioned
 *     page in millimetres, elements placed at (x, y) with (w, h). Exactly what
 *     a label, a certificate or a form needs, and what word-processor flow
 *     layout cannot give.
 *   - FLOW (`flow`): blocks one below another, paginated by the render service.
 *     What a report or a letter needs, and what placing every paragraph by hand
 *     makes miserable.
 *
 * Both are held at once so a document can be switched between them. Neither is
 * a conversion of the other at rest; conversion happens on the switch, and the
 * canvas -> flow direction loses positions, which the switch has to say before
 * it happens rather than after.
 *
 * NOTE on colours: element `fill`/`stroke`/`color` are USER CONTENT (the design
 * the operator draws), stored as data and applied via inline style. That is
 * distinct from the app's own chrome, which uses design tokens only.
 *
 * Promoted from `web/lib/documents/types.ts` into `@amroksaleh/ui` so the
 * model + the portable renderers that consume it (`element-content.tsx`,
 * `element-layer.tsx`) can be reused and Storybook-iterated outside the Next.js
 * app. `web/lib/documents/types.ts` re-exports this module unchanged so every
 * existing `@/lib/documents/types` import keeps working.
 */

import type { SheetSpec } from './sheet';
import type { FlowContent } from './flow';

export type ElementType = 'text' | 'dynamicText' | 'image' | 'barcode' | 'qr' | 'rect' | 'line' | 'math';

/** CSS renders 1mm at 96dpi → this many px. Used to convert pointer deltas. */
export const PX_PER_MM = 96 / 25.4;

/**
 * One formatted run within a text element's rich content: a slice of the text
 * with its own bold/italic override. `bold`/`italic` undefined means "inherit
 * the whole-element `TextStyle`" (`fontWeight`/`fontStyle`); `true`/`false`
 * explicitly overrides it for this run. Concatenating `run.text` in document
 * order must reconstruct the element's plain `text`/`template` field exactly —
 * that invariant is what keeps every plain-text consumer (interpolation, the
 * layers-panel label, CSV/batch data) working unchanged whether or not runs are
 * present. See `web/lib/documents/rich-text.ts` for the pure helpers that build
 * and edit these arrays (that module stays app-side; only the type lives here).
 */
export interface TextRun {
  text: string;
  bold?: boolean;
  italic?: boolean;
}

export interface TextStyle {
  /** Font size in points. */
  fontSize: number;
  fontWeight: 'normal' | 'bold';
  fontStyle: 'normal' | 'italic';
  align: 'left' | 'center' | 'right';
  vAlign: 'top' | 'middle' | 'bottom';
  color: string;
  /**
   * Text direction. 'auto' (default) lets the renderer infer per-paragraph from
   * the first strong character — correct for Arabic and mixed Arabic/Latin
   * (e.g. a Latin serial inside Arabic). Applies in edit, Preview and print.
   */
  direction?: 'auto' | 'ltr' | 'rtl';
  /** Line height as a unitless multiple of the font size (undefined = 1.2). */
  lineHeight?: number;
  /** Extra spacing between characters, in points (undefined = 0). */
  letterSpacing?: number;
}

interface ElementCommon {
  id: string;
  /** Position + size in millimetres, relative to the page top-left. */
  x: number;
  y: number;
  w: number;
  h: number;
  rotation: number;
  /** Stacking order (higher = front). */
  z: number;
  /** Locked elements can't be moved, resized, nudged, aligned or deleted. */
  locked?: boolean;
  /** Hidden elements are omitted from Preview and print (still shown, dimmed, while editing). */
  hidden?: boolean;
  /** Opacity 0–1 (undefined = fully opaque). Applies in edit, Preview and print. */
  opacity?: number;
}

export interface TextElement extends ElementCommon {
  type: 'text';
  text: string;
  style: TextStyle;
  /**
   * Optional inline bold/italic spans over `text` (see `TextRun`). Absent =
   * legacy behaviour: the whole text uses `style.fontWeight`/`style.fontStyle`.
   * Existing saved templates never have this field and keep rendering exactly
   * as before.
   */
  runs?: TextRun[];
}

/** Text with `{{placeholder}}` tokens substituted from the bound data at render. */
export interface DynamicTextElement extends ElementCommon {
  type: 'dynamicText';
  template: string;
  style: TextStyle;
  /** Optional inline bold/italic spans over `template` (see `TextRun`). Each run's
   * `text` may itself contain `{{tokens}}`, interpolated per-run at render. */
  runs?: TextRun[];
}

export interface ImageElement extends ElementCommon {
  type: 'image';
  /** Static image URL/data-URI, used when no binding resolves. */
  src: string;
  /** Optional placeholder key whose value (a URL) overrides `src`. */
  binding?: string;
  fit: 'contain' | 'cover' | 'fill';
}

export interface BarcodeElement extends ElementCommon {
  type: 'barcode';
  /** bwip-js symbology id, e.g. 'code128', 'ean13', 'qrcode'. */
  symbology: string;
  value: string;
  binding?: string;
  showText: boolean;
}

export interface QrElement extends ElementCommon {
  type: 'qr';
  value: string;
  binding?: string;
  /** QR error-correction level (undefined = 'M'). Higher = more damage-tolerant, denser. */
  eclevel?: 'L' | 'M' | 'Q' | 'H';
}

export interface RectElement extends ElementCommon {
  type: 'rect';
  fill: string;
  stroke: string;
  strokeWidth: number;
  radius: number;
}

export interface LineElement extends ElementCommon {
  type: 'line';
  stroke: string;
  strokeWidth: number;
}

/** A LaTeX math expression, rendered via KaTeX (`@amroksaleh/ui/math-text`). */
export interface MathElement extends ElementCommon {
  type: 'math';
  /** LaTeX source, without surrounding $ delimiters (passed straight to KaTeX). */
  expression: string;
  /** Display (block) math vs. inline; matches `MathText`'s `block` prop. */
  block?: boolean;
}

/**
 * A reference (pointer) to a reusable block. The document stores only the
 * blockId + placement; the block's actual elements live once in the block store
 * and are resolved at render time, so editing the block updates every instance.
 * Not an "add" element type — inserted from the Blocks panel, never via newElement.
 */
export interface BlockInstanceElement extends ElementCommon {
  type: 'blockInstance';
  blockId: string;
}

export type DocElement =
  | TextElement
  | DynamicTextElement
  | ImageElement
  | BarcodeElement
  | QrElement
  | RectElement
  | LineElement
  | MathElement
  | BlockInstanceElement;

export interface Placeholder {
  key: string;
  label: string;
  sample: string;
}

export interface PageSpec {
  widthMm: number;
  heightMm: number;
  marginMm: number;
  background: string;
}

/** One page of a template: an ordered list of elements on the shared page size. */
export interface DocPage {
  id: string;
  elements: DocElement[];
}

/**
 * Configuration for the serial/sequence generator (variable-data batch runs).
 * The type lives here (pure data shape); the generator logic + localStorage-free
 * defaults/constants (`generateSequence`, `DEFAULT_SEQUENCE`, `MAX_BATCH_ROWS`)
 * stay app-side in `web/lib/documents/batch.ts`, which re-exports this type.
 */
export interface SequenceConfig {
  /** Placeholder key the generated value is written to (e.g. "sku", "serial"). */
  key: string;
  prefix: string;
  /** First numeric value. */
  start: number;
  /** How many rows to generate. */
  count: number;
  /** Increment between rows (may be negative). */
  step: number;
  /** Zero-pad the numeric part to this width (0 = no padding). */
  padding: number;
  suffix: string;
}

export interface DocTemplate {
  version: 2;
  name: string;
  /** Page size/background/margin, shared by every page (uniform documents). */
  page: PageSpec;
  placeholders: Placeholder[];
  pages: DocPage[];
  /** Saved N-up label-sheet layout (print-time), if the operator configured one. */
  sheet?: SheetSpec;
  /** Saved serial/sequence generator settings, for repeatable batch runs. */
  sequence?: SequenceConfig;
  /**
   * Which editor owns this document (#1186).
   *
   * Absent means `canvas`, so every template written before document mode
   * existed reads correctly without a migration — and an older client that
   * does not know the field keeps opening its own documents unchanged.
   */
  mode?: 'canvas' | 'flow';
  /**
   * The flowing content, when `mode` is `flow`.
   *
   * Kept BESIDE `pages` rather than replacing it, because a template can be
   * switched between modes and the other half must survive the trip. Canvas ->
   * flow drops absolute positions and cannot get them back; holding both is
   * what makes that a stated cost rather than a silent one.
   */
  flow?: FlowContent;
}

/** The barcode symbologies offered in the properties panel (bwip-js ids). */
export const BARCODE_SYMBOLOGIES: ReadonlyArray<{ id: string; label: string }> = [
  { id: 'code128', label: 'Code 128' },
  { id: 'gs1-128', label: 'GS1-128' },
  { id: 'code39', label: 'Code 39' },
  { id: 'code93', label: 'Code 93' },
  { id: 'ean13', label: 'EAN-13' },
  { id: 'ean8', label: 'EAN-8' },
  { id: 'upca', label: 'UPC-A' },
  { id: 'upce', label: 'UPC-E' },
  { id: 'interleaved2of5', label: 'Interleaved 2 of 5' },
  { id: 'itf14', label: 'ITF-14' },
  { id: 'msi', label: 'MSI Plessey' },
  { id: 'rationalizedCodabar', label: 'Codabar' },
  { id: 'datamatrix', label: 'Data Matrix' },
  { id: 'pdf417', label: 'PDF417' },
  { id: 'azteccode', label: 'Aztec' },
];
