import type { OU } from './types';

/**
 * A node in the built OU hierarchy: the flat OU enriched with its resolved
 * `children` and its `depth` (root nodes are depth 0). Both the tree (`OuTree`)
 * and graph (`OuGraph`) renderers consume this shape.
 */
export interface OuNode extends OU {
  /** Direct child OUs, ordered by name. */
  children: OuNode[];
  /** Distance from a root (root = 0). */
  depth: number;
}

/**
 * Build the parent -> children hierarchy from a flat OU list.
 *
 * - Nodes are linked by `parent_id`. A node whose `parent_id` is `null` — or
 *   whose parent is not present in the input — becomes a root, so no OU is ever
 *   silently dropped.
 * - Roots and each node's children are sorted by name (case-insensitive) for a
 *   stable, readable rendering order.
 * - `depth` is assigned during a defensive traversal that guards against cyclic
 *   `parent_id` data (which the backend rejects, but corrupt rows must never
 *   hang the UI).
 *
 * The input array is not mutated.
 */
export function buildOuTree(flat: OU[]): OuNode[] {
  const byId = new Map<number, OuNode>();
  for (const ou of flat) {
    byId.set(ou.id, { ...ou, children: [], depth: 0 });
  }

  const roots: OuNode[] = [];
  for (const node of byId.values()) {
    const parent =
      node.parent_id !== null && node.parent_id !== undefined
        ? byId.get(node.parent_id)
        : undefined;
    if (parent) {
      parent.children.push(node);
    } else {
      roots.push(node);
    }
  }

  const byName = (a: OuNode, b: OuNode): number =>
    a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });

  // Defensive: with corrupt cyclic data (e.g. 1->2->1) no node has a missing
  // parent, so `roots` is empty even though nodes exist. The backend rejects
  // cycles, but a bad row must never make OUs vanish from the UI — promote any
  // node not reachable from an existing root (lowest id first) to a root so the
  // whole set is always rendered.
  const reachable = new Set<number>();
  const seed: OuNode[] = [...roots];
  while (seed.length > 0) {
    const node = seed.pop() as OuNode;
    if (reachable.has(node.id)) {
      continue;
    }
    reachable.add(node.id);
    seed.push(...node.children);
  }
  const promoted = [...byId.values()]
    .filter((n) => !reachable.has(n.id))
    .sort((a, b) => a.id - b.id);
  for (const node of promoted) {
    if (!reachable.has(node.id)) {
      roots.push(node);
      // Mark this subtree reachable so a sibling in the same cycle is not also
      // promoted to a second root.
      const mark: OuNode[] = [node];
      while (mark.length > 0) {
        const n = mark.pop() as OuNode;
        if (reachable.has(n.id)) {
          continue;
        }
        reachable.add(n.id);
        mark.push(...n.children);
      }
    }
  }

  roots.sort(byName);

  // Assign depths and sort children with an explicit stack so cyclic data
  // cannot cause unbounded recursion. A node is only descended into once.
  const visited = new Set<number>();
  const stack: OuNode[] = [...roots];
  while (stack.length > 0) {
    const node = stack.pop() as OuNode;
    if (visited.has(node.id)) {
      // Break the cycle: drop the back-edge so the node is not re-linked.
      continue;
    }
    visited.add(node.id);
    node.children = node.children.filter((c) => !visited.has(c.id));
    node.children.sort(byName);
    for (const child of node.children) {
      child.depth = node.depth + 1;
      stack.push(child);
    }
  }

  return roots;
}

/**
 * Collect `id` plus the ids of every descendant of the node with `id` in the
 * given tree (inclusive). Used to exclude an OU and its subtree from the
 * move-to-parent picker (a parent cannot be the OU itself or one of its
 * descendants) and to mirror the backend's cycle guard client-side.
 *
 * Returns an empty array if `id` is not present in the tree.
 */
export function getDescendantIds(tree: OuNode[], id: number): number[] {
  const node = findNode(tree, id);
  if (!node) {
    return [];
  }

  const ids: number[] = [];
  const visited = new Set<number>();
  const stack: OuNode[] = [node];
  while (stack.length > 0) {
    const current = stack.pop() as OuNode;
    if (visited.has(current.id)) {
      continue;
    }
    visited.add(current.id);
    ids.push(current.id);
    stack.push(...current.children);
  }
  return ids;
}

/** Depth-first lookup of a node by id, or `undefined` if absent. */
export function findNode(tree: OuNode[], id: number): OuNode | undefined {
  const visited = new Set<number>();
  const stack: OuNode[] = [...tree];
  while (stack.length > 0) {
    const node = stack.pop() as OuNode;
    if (visited.has(node.id)) {
      continue;
    }
    visited.add(node.id);
    if (node.id === id) {
      return node;
    }
    stack.push(...node.children);
  }
  return undefined;
}

/** Flatten a tree into a single list of every node (pre-order, cycle-safe). */
export function flattenOuTree(tree: OuNode[]): OuNode[] {
  const out: OuNode[] = [];
  const visited = new Set<number>();
  const stack: OuNode[] = [...tree];
  while (stack.length > 0) {
    const node = stack.pop() as OuNode;
    if (visited.has(node.id)) {
      continue;
    }
    visited.add(node.id);
    out.push(node);
    stack.push(...node.children);
  }
  return out;
}
