/**
 * Desktop equivalent of `web/lib/api/fetch-all-pages.ts`: exhaust a paginated
 * list endpoint through the PHP host proxy.
 *
 * The rules are web's, deliberately unchanged — walk `pagination.totalPages` to
 * the end, stop at the first empty page, cap the request count, and report
 * `complete` so a caller can never mistake a short set for the whole one. Only
 * the transport differs: `invoke("php_request", ...)`, whose failure signal is
 * a non-2xx `status` rather than a non-ok `Response`.
 *
 * It is a copy rather than an import because this template ships to consumers
 * who get `@amroksaleh/*` from the registry and no `web/` at all, and the
 * helper lives in the unpublished web app. Hoisting it into
 * `@amroksaleh/features` would make it importable from both, at the cost of
 * moving a file four core screens already depend on and pinning the template to
 * a newer package version — a packaging change, not this fix. The parity tests
 * are what keep the copy honest (#847, #850).
 */

import { invoke } from "@tauri-apps/api/core"

/** The `pagination` block every paginated core endpoint returns. */
export interface PaginationEnvelope {
  page: number
  perPage: number
  total: number
  totalPages: number
}

export type FetchAllPagesResult<T> =
  | { complete: true; items: T[]; total: number }
  /** A page request failed, or the walk hit its cap. `items` is what arrived. */
  | { complete: false; items: T[]; total: number | null }

/** The server clamps `per_page` to this, so asking for more gains nothing. */
export const MAX_PER_PAGE = 100

/**
 * Refuse to issue more than this many requests for one list. Reaching it means
 * the server is reporting a `totalPages` that never terminates, or the source
 * is far past the size a block should be loading whole; either way the walk
 * bails out incomplete instead of hanging the window.
 */
const MAX_REQUESTS = 100

export function isPaginationEnvelope(value: unknown): value is PaginationEnvelope {
  return (
    typeof value === "object" &&
    value !== null &&
    typeof (value as PaginationEnvelope).totalPages === "number" &&
    typeof (value as PaginationEnvelope).total === "number"
  )
}

export interface PhpResponse {
  status: number
  body: unknown
}

/**
 * Fetch every page of `path` and concatenate the `data` arrays.
 *
 * @param path      List endpoint path; an existing query string is preserved.
 * @param request   Issues one request. Injected so the caller keeps ownership of
 *                  its hang guard rather than this file growing a second one.
 * @param perPage   Rows per request. Defaults to the server maximum.
 */
export async function fetchAllPages<T>(
  path: string,
  request: (path: string) => Promise<PhpResponse>,
  perPage: number = MAX_PER_PAGE,
): Promise<FetchAllPagesResult<T>> {
  const separator = path.includes("?") ? "&" : "?"
  const items: T[] = []
  let total: number | null = null
  let totalPages = 1

  for (let page = 1; page <= totalPages; page++) {
    if (page > MAX_REQUESTS) {
      return { complete: false, items, total }
    }

    const response = await request(`${path}${separator}page=${page}&per_page=${perPage}`)
    if (response.status < 200 || response.status >= 300) {
      return { complete: false, items, total }
    }

    const body = response.body as { data?: T[]; pagination?: unknown } | null
    const rows = body?.data
    items.push(...(Array.isArray(rows) ? rows : []))

    if (!isPaginationEnvelope(body?.pagination)) {
      // No envelope means the endpoint is not paginated; what we have is all
      // there is. Treating this as incomplete would break unpaginated callers.
      return { complete: true, items, total: items.length }
    }

    total = body.pagination.total
    totalPages = body.pagination.totalPages

    // A page that claims successors but delivers no rows would otherwise spin
    // until the request cap. Stop at the first empty page instead.
    if (!Array.isArray(rows) || rows.length === 0) {
      break
    }
  }

  // The row count is the contract, not the page count: a concurrent insert can
  // grow `total` mid-walk, leaving us a page short of a set we would otherwise
  // present as whole.
  if (total !== null && items.length < total) {
    return { complete: false, items, total }
  }

  return { complete: true, items, total: total ?? items.length }
}

/** The transport the hook injects: one `php_request` GET, no retry logic. */
export function phpRequest(path: string): Promise<PhpResponse> {
  return invoke<PhpResponse>("php_request", { method: "GET", path, body: null })
}
