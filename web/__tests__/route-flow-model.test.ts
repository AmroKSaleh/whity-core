import {
  ROUTE_FLOW_MAX_NOTES,
  autoLayout,
  effectiveQuorum,
  handleFaces,
  needsAutoLayout,
  notesFor,
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

describe('resolveTransitions — convergence', () => {
  /**
   * When two chains reach the same person at the same stage the engine
   * DE-DUPLICATES them into one inbox item (migration 112's partial unique index
   * over open recipient rows). The stage settles ONCE per cohort, not once per
   * arriving chain — so two arrows entering a box do NOT both carry on through
   * it, which is the opposite of what a graph naturally reads as.
   *
   * A linear composer could never draw two paths meeting, so this never
   * mattered before. A canvas can, so the editor has to name it.
   */

  it('reports a stage that two DRAWN paths reach', () => {
    // approve -> 3 and reject -> 2, and 2 continues -> 3. Stage 3 is reached
    // both by the approval and by the reject path rejoining.
    const { merges } = resolveTransitions(
      graphOf(
        [step(1, { decision: true }), step(2), step(3)],
        [
          { from: 1, to: 3, verdict: 'approved' },
          { from: 1, to: 2, verdict: 'rejected' },
        ]
      )
    );

    expect(merges).toEqual([3]);
  });

  it('reports convergence the author never drew, created by the ordinal fallthrough', () => {
    // The case that makes this a label rather than a validation. Only ONE edge
    // is drawn — 1 rejected -> 3 — but stage 2 also continues into 3 by position,
    // so 3 has two arrivals. An implementation that scanned the STORED edges
    // would see one edge and report no convergence.
    const { merges, transitions } = resolveTransitions(
      graphOf(
        [step(1, { decision: true }), step(2), step(3)],
        [{ from: 1, to: 3, verdict: 'rejected' }]
      )
    );

    expect(transitions.filter((t) => t.to === 3)).toHaveLength(2);
    expect(merges).toEqual([3]);
  });

  it('reports nothing for a plain linear flow', () => {
    expect(resolveTransitions(graphOf([step(1), step(2), step(3)])).merges).toEqual([]);
  });

  it('does not count a stage reached once as a merge', () => {
    const { merges } = resolveTransitions(
      graphOf([step(1), step(2, { decision: true })], [{ from: 2, to: 1, verdict: 'rejected' }])
    );

    // Stage 1 is reached only by the reject edge; stage 2 only by the continue.
    expect(merges).toEqual([]);
  });

  it('lists several converging stages in position order', () => {
    const { merges } = resolveTransitions(
      graphOf(
        [step(1, { decision: true }), step(2, { decision: true }), step(3), step(4)],
        [
          { from: 1, to: 3, verdict: 'rejected' },
          { from: 2, to: 4, verdict: 'rejected' },
        ]
      )
    );

    expect(merges).toEqual([3, 4]);
  });
});

describe('resolveTransitions — loops', () => {
  /**
   * A reject edge pointing BACK ("to the author, to fix") is the most common real
   * approval design and the engine supports it fully — `nextForVerdict()` resolves
   * by id with no position comparison, so backwards and forwards are the same code
   * path. It must never be refused.
   *
   * What the engine does not do is count laps (#1037), so a document on its ninth
   * rejection looks identical to one on its first. This editor is the first
   * surface where somebody DRAWS the loop, so it is the first that can say one
   * exists at the moment it is created.
   */

  it('marks every stage on a rework loop, including the ones the fallthrough closes', () => {
    // Reject from 3 back to 1. The loop closes through the POSITIONAL
    // fallthrough: 1 -> 2 -> 3 are derived continues nobody drew. A scan of
    // stored edges would see one edge and no cycle at all.
    const { cycles } = resolveTransitions(
      graphOf(
        [step(1), step(2), step(3, { decision: true })],
        [{ from: 3, to: 1, verdict: 'rejected' }]
      )
    );

    expect(cycles).toEqual([1, 2, 3]);
  });

  it('reports no loop for a plain linear flow', () => {
    expect(resolveTransitions(graphOf([step(1), step(2), step(3)])).cycles).toEqual([]);
  });

  it('reports no loop for a forward-only reject path', () => {
    // 1 approves onward to 3, rejects to 2, and 2 continues to 3. Every arrow
    // points forward, so nothing comes back round.
    const { cycles } = resolveTransitions(
      graphOf(
        [step(1, { decision: true }), step(2), step(3)],
        [
          { from: 1, to: 3, verdict: 'approved' },
          { from: 1, to: 2, verdict: 'rejected' },
        ]
      )
    );

    expect(cycles).toEqual([]);
  });

  it('marks only the stages actually inside the loop', () => {
    // 1 -> 2 -> 3, 3 rejects back to 2. Stage 1 leads into the loop but nothing
    // returns to it, so it is not on it.
    const { cycles } = resolveTransitions(
      graphOf(
        [step(1), step(2), step(3, { decision: true })],
        [{ from: 3, to: 2, verdict: 'rejected' }]
      )
    );

    expect(cycles).toEqual([2, 3]);
  });
});

/**
 * The notes a card draws, and the bound the card's HEIGHT is budgeted against.
 *
 * This block exists because of a defect that a type check and 1750 unit tests
 * could not see (#1042): a stage could carry three notes and the card clamped at
 * two, so "Rejected: Ends here" was dropped from a node that had visible empty
 * space beneath it. The canvas went quiet about rejection ending the chain, in
 * the most ordinary approval shape there is.
 *
 * The height is now derived from ROUTE_FLOW_MAX_NOTES and so is the clamp, so
 * they cannot drift apart again — but that only helps while the constant is
 * TRUE. These assert it against the graphs that produce the most notes, so
 * making a fourth reachable fails here rather than on a canvas nobody rendered.
 */
describe('notesFor', () => {
  it('gives a merging, looping stage that ends on rejection all three of its notes', () => {
    // The exact shape from the bug report: 3 is a merge target (1 approves into
    // it AND 2 falls through into it), is reachable from itself (3 → 4 → 3), and
    // is a decision with no reject edge, so a rejection ends there.
    const graph = graphOf(
      [
        step(1, { decision: true }),
        step(2),
        step(3, { decision: true }),
        step(4, { decision: true }),
      ],
      [
        { from: 1, to: 3, verdict: 'approved' },
        { from: 4, to: 3, verdict: 'rejected' },
      ]
    );

    expect(notesFor(3, resolveTransitions(graph))).toEqual([
      { kind: 'merge' },
      { kind: 'cycle' },
      { kind: 'terminal', on: 'rejected' },
    ]);
  });

  it('gives a merging final decision both of its terminals, and no loop note', () => {
    // The other three-note shape, and the reason three is the ceiling: a stage
    // where BOTH verdicts end has no outgoing transition, so nothing can lead
    // back to it and the loop note is unreachable by construction.
    const graph = graphOf(
      [step(1, { decision: true }), step(2), step(3, { decision: true })],
      [{ from: 1, to: 3, verdict: 'approved' }]
    );

    expect(notesFor(3, resolveTransitions(graph))).toEqual([
      { kind: 'merge' },
      { kind: 'terminal', on: 'approved' },
      { kind: 'terminal', on: 'rejected' },
    ]);
  });

  it('never exceeds the number of note lines the card is built to hold', () => {
    // Enumerated rather than argued: every three-stage graph this editor can
    // produce, over every combination of decision flags and of the verdict
    // edges those flags permit — the shape space where merge, loop and terminal
    // can coincide. If some future note makes a fourth reachable, one of these
    // finds it.
    const positions = [1, 2, 3];
    let checked = 0;
    let worst = 0;
    let worstCase = '';

    for (let flags = 0; flags < 2 ** positions.length; flags++) {
      const steps = positions.map((p) =>
        step(p, { decision: Math.floor(flags / 2 ** (p - 1)) % 2 === 1 })
      );
      const decisions = steps.filter((s) => s.decision).map((s) => s.position);

      // Every destination (including "no edge") for every verdict of every
      // decision stage.
      let choices: RouteFlowGraph['edges'][] = [[]];
      for (const from of decisions) {
        for (const verdict of ['approved', 'rejected'] as const) {
          const grown: RouteFlowGraph['edges'][] = [];
          for (const soFar of choices) {
            grown.push(soFar);
            for (const to of positions) {
              if (to !== from) grown.push([...soFar, { from, to, verdict }]);
            }
          }
          choices = grown;
        }
      }

      for (const edges of choices) {
        const resolution = resolveTransitions(graphOf(steps, edges));
        for (const p of positions) {
          const count = notesFor(p, resolution).length;
          checked++;
          if (count > worst) {
            worst = count;
            worstCase = `stage ${p} of ${JSON.stringify({ decisions, edges })}`;
          }
        }
      }
    }

    // Asserted once, with the graph that produced the maximum, rather than once
    // per stage: tens of thousands of assertions would be slow and would say
    // less when one failed.
    expect(`${worst} (${worstCase})`).toBe(`${ROUTE_FLOW_MAX_NOTES} (${worstCase})`);
    // The bound must be TIGHT as well as true. A ceiling nothing reaches would
    // buy height the card never uses and would pass while the real maximum had
    // moved underneath it.
    expect(worst).toBe(ROUTE_FLOW_MAX_NOTES);
    expect(checked).toBeGreaterThan(1000);
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
