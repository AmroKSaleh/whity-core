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

export type DocElement =
  | TextElement
  | DynamicTextElement
  | ImageElement
  | BarcodeElement
  | QrElement
  | RectElement
  | LineElement;

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

export interface DocTemplate {
  version: 1;
  name: string;
  page: PageSpec;
  placeholders: Placeholder[];
  elements: DocElement[];
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
