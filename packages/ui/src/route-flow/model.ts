/**
 * The model behind the route-flow editor (#1027) — everything about a flow that
 * is a FACT rather than a pixel.
 *
 * Deliberately a separate module from the canvas, and free of React and of
 * `@xyflow/react`. What a graph MEANS — which transitions exist, which of them
 * the author drew and which the engine will take anyway, where a chain ends — is
 * contract behaviour, and contract behaviour that lives inside a component can
 * only be tested by rendering one. The same split `flow-model.ts` /
 * `flow-canvas.tsx` already makes for the read-only `flow` block.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE ONE IDEA: A NODE IS A TYPE, NEVER AN ENUMERATION
 * ─────────────────────────────────────────────────────────────────────────
 * A step carries a `ruleKind` and a `ruleConfig` and NOTHING ELSE about who it
 * reaches. "Everyone holding Instructor" is ONE node and stays one node whether
 * it resolves to four people or four thousand — there is no field on
 * {@link RouteFlowStep} that could hold a roster, and nothing in this module
 * expands one.
 *
 * How big a node is, is a live fact about the organisation and therefore not
 * part of the model at all. The editor renders it from {@link RouteFlowAudience}
 * that a HOST supplies, because resolving a rule needs the server and a kit
 * component must not fetch. A count that went stale in a model would be worse
 * than no count.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DERIVED EDGES: WHY THE PICTURE CANNOT DISAGREE WITH THE ENGINE
 * ─────────────────────────────────────────────────────────────────────────
 * Only two kinds of edge are ever STORED — `approved` and `rejected` — and the
 * engine's rule for everything else is positional (#1014):
 *
 *   - a step that is not a decision continues to the NEXT AUTHORING ORDINAL;
 *   - a decision step's approval takes its `approved` edge if one was drawn, and
 *     otherwise falls through to the next authoring ordinal;
 *   - a decision step's rejection takes its `rejected` edge if one was drawn,
 *     and otherwise ENDS THE CHAIN. It never falls through to where an approval
 *     would have gone.
 *
 * {@link resolveTransitions} applies exactly those three rules and returns every
 * transition the engine will actually take, each tagged with whether it was
 * DRAWN or DERIVED. The canvas renders that list and nothing else.
 *
 * That is the whole reason this function exists. An editor that drew only the
 * stored edges would show a linear approval route as a set of disconnected boxes
 * — every arrow in it is implicit — and an author would "fix" it by drawing
 * edges that changed nothing. An editor that drew arrows by its own separate
 * guess would eventually guess differently from the engine, and the picture
 * would be a confident lie. One derivation, rendered.
 */

/** The verdicts a stored edge may be keyed by. Mirrors core's `RouteVerdict`. */
export type RouteFlowVerdict = 'approved' | 'rejected';

/** What "this node approved" means. Mirrors core's `RouteQuorum`. */
export type RouteFlowQuorum = 'all' | 'any' | 'majority';

/**
 * One stage of a flow.
 *
 * `position` is the identity on the wire and within this module. Database ids
 * are deliberately absent: a graph save REPLACES rather than diffs, so ids churn
 * and a client holding one across a save would be holding a stale number.
 */
export interface RouteFlowStep {
  /** 1-based authoring ordinal. Unique within a flow, and its handle. */
  position: number;
  /** The RULE this stage reaches — never a person. */
  ruleKind: string;
  /** The rule's own configuration. Opaque here; only the server validates it. */
  ruleConfig: Record<string, unknown>;
  /** An author's name for the stage, or null to fall back to the rule. */
  label: string | null;
  /** Whether this stage is a GATE that demands a verdict. */
  decision: boolean;
  /**
   * What "this node approved" means here.
   *
   * `null` is NOT "no quorum" — it means "follow the tenant setting", which is
   * why {@link RouteFlowGraph.defaultQuorum} exists and why the editor draws the
   * effective value rather than a blank.
   */
  decisionQuorum: RouteFlowQuorum | null;
  canvasX: number;
  canvasY: number;
}

/** One STORED transition, keyed by the verdict that takes it. */
export interface RouteFlowEdge {
  /** The `position` this edge leaves. Always a decision step. */
  from: number;
  /** The `position` this edge arrives at. */
  to: number;
  verdict: RouteFlowVerdict;
}

/** A whole flow, as the editor holds it. */
export interface RouteFlowGraph {
  steps: RouteFlowStep[];
  edges: RouteFlowEdge[];
  /** What a step with a null `decisionQuorum` will actually do. */
  defaultQuorum: RouteFlowQuorum;
}

/**
 * How many people a step's rule currently reaches, as a HOST resolved it.
 *
 * Supplied to the editor rather than fetched by it: resolving a rule is a server
 * question, and a kit component that fetched would need a base URL, an auth
 * scheme and a permission model that belong to whichever app is embedding it.
 *
 * `count` is null when the host could not resolve it — most often because the
 * viewer does not hold the permission the preview needs. The editor then renders
 * the node WITHOUT a number and says why, rather than showing a zero that would
 * read as "this reaches nobody".
 */
export interface RouteFlowAudience {
  count: number | null;
  /** Why the count is missing, shown verbatim when `count` is null. */
  unavailableReason?: string;
}

/**
 * A transition the engine will actually take, ready to draw.
 *
 * `kind` is what separates an arrow an author DREW from one the engine will take
 * regardless. The editor styles them differently and lets only the first be
 * deleted, because "delete" on a derived arrow could only mean deleting the step
 * ordering that produced it.
 */
export interface RouteFlowTransition {
  from: number;
  to: number;
  /**
   * The verdict that takes it, or `continue` for the unconditional advance out
   * of a step that asks for no verdict.
   */
  on: RouteFlowVerdict | 'continue';
  kind: 'drawn' | 'derived';
}

/**
 * A place a chain STOPS, which is a fact worth drawing.
 *
 * A rejection with no `rejected` edge ends the flow, and so does the last step's
 * approval. Both are legitimate designs and both are invisible on a canvas that
 * draws only arrows — an author cannot tell "I have not drawn that yet" from
 * "this is where it ends", and those are very different documents.
 */
export interface RouteFlowTerminal {
  from: number;
  on: RouteFlowVerdict | 'continue';
}

export interface RouteFlowResolution {
  transitions: RouteFlowTransition[];
  terminals: RouteFlowTerminal[];
  /**
   * Positions that MORE THAN ONE transition arrives at.
   *
   * WHY THIS IS A FACT THE EDITOR HAS TO STATE OUT LOUD
   * ---------------------------------------------------
   * When two chains reach the same person at the same step, the engine
   * DE-DUPLICATES them into one inbox item — migration 112's partial unique
   * index over the open recipient rows. The second arrival opens no cohort and
   * gets no continuation of its own: the stage settles ONCE per cohort, not once
   * per chain that reached it.
   *
   * That is pre-existing fan-out behaviour, not something verdicts introduced.
   * It never mattered before because a LINEAR composer cannot draw two paths
   * meeting. A canvas can, and will, the first time anybody uses one — and the
   * natural reading of two arrows entering a box is that both carry on through
   * it. They do not. One continuation happens, and which chain "owns" it is not
   * something the author chose or can choose.
   *
   * REFUSING CONVERGENCE WAS NOT AVAILABLE, WHICH IS WHY THIS IS A LABEL RATHER
   * THAN A VALIDATION. Convergence is not something this editor PERMITS — the
   * engine does it regardless. The positional fallthrough alone produces
   * arrivals nobody drew: step N-1 continuing into step N IS an arrival, so any
   * explicit edge that also targets N converges with it. Refusing convergence
   * would mean refusing the ordinal fallthrough, which is the engine's own
   * documented behaviour and the thing that lets a plain linear route work with
   * no graph authored at all. The drawing cannot prevent the merge; it can only
   * avoid lying about it.
   *
   * Converging designs are also perfectly ordinary and worth keeping: "approve →
   * archive" and "reject → fix → archive" both ending at one archive stage is a
   * flow somebody obviously means to author.
   */
  merges: number[];
}

/**
 * Steps in authoring order.
 *
 * Sorted defensively rather than trusting the caller: the server returns them
 * ordered, but the editor mutates this array locally on every drag and add, and
 * a fallthrough computed over a locally-reordered list would be wrong in exactly
 * the situation the author is watching.
 */
export function stepsInOrder(steps: readonly RouteFlowStep[]): RouteFlowStep[] {
  return [...steps].sort((a, b) => a.position - b.position);
}

/**
 * Every transition the engine will take, and every place a chain ends.
 *
 * See the module docblock for the three rules this applies. They are #1014's,
 * not this module's, and they are written here once so the canvas has nothing to
 * decide.
 */
export function resolveTransitions(graph: RouteFlowGraph): RouteFlowResolution {
  const ordered = stepsInOrder(graph.steps);
  const transitions: RouteFlowTransition[] = [];
  const terminals: RouteFlowTerminal[] = [];

  // Only edges whose BOTH ends are still on the canvas. A step deleted locally
  // takes its edges with it server-side (they cascade), and drawing an arrow to
  // a node that is no longer there would be the editor showing state that cannot
  // be saved.
  const present = new Set(ordered.map((s) => s.position));
  const drawn = graph.edges.filter((e) => present.has(e.from) && present.has(e.to));

  const edgeFor = (from: number, verdict: RouteFlowVerdict): RouteFlowEdge | undefined =>
    drawn.find((e) => e.from === from && e.verdict === verdict);

  ordered.forEach((step, index) => {
    const next = ordered[index + 1];

    if (!step.decision) {
      // A step that asks for no verdict advances unconditionally. It has no
      // stored edge and cannot have one — the server refuses an edge leaving a
      // non-decision step, because such an edge could never fire.
      if (next !== undefined) {
        transitions.push({ from: step.position, to: next.position, on: 'continue', kind: 'derived' });
      } else {
        terminals.push({ from: step.position, on: 'continue' });
      }
      return;
    }

    const approved = edgeFor(step.position, 'approved');
    if (approved !== undefined) {
      transitions.push({ from: approved.from, to: approved.to, on: 'approved', kind: 'drawn' });
    } else if (next !== undefined) {
      transitions.push({ from: step.position, to: next.position, on: 'approved', kind: 'derived' });
    } else {
      terminals.push({ from: step.position, on: 'approved' });
    }

    const rejected = edgeFor(step.position, 'rejected');
    if (rejected !== undefined) {
      transitions.push({ from: rejected.from, to: rejected.to, on: 'rejected', kind: 'drawn' });
    } else {
      // NO FALLTHROUGH. A rejection with nowhere to go ends the chain; it never
      // continues to where an approval would have gone. #1014 records that
      // fallback as the precise failure it is written against — a rejection that
      // records dissent and lets the document proceed is not approval, and it
      // fails silently because every screen still shows a document moving
      // normally.
      terminals.push({ from: step.position, on: 'rejected' });
    }
  });

  // Computed from the SAME transition list the canvas draws, so a node marked as
  // merging is one the picture actually shows two arrows entering. Deriving it
  // from the STORED edges instead would miss every convergence that involves the
  // positional fallthrough — which is most of them.
  const arrivals = new Map<number, number>();
  for (const transition of transitions) {
    arrivals.set(transition.to, (arrivals.get(transition.to) ?? 0) + 1);
  }
  const merges = [...arrivals.entries()]
    .filter(([, count]) => count > 1)
    .map(([position]) => position)
    .sort((a, b) => a - b);

  return { transitions, terminals, merges };
}

/**
 * The effective quorum for a step: its own, or the tenant's.
 *
 * One function so the node card, the inspector and any future summary cannot
 * each decide differently what a null means.
 */
export function effectiveQuorum(step: RouteFlowStep, graph: RouteFlowGraph): RouteFlowQuorum {
  return step.decisionQuorum ?? graph.defaultQuorum;
}

// ─────────────────────────────────────────────────────────────────────────────
// LAYOUT, AND THE DIRECTION DECISION
// ─────────────────────────────────────────────────────────────────────────────

export type RouteFlowOrientation = 'vertical' | 'horizontal';
export type RouteFlowDirection = 'ltr' | 'rtl';

/**
 * Node geometry. Exported so the canvas and the layout agree on one set of
 * numbers instead of each carrying its own copy.
 */
export const ROUTE_FLOW_NODE_WIDTH = 224;
/**
 * Tall enough for the two note lines a stage can carry at once — "Paths merge
 * here" and "Rejected: Ends here" are both facts about the same node, and a
 * card that clipped one would hide exactly the semantics the notes exist to
 * state. The layout strides are derived from this constant, so spacing follows.
 */
export const ROUTE_FLOW_NODE_HEIGHT = 124;
const GAP_ALONG = 72;
const GAP_ACROSS = 40;

/**
 * The readability ceiling on how many nodes a canvas will draw.
 *
 * Mirrors `BlockContract::FLOW_MAX_NODES`, the ceiling #950 already declared for
 * the read-only `flow` block, rather than inventing a second number for the same
 * question. It is a DEFAULT and not a rule: the host passes its own, because the
 * authoritative limit on how many steps a route may declare is the tenant's
 * `documents.routing_max_steps` and only the server knows it.
 */
export const ROUTE_FLOW_MAX_NODES = 150;

/**
 * Whether a graph has never been arranged by a person.
 *
 * Every step sitting at exactly (0, 0) means the rows were written by something
 * that had no canvas — the API, a seed, an import — and drawing them would stack
 * every node on one square. One step at the origin is NOT that: it is a single
 * node somebody left where it started.
 *
 * The test is deliberately narrow. An author's arrangement is a shared design and
 * re-laying it out because a heuristic preferred a different shape would discard
 * meaning they encoded in the positions.
 */
export function needsAutoLayout(steps: readonly RouteFlowStep[]): boolean {
  return steps.length > 1 && steps.every((s) => s.canvasX === 0 && s.canvasY === 0);
}

/**
 * Place every step on a tidy default grid.
 *
 * Used when {@link needsAutoLayout} is true, and for one node at a time as an
 * author adds them. Not a general graph layout: the spine is authoring order,
 * and the only thing that leaves it is a step reachable ONLY as a rejection
 * target, which is pushed into the next lane so the divergent path reads as
 * divergent. That is the shape this feature is for — approval with a reject
 * path — and a force-directed layout would scramble the one thing worth seeing.
 */
export function autoLayout(
  graph: RouteFlowGraph,
  orientation: RouteFlowOrientation,
  direction: RouteFlowDirection
): RouteFlowStep[] {
  const ordered = stepsInOrder(graph.steps);

  // Reachability is read off {@link resolveTransitions} rather than off the
  // stored edges, and that distinction is the whole correctness of this lane
  // assignment. A step is on the SPINE when something continues or approves into
  // it — including the DERIVED fallthrough from the step above, which is how a
  // linear route is connected at all and which a scan of stored edges would not
  // see. A step only a rejection can reach is the divergent path, and gets its
  // own lane so that it reads as divergent.
  const { transitions } = resolveTransitions(graph);

  const rejectOnly = new Set<number>();
  for (const step of ordered) {
    const arrivals = transitions.filter((t) => t.to === step.position);
    if (arrivals.length > 0 && arrivals.every((t) => t.on === 'rejected')) {
      rejectOnly.add(step.position);
    }
  }

  return ordered.map((step, index) => ({
    ...step,
    ...slotAt(index, rejectOnly.has(step.position) ? 1 : 0, orientation, direction),
  }));
}

/**
 * Where the NEXT node an author adds should go: one slot past the last one.
 */
export function nextSlot(
  graph: RouteFlowGraph,
  orientation: RouteFlowOrientation,
  direction: RouteFlowDirection
): { canvasX: number; canvasY: number } {
  return slotAt(graph.steps.length, 0, orientation, direction);
}

/**
 * Put one step at (depth, lane), mirroring every HORIZONTAL axis under RTL.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE RTL DECISION, AND WHY IT IS NOT "SET dir AND HOPE"
 * ─────────────────────────────────────────────────────────────────────────
 * A graph is not text. `dir="rtl"` mirrors text and flexbox; it does nothing to
 * absolute coordinates on a canvas, so a left-to-right flow stays left-to-right
 * in Arabic while every label beside it flips — which reads as a diagram running
 * BACKWARDS, and an arrowhead pointing the wrong way is worse than no arrowhead.
 *
 * Two deliberate choices follow.
 *
 * FIRST, THE DEFAULT ORIENTATION IS VERTICAL. A top-to-bottom flow is
 * direction-NEUTRAL: it reads identically in Arabic and in English, needs no
 * mirroring, and cannot be got wrong by a host that forgets to pass a direction.
 * It is also the better metaphor for the thing being drawn — a document travels
 * DOWN a chain of custody — and it is the same choice the OU tree already makes
 * for the same reason. Making the safe reading the default is worth more than
 * making the familiar one the default.
 *
 * SECOND, WHEN AN AUTHOR CHOOSES HORIZONTAL, EVERY HORIZONTAL AXIS MIRRORS. Not
 * just the flow axis: under RTL, depth advances right-to-left AND lanes spread
 * right-to-left, because in a right-to-left reading the first of several
 * branches is the rightmost. One rule — "a horizontal axis runs in the reading
 * direction" — applied to whichever axis happens to be horizontal, rather than
 * two rules that could be applied inconsistently. The canvas swaps its handles
 * to match, so an arrow still leaves the face a reader expects it to leave.
 *
 * Under the vertical default the across-axis is horizontal, so lanes mirror
 * there too and the depth axis does not — which is the same one rule, and why it
 * is expressed as a rule instead of as two cases.
 */
export function slotAt(
  depth: number,
  lane: number,
  orientation: RouteFlowOrientation,
  direction: RouteFlowDirection
): { canvasX: number; canvasY: number } {
  const horizontal = orientation === 'horizontal';
  const alongStride = (horizontal ? ROUTE_FLOW_NODE_WIDTH : ROUTE_FLOW_NODE_HEIGHT) + GAP_ALONG;
  const acrossStride = (horizontal ? ROUTE_FLOW_NODE_HEIGHT : ROUTE_FLOW_NODE_WIDTH) + GAP_ACROSS;

  const along = depth * alongStride;
  const across = lane * acrossStride;

  // The single rule: a horizontal axis runs in the reading direction.
  const mirror = direction === 'rtl' ? -1 : 1;

  return horizontal
    ? { canvasX: along * mirror, canvasY: across }
    : { canvasX: across * mirror, canvasY: along };
}

/**
 * Which faces edges leave and arrive on, for the current orientation and
 * direction.
 *
 * Returned as plain strings so this module stays free of `@xyflow/react`; the
 * canvas maps them onto its own `Position` enum. Kept HERE rather than in the
 * canvas so the handles cannot drift from the coordinates — a mirrored layout
 * with unmirrored handles draws every arrow through the middle of its own node.
 */
export function handleFaces(
  orientation: RouteFlowOrientation,
  direction: RouteFlowDirection
): { source: 'top' | 'bottom' | 'left' | 'right'; target: 'top' | 'bottom' | 'left' | 'right' } {
  if (orientation === 'vertical') {
    return { target: 'top', source: 'bottom' };
  }

  return direction === 'rtl' ? { target: 'right', source: 'left' } : { target: 'left', source: 'right' };
}

/**
 * Renumber steps to a contiguous 1..N in their current order.
 *
 * Called after a delete, and it rewrites the edges to match in the same pass.
 * Doing the two together is the point: positions are what edges NAME, so a
 * renumber that did not carry the edges would silently re-point every branch at
 * whichever step inherited the number — an approval route whose reject path
 * quietly moved to a different stage, with nothing on screen to show it.
 *
 * Contiguity is not required by the schema (the unique constraint is on
 * `(template_id, position)`, not on the sequence), and it is done anyway because
 * `position` is also the FALLTHROUGH ORDER: after deleting step 3, "the next
 * ordinal" from step 2 must be the step a reader now sees below it.
 */
export function renumber(graph: RouteFlowGraph): RouteFlowGraph {
  const ordered = stepsInOrder(graph.steps);

  const remap = new Map<number, number>();
  ordered.forEach((step, index) => remap.set(step.position, index + 1));

  return {
    ...graph,
    steps: ordered.map((step) => ({ ...step, position: remap.get(step.position) ?? step.position })),
    edges: graph.edges
      .filter((e) => remap.has(e.from) && remap.has(e.to))
      .map((e) => ({
        ...e,
        from: remap.get(e.from) as number,
        to: remap.get(e.to) as number,
      })),
  };
}
