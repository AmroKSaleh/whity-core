import type { OuNode } from './ou-tree-util';

/**
 * The actions a node's per-node menu can emit. Both view renderers (`OuTree`,
 * `OuGraph`) raise the same set so the page can handle them uniformly.
 */
export type OuAction = 'create-child' | 'edit' | 'move' | 'delete';

/**
 * The shared prop contract for the two interchangeable hierarchy renderers
 * (`OuTree` and `OuGraph`). The page swaps the component behind the view toggle
 * without changing how it wires selection or actions.
 */
export interface OuViewProps {
  /** The built OU hierarchy (roots, each with nested children + depth). */
  tree: OuNode[];
  /** The currently selected OU id, or null when nothing is selected. */
  selectedId: number | null;
  /** Emitted when a node is selected (click / keyboard). */
  onSelect: (id: number) => void;
  /** Emitted when a node's action menu item is chosen. */
  onAction: (action: OuAction, node: OuNode) => void;
}
