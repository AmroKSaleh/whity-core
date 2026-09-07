import {
  MAX_BLOCK_DEPTH,
  blockChildIds,
  blocksById,
  flattenBlock,
  makeBlockFromElements,
  wouldCycle,
} from '@amroksaleh/ui/documents/blocks';
import type { DocBlock } from '@amroksaleh/ui/documents/blocks';
import type { DocElement } from '@amroksaleh/ui/documents/types';

/**
 * Blocks inside blocks (#1186 slice 3).
 *
 * A block library only composes if a block may hold another one — a letterhead
 * containing the logo block means the logo is stored once and every letterhead
 * follows it when it changes.
 *
 * The interesting cases are not the happy path. They are the three ways an
 * expansion can fail — the block is gone, the block contains itself, the
 * nesting runs away — all of which LOOK THE SAME on the page: nothing is drawn.
 * So most of what follows is about the difference between drawing nothing and
 * saying nothing.
 */

const style = {
  fontSize: 11,
  fontWeight: 'normal' as const,
  fontStyle: 'normal' as const,
  align: 'left' as const,
  vAlign: 'top' as const,
  color: '#111',
};

function text(id: string, x: number, y: number, label: string): DocElement {
  return { id, type: 'text', x, y, w: 20, h: 5, rotation: 0, z: 1, text: label, style } as DocElement;
}

function instance(id: string, blockId: string, x: number, y: number): DocElement {
  return { id, type: 'blockInstance', blockId, x, y, w: 20, h: 10, rotation: 0, z: 2 } as DocElement;
}

function block(id: string, elements: DocElement[]): DocBlock {
  return { id, name: id, scope: 'personal', w: 40, h: 20, elements };
}

describe('expanding a block that holds another block', () => {
  it('draws the nested block, which used to render as nothing at all', () => {
    const logo = block('logo', [text('t1', 0, 0, 'ACME')]);
    const letterhead = block('letterhead', [text('t2', 0, 0, 'Head'), instance('i1', 'logo', 5, 10)]);
    const lib = blocksById([logo, letterhead]);

    const { elements } = flattenBlock(letterhead, lib);

    // Two leaves: nothing is left that ElementContent would meet as a
    // `blockInstance` and draw as null.
    expect(elements.map((e) => (e as unknown as { text: string }).text)).toEqual(['Head', 'ACME']);
    expect(elements.some((e) => e.type === 'blockInstance')).toBe(false);
  });

  it('offsets the nested elements by the instance position, cumulatively', () => {
    const inner = block('inner', [text('t1', 1, 2, 'deep')]);
    const middle = block('middle', [instance('i1', 'inner', 10, 20)]);
    const outer = block('outer', [instance('i2', 'middle', 100, 200)]);

    const { elements } = flattenBlock(outer, blocksById([inner, middle, outer]));

    // 100 + 10 + 1 and 200 + 20 + 2. An offset applied once per level is the
    // whole point; applied once total, deep content piles at the origin.
    expect(elements).toHaveLength(1);
    expect(elements[0].x).toBe(111);
    expect(elements[0].y).toBe(222);
  });

  it('honours an explicit origin so an instance on a page lands where it sits', () => {
    const logo = block('logo', [text('t1', 1, 1, 'ACME')]);
    const { elements } = flattenBlock(logo, blocksById([logo]), { x: 50, y: 60 });
    expect([elements[0].x, elements[0].y]).toEqual([51, 61]);
  });

  it('expands the same block twice when it is used as siblings', () => {
    const logo = block('logo', [text('t1', 0, 0, 'ACME')]);
    const pair = block('pair', [instance('i1', 'logo', 0, 0), instance('i2', 'logo', 0, 50)]);

    // Reuse is the FEATURE. A guard that remembered "already expanded" rather
    // than "currently open" would silently drop the second copy.
    const { elements } = flattenBlock(pair, blocksById([logo, pair]));
    expect(elements).toHaveLength(2);
    expect(elements.map((e) => e.y)).toEqual([0, 50]);
  });

  it('skips hidden elements and hidden nested instances alike', () => {
    const logo = block('logo', [text('t1', 0, 0, 'ACME')]);
    const host = block('host', [
      { ...text('t2', 0, 0, 'shown') },
      { ...text('t3', 0, 0, 'gone'), hidden: true } as DocElement,
      { ...instance('i1', 'logo', 0, 0), hidden: true } as DocElement,
    ]);

    const { elements } = flattenBlock(host, blocksById([logo, host]));
    expect(elements.map((e) => (e as unknown as { text: string }).text)).toEqual(['shown']);
  });

  it('renumbers z in traversal order rather than carrying nested values over', () => {
    // A child's z is relative to its own block. Carried over, a nested z=9 would
    // jump in front of its parent's neighbours once merged into one layer.
    const inner = block('inner', [{ ...text('t1', 0, 0, 'inner'), z: 9 } as DocElement]);
    const host = block('host', [
      { ...text('t2', 0, 0, 'first'), z: 1 } as DocElement,
      { ...instance('i1', 'inner', 0, 0), z: 2 } as DocElement,
      { ...text('t3', 0, 0, 'last'), z: 3 } as DocElement,
    ]);

    const { elements } = flattenBlock(host, blocksById([inner, host]));
    expect(elements.map((e) => (e as unknown as { text: string }).text)).toEqual([
      'first',
      'inner',
      'last',
    ]);
    expect(elements.map((e) => e.z)).toEqual([0, 1, 2]);
  });
});

describe('the three ways an expansion fails', () => {
  it('reports a missing block instead of only omitting it', () => {
    const host = block('host', [text('t1', 0, 0, 'kept'), instance('i1', 'ghost', 0, 0)]);

    const { elements, diagnostics } = flattenBlock(host, blocksById([host]));

    // Drawn: what resolved. Reported: what did not. Elements alone cannot tell
    // "this block is empty" from "this block is broken".
    expect(elements).toHaveLength(1);
    expect(diagnostics.unresolved).toEqual(['ghost']);
  });

  it('cuts a cycle and names it, rather than recursing forever', () => {
    const a = block('a', [text('t1', 0, 0, 'A'), instance('i1', 'b', 0, 10)]);
    const b = block('b', [text('t2', 0, 0, 'B'), instance('i2', 'a', 0, 10)]);

    const { elements, diagnostics } = flattenBlock(a, blocksById([a, b]));

    // The real branches still print. Refusing the whole flatten would turn one
    // bad pointer into a blank page.
    expect(elements.map((e) => (e as unknown as { text: string }).text)).toEqual(['A', 'B']);
    expect(diagnostics.cycles).toEqual(['a']);
  });

  it('survives a block that contains itself directly', () => {
    const self = block('self', [text('t1', 0, 0, 'S'), instance('i1', 'self', 0, 0)]);

    const { elements, diagnostics } = flattenBlock(self, blocksById([self]));
    expect(elements).toHaveLength(1);
    expect(diagnostics.cycles).toEqual(['self']);
  });

  it('stops at the depth limit and says it did', () => {
    // A legal, acyclic chain longer than the limit — b0 -> b1 -> ... -> b30.
    const chain: DocBlock[] = [];
    for (let i = 0; i < 30; i += 1) {
      chain.push(block(`b${i}`, [text(`t${i}`, 0, 0, `L${i}`), instance(`i${i}`, `b${i + 1}`, 0, 0)]));
    }
    chain.push(block('b30', [text('t30', 0, 0, 'END')]));

    const { elements, diagnostics } = flattenBlock(chain[0], blocksById(chain));

    expect(diagnostics.tooDeep).toBe(true);
    // Everything above the cut still draws — the limit trims a tail, it does
    // not discard the document.
    expect(elements.length).toBe(MAX_BLOCK_DEPTH);
    expect(diagnostics.cycles).toEqual([]);
  });

  it('is quiet when nothing is wrong', () => {
    const logo = block('logo', [text('t1', 0, 0, 'ACME')]);
    const host = block('host', [instance('i1', 'logo', 0, 0)]);

    expect(flattenBlock(host, blocksById([logo, host])).diagnostics).toEqual({
      unresolved: [],
      cycles: [],
      tooDeep: false,
    });
  });
});

describe('refusing a cycle before it is built', () => {
  it('refuses a block inside itself', () => {
    expect(wouldCycle({}, 'a', 'a')).toBe(true);
  });

  it('refuses the indirect case, which is the one built by accident', () => {
    // A already holds B. Putting A into B months later closes the loop, and
    // nothing on screen makes the first pointer visible.
    const a = block('a', [instance('i1', 'b', 0, 0)]);
    const b = block('b', []);

    expect(wouldCycle(blocksById([a, b]), 'b', 'a')).toBe(true);
  });

  it('allows the same block in two places that do not close a loop', () => {
    const logo = block('logo', []);
    const head = block('head', [instance('i1', 'logo', 0, 0)]);

    expect(wouldCycle(blocksById([logo, head]), 'head', 'logo')).toBe(false);
  });

  it('terminates even when the library already contains a cycle', () => {
    // The guard must not itself hang on data that is already broken.
    const a = block('a', [instance('i1', 'b', 0, 0)]);
    const b = block('b', [instance('i2', 'a', 0, 0)]);

    expect(wouldCycle(blocksById([a, b]), 'fresh', 'a')).toBe(false);
  });

  it('allows an insert whose target is not in the library', () => {
    expect(wouldCycle({}, 'container', 'unknown')).toBe(false);
  });
});

describe('building a block out of a selection', () => {
  it('KEEPS a nested instance that used to be dropped silently', () => {
    const made = makeBlockFromElements('Letterhead', [
      text('t1', 10, 10, 'Head'),
      instance('i1', 'logo', 10, 20),
    ]);

    // Before, this saved successfully with the logo missing: the worse half of
    // not supporting a feature, because the save reported success.
    expect(made?.elements).toHaveLength(2);
    expect(made?.elements.some((e) => e.type === 'blockInstance')).toBe(true);
  });

  it('normalises a selection of instances to the origin', () => {
    const made = makeBlockFromElements('Pair', [
      instance('i1', 'logo', 30, 40),
      instance('i2', 'logo', 30, 60),
    ]);

    expect(made?.elements.map((e) => [e.x, e.y])).toEqual([
      [0, 0],
      [0, 20],
    ]);
  });

  it('still returns null for an empty selection', () => {
    expect(makeBlockFromElements('Empty', [])).toBeNull();
  });
});

describe('the ids a block points at', () => {
  it('lists direct children, which is what a delete guard must ask about', () => {
    const host = block('host', [text('t1', 0, 0, 'x'), instance('i1', 'logo', 0, 0)]);
    expect(blockChildIds(host)).toEqual(['logo']);
  });

  it('is empty for a block that nests nothing', () => {
    expect(blockChildIds(block('plain', [text('t1', 0, 0, 'x')]))).toEqual([]);
  });
});
