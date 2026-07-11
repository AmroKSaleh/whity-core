import type { DocTemplate, TextStyle } from './types';

/** Page-size presets (portrait millimetres). Orientation is applied separately. */
export const PAGE_PRESETS: ReadonlyArray<{ id: string; label: string; widthMm: number; heightMm: number }> = [
  { id: 'a4', label: 'A4 (210 × 297)', widthMm: 210, heightMm: 297 },
  { id: 'a5', label: 'A5 (148 × 210)', widthMm: 148, heightMm: 210 },
  { id: 'letter', label: 'US Letter (216 × 279)', widthMm: 215.9, heightMm: 279.4 },
  { id: 'label-4x6', label: 'Shipping label 4×6″ (102 × 152)', widthMm: 101.6, heightMm: 152.4 },
  { id: 'label-address', label: 'Address label (89 × 36)', widthMm: 88.9, heightMm: 36 },
  { id: 'label-62x29', label: 'Brother 62 × 29', widthMm: 62, heightMm: 29 },
  { id: 'thermal-57', label: 'Thermal receipt 57mm', widthMm: 57, heightMm: 120 },
  { id: 'badge', label: 'Name badge (86 × 54)', widthMm: 85.6, heightMm: 54 },
];

export const DEFAULT_TEXT_STYLE: TextStyle = {
  fontSize: 11,
  fontWeight: 'normal',
  fontStyle: 'normal',
  align: 'left',
  vAlign: 'top',
  color: '#111111',
};

/** A blank template — a small shipping-label sized page by default. */
export function blankTemplate(): DocTemplate {
  return {
    version: 1,
    name: 'Untitled template',
    page: { widthMm: 101.6, heightMm: 152.4, marginMm: 5, background: '#ffffff' },
    placeholders: [
      { key: 'company_name', label: 'Company name', sample: 'Acme Corp' },
      { key: 'sku', label: 'SKU', sample: 'WID-001' },
      { key: 'logo_url', label: 'Logo URL', sample: '' },
    ],
    elements: [],
  };
}
