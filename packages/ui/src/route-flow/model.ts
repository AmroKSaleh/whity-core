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
   * "ONCE PER COHORT" IS NOT "ONCE", AND THE DIFFERENCE IS WHY THE CARD NOTE
   * STATES THE MECHANISM RATHER THAN AN OUTCOME (#1058). A second arrival is
   * absorbed only where it reaches people who still hold an OPEN item here, so
   * it opens a cohort of its own — and settles the stage again, and continues
   * again — in two ordinary situations the canvas can see neither of:
   *
   *  - the arrivals reach DIFFERENT people, which is what an actor-relative rule
   *    such as `role_below_actor` produces, since each chain resolves it from a
   *    different actor; and
   *  - the arrivals reach the SAME people but do not overlap in TIME, because
   *    the index is partial over `closed_by_event_id IS NULL`. A fan-out where
   *    one recipient approves straight into the merge and another rejects the
   *    long way round can close the first cohort before the second gets there.
   *
   * Both are facts about a document in flight, not about a design, which is the
   * whole reason the note names the de-duplication rule and leaves the outcome
   * to be worked out from it. The host's inspector states the two cases in
   * prose, where there is room to hedge them accurately.
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
  /**
   * Positions that can be reached again from themselves.
   *
   * A rejection that sends a document BACK — "to the author, to fix" — is the
   * most common real approval design, is fully supported by the engine
   * (`nextForVerdict()` resolves by id with no position comparison anywhere, so
   * backwards and forwards are literally the same code path), and produces a
   * cycle. It is authored deliberately and must not be refused.
   *
   * What the engine does NOT do is count laps (#1037). One traversal per act, so
   * no request can spin — the loop is human-driven, exactly as `returned` has
   * been since #989. The cost is INVISIBILITY rather than runaway: a document on
   * its ninth rejection looks identical to one on its first, because there is one
   * inbox item and a trail nobody reads to the end.
   *
   * This editor is the first surface where somebody DRAWS that loop, so it is the
   * first place that can say a loop exists at the moment it is created. That is
   * all this does — it marks the design, not any document's laps, which the
   * canvas cannot see and should not pretend to.
   */
  cycles: number[];
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

  // A position is on a cycle when it can reach ITSELF by following transitions.
  // Walked over the derived list for the same reason `merges` is: a loop closed
  // through the positional fallthrough (reject back to 1, then 1..N forward
  // again) involves edges nobody drew, and a scan of stored edges would not see
  // it — which is the ordinary shape of a rework loop, not an exotic one.
  const outgoing = new Map<number, number[]>();
  for (const transition of transitions) {
    outgoing.set(transition.from, [...(outgoing.get(transition.from) ?? []), transition.to]);
  }

  const reachesItself = (origin: number): boolean => {
    const seen = new Set<number>();
    const stack = [...(outgoing.get(origin) ?? [])];
    while (stack.length > 0) {
      const at = stack.pop() as number;
      if (at === origin) {
        return true;
      }
      if (seen.has(at)) {
        continue;
      }
      seen.add(at);
      stack.push(...(outgoing.get(at) ?? []));
    }

    return false;
  };

  const cycles = ordered
    .map((s) => s.position)
    .filter(reachesItself);

  return { transitions, terminals, merges, cycles };
}

/**
 * One semantic note on a stage: a fact about the DESIGN that the arrows alone
 * do not say.
 *
 * Kinds, not sentences, because every string a person reads is a label the host
 * supplies; the canvas maps these onto them.
 */
export type RouteFlowNote =
  | { kind: 'merge' }
  | { kind: 'cycle' }
  | { kind: 'terminal'; on: RouteFlowVerdict | 'continue' };

/**
 * Every note one stage carries, in the order they are read.
 *
 * Lives here rather than inside the canvas's render pass for two reasons. The
 * first is that it can then be TESTED — the clipping bug in #1042 was a claim
 * about how many notes exist meeting a clamp that assumed fewer, and neither
 * side of that was reachable from a test while the note list was assembled
 * inline in an effect. The second is {@link ROUTE_FLOW_MAX_NOTES}: a bound on
 * this function's output is what the card's height is budgeted against, so the
 * two have to be able to see each other.
 *
 * The merge note comes FIRST: it is the more surprising of the two markers, and
 * the one a reader is most likely to have assumed the opposite of.
 */
export function notesFor(position: number, resolution: RouteFlowResolution): RouteFlowNote[] {
  const notes: RouteFlowNote[] = [];

  if (resolution.merges.includes(position)) {
    notes.push({ kind: 'merge' });
  }
  if (resolution.cycles.includes(position)) {
    notes.push({ kind: 'cycle' });
  }
  for (const terminal of resolution.terminals) {
    if (terminal.from === position) {
      notes.push({ kind: 'terminal', on: terminal.on });
    }
  }

  return notes;
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
 * Every text row a card draws, and the line box each one occupies.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY THE HEIGHT IS A SUM AND NOT A NUMBER
 * ─────────────────────────────────────────────────────────────────────────
 * It used to be a literal (`124`), and the note clamp on the card was a
 * separate literal (`line-clamp-2`). Nothing tied them together, so they
 * drifted: the height was raised to hold three note lines and the clamp stayed
 * at two, and a stage carrying merge + loop + "Rejected: Ends here" silently
 * dropped the third — the canvas going quiet about the fact that rejection ends
 * the chain, in a card with visible empty space underneath (#1042).
 *
 * So the height is now DERIVED from the rows, and the clamp is derived from
 * {@link ROUTE_FLOW_MAX_NOTES}, which is the same number the height budget uses.
 * Adding a row, or making a fourth note reachable, moves both at once or moves
 * neither; it can no longer move one.
 *
 * The values are the rendered line boxes of the classes the card uses, measured
 * in a browser rather than guessed: `text-sm` (20), `text-xs` (16) and
 * `text-[10px] leading-tight` (12.6, rounded up to 13).
 */
const NODE_ROW = {
  /** The stage's title, `text-sm`. */
  title: 20,
  /** The rule the stage names, `text-xs` — always visible, never only the label. */
  rule: 16,
  /** How many people the rule reaches, `text-xs`. */
  audience: 16,
  /** What counts as approved, `text-xs`. Its own row, so a long audience line cannot truncate it. */
  quorum: 16,
  /** One semantic note, `text-[10px] leading-tight`. */
  note: 13,
} as const;

/** `px-3 py-2` top + bottom. */
const NODE_PADDING_Y = 8 * 2;
/** The 1px border, top + bottom. */
const NODE_BORDER_Y = 1 * 2;
/** `gap-1` between the four flex children (title+rule block, audience, quorum, notes). */
const NODE_GAPS_Y = 4 * 3;

/**
 * The most semantic notes one stage can ever carry — and it is a PROVEN
 * maximum, not a chosen one.
 *
 * {@link notesFor} can emit at most four things: the merge note, the loop note,
 * and one terminal per verdict that ends the chain there. Three is the ceiling
 * because the fourth is unreachable:
 *
 *  - A step showing BOTH an "approved: ends here" and a "rejected: ends here"
 *    has no outgoing transition at all, so nothing can lead back to it and
 *    {@link RouteFlowResolution.cycles} cannot contain it. Two terminals
 *    therefore exclude the loop note.
 *  - A step with only ONE terminal leaves room for merge and loop, which is
 *    three.
 *  - A non-decision step has at most one terminal ('continue'), so it is
 *    bounded by the same three.
 *
 * `route-flow-model.test.ts` asserts this over the constructed shapes rather
 * than trusting the argument, because the argument is only as good as
 * {@link resolveTransitions} staying the way it is.
 */
export const ROUTE_FLOW_MAX_NOTES = 3;

/**
 * Tall enough for everything a stage can say at once.
 *
 * Derived, so the card cannot be given a row it has no room to draw. The layout
 * strides are derived from this in turn, so spacing follows the card rather than
 * being kept in step with it by hand.
 */
export const ROUTE_FLOW_NODE_HEIGHT =
  NODE_PADDING_Y +
  NODE_BORDER_Y +
  NODE_ROW.title +
  NODE_ROW.rule +
  NODE_ROW.audience +
  NODE_ROW.quorum +
  NODE_GAPS_Y +
  ROUTE_FLOW_MAX_NOTES * NODE_ROW.note;

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
