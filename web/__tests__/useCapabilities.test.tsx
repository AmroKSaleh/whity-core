import type { ReactNode } from 'react';
import { renderHook, waitFor } from '@testing-library/react';
import { useCapabilities } from '@/hooks/useCapabilities';
import { CapabilitiesProvider } from '@/lib/capabilities-context';
import * as authContext from '@/lib/auth-context';

/**
 * `useCapabilities` reads from the shared `CapabilitiesProvider`, which wraps
 * `GET /api/v1/me/capabilities` and exposes `has`/`hasAny`/`hasAll` (plus the
 * `hasPermission` back-compat alias). Security contract: fail-closed — while
 * loading (including the in-flight window right after the active user or
 * tenant changes) or on any error, every check returns `false`, so callers
 * hide write affordances rather than dangle controls that would 403
 * server-side. Multi-tenant contract: a permission held under one active
 * user/tenant must never leak as held after a switch, before that switch's
 * fetch has resolved.
 */

const mockApiClient = jest.fn<Promise<Response>, [string, (RequestInit | undefined)?]>();

jest.mock('@/lib/auth-context', () => ({
  useAuth: jest.fn(),
}));

function makeResponse(body: unknown, ok = true): Response {
  return {
    ok,
    json: () => Promise.resolve(body),
    status: ok ? 200 : 500,
    headers: new Headers(),
  } as unknown as Response;
}

interface MockUser {
  id: number;
  tenant_id: number;
}

function mockAuth(user: MockUser | null, isLoading = false): void {
  (authContext.useAuth as jest.Mock).mockReturnValue({
    apiClient: mockApiClient,
    user,
    isLoading,
  });
}

function wrapper({ children }: { children: ReactNode }) {
  return <CapabilitiesProvider>{children}</CapabilitiesProvider>;
}

beforeEach(() => {
  jest.clearAllMocks();
  mockAuth({ id: 1, tenant_id: 10 });
});

describe('useCapabilities', () => {
  it('returns loading=true and empty permissions initially', () => {
    // Never resolves — keeps the hook in loading state.
    mockApiClient.mockReturnValue(new Promise(() => undefined));

    const { result } = renderHook(() => useCapabilities(), { wrapper });

    expect(result.current.loading).toBe(true);
    expect(result.current.permissions).toEqual([]);
  });

  it('hasPermission returns false while loading', () => {
    mockApiClient.mockReturnValue(new Promise(() => undefined));

    const { result } = renderHook(() => useCapabilities(), { wrapper });

    expect(result.current.hasPermission('users:write')).toBe(false);
  });

  it('returns parsed permissions after a successful fetch', async () => {
    mockApiClient.mockResolvedValue(
      makeResponse({ data: { permissions: ['users:write', 'users:delete'] } })
    );

    const { result } = renderHook(() => useCapabilities(), { wrapper });

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.permissions).toEqual(['users:write', 'users:delete']);
  });

  it('hasPermission returns true when the permission is present', async () => {
    mockApiClient.mockResolvedValue(
      makeResponse({ data: { permissions: ['users:write', 'roles:write'] } })
    );

    const { result } = renderHook(() => useCapabilities(), { wrapper });

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.hasPermission('users:write')).toBe(true);
    expect(result.current.hasPermission('roles:write')).toBe(true);
  });

  it('hasPermission returns false when the permission is absent', async () => {
    mockApiClient.mockResolvedValue(makeResponse({ data: { permissions: ['users:write'] } }));

    const { result } = renderHook(() => useCapabilities(), { wrapper });

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.hasPermission('users:delete')).toBe(false);
    expect(result.current.hasPermission('tenants:write')).toBe(false);
  });

  it('returns empty permissions on a non-ok fetch response (fail-closed)', async () => {
    mockApiClient.mockResolvedValue(makeResponse({}, false));

    const { result } = renderHook(() => useCapabilities(), { wrapper });

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.permissions).toEqual([]);
  });

  it('hasPermission returns false on a non-ok fetch response', async () => {
    mockApiClient.mockResolvedValue(makeResponse({}, false));

    const { result } = renderHook(() => useCapabilities(), { wrapper });

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.hasPermission('users:write')).toBe(false);
  });

  it('returns empty permissions when the fetch rejects (fail-closed)', async () => {
    mockApiClient.mockRejectedValue(new Error('Network error'));

    const { result } = renderHook(() => useCapabilities(), { wrapper });

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.permissions).toEqual([]);
  });

  it('hasPermission returns false when the fetch rejects', async () => {
    mockApiClient.mockRejectedValue(new Error('Network error'));

    const { result } = renderHook(() => useCapabilities(), { wrapper });

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.hasPermission('users:write')).toBe(false);
  });

  it('fails closed when the response body is malformed', async () => {
    // Valid response but wrong shape — parsePermissions returns [].
    mockApiClient.mockResolvedValue(makeResponse({ wrong: 'shape' }));

    const { result } = renderHook(() => useCapabilities(), { wrapper });

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.permissions).toEqual([]);
    expect(result.current.hasPermission('users:write')).toBe(false);
  });

  it('throws when rendered outside a CapabilitiesProvider', () => {
    // Guards against a page silently getting an undefined context instead of
    // a loud failure — mirrors useAuth/useNavigation/usePluginFeatures.
    const consoleError = jest.spyOn(console, 'error').mockImplementation(() => undefined);
    expect(() => renderHook(() => useCapabilities())).toThrow(
      'useCapabilitiesContext must be used within CapabilitiesProvider'
    );
    consoleError.mockRestore();
  });

  describe('has / hasAny / hasAll', () => {
    beforeEach(() => {
      mockApiClient.mockResolvedValue(
        makeResponse({ data: { permissions: ['users:write', 'roles:write'] } })
      );
    });

    it('has() mirrors hasPermission()', async () => {
      const { result } = renderHook(() => useCapabilities(), { wrapper });
      await waitFor(() => expect(result.current.loading).toBe(false));

      expect(result.current.has('users:write')).toBe(true);
      expect(result.current.has('users:delete')).toBe(false);
    });

    it('has() fails closed while loading', () => {
      mockApiClient.mockReturnValue(new Promise(() => undefined));
      const { result } = renderHook(() => useCapabilities(), { wrapper });

      expect(result.current.has('users:write')).toBe(false);
    });

    it('hasAny() is true when at least one capability is held', async () => {
      const { result } = renderHook(() => useCapabilities(), { wrapper });
      await waitFor(() => expect(result.current.loading).toBe(false));

      expect(result.current.hasAny(['users:delete', 'users:write'])).toBe(true);
      expect(result.current.hasAny(['users:delete', 'tenants:write'])).toBe(false);
    });

    it('hasAny() fails closed while loading', () => {
      mockApiClient.mockReturnValue(new Promise(() => undefined));
      const { result } = renderHook(() => useCapabilities(), { wrapper });

      expect(result.current.hasAny(['users:write'])).toBe(false);
    });

    it('hasAll() is true only when every capability is held', async () => {
      const { result } = renderHook(() => useCapabilities(), { wrapper });
      await waitFor(() => expect(result.current.loading).toBe(false));

      expect(result.current.hasAll(['users:write', 'roles:write'])).toBe(true);
      expect(result.current.hasAll(['users:write', 'users:delete'])).toBe(false);
    });

    it('hasAll() fails closed while loading', () => {
      mockApiClient.mockReturnValue(new Promise(() => undefined));
      const { result } = renderHook(() => useCapabilities(), { wrapper });

      expect(result.current.hasAll(['users:write'])).toBe(false);
    });
  });

  describe('refetch on active user/tenant change (multi-tenant leak prevention)', () => {
    it('clears permissions and skips the fetch when signed out', () => {
      mockAuth(null);

      const { result } = renderHook(() => useCapabilities(), { wrapper });

      expect(result.current.loading).toBe(false);
      expect(result.current.permissions).toEqual([]);
      expect(mockApiClient).not.toHaveBeenCalled();
    });

    it('does not fetch while auth is still resolving', () => {
      mockAuth({ id: 1, tenant_id: 10 }, true);

      const { result } = renderHook(() => useCapabilities(), { wrapper });

      expect(result.current.loading).toBe(true);
      expect(mockApiClient).not.toHaveBeenCalled();
    });

    it('re-renders for the same user/tenant do not trigger a second fetch', async () => {
      mockApiClient.mockResolvedValue(makeResponse({ data: { permissions: ['users:write'] } }));

      const { result, rerender } = renderHook(() => useCapabilities(), { wrapper });
      await waitFor(() => expect(result.current.loading).toBe(false));

      rerender();
      rerender();

      expect(mockApiClient).toHaveBeenCalledTimes(1);
    });

    it('refetches when the active user changes, discarding the previous user’s permissions', async () => {
      mockApiClient.mockResolvedValue(makeResponse({ data: { permissions: ['users:write'] } }));

      const { result, rerender } = renderHook(() => useCapabilities(), { wrapper });
      await waitFor(() => expect(result.current.loading).toBe(false));
      expect(result.current.has('users:write')).toBe(true);

      mockApiClient.mockResolvedValue(makeResponse({ data: { permissions: ['tags:read'] } }));
      mockAuth({ id: 2, tenant_id: 10 });
      rerender();

      await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(2));
      await waitFor(() => expect(result.current.has('tags:read')).toBe(true));
      expect(result.current.has('users:write')).toBe(false);
    });

    it('refetches when the ACTIVE TENANT changes and fails closed until the new fetch resolves', async () => {
      mockApiClient.mockResolvedValue(makeResponse({ data: { permissions: ['tenant-a:read'] } }));

      const { result, rerender } = renderHook(() => useCapabilities(), { wrapper });
      await waitFor(() => expect(result.current.loading).toBe(false));
      expect(result.current.has('tenant-a:read')).toBe(true);

      // Switch the active tenant (same profile, new tenant_id). Hold the new
      // fetch open so the in-flight window is observable.
      let resolveSecondFetch: (value: Response) => void = () => undefined;
      mockApiClient.mockReturnValue(
        new Promise<Response>((resolve) => {
          resolveSecondFetch = resolve;
        })
      );
      mockAuth({ id: 1, tenant_id: 20 });
      rerender();

      // The critical assertion: tenant A's permission must NOT still read as
      // held just because the new fetch hasn't resolved yet.
      expect(result.current.loading).toBe(true);
      expect(result.current.has('tenant-a:read')).toBe(false);
      expect(result.current.permissions).toEqual([]);

      resolveSecondFetch(makeResponse({ data: { permissions: ['tenant-b:read'] } }));

      await waitFor(() => expect(result.current.loading).toBe(false));
      expect(result.current.has('tenant-b:read')).toBe(true);
      expect(result.current.has('tenant-a:read')).toBe(false);
    });
  });
});
