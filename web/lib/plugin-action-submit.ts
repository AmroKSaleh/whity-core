/**
 * WC-235: shared interactive-block submission helper.
 *
 * Extracted from `ActionScreen`'s submit logic so the form-context and
 * action-button renderer can reuse the same POST/PUT → result shape without
 * duplicating the response-parsing logic.
 *
 * `ActionIssue`, `extractIssues`, and `extractError` are exported so
 * `action-screen.tsx` can import them (DRY refactor; ActionScreen behavior
 * including file-download remains unchanged).
 */

import { apiClient } from '@/lib/api-client';

/** One issue from a server validation report (best-effort shape). */
export interface ActionIssue {
  severity?: string;
  message?: string;
  item?: number | null;
  column?: string | null;
}

/** Extract an `issues` array from an unknown response body, or return null. */
export function extractIssues(body: unknown): ActionIssue[] | null {
  if (typeof body === 'object' && body !== null && 'issues' in body) {
    const issues = (body as { issues: unknown }).issues;
    if (Array.isArray(issues)) {
      return issues as ActionIssue[];
    }
  }
  return null;
}

/** Extract an `error` string from an unknown response body, or return null. */
export function extractError(body: unknown): string | null {
  if (typeof body === 'object' && body !== null && 'error' in body) {
    const error = (body as { error: unknown }).error;
    if (typeof error === 'string') {
      return error;
    }
  }
  return null;
}

/** The result shape returned by `submitPluginAction`. */
export type SubmitResult =
  | { ok: true }
  | { ok: false; issues?: ActionIssue[]; error?: string };

/**
 * Submit a JSON payload to an interactive-block endpoint via `apiClient`.
 *
 * - 2xx           → `{ ok: true }`
 * - 4xx/5xx with `{ issues }` → `{ ok: false, issues }`
 * - 4xx/5xx with `{ error }` → `{ ok: false, error }`
 * - other non-ok  → `{ ok: false, error: 'Request failed (HTTP <status>)' }`
 * - thrown        → `{ ok: false, error: <message> }`
 *
 * The endpoint must be a versioned relative path (`/api/v1/…`) — the host
 * validates and rewrites it before serving; we pass it verbatim to apiClient.
 */
export async function submitPluginAction(
  endpoint: string,
  method: 'POST' | 'PUT',
  payload: Record<string, unknown>
): Promise<SubmitResult> {
  try {
    const response = await apiClient(endpoint, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });

    if (response.ok) {
      return { ok: true };
    }

    const body: unknown = await response.json().catch(() => null);

    const issues = extractIssues(body);
    if (issues !== null) {
      return { ok: false, issues };
    }

    const error = extractError(body);
    if (error !== null) {
      return { ok: false, error };
    }

    return { ok: false, error: `Request failed (HTTP ${response.status})` };
  } catch (thrown) {
    const error =
      thrown instanceof Error ? thrown.message : 'Request failed';
    return { ok: false, error };
  }
}
