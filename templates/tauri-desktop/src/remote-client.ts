import { invoke } from "@tauri-apps/api/core"

/**
 * The remote peer of the `php_request` transport (see
 * `plugin-blocks/fetch-features.ts`): a thin typed wrapper over the Rust
 * `remote_request` command (src-tauri/src/commands/remote.rs), which forwards
 * to the enrolled REMOTE whity-core instance authenticated as this device (the
 * in-memory access token, refreshed from the keychain credential on a stale
 * `401` — NOT a browser cookie). Server-owned admin surfaces (Roles, Users, the
 * plugin catalog) live on the backend, not in an offline plugin, so they route
 * through here instead of `php_request`.
 *
 * The `{ status, body }` shape is byte-for-byte identical to `php_request`'s
 * `PhpResponse`, so a resource adapter (e.g. `roles-tauri-adapter.ts`) consumes
 * either transport the same way. `path` is an ABSOLUTE path from the backend
 * origin (e.g. `/api/v1/roles`, `/api/v1/me/capabilities`).
 */
export interface RemoteResponse {
  status: number
  body: unknown
}

export function remoteRequest(
  method: string,
  path: string,
  body?: unknown,
  headers?: Record<string, string>,
): Promise<RemoteResponse> {
  return invoke<RemoteResponse>("remote_request", {
    method,
    path,
    body: body ?? null,
    headers: headers ?? null,
  })
}
