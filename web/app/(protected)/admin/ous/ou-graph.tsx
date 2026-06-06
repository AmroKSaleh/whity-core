'use client';

import { useCallback, useEffect, useMemo } from 'react';
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
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { IconDotsVertical } from '@tabler/icons-react';
import { flattenOuTree, type OuNode } from './ou-tree-util';
import type { OuAction, OuViewProps } from './ou-view';

const NODE_WIDTH = 180;
const NODE_HEIGHT = 52;
const H_GAP = 32;
const V_GAP = 90;

/** Data carried on each react-flow node so the custom node can render + act. */
interface OuNodeData extends Record<string, unknown> {
  ou: OuNode;
  selected: boolean;
  onSelect: (id: number) => void;
  onAction: (action: OuAction, node: OuNode) => void;
}

/**
 * Hand-rolled, dependency-free tidy-tree layout.
 *
 * A single post-order pass assigns each node an `x`:
 * - **Leaves** take the next free horizontal slot (left to right).
 * - **Parents** are centred over the span of their children
 *   (`(firstChildX + lastChildX) / 2`).
 *
 * `y` is driven purely by depth (`depth * V_GAP`), so every parent sits directly
 * above its subtree and edges run cleanly top-down instead of zig-zagging across
 * independently-centred layers. This is the documented v1 approach (no dagre/elk).
 *
 * Recursion is safe here: `buildOuTree` has already stripped any cyclic
 * `parent_id` data, so the tree it returns is finite and acyclic.
 */
function layout(tree: OuNode[]): { nodes: Array<{ node: OuNode; x: number; y: number }> } {
  const positioned: Array<{ node: OuNode; x: number; y: number }> = [];
  const step = NODE_WIDTH + H_GAP;
  let nextLeafSlot = 0;

  const assignX = (node: OuNode): number => {
    const y = node.depth * V_GAP;
    let x: number;
    if (node.children.length === 0) {
      x = nextLeafSlot * step;
      nextLeafSlot += 1;
    } else {
      const childXs = node.children.map(assignX);
      x = (childXs[0] + childXs[childXs.length - 1]) / 2;
    }
    positioned.push({ node, x, y });
    return x;
  };

  for (const root of tree) {
    assignX(root);
  }
  return { nodes: positioned };
}

/** Custom react-flow node: a selectable card with a per-node action menu. */
function OuFlowNode({ data }: NodeProps<Node<OuNodeData>>) {
  const { ou, selected, onSelect, onAction } = data;
  return (
    <div
      className={cn(
        'flex items-center justify-between gap-1 rounded-lg border bg-card px-2.5 py-2 text-xs/relaxed shadow-sm transition-colors',
        selected ? 'border-primary ring-2 ring-ring/30' : 'border-border hover:border-muted-foreground/40'
      )}
      style={{ width: NODE_WIDTH, height: NODE_HEIGHT }}
    >
      <Handle type="target" position={Position.Top} className="!bg-muted-foreground/50" />
      <button
        type="button"
        onClick={() => onSelect(ou.id)}
        className="min-w-0 flex-1 truncate text-start font-medium text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
      >
        {ou.name}
      </button>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={`Actions for ${ou.name}`}
            className="nodrag"
          >
            <IconDotsVertical />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onClick={() => onAction('create-child', ou)}>
            Create child OU
          </DropdownMenuItem>
          <DropdownMenuItem onClick={() => onAction('edit', ou)}>Edit</DropdownMenuItem>
          <DropdownMenuItem onClick={() => onAction('move', ou)}>Move to&hellip;</DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem variant="destructive" onClick={() => onAction('delete', ou)}>
            Delete
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
      <Handle type="source" position={Position.Bottom} className="!bg-muted-foreground/50" />
    </div>
  );
}

const nodeTypes: NodeTypes = { ouNode: OuFlowNode };

/**
 * `OuGraph` — the opt-in, top-down node-graph view of the OU hierarchy,
 * rendered with react-flow. Interchangeable with `OuTree` (same {@link OuViewProps}).
 *
 * Nodes are **freely draggable on the canvas for layout only** — clicking a node
 * selects it (opens the drawer), structural actions are reached via each node's
 * menu, and **connecting is disabled** (`nodesConnectable={false}` /
 * `connectable: false`) so dragging can never create or change a parent/child
 * relation. Re-parenting stays in the "Move to…" picker. Initial positions come
 * from the tidy-tree layout above; positions a user drags are preserved across
 * re-renders (selection, refetch) for the session. This component is dynamically
 * imported with `ssr:false` by the page so react-flow stays out of the main
 * bundle and never runs on the server.
 */
export default function OuGraph({ tree, selectedId, onSelect, onAction }: OuViewProps) {
  const [nodes, setNodes, onNodesChange] = useNodesState<Node<OuNodeData>>([]);

  // Computed tidy-tree positions, recomputed only when the tree structure changes.
  const layouted = useMemo(() => layout(tree).nodes, [tree]);

  // Build/refresh react-flow nodes. Positions a user has dragged are kept (keyed
  // by id) so selecting a node or a background refetch never snaps the canvas
  // back to the computed layout; only the node `data` (selection, callbacks) and
  // newly-appeared OUs pick up fresh values.
  useEffect(() => {
    setNodes((prev) => {
      const draggedPos = new Map(prev.map((n) => [n.id, n.position]));
      return layouted.map(({ node, x, y }) => ({
        id: String(node.id),
        type: 'ouNode',
        position: draggedPos.get(String(node.id)) ?? { x, y },
        data: { ou: node, selected: node.id === selectedId, onSelect, onAction },
        connectable: false,
      }));
    });
  }, [layouted, selectedId, onSelect, onAction, setNodes]);

  const edges = useMemo<Edge[]>(() => {
    const all = flattenOuTree(tree);
    return all
      .filter((node) => node.parent_id !== null && node.parent_id !== undefined)
      .map((node) => ({
        id: `e-${node.parent_id}-${node.id}`,
        source: String(node.parent_id),
        target: String(node.id),
        type: 'smoothstep',
      }));
  }, [tree]);

  const handleNodeClick = useCallback(
    (_: unknown, node: Node) => onSelect(Number(node.id)),
    [onSelect]
  );

  return (
    <div className="h-[32rem] w-full rounded-lg border border-border bg-card" data-testid="ou-graph">
      <ReactFlow
        nodes={nodes}
        edges={edges}
        nodeTypes={nodeTypes}
        onNodesChange={onNodesChange}
        onNodeClick={handleNodeClick}
        nodesDraggable
        nodesConnectable={false}
        elementsSelectable
        fitView
        proOptions={{ hideAttribution: true }}
      >
        <Background variant={BackgroundVariant.Dots} gap={16} size={1} />
        <Controls showInteractive={false} />
      </ReactFlow>
    </div>
  );
}
