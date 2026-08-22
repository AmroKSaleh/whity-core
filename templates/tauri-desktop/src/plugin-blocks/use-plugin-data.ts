/**
 * Desktop equivalent of `web/lib/use-plugin-data.ts`: fetch hook for
 * data-bound plugin UI blocks (`dataTable`/`dataStat`/`dataList`/`chart`/
 * `selector`/`referenceSelect`). Same loading -> error / empty / ready state
 * machine, same `{ data: unknown }` envelope contract, same
 * caller-supplied-`parse`-returns-`null`-means-empty rule — only the
 * transport differs: `invoke("php_request", ...)` (a local loopback call
 * through the bundled PHP host) instead of a browser `fetch`. Since it's
 * local, a hang guard is still worth keeping (an unhealthy plugin route
 * could still never answer), implemented as a `Promise.race` rather than a
 * real `AbortController` signal (`invoke` has no abort mechanism).
 *
 * #867: same pagination rule as web, and for the same reason. One request is
 * one page, every core list endpoint is paginated at 25, and a block presents
 * its `source` as the whole collection — so a source that turns out to be
 * paginated is exhausted, and a walk that could not finish surfaces as `error`
 * rather than rendering a short list the operator would read as missing data.
 */

import { useCallback, useEffect, useRef, useState } from "react"

import { fetchAllPages, isPaginationEnvelope, phpRequest, type PhpResponse } from "./fetch-all-pages"

export type PluginDataState<T> =
  | { status: "loading" }
  | { status: "error"; retry: () => void }
  | { status: "empty"; refresh: () => void }
  | { status: "ready"; data: T; refresh: () => void }

type ResolvedResult<T> = { key: number; status: "error" } | { key: number; status: "empty" } | { key: number; status: "ready"; data: T }

const HANG_GUARD_MS = 15_000

/**
 * One request, bounded. The guard is per request rather than per load so a
 * multi-page walk is not capped at one request's budget; the timer is cleared
 * once the request settles, which matters now that a single load can arm it a
 * hundred times.
 */
function guardedRequest(path: string): Promise<PhpResponse> {
  let timer: ReturnType<typeof setTimeout> | undefined
  const timeout = new Promise<never>((_, reject) => {
    timer = setTimeout(() => reject(new Error("timed out")), HANG_GUARD_MS)
  })
  return Promise.race([phpRequest(path), timeout]).finally(() => clearTimeout(timer))
}

/**
 * Whether the response we already have is a page of a larger set. Web's
 * `usePluginData` asks the identical question of the identical envelope: keyed
 * off the `pagination` block (so anything else — a plugin's own unpaginated
 * route, a `dataStat`'s single object — takes the single-request path
 * unchanged) and off the row count rather than `totalPages`, because the row
 * count is what the walk treats as the contract when the two disagree.
 */
function hasUnfetchedPages(body: { data: unknown; pagination?: unknown }): boolean {
  return Array.isArray(body.data) && isPaginationEnvelope(body.pagination) && body.data.length < body.pagination.total
}

async function fetchSource(source: string): Promise<unknown> {
  const response = await guardedRequest(source)
  if (response.status < 200 || response.status >= 300) {
    throw new Error(`request failed (${response.status})`)
  }
  const body = response.body as { data: unknown; pagination?: unknown }
  if (typeof body !== "object" || body === null || !("data" in body)) {
    throw new Error("malformed response")
  }

  if (!hasUnfetchedPages(body)) return body.data

  // Re-walk from page 1 rather than continuing from page 2: one extra request,
  // in exchange for the walk being used exactly as written and at the server's
  // maximum page size — fewer total requests than continuing at the default 25.
  const all = await fetchAllPages<unknown>(source, guardedRequest)
  if (!all.complete) {
    // A page failed or the walk hit its request cap. Throwing lands in the
    // hook's catch and shows the error state with its retry, which is the
    // whole point: what we hold is a short list, and a short list rendered as
    // a complete one is the defect (#824), not a degraded success.
    throw new Error("could not load every page")
  }
  return all.items
}

/**
 * Fetch `source` through the PHP host proxy and map the result into a
 * discriminated state. `parse` receives the unwrapped `body.data` value and
 * must return `T` (valid, non-empty) or `null` (treat as empty).
 */
export function usePluginData<T>(source: string, parse: (body: unknown) => T | null): PluginDataState<T> {
  const [fetchKey, setFetchKey] = useState(0)
  const [resolved, setResolved] = useState<ResolvedResult<T> | null>(null)
  const bump = useCallback(() => setFetchKey((k) => k + 1), [])

  const mountedRef = useRef(true)
  useEffect(() => {
    mountedRef.current = true
    return () => {
      mountedRef.current = false
    }
  }, [])

  useEffect(() => {
    // #883: an empty source means "no record named yet" (a `dataRecord` whose
    // token has not resolved), not a path to fetch. Same guard as the web hook.
    if (source === "") return

    const key = fetchKey
    let cancelled = false

    void (async () => {
      try {
        const data = await fetchSource(source)
        if (cancelled || !mountedRef.current) return
        const parsed = parse(data)
        setResolved(parsed === null ? { key, status: "empty" } : { key, status: "ready", data: parsed })
      } catch {
        if (cancelled || !mountedRef.current) return
        setResolved({ key, status: "error" })
      }
    })()

    return () => {
      cancelled = true
    }
    // `parse` intentionally excluded — see web/lib/use-plugin-data.ts's identical rationale.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [source, fetchKey])

  if (resolved === null || resolved.key !== fetchKey) {
    return { status: "loading" }
  }
  if (resolved.status === "error") {
    return { status: "error", retry: bump }
  }
  if (resolved.status === "empty") {
    return { status: "empty", refresh: bump }
  }
  return { status: "ready", data: resolved.data, refresh: bump }
}
