import {
  autoLayout,
  effectiveQuorum,
  handleFaces,
  needsAutoLayout,
  renumber,
  resolveTransitions,
  slotAt,
  type RouteFlowGraph,
  type RouteFlowStep,
} from '@amroksaleh/ui/route-flow/model';

/**
 * The route-flow model (#1027) — the transitions an engine will actually take,
 * and the direction the picture runs in.
 *
 * These live here rather than beside the component because `packages/ui` has no
 * Jest project of its own; web's is the only runner in the repo and its
 * `roots`/`moduleNameMapper` resolve `@amroksaleh/ui/*` into this checkout's
 * `packages/ui/src`, so the module under test is the real one.
 *
 * EXPECTED VALUES ARE WRITTEN OUT, NEVER RE-DERIVED
 * -------------------------------------------------
 * Every expectation below is a transition list somebody worked out by hand from
 * the three rules #1014 states, and typed in. Nothing here calls
 * `resolveTransitions` to compute what `resolveTransitions` should return, and
 * nothing re-implements the fallthrough to check the fallthrough — a test that
 * compares a function to a second copy of its own logic passes on the day both
 * copies are wrong, which is the day it was needed.
 *
 * The layout tests are the same idea applied to geometry: they assert the
 * PROPERTIES the direction decision claims ("a vertical flow reads identically
 * in both directions", "a horizontal flow runs in the reading direction") rather
 * than re-multiplying the strides, so they would still fail if the arithmetic
 * were changed to something that happened to be self-consistent and wrong.
 */

function step(position: number, overrides: Partial<RouteFlowStep> = {}): RouteFlowStep {
  return {
    position,
    ruleKind: 'role',
    ruleConfig: { role_id: 1 },
    label: null,
    decision: false,
    decisionQuorum: null,
    canvasX: 0,
    canvasY: 0,
    ...overrides,
  };
}

function graphOf(steps: RouteFlowStep[], edges: RouteFlowGraph['edges'] = []): RouteFlowGraph {
  return { steps, edges, defaultQuorum: 'all' };
}

describe('resolveTransitions', () => {
  it('connects a plain circulation with derived continues and ends at the last step', () => {
    const { transitions, terminals } = resolveTransitions(graphOf([step(1), step(2), step(3)]));

    expect(transitions).toEqual([
      { from: 1, to: 2, on: 'continue', kind: 'derived' },
      { from: 2, to: 3, on: 'continue', kind: 'derived' },
    ]);
    expect(terminals).toEqual([{ from: 3, on: 'continue' }]);
  });

  it('falls an approval through to the next ordinal but NEVER falls a rejection through', () => {
    // The load-bearing rule. #1014: "a rejection that records dissent and lets
    // the document proceed is not approval". So step 2's approval reaches step 3
    // and its rejection reaches nothing at all.
    const { transitions, terminals } = resolveTransitions(
      graphOf([step(1), step(2, { decision: true }), step(3)])
    );

    expect(transitions).toEqual([
      { from: 1, to: 2, on: 'continue', kind: 'derived' },
      { from: 2, to: 3, on: 'approved', kind: 'derived' },
    ]);
    expect(terminals).toContainEqual({ from: 2, on: 'rejected' });
    expect(terminals).toContainEqual({ from: 3, on: 'continue' });
  });

  it('never routes a rejection to where the approval went', () => {
    const { transitions } = resolveTransitions(
      graphOf([step(1, { decision: true }), step(2)])
    );

    // Stated as its own assertion because it is the exact silent failure: an
    // engine (or an editor) that treated "no reject edge" as "same as approve"
    // would produce a 1 -> 2 transition on 'rejected' and every screen would
    // still look normal.
    expect(transitions.filter((t) => t.on === 'rejected')).toEqual([]);
  });

  it('prefers a drawn approve edge over the positional fallthrough', () => {
    const { transitions } = resolveTransitions(
      graphOf(
        [step(1, { decision: true }), step(2), step(3)],
        [{ from: 1, to: 3, verdict: 'approved' }]
      )
    );

    expect(transitions).toContainEqual({ from: 1, to: 3, on: 'approved', kind: 'drawn' });
    expect(transitions).not.toContainEqual({ from: 1, to: 2, on: 'approved', kind: 'derived' });
  });

  it('allows a reject edge pointing backwards — the send-it-back-to-fix design', () => {
    const { transitions, terminals } = resolveTransitions(
      graphOf(
        [step(1), step(2, { decision: true })],
        [{ from: 2, to: 1, verdict: 'rejected' }]
      )
    );

    expect(transitions).toContainEqual({ from: 2, to: 1, on: 'rejected', kind: 'drawn' });
    // Having drawn one, the chain no longer ends on a rejection there.
    expect(terminals).not.toContainEqual({ from: 2, on: 'rejected' });
  });

  it('ends both ways when the last step is a decision nobody drew edges from', () => {
    const { terminals } = resolveTransitions(graphOf([step(1), step(2, { decision: true })]));

    expect(terminals).toEqual([
      { from: 2, on: 'approved' },
      { from: 2, on: 'rejected' },
    ]);
  });

  it('ignores an edge whose endpoint is no longer on the canvas', () => {
    const { transitions } = resolveTransitions(
      graphOf(
        [step(1, { decision: true })],
        [{ from: 1, to: 9, verdict: 'rejected' }]
      )
    );

    expect(transitions).toEqual([]);
  });

  it('reads steps in position order even when the array is not', () => {
    const { transitions } = resolveTransitions(graphOf([step(3), step(1), step(2)]));

    expect(transitions).toEqual([
      { from: 1, to: 2, on: 'continue', kind: 'derived' },
      { from: 2, to: 3, on: 'continue', kind: 'derived' },
    ]);
  });
});

describe('effectiveQuorum', () => {
  it('falls back to the tenant default when the step does not say', () => {
    const graph: RouteFlowGraph = { ...graphOf([step(1, { decision: true })]), defaultQuorum: 'majority' };

    expect(effectiveQuorum(graph.steps[0], graph)).toBe('majority');
  });

  it('prefers the step over the tenant default', () => {
    const graph: RouteFlowGraph = {
      ...graphOf([step(1, { decision: true, decisionQuorum: 'any' })]),
      defaultQuorum: 'all',
    };

    expect(effectiveQuorum(graph.steps[0], graph)).toBe('any');
  });
});

describe('renumber', () => {
  it('closes the gap a delete leaves AND carries the edges with it', () => {
    // Deleting step 2 from 1,2,3 leaves 1,3 -> renumbered to 1,2. The reject edge
    // that pointed at 3 must now point at 2. A renumber that moved the steps and
    // not the edges would silently re-point the reject path at a different stage.
    const after = renumber(
      graphOf([step(1), step(3, { decision: true })], [{ from: 3, to: 1, verdict: 'rejected' }])
    );

    expect(after.steps.map((s) => s.position)).toEqual([1, 2]);
    expect(after.edges).toEqual([{ from: 2, to: 1, verdict: 'rejected' }]);
  });

  it('drops an edge whose endpoint was deleted', () => {
    const after = renumber(graphOf([step(1, { decision: true })], [{ from: 1, to: 7, verdict: 'rejected' }]));

    expect(after.edges).toEqual([]);
  });
});

describe('needsAutoLayout', () => {
  it('is false for a single node left at the origin', () => {
    expect(needsAutoLayout([step(1)])).toBe(false);
  });

  it('is true when every node is stacked on the origin — nothing ever arranged it', () => {
    expect(needsAutoLayout([step(1), step(2)])).toBe(true);
  });

  it('is false as soon as one node has been placed', () => {
    expect(needsAutoLayout([step(1), step(2, { canvasY: 180 })])).toBe(false);
  });
});

describe('autoLayout', () => {
  it('keeps a step reached by the positional fallthrough on the spine', () => {
    // Step 3 is reached by step 2's derived continue as well as by step 1's
    // reject edge, so it is NOT the divergent path. A lane assignment that read
    // only the STORED edges would see "every arriving edge is a rejection" and
    // push it aside — the derived transitions are what make this correct.
    const placed = autoLayout(
      graphOf([step(1, { decision: true }), step(2), step(3)], [{ from: 1, to: 3, verdict: 'rejected' }]),
      'vertical',
      'ltr'
    );

    const lanes = placed.map((s) => s.canvasX);
    expect(new Set(lanes).size).toBe(1);
  });

  it('pushes a step ONLY a rejection can reach into its own lane', () => {
    const placed = autoLayout(
      graphOf(
        [step(1, { decision: true }), step(2), step(3)],
        [
          { from: 1, to: 3, verdict: 'approved' },
          { from: 1, to: 2, verdict: 'rejected' },
        ]
      ),
      'vertical',
      'ltr'
    );

    const byPosition = new Map(placed.map((s) => [s.position, s]));
    expect(byPosition.get(2)?.canvasX).not.toBe(byPosition.get(1)?.canvasX);
    expect(byPosition.get(3)?.canvasX).toBe(byPosition.get(1)?.canvasX);
  });
});

describe('direction', () => {
  it('runs a VERTICAL flow identically in both directions — the reason it is the default', () => {
    // The whole claim behind defaulting to vertical: the axis a reader follows
    // is unaffected by the reading direction, so an Arabic reader and an English
    // reader see the same flow rather than a mirrored one.
    const ltr = [0, 1, 2].map((d) => slotAt(d, 0, 'vertical', 'ltr').canvasY);
    const rtl = [0, 1, 2].map((d) => slotAt(d, 0, 'vertical', 'rtl').canvasY);

    expect(rtl).toEqual(ltr);
    expect(ltr[0]).toBeLessThan(ltr[1]);
    expect(ltr[1]).toBeLessThan(ltr[2]);
  });

  it('mirrors the LANE axis of a vertical flow, because that axis is horizontal', () => {
    // The single rule: a horizontal axis runs in the reading direction. Under a
    // vertical orientation the lanes are the horizontal axis, so in Arabic the
    // first branch sits to the RIGHT of the spine.
    expect(slotAt(0, 1, 'vertical', 'ltr').canvasX).toBeGreaterThan(0);
    expect(slotAt(0, 1, 'vertical', 'rtl').canvasX).toBeLessThan(0);
  });

  it('runs a HORIZONTAL flow right-to-left under RTL', () => {
    const ltr = [0, 1, 2].map((d) => slotAt(d, 0, 'horizontal', 'ltr').canvasX);
    const rtl = [0, 1, 2].map((d) => slotAt(d, 0, 'horizontal', 'rtl').canvasX);

    expect(ltr[0]).toBeLessThan(ltr[1]);
    expect(ltr[1]).toBeLessThan(ltr[2]);
    // Reversed, not merely negated in place: depth advances toward the reader's
    // start, which is the right-hand side.
    expect(rtl[0]).toBeGreaterThan(rtl[1]);
    expect(rtl[1]).toBeGreaterThan(rtl[2]);
  });

  it('swaps the handle faces so an arrow still leaves the face a reader expects', () => {
    // A mirrored layout with unmirrored handles draws every edge back through
    // the middle of its own node — the failure that makes "just set dir=rtl"
    // insufficient for a canvas.
    expect(handleFaces('horizontal', 'ltr')).toEqual({ target: 'left', source: 'right' });
    expect(handleFaces('horizontal', 'rtl')).toEqual({ target: 'right', source: 'left' });
  });

  it('leaves a vertical flow’s handles alone in both directions', () => {
    expect(handleFaces('vertical', 'ltr')).toEqual({ target: 'top', source: 'bottom' });
    expect(handleFaces('vertical', 'rtl')).toEqual({ target: 'top', source: 'bottom' });
  });
});
