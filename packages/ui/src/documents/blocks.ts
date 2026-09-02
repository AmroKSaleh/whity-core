import type { BlockInstanceElement, DocElement } from './types';

/**
 * Reusable blocks (Gutenberg synced-pattern model) for the document designer —
 * the pure model + resolution helpers. A block is a named group of elements
 * stored ONCE; documents reference it by id via a `blockInstance` element (a
 * pointer). Resolving an instance offsets the block's elements to the instance
 * position, so editing the block updates every instance.
 *
 * The localStorage-backed persistence (`listBlocks`/`saveBlock`/`deleteBlock`)
 * stays app-side in `web/lib/documents/blocks.ts`, which re-exports everything
 * here so existing imports are unaffected. MVP persistence is localStorage
 * (personal scope); a tenant-scoped backend store + tenant-wide publishing is
 * the follow-up (Tasker ca1d8c03).
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
  /**
   * Whether this block was SEEDED rather than authored by somebody — a tenant's
   * `sys-header` / `sys-footer`.
   *
   * Optional, and absent means "not seeded". That is the safe direction: it
   * under-warns before a delete rather than labelling an author's own block as
   * the product's.
   */
  isSystem?: boolean;
  /**
   * The stable identity of a seeded starter — `sys-header`, `sys-footer`.
   *
   * Set by the server's seeder and never by a client, so it survives a rename
   * where the display name does not. The built-in `STARTER_BLOCKS` carry the
   * same values as their `id`, which is what lets the two be matched.
   */
  starterKey?: string;
  /** Intrinsic size (bounding box of the block's elements), in millimetres. */
  w: number;
  h: number;
  elements: DocElement[];
}

function uid(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : `block-${Math.random().toString(36).slice(2)}`;
}

/** Index blocks by id for O(1) lookup during render. */
export function blocksById(list: DocBlock[]): Record<string, DocBlock> {
  const out: Record<string, DocBlock> = {};
  for (const b of list) out[b.id] = b;
  return out;
}

/**
 * The distinct block ids a template's `data` tree references via `blockInstance`
 * elements, in first-seen order.
 *
 * The TypeScript twin of PHP's `Whity\Core\Document\BlockReferenceScanner` —
 * SAME recursive-descent walk, same shape check (`{type: 'blockInstance',
 * blockId}` at any depth, in any page, under any key), so a client counting a
 * template's block references and the server answering
 * `GET /document-blocks/{id}/usage` cannot disagree about what counts as a
 * reference. A management screen that said "nothing uses this block" over a
 * delete the server then refuses is precisely the failure this parity avoids.
 *
 * Takes `unknown`, not `DocTemplate`, on purpose. The caller is a governance
 * screen reading raw API rows, and a row whose `data` fails template-shape
 * validation still has references that matter — `toSavedTemplate` returns null
 * for such a row, which is right for a canvas and would silently undercount
 * here. Anything that is not an object tree simply yields no ids.
 */
export function collectBlockIds(node: unknown): string[] {
  const ids: string[] = [];
  const seen = new Set<string>();

  const walk = (value: unknown): void => {
    if (value === null || typeof value !== 'object') return;

    if (Array.isArray(value)) {
      for (const item of value) walk(item);
      return;
    }

    const rec = value as Record<string, unknown>;
    if (rec.type === 'blockInstance' && 'blockId' in rec) {
      // Stringified for the reason the PHP scanner records: the client's
      // `blockId` field is a string while the backend id is numeric, and the two
      // meet here.
      const id = String(rec.blockId);
      if (!seen.has(id)) {
        seen.add(id);
        ids.push(id);
      }
    }
    for (const child of Object.values(rec)) walk(child);
  };

  walk(node);
  return ids;
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
