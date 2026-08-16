/**
 * Desktop equivalent of `web/lib/plugin-action-submit.ts`: submits a JSON
 * payload to an interactive block's endpoint through the PHP host proxy
 * instead of a browser `fetch`. Same result shape/precedence: 2xx -> ok;
 * non-ok with `{issues}` -> issues; non-ok with `{error}` -> error string;
 * otherwise a generic HTTP-status message; a thrown/network failure -> its
 * message.
 */

import { invoke } from "@tauri-apps/api/core"

export interface ActionIssue {
  severity?: string
  message?: string
  item?: number | null
  column?: string | null
}

export type SubmitResult = { ok: true } | { ok: false; issues?: ActionIssue[]; error?: string }

interface PhpResponse {
  status: number
  body: unknown
}

function extractIssues(body: unknown): ActionIssue[] | null {
  if (typeof body === "object" && body !== null && "issues" in body) {
    const issues = (body as { issues: unknown }).issues
    if (Array.isArray(issues)) return issues as ActionIssue[]
  }
  return null
}

function extractError(body: unknown): string | null {
  if (typeof body === "object" && body !== null && "error" in body) {
    const error = (body as { error: unknown }).error
    if (typeof error === "string") return error
  }
  return null
}

export async function submitPluginAction(
  endpoint: string,
  method: "POST" | "PUT" | "DELETE",
  payload: Record<string, unknown>,
): Promise<SubmitResult> {
  try {
    const response = await invoke<PhpResponse>("php_request", { method, path: endpoint, body: payload })
    if (response.status >= 200 && response.status < 300) {
      return { ok: true }
    }

    const issues = extractIssues(response.body)
    if (issues !== null) return { ok: false, issues }

    const error = extractError(response.body)
    if (error !== null) return { ok: false, error }

    return { ok: false, error: `Request failed (HTTP ${response.status})` }
  } catch (thrown) {
    const error = thrown instanceof Error ? thrown.message : String(thrown)
    return { ok: false, error }
  }
}
