import type { DocBlock } from '@amroksaleh/ui/documents/blocks';
import type { DocElement } from './types';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';

export type { BlockScope, DocBlock } from '@amroksaleh/ui/documents/blocks';
export { BLOCK_SCOPES, blocksById, makeBlockFromElements, resolveInstance } from '@amroksaleh/ui/documents/blocks';

/**
 * Persistence for reusable document/label-designer blocks (kept here in
 * `web/`, not in the portable `@amroksaleh/ui` package). The block MODEL
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

type DocumentBlockRow = components['schemas']['DocumentBlock'];

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
function toDocBlock(row: DocumentBlockRow): DocBlock | null {
  if (!isElementList(row.data)) return null;
  const scope = (KNOWN_SCOPES as readonly string[]).includes(row.scope) ? (row.scope as DocBlock['scope']) : 'personal';
  return { id: String(row.id), name: row.name, scope, ...boundingBoxOf(row.data), elements: row.data };
}

/** The blocks visible to the caller (server-side RBAC-filtered). Throws on a
 *  network/API failure; the caller catches and toasts. */
export async function listBlocks(): Promise<DocBlock[]> {
  const { data, response } = await api.GET('/api/v1/document-blocks');
  if (data === undefined) {
    throw new Error(`Failed to load blocks (${response.status})`);
  }
  return data.data.reduce<DocBlock[]>((out, row) => {
    const b = toDocBlock(row);
    if (b) out.push(b);
    return out;
  }, []);
}

/** Upsert a block. `block.id` disambiguates create vs. update: every id this
 *  module hands back (from create()/listBlocks()) is a stringified numeric
 *  backend id, while a freshly-authored block not yet saved carries a
 *  non-numeric client id (`makeBlockFromElements` mints a
 *  `crypto.randomUUID()`) — so a purely-numeric id means "update", anything
 *  else means "create". Returns the block's id (a fresh numeric id,
 *  stringified, on create). Throws on failure. */
export async function saveBlock(block: DocBlock): Promise<string> {
  const body = {
    name: block.name,
    data: block.elements as unknown as Record<string, unknown>[],
    scope: block.scope,
  };

  if (/^\d+$/.test(block.id)) {
    const { data, error, response } = await api.PATCH('/api/v1/document-blocks/{id}', {
      params: { path: { id: Number(block.id) } },
      body,
    });
    if (error !== undefined || !response.ok || data === undefined) {
      throw new Error(error?.error ?? 'Failed to save block');
    }
    return String(data.data.id);
  }

  const { data, error, response } = await api.POST('/api/v1/document-blocks', { body });
  if (error !== undefined || !response.ok || data === undefined) {
    throw new Error(error?.error ?? 'Failed to save block');
  }
  return String(data.data.id);
}

/** Delete a block. Throws on failure (including the backend's 409
 *  reference-integrity guard when a template still holds a live
 *  `blockInstance` pointer at this block — surfaced via the thrown error's
 *  message). */
export async function deleteBlock(id: string): Promise<void> {
  const { error, response } = await api.DELETE('/api/v1/document-blocks/{id}', {
    params: { path: { id: Number(id) } },
  });
  if (error !== undefined || !response.ok) {
    throw new Error(error?.error ?? 'Failed to delete block');
  }
}
