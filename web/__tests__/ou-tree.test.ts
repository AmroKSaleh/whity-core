import { buildOuTree, getDescendantIds, flattenOuTree, type OuNode } from '@/app/(protected)/admin/ous/ou-tree-util';
import type { OU } from '@/app/(protected)/admin/ous/types';

/** Build a minimal OU flat record for tests. */
function ou(id: number, parentId: number | null, name: string): OU {
  return {
    id,
    tenant_id: 1,
    parent_id: parentId,
    name,
    slug: name.toLowerCase(),
    description: '',
    created_at: '2026-01-01 00:00:00',
  };
}

/**
 * Seeded hierarchy used across the tests:
 *   1 Engineering
 *     ├─ 2 Backend
 *     │    └─ 4 Platform
 *     └─ 3 Frontend
 *   5 Sales (root)
 */
const FLAT: OU[] = [
  ou(1, null, 'Engineering'),
  ou(2, 1, 'Backend'),
  ou(3, 1, 'Frontend'),
  ou(4, 2, 'Platform'),
  ou(5, null, 'Sales'),
];

describe('buildOuTree', () => {
  it('builds roots and nests children by parent_id', () => {
    const tree = buildOuTree(FLAT);

    expect(tree.map((n) => n.id).sort()).toEqual([1, 5]);

    const engineering = tree.find((n) => n.id === 1) as OuNode;
    expect(engineering.children.map((c) => c.id).sort()).toEqual([2, 3]);

    const backend = engineering.children.find((c) => c.id === 2) as OuNode;
    expect(backend.children.map((c) => c.id)).toEqual([4]);

    const sales = tree.find((n) => n.id === 5) as OuNode;
    expect(sales.children).toEqual([]);
  });

  it('annotates each node with its depth (roots at 0)', () => {
    const tree = buildOuTree(FLAT);
    const engineering = tree.find((n) => n.id === 1) as OuNode;
    const backend = engineering.children.find((c) => c.id === 2) as OuNode;
    const platform = backend.children.find((c) => c.id === 4) as OuNode;

    expect(engineering.depth).toBe(0);
    expect(backend.depth).toBe(1);
    expect(platform.depth).toBe(2);
  });

  it('orders siblings and roots by name (case-insensitive)', () => {
    const tree = buildOuTree([
      ou(1, null, 'Zebra'),
      ou(2, null, 'apple'),
      ou(3, null, 'Mango'),
    ]);
    expect(tree.map((n) => n.name)).toEqual(['apple', 'Mango', 'Zebra']);
  });

  it('returns an empty array for empty input', () => {
    expect(buildOuTree([])).toEqual([]);
  });

  it('treats an OU whose parent is missing from the set as a root (no orphan loss)', () => {
    // parent_id 99 does not exist in the flat list -> the node must still appear.
    const tree = buildOuTree([ou(1, 99, 'Orphan'), ou(2, null, 'Root')]);
    expect(tree.map((n) => n.id).sort()).toEqual([1, 2]);
    const orphan = tree.find((n) => n.id === 1) as OuNode;
    expect(orphan.depth).toBe(0);
  });

  it('does not loop forever on a cyclic parent_id (defensive)', () => {
    // Corrupt data: 1 -> 2 -> 1. The builder must terminate and surface both
    // nodes without infinite recursion.
    const tree = buildOuTree([ou(1, 2, 'A'), ou(2, 1, 'B')]);
    const ids = flattenOuTree(tree).map((n) => n.id).sort();
    expect(ids).toEqual([1, 2]);
  });
});

describe('getDescendantIds', () => {
  it('returns the id itself plus every descendant id', () => {
    const tree = buildOuTree(FLAT);
    const ids = getDescendantIds(tree, 1).sort((a, b) => a - b);
    // Engineering(1) + Backend(2) + Frontend(3) + Platform(4)
    expect(ids).toEqual([1, 2, 3, 4]);
  });

  it('returns just the id for a leaf', () => {
    const tree = buildOuTree(FLAT);
    expect(getDescendantIds(tree, 4)).toEqual([4]);
  });

  it('returns just the id for a childless root', () => {
    const tree = buildOuTree(FLAT);
    expect(getDescendantIds(tree, 5)).toEqual([5]);
  });

  it('returns an empty array when the id is not in the tree', () => {
    const tree = buildOuTree(FLAT);
    expect(getDescendantIds(tree, 999)).toEqual([]);
  });
});

describe('flattenOuTree', () => {
  it('returns every node in the tree', () => {
    const tree = buildOuTree(FLAT);
    expect(flattenOuTree(tree).map((n) => n.id).sort((a, b) => a - b)).toEqual([1, 2, 3, 4, 5]);
  });
});
