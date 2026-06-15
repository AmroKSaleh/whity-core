import { renderHook, waitFor } from '@testing-library/react';
import { useCapabilities } from '@/hooks/useCapabilities';
import * as authContext from '@/lib/auth-context';

/**
 * `useCapabilities` wraps `GET /api/me/capabilities` via `useFetch` and
 * exposes a `hasPermission` predicate. Security contract: fail-closed — while
 * loading or on any error, `permissions` is `[]` and `hasPermission` returns
 * `false`, so callers hide write affordances rather than dangle controls that
 * would 403 server-side.
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

beforeEach(() => {
  jest.clearAllMocks();
  (authContext.useAuth as jest.Mock).mockReturnValue({ apiClient: mockApiClient });
});

describe('useCapabilities', () => {
  it('returns loading=true and empty permissions initially', () => {
    // Never resolves — keeps the hook in loading state.
    mockApiClient.mockReturnValue(new Promise(() => undefined));

    const { result } = renderHook(() => useCapabilities());

    expect(result.current.loading).toBe(true);
    expect(result.current.permissions).toEqual([]);
  });

  it('hasPermission returns false while loading', () => {
    mockApiClient.mockReturnValue(new Promise(() => undefined));

    const { result } = renderHook(() => useCapabilities());

    expect(result.current.hasPermission('users:write')).toBe(false);
  });

  it('returns parsed permissions after a successful fetch', async () => {
    mockApiClient.mockResolvedValue(
      makeResponse({ data: { permissions: ['users:write', 'users:delete'] } })
    );

    const { result } = renderHook(() => useCapabilities());

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.permissions).toEqual(['users:write', 'users:delete']);
  });

  it('hasPermission returns true when the permission is present', async () => {
    mockApiClient.mockResolvedValue(
      makeResponse({ data: { permissions: ['users:write', 'roles:write'] } })
    );

    const { result } = renderHook(() => useCapabilities());

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.hasPermission('users:write')).toBe(true);
    expect(result.current.hasPermission('roles:write')).toBe(true);
  });

  it('hasPermission returns false when the permission is absent', async () => {
    mockApiClient.mockResolvedValue(
      makeResponse({ data: { permissions: ['users:write'] } })
    );

    const { result } = renderHook(() => useCapabilities());

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.hasPermission('users:delete')).toBe(false);
    expect(result.current.hasPermission('tenants:write')).toBe(false);
  });

  it('returns empty permissions on a non-ok fetch response (fail-closed)', async () => {
    mockApiClient.mockResolvedValue(makeResponse({}, false));

    const { result } = renderHook(() => useCapabilities());

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.permissions).toEqual([]);
  });

  it('hasPermission returns false on a non-ok fetch response', async () => {
    mockApiClient.mockResolvedValue(makeResponse({}, false));

    const { result } = renderHook(() => useCapabilities());

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.hasPermission('users:write')).toBe(false);
  });

  it('returns empty permissions when the fetch rejects (fail-closed)', async () => {
    mockApiClient.mockRejectedValue(new Error('Network error'));

    const { result } = renderHook(() => useCapabilities());

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.permissions).toEqual([]);
  });

  it('hasPermission returns false when the fetch rejects', async () => {
    mockApiClient.mockRejectedValue(new Error('Network error'));

    const { result } = renderHook(() => useCapabilities());

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.hasPermission('users:write')).toBe(false);
  });

  it('fails closed when the response body is malformed', async () => {
    // Valid response but wrong shape — parsePermissions returns [].
    mockApiClient.mockResolvedValue(makeResponse({ wrong: 'shape' }));

    const { result } = renderHook(() => useCapabilities());

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.permissions).toEqual([]);
    expect(result.current.hasPermission('users:write')).toBe(false);
  });
});
