/**
 * WC-user-status: the users admin page renders an account-status badge and
 * offers a Deactivate/Reactivate row action, gated on the SAME users:write
 * capability as Edit (no dedicated permission exists server-side for this).
 *
 * Mirrors the mocking conventions of edit-user-modal-ou.test.tsx (mock
 * `@/lib/api/client`'s typed `api.PATCH`) and
 * roles-page-manageability.test.tsx (mount the real page, drive the row's
 * actions dropdown via testing-library, stub the heavy modal children).
 */

import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import UsersPage, { type User } from '@/app/(protected)/admin/users/page';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useToast } from '@/lib/toast-context';

jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: jest.fn(),
}));

jest.mock('@/lib/toast-context', () => ({
  useToast: jest.fn(),
}));

const mockApiClient = jest.fn();
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => mockApiClient(...args),
}));

const mockApiPatch = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: {
    PATCH: (...args: unknown[]) => mockApiPatch(...args),
  },
}));

// Stub the heavy modal children — this test is about the row's status badge
// and Deactivate/Reactivate action, not the Create/Edit/Delete modals.
jest.mock('@/app/(protected)/admin/users/create-modal', () => ({
  CreateUserModal: () => null,
}));
jest.mock('@/app/(protected)/admin/users/edit-modal', () => ({
  EditUserModal: () => null,
}));
jest.mock('@/app/(protected)/admin/users/delete-modal', () => ({
  DeleteUserModal: () => null,
}));

const mockUseCapabilities = useCapabilities as jest.MockedFunction<typeof useCapabilities>;
const mockUseToast = useToast as jest.MockedFunction<typeof useToast>;

const ACTIVE_USER: User = {
  id: 1,
  name: 'alice',
  email: 'alice@example.com',
  role: 'user',
  tenantId: 1,
  ou_id: null,
  createdAt: '2026-01-01T00:00:00Z',
  accountStatus: 'active',
};

const INACTIVE_USER: User = {
  id: 2,
  name: 'bob',
  email: 'bob@example.com',
  role: 'user',
  tenantId: 1,
  ou_id: null,
  createdAt: '2026-01-01T00:00:00Z',
  accountStatus: 'inactive',
};

const addToast = jest.fn();

function mockUsers(users: User[]): void {
  mockApiClient.mockResolvedValue({
    ok: true,
    json: async () => ({ data: users }),
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  mockUseToast.mockReturnValue({ addToast } as unknown as ReturnType<typeof useToast>);
  mockApiPatch.mockResolvedValue({
    data: { data: { ...ACTIVE_USER, accountStatus: 'inactive' } },
    error: undefined,
    response: { ok: true },
  });
});

/** Open the row's actions dropdown and return its menu element. */
async function openRowMenu(rowName: string): Promise<HTMLElement> {
  const user = userEvent.setup();
  const row = screen.getByText(rowName).closest('tr');
  expect(row).not.toBeNull();
  const trigger = within(row as HTMLElement).getByRole('button');
  await user.click(trigger);
  return await screen.findByRole('menu');
}

describe('UsersPage account status (WC-user-status)', () => {
  it('renders an Active badge for an active user and an Inactive badge for a deactivated one', async () => {
    mockUseCapabilities.mockReturnValue({
      permissions: [],
      loading: false,
      hasPermission: () => true,
    });
    mockUsers([ACTIVE_USER, INACTIVE_USER]);

    render(<UsersPage />);

    await waitFor(() => {
      expect(screen.getByText('alice')).toBeInTheDocument();
    });

    expect(screen.getByText('Active')).toBeInTheDocument();
    expect(screen.getByText('Inactive')).toBeInTheDocument();
  });

  it('offers "Deactivate" for an active user and calls PATCH with accountStatus: inactive', async () => {
    mockUseCapabilities.mockReturnValue({
      permissions: [],
      loading: false,
      hasPermission: () => true,
    });
    mockUsers([ACTIVE_USER]);
    const user = userEvent.setup();

    render(<UsersPage />);
    await screen.findByText('alice');

    const menu = await openRowMenu('alice');
    const deactivateItem = within(menu).getByText('Deactivate');
    expect(within(menu).queryByText('Reactivate')).not.toBeInTheDocument();

    await user.click(deactivateItem);

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalledWith(
        '/api/v1/users/{id}',
        expect.objectContaining({
          params: { path: { id: 1 } },
          body: { accountStatus: 'inactive' },
        })
      );
    });
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('User deactivated', 'success'));
  });

  it('offers "Reactivate" for an inactive user and calls PATCH with accountStatus: active', async () => {
    mockUseCapabilities.mockReturnValue({
      permissions: [],
      loading: false,
      hasPermission: () => true,
    });
    mockUsers([INACTIVE_USER]);
    const user = userEvent.setup();

    render(<UsersPage />);
    await screen.findByText('bob');

    const menu = await openRowMenu('bob');
    const reactivateItem = within(menu).getByText('Reactivate');
    expect(within(menu).queryByText('Deactivate')).not.toBeInTheDocument();

    await user.click(reactivateItem);

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalledWith(
        '/api/v1/users/{id}',
        expect.objectContaining({
          params: { path: { id: 2 } },
          body: { accountStatus: 'active' },
        })
      );
    });
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('User reactivated', 'success'));
  });

  it('hides the Deactivate/Reactivate action for a caller without users:write', async () => {
    // Only users:delete — mirrors the existing Edit gating contract.
    mockUseCapabilities.mockReturnValue({
      permissions: [],
      loading: false,
      hasPermission: (slug: string) => slug === 'users:delete',
    });
    mockUsers([ACTIVE_USER]);

    render(<UsersPage />);
    await screen.findByText('alice');

    const menu = await openRowMenu('alice');
    expect(within(menu).queryByText('Deactivate')).not.toBeInTheDocument();
    expect(within(menu).queryByText('Edit')).not.toBeInTheDocument();
    expect(within(menu).getByText('Delete')).toBeInTheDocument();
  });

  it('surfaces a toast error and does not crash when the status toggle fails', async () => {
    mockUseCapabilities.mockReturnValue({
      permissions: [],
      loading: false,
      hasPermission: () => true,
    });
    mockUsers([ACTIVE_USER]);
    mockApiPatch.mockResolvedValue({
      data: undefined,
      error: { error: 'Something went wrong' },
      response: { ok: false },
    });
    const user = userEvent.setup();

    render(<UsersPage />);
    await screen.findByText('alice');

    const menu = await openRowMenu('alice');
    await user.click(within(menu).getByText('Deactivate'));

    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Something went wrong', 'error'));
  });
});
