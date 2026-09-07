import { canvasToFlow, describeSwitch, flowToCanvas } from '@amroksaleh/features/document-designer';
import type { DocTemplate } from '@amroksaleh/ui/documents/types';

/**
 * Switching a template between canvas and flow (#1186 slice 2).
 *
 * THE ASYMMETRY IS THE SUBJECT. Flow to canvas is additive: blocks gain
 * coordinates and nothing is lost. Canvas to flow LOSES POSITION and cannot get
 * it back, because a canvas element knows exactly where it sits while a flow
 * block knows only what precedes it.
 *
 * So these tests are less about the conversions being clever and more about
 * them being HONEST: what is dropped is dropped deliberately, and
 * `describeSwitch` reports the same truth the confirmation shows the author.
 */

const PAGE = { widthMm: 210, heightMm: 297, marginMm: 10, background: '#ffffff' };

const style = {
  fontSize: 11,
  fontWeight: 'normal' as const,
  fontStyle: 'normal' as const,
  align: 'left' as const,
  vAlign: 'top' as const,
  color: '#111',
};

function canvasTemplate(elements: unknown[]): DocTemplate {
  return {
    version: 2,
    name: 'T',
    page: PAGE,
    placeholders: [],
    pages: [{ id: 'p1', elements: elements as never }],
  } as DocTemplate;
}

let n = 0;
const uid = () => `id-${(n += 1)}`;

describe('canvas to flow', () => {
  it('reads the page top-to-bottom, not in creation order', () => {
    const t = canvasTemplate([
      { id: 'b', type: 'text', x: 10, y: 90, w: 50, h: 10, rotation: 0, z: 1, text: 'second', style },
      { id: 'a', type: 'text', x: 10, y: 10, w: 50, h: 10, rotation: 0, z: 2, text: 'first', style },
    ]);

    // Reading order is the only ordering a coordinate grid honestly supports.
    expect(canvasToFlow(t).blocks).toEqual([
      { type: 'paragraph', text: 'first' },
      { type: 'paragraph', text: 'second' },
    ]);
  });

  it('keeps a dynamic field as its TEMPLATE, not its sample value', () => {
    const t = canvasTemplate([
      {
        id: 'a',
        type: 'dynamicText',
        x: 10,
        y: 10,
        w: 50,
        h: 10,
        rotation: 0,
        z: 1,
        template: '{{company_name}}',
        style,
      },
    ]);

    // A template is not its sample data. Freezing today's sample in would
    // silently convert a placeholder into a literal that never updates again.
    expect(canvasToFlow(t).blocks).toEqual([{ type: 'paragraph', text: '{{company_name}}' }]);
  });

  /**
   * Dropped, not approximated. A rectangle is a drawn shape, not a paragraph;
   * turning one into an empty block would put something in the reading order
   * the author never wrote, which the renderer then prints as a gap.
   */
  it('drops shapes and lines rather than inventing empty blocks for them', () => {
    const t = canvasTemplate([
      {
        id: 'r',
        type: 'rect',
        x: 10, y: 10, w: 50, h: 20, rotation: 0, z: 1,
        fill: '#eee', stroke: '#000', strokeWidth: 0.3, radius: 1,
      },
      { id: 'l', type: 'line', x: 10, y: 40, w: 50, h: 0.5, rotation: 0, z: 2, stroke: '#000', strokeWidth: 0.5 },
    ]);

    expect(canvasToFlow(t).blocks).toEqual([]);
  });

  it('drops a remote image, because the flow renderer refuses one', () => {
    const t = canvasTemplate([
      { id: 'i', type: 'image', x: 10, y: 10, w: 30, h: 30, rotation: 0, z: 1, src: 'https://example.com/a.png', fit: 'contain' },
      { id: 'j', type: 'image', x: 10, y: 50, w: 30, h: 30, rotation: 0, z: 2, src: 'data:image/png;base64,AAA', fit: 'contain' },
    ]);

    // Carrying the remote one over would produce a block that exists and
    // cannot print — the exact failure this architecture is arranged around.
    expect(canvasToFlow(t).blocks).toEqual([{ type: 'figure', dataUri: 'data:image/png;base64,AAA' }]);
  });

  it('separates pages with a page break', () => {
    const t: DocTemplate = {
      ...canvasTemplate([]),
      pages: [
        { id: 'p1', elements: [{ id: 'a', type: 'text', x: 0, y: 0, w: 10, h: 10, rotation: 0, z: 1, text: 'one', style }] as never },
        { id: 'p2', elements: [{ id: 'b', type: 'text', x: 0, y: 0, w: 10, h: 10, rotation: 0, z: 1, text: 'two', style }] as never },
      ],
    };

    expect(canvasToFlow(t).blocks.map((b) => b.type)).toEqual(['paragraph', 'pageBreak', 'paragraph']);
  });
});

describe('flow to canvas', () => {
  it('stacks blocks down the page from the margin', () => {
    const t: DocTemplate = {
      ...canvasTemplate([]),
      flow: { blocks: [{ type: 'heading', level: 1, text: 'Title' }, { type: 'paragraph', text: 'Body' }] },
    };

    const out = flowToCanvas(t, uid);
    const els = out.pages[0].elements;

    expect(out.mode).toBe('canvas');
    expect(els).toHaveLength(2);
    expect(els[0].x).toBe(PAGE.marginMm);
    // Stacked, not piled: the second sits below the first.
    expect(els[1].y).toBeGreaterThan(els[0].y);
    expect(els[0].w).toBe(PAGE.widthMm - PAGE.marginMm * 2);
  });

  it('represents a table by its caption rather than leaving a hole', () => {
    const t: DocTemplate = {
      ...canvasTemplate([]),
      flow: { blocks: [{ type: 'table', columns: ['a'], rows: [['1']], caption: 'Results' }] },
    };

    // There is no canvas table element. Showing the caption lets the author see
    // something was here and replace it; dropping it silently would not.
    const els = flowToCanvas(t, uid).pages[0].elements;
    expect(els).toHaveLength(1);
    expect((els[0] as unknown as { text: string }).text).toBe('Results');
  });

  it('does nothing when there is no flow content to lay out', () => {
    const t = canvasTemplate([]);
    expect(flowToCanvas(t, uid)).toBe(t);
  });
});

describe('describing the cost before it is paid', () => {
  it('reports canvas to flow as lossy, counting only what survives', () => {
    const t = canvasTemplate([
      { id: 'a', type: 'text', x: 0, y: 0, w: 10, h: 10, rotation: 0, z: 1, text: 'kept', style },
      {
        id: 'r',
        type: 'rect',
        x: 0, y: 20, w: 10, h: 10, rotation: 0, z: 2,
        fill: '#eee', stroke: '#000', strokeWidth: 0.3, radius: 1,
      },
    ]);

    // The number shown to the author is the number that SURVIVES — counting
    // the rectangle would promise it carries over when it does not.
    expect(describeSwitch(t, 'flow')).toEqual({ carried: 1, lossy: true });
  });

  it('reports flow to canvas as lossless', () => {
    const t: DocTemplate = {
      ...canvasTemplate([]),
      flow: { blocks: [{ type: 'paragraph', text: 'a' }, { type: 'paragraph', text: 'b' }] },
    };

    expect(describeSwitch(t, 'canvas')).toEqual({ carried: 2, lossy: false });
  });

  it('is not lossy when the canvas is empty — there is nothing to lose', () => {
    expect(describeSwitch(canvasTemplate([]), 'flow')).toEqual({ carried: 0, lossy: false });
  });
});
