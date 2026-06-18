/**
 * WC-222: the roles admin gates per-row Edit/Delete on tenant manageability.
 *
 * `PATCH/DELETE /api/v1/roles/{id}` returns 404 by design for a GLOBAL base
 * role (NULL tenant_id) when the caller is a regular (non-system) tenant — only
 * the system tenant may manage global roles (WC-110). The list now surfaces a
 * per-row `manageable` flag; the page renders Edit/Delete DISABLED with an
 * explanatory tooltip (native `title`) when `!manageable`, and a disabled item
 * must not open its modal. The capability gate (ROLES_WRITE / ROLES_DELETE) and
 * the manageability gate BOTH apply.
 */

import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import RolesPage from '@/app/(protected)/admin/roles/page';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useToast } from '@/lib/toast-context';
import { ROLES_WRITE, ROLES_DELETE } from '@/lib/capabilities';
import type { Role } from '@/app/(protected)/admin/roles/types';

jest.mock('@/lib/auth-context', () => ({
  useAuth: jest.fn(),
}));
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: jest.fn(),
}));
jest.mock('@/lib/toast-context', () => ({
  useToast: jest.fn(),
}));

// Stub the heavy modal/panel children so the test stays focused on the row
// actions; each records whether it was opened via its `isOpen` prop.
const editOpenSpy = jest.fn();
const deleteOpenSpy = jest.fn();
jest.mock('@/app/(protected)/admin/roles/create-modal', () => ({
  CreateRoleModal: () => null,
}));
jest.mock('@/app/(protected)/admin/roles/edit-modal', () => ({
  EditRoleModal: ({ isOpen }: { isOpen: boolean }) => {
    if (isOpen) editOpenSpy();
    return isOpen ? <div data-testid="edit-modal-open" /> : null;
  },
}));
jest.mock('@/app/(protected)/admin/roles/delete-modal', () => ({
  DeleteRoleModal: ({ isOpen }: { isOpen: boolean }) => {
    if (isOpen) deleteOpenSpy();
    return isOpen ? <div data-testid="delete-modal-open" /> : null;
  },
}));
jest.mock('@/app/(protected)/admin/roles/permissions-panel', () => ({
  PermissionsPanel: () => null,
}));

const mockUseAuth = useAuth as jest.MockedFunction<typeof useAuth>;
const mockUseCapabilities = useCapabilities as jest.MockedFunction<
  typeof useCapabilities
>;
const mockUseToast = useToast as jest.MockedFunction<typeof useToast>;

const MANAGEABLE_ROLE: Role = {
  id: 10,
  name: 'TenantCustom',
  description: 'A tenant-owned role',
  createdAt: '2026-01-01',
  permissionCount: 2,
  manageable: true,
};

const GLOBAL_ROLE: Role = {
  id: 1,
  name: 'admin',
  description: 'Global base role',
  createdAt: '2026-01-01',
  permissionCount: 5,
  manageable: false,
};

const EDIT_TOOLTIP = 'Global base roles can only be edited by the system tenant.';
const DELETE_TOOLTIP =
  'Global base roles can only be deleted by the system tenant.';

function mockRoles(roles: Role[]): jest.Mock {
  const apiClient = jest.fn(async () => ({
    ok: true,
    json: async () => ({ data: roles }),
  }));
  mockUseAuth.mockReturnValue({ apiClient } as unknown as ReturnType<
    typeof useAuth
  >);
  return apiClient;
}

beforeEach(() => {
  jest.clearAllMocks();
  mockUseToast.mockReturnValue({
    addToast: jest.fn(),
  } as unknown as ReturnType<typeof useToast>);
  // Caller holds both write capabilities so only manageability differs.
  mockUseCapabilities.mockReturnValue({
    permissions: [ROLES_WRITE, ROLES_DELETE],
    loading: false,
    hasPermission: (slug: string) =>
      slug === ROLES_WRITE || slug === ROLES_DELETE,
  });
});

/** Open the row's actions dropdown and return its menu element. */
async function openRowMenu(roleName: string): Promise<HTMLElement> {
  const user = userEvent.setup();
  const row = screen.getByText(roleName).closest('tr');
  expect(row).not.toBeNull();
  const trigger = within(row as HTMLElement).getByRole('button');
  await user.click(trigger);
  return await screen.findByRole('menu');
}

describe('RolesPage per-row manageability gating (WC-222)', () => {
  it('renders Edit/Delete ENABLED for a manageable role and opens the edit modal on click', async () => {
    mockRoles([MANAGEABLE_ROLE]);
    const user = userEvent.setup();

    render(<RolesPage />);
    await screen.findByText('TenantCustom');

    const menu = await openRowMenu('TenantCustom');
    const editItem = within(menu).getByText('Edit');
    const deleteItem = within(menu).getByText('Delete');

    expect(editItem).not.toHaveAttribute('data-disabled');
    expect(editItem).not.toHaveAttribute('title', EDIT_TOOLTIP);
    expect(deleteItem).not.toHaveAttribute('data-disabled');

    await user.click(editItem);
    await waitFor(() => expect(editOpenSpy).toHaveBeenCalled());
  });

  it('renders Edit/Delete DISABLED with an explanatory tooltip for a non-manageable global role', async () => {
    mockRoles([GLOBAL_ROLE]);

    render(<RolesPage />);
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

  it('does NOT open the edit/delete modal when a disabled action is clicked', async () => {
    mockRoles([GLOBAL_ROLE]);
    const user = userEvent.setup();

    render(<RolesPage />);
    await screen.findByText('admin');

    const menu = await openRowMenu('admin');
    const editItem = within(menu).getByText('Edit');

    await user.click(editItem);

    // A disabled item must never open its modal.
    expect(editOpenSpy).not.toHaveBeenCalled();
    expect(deleteOpenSpy).not.toHaveBeenCalled();
    expect(screen.queryByTestId('edit-modal-open')).not.toBeInTheDocument();
  });
});
