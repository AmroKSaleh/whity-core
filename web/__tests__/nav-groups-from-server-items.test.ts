import {
  navGroupsFromServerItems,
  mostSpecificActiveItemId,
} from '@amroksaleh/features/nav';
import type { ServerNavItem } from '@amroksaleh/features/nav';

/**
 * Tests for the SERVER-DRIVEN half of the nav contract
 * (@amroksaleh/features/nav): turning `GET /api/navigation`'s flat,
 * RBAC-filtered item list into `AppSidebar`'s `AppSidebarNavGroup[]`.
 *
 * Three things here are regressions, not hypotheticals — each is a shape the
 * pre-grouping sidebar actually produced:
 *
 *  1. ungrouped items rendered FIRST, because the old grouping seeded its Map
 *     with the '_ungrouped' key before inserting anything else. The account
 *     "Settings" link registers with `order => 100` — plainly meant to be last
 *     — and appeared above every other link.
 *  2. group sequence was whatever the sort happened to produce, so it could not
 *     be stated, only observed.
 *  3. `order` collided (seven core items were all `order => 9`), leaving
 *     within-group sequence to sort stability and registration order.
 */

const GROUP_ORDER = ['overview', 'access', 'documents'] as const;

function item(partial: Partial<ServerNavItem> & Pick<ServerNavItem, 'id' | 'href'>): ServerNavItem {
  return {
    label: partial.id,
    order: 1,
    ...partial,
  };
}

describe('navGroupsFromServerItems — grouping and sequence', () => {
  it('emits groups in the declared order, not the order items arrived in', () => {
    const groups = navGroupsFromServerItems(
      [
        item({ id: 'users', href: '/admin/users', group: 'access' }),
        item({ id: 'dashboard', href: '/admin', group: 'overview' }),
        item({ id: 'docs', href: '/admin/documents', group: 'documents' }),
      ],
      { currentPath: '/nowhere', groupOrder: GROUP_ORDER },
    );

    expect(groups.map((group) => group.id)).toEqual(['overview', 'access', 'documents']);
  });

  it('skips declared groups that have no items', () => {
    const groups = navGroupsFromServerItems(
      [item({ id: 'users', href: '/admin/users', group: 'access' })],
      { currentPath: '/nowhere', groupOrder: GROUP_ORDER },
    );

    expect(groups.map((group) => group.id)).toEqual(['access']);
  });

  it('orders items WITHIN a group by `order`', () => {
    const groups = navGroupsFromServerItems(
      [
        item({ id: 'tenants', href: '/admin/tenants', group: 'access', order: 5 }),
        item({ id: 'users', href: '/admin/users', group: 'access', order: 1 }),
        item({ id: 'roles', href: '/admin/roles', group: 'access', order: 2 }),
      ],
      { currentPath: '/nowhere', groupOrder: GROUP_ORDER },
    );

    expect(groups[0]!.items.map((navItem) => navItem.id)).toEqual(['users', 'roles', 'tenants']);
  });

  it('breaks an `order` tie by label, so a collision is still deterministic', () => {
    const groups = navGroupsFromServerItems(
      [
        item({ id: 'b', label: 'Beta', href: '/b', group: 'access', order: 9 }),
        item({ id: 'a', label: 'Alpha', href: '/a', group: 'access', order: 9 }),
      ],
      { currentPath: '/nowhere', groupOrder: GROUP_ORDER },
    );

    expect(groups[0]!.items.map((navItem) => navItem.label)).toEqual(['Alpha', 'Beta']);
  });

  it('puts ungrouped items LAST and gives them no heading (the order-100 regression)', () => {
    const groups = navGroupsFromServerItems(
      [
        item({ id: 'settings', label: 'Settings', href: '/settings', order: 100 }),
        item({ id: 'users', href: '/admin/users', group: 'access' }),
      ],
      {
        currentPath: '/nowhere',
        groupOrder: GROUP_ORDER,
        groupLabel: () => 'Should not be used for the ungrouped bucket',
      },
    );

    expect(groups.map((group) => group.id)).toEqual(['access', '']);
    const trailing = groups[groups.length - 1]!;
    expect(trailing.items.map((navItem) => navItem.label)).toEqual(['Settings']);
    expect(trailing.label).toBeUndefined();
  });

  it('renders an UNDECLARED group after the declared ones rather than dropping it', () => {
    const groups = navGroupsFromServerItems(
      [
        item({ id: 'plugin-hello', href: '/admin/x/hello', group: 'plugins', order: 100 }),
        item({ id: 'users', href: '/admin/users', group: 'access' }),
      ],
      { currentPath: '/nowhere', groupOrder: GROUP_ORDER },
    );

    expect(groups.map((group) => group.id)).toEqual(['access', 'plugins']);
  });

  it('sequences several undeclared groups by their lowest item order', () => {
    const groups = navGroupsFromServerItems(
      [
        item({ id: 'late', href: '/late', group: 'zeta', order: 50 }),
        item({ id: 'early', href: '/early', group: 'alpha', order: 90 }),
        item({ id: 'earliest', href: '/earliest', group: 'zeta', order: 10 }),
      ],
      { currentPath: '/nowhere', groupOrder: [] },
    );

    // 'zeta' first despite sorting later alphabetically: its lowest order is 10.
    expect(groups.map((group) => group.id)).toEqual(['zeta', 'alpha']);
  });

  it('treats an empty-string group as ungrouped rather than as a group named ""', () => {
    const groups = navGroupsFromServerItems(
      [item({ id: 'settings', href: '/settings', group: '' })],
      { currentPath: '/nowhere', groupOrder: GROUP_ORDER, groupLabel: () => 'Nope' },
    );

    expect(groups).toHaveLength(1);
    expect(groups[0]!.label).toBeUndefined();
  });
});

describe('navGroupsFromServerItems — labels and icons', () => {
  it('takes each heading from the caller, keyed by group id', () => {
    const groups = navGroupsFromServerItems(
      [
        item({ id: 'users', href: '/admin/users', group: 'access' }),
        item({ id: 'dashboard', href: '/admin', group: 'overview' }),
      ],
      {
        currentPath: '/nowhere',
        groupOrder: GROUP_ORDER,
        groupLabel: (groupId) => (groupId === 'access' ? 'الوصول' : 'Overview'),
      },
    );

    expect(groups.map((group) => group.label)).toEqual(['Overview', 'الوصول']);
  });

  it('leaves a group unlabelled when the caller returns undefined for it', () => {
    const groups = navGroupsFromServerItems(
      [item({ id: 'users', href: '/admin/users', group: 'access' })],
      { currentPath: '/nowhere', groupOrder: GROUP_ORDER, groupLabel: () => undefined },
    );

    expect(groups[0]!.label).toBeUndefined();
  });

  it('resolves icons through the caller, and omits them when it has no renderer', () => {
    const withIcons = navGroupsFromServerItems(
      [item({ id: 'users', href: '/admin/users', group: 'access', icon: 'users' })],
      {
        currentPath: '/nowhere',
        groupOrder: GROUP_ORDER,
        renderIcon: (icon) => `icon:${icon}`,
      },
    );
    expect(withIcons[0]!.items[0]!.icon).toBe('icon:users');

    const withoutIcons = navGroupsFromServerItems(
      [item({ id: 'users', href: '/admin/users', group: 'access', icon: 'users' })],
      { currentPath: '/nowhere', groupOrder: GROUP_ORDER },
    );
    expect(withoutIcons[0]!.items[0]!.icon).toBeUndefined();
  });
});

describe('mostSpecificActiveItemId', () => {
  const items = [
    item({ id: 'dashboard', href: '/admin' }),
    item({ id: 'plugins', href: '/admin/plugins' }),
    item({ id: 'plugin-store', href: '/admin/plugins/store' }),
    item({ id: 'users', href: '/admin/users' }),
  ];

  it('prefers the exact match over a parent whose href is a prefix of it', () => {
    expect(mostSpecificActiveItemId(items, '/admin/plugins/store')).toBe('plugin-store');
  });

  it('prefix-matches a detail route onto its list item', () => {
    expect(mostSpecificActiveItemId(items, '/admin/users/5')).toBe('users');
  });

  it('picks the LONGEST prefix when several match', () => {
    expect(mostSpecificActiveItemId(items, '/admin/plugins/store/detail')).toBe('plugin-store');
  });

  it('never lets a single-segment href claim its children', () => {
    // Were /admin allowed to prefix-match, the dashboard would look current on
    // every admin page and no leaf item could ever highlight.
    expect(mostSpecificActiveItemId(items, '/admin/stats')).toBeNull();
    expect(mostSpecificActiveItemId(items, '/admin')).toBe('dashboard');
  });

  it('returns null when nothing matches', () => {
    expect(mostSpecificActiveItemId(items, '/somewhere-else')).toBeNull();
  });

  it('marks exactly one item active on the resolved groups', () => {
    const groups = navGroupsFromServerItems(
      [
        item({ id: 'plugins', href: '/admin/plugins', group: 'extend', order: 1 }),
        item({ id: 'plugin-store', href: '/admin/plugins/store', group: 'extend', order: 2 }),
      ],
      { currentPath: '/admin/plugins/store', groupOrder: [] },
    );

    const active = groups.flatMap((group) => group.items).filter((navItem) => navItem.active);
    expect(active.map((navItem) => navItem.id)).toEqual(['plugin-store']);
  });
});
