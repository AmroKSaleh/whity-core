/**
 * Geometry helpers for the document designer canvas.
 *
 * `snapMove` computes alignment snapping while an element is dragged: it nudges
 * the moving element so one of its edges/centre lines up with the page's
 * edges/centre or with another element's edges/centre, and reports the guide
 * lines to draw. Pure and deterministic so it can be unit-tested without a DOM.
 */

export interface Box {
  x: number;
  y: number;
  w: number;
  h: number;
}

export interface SnapResult {
  /** Snapped top-left position, in millimetres. */
  x: number;
  y: number;
  /** Vertical guide lines to draw (x positions, mm). */
  vGuides: number[];
  /** Horizontal guide lines to draw (y positions, mm). */
  hGuides: number[];
}

/** The three snap anchors along one axis: near edge, centre, far edge. */
function anchors(pos: number, size: number): [number, number, number] {
  return [pos, pos + size / 2, pos + size];
}

/**
 * Snap `moving` against the page box and `others`, within `threshold` mm.
 * Returns the adjusted position and the guide lines that ended up aligned.
 */
export function snapMove(
  moving: Box,
  others: Box[],
  page: { widthMm: number; heightMm: number },
  threshold: number
): SnapResult {
  // Candidate target lines on each axis: page near/centre/far + every other
  // element's near/centre/far.
  const xTargets = [0, page.widthMm / 2, page.widthMm];
  const yTargets = [0, page.heightMm / 2, page.heightMm];
  for (const o of others) {
    xTargets.push(...anchors(o.x, o.w));
    yTargets.push(...anchors(o.y, o.h));
  }

  const solve = (pos: number, size: number, targets: number[]): { pos: number; guides: number[] } => {
    const a = anchors(pos, size);
    // Best (smallest) delta that brings any anchor onto any target within threshold.
    let best: number | null = null;
    for (const anchor of a) {
      for (const t of targets) {
        const delta = t - anchor;
        if (Math.abs(delta) <= threshold && (best === null || Math.abs(delta) < Math.abs(best))) {
          best = delta;
        }
      }
    }
    if (best === null) return { pos, guides: [] };
    const snapped = pos + best;
    const snappedAnchors = anchors(snapped, size);
    // Guides = every target an anchor now coincides with (dedup, small epsilon).
    const guides = new Set<number>();
    for (const anchor of snappedAnchors) {
      for (const t of targets) {
        if (Math.abs(anchor - t) < 0.01) guides.add(t);
      }
    }
    return { pos: snapped, guides: [...guides] };
  };

  const sx = solve(moving.x, moving.w, xTargets);
  const sy = solve(moving.y, moving.h, yTargets);
  return { x: sx.pos, y: sy.pos, vGuides: sx.guides, hGuides: sy.guides };
}
