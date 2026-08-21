import type { DocBlock } from '@amroksaleh/ui/documents/blocks';
import type { DocElement } from '@amroksaleh/ui/documents/types';

export type { BlockScope, DocBlock } from '@amroksaleh/ui/documents/blocks';
export { BLOCK_SCOPES, blocksById, makeBlockFromElements, resolveInstance } from '@amroksaleh/ui/documents/blocks';

/**
 * The PURE model half for reusable document/label-designer blocks: row
 * mapping, element-list validation and bounding-box derivation.
 *
 * The HTTP half (`listBlocks`/`saveBlock`/`deleteBlock`) moved behind
 * `DocumentDesignerAdapter` (./types); nothing here touches the network. The
 * block MODEL
 * (`DocBlock`, `BlockScope`) and the pure resolution helpers (`blocksById`,
 * `makeBlockFromElements`, `resolveInstance`) live in
 * `@amroksaleh/ui/documents/blocks` (re-exported above) so the rendering path
 * (`element-layer.tsx`) can depend on them without pulling in this module's
 * API-backed persistence.
 *
 * Backed by the tenant-scoped, RBAC-gated `/api/v1/document-blocks` CRUD API
 * (WC-521) via the typed OpenAPI client, the same convention used throughout
 * `app/(protected)/admin/*`. `listBlocks`/`saveBlock`/`deleteBlock` THROW on
 * failure so the caller (document-designer.tsx) can catch and toast — this
 * module has no UI/toast context of its own.
 *
 * A block's `data` column is JUST the DocElement[] fragment (no `w`/`h`) —
 * see DocumentBlockRepository/CoreApiSchemas. `w`/`h` are a client-side
 * concern: recomputed on load from the elements' bounding box, exactly as
 * `makeBlockFromElements` derives them when a block is first authored (a
 * small amount of deliberate author-chosen padding beyond the tightest
 * bounding box, e.g. a header block's box being a touch taller than its text,
 * is not preserved across a reload — cosmetic only, not a content loss).
 */

/**
 * The subset of a `/document-blocks` row this mapper reads. Structural rather
 * than the generated `components['schemas']['DocumentBlock']`, for the reason
 * given on `DocumentTemplateRow` in ./template-model.
 */
export interface DocumentBlockRow {
  id: number | string;
  name: string;
  scope: string;
  data: unknown;
}

const KNOWN_SCOPES = ['system', 'personal', 'tenant', 'global'] as const;

function isDocElement(value: unknown): value is DocElement {
  if (!value || typeof value !== 'object') return false;
  const e = value as Record<string, unknown>;
  return typeof e.id === 'string' && typeof e.type === 'string';
}

function isElementList(value: unknown): value is DocElement[] {
  return Array.isArray(value) && value.every(isDocElement);
}

/** Bounding box of a set of elements, in millimetres. */
function boundingBoxOf(elements: DocElement[]): { w: number; h: number } {
  if (elements.length === 0) return { w: 40, h: 40 };
  const minX = Math.min(...elements.map((e) => e.x));
  const minY = Math.min(...elements.map((e) => e.y));
  const maxX = Math.max(...elements.map((e) => e.x + e.w));
  const maxY = Math.max(...elements.map((e) => e.y + e.h));
  return { w: maxX - minX, h: maxY - minY };
}

/** Map an API row to the designer's DocBlock shape. Returns null for a row
 *  whose `data` fails basic element-list validation (defensive — a
 *  corrupt/foreign row must not crash the whole designer). */
export function toDocBlock(row: DocumentBlockRow): DocBlock | null {
  if (!isElementList(row.data)) return null;
  const scope = (KNOWN_SCOPES as readonly string[]).includes(row.scope) ? (row.scope as DocBlock['scope']) : 'personal';
  return { id: String(row.id), name: row.name, scope, ...boundingBoxOf(row.data), elements: row.data };
}
