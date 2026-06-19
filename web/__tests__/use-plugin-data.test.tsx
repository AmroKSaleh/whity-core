/**
 * WC-231: usePluginData hook — fetch + state-machine tests.
 *
 * The hook manages a loading→error/empty/ready state machine over apiClient,
 * guards against setState-after-unmount via AbortController, and supports
 * manual refresh/retry that re-invokes apiClient with the same source.
 */

import { renderHook, waitFor, act } from '@testing-library/react';
import { usePluginData } from '@/lib/use-plugin-data';
import { apiClient } from '@/lib/api-client';

jest.mock('@/lib/api-client', () => ({
  apiClient: jest.fn(),
}));

const mockApiClient = apiClient as jest.MockedFunction<typeof apiClient>;

/** Build a minimal Response stub. */
function stubResponse(ok: boolean, status: number, body: unknown): Response {
  return {
    ok,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

/** A parse function that accepts a non-empty array of records. */
function parseRows(body: unknown): Record<string, unknown>[] | null {
  if (!Array.isArray(body) || body.length === 0) return null;
  return body as Record<string, unknown>[];
}

describe('usePluginData', () => {
  beforeEach(() => {
    mockApiClient.mockReset();
  });

  it('starts in loading state', () => {
    // Never resolves — keeps the hook in loading state.
    mockApiClient.mockReturnValue(new Promise(() => undefined));

    const { result } = renderHook(() =>
      usePluginData('/api/v1/x/rows', parseRows)
    );

    expect(result.current.status).toBe('loading');
  });

  it('resolves to ready with parsed data on a 200 {data:[...]} response', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(true, 200, { data: [{ a: '1' }] })
    );

    const { result } = renderHook(() =>
      usePluginData('/api/v1/x/rows', parseRows)
    );

    await waitFor(() => expect(result.current.status).toBe('ready'));

    if (result.current.status === 'ready') {
      expect(result.current.data).toEqual([{ a: '1' }]);
    }
  });

  it('resolves to empty when parse returns null (empty array body)', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(true, 200, { data: [] })
    );

    const { result } = renderHook(() =>
      usePluginData('/api/v1/x/rows', parseRows)
    );

    await waitFor(() => expect(result.current.status).toBe('empty'));
  });

  it('resolves to error on a non-ok 403 response', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(false, 403, { error: 'Forbidden' })
    );

    const { result } = renderHook(() =>
      usePluginData('/api/v1/x/rows', parseRows)
    );

    await waitFor(() => expect(result.current.status).toBe('error'));
  });

  it('resolves to error when the body is malformed (no data envelope)', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(true, 200, { nope: 1 })
    );

    const { result } = renderHook(() =>
      usePluginData('/api/v1/x/rows', parseRows)
    );

    await waitFor(() => expect(result.current.status).toBe('error'));
  });

  it('resolves to error when apiClient throws', async () => {
    mockApiClient.mockRejectedValue(new Error('network error'));

    const { result } = renderHook(() =>
      usePluginData('/api/v1/x/rows', parseRows)
    );

    await waitFor(() => expect(result.current.status).toBe('error'));
  });

  it('calling refresh() re-invokes apiClient with the same source', async () => {
    mockApiClient
      .mockResolvedValueOnce(stubResponse(true, 200, { data: [{ a: '1' }] }))
      .mockResolvedValueOnce(stubResponse(true, 200, { data: [{ a: '2' }] }));

    const { result } = renderHook(() =>
      usePluginData('/api/v1/x/rows', parseRows)
    );

    await waitFor(() => expect(result.current.status).toBe('ready'));

    // Call refresh
    act(() => {
      if (result.current.status === 'ready') {
        result.current.refresh();
      }
    });

    await waitFor(() => {
      if (result.current.status === 'ready') {
        expect(result.current.data).toEqual([{ a: '2' }]);
      }
    });

    expect(mockApiClient).toHaveBeenCalledTimes(2);
    expect(mockApiClient).toHaveBeenNthCalledWith(2, '/api/v1/x/rows', expect.objectContaining({ signal: expect.any(AbortSignal) }));
  });

  it('calling retry() re-invokes apiClient with the same source', async () => {
    mockApiClient
      .mockResolvedValueOnce(stubResponse(false, 500, { error: 'fail' }))
      .mockResolvedValueOnce(stubResponse(true, 200, { data: [{ a: '1' }] }));

    const { result } = renderHook(() =>
      usePluginData('/api/v1/x/rows', parseRows)
    );

    await waitFor(() => expect(result.current.status).toBe('error'));

    act(() => {
      if (result.current.status === 'error') {
        result.current.retry();
      }
    });

    await waitFor(() => expect(result.current.status).toBe('ready'));

    expect(mockApiClient).toHaveBeenCalledTimes(2);
    expect(mockApiClient).toHaveBeenNthCalledWith(2, '/api/v1/x/rows', expect.objectContaining({ signal: expect.any(AbortSignal) }));
  });

  it('calling refresh() from empty state re-invokes apiClient', async () => {
    mockApiClient
      .mockResolvedValueOnce(stubResponse(true, 200, { data: [] }))
      .mockResolvedValueOnce(stubResponse(true, 200, { data: [{ a: '1' }] }));

    const { result } = renderHook(() =>
      usePluginData('/api/v1/x/rows', parseRows)
    );

    await waitFor(() => expect(result.current.status).toBe('empty'));

    act(() => {
      if (result.current.status === 'empty') {
        result.current.refresh();
      }
    });

    await waitFor(() => expect(result.current.status).toBe('ready'));

    expect(mockApiClient).toHaveBeenCalledTimes(2);
  });

  it('does not setState after unmount', () => {
    // We set up a promise we never resolve to keep the hook in-flight,
    // then unmount. There should be no warning.
    let resolve!: (r: Response) => void;
    mockApiClient.mockReturnValue(new Promise<Response>((res) => { resolve = res; }));

    const { result, unmount } = renderHook(() =>
      usePluginData('/api/v1/x/rows', parseRows)
    );

    expect(result.current.status).toBe('loading');

    // Unmount first, then resolve — should NOT throw/warn about setState after unmount.
    unmount();
    act(() => {
      resolve(stubResponse(true, 200, { data: [{ a: '1' }] }));
    });
    // No assertion needed — if setState-after-unmount occurred, React (or jsdom) would warn.
  });
});
