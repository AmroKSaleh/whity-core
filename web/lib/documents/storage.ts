import type { DocElement, DocPage, DocTemplate, ElementType, PageSpec, Placeholder } from './types';
import { DEFAULT_TEXT_STYLE, newPageId } from './presets';

/**
 * Client-side template persistence + helpers for the document designer.
 *
 * MVP persistence is browser localStorage plus JSON export/import. A backend
 * `document_templates` table + API (tenant-scoped, RBAC-gated) is the intended
 * durable store — a coordination point flagged in the PR, not built here.
 */

const STORE_KEY = 'whity.doc.templates.v1';

export interface SavedTemplate {
  id: string;
  name: string;
  updatedAt: string;
  data: DocTemplate;
}

function uid(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : `id-${Math.random().toString(36).slice(2)}`;
}

/** Substitute `{{key}}` tokens from `data` (missing keys → empty string). */
export function interpolate(text: string, data: Record<string, string>): string {
  return text.replace(/\{\{\s*([\w.-]+)\s*\}\}/g, (_m, key: string) => data[key] ?? '');
}

/** The effective value for a bindable element: bound placeholder wins, else fallback. */
export function resolveBound(
  binding: string | undefined,
  fallback: string,
  data: Record<string, string>
): string {
  if (binding && data[binding] !== undefined && data[binding] !== '') {
    return data[binding];
  }
  return fallback;
}

/** Build the sample-data map from a template's placeholders. */
export function sampleDataOf(template: DocTemplate): Record<string, string> {
  const out: Record<string, string> = {};
  for (const p of template.placeholders) out[p.key] = p.sample;
  return out;
}

const nextZ = (els: DocElement[]): number => els.reduce((m, e) => Math.max(m, e.z), 0) + 1;

/** Factory: a new element of `type`, placed near the page origin. */
export function newElement(type: ElementType, els: DocElement[]): DocElement {
  const base = { id: uid(), x: 8, y: 8, rotation: 0, z: nextZ(els) };
  switch (type) {
    case 'text':
      return { ...base, type, w: 50, h: 10, text: 'Text', style: { ...DEFAULT_TEXT_STYLE } };
    case 'dynamicText':
      return { ...base, type, w: 60, h: 10, template: '{{company_name}}', style: { ...DEFAULT_TEXT_STYLE } };
    case 'image':
      return { ...base, type, w: 30, h: 30, src: '', binding: 'logo_url', fit: 'contain' };
    case 'barcode':
      return { ...base, type, w: 60, h: 20, symbology: 'code128', value: '{{sku}}', binding: undefined, showText: true };
    case 'qr':
      return { ...base, type, w: 25, h: 25, value: '{{sku}}', binding: undefined };
    case 'rect':
      return { ...base, type, w: 40, h: 20, fill: '#eef2ff', stroke: '#4f46e5', strokeWidth: 0.3, radius: 1 };
    case 'line':
      return { ...base, type, w: 50, h: 0.5, stroke: '#111111', strokeWidth: 0.5 };
    default: {
      const _exhaustive: never = type;
      throw new Error(`Unknown element type: ${String(_exhaustive)}`);
    }
  }
}

export function listSaved(): SavedTemplate[] {
  if (typeof localStorage === 'undefined') return [];
  try {
    const raw = localStorage.getItem(STORE_KEY);
    const parsed: unknown = raw ? JSON.parse(raw) : [];
    if (!Array.isArray(parsed)) return [];
    // Migrate persisted templates to the current shape on read.
    return (parsed as SavedTemplate[]).map((s) => ({ ...s, data: migrateTemplate(s.data) }));
  } catch {
    return [];
  }
}

/** Upsert a template into the saved list; returns its id. */
export function saveTemplate(data: DocTemplate, id?: string): string {
  const list = listSaved();
  const theId = id ?? uid();
  const entry: SavedTemplate = { id: theId, name: data.name, updatedAt: new Date().toISOString(), data };
  const idx = list.findIndex((s) => s.id === theId);
  if (idx >= 0) list[idx] = entry;
  else list.unshift(entry);
  localStorage.setItem(STORE_KEY, JSON.stringify(list));
  return theId;
}

export function deleteSaved(id: string): void {
  localStorage.setItem(STORE_KEY, JSON.stringify(listSaved().filter((s) => s.id !== id)));
}

/** Minimal structural validation of a template (accepts legacy v1 and v2). */
export function isDocTemplate(value: unknown): value is DocTemplate {
  if (!value || typeof value !== 'object') return false;
  const t = value as Record<string, unknown>;
  const versionOk = t.version === 1 || t.version === 2;
  const hasBody = Array.isArray(t.elements) || Array.isArray(t.pages); // v1 has elements, v2 has pages
  return (
    versionOk &&
    typeof t.name === 'string' &&
    typeof t.page === 'object' &&
    t.page !== null &&
    Array.isArray(t.placeholders) &&
    hasBody
  );
}

/**
 * Normalise any accepted template to the current v2 shape. v1 templates (a flat
 * top-level `elements` array) become a single-page v2 template. Idempotent for
 * templates already at v2.
 */
export function migrateTemplate(value: DocTemplate): DocTemplate {
  const t = value as unknown as {
    name: string;
    page: PageSpec;
    placeholders: Placeholder[];
    elements?: DocElement[];
    pages?: DocPage[];
  };
  if (Array.isArray(t.pages)) {
    return { version: 2, name: t.name, page: t.page, placeholders: t.placeholders, pages: t.pages };
  }
  return {
    version: 2,
    name: t.name,
    page: t.page,
    placeholders: t.placeholders,
    pages: [{ id: newPageId(), elements: t.elements ?? [] }],
  };
}

export function exportTemplateJson(template: DocTemplate): void {
  const blob = new Blob([JSON.stringify(template, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `${template.name.replace(/[^\w-]+/g, '_') || 'template'}.json`;
  a.click();
  URL.revokeObjectURL(url);
}
