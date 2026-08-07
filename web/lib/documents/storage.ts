import type { DocElement, DocPage, DocTemplate, ElementType, PageSpec, Placeholder } from './types';
import { DEFAULT_TEXT_STYLE, newPageId } from './presets';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';

/**
 * Template persistence + helpers for the document designer.
 *
 * Backed by the tenant-scoped, RBAC-gated `/api/v1/document-templates` CRUD
 * API (WC-515) via the typed OpenAPI client — the same calling convention used
 * throughout `app/(protected)/admin/*` (see e.g. the delegations page):
 * `api.GET/POST/PATCH/DELETE(...)` returning `{ data, error, response }`,
 * never thrown, with callers branching on the result. `listSaved`/
 * `saveTemplate`/`deleteSaved` THROW on failure (network error, non-2xx) so
 * the CALLER (document-designer.tsx) can catch and surface a toast, exactly
 * like every other admin page's data-fetching function — this module has no
 * UI/toast context of its own to report through.
 */

type DocumentTemplateRow = components['schemas']['DocumentTemplate'];

export interface SavedTemplate {
  id: string;
  name: string;
  updatedAt: string;
  data: DocTemplate;
}

/** Map an API row to the designer's SavedTemplate shape, migrating v1→v2 on
 *  read. Returns null for a row whose `data` fails basic template-shape
 *  validation (defensive — a corrupt/foreign row must not crash the whole
 *  designer, just be skipped from the saved list). */
function toSavedTemplate(row: DocumentTemplateRow): SavedTemplate | null {
  if (!isDocTemplate(row.data)) return null;
  return {
    id: String(row.id),
    name: row.name,
    updatedAt: row.updated_at,
    data: migrateTemplate(row.data),
  };
}

function uid(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : `id-${Math.random().toString(36).slice(2)}`;
}

// `interpolate`/`resolveBound` were pure (no localStorage) and moved to
// `@amroksaleh/ui/documents/interpolation` (WC doc-designer-ui-extraction) so
// the relocated `element-content.tsx` renderer can use them without pulling in
// this module's localStorage-backed persistence. Re-exported here for
// backward compatibility since this module used to own them.
export { interpolate, resolveBound } from '@amroksaleh/ui/documents/interpolation';

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
    case 'math':
      return { ...base, type, w: 40, h: 14, expression: 'x^2 + y^2 = z^2', block: true };
    default: {
      const _exhaustive: never = type;
      throw new Error(`Unknown element type: ${String(_exhaustive)}`);
    }
  }
}

/** The templates visible to the caller (server-side RBAC-filtered — see
 *  DocumentAccessPolicy), newest first. Throws on a network/API failure; the
 *  caller (document-designer.tsx) catches and toasts. */
export async function listSaved(): Promise<SavedTemplate[]> {
  const { data, response } = await api.GET('/api/v1/document-templates');
  if (data === undefined) {
    throw new Error(`Failed to load templates (${response.status})`);
  }
  return data.data.reduce<SavedTemplate[]>((out, row) => {
    const t = toSavedTemplate(row);
    if (t) out.push(t);
    return out;
  }, []);
}

/** Create (no `id`) or update (existing `id`) a template; returns its id
 *  (the id round-trips through PATCH; a fresh backend id comes back on
 *  create). Throws on failure. */
export async function saveTemplate(data: DocTemplate, id?: string): Promise<string> {
  const body = { name: data.name, data: data as unknown as Record<string, unknown> };

  if (id !== undefined) {
    const { data: updated, error, response } = await api.PATCH('/api/v1/document-templates/{id}', {
      params: { path: { id: Number(id) } },
      body,
    });
    if (error !== undefined || !response.ok || updated === undefined) {
      throw new Error(error?.error ?? 'Failed to save template');
    }
    return String(updated.data.id);
  }

  const { data: created, error, response } = await api.POST('/api/v1/document-templates', { body });
  if (error !== undefined || !response.ok || created === undefined) {
    throw new Error(error?.error ?? 'Failed to save template');
  }
  return String(created.data.id);
}

/** Delete a saved template. Throws on failure. */
export async function deleteSaved(id: string): Promise<void> {
  const { error, response } = await api.DELETE('/api/v1/document-templates/{id}', {
    params: { path: { id: Number(id) } },
  });
  if (error !== undefined || !response.ok) {
    throw new Error(error?.error ?? 'Failed to delete template');
  }
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
    sheet?: DocTemplate['sheet'];
    sequence?: DocTemplate['sequence'];
  };
  const extras = {
    ...(t.sheet ? { sheet: t.sheet } : {}),
    ...(t.sequence ? { sequence: t.sequence } : {}),
  };
  if (Array.isArray(t.pages)) {
    return { version: 2, name: t.name, page: t.page, placeholders: t.placeholders, pages: t.pages, ...extras };
  }
  return {
    version: 2,
    name: t.name,
    page: t.page,
    placeholders: t.placeholders,
    pages: [{ id: newPageId(), elements: t.elements ?? [] }],
    ...extras,
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
