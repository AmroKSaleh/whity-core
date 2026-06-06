'use client';

import { useCallback, useMemo } from 'react';
import {
  Background,
  BackgroundVariant,
  Controls,
  Handle,
  Position,
  ReactFlow,
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
 * Hand-rolled, dependency-free layered layout.
 *
 * Nodes are bucketed by depth (one horizontal row per depth, y = depth * V_GAP).
 * Within a depth, nodes are spread evenly by their index in that depth's bucket
 * (x = index * (NODE_WIDTH + H_GAP)), then the whole layer is centred so the
 * tree reads top-down and roughly balanced. This is the documented v1 approach
 * (no dagre/elk).
 */
function layout(tree: OuNode[]): { nodes: Array<{ node: OuNode; x: number; y: number }> } {
  const all = flattenOuTree(tree);
  const byDepth = new Map<number, OuNode[]>();
  for (const node of all) {
    const bucket = byDepth.get(node.depth) ?? [];
    bucket.push(node);
    byDepth.set(node.depth, bucket);
  }

  const widest = Math.max(1, ...[...byDepth.values()].map((b) => b.length));
  const totalWidth = widest * (NODE_WIDTH + H_GAP);

  const positioned: Array<{ node: OuNode; x: number; y: number }> = [];
  for (const [depth, bucket] of byDepth) {
    const layerWidth = bucket.length * (NODE_WIDTH + H_GAP);
    const offset = (totalWidth - layerWidth) / 2;
    bucket.forEach((node, index) => {
      positioned.push({
        node,
        x: offset + index * (NODE_WIDTH + H_GAP),
        y: depth * V_GAP,
      });
    });
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
 * Nodes are **select-only** (no drag-to-reparent): dragging and connecting are
 * disabled, clicking a node selects it (opens the drawer), and structural
 * actions are reached via each node's menu. Positions come from the hand-rolled
 * layered layout above. This component is dynamically imported with `ssr:false`
 * by the page so react-flow stays out of the main bundle and never runs on the
 * server.
 */
export default function OuGraph({ tree, selectedId, onSelect, onAction }: OuViewProps) {
  const nodes = useMemo<Array<Node<OuNodeData>>>(() => {
    const { nodes: positioned } = layout(tree);
    return positioned.map(({ node, x, y }) => ({
      id: String(node.id),
      type: 'ouNode',
      position: { x, y },
      data: { ou: node, selected: node.id === selectedId, onSelect, onAction },
      draggable: false,
      connectable: false,
    }));
  }, [tree, selectedId, onSelect, onAction]);

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
        onNodeClick={handleNodeClick}
        nodesDraggable={false}
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
