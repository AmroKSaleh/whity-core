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
 */

import { useCallback, useEffect, useRef, useState } from "react"
import { invoke } from "@tauri-apps/api/core"

export type PluginDataState<T> =
  | { status: "loading" }
  | { status: "error"; retry: () => void }
  | { status: "empty"; refresh: () => void }
  | { status: "ready"; data: T; refresh: () => void }

type ResolvedResult<T> = { key: number; status: "error" } | { key: number; status: "empty" } | { key: number; status: "ready"; data: T }

interface PhpResponse {
  status: number
  body: unknown
}

const HANG_GUARD_MS = 15_000

async function fetchSource(source: string): Promise<unknown> {
  const timeout = new Promise<never>((_, reject) => {
    setTimeout(() => reject(new Error("timed out")), HANG_GUARD_MS)
  })
  const response = await Promise.race([invoke<PhpResponse>("php_request", { method: "GET", path: source, body: null }), timeout])
  if (response.status < 200 || response.status >= 300) {
    throw new Error(`request failed (${response.status})`)
  }
  const body = response.body as { data?: unknown }
  if (typeof body !== "object" || body === null || !("data" in body)) {
    throw new Error("malformed response")
  }
  return body.data
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
