/**
 * WC-175 regression: the sidebar navigation is AUTH-AWARE.
 *
 * `GET /api/navigation` is server-side RBAC-filtered and now 401s when
 * unauthenticated (#191), so the list is only meaningful for the CURRENT
 * authenticated user. The provider must wait for auth to settle, fetch per
 * signed-in user, clear on logout, and refetch when the user changes — a fetch
 * fired once pre-auth would 401 and pin an empty sidebar for the whole SPA
 * session ("sidebar empty for every role after login until a hard refresh",
 * the bug this test pins).
 */

import React from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import {
  NavigationProvider,
  useNavigation,
  type NavigationItem,
} from '@/lib/navigation-context';
import { useAuth } from '@/lib/auth-context';

jest.mock('@/lib/auth-context', () => ({
  useAuth: jest.fn(),
}));

const mockUseAuth = useAuth as jest.MockedFunction<typeof useAuth>;
const mockFetch = jest.fn<Promise<Response>, [RequestInfo | URL, RequestInit?]>();

beforeAll(() => {
  global.fetch = mockFetch as unknown as typeof global.fetch;
});

function authState(user: { id: number } | null, isLoading = false) {
  return { user, isLoading } as unknown as ReturnType<typeof useAuth>;
}

/** Build an ok Response whose JSON body is the `{ data: [...] }` envelope. */
function navResponse(items: NavigationItem[]): Response {
  return {
    ok: true,
    json: async () => ({ data: items }),
  } as unknown as Response;
}

function Probe() {
  const { items, isLoading } = useNavigation();
  return (
    <div>
      <span data-testid="loading">{String(isLoading)}</span>
      <span data-testid="ids">{items.map((i) => i.id).join(',')}</span>
    </div>
  );
}

const DASHBOARD: NavigationItem = {
  id: 'dashboard',
  label: 'Dashboard',
  href: '/dashboard',
  icon: 'home',
  group: 'core',
  order: 10,
};

const USERS: NavigationItem = {
  id: 'users',
  label: 'Users',
  href: '/admin/users',
  icon: 'users',
  group: 'admin',
  order: 20,
};

describe('NavigationProvider auth-awareness', () => {
  beforeEach(() => {
    mockUseAuth.mockReset();
    mockFetch.mockReset();
  });

  it('does not fetch while auth state is still resolving', () => {
    mockUseAuth.mockReturnValue(authState(null, true));

    render(
      <NavigationProvider>
        <Probe />
      </NavigationProvider>
    );

    expect(mockFetch).not.toHaveBeenCalled();
    expect(screen.getByTestId('loading').textContent).toBe('true');
  });

  it('exposes an empty list without fetching when signed out', async () => {
    mockUseAuth.mockReturnValue(authState(null));

    render(
      <NavigationProvider>
        <Probe />
      </NavigationProvider>
    );

    await waitFor(() =>
      expect(screen.getByTestId('loading').textContent).toBe('false')
    );
    expect(mockFetch).not.toHaveBeenCalled();
    expect(screen.getByTestId('ids').textContent).toBe('');
  });

  it('fetches /api/navigation when a user signs in (the SPA login flow)', async () => {
    mockUseAuth.mockReturnValue(authState(null));
    mockFetch.mockResolvedValue(navResponse([DASHBOARD]));

    const { rerender } = render(
      <NavigationProvider>
        <Probe />
      </NavigationProvider>
    );

    await waitFor(() =>
      expect(screen.getByTestId('loading').textContent).toBe('false')
    );
    expect(mockFetch).not.toHaveBeenCalled();

    // The user signs in via the SPA login page — no full page reload.
    mockUseAuth.mockReturnValue(authState({ id: 2 }));
    rerender(
      <NavigationProvider>
        <Probe />
      </NavigationProvider>
    );

    await waitFor(() =>
      expect(screen.getByTestId('ids').textContent).toBe('dashboard')
    );
    expect(mockFetch).toHaveBeenCalledTimes(1);
    expect(mockFetch).toHaveBeenCalledWith('/api/v1/navigation', {
      credentials: 'include',
      signal: expect.any(AbortSignal),
    });
  });

  it('clears the list on logout without making a request', async () => {
    mockUseAuth.mockReturnValue(authState({ id: 2 }));
    mockFetch.mockResolvedValue(navResponse([DASHBOARD]));

    const { rerender } = render(
      <NavigationProvider>
        <Probe />
      </NavigationProvider>
    );
    await waitFor(() =>
      expect(screen.getByTestId('ids').textContent).toBe('dashboard')
    );
    expect(mockFetch).toHaveBeenCalledTimes(1);

    mockUseAuth.mockReturnValue(authState(null));
    rerender(
      <NavigationProvider>
        <Probe />
      </NavigationProvider>
    );

    await waitFor(() => expect(screen.getByTestId('ids').textContent).toBe(''));
    // No further request: the signed-out branch never hits the network.
    expect(mockFetch).toHaveBeenCalledTimes(1);
  });

  it('refetches when a different user signs in (caller-aware nav swap)', async () => {
    mockUseAuth.mockReturnValue(authState({ id: 2 }));
    mockFetch.mockResolvedValue(navResponse([DASHBOARD, USERS]));

    const { rerender } = render(
      <NavigationProvider>
        <Probe />
      </NavigationProvider>
    );
    await waitFor(() =>
      expect(screen.getByTestId('ids').textContent).toBe('dashboard,users')
    );
    expect(mockFetch).toHaveBeenCalledTimes(1);

    // A different user signs in — their RBAC-filtered nav replaces the old one.
    mockFetch.mockResolvedValue(navResponse([DASHBOARD]));
    mockUseAuth.mockReturnValue(authState({ id: 7 }));
    rerender(
      <NavigationProvider>
        <Probe />
      </NavigationProvider>
    );

    await waitFor(() => expect(mockFetch).toHaveBeenCalledTimes(2));
    await waitFor(() =>
      expect(screen.getByTestId('ids').textContent).toBe('dashboard')
    );
  });
});

/**
 * WC-221: imperative `refresh()` and optimistic `removeItemsByHref()`.
 *
 * The plugins console disables/uninstalls plugins and needs the sidebar to drop
 * the plugin's contributed nav links WITHOUT a full page reload. The provider
 * exposes:
 *   - `refresh()`            — re-runs the `/api/v1/navigation` fetch and
 *                              resolves once the new list is applied.
 *   - `removeItemsByHref()`  — optimistically filters items by href so a link
 *                              disappears BEFORE the refetch resolves.
 */

const PLUGIN_GREETINGS: NavigationItem = {
  id: 'hello-greetings',
  label: 'Greetings',
  href: '/admin/x/hello-greetings',
  icon: 'message',
  group: 'plugins',
  order: 30,
};

function RefreshProbe() {
  const { items, refresh, removeItemsByHref } = useNavigation();
  return (
    <div>
      <span data-testid="ids">{items.map((i) => i.id).join(',')}</span>
      <button data-testid="refresh" onClick={() => void refresh()}>
        refresh
      </button>
      <button
        data-testid="optimistic-remove"
        onClick={() => removeItemsByHref(['/admin/x/hello-greetings'])}
      >
        remove
      </button>
    </div>
  );
}

describe('NavigationProvider refresh + optimistic removal (WC-221)', () => {
  beforeEach(() => {
    mockUseAuth.mockReset();
    mockFetch.mockReset();
  });

  it('refresh() re-runs the navigation fetch and applies the new list', async () => {
    mockUseAuth.mockReturnValue(authState({ id: 2 }));
    mockFetch.mockResolvedValue(navResponse([DASHBOARD, PLUGIN_GREETINGS]));

    render(
      <NavigationProvider>
        <RefreshProbe />
      </NavigationProvider>
    );
    await waitFor(() =>
      expect(screen.getByTestId('ids').textContent).toBe(
        'dashboard,hello-greetings'
      )
    );
    expect(mockFetch).toHaveBeenCalledTimes(1);

    // After a plugin is disabled the server no longer returns its link; a
    // refresh() pulls the new RBAC-filtered list in without a page reload.
    mockFetch.mockResolvedValue(navResponse([DASHBOARD]));
    await act(async () => {
      screen.getByTestId('refresh').click();
    });

    await waitFor(() => expect(mockFetch).toHaveBeenCalledTimes(2));
    await waitFor(() =>
      expect(screen.getByTestId('ids').textContent).toBe('dashboard')
    );
  });

  it('removeItemsByHref() optimistically drops a plugin link before any refetch', async () => {
    mockUseAuth.mockReturnValue(authState({ id: 2 }));
    mockFetch.mockResolvedValue(navResponse([DASHBOARD, PLUGIN_GREETINGS]));

    render(
      <NavigationProvider>
        <RefreshProbe />
      </NavigationProvider>
    );
    await waitFor(() =>
      expect(screen.getByTestId('ids').textContent).toBe(
        'dashboard,hello-greetings'
      )
    );
    expect(mockFetch).toHaveBeenCalledTimes(1);

    // Optimistic removal is local-only: the plugin link vanishes immediately
    // and NO new request is made by the removal itself.
    act(() => {
      screen.getByTestId('optimistic-remove').click();
    });

    expect(screen.getByTestId('ids').textContent).toBe('dashboard');
    expect(mockFetch).toHaveBeenCalledTimes(1);
  });
});
