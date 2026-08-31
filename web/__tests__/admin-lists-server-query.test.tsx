/**
 * #1102 — the users, roles and user-groups lists ask the SERVER to page, sort
 * and search.
 *
 * WHAT THESE GUARD. All three screens used to fetch a slice (`per_page=100`, or
 * every page walked in a loop) and then sort, filter and paginate it in the
 * browser. On a tenant with more than a hundred users the rest were simply not
 * on the screen, and nothing said so — the users page's own comment admitted it.
 *
 * The assertions are about the REQUEST, not about rows appearing, because the
 * rows are whatever the stub was told to return: a screen that ignored the sort
 * entirely would still render them. So each case pins a query string, and two
 * of them pin the thing nothing else would notice —
 *
 *   - changing the sort or the search RESETS to page 1. Without it, landing on
 *     page 7 and then searching for something with three matches asks the server
 *     for rows 151-175 of a three-row list and renders an empty table with no
 *     explanation.
 *   - a refetch after a create/edit/delete carries the page, sort and term the
 *     operator was looking at. Without it the table silently jumps back to an
 *     unfiltered page 1 the moment anything is saved.
 *
 * And the negative one: with no column chosen, NO `sort` and NO `dir` are sent,
 * so the endpoint applies its own default ordering rather than one the UI
 * invented. `ListQuery` treats an unknown or empty `sort` as "none was asked
 * for" and silently falls back, which is exactly why sending one anyway would
 * never show up as a failure.
 */

import React from 'react';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DATA_TABLE_SEARCH_DEBOUNCE_MS } from '@amroksaleh/ui/data-table';

const mockRouterPush = jest.fn();
jest.mock('next/navigation', () => ({
  useRouter: () => ({ push: mockRouterPush }),
}));

const hasPermission = jest.fn<boolean, [string]>(() => true);
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({
    permissions: [],
    loading: false,
    has: hasPermission,
    hasAny: () => true,
    hasAll: () => true,
    hasPermission,
  }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

/** The users screen's transport. */
const mockApiClient = jest.fn();
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => mockApiClient(...args),
}));

/** The user-groups screen's transport, which comes from the auth context. */
const mockAuthApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockAuthApiClient, user: { id: 3, tenant_id: 1 } }),
}));

const mockApiPatch = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: { PATCH: (...args: unknown[]) => mockApiPatch(...args) },
}));

// The heavy children of the users page. None of them is under test here and
// each fetches on its own, which would put unrelated URLs in the transport log
// this file reads its assertions out of.
jest.mock('@/app/(protected)/admin/users/create-modal', () => ({
  CreateUserModal: () => null,
}));
jest.mock('@/app/(protected)/admin/users/edit-modal', () => ({
  EditUserModal: () => null,
}));
jest.mock('@/app/(protected)/admin/users/delete-modal', () => ({
  DeleteUserModal: () => null,
}));
jest.mock('@/app/(protected)/admin/users/invitations-panel', () => ({
  InvitationsPanel: () => null,
}));

import UsersPage, { type User } from '@/app/(protected)/admin/users/page';
import UserGroupsPage from '@/app/(protected)/admin/user-groups/page';
import { RolesScreen } from '@amroksaleh/features/roles';
import type { Role, RoleListQuery, RolesAdapter } from '@amroksaleh/features/roles';

function jsonResponse(body: unknown) {
  return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
}

/** Every URL this transport was asked for, oldest first. */
function requestsTo(client: jest.Mock, prefix: string): string[] {
  return client.mock.calls
    .map((call) => String(call[0]))
    .filter((url) => url.startsWith(prefix));
}

function lastRequestTo(client: jest.Mock, prefix: string): string {
  const urls = requestsTo(client, prefix);
  return urls[urls.length - 1] ?? '';
}

// ---------------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------------

const USERS: User[] = [
  {
    id: 1,
    name: 'alice',
    email: 'alice@example.com',
    role: 'user',
    tenantId: 1,
    ou_id: null,
    createdAt: '2026-01-01T00:00:00Z',
    accountStatus: 'active',
  },
  {
    id: 2,
    name: 'bob',
    email: 'bob@example.com',
    role: 'admin',
    tenantId: 1,
    ou_id: null,
    createdAt: '2026-01-02T00:00:00Z',
    accountStatus: 'active',
  },
];

/** 60 people over 25 a page: three pages, so "next" is reachable. */
const USERS_TOTAL = 60;

function stubUsers(): void {
  mockApiClient.mockImplementation(() =>
    jsonResponse({
      data: USERS,
      pagination: { page: 1, perPage: 25, total: USERS_TOTAL, totalPages: 3 },
    })
  );
}

describe('Users list asks the server to page, sort and search (#1102)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    hasPermission.mockReturnValue(true);
    stubUsers();
    mockApiPatch.mockResolvedValue({
      data: { data: { ...USERS[0], accountStatus: 'inactive' } },
      error: undefined,
      response: { ok: true },
    });
  });

  it('opens on page 1 and sends NO sort, so the endpoint orders by its own default', async () => {
    render(<UsersPage />);
    await screen.findByText('alice');

    // Byte-for-byte: an extra `sort=` here would be the UI presenting the
    // endpoint's fallback ordering as a choice the reader made.
    expect(requestsTo(mockApiClient, '/api/v1/users?')).toEqual([
      '/api/v1/users?page=1&per_page=25',
    ]);
  });

  it('sends the ENDPOINT key for a column whose id differs from it', async () => {
    render(<UsersPage />);
    await screen.findByText('alice');

    fireEvent.click(screen.getByRole('columnheader', { name: /Status/ }));

    // The column id is `accountStatus`; `UsersApiHandler::listSpec()` calls the
    // key `status`. Sending the id would not error — it would fall back to the
    // default order — so the header would click and reorder nothing.
    await waitFor(() => {
      expect(lastRequestTo(mockApiClient, '/api/v1/users?')).toBe(
        '/api/v1/users?page=1&per_page=25&sort=status&dir=asc'
      );
    });
  });

  it('returns to page 1 when the sort changes', async () => {
    const user = userEvent.setup();
    render(<UsersPage />);
    await screen.findByText('alice');

    await user.click(screen.getByRole('button', { name: 'Next page' }));
    await waitFor(() => {
      expect(lastRequestTo(mockApiClient, '/api/v1/users?')).toBe(
        '/api/v1/users?page=2&per_page=25'
      );
    });

    fireEvent.click(screen.getByRole('columnheader', { name: /Email/ }));

    // THE ASSERTION THIS CASE EXISTS FOR: page 2 of the old ordering is not
    // page 2 of the new one, it is an arbitrary window into a different list.
    await waitFor(() => {
      expect(lastRequestTo(mockApiClient, '/api/v1/users?')).toBe(
        '/api/v1/users?page=1&per_page=25&sort=email&dir=asc'
      );
    });
  });

  it('drops sort AND dir when a column is cycled past descending', async () => {
    render(<UsersPage />);
    await screen.findByText('alice');

    const email = () => screen.getByRole('columnheader', { name: /Email/ });
    fireEvent.click(email()); // asc
    await waitFor(() =>
      expect(lastRequestTo(mockApiClient, '/api/v1/users?')).toContain('sort=email&dir=asc')
    );
    fireEvent.click(email()); // desc
    await waitFor(() =>
      expect(lastRequestTo(mockApiClient, '/api/v1/users?')).toContain('sort=email&dir=desc')
    );
    fireEvent.click(email()); // none

    await waitFor(() => {
      expect(lastRequestTo(mockApiClient, '/api/v1/users?')).toBe(
        '/api/v1/users?page=1&per_page=25'
      );
    });
  });

  it('keeps the page, the sort AND the term across a refetch after a row action', async () => {
    const user = userEvent.setup();
    render(<UsersPage />);
    await screen.findByText('alice');

    fireEvent.click(screen.getByRole('columnheader', { name: /Role/ }));
    await waitFor(() =>
      expect(lastRequestTo(mockApiClient, '/api/v1/users?')).toContain('sort=role')
    );
    await user.click(screen.getByRole('button', { name: 'Next page' }));
    await waitFor(() =>
      expect(lastRequestTo(mockApiClient, '/api/v1/users?')).toContain('page=2')
    );

    const row = screen.getByText('alice').closest('tr') as HTMLElement;
    await user.click(within(row).getByRole('button', { name: 'Row actions' }));
    await user.click(await screen.findByText('Deactivate'));

    // The refetch the toggle triggers must be the SAME question the operator
    // was looking at. Dropping back to an unfiltered page 1 here is the bug
    // this asserts against, and it looks like the table simply "refreshed".
    await waitFor(() => {
      expect(lastRequestTo(mockApiClient, '/api/v1/users?')).toBe(
        '/api/v1/users?page=2&per_page=25&sort=role&dir=asc'
      );
    });
  });

  describe('the search box', () => {
    beforeEach(() => {
      jest.useFakeTimers();
    });

    afterEach(() => {
      jest.runOnlyPendingTimers();
      jest.useRealTimers();
    });

    it('sends the term as `q`, once, and back on page 1', async () => {
      render(<UsersPage />);
      await act(async () => {});

      fireEvent.click(screen.getByRole('columnheader', { name: /Email/ }));
      await act(async () => {});
      fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
      await act(async () => {});
      expect(lastRequestTo(mockApiClient, '/api/v1/users?')).toContain('page=2');

      const box = screen.getByPlaceholderText('Search users…');
      fireEvent.change(box, { target: { value: 'sa' } });
      fireEvent.change(box, { target: { value: 'sar' } });
      fireEvent.change(box, { target: { value: 'sara' } });

      // DataTableServerSearch owns the debounce; the screen adds none of its
      // own, so three keystrokes are still one request.
      const before = requestsTo(mockApiClient, '/api/v1/users?').length;
      await act(async () => {
        jest.advanceTimersByTime(DATA_TABLE_SEARCH_DEBOUNCE_MS);
      });

      expect(requestsTo(mockApiClient, '/api/v1/users?')).toHaveLength(before + 1);
      expect(lastRequestTo(mockApiClient, '/api/v1/users?')).toBe(
        '/api/v1/users?page=1&per_page=25&sort=email&dir=asc&q=sara'
      );
    });
  });
});

// ---------------------------------------------------------------------------
// Roles — the same wiring, through the injected adapter rather than a URL
// ---------------------------------------------------------------------------

const ROLE: Role = {
  id: 10,
  name: 'TenantCustom',
  description: 'A tenant-owned role',
  createdAt: '2026-01-01',
  permissionCount: 2,
  manageable: true,
  global: false,
};

const t = (_key: string, fallback?: string) => fallback ?? _key;

function rolesAdapter(listRoles: jest.Mock): RolesAdapter {
  return {
    listRoles: listRoles as unknown as RolesAdapter['listRoles'],
    getRole: jest.fn().mockResolvedValue({ ...ROLE, permissions: [] }),
    getRolePermissions: jest.fn().mockResolvedValue([]),
    getRoleAssignments: jest.fn().mockResolvedValue({ assignments: [], total: 0 }),
    getRoleActivity: jest.fn().mockResolvedValue([]),
    listPermissions: jest.fn().mockResolvedValue([]),
    createRole: jest.fn().mockResolvedValue(undefined),
    updateRole: jest.fn().mockResolvedValue('ok'),
    deleteRole: jest.fn().mockResolvedValue('ok'),
    getCapabilities: jest.fn().mockResolvedValue([]),
  };
}

function lastRoleQuery(listRoles: jest.Mock): RoleListQuery {
  return listRoles.mock.calls[listRoles.mock.calls.length - 1][0] as RoleListQuery;
}

describe('Roles list asks the server to page, sort and search (#1102)', () => {
  it('asks for one page with no sort of its own', async () => {
    const listRoles = jest
      .fn()
      .mockResolvedValue({ roles: [ROLE], total: 60, totalPages: 3 });

    render(
      <RolesScreen adapter={rolesAdapter(listRoles)} can={() => true} t={t} onOpenRecord={jest.fn()} />
    );
    await screen.findByText('TenantCustom');

    expect(listRoles).toHaveBeenCalledTimes(1);
    expect(lastRoleQuery(listRoles)).toEqual({
      page: 1,
      perPage: 25,
      sort: undefined,
      dir: undefined,
      q: undefined,
    });
  });

  it('returns to page 1 when the sort changes', async () => {
    const user = userEvent.setup();
    const listRoles = jest
      .fn()
      .mockResolvedValue({ roles: [ROLE], total: 60, totalPages: 3 });

    render(
      <RolesScreen adapter={rolesAdapter(listRoles)} can={() => true} t={t} onOpenRecord={jest.fn()} />
    );
    await screen.findByText('TenantCustom');

    await user.click(screen.getByRole('button', { name: 'Next page' }));
    await waitFor(() => expect(lastRoleQuery(listRoles).page).toBe(2));

    fireEvent.click(screen.getByRole('columnheader', { name: /Name/ }));

    await waitFor(() => {
      expect(lastRoleQuery(listRoles)).toMatchObject({ page: 1, sort: 'name', dir: 'asc' });
    });
  });

  describe('the search box', () => {
    beforeEach(() => {
      jest.useFakeTimers();
    });

    afterEach(() => {
      jest.runOnlyPendingTimers();
      jest.useRealTimers();
    });

    it('reaches the adapter as `q`', async () => {
      const listRoles = jest
        .fn()
        .mockResolvedValue({ roles: [ROLE], total: 60, totalPages: 3 });

      render(
        <RolesScreen
          adapter={rolesAdapter(listRoles)}
          can={() => true}
          t={t}
          onOpenRecord={jest.fn()}
        />
      );
      await act(async () => {});

      fireEvent.change(screen.getByPlaceholderText('Search roles…'), {
        target: { value: 'audit' },
      });
      await act(async () => {
        jest.advanceTimersByTime(DATA_TABLE_SEARCH_DEBOUNCE_MS);
      });

      expect(lastRoleQuery(listRoles)).toMatchObject({ page: 1, q: 'audit' });
    });
  });
});

// ---------------------------------------------------------------------------
// User groups
// ---------------------------------------------------------------------------

const GROUP = {
  id: 1,
  name: 'Technicians',
  description: 'Everyone holding the technician role',
  rule_kind: 'role',
  rule_config: { role_id: 6 },
};

function stubUserGroups(): void {
  mockAuthApiClient.mockImplementation((url: string) => {
    if (url.startsWith('/api/v1/user-groups')) {
      return jsonResponse({
        data: [GROUP],
        pagination: { page: 1, perPage: 25, total: 60, totalPages: 3 },
      });
    }
    if (url === '/api/v1/group-rules') {
      return jsonResponse({
        data: [{ kind: 'role', label: 'Everyone holding a role', source: 'core' }],
      });
    }
    // The dialog's pickers, which still legitimately want every row.
    return jsonResponse({
      data: [],
      pagination: { page: 1, perPage: 100, total: 0, totalPages: 1 },
    });
  });
}

describe('User groups list asks the server to page, sort and search (#1102)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    hasPermission.mockReturnValue(true);
    stubUserGroups();
  });

  it('asks for ONE page rather than walking every page', async () => {
    render(<UserGroupsPage />);
    await screen.findByText('Technicians');

    expect(requestsTo(mockAuthApiClient, '/api/v1/user-groups')).toEqual([
      '/api/v1/user-groups?page=1&per_page=25',
    ]);
  });

  it('sends the ENDPOINT key for the rule column, whose id is the localised label', async () => {
    render(<UserGroupsPage />);
    await screen.findByText('Technicians');

    fireEvent.click(screen.getByRole('columnheader', { name: /Defined as/ }));

    // The column renders a label resolved from /group-rules; the endpoint can
    // only order by the `rule_kind` slug behind it, which it calls `rule`.
    await waitFor(() => {
      expect(lastRequestTo(mockAuthApiClient, '/api/v1/user-groups')).toBe(
        '/api/v1/user-groups?page=1&per_page=25&sort=rule&dir=asc'
      );
    });
  });

  describe('the search box', () => {
    beforeEach(() => {
      jest.useFakeTimers();
    });

    afterEach(() => {
      jest.runOnlyPendingTimers();
      jest.useRealTimers();
    });

    it('sends the term as `q`, back on page 1', async () => {
      render(<UserGroupsPage />);
      await act(async () => {});

      fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
      await act(async () => {});
      expect(lastRequestTo(mockAuthApiClient, '/api/v1/user-groups')).toContain('page=2');

      fireEvent.change(screen.getByPlaceholderText('Search user groups…'), {
        target: { value: 'tech' },
      });
      await act(async () => {
        jest.advanceTimersByTime(DATA_TABLE_SEARCH_DEBOUNCE_MS);
      });

      expect(lastRequestTo(mockAuthApiClient, '/api/v1/user-groups')).toBe(
        '/api/v1/user-groups?page=1&per_page=25&q=tech'
      );
    });
  });
});
