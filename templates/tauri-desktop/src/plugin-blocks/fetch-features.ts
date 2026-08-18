import { invoke } from "@tauri-apps/api/core"

import type { PluginFeature } from "./types"

interface PhpResponse {
  status: number
  body: unknown
}

/**
 * Fetch the permission-filtered feature list for this device from the
 * offline PHP host, via the same generic `php_request` proxy
 * `plugins-page.tsx`'s `loadPluginsReport` already uses. Resolves to an
 * empty list on any failure (host not ready yet, malformed body, network
 * error) — callers render "no plugin features" rather than crash, mirroring
 * `web/lib/plugin-features.ts::fetchPluginFeatures`.
 */
export async function fetchPluginFeatures(): Promise<PluginFeature[]> {
  try {
    const response = await invoke<PhpResponse>("php_request", {
      method: "GET",
      path: "/__whity/frontend-features",
      body: null,
    })
    if (response.status !== 200) return []
    const body = response.body as { data?: unknown }
    return Array.isArray(body?.data) ? (body.data as PluginFeature[]) : []
  } catch {
    return []
  }
}
