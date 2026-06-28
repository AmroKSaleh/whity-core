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
import { Button } from '@whity/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { IconDotsVertical, IconUser, IconUserOff } from '@tabler/icons-react';
import type { PersonAction, RelationsGraphProps } from './relations-view';
import type { Person } from './types';

const NODE_WIDTH = 190;
const NODE_HEIGHT = 56;
/** Radius growth per node so larger families spread further apart. */
const RADIUS_STEP = 34;
const BASE_RADIUS = 180;

/** Data carried on each react-flow node so the custom node can render + act. */
interface PersonNodeData extends Record<string, unknown> {
  person: Person;
  selected: boolean;
  onSelect: (id: number) => void;
  onAction: (action: PersonAction, person: Person) => void;
  /** When false the per-node write action menu is hidden (WC-177). */
  canManage: boolean;
}

/**
 * Best-effort general-graph layout.
 *
 * A family is NOT a single-parent hierarchy, so the OU hub's tidy-tree layout is
 * deliberately NOT reused (it assumes one parent per node and would mis-place
 * spouses/siblings/multi-parent links). Instead nodes are spread evenly around a
 * circle whose radius grows with the node count; the user then drags nodes into
 * a meaningful arrangement (positions are preserved for the session). This keeps
 * v1 dependency-free (no dagre/elk) and honest about the graph being general.
 */
function layout(persons: Person[]): Array<{ person: Person; x: number; y: number }> {
  const count = persons.length;
  if (count === 0) {
    return [];
  }
  if (count === 1) {
    return [{ person: persons[0], x: 0, y: 0 }];
  }

  const radius = BASE_RADIUS + count * RADIUS_STEP;
  return persons.map((person, index) => {
    const angle = (index / count) * 2 * Math.PI;
    return {
      person,
      x: Math.round(Math.cos(angle) * radius),
      y: Math.round(Math.sin(angle) * radius),
    };
  });
}

/** Custom react-flow node: a selectable card marked account vs non-user. */
function PersonFlowNode({ data }: NodeProps<Node<PersonNodeData>>) {
  const { person, selected, onSelect, onAction, canManage } = data;
  const Icon = person.hasAccount ? IconUser : IconUserOff;

  return (
    <div
      className={cn(
        'flex items-center justify-between gap-1 rounded-lg border bg-card px-2.5 py-2 text-xs/relaxed shadow-sm transition-colors',
        selected ? 'border-primary ring-2 ring-ring/30' : 'border-border hover:border-muted-foreground/40'
      )}
      style={{ width: NODE_WIDTH, height: NODE_HEIGHT }}
    >
      {/* A general graph has no fixed direction; expose handles on all sides so
          bezier edges can attach cleanly regardless of relative position. */}
      <Handle type="target" position={Position.Top} className="!bg-muted-foreground/50" />
      <Handle type="source" position={Position.Bottom} className="!bg-muted-foreground/50" />
      <Icon
        className={cn('size-4 flex-shrink-0', person.hasAccount ? 'text-primary' : 'text-muted-foreground')}
        aria-hidden
      />
      <button
        type="button"
        onClick={() => onSelect(person.id)}
        className="min-w-0 flex-1 truncate text-start font-medium text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
      >
        {person.displayName}
      </button>
      {/* The action menu items are ALL writes (Add relation / Edit / Delete),
          so the whole menu is hidden unless the caller holds relations:manage
          (WC-177). Selecting the node (the name button above) stays available
          to any reader. */}
      {canManage && (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="icon-sm"
              aria-label={`Actions for ${person.displayName}`}
              className="nodrag"
            >
              <IconDotsVertical />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onClick={() => onAction('add-relation', person)}>
              Add relation
            </DropdownMenuItem>
            {!person.hasAccount && (
              <DropdownMenuItem onClick={() => onAction('edit', person)}>Edit</DropdownMenuItem>
            )}
            {!person.hasAccount && (
              <>
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onClick={() => onAction('delete', person)}>
                  Delete
                </DropdownMenuItem>
              </>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      )}
    </div>
  );
}

const nodeTypes: NodeTypes = { personNode: PersonFlowNode };

/**
 * `RelationsGraph` — the family graph view, rendered with react-flow, reusing the
 * OU hub's polished graph composition (custom selectable nodes with a per-node
 * action menu, draggable canvas, Controls + dotted Background, attribution
 * hidden). Adaptations for a GENERAL graph (per the ADR):
 *
 *  - Layout is a best-effort circular spread (not the OU tidy-tree), since a
 *    family has no single-parent hierarchy; the user drags nodes to arrange.
 *  - Edges are **bezier** (`type: 'default'`) and **type-labelled** with the
 *    relationship from the stored `from` person's perspective.
 *  - Connecting is disabled so dragging can never create/alter a relation;
 *    relations are added via the node menu / drawer.
 *
 * Dynamically imported with `ssr:false` by the page so react-flow stays out of
 * the main bundle and never runs on the server.
 */
export default function RelationsGraph({
  persons,
  edges,
  selectedId,
  onSelect,
  onAction,
  canManage,
}: RelationsGraphProps) {
  const [nodes, setNodes, onNodesChange] = useNodesState<Node<PersonNodeData>>([]);

  // Computed positions, recomputed only when the set of persons changes.
  const layouted = useMemo(() => layout(persons), [persons]);

  // Build/refresh react-flow nodes. Positions a user has dragged are kept (keyed
  // by id) so selecting a node or a refetch never snaps the canvas back; only the
  // node data (selection, callbacks) and newly-appeared persons pick up fresh
  // values.
  useEffect(() => {
    setNodes((prev) => {
      const draggedPos = new Map(prev.map((n) => [n.id, n.position]));
      return layouted.map(({ person, x, y }) => ({
        id: String(person.id),
        type: 'personNode',
        position: draggedPos.get(String(person.id)) ?? { x, y },
        data: { person, selected: person.id === selectedId, onSelect, onAction, canManage },
        connectable: false,
      }));
    });
  }, [layouted, selectedId, onSelect, onAction, canManage, setNodes]);

  const flowEdges = useMemo<Edge[]>(
    () =>
      edges.map((edge) => ({
        id: `e-${edge.id}`,
        source: String(edge.fromPersonId),
        target: String(edge.toPersonId),
        // 'default' is react-flow's bezier edge: smooth curves between nodes.
        type: 'default',
        // Label the edge with the stored type (from the `from` person's side).
        label: edge.typeName,
        labelBgPadding: [6, 2] as [number, number],
      })),
    [edges]
  );

  const handleNodeClick = useCallback(
    (_: unknown, node: Node) => onSelect(Number(node.id)),
    [onSelect]
  );

  return (
    <div className="h-[32rem] w-full rounded-lg border border-border bg-card" data-testid="relations-graph">
      <ReactFlow
        nodes={nodes}
        edges={flowEdges}
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
