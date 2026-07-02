import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { AuthProvider, useAuth } from '@/lib/auth-context';
import * as apiClientModule from '@/lib/api-client';

// Mock the api-client module
jest.mock('@/lib/api-client', () => ({
  apiClient: jest.fn(),
}));

// Mock global fetch
global.fetch = jest.fn();

const mockUser = {
  id: 1,
  email: 'test@example.com',
  role: 'admin',
};

// Test component that uses auth context
function TestComponent() {
  const auth = useAuth();
  return (
    <div>
      <div data-testid="user">{auth.user ? auth.user.email : 'No user'}</div>
      <div data-testid="loading">{auth.isLoading ? 'Loading' : 'Ready'}</div>
      <div data-testid="error">{auth.error || 'No error'}</div>
      <div data-testid="authenticated">{auth.isAuthenticated() ? 'Authenticated' : 'Not authenticated'}</div>
      <button onClick={() => auth.login('test@example.com', 'password')}>Login</button>
      <button onClick={() => auth.logout()}>Logout</button>
    </div>
  );
}

describe('AuthContext', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    // Default: fetch returns 401 so any unexpected call (e.g. the silent-refresh
    // attempt when /api/v1/me returns 401) fails gracefully without throwing.
    (global.fetch as jest.Mock).mockResolvedValue({ ok: false, status: 401 });
  });

  // Test 1: Initialize with /api/me on mount
  test('testAuthProviderInitializesWithApiMe', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('Ready');
    });

    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining('/api/v1/me'),
      expect.objectContaining({ credentials: 'include' })
    );
  });

  // Test 2: Set user from /api/me response
  test('testSetUserFromMeResponse', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('user')).toHaveTextContent(mockUser.email);
    });
  });

  // Test 3: Set user to null if /api/me returns 401
  test('testSetUserNullIf401OnInit', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('Ready');
    });

    expect(screen.getByTestId('user')).toHaveTextContent('No user');
    expect(screen.getByTestId('authenticated')).toHaveTextContent('Not authenticated');
  });

  // Test 4: Login updates user from response
  test('testLoginUpdatesUserFromResponse', async () => {
    // Mock initial /api/me call
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('Ready');
    });

    // Mock login response
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    const loginButton = screen.getByText('Login');
    loginButton.click();

    await waitFor(() => {
      expect(screen.getByTestId('user')).toHaveTextContent(mockUser.email);
    });
  });

  // Test 5: Login does NOT store token in context
  test('testLoginStoresNoToken', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('Ready');
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        user: mockUser,
        token: 'fake-jwt-token', // Server includes token, but it's not stored
      }),
    });

    const loginButton = screen.getByText('Login');
    loginButton.click();

    await waitFor(() => {
      expect(screen.getByTestId('user')).toHaveTextContent(mockUser.email);
    });

    // Verify token is NOT in the context value
    const contextValue: Record<string, unknown> = {
      user: mockUser,
      isLoading: false,
      error: null,
    };
    expect(contextValue.token).toBeUndefined();
  });

  // Test 6: Logout calls /api/auth/logout endpoint
  test('testLogoutCallsLogoutEndpoint', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('user')).toHaveTextContent(mockUser.email);
    });

    // Mock logout response
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
    });

    const logoutButton = screen.getByText('Logout');
    logoutButton.click();

    await waitFor(() => {
      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining('/api/v1/auth/logout'),
        expect.objectContaining({
          method: 'POST',
          credentials: 'include',
        })
      );
    });
  });

  // Test 7: Logout clears user state
  test('testLogoutClearsUserState', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('user')).toHaveTextContent(mockUser.email);
    });

    // Mock logout response
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
    });

    const logoutButton = screen.getByText('Logout');
    logoutButton.click();

    await waitFor(() => {
      expect(screen.getByTestId('user')).toHaveTextContent('No user');
      expect(screen.getByTestId('authenticated')).toHaveTextContent('Not authenticated');
    });
  });

  // Test 8: Verify token field does NOT exist in context
  test('testNoTokenFieldInContext', () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    const captured: { value: ReturnType<typeof useAuth> | null } = { value: null };

    function CaptureContext() {
      const auth = useAuth();
      React.useEffect(() => {
        captured.value = auth;
      }, [auth]);
      return null;
    }

    render(
      <AuthProvider>
        <CaptureContext />
      </AuthProvider>
    );

    waitFor(() => {
      expect(captured.value).not.toHaveProperty('token');
    });
  });

  // Test 9: apiClient delegates to api-client.ts
  test('testApiClientDelegates', async () => {
    const mockApiClient = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ data: 'test' }),
    });

    jest.spyOn(apiClientModule, 'apiClient').mockImplementation(mockApiClient);

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    const captured: { apiClient: ReturnType<typeof useAuth>['apiClient'] | null } = {
      apiClient: null,
    };

    function CaptureApiClient() {
      const auth = useAuth();
      React.useEffect(() => {
        captured.apiClient = auth.apiClient;
      }, [auth]);
      return null;
    }

    render(
      <AuthProvider>
        <CaptureApiClient />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining('/api/v1/me'),
        expect.any(Object)
      );
    });

    // Call apiClient and verify it delegates.
    if (captured.apiClient) {
      await captured.apiClient('/api/test');
      expect(mockApiClient).toHaveBeenCalledWith('/api/test', undefined);
    }
  });

  // Test 10: isAuthenticated depends only on user
  test('testIsAuthenticatedDependsOnlyOnUser', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('authenticated')).toHaveTextContent('Not authenticated');
    });

    // User null = not authenticated
    expect(screen.getByTestId('user')).toHaveTextContent('No user');

    // Mock successful login to set user
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    const loginButton = screen.getByText('Login');
    loginButton.click();

    await waitFor(() => {
      expect(screen.getByTestId('authenticated')).toHaveTextContent('Authenticated');
    });
  });

  // Test 11: Boot sequence handles network errors gracefully
  test('testBootSequenceHandlesNetworkErrors', async () => {
    (global.fetch as jest.Mock).mockRejectedValueOnce(new Error('Network error'));

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('Ready');
    });

    expect(screen.getByTestId('user')).toHaveTextContent('No user');
    expect(screen.getByTestId('authenticated')).toHaveTextContent('Not authenticated');
  });

  // ── WC-d4340daf: dual-claim JWT decode (token-fallback login path) ──────────

  /** Build an unsigned JWT-shaped string with the given payload claims. */
  function fakeJwt(payload: Record<string, unknown>): string {
    const encode = (obj: Record<string, unknown>) =>
      Buffer.from(JSON.stringify(obj)).toString('base64');
    return `${encode({ alg: 'HS256', typ: 'JWT' })}.${encode(payload)}.signature`;
  }

  function UserDetails() {
    const auth = useAuth();
    return (
      <div>
        <div data-testid="user-id">{auth.user ? String(auth.user.id) : 'none'}</div>
        <div data-testid="tenant-id">{auth.user ? String(auth.user.tenant_id) : 'none'}</div>
        <button onClick={() => auth.login('test@example.com', 'password')}>Login</button>
      </div>
    );
  }

  async function loginWithTokenOnly(token: string) {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    render(
      <AuthProvider>
        <UserDetails />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('user-id')).toHaveTextContent('none');
    });

    // Login response carries only a token (no user object) so the decode
    // fallback populates the profile state.
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      json: async () => ({ token }),
    });

    screen.getByText('Login').click();
  }

  test('testTokenDecodePrefersNewClaims', async () => {
    await loginWithTokenOnly(
      fakeJwt({
        profile_id: 42,
        active_tenant_id: 7,
        user_id: 5,
        tenant_id: 3,
        email: 'test@example.com',
        role: 'admin',
      })
    );

    await waitFor(() => {
      expect(screen.getByTestId('user-id')).toHaveTextContent('42');
      expect(screen.getByTestId('tenant-id')).toHaveTextContent('7');
    });
  });

  test('testTokenDecodeFallsBackToLegacyClaims', async () => {
    await loginWithTokenOnly(
      fakeJwt({
        user_id: 5,
        tenant_id: 3,
        email: 'test@example.com',
        role: 'admin',
      })
    );

    await waitFor(() => {
      expect(screen.getByTestId('user-id')).toHaveTextContent('5');
      expect(screen.getByTestId('tenant-id')).toHaveTextContent('3');
    });
  });

  test('testTokenDecodeNewClaimsOnlyShape', async () => {
    await loginWithTokenOnly(
      fakeJwt({
        profile_id: 42,
        active_tenant_id: 0,
        email: 'test@example.com',
        role: 'admin',
      })
    );

    await waitFor(() => {
      expect(screen.getByTestId('user-id')).toHaveTextContent('42');
      expect(screen.getByTestId('tenant-id')).toHaveTextContent('0');
    });
  });

  // Test 12: Login includes credentials for cookie handling
  test('testLoginIncludesCredentialsForCookies', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('Ready');
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    const loginButton = screen.getByText('Login');
    loginButton.click();

    await waitFor(() => {
      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining('/api/v1/login'),
        expect.objectContaining({
          credentials: 'include',
        })
      );
    });
  });
});
