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
 * record the bounding-box size. Returns null if the selection is empty.
 *
 * BLOCK INSTANCES IN THE SELECTION ARE KEPT (#1186 slice 3). They used to be
 * filtered out — "no nesting in the MVP" — which meant selecting a letterhead
 * plus its logo and saving the pair gave you a block with the logo silently
 * missing. The save reported success and the result was wrong, which is the
 * worse half of not supporting a feature.
 *
 * A selection cannot itself introduce a cycle: the block being built has an id
 * that does not exist yet, so nothing in the selection can point at it. Cycles
 * only become possible when an EXISTING block is edited, which is what
 * {@link wouldCycle} guards.
 */
export function makeBlockFromElements(name: string, els: DocElement[]): DocBlock | null {
  if (els.length === 0) return null;
  const minX = Math.min(...els.map((e) => e.x));
  const minY = Math.min(...els.map((e) => e.y));
  const maxX = Math.max(...els.map((e) => e.x + e.w));
  const maxY = Math.max(...els.map((e) => e.y + e.h));
  const elements = els.map((e) => ({ ...e, x: e.x - minX, y: e.y - minY }));
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

/**
 * How deep a block may nest inside another block (#1186 slice 3).
 *
 * A limit rather than "as deep as it goes", because resolution is recursive and
 * a document is not a data structure a person keeps in their head past a few
 * levels. Ten is far past any layout anyone has asked for and far short of
 * anything that troubles a stack.
 *
 * This is NOT the cycle guard. Cycles are caught by identity, below, and a
 * cycle would trip this limit only by accident.
 */
export const MAX_BLOCK_DEPTH = 10;

/** What a flatten could not draw, so a caller can say so instead of guessing. */
export interface FlattenDiagnostics {
  /** Ids referenced by an instance that resolved to no block in the library. */
  unresolved: string[];
  /** Ids whose expansion re-entered a block already open above it. */
  cycles: string[];
  /** True when nesting was cut off at {@link MAX_BLOCK_DEPTH}. */
  tooDeep: boolean;
}

export interface FlattenResult {
  /** Leaf elements only — every nested instance already expanded away. */
  elements: DocElement[];
  diagnostics: FlattenDiagnostics;
}

/**
 * Expand a block's elements to LEAVES, resolving nested block instances.
 *
 * Nesting is what makes a block library compose: a letterhead block holding the
 * logo block means the logo is stored once and every letterhead follows it.
 * Before this, `makeBlockFromElements` dropped instances outright and this
 * resolution went one level deep, so a nested instance reached
 * `ElementContent`, matched `case 'blockInstance'`, and rendered `null` — it
 * appeared nowhere and reported nothing.
 *
 * WHY THIS RETURNS DIAGNOSTICS AND NOT JUST ELEMENTS
 * --------------------------------------------------
 * Three things can stop an instance expanding — the block is gone, the block is
 * an ancestor of itself, or the nesting is deeper than {@link MAX_BLOCK_DEPTH}
 * — and all three have the same visual result: nothing is drawn. Returning only
 * elements would make "this block is empty" and "this block could not be
 * resolved" indistinguishable to every caller, which is how a hole gets printed
 * onto a customer's document and nobody hears about it.
 *
 * So the shape mirrors the rule the renderer already follows: draw nothing, and
 * hand the caller enough to SAY so. The editor shows a marker; print draws the
 * document without it.
 *
 * CYCLES ARE CUT, NOT REFUSED. A block that contains itself is malformed, but
 * it is malformed in one branch of a tree whose other branches are fine. Cutting
 * the re-entry and reporting it prints the parts that are real; refusing the
 * whole flatten would turn one bad pointer into a blank page.
 */
export function flattenBlock(
  block: DocBlock,
  blocks: Record<string, DocBlock>,
  origin: { x: number; y: number } = { x: 0, y: 0 }
): FlattenResult {
  const elements: DocElement[] = [];
  const unresolved = new Set<string>();
  const cycles = new Set<string>();
  let tooDeep = false;
  let z = 0;

  /**
   * `path` is the chain of block ids currently open above this point, so the
   * check is "is this block already an ancestor of itself", not "have we drawn
   * it before". A block used twice as SIBLINGS is perfectly legal and must
   * expand both times; only re-entering an open one is a cycle.
   */
  const walk = (from: DocBlock, dx: number, dy: number, path: string[]): void => {
    if (path.length > MAX_BLOCK_DEPTH) {
      tooDeep = true;
      return;
    }

    for (const el of [...from.elements].sort((a, b) => a.z - b.z)) {
      if (el.hidden) continue;

      if (el.type !== 'blockInstance') {
        // z is REASSIGNED in traversal order rather than carried over. A nested
        // block's z values are relative to that block, so keeping them would
        // let a child's z=9 jump in front of its own parent's neighbours once
        // the two lists were merged into one layer.
        elements.push({ ...el, x: el.x + dx, y: el.y + dy, z: z++ });
        continue;
      }

      const child = blocks[el.blockId];
      if (!child) {
        unresolved.add(el.blockId);
        continue;
      }
      if (path.includes(el.blockId)) {
        cycles.add(el.blockId);
        continue;
      }
      walk(child, el.x + dx, el.y + dy, [...path, el.blockId]);
    }
  };

  walk(block, origin.x, origin.y, [block.id]);

  return {
    elements,
    diagnostics: { unresolved: [...unresolved], cycles: [...cycles], tooDeep },
  };
}

/**
 * Would putting `insertedId` inside `containerId` make a block contain itself?
 *
 * Asked BEFORE an insert, because the honest moment to refuse a cycle is when
 * somebody builds one, not when a document later fails to print part of itself.
 * Inserting a block into itself is the direct case; the walk covers the case
 * that is actually easy to create by accident — A holds B, and someone puts A
 * into B months later without remembering the first pointer.
 */
export function wouldCycle(
  blocks: Record<string, DocBlock>,
  containerId: string,
  insertedId: string
): boolean {
  if (containerId === insertedId) return true;

  const seen = new Set<string>();
  const reaches = (id: string): boolean => {
    if (id === containerId) return true;
    if (seen.has(id)) return false;
    seen.add(id);

    const block = blocks[id];
    if (!block) return false;
    return block.elements.some((el) => el.type === 'blockInstance' && reaches(el.blockId));
  };

  return reaches(insertedId);
}

/**
 * The block ids `block` points at directly — one level, not transitive.
 *
 * Separate from {@link collectBlockIds} because that one takes an arbitrary
 * template tree and this one takes a block, and the delete guard needs to ask
 * about blocks specifically: a block referenced only by ANOTHER BLOCK was
 * invisible to a guard that queried templates alone.
 */
export function blockChildIds(block: DocBlock): string[] {
  return collectBlockIds(block.elements);
}
