import { renderHook } from '@testing-library/react';
import { useActionPermission } from '@/hooks/useActionPermission';
import * as capabilities from '@/hooks/useCapabilities';
import type { UseCapabilitiesResult } from '@/hooks/useCapabilities';

/**
 * `useActionPermission` derives a UI-gating decision from `useCapabilities`.
 * It is the single source of the HYBRID policy:
 *   - holds the permission        → allowed (visible, enabled)
 *   - lacks it, non-destructive   → disabled + tooltip reason (still visible)
 *   - lacks it, destructive       → hidden (don't tempt the click at all)
 * Fail-closed: while capabilities load, treat the caller as lacking the
 * permission, mirroring `useCapabilities`'s own loading semantics.
 */

jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: jest.fn(),
}));

const mockedUseCapabilities = capabilities.useCapabilities as jest.MockedFunction<
  typeof capabilities.useCapabilities
>;

/**
 * Builds a `useCapabilities` return value whose `hasPermission` honours both
 * the loading flag (fail-closed) and a fixed set of granted slugs.
 */
function makeCapabilities(granted: string[], loading = false): UseCapabilitiesResult {
  return {
    permissions: loading ? [] : granted,
    loading,
    hasPermission: (slug: string): boolean => {
      if (loading) return false;
      return granted.includes(slug);
    },
  };
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('useActionPermission', () => {
  it('allows the action when the caller holds the permission', () => {
    mockedUseCapabilities.mockReturnValue(makeCapabilities(['users:write']));

    const { result } = renderHook(() => useActionPermission('users:write'));

    expect(result.current).toEqual({
      allowed: true,
      hidden: false,
      disabled: false,
      reason: null,
    });
  });

  it('disables (but shows) a non-destructive action when the permission is missing', () => {
    mockedUseCapabilities.mockReturnValue(makeCapabilities(['other:read']));

    const { result } = renderHook(() => useActionPermission('users:write'));

    expect(result.current).toEqual({
      allowed: false,
      hidden: false,
      disabled: true,
      reason: 'Requires users:write',
    });
  });

  it('treats the default (no opts) as non-destructive', () => {
    mockedUseCapabilities.mockReturnValue(makeCapabilities([]));

    const { result } = renderHook(() => useActionPermission('roles:write'));

    expect(result.current.hidden).toBe(false);
    expect(result.current.disabled).toBe(true);
    expect(result.current.reason).toBe('Requires roles:write');
  });

  it('hides a destructive action when the permission is missing', () => {
    mockedUseCapabilities.mockReturnValue(makeCapabilities(['other:read']));

    const { result } = renderHook(() =>
      useActionPermission('users:delete', { destructive: true })
    );

    expect(result.current.allowed).toBe(false);
    expect(result.current.hidden).toBe(true);
    expect(result.current.disabled).toBe(false);
    expect(result.current.reason).toBe('Requires users:delete');
  });

  it('allows a destructive action when the permission IS held (not hidden)', () => {
    mockedUseCapabilities.mockReturnValue(makeCapabilities(['users:delete']));

    const { result } = renderHook(() =>
      useActionPermission('users:delete', { destructive: true })
    );

    expect(result.current).toEqual({
      allowed: true,
      hidden: false,
      disabled: false,
      reason: null,
    });
  });

  it('fails closed while capabilities are loading (non-destructive → disabled)', () => {
    // Even though the slug is "granted", loading forces hasPermission → false.
    mockedUseCapabilities.mockReturnValue(makeCapabilities(['users:write'], true));

    const { result } = renderHook(() => useActionPermission('users:write'));

    expect(result.current.allowed).toBe(false);
    expect(result.current.disabled).toBe(true);
    expect(result.current.hidden).toBe(false);
    expect(result.current.reason).toBe('Requires users:write');
  });

  it('fails closed while capabilities are loading (destructive → hidden)', () => {
    mockedUseCapabilities.mockReturnValue(makeCapabilities(['users:delete'], true));

    const { result } = renderHook(() =>
      useActionPermission('users:delete', { destructive: true })
    );

    expect(result.current.allowed).toBe(false);
    expect(result.current.hidden).toBe(true);
    expect(result.current.disabled).toBe(false);
    expect(result.current.reason).toBe('Requires users:delete');
  });
});
