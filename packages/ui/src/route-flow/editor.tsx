'use client';

import * as React from 'react';
import {
  Background,
  BackgroundVariant,
  Controls,
  Handle,
  MarkerType,
  Position,
  ReactFlow,
  useNodesState,
  type Connection,
  type Edge,
  type Node,
  type NodeProps,
  type NodeTypes,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { cn } from '../utils';
import {
  ROUTE_FLOW_MAX_NODES,
  ROUTE_FLOW_NODE_HEIGHT,
  ROUTE_FLOW_NODE_WIDTH,
  autoLayout,
  effectiveQuorum,
  handleFaces,
  needsAutoLayout,
  nextSlot,
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
  terminalNotes: string[];
  labels: RouteFlowEditorLabels;
  sourceFace: Position;
  targetFace: Position;
  onSelect: (position: number) => void;
  onDelete: (position: number) => void;
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
    terminalNotes,
    labels,
    sourceFace,
    targetFace,
    onSelect,
    onDelete,
  } = data;

  const title = step.label !== null && step.label !== '' ? step.label : ruleLabel;

  return (
    <div
      className={cn(
        'flex flex-col gap-1 rounded-lg border bg-card px-3 py-2 text-start shadow-sm transition-colors',
        selected ? 'border-primary ring-2 ring-primary/30' : 'border-border'
      )}
      style={{ width: ROUTE_FLOW_NODE_WIDTH, height: ROUTE_FLOW_NODE_HEIGHT }}
      data-slot="route-flow-node"
      data-position={step.position}
      data-decision={step.decision ? 'true' : 'false'}
    >
      <Handle type="target" position={targetFace} className="!bg-muted-foreground/50" />

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
          card, and the number of people behind it.

          The QUORUM is shown for every decision stage, INDEPENDENTLY of whether
          the count resolved. It used to be rendered inside the count branch, so
          a viewer who could not preview audiences (a 403 there is ordinary — the
          draft preview needs `groups:write`, which designing a flow does not
          imply) saw a gate with no indication of what would satisfy it. The two
          facts have different sources and only one of them can fail; binding
          them together made the reliable one vanish with the unreliable one.

          One line rather than two because the card height is fixed: a third row
          would push the terminal note out of the box. */}
      <span className="truncate text-xs text-muted-foreground">
        {[
          audience === undefined
            ? null
            : audience.count !== null
              ? `${labels.reaches} ${audience.count.toLocaleString()} ${labels.people}`
              : (audience.unavailableReason ?? labels.audienceUnavailable),
          step.decision ? quorumText : null,
        ]
          .filter((part): part is string => part !== null)
          .join(' · ')}
      </span>

      {terminalNotes.length > 0 && (
        <span className="line-clamp-2 text-[10px] uppercase leading-tight tracking-wide text-muted-foreground">
          {terminalNotes.join(' · ')}
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
          coloured, so the gesture and the picture agree before an edge exists. */}
      {step.decision ? (
        <>
          <Handle
            id="approved"
            type="source"
            position={sourceFace}
            style={sourceFace === Position.Bottom || sourceFace === Position.Top
              ? { insetInlineStart: '30%' }
              : { top: '35%' }}
            className="!bg-emerald-500"
            data-testid={`approve-handle-${step.position}`}
          />
          <Handle
            id="rejected"
            type="source"
            position={sourceFace}
            style={sourceFace === Position.Bottom || sourceFace === Position.Top
              ? { insetInlineStart: '70%' }
              : { top: '70%' }}
            className="!bg-rose-500"
            data-testid={`reject-handle-${step.position}`}
          />
        </>
      ) : (
        <Handle type="source" position={sourceFace} className="!bg-muted-foreground/50" />
      )}
    </div>
  );
}

const nodeTypes: NodeTypes = { routeFlowStep: RouteFlowNodeCard };

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

  const { transitions, terminals, merges, cycles } = React.useMemo(
    () => resolveTransitions(graph),
    [graph]
  );

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
        const notes: string[] = [];
        // The merge note comes FIRST: it is the more surprising of the two, and
        // the one a reader is most likely to have assumed the opposite of.
        if (merges.includes(step.position)) {
          notes.push(labels.arrivalsMerge);
        }
        if (cycles.includes(step.position)) {
          notes.push(labels.inCycle);
        }
        for (const terminal of terminals) {
          if (terminal.from !== step.position) continue;
          const on =
            terminal.on === 'approved'
              ? labels.approved
              : terminal.on === 'rejected'
                ? labels.rejected
                : labels.continues;
          notes.push(`${on}: ${labels.ends}`);
        }

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
            terminalNotes: notes,
            labels,
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
    cycles,
    merges,
    selectedPosition,
    setNodes,
    sourceFace,
    targetFace,
    terminals,
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
          type: 'default',
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
          label: t.kind === 'derived' ? `${label} (${labels.implicit})` : label,
          labelStyle: { fontSize: 10 },
          markerEnd: { type: MarkerType.ArrowClosed },
          data: { kind: t.kind },
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
          onNodesChange={onNodesChange}
          onNodeDragStop={onNodeDragStop}
          onConnect={onConnect}
          onEdgesDelete={onEdgesDelete}
          nodesDraggable={!readOnly}
          nodesConnectable={!readOnly}
          elementsSelectable
          fitView
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
