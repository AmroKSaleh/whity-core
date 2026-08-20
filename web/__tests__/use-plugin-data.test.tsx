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

/** PaginationParams::DEFAULT_PER_PAGE — what a request with no `per_page` gets. */
const SERVER_DEFAULT_PER_PAGE = 25;
/** PaginationParams::MAX_PER_PAGE — the server hard-clamps to this. */
const SERVER_MAX_PER_PAGE = 100;

function makeRows(count: number) {
  return Array.from({ length: count }, (_, i) => ({
    id: i + 1,
    name: `Row ${String(i + 1).padStart(String(count).length, '0')}`,
  }));
}

/**
 * Answer the way a paginated core endpoint answers: a `data` slice plus the
 * `pagination` envelope, with `per_page` defaulted and clamped exactly as the
 * server clamps it. The clamp is the point — no single request can return more
 * than 100 rows, so a client that trusts one request is wrong at any page size.
 */
function servePaginated(
  dataset: Record<string, unknown>[],
  url: string
): Promise<Response> {
  const query = new URLSearchParams(url.split('?')[1] ?? '');
  const perPage = Math.min(
    Number(query.get('per_page') ?? SERVER_DEFAULT_PER_PAGE),
    SERVER_MAX_PER_PAGE
  );
  const page = Number(query.get('page') ?? '1');
  const offset = (page - 1) * perPage;

  return Promise.resolve(
    stubResponse(true, 200, {
      data: dataset.slice(offset, offset + perPage),
      pagination: {
        page,
        perPage,
        total: dataset.length,
        totalPages: Math.ceil(dataset.length / perPage),
      },
    })
  );
}

function requestedUrls(): string[] {
  return mockApiClient.mock.calls.map((call) => String(call[0]));
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

/**
 * #867: a data-bound block presents its `source` as the whole collection, and
 * one request is one page of 25. These pin the four properties that decide
 * whether that is safe: later pages are fetched, a walk that cannot be finished
 * is reported rather than rendered, the walk is bounded, and — the regression
 * risk — a source that is not paginated is fetched exactly as it always was.
 */
describe('usePluginData pagination', () => {
  beforeEach(() => {
    mockApiClient.mockReset();
  });

  it('loads rows that fall beyond the server default page size', async () => {
    // 48 rows: the shape of the instance whose report reached us as "the
    // department was never created". 23 of them used to vanish.
    const dataset = makeRows(48);
    mockApiClient.mockImplementation((url) =>
      servePaginated(dataset, String(url))
    );

    const { result } = renderHook(() =>
      usePluginData('/api/v1/x/rows', parseRows)
    );

    await waitFor(() => expect(result.current.status).toBe('ready'));

    if (result.current.status === 'ready') {
      expect(result.current.data).toHaveLength(48);
      expect(result.current.data[47]).toEqual({ id: 48, name: 'Row 48' });
    }
  });

  it('loads every row past the server maximum page size', async () => {
    // 240 rows cannot arrive in one request at ANY page size, because the
    // server clamps per_page to 100. This is the case a bare `?per_page=100`
    // in the block's source would still get wrong.
    const dataset = makeRows(240);
    mockApiClient.mockImplementation((url) =>
      servePaginated(dataset, String(url))
    );

    const { result } = renderHook(() =>
      usePluginData('/api/v1/x/rows', parseRows)
    );

    await waitFor(() => expect(result.current.status).toBe('ready'));

    if (result.current.status === 'ready') {
      expect(result.current.data).toHaveLength(240);
    }
    // The verbatim probe, then three pages at the server maximum.
    expect(requestedUrls()).toEqual([
      '/api/v1/x/rows',
      '/api/v1/x/rows?page=1&per_page=100',
      '/api/v1/x/rows?page=2&per_page=100',
      '/api/v1/x/rows?page=3&per_page=100',
    ]);
  });

  it('leaves an unpaginated source fetched exactly as before', async () => {
    // The regression this change must not cause: a plugin route answering a
    // bare `{data}` is still requested once, at the URL the block declared,
    // with no pagination parameters bolted on.
    mockApiClient.mockResolvedValue(
      stubResponse(true, 200, { data: makeRows(40) })
    );

    const { result } = renderHook(() =>
      usePluginData('/api/v1/x/rows', parseRows)
    );

    await waitFor(() => expect(result.current.status).toBe('ready'));

    if (result.current.status === 'ready') {
      expect(result.current.data).toHaveLength(40);
    }
    expect(requestedUrls()).toEqual(['/api/v1/x/rows']);
  });

  it('does not re-request a paginated source that already arrived whole', async () => {
    // An envelope is not by itself a reason to walk. Most block sources are
    // small, and a second round-trip for a set we already hold would be a cost
    // paid by every block on the platform.
    mockApiClient.mockImplementation((url) =>
      servePaginated(makeRows(3), String(url))
    );

    const { result } = renderHook(() =>
      usePluginData('/api/v1/x/rows', parseRows)
    );

    await waitFor(() => expect(result.current.status).toBe('ready'));

    expect(requestedUrls()).toEqual(['/api/v1/x/rows']);
  });

  it('appends to a source that already carries a query string', async () => {
    // `params` compose a query string onto the source (a master-detail
    // selection, a `?type=` filter), so the walk must extend it rather than
    // start a new one — otherwise the later pages answer a different query.
    const dataset = makeRows(120);
    mockApiClient.mockImplementation((url) =>
      servePaginated(dataset, String(url))
    );

    const { result } = renderHook(() =>
      usePluginData('/api/v1/x/rows?parent_id=7', parseRows)
    );

    await waitFor(() => expect(result.current.status).toBe('ready'));

    expect(requestedUrls().slice(1)).toEqual([
      '/api/v1/x/rows?parent_id=7&page=1&per_page=100',
      '/api/v1/x/rows?parent_id=7&page=2&per_page=100',
    ]);
  });

  it('reports a walk it could not finish instead of a short list', async () => {
    // #824's finding: a block that omits a row is read as data that does not
    // exist. A failed second page must therefore reach the operator as an
    // error with a retry, never as the 100 rows that did arrive.
    const dataset = makeRows(240);
    const parse = jest.fn(parseRows);
    mockApiClient.mockImplementation((url) => {
      const query = new URLSearchParams(String(url).split('?')[1] ?? '');
      if (query.get('page') === '2') {
        return Promise.resolve(stubResponse(false, 500, { error: 'boom' }));
      }
      return servePaginated(dataset, String(url));
    });

    const { result } = renderHook(() => usePluginData('/api/v1/x/rows', parse));

    await waitFor(() => expect(result.current.status).toBe('error'));

    // Nothing was ever handed to the block: no partial render, not even for a
    // frame.
    expect(parse).not.toHaveBeenCalled();
  });

  it('retries a failed walk from the beginning', async () => {
    const dataset = makeRows(240);
    let failPageTwo = true;
    mockApiClient.mockImplementation((url) => {
      const query = new URLSearchParams(String(url).split('?')[1] ?? '');
      if (failPageTwo && query.get('page') === '2') {
        return Promise.resolve(stubResponse(false, 500, { error: 'boom' }));
      }
      return servePaginated(dataset, String(url));
    });

    const { result } = renderHook(() =>
      usePluginData('/api/v1/x/rows', parseRows)
    );

    await waitFor(() => expect(result.current.status).toBe('error'));

    failPageTwo = false;
    act(() => {
      if (result.current.status === 'error') result.current.retry();
    });

    await waitFor(() => expect(result.current.status).toBe('ready'));
    if (result.current.status === 'ready') {
      expect(result.current.data).toHaveLength(240);
    }
  });

  it('surfaces the request cap rather than truncating at it', async () => {
    // A server whose `totalPages` never terminates — the accidentally-huge
    // endpoint, or a broken envelope. The walk is bounded at 100 requests, and
    // reaching that bound is a failure, not a quiet stop with what it had.
    const parse = jest.fn(parseRows);
    mockApiClient.mockImplementation((url) => {
      const query = new URLSearchParams(String(url).split('?')[1] ?? '');
      return Promise.resolve(
        stubResponse(true, 200, {
          data: makeRows(SERVER_MAX_PER_PAGE),
          pagination: {
            page: Number(query.get('page') ?? '1'),
            perPage: SERVER_MAX_PER_PAGE,
            total: 1_000_000,
            totalPages: 10_000,
          },
        })
      );
    });

    const { result } = renderHook(() => usePluginData('/api/v1/x/rows', parse));

    await waitFor(() => expect(result.current.status).toBe('error'));

    // The probe plus the cap — not the 10 000 pages the server claimed.
    expect(mockApiClient).toHaveBeenCalledTimes(101);
    expect(parse).not.toHaveBeenCalled();
  });
});
