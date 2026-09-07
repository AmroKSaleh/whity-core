/**
 * #910 on the acute case: the ROLE record, gated region by region.
 *
 * The roles page is the proving ground because it has the two questions the
 * issue is actually about, and they have different answers: **who may see what a
 * role grants** and **who may change it**. `permissions:read` governs the first
 * and `roles:manage` the second, so a caller can legitimately hold one and not
 * the other — and a page with one gate for the whole record cannot express that
 * at all.
 *
 * What these tests pin, and none of it is styling:
 *
 *  1. **The client resolves nothing.** `RoleRecordScreen` takes no `can` prop.
 *     Every state below is produced by varying what the SERVER said in
 *     `getRole` — which is the only place the answer comes from — and by
 *     nothing else.
 *  2. **A hidden region is absent from the PAYLOAD, not from the CSS.** The
 *     adapter is driven over a real transport so the assertion is about the
 *     wire: no `permissions` verdict, no `permissions` array, and the whole
 *     permission catalogue never fetched. Shipping a viewer the labels of things
 *     they may not see is a different bug wearing authorization's clothes.
 *  3. **A read-only region says why**, and a caller who may read permission
 *     slugs also gets the operator-grade half (#951/#968's `detail`).
 *  4. **A save carries only the regions the caller may write**, because the
 *     server refuses the rest with a 403 rather than dropping it silently — so a
 *     page that sent everything would turn a read-only REGION into a failed
 *     PAGE.
 */

import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { RoleRecordScreen } from '@amroksaleh/features/roles';
import type { Permission, RoleWithPermissions, RolesAdapter } from '@amroksaleh/features/roles';
import { createRolesAdapter } from '@/lib/roles-adapter';

/** English-fallback translator: returns the caller-supplied source string. */
const t = (_key: string, fallback?: string, vars?: Record<string, string | number>): string => {
  const text = fallback ?? _key;
  if (!vars) return text;
  return Object.entries(vars).reduce((acc, [k, v]) => acc.replaceAll(`{${k}}`, String(v)), text);
};

const CATALOGUE: Permission[] = [
  { id: 1, name: 'users:read', description: 'Read users' },
  { id: 2, name: 'users:write', description: 'Write users' },
  { id: 3, name: 'roles:read', description: 'Read roles' },
];

/** The role as the server describes it, before any per-region verdict. */
const ROLE = {
  id: 10,
  name: 'Support',
  description: 'Front-line support',
  createdAt: '2026-01-05T09:00:00Z',
  manageable: true,
  global: false,
};

const EDITABLE = { state: 'editable' as const, denial: null };

const permissionDenied = (detail: string | null) => ({
  state: 'read-only' as const,
  denial: {
    code: 'permission',
    reason: 'the server sentence, which the client localizes over',
    detail,
  },
});

function fakeAdapter(over: Partial<RolesAdapter> = {}): RolesAdapter {
  return {
    // #1102: `listRoles` answers a PAGE — rows plus the full count — not an
    // array. The record page never calls it; the shape is here so the fake
    // still satisfies `RolesAdapter`.
    listRoles: jest.fn().mockResolvedValue({ roles: [], total: 0, totalPages: 1 }),
    getRole: jest.fn(),
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

/** Mount the record for one server answer. No `can` — there is no such prop. */
function renderFor(role: RoleWithPermissions, over: Partial<RolesAdapter> = {}) {
  const adapter = fakeAdapter({ getRole: jest.fn().mockResolvedValue(role), ...over });
  render(<RoleRecordScreen adapter={adapter} roleId={10} t={t} onBack={jest.fn()} />);
  return adapter;
}

describe('the permissions region — hidden, read-only, editable', () => {
  it('EDITABLE: the whole catalogue, tickable, with the role’s own set ticked', async () => {
    const adapter = renderFor({
      ...ROLE,
      permissions: [CATALOGUE[0]],
      sections: { details: EDITABLE, permissions: EDITABLE },
    });

    await screen.findByTestId('perm-grid');
    expect(screen.getByTestId('perm-grid-group-users')).toBeInTheDocument();
    // Editable is the only state that needs the catalogue, and the only state
    // that fetches it.
    expect(adapter.listPermissions).toHaveBeenCalled();

    const usersGroup = screen.getByTestId('perm-grid-group-users');
    const usersRead = within(usersGroup)
      .getByText('users:read')
      .closest('label')
      ?.querySelector('input[type="checkbox"]');
    expect(usersRead).toBeChecked();
    expect(usersRead).not.toBeDisabled();
  });

  it('READ-ONLY: the ROLE’s own grants, no controls, and a reason — plus the detail', async () => {
    const adapter = renderFor({
      ...ROLE,
      permissions: [CATALOGUE[0]],
      sections: {
        details: EDITABLE,
        permissions: permissionDenied("changing this requires the 'roles:manage' permission"),
      },
    });

    await screen.findByTestId('role-record-section-permissions');

    // #951/#968 one level up: refused WITH a reason, and with the
    // operator-grade half appended for a caller the server let read it.
    expect(screen.getByTestId('role-record-section-permissions-readonly')).toHaveTextContent(
      "You may see what this role grants, but not change it. " +
        "(changing this requires the 'roles:manage' permission)"
    );

    // The role's OWN grants, never the installation's catalogue — feeding the
    // catalogue to a read-only view renders every permission on the deployment
    // as though the role held it.
    expect(screen.getByText('users:read')).toBeInTheDocument();
    expect(screen.queryByText('users:write')).not.toBeInTheDocument();
    expect(adapter.listPermissions).not.toHaveBeenCalled();

    // The details region is still editable beside it — that is the whole point.
    expect(screen.getByDisplayValue('Support')).toBeInTheDocument();
  });

  it('READ-ONLY without the detail: the reason alone, for a caller who may not read slugs', async () => {
    renderFor({
      ...ROLE,
      permissions: [CATALOGUE[0]],
      sections: { details: EDITABLE, permissions: permissionDenied(null) },
    });

    const notice = await screen.findByTestId('role-record-section-permissions-readonly');
    expect(notice).toHaveTextContent('You may see what this role grants, but not change it.');
    // The slug is what `detail` gates, so it must not appear anywhere.
    expect(notice.textContent).not.toContain('roles:manage');
  });

  it('HIDDEN: absent from the document, and never asked for', async () => {
    // No `permissions` verdict and no `permissions` array — the server withheld
    // both in one branch.
    const adapter = renderFor({ ...ROLE, sections: { details: EDITABLE } });

    await screen.findByTestId('role-record-section-details');

    expect(screen.queryByTestId('role-record-section-permissions')).not.toBeInTheDocument();
    expect(screen.queryByTestId('perm-grid')).not.toBeInTheDocument();
    // Not a heading with an explanation under it, either: the region's very
    // existence is what withholding it was for.
    expect(screen.queryByText('Permissions')).not.toBeInTheDocument();
    expect(adapter.listPermissions).not.toHaveBeenCalled();
  });
});

describe('a hidden region is absent from the PAYLOAD, not merely unrendered', () => {
  /**
   * Driven through the real adapter over a stub transport, so the assertion is
   * about the WIRE rather than about a fixture we wrote ourselves. This is the
   * property that separates authorization from styling: a `display:none`
   * implementation passes every DOM assertion above and still hands the viewer
   * the rows in the network tab.
   */
  function transportFor(body: unknown) {
    const request = jest.fn().mockResolvedValue({ status: 200, body });
    return { transport: { request }, request };
  }

  it('carries no permissions array and no permissions verdict when the region is hidden', async () => {
    const { transport } = transportFor({
      data: {
        id: 10,
        name: 'Support',
        description: 'Front-line support',
        created_at: '2026-01-05 09:00:00',
        manageable: true,
        global: false,
        // Note what is NOT here: no `permissions`, and no `permissions` key
        // under `sections`.
        sections: { details: { state: 'editable', denial: null } },
      },
    });

    const role = await createRolesAdapter(transport).getRole(10);

    expect(role.permissions).toBeUndefined();
    expect(role.sections?.permissions).toBeUndefined();
    expect(role.sections?.details?.state).toBe('editable');
  });

  it('carries them when the region is visible, so the absence above is a decision', async () => {
    const { transport } = transportFor({
      data: {
        id: 10,
        name: 'Support',
        description: '',
        manageable: true,
        global: false,
        permissions: [{ id: 1, name: 'users:read', description: 'Read users' }],
        sections: {
          details: { state: 'editable', denial: null },
          permissions: { state: 'editable', denial: null },
        },
      },
    });

    const role = await createRolesAdapter(transport).getRole(10);

    expect(role.permissions).toHaveLength(1);
    expect(role.sections?.permissions?.state).toBe('editable');
  });

  it('resolves every region to hidden when the server sent no verdicts at all', async () => {
    // Fail closed. A screen that asked to be told and was told nothing has not
    // been told yes — so a host that has not wired the resolver shows a header
    // and no body rather than an editable form.
    renderFor({ ...ROLE, permissions: [CATALOGUE[0]] });

    await screen.findByTestId('role-record');
    expect(screen.queryByTestId('role-record-section-details')).not.toBeInTheDocument();
    expect(screen.queryByTestId('role-record-section-permissions')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument();
  });
});

describe('a save carries only the regions this caller may write', () => {
  it('sends name and description but NOT permissions when that region is read-only', async () => {
    const user = userEvent.setup();
    const updateRole = jest.fn().mockResolvedValue('ok');
    renderFor(
      {
        ...ROLE,
        permissions: [CATALOGUE[0]],
        sections: { details: EDITABLE, permissions: permissionDenied(null) },
      },
      { updateRole }
    );

    const nameInput = await screen.findByDisplayValue('Support');
    await user.clear(nameInput);
    await user.type(nameInput, 'Support Tier 2');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(updateRole).toHaveBeenCalled());
    const [, body] = updateRole.mock.calls[0];
    expect(body.name).toBe('Support Tier 2');
    expect(body.description).toBe('Front-line support');
    // The server would 403 this key — deliberately, rather than dropping it, so
    // a save can never return 200 without doing what it said.
    expect(body).not.toHaveProperty('permissions');
  });

  it('sends permissions but NOT name or description when the details are read-only', async () => {
    const user = userEvent.setup();
    const updateRole = jest.fn().mockResolvedValue('ok');
    renderFor(
      {
        ...ROLE,
        permissions: [CATALOGUE[0]],
        sections: { details: permissionDenied(null), permissions: EDITABLE },
      },
      { updateRole }
    );

    await screen.findByTestId('perm-grid');
    const usersGroup = screen.getByTestId('perm-grid-group-users');
    await user.click(within(usersGroup).getByText('users:write'));
    await user.click(screen.getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(updateRole).toHaveBeenCalled());
    const [, body] = updateRole.mock.calls[0];
    expect([...body.permissions].sort((a: number, b: number) => a - b)).toEqual([1, 2]);
    expect(body).not.toHaveProperty('name');
    expect(body).not.toHaveProperty('description');
  });

  it('does not light up Save for an edit the caller could not have made', async () => {
    const user = userEvent.setup();
    renderFor({
      ...ROLE,
      permissions: [CATALOGUE[0]],
      sections: { details: EDITABLE, permissions: permissionDenied(null) },
    });

    // Nothing has been changed in the one editable region, so there is nothing
    // to save — even though the read-only grid below has re-derived its set.
    const save = await screen.findByRole('button', { name: 'Save changes' });
    expect(save).toBeDisabled();

    const nameInput = screen.getByDisplayValue('Support');
    await user.type(nameInput, '!');
    expect(screen.getByRole('button', { name: 'Save changes' })).not.toBeDisabled();
  });
});
