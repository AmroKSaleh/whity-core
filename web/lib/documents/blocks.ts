import type { BlockInstanceElement, DocElement } from './types';

/**
 * Reusable blocks (Gutenberg synced-pattern model) for the document designer.
 *
 * A block is a named group of elements stored ONCE; documents reference it by id
 * via a `blockInstance` element (a pointer). Resolving an instance offsets the
 * block's elements to the instance position, so editing the block updates every
 * instance. MVP persistence is localStorage (personal scope); a tenant-scoped
 * backend store + tenant-wide publishing is the follow-up (Tasker ca1d8c03).
 */

/**
 * Visibility tier of a block. `personal` = the creator's own library; `tenant`
 * = published to everyone in the tenant; `global` = operator-wide. Only personal
 * is meaningful in the localStorage MVP; tenant/global become real once the
 * tenant-scoped backend store + RBAC (who may publish) land (Tasker ca1d8c03).
 */
export type BlockScope = 'system' | 'personal' | 'tenant' | 'global';

export const BLOCK_SCOPES: ReadonlyArray<{ id: BlockScope; label: string }> = [
  { id: 'system', label: 'System' },
  { id: 'personal', label: 'Personal' },
  { id: 'tenant', label: 'Tenant-wide' },
  { id: 'global', label: 'Global' },
];

export interface DocBlock {
  id: string;
  name: string;
  scope: BlockScope;
  /** Intrinsic size (bounding box of the block's elements), in millimetres. */
  w: number;
  h: number;
  elements: DocElement[];
}

const STORE_KEY = 'whity.doc.blocks.v1';

function uid(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : `block-${Math.random().toString(36).slice(2)}`;
}

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

/** Index blocks by id for O(1) lookup during render. */
export function blocksById(list: DocBlock[]): Record<string, DocBlock> {
  const out: Record<string, DocBlock> = {};
  for (const b of list) out[b.id] = b;
  return out;
}

/**
 * Build a block from a set of elements: normalise them to a (0,0) origin and
 * record the bounding-box size. Any block instances in the selection are
 * dropped (no nesting in the MVP). Returns null if nothing usable remains.
 */
export function makeBlockFromElements(name: string, els: DocElement[]): DocBlock | null {
  const flat = els.filter((e) => e.type !== 'blockInstance');
  if (flat.length === 0) return null;
  const minX = Math.min(...flat.map((e) => e.x));
  const minY = Math.min(...flat.map((e) => e.y));
  const maxX = Math.max(...flat.map((e) => e.x + e.w));
  const maxY = Math.max(...flat.map((e) => e.y + e.h));
  const elements = flat.map((e) => ({ ...e, x: e.x - minX, y: e.y - minY }));
  return { id: uid(), name: name.trim() || 'Block', scope: 'personal', w: maxX - minX, h: maxY - minY, elements };
}

/**
 * Resolve a block instance to positioned elements for rendering: the block's
 * (origin-normalised) elements offset to the instance's top-left. Returns [] if
 * the referenced block is missing (deleted). Non-interactive — the instance
 * itself is the single movable/selectable unit on the page.
 */
export function resolveInstance(
  instance: BlockInstanceElement,
  block: DocBlock | undefined
): DocElement[] {
  if (!block) return [];
  return block.elements.map((e) => ({ ...e, x: e.x + instance.x, y: e.y + instance.y }));
}
