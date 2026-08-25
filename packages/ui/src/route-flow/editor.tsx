'use client';

import * as React from 'react';
import {
  Background,
  BackgroundVariant,
  BaseEdge,
  Controls,
  Handle,
  MarkerType,
  Position,
  ReactFlow,
  getBezierPath,
  useNodesState,
  type Connection,
  type Edge,
  type EdgeProps,
  type EdgeTypes,
  type Node,
  type NodeProps,
  type NodeTypes,
  type ReactFlowInstance,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { cn } from '../utils';
import {
  ROUTE_FLOW_MAX_NODES,
  ROUTE_FLOW_MAX_NOTES,
  ROUTE_FLOW_NODE_HEIGHT,
  ROUTE_FLOW_NODE_WIDTH,
  autoLayout,
  effectiveQuorum,
  handleFaces,
  needsAutoLayout,
  nextSlot,
  notesFor,
  renumber,
  resolveTransitions,
  stepsInOrder,
  type RouteFlowAudience,
  type RouteFlowDirection,
  type RouteFlowGraph,
  type RouteFlowOrientation,
  type RouteFlowStep,
  type RouteFlowVerdict,
} from './model';

/**
 * The node-based editor for document route flows (#1027).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY THIS IS NOT THE `flow` BLOCK
 * ─────────────────────────────────────────────────────────────────────────
 * #950 already ships a `flow` block, and it was the first thing checked. It is
 * not reusable here, for reasons that are properties of its contract rather than
 * gaps in its implementation:
 *
 *  - IT IS READ-ONLY BY DESIGN. Its canvas sets `nodesConnectable={false}` and
 *    `elementsSelectable={false}`, and its own comment says why: "`flow` is
 *    read-only, so a drag must never be able to look like it created an edge."
 *    Making it editable would not extend it; it would retire the guarantee.
 *  - IT IS DATA-BOUND TO A URL. A block declares a `source` and renders whatever
 *    comes back. An editor's graph is local, mutable state that is saved as one
 *    document, which is a different lifecycle, not a different fetch.
 *  - IT LIVES IN `web/components/plugin/blocks/`, so it ships to one of three
 *    clients. A graph editor is precisely the component the second one wants.
 *
 * What it DID give, and what is taken from it rather than re-decided: the
 * composition that works (custom node cards, dotted background, `Controls`,
 * attribution hidden, `minZoom` low enough that `fitView` does not clip), and
 * the node ceiling — {@link ROUTE_FLOW_MAX_NODES} mirrors its
 * `BlockContract::FLOW_MAX_NODES` rather than inventing a second number for the
 * same question.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * A NODE IS A TYPE, AND THIS COMPONENT CANNOT MAKE IT ANYTHING ELSE
 * ─────────────────────────────────────────────────────────────────────────
 * One node per RULE. "Everyone holding Instructor" is one card whether it
 * resolves to four people or four thousand, and there is no code path here that
 * expands a rule into people — {@link RouteFlowStep} has no field that could hold
 * them.
 *
 * How big a node is comes in through {@link RouteFlowEditorProps.audienceFor},
 * which a HOST answers from the server's existing preview. The card shows the
 * COUNT and never a roster, and where the host cannot resolve one it shows the
 * host's reason instead of a zero — a zero would read as "this reaches nobody",
 * which is a different and much more alarming statement than "you cannot see
 * this".
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NO TRANSLATOR, NO FETCH, NO ROUTER
 * ─────────────────────────────────────────────────────────────────────────
 * Every string a person reads is a prop with an English default
 * ({@link RouteFlowEditorLabels}), because a kit component that imported a
 * translator would bind three clients to one app's i18n runtime. Rule kinds are
 * turned into human labels by {@link RouteFlowEditorProps.ruleLabelFor}, because
 * the catalogue of kinds is a server fact and includes whatever plugins the host
 * has installed.
 *
 * The INSPECTOR — choosing a rule kind, picking a group, setting a quorum — is
 * deliberately not here. Those need pickers bound to the host's own APIs, and one
 * of them (the group picker) is being built as its own kit component. This
 * component owns the CANVAS and reports selection; the host renders the panel.
 */

export interface RouteFlowEditorLabels {
  /** Shown on the canvas when the flow has no steps at all. */
  empty: string;
  addStep: string;
  deleteStep: string;
  /** Badge on a step that demands a verdict. */
  decision: string;
  /** Prefix for the resolved audience, e.g. "reaches 1,043 people". */
  reaches: string;
  people: string;
  /**
   * The singular of {@link RouteFlowEditorLabels.people}.
   *
   * A separate label rather than a rule applied to `people`, because which
   * counts take which form is a property of the LANGUAGE and not of this
   * component: English splits at one, Arabic splits at one, two and eleven. A
   * host with a plural-aware translator passes the form its own rules chose;
   * this only ever asks for "the word for a count of 1".
   */
  person: string;
  /** Shown in place of a count the host could not resolve and gave no reason for. */
  audienceUnavailable: string;
  approved: string;
  rejected: string;
  /** The unconditional advance out of a non-decision step. */
  continues: string;
  /** Marker on a transition the engine takes that nobody drew. */
  implicit: string;
  /** Marker where a chain stops. */
  ends: string;
  /**
   * Marker on a stage that more than one path reaches.
   *
   * Says that arrivals MERGE — the engine de-duplicates two chains reaching the
   * same person at the same stage into one item, so the stage settles once
   * rather than once per arriving path. Without it, two arrows entering a box
   * read as two things carrying on through it, which is not what happens.
   */
  arrivalsMerge: string;
  /**
   * Marker on a stage a document can come back round to.
   *
   * Says a LOOP exists in the design — not that any document has gone round it,
   * which the canvas cannot know. Worth stating because a rework loop is
   * authored deliberately and is invisible afterwards: nothing counts laps
   * (#1037), so a document on its ninth rejection looks like one on its first.
   */
  inCycle: string;
  /** Prefix for the effective quorum, e.g. "all must approve". */
  quorumAll: string;
  quorumAny: string;
  quorumMajority: string;
  /** Shown when the graph is larger than the ceiling and has been cut. */
  tooManyNodes: string;
}

const DEFAULT_LABELS: RouteFlowEditorLabels = {
  empty: 'No stages yet. Add the first one to start the flow.',
  addStep: 'Add stage',
  deleteStep: 'Delete stage',
  decision: 'Decision',
  reaches: 'Reaches',
  people: 'people',
  person: 'person',
  audienceUnavailable: 'Audience size unavailable',
  approved: 'Approved',
  rejected: 'Rejected',
  continues: 'Continues',
  implicit: 'implicit',
  ends: 'Ends here',
  arrivalsMerge: 'Paths merge here — settles once',
  inCycle: 'Can come back round — loops',
  quorumAll: 'all must approve',
  quorumAny: 'any one may approve',
  quorumMajority: 'a majority must approve',
  tooManyNodes: 'This flow is larger than the canvas will draw. Showing the first stages only.',
};

export interface RouteFlowEditorProps {
  graph: RouteFlowGraph;
  /**
   * Called with the whole next graph on every edit.
   *
   * The whole graph, not a patch: the editor's unit of work is the canvas, and a
   * patch would ask the host to re-apply changes it did not make.
   */
  onGraphChange?: (graph: RouteFlowGraph) => void;
  /** The step the host's inspector is editing, by `position`. */
  selectedPosition?: number | null;
  onSelectStep?: (position: number | null) => void;
  /**
   * Direction-NEUTRAL by default. See `positionStep` in the model for the full
   * argument: a top-to-bottom flow reads identically in Arabic and English.
   */
  orientation?: RouteFlowOrientation;
  /**
   * The reading direction. Mirrors every horizontal axis, including the handles,
   * so an arrow leaves the face a reader expects it to leave.
   *
   * "Including the handles" is load-bearing and was, for a while, untrue.
   * `@xyflow/react` sets `direction: ltr` on its own container — deliberately,
   * because its transforms are computed in one coordinate system — and that
   * neutralises `insetInlineStart` on anything inside it. The verdict handles
   * were positioned with exactly that property, so they stayed at 30% and 70%
   * from the LEFT in Arabic while {@link slotAt} put the reject lane on the
   * left, which is the "mirrored layout, unmirrored handles" failure
   * {@link handleFaces} warns about: the reject arrow left the far side of its
   * own card and crossed back underneath it.
   *
   * They are placed with physical `left`/`right` now, chosen from this prop, so
   * the mirroring does not depend on a container's inherited direction. The card
   * carries its own `dir` for the same reason — `text-start` inside the canvas
   * would otherwise resolve to "left" and left-align Arabic.
   */
  direction?: RouteFlowDirection;
  /**
   * The most nodes to draw. Defaults to {@link ROUTE_FLOW_MAX_NODES}; a host that
   * knows its tenant's `documents.routing_max_steps` should pass it, because THAT
   * is the authoritative limit on how many steps may be saved.
   */
  maxNodes?: number;
  readOnly?: boolean;
  /** How many people a step's rule currently reaches, resolved by the host. */
  audienceFor?: (step: RouteFlowStep) => RouteFlowAudience | undefined;
  /** A human label for a rule kind — the catalogue is a server fact. */
  ruleLabelFor?: (step: RouteFlowStep) => string;
  labels?: Partial<RouteFlowEditorLabels>;
  className?: string;
}

interface RouteFlowNodeData extends Record<string, unknown> {
  step: RouteFlowStep;
  quorumText: string;
  ruleLabel: string;
  audience: RouteFlowAudience | undefined;
  selected: boolean;
  readOnly: boolean;
  notes: string[];
  labels: RouteFlowEditorLabels;
  direction: RouteFlowDirection;
  sourceFace: Position;
  targetFace: Position;
  onSelect: (position: number) => void;
  onDelete: (position: number) => void;
}

/**
 * How big a handle is drawn.
 *
 * `@xyflow/react`'s default is 6×6 CSS px, which the canvas zoom then shrinks:
 * at the zoom a four-stage flow fits at, the measured target was 5.4px on
 * screen. Starting an edge meant hitting a 5px dot, and the alternative gesture
 * a person tries first — dropping on the target CARD — does nothing, because a
 * connection has to end on a handle. 12px is a target a hand can find, and it is
 * still small enough that two of them sit on one 224px face without touching.
 *
 * The other half of that fix is {@link CONNECTION_RADIUS}.
 */
const HANDLE_CLASS = '!h-3 !w-3 !border-2 !border-card !bg-muted-foreground/60';

/**
 * How far from a handle a drop still counts as landing on it.
 *
 * `@xyflow/react` defaults to 20px, so a connection had to be released within
 * 20px of a 5px dot; releasing over the target CARD — the gesture people try
 * first — did nothing at all, and did it silently.
 *
 * Widened to reach from the target handle (top-centre of a card) across most of
 * the card it belongs to, so dropping ON the node connects. Deliberately NOT
 * wide enough to span the gap to the next node: {@link ROUTE_FLOW_NODE_HEIGHT}
 * plus its layout gap is the distance between two stages, and this is well
 * inside half of that, so a drop is never claimed by the card an author was not
 * pointing at.
 */
const CONNECTION_RADIUS = 80;

/**
 * Where a verdict handle sits along the source face, as a physical inset.
 *
 * PHYSICAL, not logical. `insetInlineStart` would be the idiomatic choice and it
 * is the one that was here; it does not work, because `@xyflow/react` forces
 * `direction: ltr` on its container and a logical inset resolves against that
 * rather than against the document. So the mirroring is done here, from the
 * direction the host passed, where nothing can quietly undo it.
 */
function verdictInset(
  direction: RouteFlowDirection,
  percentFromReadingStart: number
): React.CSSProperties {
  // Mirrored by arithmetic and still written as `left`, NOT by switching to
  // `right`. `@xyflow/react` centres a handle on its inset with a
  // `translateX(-50%)` of its own; anchoring from the other edge leaves that
  // translation pulling the wrong way and lands the handle half its own width
  // off — measured 0.65 where 0.70 was meant. One physical property, one
  // percentage, nothing left for a transform to disagree with.
  const fromLeft = direction === 'rtl' ? 100 - percentFromReadingStart : percentFromReadingStart;

  return { left: `${fromLeft}%` };
}

const FACE: Record<string, Position> = {
  top: Position.Top,
  bottom: Position.Bottom,
  left: Position.Left,
  right: Position.Right,
};

/**
 * One stage.
 *
 * A DECISION step carries TWO source handles — one per verdict — and an ordinary
 * step carries one. That is what makes "draw the approve edge and the reject
 * edge from one node" a gesture rather than a form: which handle a drag starts
 * from IS the verdict, so an author never picks it from a menu afterwards and
 * the two arrows are visibly different before they are drawn.
 *
 * A non-decision step gets no verdict handles at all, because an edge leaving one
 * could never fire — the server refuses it, and an affordance that produces a
 * refusal is worse than no affordance.
 */
function RouteFlowNodeCard({ data }: NodeProps<Node<RouteFlowNodeData>>) {
  const {
    step,
    quorumText,
    ruleLabel,
    audience,
    selected,
    readOnly,
    notes,
    labels,
    direction,
    sourceFace,
    targetFace,
    onSelect,
    onDelete,
  } = data;

  const title = step.label !== null && step.label !== '' ? step.label : ruleLabel;

  return (
    <div
      // The card carries its OWN `dir`. `@xyflow/react` forces `direction: ltr`
      // on its container, so without this every `text-start` and every `end-*`
      // inside a node resolves to the left-to-right answer and Arabic labels sit
      // left-aligned in a right-to-left page.
      dir={direction}
      className={cn(
        'flex flex-col gap-1 rounded-lg border bg-card px-3 py-2 text-start shadow-sm transition-colors',
        selected ? 'border-primary ring-2 ring-primary/30' : 'border-border'
      )}
      style={{ width: ROUTE_FLOW_NODE_WIDTH, height: ROUTE_FLOW_NODE_HEIGHT }}
      data-slot="route-flow-node"
      data-position={step.position}
      data-decision={step.decision ? 'true' : 'false'}
    >
      <Handle type="target" position={targetFace} className={HANDLE_CLASS} />

      <button
        type="button"
        onClick={() => onSelect(step.position)}
        className="flex min-w-0 flex-col text-start outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
      >
        <span className="flex items-center gap-1.5">
          <span className="truncate text-sm font-medium text-foreground">{title}</span>
          {step.decision && (
            <span className="shrink-0 rounded bg-primary/10 px-1 text-[10px] font-medium uppercase text-primary">
              {labels.decision}
            </span>
          )}
        </span>
        {/* The RULE, always visible. A card that showed only an author's label
            would let "Dean" sit on a node that actually reaches every registrar,
            and nothing on screen would say so. */}
        <span className="truncate text-xs text-muted-foreground">{ruleLabel}</span>
      </button>

      {/* THE COUNT, NEVER A ROSTER — the line that makes a type-node honest: one
          card, and the number of people behind it. */}
      <span className="truncate text-xs text-muted-foreground">
        {audience === undefined
          ? ' '
          : audience.count !== null
            ? `${labels.reaches} ${audience.count.toLocaleString()} ${
                audience.count === 1 ? labels.person : labels.people
              }`
            : (audience.unavailableReason ?? labels.audienceUnavailable)}
      </span>

      {/* THE QUORUM, on its own row.

          It is shown for every decision stage, INDEPENDENTLY of whether the
          count resolved. It used to be rendered inside the count branch, so a
          viewer who could not preview audiences (a 403 there is ordinary — the
          draft preview needs `groups:write`, which designing a flow does not
          imply) saw a gate with no indication of what would satisfy it. The two
          facts have different sources and only one of them can fail; binding
          them together made the reliable one vanish with the unreliable one.

          It was then joined onto the COUNT's line with a separator, and the pair
          overflowed one 224px row: "Reaches 1 people · all must appr…" — a
          quorum made unconditionally visible and then truncated mid-word, which
          is the same disappearance by a slower route (#1042). Its own row costs
          16px of a height that is now derived from the rows it holds, and no
          audience string can crowd it out. */}
      <span className="truncate text-xs text-muted-foreground">
        {step.decision ? quorumText : ' '}
      </span>

      {notes.length > 0 && (
        <span
          data-slot="route-flow-notes"
          // Clamped from the SAME constant the card's height is budgeted against
          // (see ROUTE_FLOW_MAX_NOTES). Tailwind's `line-clamp-N` is a static
          // class and could only repeat the number as a literal, which is
          // precisely how the two drifted apart: the height was raised to hold
          // three lines while the clamp stayed at two, and a stage carrying
          // merge + loop + "Rejected: Ends here" dropped the last of them.
          style={{
            display: '-webkit-box',
            WebkitBoxOrient: 'vertical',
            WebkitLineClamp: ROUTE_FLOW_MAX_NOTES,
            overflow: 'hidden',
          }}
          className="text-[10px] uppercase leading-tight tracking-wide text-muted-foreground"
        >
          {notes.join(' · ')}
        </span>
      )}

      {!readOnly && (
        <button
          type="button"
          onClick={() => onDelete(step.position)}
          aria-label={labels.deleteStep}
          className="nodrag absolute end-1 top-1 rounded px-1 text-xs text-muted-foreground hover:text-destructive"
        >
          &times;
        </button>
      )}

      {/* A decision step's two verdict handles are stacked on the source face and
          coloured, so the gesture and the picture agree before an edge exists.

          On a horizontal face the two are offset along the VERTICAL axis, which
          no reading direction mirrors, so only the top/bottom case consults
          `direction`. */}
      {step.decision ? (
        <>
          <Handle
            id="approved"
            type="source"
            position={sourceFace}
            style={
              sourceFace === Position.Bottom || sourceFace === Position.Top
                ? verdictInset(direction, 30)
                : { top: '35%' }
            }
            className={cn(HANDLE_CLASS, '!bg-emerald-500')}
            data-testid={`approve-handle-${step.position}`}
          />
          <Handle
            id="rejected"
            type="source"
            position={sourceFace}
            style={
              sourceFace === Position.Bottom || sourceFace === Position.Top
                ? verdictInset(direction, 70)
                : { top: '70%' }
            }
            className={cn(HANDLE_CLASS, '!bg-rose-500')}
            data-testid={`reject-handle-${step.position}`}
          />
        </>
      ) : (
        <Handle type="source" position={sourceFace} className={HANDLE_CLASS} />
      )}
    </div>
  );
}

const nodeTypes: NodeTypes = { routeFlowStep: RouteFlowNodeCard };

interface RouteFlowEdgeData extends Record<string, unknown> {
  kind: 'drawn' | 'derived';
  on: RouteFlowVerdict | 'continue';
  text: string;
}

/**
 * How far a transition's label is nudged off the middle of its own curve, by
 * verdict.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY A LABEL NEEDS AN OFFSET AT ALL
 * ─────────────────────────────────────────────────────────────────────────
 * Two transitions running BETWEEN THE SAME PAIR OF STAGES in opposite
 * directions have, to within a few pixels, the same midpoint — and a built-in
 * edge draws its label at exactly that midpoint. So a rework loop, which is the
 * commonest real design there is, printed both labels on top of each other:
 * "Approved (imp Rejected" (#1042).
 *
 * That is an anti-parallel PAIR, not "two edges leaving one node" — two edges
 * leaving one node diverge, and their labels separate on their own. The pair is
 * always a pair of DIFFERENT verdicts (one destination per verdict per stage is
 * enforced by the schema and by `onConnect`), so nudging by verdict is enough to
 * separate any pair that can exist, and it does it deterministically rather than
 * by a collision search whose answer would move as nodes are dragged.
 *
 * Bigger than a label's own line box, so the two clear rather than touch.
 */
const LABEL_NUDGE: Record<RouteFlowVerdict | 'continue', number> = {
  approved: -18,
  continue: 0,
  rejected: 18,
};

/**
 * One transition.
 *
 * A custom edge only so the label can be moved; everything else is
 * `@xyflow/react`'s own, including the invisible interaction path that makes an
 * edge selectable and therefore deletable.
 *
 * The label is drawn on a background chip because it sits over a dotted
 * background and, in a loop, over the other arrow.
 */
function RouteFlowTransitionEdge({
  sourceX,
  sourceY,
  sourcePosition,
  targetX,
  targetY,
  targetPosition,
  markerEnd,
  style,
  data,
}: EdgeProps<Edge<RouteFlowEdgeData>>) {
  const [path, labelX, labelY] = getBezierPath({
    sourceX,
    sourceY,
    sourcePosition,
    targetX,
    targetY,
    targetPosition,
  });

  return (
    <BaseEdge
      path={path}
      markerEnd={markerEnd}
      style={style}
      label={data?.text}
      labelX={labelX}
      labelY={labelY + LABEL_NUDGE[data?.on ?? 'continue']}
      // SVG text defaults to black and `@xyflow/react` sets no fill of its own,
      // so an edge label was black on a near-black canvas in dark mode. Both
      // colours come from the theme, so the chip and its text move together.
      labelStyle={{ fontSize: 10, fill: 'var(--color-muted-foreground, #64748b)' }}
      labelShowBg
      labelBgPadding={[4, 2]}
      labelBgBorderRadius={3}
      labelBgStyle={{ fill: 'var(--color-card, #ffffff)', fillOpacity: 0.92 }}
    />
  );
}

const edgeTypes: EdgeTypes = { routeFlowTransition: RouteFlowTransitionEdge };

/**
 * How the canvas frames itself.
 *
 * `maxZoom` is the important one and it was missing. `@xyflow/react` defaults a
 * fit to a maximum of 2×, and `fitView` runs on MOUNT — which, for a template
 * being authored from nothing, is the moment the FIRST node appears. One node
 * fits at 2×, the viewport froze there, and stages 2, 3 and 4 were then added
 * off-screen: the measured result was `scale(2)` with one of four nodes visible,
 * for every new flow anybody made (#1042).
 *
 * Capped at 1 rather than at some larger number because a node card is designed
 * at a fixed pixel size and a magnified one is not more legible, only bigger.
 * This bounds the automatic fit ONLY — the `maxZoom` prop is left at its default
 * so a person can still zoom in past this by hand.
 */
const FIT_VIEW = { maxZoom: 1, padding: 0.15 };

export function RouteFlowEditor({
  graph,
  onGraphChange,
  selectedPosition = null,
  onSelectStep,
  orientation = 'vertical',
  direction = 'ltr',
  maxNodes = ROUTE_FLOW_MAX_NODES,
  readOnly = false,
  audienceFor,
  ruleLabelFor,
  labels: labelOverrides,
  className,
}: RouteFlowEditorProps) {
  const labels = React.useMemo<RouteFlowEditorLabels>(
    () => ({ ...DEFAULT_LABELS, ...labelOverrides }),
    [labelOverrides]
  );

  const faces = handleFaces(orientation, direction);
  const sourceFace = FACE[faces.source] ?? Position.Bottom;
  const targetFace = FACE[faces.target] ?? Position.Top;

  const ordered = React.useMemo(() => stepsInOrder(graph.steps), [graph.steps]);
  const cut = ordered.length > maxNodes;
  const drawn = cut ? ordered.slice(0, maxNodes) : ordered;

  const resolution = React.useMemo(() => resolveTransitions(graph), [graph]);
  const { transitions } = resolution;

  const quorumLabel = React.useCallback(
    (step: RouteFlowStep): string => {
      switch (effectiveQuorum(step, graph)) {
        case 'any':
          return labels.quorumAny;
        case 'majority':
          return labels.quorumMajority;
        default:
          return labels.quorumAll;
      }
    },
    [graph, labels]
  );

  const emit = React.useCallback(
    (next: RouteFlowGraph) => {
      onGraphChange?.(next);
    },
    [onGraphChange]
  );

  const handleDelete = React.useCallback(
    (position: number) => {
      // Renumber and rewrite edges in the same pass — see `renumber`. `position`
      // is also the FALLTHROUGH order, so a delete that left a gap would leave
      // an author reading arrows that no longer match the list.
      emit(
        renumber({
          ...graph,
          steps: graph.steps.filter((s) => s.position !== position),
          edges: graph.edges.filter((e) => e.from !== position && e.to !== position),
        })
      );
      if (selectedPosition === position) {
        onSelectStep?.(null);
      }
    },
    [emit, graph, onSelectStep, selectedPosition]
  );

  const [nodes, setNodes, onNodesChange] = useNodesState<Node<RouteFlowNodeData>>([]);

  React.useEffect(() => {
    // A graph nothing has ever arranged gets one tidy layout; anything an author
    // has touched keeps the arrangement they made. See `needsAutoLayout` for why
    // the test is deliberately narrow.
    const placed = needsAutoLayout(drawn)
      ? autoLayout({ ...graph, steps: drawn }, orientation, direction)
      : drawn;

    setNodes(
      placed.map((step) => {
        // WHICH notes a stage carries is decided in the model, by `notesFor`,
        // and only the wording is decided here. That split is what lets
        // ROUTE_FLOW_MAX_NOTES — the number the card's height is budgeted
        // against — be an assertion a test can make.
        const notes = notesFor(step.position, resolution).map((note) => {
          if (note.kind === 'merge') return labels.arrivalsMerge;
          if (note.kind === 'cycle') return labels.inCycle;
          const on =
            note.on === 'approved'
              ? labels.approved
              : note.on === 'rejected'
                ? labels.rejected
                : labels.continues;
          return `${on}: ${labels.ends}`;
        });

        return {
          id: String(step.position),
          type: 'routeFlowStep',
          position: { x: step.canvasX, y: step.canvasY },
          draggable: !readOnly,
          data: {
            step,
            quorumText: quorumLabel(step),
            ruleLabel: ruleLabelFor?.(step) ?? step.ruleKind,
            audience: audienceFor?.(step),
            selected: selectedPosition === step.position,
            readOnly,
            notes,
            labels,
            direction,
            sourceFace,
            targetFace,
            onSelect: (p: number) => onSelectStep?.(p),
            onDelete: handleDelete,
          },
        };
      })
    );
  }, [
    audienceFor,
    direction,
    drawn,
    graph,
    handleDelete,
    labels,
    onSelectStep,
    orientation,
    quorumLabel,
    readOnly,
    ruleLabelFor,
    resolution,
    selectedPosition,
    setNodes,
    sourceFace,
    targetFace,
  ]);

  const edges = React.useMemo<Edge[]>(() => {
    const visible = new Set(drawn.map((s) => s.position));

    return transitions
      .filter((t) => visible.has(t.from) && visible.has(t.to))
      .map((t) => {
        const isReject = t.on === 'rejected';
        const isApprove = t.on === 'approved';
        const label =
          t.on === 'approved'
            ? labels.approved
            : t.on === 'rejected'
              ? labels.rejected
              : labels.continues;

        return {
          id: `${t.from}-${t.on}-${t.to}`,
          source: String(t.from),
          target: String(t.to),
          sourceHandle: isApprove || isReject ? t.on : undefined,
          type: 'routeFlowTransition',
          // A DERIVED transition is dashed and says so. It is a real arrow — the
          // engine will take it — but it is not one the author drew, and it
          // cannot be deleted except by reordering the steps that imply it.
          animated: false,
          style: {
            strokeDasharray: t.kind === 'derived' ? '4 3' : undefined,
            stroke: isReject
              ? 'var(--color-rose-500, #f43f5e)'
              : isApprove
                ? 'var(--color-emerald-500, #10b981)'
                : undefined,
          },
          markerEnd: { type: MarkerType.ArrowClosed },
          // The label travels in `data` rather than in `label`, because the
          // custom edge draws it at a nudged position — see LABEL_NUDGE.
          data: {
            kind: t.kind,
            on: t.on,
            text: t.kind === 'derived' ? `${label} (${labels.implicit})` : label,
          },
        } satisfies Edge;
      });
  }, [drawn, labels, transitions]);

  /**
   * A drag between two handles becomes a stored edge.
   *
   * The verdict is the SOURCE HANDLE's id, never a guess: an author who dragged
   * from the green handle drew an approve edge, and there is no second place that
   * decision could be made differently. A drag from a plain (non-decision) handle
   * is dropped, because such an edge could never fire and the server would refuse
   * it — better to have the gesture do nothing visible than to save something
   * that comes back as an error.
   */
  const onConnect = React.useCallback(
    (connection: Connection) => {
      if (readOnly) return;

      const verdict = connection.sourceHandle;
      if (verdict !== 'approved' && verdict !== 'rejected') return;

      const from = Number(connection.source);
      const to = Number(connection.target);
      if (!Number.isFinite(from) || !Number.isFinite(to) || from === to) return;

      // One destination per verdict per node — the same uniqueness the schema
      // enforces. Re-drawing replaces rather than adding a second arrow, which
      // is what an author dragging again means.
      const kept = graph.edges.filter((e) => !(e.from === from && e.verdict === verdict));
      emit({ ...graph, edges: [...kept, { from, to, verdict: verdict as RouteFlowVerdict }] });
    },
    [emit, graph, readOnly]
  );

  const onEdgesDelete = React.useCallback(
    (removed: Edge[]) => {
      if (readOnly) return;
      // Only DRAWN edges can be removed. A derived one is a consequence of the
      // step order; deleting the arrow without deleting the cause would show a
      // canvas the engine disagrees with.
      const drawnIds = new Set(removed.filter((e) => (e.data as { kind?: string })?.kind === 'drawn').map((e) => e.id));
      if (drawnIds.size === 0) return;

      emit({
        ...graph,
        edges: graph.edges.filter((e) => !drawnIds.has(`${e.from}-${e.verdict}-${e.to}`)),
      });
    },
    [emit, graph, readOnly]
  );

  /**
   * Re-frame the canvas when the flow gains or loses a stage.
   *
   * `fitView` alone only ever fires once, on mount. Everything an author does
   * after that — and on a new template, everything they do at all — happens to a
   * viewport that was framed for a graph with one node in it.
   *
   * Keyed on the node COUNT and nothing else, deliberately. Re-fitting on every
   * graph change would yank the canvas out from under someone who had panned to
   * a corner and was renaming a stage; adding or deleting a stage is the one
   * edit that changes what there is to look at.
   *
   * `requestAnimationFrame` because the fit needs the new node MEASURED, and
   * this effect runs in the same commit that first renders it.
   */
  const instance = React.useRef<ReactFlowInstance<Node<RouteFlowNodeData>, Edge> | null>(null);
  const onInit = React.useCallback(
    (next: ReactFlowInstance<Node<RouteFlowNodeData>, Edge>) => {
      instance.current = next;
    },
    []
  );

  const nodeCount = drawn.length;
  React.useEffect(() => {
    if (nodeCount === 0) return undefined;
    const frame = requestAnimationFrame(() => {
      void instance.current?.fitView(FIT_VIEW);
    });
    return () => cancelAnimationFrame(frame);
  }, [nodeCount]);

  const onNodeDragStop = React.useCallback(
    (_event: MouseEvent | TouchEvent, node: Node) => {
      if (readOnly) return;
      const position = Number(node.id);
      emit({
        ...graph,
        steps: graph.steps.map((s) =>
          s.position === position
            ? { ...s, canvasX: Math.round(node.position.x), canvasY: Math.round(node.position.y) }
            : s
        ),
      });
    },
    [emit, graph, readOnly]
  );

  return (
    <div className={cn('relative h-[32rem] w-full rounded-lg border border-border bg-card', className)}>
      {cut && (
        <p
          className="absolute inset-x-0 top-0 z-10 bg-amber-500/10 px-3 py-1 text-xs text-foreground"
          role="status"
        >
          {labels.tooManyNodes}
        </p>
      )}
      {drawn.length === 0 ? (
        <p className="flex h-full items-center justify-center px-6 text-center text-sm text-muted-foreground">
          {labels.empty}
        </p>
      ) : (
        <ReactFlow
          nodes={nodes}
          edges={edges}
          nodeTypes={nodeTypes}
          edgeTypes={edgeTypes}
          onInit={onInit}
          onNodesChange={onNodesChange}
          onNodeDragStop={onNodeDragStop}
          onConnect={onConnect}
          onEdgesDelete={onEdgesDelete}
          nodesDraggable={!readOnly}
          nodesConnectable={!readOnly}
          elementsSelectable
          connectionRadius={CONNECTION_RADIUS}
          fitView
          fitViewOptions={FIT_VIEW}
          // react-flow's default minZoom (0.5) defeats fitView on anything wider
          // than about twice the canvas: the fit clamps at 0.5 and centres,
          // silently clipping the outermost nodes. The OU hub hit this first and
          // the `flow` block records it; a route flow with a reject lane is wide
          // enough to hit it sooner.
          minZoom={0.1}
          proOptions={{ hideAttribution: true }}
        >
          <Background variant={BackgroundVariant.Dots} gap={16} size={1} />
          <Controls showInteractive={false} />
        </ReactFlow>
      )}
    </div>
  );
}

/**
 * Append a stage to a flow, placed in the next free slot.
 *
 * Exported beside the editor rather than living inside it because ADDING a stage
 * needs a rule, and choosing one is the host's inspector's job. The host builds
 * the step and calls this to get the placement and the numbering right.
 */
export function appendStep(
  graph: RouteFlowGraph,
  step: Omit<RouteFlowStep, 'position' | 'canvasX' | 'canvasY'>,
  orientation: RouteFlowOrientation = 'vertical',
  direction: RouteFlowDirection = 'ltr'
): RouteFlowGraph {
  const positions = graph.steps.map((s) => s.position);
  const position = positions.length === 0 ? 1 : Math.max(...positions) + 1;

  return {
    ...graph,
    steps: [...graph.steps, { ...step, position, ...nextSlot(graph, orientation, direction) }],
  };
}
