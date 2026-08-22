import { repointBlockInstances } from '../template-model';
import type { DocTemplate } from '@amroksaleh/ui/documents/types';

/**
 * Regression cover for the "missing block" defect.
 *
 * Reported from the desktop client but present in web all along: editing a
 * STARTER block (`sys-header`) takes the create path, because its id is not a
 * backend id — so the backend mints a new numeric one. `refreshBlocks()` then
 * drops the starter from the library (a saved block now shares its name), and
 * every instance already on the page still points at `sys-header`, resolving
 * to nothing and rendering as "missing block".
 */

const PAGE = { widthMm: 100, heightMm: 100, marginMm: 0, background: '#ffffff' };

const instance = (id: string, blockId: string) => ({
  id,
  type: 'blockInstance' as const,
  blockId,
  x: 0,
  y: 0,
  w: 10,
  h: 10,
  rotation: 0,
  z: 1,
});

const text = (id: string) => ({
  id,
  type: 'text' as const,
  x: 0,
  y: 0,
  w: 10,
  h: 10,
  rotation: 0,
  z: 1,
  text: 'hi',
  style: {},
});

function templateWith(...pages: Array<Array<ReturnType<typeof instance> | ReturnType<typeof text>>>) {
  return {
    version: 2,
    name: 'T',
    page: PAGE,
    placeholders: [],
    pages: pages.map((elements, i) => ({ id: `p${i + 1}`, elements })),
  } as unknown as DocTemplate;
}

describe('repointBlockInstances', () => {
  it('follows the new id across every page', () => {
    const t = templateWith(
      [instance('i1', 'sys-header'), text('t1')],
      [instance('i2', 'sys-header')],
    );

    const out = repointBlockInstances(t, 'sys-header', '12');

    expect(out.pages[0].elements[0]).toMatchObject({ id: 'i1', blockId: '12' });
    expect(out.pages[1].elements[0]).toMatchObject({ id: 'i2', blockId: '12' });
  });

  it('leaves instances of other blocks alone', () => {
    const t = templateWith([instance('i1', 'sys-header'), instance('i2', 'sys-footer')]);

    const out = repointBlockInstances(t, 'sys-header', '12');

    expect(out.pages[0].elements[0]).toMatchObject({ blockId: '12' });
    expect(out.pages[0].elements[1]).toMatchObject({ blockId: 'sys-footer' });
  });

  it('leaves non-instance elements untouched', () => {
    const t = templateWith([text('t1')]);

    const out = repointBlockInstances(t, 'sys-header', '12');

    expect(out.pages[0].elements[0]).toMatchObject({ id: 't1', type: 'text' });
  });

  it('returns the SAME template reference when nothing matched', () => {
    // Identity matters: this runs on every block save, and a fresh object
    // would re-render the whole canvas for a no-op.
    const t = templateWith([text('t1')]);

    expect(repointBlockInstances(t, 'sys-header', '12')).toBe(t);
  });

  it('is a no-op when the id did not change', () => {
    // The update path (a numeric id PATCHed in place) keeps its id, which is
    // the common case — it must cost nothing.
    const t = templateWith([instance('i1', '12')]);

    expect(repointBlockInstances(t, '12', '12')).toBe(t);
  });

  it('leaves pages that contain no matching instance untouched by reference', () => {
    const t = templateWith([text('t1')], [instance('i2', 'sys-header')]);

    const out = repointBlockInstances(t, 'sys-header', '12');

    expect(out.pages[0]).toBe(t.pages[0]);
    expect(out.pages[1]).not.toBe(t.pages[1]);
  });
});
