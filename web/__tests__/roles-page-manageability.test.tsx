/**
 * WC-222: the roles admin gates per-row Edit/Delete on tenant manageability.
 *
 * `PATCH/DELETE /api/v1/roles/{id}` returns 404 by design for a GLOBAL base
 * role (NULL tenant_id) when the caller is a regular (non-system) tenant — only
 * the system tenant may manage global roles (WC-110). The list surfaces a
 * per-row `manageable` flag; `RolesScreen` renders Edit/Delete DISABLED with an
 * explanatory tooltip (native `title`) when `!manageable`, and a disabled item
 * must not open its modal. The capability gate (ROLES_WRITE / ROLES_DELETE) and
 * the manageability gate BOTH apply.
 *
 * Post Path-B extraction, this gating lives in the data-source-agnostic
 * `RolesScreen` (@amroksaleh/features/roles), so the test drives it directly
 * through injected props (adapter/can/t) rather than web's provider stack.
 */

import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { RolesScreen } from '@amroksaleh/features/roles';
import type { Role, RolesAdapter } from '@amroksaleh/features/roles';

const MANAGEABLE_ROLE: Role = {
  id: 10,
  name: 'TenantCustom',
  description: 'A tenant-owned role',
  createdAt: '2026-01-01',
  permissionCount: 2,
  manageable: true,
  global: false,
};

const GLOBAL_ROLE: Role = {
  id: 1,
  name: 'admin',
  description: 'Global base role',
  createdAt: '2026-01-01',
  permissionCount: 5,
  manageable: false,
  global: true,
};

const EDIT_TOOLTIP = 'Global base roles can only be edited by the system tenant.';
const DELETE_TOOLTIP =
  'Global base roles can only be deleted by the system tenant.';

/** English-fallback translator: returns the caller-supplied source string. */
const t = (_key: string, fallback?: string) => fallback ?? _key;
/** Caller holds both write capabilities so only manageability differs. */
const can = () => true;

function fakeAdapter(over: Partial<RolesAdapter> = {}): RolesAdapter {
  return {
    listRoles: jest.fn().mockResolvedValue([]),
    getRole: jest.fn().mockResolvedValue({ ...MANAGEABLE_ROLE, permissions: [] }),
    getRolePermissions: jest.fn().mockResolvedValue([]),
    getRoleAssignments: jest.fn().mockResolvedValue({ assignments: [], total: 0 }),
    getRoleActivity: jest.fn().mockResolvedValue([]),
    listPermissions: jest.fn().mockResolvedValue([]),
    createRole: jest.fn().mockResolvedValue(undefined),
    updateRole: jest.fn().mockResolvedValue('ok'),
    deleteRole: jest.fn().mockResolvedValue('ok'),
    getCapabilities: jest.fn().mockResolvedValue([]),
    ...over,
  };
}

/** Open the row's actions dropdown and return its menu element. */
async function openRowMenu(roleName: string): Promise<HTMLElement> {
  const user = userEvent.setup();
  const row = screen.getByText(roleName).closest('tr');
  expect(row).not.toBeNull();
  // The row holds TWO buttons since #910: the role's NAME (which opens the
  // record) and the actions trigger. Named explicitly rather than "the button".
  const trigger = within(row as HTMLElement).getByRole('button', { name: 'Actions' });
  await user.click(trigger);
  return await screen.findByRole('menu');
}

describe('RolesScreen per-row manageability gating (WC-222)', () => {
  it('renders Edit/Delete ENABLED for a manageable role and opens the RECORD on click', async () => {
    const adapter = fakeAdapter({ listRoles: jest.fn().mockResolvedValue([MANAGEABLE_ROLE]) });
    const user = userEvent.setup();
    const onOpenRecord = jest.fn();

    render(
      <RolesScreen
        adapter={adapter}
        can={can}
        t={t}
        onNotify={jest.fn()}
        onOpenRecord={onOpenRecord}
      />
    );
    await screen.findByText('TenantCustom');

    const menu = await openRowMenu('TenantCustom');
    const editItem = within(menu).getByText('Edit');
    const deleteItem = within(menu).getByText('Delete');

    expect(editItem).not.toHaveAttribute('data-disabled');
    expect(editItem).not.toHaveAttribute('title', EDIT_TOOLTIP);
    expect(deleteItem).not.toHaveAttribute('data-disabled');

    await user.click(editItem);
    // #910: Edit navigates to the record page. There is no edit modal left to
    // open — a dialog cannot express a record whose regions are gated
    // separately, which is the whole reason the modal was retired.
    expect(onOpenRecord).toHaveBeenCalledWith(MANAGEABLE_ROLE);
  });

  it('renders Edit/Delete DISABLED with an explanatory tooltip for a non-manageable global role', async () => {
    const adapter = fakeAdapter({ listRoles: jest.fn().mockResolvedValue([GLOBAL_ROLE]) });

    render(
      <RolesScreen
        adapter={adapter}
        can={can}
        t={t}
        onNotify={jest.fn()}
        onOpenRecord={jest.fn()}
      />
    );
    await screen.findByText('admin');

    const menu = await openRowMenu('admin');
    const editItem = within(menu).getByText('Edit');
    const deleteItem = within(menu).getByText('Delete');

    // Radix marks a disabled Item with `data-disabled` + aria-disabled.
    expect(editItem).toHaveAttribute('data-disabled');
    expect(editItem).toHaveAttribute('aria-disabled', 'true');
    expect(editItem).toHaveAttribute('title', EDIT_TOOLTIP);

    expect(deleteItem).toHaveAttribute('data-disabled');
    expect(deleteItem).toHaveAttribute('aria-disabled', 'true');
    expect(deleteItem).toHaveAttribute('title', DELETE_TOOLTIP);
  });

  it('does NOT navigate to the record when a disabled action is clicked', async () => {
    const adapter = fakeAdapter({ listRoles: jest.fn().mockResolvedValue([GLOBAL_ROLE]) });
    const user = userEvent.setup();
    const onOpenRecord = jest.fn();

    render(
      <RolesScreen
        adapter={adapter}
        can={can}
        t={t}
        onNotify={jest.fn()}
        onOpenRecord={onOpenRecord}
      />
    );
    await screen.findByText('admin');

    const menu = await openRowMenu('admin');
    const editItem = within(menu).getByText('Edit');

    await user.click(editItem);

    // A disabled item must never fire its action. The action is now navigation
    // rather than a modal, and the assertion moved with it.
    await waitFor(() => {
      expect(onOpenRecord).not.toHaveBeenCalled();
    });
    expect(adapter.getRole).not.toHaveBeenCalled();
  });
});
