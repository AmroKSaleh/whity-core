import type { DocElement, DocTemplate, PageSpec, Placeholder, TextStyle } from './types';
import type { DocBlock } from './blocks';
import { DEFAULT_TEXT_STYLE, blankTemplate, newPageId } from './presets';

/**
 * Smart starter templates — so a new document is never a blank white sheet.
 * Each is a ready, fully-editable layout (invoice, exam sheet, production note,
 * shipping label…) built from ordinary elements + `{{placeholders}}`.
 *
 * These are code-shipped defaults. When the backend persistence lands, tenant
 * creation will seed these pre-filled with the tenant's real company info (see
 * the doc-designer-starters note); the shapes here are the seed source.
 */

// ── tiny element builders (ids assigned per template so they stay stable) ────
let seq = 0;
const eid = (t: string) => `${t}-s${(seq += 1)}`;
const st = (o: Partial<TextStyle> = {}): TextStyle => ({ ...DEFAULT_TEXT_STYLE, ...o });

function T(x: number, y: number, w: number, h: number, text: string, o: Partial<TextStyle> = {}): DocElement {
  return { id: eid('text'), type: 'text', x, y, w, h, rotation: 0, z: 0, text, style: st(o) };
}
function D(x: number, y: number, w: number, h: number, template: string, o: Partial<TextStyle> = {}): DocElement {
  return { id: eid('dynamicText'), type: 'dynamicText', x, y, w, h, rotation: 0, z: 0, template, style: st(o) };
}
function L(x: number, y: number, w: number): DocElement {
  return { id: eid('line'), type: 'line', x, y, w, h: 0.4, rotation: 0, z: 0, stroke: '#333333', strokeWidth: 0.4 };
}
function R(x: number, y: number, w: number, h: number): DocElement {
  return { id: eid('rect'), type: 'rect', x, y, w, h, rotation: 0, z: 0, fill: '#ffffff', stroke: '#999999', strokeWidth: 0.3, radius: 1 };
}
function BC(x: number, y: number, w: number, h: number, value: string): DocElement {
  return { id: eid('barcode'), type: 'barcode', x, y, w, h, rotation: 0, z: 0, symbology: 'code128', value, binding: undefined, showText: true };
}

const page = (widthMm: number, heightMm: number, marginMm = 10): PageSpec => ({ widthMm, heightMm, marginMm, background: '#ffffff' });

function tpl(name: string, p: PageSpec, placeholders: Placeholder[], els: DocElement[]): DocTemplate {
  // Stack in array order so later elements sit on top.
  const elements = els.map((e, i) => ({ ...e, z: i + 1 }));
  return { version: 2, name, page: p, placeholders, pages: [{ id: newPageId(), elements }] };
}

const A4 = () => page(210, 297);

function invoice(): DocTemplate {
  return tpl(
    'Invoice',
    A4(),
    [
      { key: 'company_name', label: 'Company name', sample: 'Acme Corp' },
      { key: 'company_contact', label: 'Company contact', sample: 'Acme Corp · info@acme.com · +1 555 0100' },
      { key: 'invoice_no', label: 'Invoice #', sample: 'INV-1001' },
      { key: 'date', label: 'Date', sample: '2026-01-15' },
      { key: 'bill_to', label: 'Bill to', sample: 'Customer Name\n123 Example St\nCity' },
      { key: 'total', label: 'Total', sample: '$0.00' },
    ],
    [
      D(15, 15, 120, 12, '{{company_name}}', { fontSize: 20, fontWeight: 'bold' }),
      T(150, 15, 45, 12, 'INVOICE', { fontSize: 22, fontWeight: 'bold', align: 'right' }),
      L(15, 30, 180),
      T(15, 40, 30, 6, 'Bill To', { fontWeight: 'bold' }),
      D(15, 47, 95, 24, '{{bill_to}}'),
      T(135, 40, 30, 6, 'Invoice #', { fontWeight: 'bold', align: 'right' }),
      D(167, 40, 28, 6, '{{invoice_no}}', { align: 'right' }),
      T(135, 47, 30, 6, 'Date', { fontWeight: 'bold', align: 'right' }),
      D(167, 47, 28, 6, '{{date}}', { align: 'right' }),
      T(15, 78, 95, 6, 'Description', { fontWeight: 'bold' }),
      T(115, 78, 30, 6, 'Qty', { fontWeight: 'bold', align: 'right' }),
      T(160, 78, 35, 6, 'Amount', { fontWeight: 'bold', align: 'right' }),
      L(15, 85, 180),
      T(150, 250, 20, 8, 'Total', { fontWeight: 'bold', align: 'right' }),
      D(172, 250, 23, 8, '{{total}}', { fontWeight: 'bold', align: 'right' }),
      L(15, 280, 180),
      D(15, 283, 180, 6, '{{company_contact}}', { fontSize: 8, align: 'center', color: '#666666' }),
    ]
  );
}

function examSheet(): DocTemplate {
  return tpl(
    'Exam sheet',
    A4(),
    [
      { key: 'exam_title', label: 'Exam title', sample: 'Midterm Examination' },
      { key: 'subject', label: 'Subject', sample: 'Mathematics' },
    ],
    [
      D(15, 15, 180, 12, '{{exam_title}}', { fontSize: 20, fontWeight: 'bold', align: 'center' }),
      D(15, 28, 180, 7, '{{subject}}', { fontSize: 12, align: 'center', color: '#555555' }),
      T(15, 42, 18, 6, 'Name:', { fontWeight: 'bold' }),
      L(34, 48, 90),
      T(135, 42, 14, 6, 'Date:', { fontWeight: 'bold' }),
      L(151, 48, 44),
      T(15, 52, 18, 6, 'Score:', { fontWeight: 'bold' }),
      L(34, 58, 50),
      L(15, 64, 180),
      T(15, 68, 180, 6, 'Answer all questions. Show your work clearly.', { fontStyle: 'italic', fontSize: 10 }),
      T(15, 82, 10, 6, '1.', { fontWeight: 'bold' }),
      L(27, 92, 168),
      T(15, 102, 10, 6, '2.', { fontWeight: 'bold' }),
      L(27, 112, 168),
      T(15, 122, 10, 6, '3.', { fontWeight: 'bold' }),
      L(27, 132, 168),
    ]
  );
}

function productionNote(): DocTemplate {
  return tpl(
    'Production note',
    A4(),
    [
      { key: 'company_name', label: 'Company name', sample: 'Acme Manufacturing' },
      { key: 'order_no', label: 'Order #', sample: 'WO-5567' },
      { key: 'product', label: 'Product', sample: 'Widget A' },
      { key: 'qty', label: 'Quantity', sample: '250' },
      { key: 'date', label: 'Date', sample: '2026-01-15' },
    ],
    [
      D(15, 15, 110, 10, '{{company_name}}', { fontSize: 16, fontWeight: 'bold' }),
      T(120, 15, 75, 10, 'PRODUCTION NOTE', { fontSize: 16, fontWeight: 'bold', align: 'right' }),
      L(15, 28, 180),
      T(15, 36, 28, 6, 'Order #', { fontWeight: 'bold' }),
      D(45, 36, 70, 6, '{{order_no}}'),
      T(15, 44, 28, 6, 'Product', { fontWeight: 'bold' }),
      D(45, 44, 90, 6, '{{product}}'),
      T(15, 52, 28, 6, 'Quantity', { fontWeight: 'bold' }),
      D(45, 52, 40, 6, '{{qty}}'),
      T(15, 60, 28, 6, 'Date', { fontWeight: 'bold' }),
      D(45, 60, 40, 6, '{{date}}'),
      BC(140, 34, 55, 22, '{{order_no}}'),
      T(15, 75, 40, 6, 'Notes', { fontWeight: 'bold' }),
      R(15, 82, 180, 90),
    ]
  );
}

function shippingLabel(): DocTemplate {
  return tpl(
    'Shipping label',
    page(101.6, 152.4, 6),
    [
      { key: 'company_name', label: 'Company name', sample: 'Acme Corp' },
      { key: 'ship_to', label: 'Ship to', sample: 'Recipient Name\n456 Delivery Rd\nCity, ZIP' },
      { key: 'tracking', label: 'Tracking', sample: 'TRK-000123456' },
      { key: 'sku', label: 'SKU', sample: 'WID-001' },
    ],
    [
      D(6, 6, 90, 8, '{{company_name}}', { fontWeight: 'bold', fontSize: 12 }),
      L(6, 16, 90),
      T(6, 20, 40, 5, 'SHIP TO:', { fontWeight: 'bold', fontSize: 8, color: '#555555' }),
      D(6, 26, 90, 28, '{{ship_to}}', { fontSize: 12 }),
      BC(6, 60, 90, 26, '{{tracking}}'),
      D(6, 132, 90, 6, '{{sku}}', { fontSize: 8, align: 'center', color: '#666666' }),
    ]
  );
}

export interface StarterTemplate {
  id: string;
  label: string;
  make: () => DocTemplate;
}

/**
 * Built-in starter blocks — so the Blocks panel is never empty. A company
 * header + footer (with the company name) are the must-haves; users insert and
 * customise them like any block. Stable ids so a user's edit (saved under the
 * same id) overrides the built-in. Scope 'system'. When the backend lands,
 * these get seeded per-tenant pre-filled with real company info.
 */
function block(id: string, name: string, w: number, h: number, els: DocElement[]): DocBlock {
  return { id, name, scope: 'system', w, h, elements: els.map((e, i) => ({ ...e, z: i + 1 })) };
}

export const STARTER_BLOCKS: ReadonlyArray<DocBlock> = [
  block('sys-header', 'Company header', 180, 21, [
    D(0, 0, 120, 10, '{{company_name}}', { fontSize: 18, fontWeight: 'bold' }),
    T(0, 11, 180, 5, 'Address line · City · Country', { fontSize: 8, color: '#666666' }),
    L(0, 20, 180),
  ]),
  block('sys-footer', 'Company footer', 180, 8, [
    L(0, 0, 180),
    D(0, 2, 180, 6, '{{company_name}} · contact@example.com · +1 555 0100', {
      fontSize: 8,
      align: 'center',
      color: '#666666',
    }),
  ]),
];

/** Ordered starter set shown in the "Start from…" picker (Blank first). */
export const STARTER_TEMPLATES: ReadonlyArray<StarterTemplate> = [
  { id: 'blank', label: 'Blank', make: () => blankTemplate() },
  { id: 'invoice', label: 'Invoice', make: invoice },
  { id: 'exam', label: 'Exam sheet', make: examSheet },
  { id: 'production', label: 'Production note', make: productionNote },
  { id: 'shipping', label: 'Shipping label', make: shippingLabel },
];
