import type { Person, RelationEdge } from './types';

/**
 * The actions a person node's menu can emit. Both views (the list and the graph)
 * raise the same set so the page handles them uniformly — mirrors the OU hub's
 * `OuAction`.
 */
export type PersonAction = 'edit' | 'delete' | 'add-relation';

/**
 * The shared prop contract for the graph renderer. The page passes the persons
 * (nodes) + edges and wires selection / actions; selecting a node opens the
 * shared detail drawer, exactly like the OU hub.
 */
export interface RelationsGraphProps {
  /** All persons in the tenant (graph nodes). */
  persons: Person[];
  /** All stored relation edges (canonical direction). */
  edges: RelationEdge[];
  /** The currently selected person id, or null when nothing is selected. */
  selectedId: number | null;
  /** Emitted when a node is selected (click). */
  onSelect: (id: number) => void;
  /** Emitted when a node's action menu item is chosen. */
  onAction: (action: PersonAction, person: Person) => void;
  /**
   * Whether the caller holds `relations:manage` (WC-177). When false the
   * per-node write action menu is hidden; node selection (read) is unaffected.
   */
  canManage: boolean;
}
