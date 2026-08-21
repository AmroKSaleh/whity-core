/**
 * #882: the role RECORD page — the first record page in the app.
 *
 * These tests pin the four things that distinguish a record page from a bigger
 * dialog, because each of them is a decision that could silently regress:
 *
 *  1. **The permissions editor has no scroll porthole.** The modal's picker put
 *     53+ permissions in a `max-h-80` popover nested inside a `max-h-[90vh]`
 *     dialog. The record page lays every group out at once. A `max-h-*` or
 *     `overflow-y-auto` reappearing anywhere inside the grid is the regression,
 *     so it is asserted against directly rather than inferred from a screenshot.
 *  2. **The record carries context a modal cannot** — the headcount and the
 *     most-recent assignment come from `getRoleAssignments`, whose `total` is
 *     the FULL count while `assignments` is only the page. Reading the count off
 *     `assignments.length` is the bug this endpoint exists to prevent.
 *  3. **Read-only is a state, not a disabled form.** Both gates — the caller's
 *     `roles:write` and the role's server-computed `manageable` — must produce a
 *     page with no inputs, and one that says why.
 *  4. **A missing `audit:read` hides the history panel** rather than showing an
 *     error, because that is an ungranted capability and not a failure.
 *
 * Driven through the data-source-agnostic `RoleRecordScreen`
 * (@amroksaleh/features/roles) with injected props, like the other roles tests
 * post Path-B extraction — not through web's provider stack.
 */

import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { RoleRecordScreen } from '@amroksaleh/features/roles';
import type {
  Permission,
  RoleWithPermissions,
  RolesAdapter,
  Transport,
} from '@amroksaleh/features/roles';
import { createRolesAdapter } from '@/lib/roles-adapter';

/** English-fallback translator: returns the caller-supplied source string. */
const t = (_key: string, fallback?: string, vars?: Record<string, string | number>): string => {
  const text = fallback ?? _key;
  if (!vars) return text;
  return Object.entries(vars).reduce(
    (acc, [k, v]) => acc.replaceAll(`{${k}}`, String(v)),
    text
  );
};

const CATALOGUE: Permission[] = [
  { id: 1, name: 'users:read', description: 'Read users' },
  { id: 2, name: 'users:write', description: 'Write users' },
  { id: 3, name: 'roles:read', description: 'Read roles' },
  { id: 4, name: 'roles:write', description: 'Write roles' },
  { id: 5, name: 'ous:read', description: 'Read OUs' },
];

const TENANT_ROLE: RoleWithPermissions = {
  id: 10,
  name: 'Support',
  description: 'Front-line support',
  createdAt: '2026-01-05T09:00:00Z',
  manageable: true,
  global: false,
  permissions: [CATALOGUE[0], CATALOGUE[2]],
};

const GLOBAL_ROLE: RoleWithPermissions = {
  ...TENANT_ROLE,
  id: 1,
  name: 'admin',
  description: 'Global base role',
  manageable: false,
  global: true,
};

function fakeAdapter(over: Partial<RolesAdapter> = {}): RolesAdapter {
  return {
    listRoles: jest.fn().mockResolvedValue([]),
    getRole: jest.fn().mockResolvedValue(TENANT_ROLE),
    getRolePermissions: jest.fn().mockResolvedValue([]),
    getRoleAssignments: jest.fn().mockResolvedValue({ assignments: [], total: 0 }),
    getRoleActivity: jest.fn().mockResolvedValue([]),
    listPermissions: jest.fn().mockResolvedValue(CATALOGUE),
    createRole: jest.fn().mockResolvedValue(undefined),
    updateRole: jest.fn().mockResolvedValue('ok'),
    deleteRole: jest.fn().mockResolvedValue('ok'),
    getCapabilities: jest.fn().mockResolvedValue([]),
    ...over,
  };
}

/** Caller holds every capability unless a test says otherwise. */
const canAll = () => true;

function renderRecord(props: Partial<React.ComponentProps<typeof RoleRecordScreen>> = {}) {
  const adapter = props.adapter ?? fakeAdapter();
  const onBack = props.onBack ?? jest.fn();
  render(
    <RoleRecordScreen
      adapter={adapter}
      roleId={props.roleId ?? 10}
      can={props.can ?? canAll}
      t={t}
      onNotify={props.onNotify}
      onBack={onBack}
    />
  );
  return { adapter, onBack };
}

describe('RoleRecordScreen — the record and its form', () => {
  it('loads the role and renders its fields in an editable form', async () => {
    const { adapter } = renderRecord();

    expect(await screen.findByDisplayValue('Support')).toBeInTheDocument();
    expect(screen.getByDisplayValue('Front-line support')).toBeInTheDocument();
    expect(adapter.getRole).toHaveBeenCalledWith(10);
    // Renders once per mount — a re-fetch loop here would show as a second call.
    expect(adapter.getRole).toHaveBeenCalledTimes(1);
  });

  it('shows the whole permission catalogue grouped by resource, with the role\'s own set ticked', async () => {
    renderRecord();

    await screen.findByTestId('perm-grid');
    // One section per resource prefix, all present at once.
    expect(screen.getByTestId('perm-grid-group-users')).toBeInTheDocument();
    expect(screen.getByTestId('perm-grid-group-roles')).toBeInTheDocument();
    expect(screen.getByTestId('perm-grid-group-ous')).toBeInTheDocument();

    const usersGroup = screen.getByTestId('perm-grid-group-users');
    const usersRead = within(usersGroup)
      .getByText('users:read')
      .closest('label')
      ?.querySelector('input[type="checkbox"]');
    const usersWrite = within(usersGroup)
      .getByText('users:write')
      .closest('label')
      ?.querySelector('input[type="checkbox"]');

    expect(usersRead).toBeChecked();
    expect(usersWrite).not.toBeChecked();
  });

  /**
   * THE ACUTE FIX, asserted structurally. The modal path renders
   * `max-h-80 overflow-y-auto` around the permission list; a record page that
   * reintroduced any height cap or nested scroll container would be the same
   * defect wearing a different route.
   */
  it('renders the permissions with no height cap and no nested scroll container', async () => {
    renderRecord();

    const grid = await screen.findByTestId('perm-grid');
    const offenders = grid.querySelectorAll(
      '[class*="max-h-"], [class*="overflow-y-auto"], [class*="overflow-auto"]'
    );
    expect(offenders).toHaveLength(0);
    expect(grid.className).not.toMatch(/max-h-|overflow-/);
  });

  it('filters the permission set without collapsing it into a scroll box', async () => {
    const user = userEvent.setup();
    renderRecord();

    await screen.findByTestId('perm-grid');
    await user.type(screen.getByTestId('perm-grid-search'), 'ous');

    expect(screen.getByTestId('perm-grid-group-ous')).toBeInTheDocument();
    expect(screen.queryByTestId('perm-grid-group-users')).not.toBeInTheDocument();
  });

  it('saves name, description and the permission set through the adapter', async () => {
    const user = userEvent.setup();
    const updateRole = jest.fn().mockResolvedValue('ok');
    const onNotify = jest.fn();
    renderRecord({ adapter: fakeAdapter({ updateRole }), onNotify });

    const nameInput = await screen.findByDisplayValue('Support');
    await user.clear(nameInput);
    await user.type(nameInput, 'Support Tier 2');

    const usersGroup = screen.getByTestId('perm-grid-group-users');
    const usersWrite = within(usersGroup).getByText('users:write');
    await user.click(usersWrite);

    await user.click(screen.getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(updateRole).toHaveBeenCalled());
    const [id, input] = updateRole.mock.calls[0];
    expect(id).toBe(10);
    expect(input.name).toBe('Support Tier 2');
    expect(input.description).toBe('Front-line support');
    expect([...input.permissions].sort((a: number, b: number) => a - b)).toEqual([1, 2, 3]);
    expect(onNotify).toHaveBeenCalledWith('Role updated successfully', 'success');
  });

  it('discards edits back to the loaded record without refetching', async () => {
    const user = userEvent.setup();
    const { adapter } = renderRecord();

    const nameInput = await screen.findByDisplayValue('Support');
    await user.clear(nameInput);
    await user.type(nameInput, 'Changed');

    await user.click(screen.getByRole('button', { name: 'Discard changes' }));

    expect(await screen.findByDisplayValue('Support')).toBeInTheDocument();
    expect(adapter.getRole).toHaveBeenCalledTimes(1);
  });

  it('surfaces the friendly explanation when a save comes back not-manageable (WC-222)', async () => {
    const user = userEvent.setup();
    const onNotify = jest.fn();
    renderRecord({
      adapter: fakeAdapter({ updateRole: jest.fn().mockResolvedValue('not-manageable') }),
      onNotify,
    });

    const nameInput = await screen.findByDisplayValue('Support');
    await user.type(nameInput, '!');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));

    await waitFor(() =>
      expect(onNotify).toHaveBeenCalledWith(
        "This role can't be modified by your tenant — global base roles are managed by the system tenant.",
        'error'
      )
    );
  });

  it('renders an error state with a way back when the record cannot be loaded', async () => {
    const onBack = jest.fn();
    const user = userEvent.setup();
    renderRecord({
      adapter: fakeAdapter({ getRole: jest.fn().mockRejectedValue(new Error('boom')) }),
      onBack,
    });

    expect(await screen.findByText('This role could not be loaded')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Back to roles' }));
    expect(onBack).toHaveBeenCalled();
  });
});

describe('RoleRecordScreen — context a modal cannot carry', () => {
  it('shows how many users hold the role, taking the count from the total and not the page', async () => {
    renderRecord({
      adapter: fakeAdapter({
        getRoleAssignments: jest.fn().mockResolvedValue({
          // Two rows on the page, twelve holders in the tenant. A page-length
          // count would say 2 — the exact wrongness this endpoint prevents.
          assignments: [
            {
              membershipId: 1,
              profileId: 3,
              tenantId: 1,
              displayName: 'user3',
              email: 'user3@example.test',
              ouId: null,
              isPrimary: true,
              status: 'active',
              assignedAt: '2026-08-14T10:00:00Z',
            },
            {
              membershipId: 2,
              profileId: 4,
              tenantId: 1,
              displayName: 'user4',
              email: 'user4@example.test',
              ouId: null,
              isPrimary: true,
              status: 'active',
              assignedAt: '2026-08-01T10:00:00Z',
            },
          ],
          total: 12,
        }),
      }),
    });

    expect(await screen.findByTestId('role-record-stat-holders')).toHaveTextContent('12');
    // The most recent assignment is the first row — "latest role assignment was
    // to user3".
    const holders = screen.getByTestId('role-record-holders');
    expect(within(holders).getAllByRole('listitem')[0]).toHaveTextContent('user3');
    expect(within(holders).getByText('and 10 more')).toBeInTheDocument();
  });

  it('says nobody holds the role rather than showing an empty panel', async () => {
    renderRecord();

    const holders = await screen.findByTestId('role-record-holders');
    expect(within(holders).getByText('Nobody holds this role yet.')).toBeInTheDocument();
    expect(await screen.findByTestId('role-record-stat-holders')).toHaveTextContent('0');
  });

  it('keeps the record usable when the holders panel fails', async () => {
    renderRecord({
      adapter: fakeAdapter({
        getRoleAssignments: jest.fn().mockRejectedValue(new Error('nope')),
      }),
    });

    // The record still rendered — a supplementary panel's failure is not the
    // page's failure.
    expect(await screen.findByDisplayValue('Support')).toBeInTheDocument();
    const holders = screen.getByTestId('role-record-holders');
    expect(within(holders).getByText('Failed to load who holds this role')).toBeInTheDocument();
  });

  it('renders the role history when the caller may read the audit trail', async () => {
    renderRecord({
      adapter: fakeAdapter({
        getRoleActivity: jest.fn().mockResolvedValue([
          {
            id: 7,
            action: 'role.updated',
            actorUserId: 42,
            createdAt: '2026-08-15T12:00:00Z',
            metadata: {},
          },
        ]),
      }),
    });

    const activity = await screen.findByTestId('role-record-activity');
    expect(within(activity).getByText('role.updated')).toBeInTheDocument();
    expect(within(activity).getByText(/by user 42/)).toBeInTheDocument();
  });

  it('OMITS the history panel entirely when the caller lacks audit:read', async () => {
    renderRecord({
      adapter: fakeAdapter({ getRoleActivity: jest.fn().mockResolvedValue('forbidden') }),
    });

    // The record loaded…
    expect(await screen.findByDisplayValue('Support')).toBeInTheDocument();
    // …and the panel is absent rather than present-and-complaining: an
    // ungranted capability is not an error.
    await waitFor(() =>
      expect(screen.queryByTestId('role-record-activity')).not.toBeInTheDocument()
    );
  });

  it('asks the adapter for the role activity by role id', async () => {
    const { adapter } = renderRecord({ roleId: 77 });

    await waitFor(() => expect(adapter.getRoleActivity).toHaveBeenCalled());
    expect((adapter.getRoleActivity as jest.Mock).mock.calls[0][0]).toBe(77);
  });
});

describe('RoleRecordScreen — read-only is a state, not a disabled form', () => {
  it('renders read-only, and says why, for a global base role (WC-110/WC-222)', async () => {
    renderRecord({ adapter: fakeAdapter({ getRole: jest.fn().mockResolvedValue(GLOBAL_ROLE) }) });

    expect(await screen.findByTestId('role-record-badge-global')).toHaveTextContent(
      'Global base role'
    );
    expect(
      screen.getByText('This is a global base role. Only the system tenant can change it.')
    ).toBeInTheDocument();

    // No form controls at all — not disabled ones.
    expect(screen.queryByDisplayValue('admin')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument();
    expect(document.querySelectorAll('input[type="checkbox"]')).toHaveLength(0);
  });

  it('renders read-only, and says why, for a caller without roles:write', async () => {
    renderRecord({ can: () => false });

    expect(
      await screen.findByText(
        "You don't have permission to edit roles, so this record is read-only."
      )
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument();
  });

  /**
   * A read-only page shows the ROLE'S permissions, never the installation's
   * whole catalogue — otherwise a viewer reads "this role can do everything".
   */
  it('shows only the role\'s own permissions when read-only', async () => {
    renderRecord({ adapter: fakeAdapter({ getRole: jest.fn().mockResolvedValue(GLOBAL_ROLE) }) });

    await screen.findByTestId('perm-grid');
    expect(screen.getByText('users:read')).toBeInTheDocument();
    expect(screen.getByText('roles:read')).toBeInTheDocument();
    // In the catalogue, not on the role.
    expect(screen.queryByText('users:write')).not.toBeInTheDocument();
    expect(screen.queryByText('ous:read')).not.toBeInTheDocument();
  });
});

/**
 * The adapter half of #882 — the wire translation the record screen never sees.
 * Driven through `createRolesAdapter` over a stub transport, the same way
 * `roles-modals-404.test.tsx` drives the 404 → 'not-manageable' mapping.
 */
describe('web roles adapter — the #882 additions', () => {
  /** A transport that always answers with one fixed `{ status, body }`. */
  function transportReturning(status: number, body: unknown = {}): Transport {
    return { request: jest.fn().mockResolvedValue({ status, body }) };
  }

  it('takes the headcount from pagination.total, never from the page length', async () => {
    const adapter = createRolesAdapter(
      transportReturning(200, {
        data: [{ membershipId: 1, profileId: 2, displayName: 'a' }],
        pagination: { page: 1, perPage: 1, total: 12, totalPages: 12 },
      })
    );

    const page = await adapter.getRoleAssignments(10, 1);
    expect(page.assignments).toHaveLength(1);
    expect(page.total).toBe(12);
  });

  it('reports a headcount of 0 rather than guessing when the envelope carries no pagination', async () => {
    const adapter = createRolesAdapter(transportReturning(200, { data: [] }));
    await expect(adapter.getRoleAssignments(10)).resolves.toEqual({ assignments: [], total: 0 });
  });

  it('asks for this role\'s audit entries by target type AND target id', async () => {
    const transport = transportReturning(200, { data: [] });
    const adapter = createRolesAdapter(transport);

    await adapter.getRoleActivity(10, 5);

    const [, path] = (transport.request as jest.Mock).mock.calls[0];
    expect(path).toContain('target_type=role');
    expect(path).toContain('target_id=10');
    expect(path).toContain('per_page=5');
  });

  it('maps a 403 on the audit trail to the "forbidden" sentinel, not an error', async () => {
    const adapter = createRolesAdapter(transportReturning(403));
    await expect(adapter.getRoleActivity(10)).resolves.toBe('forbidden');
  });

  it('still throws on a real audit failure, so it is not confused with an ungranted capability', async () => {
    const adapter = createRolesAdapter(transportReturning(500));
    await expect(adapter.getRoleActivity(10)).rejects.toThrow();
  });

  /**
   * `Role.createdAt` was declared while the API has always sent `created_at`.
   * Nothing read it until the record page showed "Created" — this pins the
   * normalization so the stat cannot silently go back to an em dash.
   */
  it('fills createdAt from the wire\'s created_at', async () => {
    const adapter = createRolesAdapter(
      transportReturning(200, {
        data: { id: 10, name: 'Support', description: '', created_at: '2026-01-05 09:00:00', permissions: [] },
      })
    );

    const role = await adapter.getRole(10);
    expect(role.createdAt).toBe('2026-01-05 09:00:00');
  });
});
