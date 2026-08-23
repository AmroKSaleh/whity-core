'use client';

import * as React from 'react';
import {
  Background,
  BackgroundVariant,
  Controls,
  Handle,
  Position,
  ReactFlow,
  useNodesState,
  type Edge,
  type Node,
  type NodeProps,
  type NodeTypes,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { cn } from '@/lib/utils';
import type { FlowModel, FlowNodeModel } from '@/components/plugin/blocks/flow-model';

/**
 * #950: the canvas half of the `flow` block.
 *
 * Deliberately a SEPARATE module from the block renderer, and loaded from it by
 * `next/dynamic` with `ssr: false` — the same arrangement the OU hub and the
 * relations hub already use for their graphs. react-flow is heavy and touches
 * browser-only APIs, and the block renderer is on the critical path of every
 * plugin screen; a static import here would put the graph library in the bundle
 * of every plugin page that has no graph on it.
 *
 * It draws a model and nothing else. Which rows became nodes, which references
 * became edges and where the graph was cut are all decided in `flow-model.ts`,
 * because those are contract behaviour rather than rendering; what is left here
 * is coordinates, handles and the affordances, which is the part that is
 * genuinely per-platform.
 *
 * The composition is the one the two existing consumers arrived at: custom node
 * cards, a dotted background, Controls, attribution hidden, nodes draggable for
 * layout only, and **connecting disabled** — `flow` is read-only, so a drag must
 * never be able to look like it created an edge.
 */

const NODE_WIDTH = 208;
/** Row heights the node card is composed of, so layout can size without measuring. */
const LABEL_HEIGHT = 48;
const SUBTITLE_HEIGHT = 16;
const ACTIONS_HEIGHT = 34;
const GAP_ALONG_FLOW = 64;
const GAP_ACROSS_FLOW = 24;

/** What one node needs to render and act. */
interface FlowNodeData extends Record<string, unknown> {
  label: string;
  subtitle: string;
  row: Record<string, string>;
  height: number;
  horizontal: boolean;
  /** Runs the node's primary affordance; absent when the block declared none. */
  onActivate?: (row: Record<string, string>) => void;
  /** The node's action controls, already built by the block renderer. */
  actions?: React.ReactNode;
}

/**
 * A node card: label, optional subtitle, optional action row.
 *
 * The label is a real `<button>` when the block gave the node something to do,
 * and a plain element when it did not — rather than a permanently disabled
 * button, which announces an affordance that does not exist. Activation lives on
 * that button instead of on react-flow's `onNodeClick` so a click on an action
 * control cannot also fire the node's own action on its way up.
 */
function FlowNodeCard({ data }: NodeProps<Node<FlowNodeData>>) {
  const { label, subtitle, row, height, horizontal, onActivate, actions } = data;

  const body = (
    <>
      <span className="truncate text-sm font-medium text-foreground">{label}</span>
      {subtitle !== '' && (
        <span className="truncate text-xs text-muted-foreground">{subtitle}</span>
      )}
    </>
  );

  return (
    <div
      className={cn(
        'flex flex-col justify-center gap-0.5 rounded-lg border border-border bg-card px-3 py-2 shadow-sm transition-colors',
        onActivate !== undefined && 'hover:border-muted-foreground/40'
      )}
      style={{ width: NODE_WIDTH, height }}
      data-slot="block-flow-node"
    >
      <Handle
        type="target"
        position={horizontal ? Position.Left : Position.Top}
        className="!bg-muted-foreground/50"
      />
      {onActivate !== undefined ? (
        <button
          type="button"
          onClick={() => onActivate(row)}
          className="flex min-w-0 flex-col text-start outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
        >
          {body}
        </button>
      ) : (
        <span className="flex min-w-0 flex-col">{body}</span>
      )}
      {/* `nodrag` keeps a press on a control from panning the node under it. */}
      {actions !== undefined && <div className="nodrag flex flex-wrap gap-1">{actions}</div>}
      <Handle
        type="source"
        position={horizontal ? Position.Right : Position.Bottom}
        className="!bg-muted-foreground/50"
      />
    </div>
  );
}

const nodeTypes: NodeTypes = { flowNode: FlowNodeCard };

/**
 * Place a node from its layer (`depth`) and its position within that layer
 * (`lane`).
 *
 * `depth` always advances ALONG the reading direction and `lane` always spreads
 * across it, so `orientation` swaps the two axes and changes nothing else —
 * including which sides the handles are on, so edges leave and arrive on the
 * faces that match the direction the diagram runs in.
 */
function positionOf(
  node: FlowNodeModel,
  horizontal: boolean,
  nodeHeight: number
): { x: number; y: number } {
  const along = horizontal ? NODE_WIDTH + GAP_ALONG_FLOW : nodeHeight + GAP_ALONG_FLOW;
  const across = horizontal ? nodeHeight + GAP_ACROSS_FLOW : NODE_WIDTH + GAP_ACROSS_FLOW;

  return horizontal
    ? { x: node.depth * along, y: node.lane * across }
    : { x: node.lane * across, y: node.depth * along };
}

export default function FlowCanvas({
  model,
  orientation = 'horizontal',
  hasSubtitle,
  onActivate,
  renderNodeActions,
}: {
  model: FlowModel;
  orientation?: 'horizontal' | 'vertical';
  /** Whether the block mapped a subtitle field, so every card is one height. */
  hasSubtitle: boolean;
  onActivate?: (row: Record<string, string>) => void;
  renderNodeActions?: (row: Record<string, string>) => React.ReactNode;
}) {
  const horizontal = orientation === 'horizontal';
  const nodeHeight =
    LABEL_HEIGHT +
    (hasSubtitle ? SUBTITLE_HEIGHT : 0) +
    (renderNodeActions !== undefined ? ACTIONS_HEIGHT : 0);

  const [nodes, setNodes, onNodesChange] = useNodesState<Node<FlowNodeData>>([]);

  // Rebuild the react-flow nodes when the model or the affordances change, but
  // KEEP any position the user has dragged (keyed by id). A data-bound block
  // refetches — on a refresh press, on a selector change, after a node action
  // mutated something — and a canvas that snapped back to the computed layout
  // every time would undo the arrangement the reader just made to understand it.
  React.useEffect(() => {
    setNodes((previous) => {
      const dragged = new Map(previous.map((n) => [n.id, n.position]));
      return model.nodes.map((node) => ({
        id: node.id,
        type: 'flowNode',
        position: dragged.get(node.id) ?? positionOf(node, horizontal, nodeHeight),
        data: {
          label: node.label,
          subtitle: node.subtitle,
          row: node.row,
          height: nodeHeight,
          horizontal,
          onActivate,
          actions: renderNodeActions?.(node.row),
        },
        connectable: false,
      }));
    });
  }, [model, horizontal, nodeHeight, onActivate, renderNodeActions, setNodes]);

  const edges = React.useMemo<Edge[]>(
    () =>
      model.edges.map((edge) => ({
        id: edge.id,
        source: edge.source,
        target: edge.target,
        // react-flow's bezier edge, the same one both existing graph views use.
        type: 'default',
      })),
    [model.edges]
  );

  return (
    <div
      className="h-[28rem] w-full rounded-lg border border-border bg-card"
      data-slot="block-flow-canvas"
    >
      <ReactFlow
        nodes={nodes}
        edges={edges}
        nodeTypes={nodeTypes}
        onNodesChange={onNodesChange}
        nodesDraggable
        // Read-only is a property of the block's CONTRACT — it declares no
        // endpoint and no verb — so the canvas must not offer a gesture that
        // looks like it edits the graph. Dragging moves a box; it never links.
        nodesConnectable={false}
        elementsSelectable={false}
        fitView
        // react-flow's default minZoom (0.5) defeats fitView on anything wider
        // than about twice the canvas: the fit clamps at 0.5 and centres,
        // silently clipping the outermost nodes. The OU hub hit this first; a
        // block draws up to FLOW_MAX_NODES and hits it sooner.
        minZoom={0.1}
        proOptions={{ hideAttribution: true }}
      >
        <Background variant={BackgroundVariant.Dots} gap={16} size={1} />
        <Controls showInteractive={false} />
      </ReactFlow>
    </div>
  );
}
