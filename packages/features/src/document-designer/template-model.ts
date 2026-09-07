import type { DocElement, DocPage, DocTemplate, ElementType, PageSpec, Placeholder } from '@amroksaleh/ui/documents/types';
import { DEFAULT_TEXT_STYLE, newPageId } from './presets';
import type { SavedTemplate } from './types';

/**
 * The document designer's PURE template model: validation, v1->v2 migration,
 * element factory, sample-data derivation, row mapping and JSON export.
 *
 * The HTTP half that used to live beside this (`listSaved`/`saveTemplate`/
 * `deleteSaved`) moved behind `DocumentDesignerAdapter` (./types) so the
 * screen can be driven by web's typed api-client on one client and the
 * desktop's `remote_request` transport on another. Nothing here touches the
 * network.
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

/**
 * The subset of a `/document-templates` row this mapper reads.
 *
 * Declared structurally rather than imported from web's generated
 * `components['schemas']['DocumentTemplate']`: this package has no OpenAPI
 * schema and must not gain one. The generated type is structurally assignable
 * to this, so web's call sites keep their full generated typing.
 */
export interface DocumentTemplateRow {
  id: number | string;
  name: string;
  updated_at: string;
  data: unknown;
  /**
   * WHO CAN SEE THIS TEMPLATE — `personal`, `tenant`, `global` or `system`.
   *
   * Optional because a row from a server that predates the field, or a fixture
   * written before it, must still map. Absent reads as `personal`, which is
   * what the server itself defaults a missing scope to.
   *
   * CARRIED HERE BECAUSE ITS ABSENCE WAS INVISIBLE AND EXPENSIVE. The designer
   * never declared this field, so it never sent one either — and the server
   * reads a missing `scope` on CREATE as `personal`, which
   * `DocumentAccessPolicy::canView()` defines as *the creator and nobody else*.
   * Every template authored in the designer was therefore invisible to every
   * other person in the tenant, absent from their create-document picker, and
   * the only place to change it was a different page behind a different
   * permission. Nothing said so; the save toast read "Template saved."
   *
   * Blocks have carried their scope from the beginning and have a control for
   * it in the palette, which is what makes this an oversight rather than a
   * policy.
   */
  scope?: string;
}

export type { SavedTemplate };

/** Map an API row to the designer's SavedTemplate shape, migrating v1→v2 on
 *  read. Returns null for a row whose `data` fails basic template-shape
 *  validation (defensive — a corrupt/foreign row must not crash the whole
 *  designer, just be skipped from the saved list). */
export function toSavedTemplate(row: DocumentTemplateRow): SavedTemplate | null {
  if (!isDocTemplate(row.data)) return null;
  return {
    id: String(row.id),
    name: row.name,
    updatedAt: row.updated_at,
    data: migrateTemplate(row.data),
    // Absent reads as `personal` — the same reading the server gives a missing
    // scope, so a row from an older backend describes itself the same way on
    // both sides rather than arriving as `undefined` and being defaulted twice.
    scope: typeof row.scope === 'string' && row.scope !== '' ? row.scope : 'personal',
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

/**
 * Repoint every `blockInstance` that references `fromId` at `toId`.
 *
 * Saving a block whose id is not a backend id — a starter (`sys-header`), or
 * any block authored locally and never persisted — takes the CREATE path, and
 * the backend mints a fresh numeric id. Every instance already placed on the
 * page still points at the old one, and `refreshBlocks()` drops the starter
 * from the library the moment a saved block shares its name, so those
 * instances resolve to nothing and render as "missing block".
 *
 * Returns the template unchanged (same reference) when nothing matched, so a
 * no-op save does not force a re-render.
 */
export function repointBlockInstances(template: DocTemplate, fromId: string, toId: string): DocTemplate {
  if (fromId === toId) return template;

  let touched = false;
  const pages = template.pages.map((page) => {
    let pageTouched = false;
    const elements = page.elements.map((el) => {
      if (el.type === 'blockInstance' && el.blockId === fromId) {
        pageTouched = true;
        return { ...el, blockId: toId };
      }
      return el;
    });
    if (!pageTouched) return page;
    touched = true;
    return { ...page, elements };
  });

  return touched ? { ...template, pages } : template;
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
