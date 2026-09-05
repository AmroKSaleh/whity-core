import type { DocElement, DocTemplate, TextStyle } from '@amroksaleh/ui/documents/types';
import type { FlowBlock, FlowContent } from '@amroksaleh/ui/documents/flow';

/**
 * Switching a template between CANVAS and FLOW mode (#1186 slice 2).
 *
 * The two bodies live side by side on the template, so a switch does not
 * destroy the one it is leaving — reading order is not the same thing as
 * placement, and a document switched to flow and back should not come home
 * having lost its layout.
 *
 * WHAT EACH DIRECTION COSTS, WHICH IS THE POINT OF THIS FILE
 * ---------------------------------------------------------
 * FLOW -> CANVAS is additive. Blocks are laid out down the page at the
 * template's margin, and from then on they are ordinary elements you may drag
 * anywhere. Nothing is lost; you gain coordinates.
 *
 * CANVAS -> FLOW LOSES POSITION, and cannot get it back. A canvas element knows
 * exactly where it sits; a flow block knows only what comes before it. Reading
 * an (x, y) grid back into one order is a guess — two elements side by side in
 * columns have no true "first" — so the result is an approximation of the
 * author's intent, and the switch has to say so BEFORE it happens rather than
 * leave it to be discovered.
 *
 * That is why {@see describeSwitch} exists separately from the converters: the
 * screen asks what a switch will cost, shows it, and only then performs one.
 * The designer already sets this precedent for deletes (#1166) — an action that
 * destroys something says what, in advance.
 */

/** What is preserved and what is lost, for the confirmation the screen shows. */
export interface SwitchCost {
  /** Blocks or elements that carry over. */
  carried: number;
  /** True when the switch discards information that cannot be recovered. */
  lossy: boolean;
}

export function describeSwitch(template: DocTemplate, to: 'canvas' | 'flow'): SwitchCost {
  if (to === 'flow') {
    const elements = template.pages.flatMap((p) => p.elements);
    return {
      // A blockInstance has no flow equivalent — a saved block is a positioned
      // group, and flattening one into reading order would silently detach it
      // from the library it belongs to. Counted as NOT carried so the number
      // the author is shown is the number that survives.
      carried: elements.filter((e) => convertibleToFlow(e)).length,
      lossy: elements.length > 0,
    };
  }

  return { carried: template.flow?.blocks.length ?? 0, lossy: false };
}

/** Element types with an honest flow equivalent. */
function convertibleToFlow(el: DocElement): boolean {
  return el.type === 'text' || el.type === 'dynamicText' || el.type === 'image';
}

/**
 * CANVAS -> FLOW: read the page top-to-bottom and keep what has a flow meaning.
 *
 * Ordered by vertical position, then horizontal, which is the only ordering a
 * grid of coordinates honestly supports. Elements at the same height are read
 * start-to-end; that is a guess in a two-column layout and the confirmation
 * says so.
 *
 * Types with no flow equivalent are DROPPED rather than approximated. A
 * rectangle is a drawn shape, not a paragraph; turning one into an empty block
 * would put something in the reading order that the author never wrote and the
 * renderer would then print as a gap.
 */
export function canvasToFlow(template: DocTemplate): FlowContent {
  const existing = template.flow;
  const blocks: FlowBlock[] = [];

  for (const page of template.pages) {
    if (blocks.length > 0) blocks.push({ type: 'pageBreak' });

    const ordered = [...page.elements].sort((a, b) => a.y - b.y || a.x - b.x);
    for (const el of ordered) {
      if (el.type === 'text') {
        blocks.push({ type: 'paragraph', text: el.text });
        continue;
      }
      if (el.type === 'dynamicText') {
        // The template string, not an interpolated value: a template is not
        // its sample data, and freezing today's sample into the document would
        // silently convert a placeholder into a literal.
        blocks.push({ type: 'paragraph', text: el.template });
        continue;
      }
      if (el.type === 'image' && el.src.startsWith('data:')) {
        blocks.push({ type: 'figure', dataUri: el.src });
      }
      // A remote image src is dropped: the flow renderer refuses a non-data
      // source, so carrying one over would produce a block that exists and
      // cannot print.
    }
  }

  return { ...existing, blocks };
}

/** Millimetres between stacked blocks when laying flow content onto a page. */
const FLOW_TO_CANVAS_GAP_MM = 4;

/**
 * FLOW -> CANVAS: stack the blocks down the page from the margin.
 *
 * A first layout, not a final one — the point of switching is that from here
 * every element is draggable. Heights are estimates by type, because the real
 * height of a paragraph depends on the font metrics only the renderer has, and
 * an estimate the author can drag is more useful than a precise number they
 * cannot see.
 *
 * Blocks that overflow the page keep going past its bottom edge rather than
 * being clipped or silently dropped. An element off the page is visible in the
 * layers list and draggable back; one that was never created is neither.
 */
export function flowToCanvas(template: DocTemplate, uid: () => string): DocTemplate {
  const content = template.flow;
  if (!content || content.blocks.length === 0) return template;

  const margin = template.page.marginMm;
  const width = template.page.widthMm - margin * 2;
  let y = margin;
  let z = 1;
  const elements: DocElement[] = [];

  for (const block of content.blocks) {
    if (block.type === 'pageBreak' || block.type === 'spacer') {
      y += block.type === 'spacer' ? block.heightMm : FLOW_TO_CANVAS_GAP_MM * 2;
      continue;
    }

    const base = { id: uid(), x: margin, y, w: width, rotation: 0, z: z++ };

    if (block.type === 'heading') {
      const size = [0, 20, 17, 15, 13, 12, 11][block.level] ?? 12;
      const h = size * 0.55;
      elements.push({
        ...base,
        h,
        type: 'text',
        text: block.text,
        style: headingStyle(size),
      } as DocElement);
      y += h + FLOW_TO_CANVAS_GAP_MM;
      continue;
    }

    if (block.type === 'paragraph') {
      // Roughly 90 characters to a line at body size across a full column.
      const lines = Math.max(1, Math.ceil(block.text.length / 90));
      const h = lines * 5;
      elements.push({
        ...base,
        h,
        type: 'text',
        text: block.text,
        style: bodyStyle(),
      } as DocElement);
      y += h + FLOW_TO_CANVAS_GAP_MM;
      continue;
    }

    if (block.type === 'figure') {
      const h = 40;
      elements.push({ ...base, h, type: 'image', src: block.dataUri, binding: undefined, fit: 'contain' } as DocElement);
      y += h + FLOW_TO_CANVAS_GAP_MM;
      continue;
    }

    if (block.type === 'table') {
      // Tables have no canvas element. Rendered as their caption or a summary
      // line so the author can see that something was here and replace it,
      // rather than finding a hole where a table used to be.
      const h = 6;
      elements.push({
        ...base,
        h,
        type: 'text',
        text: block.caption ?? `${block.rows.length} × ${block.columns?.length ?? 0}`,
        style: bodyStyle(),
      } as DocElement);
      y += h + FLOW_TO_CANVAS_GAP_MM;
    }
  }

  return {
    ...template,
    mode: 'canvas',
    pages: [{ id: template.pages[0]?.id ?? uid(), elements }],
  };
}

/**
 * `direction` is left unset on purpose, so the renderer infers it per paragraph
 * from the first strong character. Converted content is very often mixed
 * Arabic and Latin, and stamping a direction here would freeze today's guess
 * into the document.
 */
function headingStyle(fontSize: number): TextStyle {
  return {
    fontSize,
    fontWeight: 'bold',
    fontStyle: 'normal',
    align: 'left',
    vAlign: 'top',
    color: '#111111',
  };
}

function bodyStyle(): TextStyle {
  return {
    fontSize: 11,
    fontWeight: 'normal',
    fontStyle: 'normal',
    align: 'left',
    vAlign: 'top',
    color: '#111111',
  };
}
