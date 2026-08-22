/**
 * Desktop equivalent of `web/lib/use-permitted-actions.ts` (#868): resolve,
 * through the host's own authority, which of a batch of concrete requests the
 * caller may actually make. Same batch shape, same `{ data: [{ref, allowed}] }`
 * envelope, same fail-closed policy — only the transport and the path differ.
 *
 * The path differs because the offline host has no versioned `/api/v1/me/...`
 * surface: it is a single-tenant, single-device host whose own endpoints live
 * under `/__whity/`, beside `/__whity/frontend-features`. The ANSWER, though, is
 * produced the same way on both hosts — the route table's RBAC descriptors
 * evaluated through the same PermissionResolver the request-dispatch gate uses —
 * so an `inbox` renders the same permitted set here as on the server.
 *
 * Fail-closed, three times over: while loading, on any error, and whenever the
 * batch has moved on from the answer in hand, every ref resolves to NOT allowed.
 * An unresolved batch therefore renders no action buttons rather than buttons
 * the gate will refuse. The answers are UI hints regardless — the host re-gates
 * every request when it is actually made.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { invoke } from "@tauri-apps/api/core"

/** The offline host's resolution endpoint (see php-host/public/index.php). */
export const PERMITTED_ACTIONS_PATH = "/__whity/permitted-actions"

export interface PermittedActionCheck {
  ref: string
  /** GET joined the write verbs for #909's `accessGate` — "may I SEE this
   * region at all?" is a read. Same set as the server handler accepts. */
  method: "GET" | "POST" | "PUT" | "PATCH" | "DELETE"
  path: string
  resourceType?: string
  resourceId?: string | number
  scopedPermission?: string
}

export type PermittedActionsState =
  | { status: "loading"; isAllowed: (ref: string) => boolean }
  | { status: "error"; isAllowed: (ref: string) => boolean; retry: () => void }
  | { status: "ready"; isAllowed: (ref: string) => boolean; refresh: () => void }

/**
 * A settled answer, tagged with BOTH the fetch nonce and the batch it answered.
 * The batch tag is what makes a stale answer unusable: `fetchKey` does not
 * change when the caller's batch does, so an answer about the PREVIOUS batch
 * would otherwise keep authorizing buttons until the new one landed.
 */
interface ResolvedResult {
  key: number
  batchKey: string
  status: "error" | "ready"
  allowed: Set<string>
}

interface PhpResponse {
  status: number
  body: unknown
}

const HANG_GUARD_MS = 15_000

/** Nothing is allowed — the loading and error answer. */
const denyAll = (): boolean => false

/** Parse `{ data: [{ ref, allowed }] }` into the set of allowed refs. */
function parseAllowed(body: unknown): Set<string> {
  const allowed = new Set<string>()
  if (typeof body !== "object" || body === null || !("data" in body)) return allowed
  const data = (body as { data: unknown }).data
  if (!Array.isArray(data)) return allowed
  for (const entry of data) {
    if (
      typeof entry === "object" &&
      entry !== null &&
      typeof (entry as { ref?: unknown }).ref === "string" &&
      (entry as { allowed?: unknown }).allowed === true
    ) {
      allowed.add((entry as { ref: string }).ref)
    }
  }
  return allowed
}

async function resolveBatch(checks: PermittedActionCheck[]): Promise<Set<string>> {
  const timeout = new Promise<never>((_, reject) => {
    setTimeout(() => reject(new Error("timed out")), HANG_GUARD_MS)
  })
  const response = await Promise.race([
    invoke<PhpResponse>("php_request", { method: "POST", path: PERMITTED_ACTIONS_PATH, body: { checks } }),
    timeout,
  ])
  if (response.status < 200 || response.status >= 300) {
    throw new Error(`request failed (${response.status})`)
  }
  return parseAllowed(response.body)
}

/**
 * Resolve a batch of checks. Re-resolves whenever `batchKey` changes — the
 * caller's declaration of when the batch meaningfully moved, since `checks` is
 * a fresh array on every render.
 */
export function usePermittedActions(checks: PermittedActionCheck[], batchKey: string): PermittedActionsState {
  const [fetchKey, setFetchKey] = useState(0)
  const [resolved, setResolved] = useState<ResolvedResult | null>(null)
  const bump = useCallback(() => setFetchKey((k) => k + 1), [])

  const mountedRef = useRef(true)
  useEffect(() => {
    mountedRef.current = true
    return () => {
      mountedRef.current = false
    }
  }, [])

  const isEmptyBatch = checks.length === 0

  useEffect(() => {
    // An empty batch has a known answer, derived during render below. Settling
    // it here would be a synchronous setState in an effect body — the
    // cascading-render hazard use-plugin-data.ts avoids the same way.
    if (isEmptyBatch) return

    const key = fetchKey
    // `checks` from THIS render's closure. The effect re-runs whenever
    // `batchKey` changes, and `batchKey` is derived from `checks`, so the batch
    // captured here is always the one that key describes.
    const batch = checks
    let cancelled = false

    void (async () => {
      try {
        const allowed = await resolveBatch(batch)
        if (cancelled || !mountedRef.current) return
        setResolved({ key, batchKey, status: "ready", allowed })
      } catch {
        if (cancelled || !mountedRef.current) return
        setResolved({ key, batchKey, status: "error", allowed: new Set<string>() })
      }
    })()

    return () => {
      cancelled = true
    }
    // `checks` intentionally excluded from the deps — a fresh array every
    // render would re-resolve every render; `batchKey` is the caller's change
    // signal. Same rationale as use-plugin-data.ts.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [batchKey, fetchKey, isEmptyBatch])

  // An answer counts only when it answered THIS fetch AND THIS batch.
  const fresh =
    resolved !== null && resolved.key === fetchKey && resolved.batchKey === batchKey ? resolved : null

  const allowedSet = fresh !== null && fresh.status === "ready" ? fresh.allowed : null

  const isAllowed = useMemo(() => {
    if (allowedSet === null) return denyAll
    return (ref: string): boolean => allowedSet.has(ref)
  }, [allowedSet])

  if (isEmptyBatch) {
    return { status: "ready", isAllowed: denyAll, refresh: bump }
  }
  if (fresh === null) {
    return { status: "loading", isAllowed: denyAll }
  }
  if (fresh.status === "error") {
    return { status: "error", isAllowed: denyAll, retry: bump }
  }
  return { status: "ready", isAllowed, refresh: bump }
}
