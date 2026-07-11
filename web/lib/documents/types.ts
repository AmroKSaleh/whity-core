/**
 * Document/label designer model (WC-doceditor).
 *
 * A template is an ABSOLUTE-POSITIONED canvas: a fixed-size page (millimetres,
 * print-accurate) holding elements placed at (x, y) with (w, h). This suits
 * labels, sheets and docs alike — unlike word-processor flow layout.
 *
 * NOTE on colours: element `fill`/`stroke`/`color` are USER CONTENT (the design
 * the operator draws), stored as data and applied via inline style. That is
 * distinct from the app's own chrome, which uses design tokens only.
 */

import type { SheetSpec } from './sheet';
import type { SequenceConfig } from './batch';

export type ElementType = 'text' | 'dynamicText' | 'image' | 'barcode' | 'qr' | 'rect' | 'line';

/** CSS renders 1mm at 96dpi → this many px. Used to convert pointer deltas. */
export const PX_PER_MM = 96 / 25.4;

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
}

/** Text with `{{placeholder}}` tokens substituted from the bound data at render. */
export interface DynamicTextElement extends ElementCommon {
  type: 'dynamicText';
  template: string;
  style: TextStyle;
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
}

/** The barcode symbologies offered in the properties panel (bwip-js ids). */
export const BARCODE_SYMBOLOGIES: ReadonlyArray<{ id: string; label: string }> = [
  { id: 'code128', label: 'Code 128' },
  { id: 'code39', label: 'Code 39' },
  { id: 'ean13', label: 'EAN-13' },
  { id: 'ean8', label: 'EAN-8' },
  { id: 'upca', label: 'UPC-A' },
  { id: 'datamatrix', label: 'Data Matrix' },
  { id: 'pdf417', label: 'PDF417' },
  { id: 'itf14', label: 'ITF-14' },
];
