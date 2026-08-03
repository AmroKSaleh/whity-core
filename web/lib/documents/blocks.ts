import type { DocBlock } from '@amroksaleh/ui/documents/blocks';

export type { BlockScope, DocBlock } from '@amroksaleh/ui/documents/blocks';
export { BLOCK_SCOPES, blocksById, makeBlockFromElements, resolveInstance } from '@amroksaleh/ui/documents/blocks';

/**
 * Client-side persistence for reusable document/label-designer blocks
 * (localStorage-backed — kept here in `web/`, not in the portable
 * `@amroksaleh/ui` package). The block MODEL (`DocBlock`, `BlockScope`) and the
 * pure resolution helpers (`blocksById`, `makeBlockFromElements`,
 * `resolveInstance`) moved to `@amroksaleh/ui/documents/blocks` (re-exported
 * above, WC doc-designer-ui-extraction) so the rendering path
 * (`element-layer.tsx`) can depend on them without pulling in localStorage.
 * MVP persistence is localStorage (personal scope); a tenant-scoped backend
 * store + tenant-wide publishing is the follow-up (Tasker ca1d8c03).
 */

const STORE_KEY = 'whity.doc.blocks.v1';

export function listBlocks(): DocBlock[] {
  if (typeof localStorage === 'undefined') return [];
  try {
    const raw = localStorage.getItem(STORE_KEY);
    const parsed: unknown = raw ? JSON.parse(raw) : [];
    if (!Array.isArray(parsed)) return [];
    // Back-compat: blocks saved before scoping default to personal.
    return (parsed as DocBlock[]).map((b) => ({ ...b, scope: b.scope ?? 'personal' }));
  } catch {
    return [];
  }
}

/** Upsert a block by id; returns its id. */
export function saveBlock(block: DocBlock): string {
  const list = listBlocks();
  const idx = list.findIndex((b) => b.id === block.id);
  if (idx >= 0) list[idx] = block;
  else list.unshift(block);
  localStorage.setItem(STORE_KEY, JSON.stringify(list));
  return block.id;
}

export function deleteBlock(id: string): void {
  localStorage.setItem(STORE_KEY, JSON.stringify(listBlocks().filter((b) => b.id !== id)));
}
