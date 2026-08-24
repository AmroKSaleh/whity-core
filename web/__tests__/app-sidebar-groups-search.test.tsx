import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AppSidebar } from '@amroksaleh/ui/app-sidebar';
import type { AppSidebarNavGroup } from '@amroksaleh/ui/app-sidebar';

/**
 * Group disclosure and the filter box in the UI kit's `AppSidebar` — the two
 * halves of making a 22-item sidebar navigable (#1007).
 *
 * These live here rather than beside the component because `packages/ui` has
 * no Jest project of its own; web's is the only runner in the repo and its
 * `roots`/`moduleNameMapper` resolve `@amroksaleh/ui/*` into this checkout's
 * `packages/ui/src`, so the component under test is the real one.
 */

function groups(activeId?: string): AppSidebarNavGroup[] {
  const mark = (id: string) => ({ active: id === activeId });
  return [
    {
      id: 'overview',
      label: 'Overview',
      items: [
        { id: 'dashboard', label: 'Dashboard', href: '/admin', ...mark('dashboard') },
        { id: 'inbox', label: 'Inbox', href: '/admin/inbox', ...mark('inbox') },
      ],
    },
    {
      id: 'access',
      label: 'Access',
      items: [
        { id: 'users', label: 'Users', href: '/admin/users', ...mark('users') },
        { id: 'roles', label: 'Roles', href: '/admin/roles', ...mark('roles') },
      ],
    },
    {
      // No label — so it can never be collapsed away.
      id: '',
      items: [{ id: 'settings', label: 'Settings', href: '/settings', ...mark('settings') }],
    },
  ];
}

function groupToggle(name: string): HTMLElement {
  return screen.getByRole('button', { name: new RegExp(name, 'i') });
}

describe('AppSidebar — group disclosure', () => {
  it('opens only the group holding the active item', () => {
    render(<AppSidebar groups={groups('users')} collapsibleGroups />);

    expect(groupToggle('Access')).toHaveAttribute('aria-expanded', 'true');
    expect(groupToggle('Overview')).toHaveAttribute('aria-expanded', 'false');
    expect(screen.getByText('Users')).toBeVisible();
    expect(screen.getByText('Dashboard')).not.toBeVisible();
  });

  it('keeps a closed group’s items in the DOM but out of the accessibility tree', () => {
    // Both halves matter, and they pull in opposite directions.
    //
    // OUT OF THE A11Y TREE is the correctness requirement: collapsed content a
    // screen reader still reads is an accessibility bug, so `hidden` is right
    // and a role/name query — `getByRole` here, `page.getByRole` in Playwright
    // — cannot see the item.
    //
    // IN THE DOM is what keeps the markup honest: the item is still there, so
    // a DOM/CSS query distinguishes "collapsed" from "RBAC removed it". Any
    // suite asserting a link's ABSENCE as proof of permission filtering must
    // therefore either query by CSS or open the group first — see
    // `expandAllNavGroups` in web/e2e/support/pages.ts, which exists for
    // exactly this reason.
    const { container } = render(<AppSidebar groups={groups('users')} collapsibleGroups />);

    const dashboard = container.querySelector('a[href="/admin"]');
    expect(dashboard).not.toBeNull();
    expect(dashboard).not.toBeVisible();
    expect(screen.queryByRole('link', { name: 'Dashboard' })).not.toBeInTheDocument();
  });

  it('falls back to the first group when nothing is active', () => {
    render(<AppSidebar groups={groups()} collapsibleGroups />);

    expect(groupToggle('Overview')).toHaveAttribute('aria-expanded', 'true');
    expect(groupToggle('Access')).toHaveAttribute('aria-expanded', 'false');
  });

  it('opens the active group when the nav arrives AFTER the first render', () => {
    // The real sequence: the shell renders before `GET /api/navigation`
    // resolves, so the default cannot be computed once in a useState
    // initializer — it has to react to the groups appearing.
    const { rerender } = render(<AppSidebar groups={[]} collapsibleGroups />);
    expect(screen.queryByRole('button', { name: /Access/i })).not.toBeInTheDocument();

    rerender(<AppSidebar groups={groups('roles')} collapsibleGroups />);
    expect(groupToggle('Access')).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByText('Roles')).toBeVisible();
  });

  it('toggles a group open and closed on click', async () => {
    const user = userEvent.setup();
    render(<AppSidebar groups={groups('users')} collapsibleGroups />);

    await user.click(groupToggle('Overview'));
    expect(groupToggle('Overview')).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByText('Dashboard')).toBeVisible();

    await user.click(groupToggle('Overview'));
    expect(groupToggle('Overview')).toHaveAttribute('aria-expanded', 'false');
    expect(screen.getByText('Dashboard')).not.toBeVisible();
  });

  it('leaves a group the user opened by hand open when the active group changes', async () => {
    const user = userEvent.setup();
    const { rerender } = render(<AppSidebar groups={groups('users')} collapsibleGroups />);

    await user.click(groupToggle('Overview'));
    expect(groupToggle('Overview')).toHaveAttribute('aria-expanded', 'true');

    // Navigating WITHIN the already-open active group must not re-narrow.
    rerender(<AppSidebar groups={groups('roles')} collapsibleGroups />);
    expect(groupToggle('Overview')).toHaveAttribute('aria-expanded', 'true');
    expect(groupToggle('Access')).toHaveAttribute('aria-expanded', 'true');
  });

  it('never hides a group that has no heading', () => {
    render(<AppSidebar groups={groups('users')} collapsibleGroups />);

    expect(screen.getByText('Settings')).toBeVisible();
    expect(screen.queryByRole('button', { name: /^Settings$/ })).not.toBeInTheDocument();
  });

  it('renders every group open when collapsibleGroups is off', () => {
    render(<AppSidebar groups={groups('users')} />);

    expect(screen.getByText('Dashboard')).toBeVisible();
    expect(screen.getByText('Users')).toBeVisible();
    expect(screen.queryByRole('button', { name: /^Overview$/ })).not.toBeInTheDocument();
  });
});

describe('AppSidebar — search', () => {
  it('is absent unless asked for', () => {
    render(<AppSidebar groups={groups('users')} />);
    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
  });

  it('reaches an item inside a CLOSED group', async () => {
    const user = userEvent.setup();
    render(<AppSidebar groups={groups('users')} collapsibleGroups searchable />);

    expect(screen.getByText('Dashboard')).not.toBeVisible();

    await user.type(screen.getByRole('searchbox'), 'dash');

    // The whole point of the box: find a page without knowing its group.
    expect(screen.getByText('Dashboard')).toBeVisible();
  });

  it('drops items and groups that do not match', async () => {
    const user = userEvent.setup();
    render(<AppSidebar groups={groups('users')} collapsibleGroups searchable />);

    await user.type(screen.getByRole('searchbox'), 'role');

    expect(screen.getByText('Roles')).toBeVisible();
    expect(screen.queryByText('Users')).not.toBeInTheDocument();
    expect(screen.queryByText('Overview')).not.toBeInTheDocument();
  });

  it('matches case-insensitively and ignores surrounding whitespace', async () => {
    const user = userEvent.setup();
    render(<AppSidebar groups={groups('users')} collapsibleGroups searchable />);

    await user.type(screen.getByRole('searchbox'), '  USER  ');
    expect(screen.getByText('Users')).toBeVisible();
  });

  it('says so when nothing matches, instead of showing an empty nav', async () => {
    const user = userEvent.setup();
    render(
      <AppSidebar
        groups={groups('users')}
        collapsibleGroups
        searchable
        searchNoResultsLabel="No matching pages"
      />,
    );

    await user.type(screen.getByRole('searchbox'), 'zzzz');

    expect(screen.getByText('No matching pages')).toBeVisible();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
  });

  it('clears via the clear button, restoring the disclosure state', async () => {
    const user = userEvent.setup();
    render(
      <AppSidebar
        groups={groups('users')}
        collapsibleGroups
        searchable
        clearSearchLabel="Clear search"
      />,
    );

    await user.type(screen.getByRole('searchbox'), 'dash');
    expect(screen.getByText('Dashboard')).toBeVisible();

    await user.click(screen.getByRole('button', { name: 'Clear search' }));

    expect(screen.getByRole('searchbox')).toHaveValue('');
    expect(screen.getByText('Dashboard')).not.toBeVisible();
    expect(screen.getByText('Users')).toBeVisible();
  });

  it('clears on Escape', async () => {
    const user = userEvent.setup();
    render(<AppSidebar groups={groups('users')} collapsibleGroups searchable />);

    const box = screen.getByRole('searchbox');
    await user.type(box, 'dash');
    await user.type(box, '{Escape}');

    expect(box).toHaveValue('');
  });

  it('reports the query to a controlling parent', async () => {
    const user = userEvent.setup();
    const onSearchChange = jest.fn();
    render(
      <AppSidebar
        groups={groups('users')}
        searchable
        searchValue=""
        onSearchChange={onSearchChange}
      />,
    );

    await user.type(screen.getByRole('searchbox'), 'x');

    expect(onSearchChange).toHaveBeenCalledWith('x');
    // Controlled: the parent owns the value, so the box did not self-update.
    expect(screen.getByRole('searchbox')).toHaveValue('');
  });

  it('is hidden in the icon rail, where there is nowhere to put it', () => {
    render(<AppSidebar groups={groups('users')} collapsibleGroups searchable collapsed />);

    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
    // Collapsed hides the headings, so disclosure cannot apply — every item
    // stays reachable as an icon, with its label as the tooltip.
    expect(screen.getByRole('link', { name: 'Dashboard' })).toHaveAttribute('title', 'Dashboard');
  });
});
