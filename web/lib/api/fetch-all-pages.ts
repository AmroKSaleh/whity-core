/**
 * Exhaust a paginated list endpoint.
 *
 * Every core list endpoint is paginated (`PaginationParams`: 25 per page by
 * default, 100 maximum) and answers with `{ data, pagination }`. A screen that
 * needs the WHOLE set — a hierarchy, a picker, anything it will present as
 * complete — cannot get it from one request, and the common workaround of
 * asking for `per_page=100` only moves the cliff: at 101 rows it starts
 * dropping data again, silently, because a short page is indistinguishable
 * from a small dataset once the envelope is discarded.
 *
 * So this walks `pagination.totalPages` to the end and, crucially, reports
 * whether it got there. Callers MUST branch on `complete` — the whole point is
 * that a truncated set is never mistaken for the full one.
 *
 * Two entry points, one walk: {@link fetchAllPages} for the raw `apiClient`
 * from `useAuth()`, {@link fetchAllPagesTyped} for the openapi-fetch client in
 * `lib/api/client`. They differ only in how a page is requested and how failure
 * is signalled (a non-ok `Response` versus an undefined `data`), so the
 * termination and completeness rules — the parts that are easy to get subtly
 * wrong — exist exactly once, in `walkPages`.
 */

type ApiClient = (url: string, options?: RequestInit) => Promise<Response>;

/** The `pagination` block every paginated core endpoint returns. */
export interface PaginationEnvelope {
  page: number;
  perPage: number;
  total: number;
  totalPages: number;
}

export type FetchAllPagesResult<T> =
  | { complete: true; items: T[]; total: number }
  /**
   * A page request failed, or the walk hit its safety cap. `items` holds what
   * did arrive (useful for telling the user how much is missing) and `total`
   * is the server's row count, or null if even the first page failed.
   */
  | { complete: false; items: T[]; total: number | null };

/**
 * The server clamps `per_page` to this, so asking for more wastes nothing but
 * gains nothing either. Using the maximum keeps the request count low.
 */
export const MAX_PER_PAGE = 100;

/**
 * Refuse to issue more than this many requests for one list. At the maximum
 * page size that is a million rows — far past the point where a screen should
 * be loading everything into memory — so hitting it means the server is
 * reporting a `totalPages` that never terminates. Bailing out returns an
 * incomplete result instead of hanging the tab.
 */
const MAX_REQUESTS = 100;

/**
 * Whether a response's `pagination` field is one of those envelopes.
 *
 * Exported because a caller that fetches the first page itself — `usePluginData`
 * requests a plugin's `source` verbatim before it knows anything about it —
 * needs the SAME test the walk uses to decide whether more pages exist. Two
 * tests would be two chances to disagree about what "paginated" means.
 */
export function isPaginationEnvelope(
  value: unknown
): value is PaginationEnvelope {
  return (
    typeof value === 'object' &&
    value !== null &&
    typeof (value as PaginationEnvelope).totalPages === 'number' &&
    typeof (value as PaginationEnvelope).total === 'number'
  );
}

/** One page as the walk needs to see it, whichever client fetched it. */
type PageOutcome<T> =
  | { ok: false }
  | { ok: true; rows: T[] | undefined; pagination: unknown };

/**
 * The walk itself: request pages until the envelope says there are no more,
 * then decide whether the set is whole.
 */
async function walkPages<T>(
  fetchPage: (page: number, perPage: number) => Promise<PageOutcome<T>>,
  perPage: number
): Promise<FetchAllPagesResult<T>> {
  const items: T[] = [];
  let total: number | null = null;
  let totalPages = 1;

  for (let page = 1; page <= totalPages; page++) {
    if (page > MAX_REQUESTS) {
      return { complete: false, items, total };
    }

    const outcome = await fetchPage(page, perPage);
    if (!outcome.ok) {
      return { complete: false, items, total };
    }

    const { rows, pagination } = outcome;
    items.push(...(Array.isArray(rows) ? rows : []));

    if (!isPaginationEnvelope(pagination)) {
      // No envelope means the endpoint is not paginated; what we have is all
      // there is. Treating this as incomplete would break unpaginated callers.
      return { complete: true, items, total: items.length };
    }

    total = pagination.total;
    totalPages = pagination.totalPages;

    // A page that claims successors but delivers no rows would otherwise spin
    // until the request cap. Stop at the first empty page instead.
    if (!Array.isArray(rows) || rows.length === 0) {
      break;
    }
  }

  // The row count is the contract, not the page count: a concurrent insert can
  // grow `total` mid-walk, leaving us a page short of a set we would otherwise
  // present as whole.
  if (total !== null && items.length < total) {
    return { complete: false, items, total };
  }

  return { complete: true, items, total: total ?? items.length };
}

/**
 * Fetch every page of `path` and concatenate the `data` arrays.
 *
 * @param apiClient The authenticated fetch wrapper from `useAuth()`.
 * @param path      List endpoint path; an existing query string is preserved.
 * @param perPage   Rows per request. Defaults to the server maximum.
 */
export async function fetchAllPages<T>(
  apiClient: ApiClient,
  path: string,
  perPage: number = MAX_PER_PAGE
): Promise<FetchAllPagesResult<T>> {
  const separator = path.includes('?') ? '&' : '?';

  return walkPages<T>(async (page, size) => {
    const response = await apiClient(
      `${path}${separator}page=${page}&per_page=${size}`
    );
    if (!response.ok) {
      return { ok: false };
    }

    const body: unknown = await response.json();
    return {
      ok: true,
      rows: (body as { data?: T[] } | null)?.data,
      pagination: (body as { pagination?: unknown } | null)?.pagination,
    };
  }, perPage);
}

/**
 * What one `api.GET(...)` call resolves to. The typed client never throws on an
 * HTTP error — it leaves `data` undefined and puts the parsed body in `error` —
 * so an absent `data` is the only failure signal there is.
 */
export interface TypedPageResult<T> {
  data?: { data?: T[]; pagination?: unknown };
}

/**
 * Same walk for the typed client. It takes the request as a closure rather than
 * a path because openapi-fetch derives the response type from the path
 * literal — `api.GET('/api/v1/ous', …)` typed at the call site keeps that, a
 * path passed through this helper as a string would not.
 *
 * ```ts
 * const result = await fetchAllPagesTyped<Ou>((query) =>
 *   api.GET('/api/v1/ous', { params: { query } })
 * );
 * ```
 *
 * @param fetchPage Issues one request for the given page.
 * @param perPage   Rows per request. Defaults to the server maximum.
 */
export async function fetchAllPagesTyped<T>(
  fetchPage: (query: {
    page: number;
    per_page: number;
  }) => Promise<TypedPageResult<T>>,
  perPage: number = MAX_PER_PAGE
): Promise<FetchAllPagesResult<T>> {
  return walkPages<T>(async (page, size) => {
    const { data } = await fetchPage({ page, per_page: size });
    if (data === undefined) {
      return { ok: false };
    }
    return { ok: true, rows: data.data, pagination: data.pagination };
  }, perPage);
}
