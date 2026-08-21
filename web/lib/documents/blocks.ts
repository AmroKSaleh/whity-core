import type { DocBlock } from '@amroksaleh/ui/documents/blocks';
import { api } from '@/lib/api/client';
import { toDocBlock } from '@amroksaleh/features/document-designer';

/**
 * Web's HTTP half of reusable-block persistence.
 *
 * The pure model (row mapping, element-list validation, bounding-box
 * derivation) moved to `@amroksaleh/features/document-designer` alongside the
 * designer itself; the block TYPE and the pure resolution helpers continue to
 * live in `@amroksaleh/ui/documents/blocks`. Both are re-exported here, so
 * every existing `@/lib/documents/blocks` import keeps working unchanged.
 *
 * These three stay on the typed OpenAPI client for the reasons given in
 * ./storage.ts. They THROW on failure so the caller can catch and toast.
 *
 * A block's `data` column is JUST the DocElement[] fragment (no `w`/`h`) —
 * see DocumentBlockRepository/CoreApiSchemas. `w`/`h` are a client-side
 * concern: recomputed on load from the elements' bounding box, exactly as
 * `makeBlockFromElements` derives them when a block is first authored.
 */

export type { BlockScope, DocBlock } from '@amroksaleh/ui/documents/blocks';
export { BLOCK_SCOPES, blocksById, makeBlockFromElements, resolveInstance } from '@amroksaleh/ui/documents/blocks';

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
