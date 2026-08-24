import type { useAuth } from '@/lib/auth-context';
import type { useToast } from '@/lib/toast-context';

export type ApiClient = ReturnType<typeof useAuth>['apiClient'];
export type AddToast = ReturnType<typeof useToast>['addToast'];

/** Which of the two identically-shaped tables a dialog is acting on. */
export type ResourceKind = 'template' | 'block';

/**
 * The collection path for a kind.
 *
 * The two resources have byte-identical shapes and gates, so every dialog on
 * this screen is written once and told which table it is editing. That is the
 * same choice the server makes (one row-mapping trait, one access policy, two
 * handlers) and it keeps a fix to the rename flow from having to be made twice.
 */
export function basePath(kind: ResourceKind): string {
  return kind === 'template' ? '/api/v1/document-templates' : '/api/v1/document-blocks';
}

/**
 * PATCH a row, returning null on success or a message to show on failure.
 *
 * Both the status AND the body are consulted. A 5xx with no JSON body yields no
 * `error` field, and branching on the body alone would toast success over a
 * write that did not happen — the mistake the delegations modal documents.
 */
export async function patchRow(
  apiClient: ApiClient,
  kind: ResourceKind,
  id: number,
  body: Record<string, unknown>,
  fallbackMessage: string
): Promise<string | null> {
  const response = await apiClient(`${basePath(kind)}/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(body),
  });
  if (response.ok) return null;
  const parsed = (await response.json().catch(() => null)) as { error?: string } | null;
  return parsed?.error ?? fallbackMessage;
}

/**
 * DELETE a row, returning null on success or a message on failure.
 *
 * A 409 is the reference-integrity guard and its message is passed through
 * verbatim: the server refused because a template — possibly one the caller
 * cannot see — still holds a live `blockInstance` pointer, and no client-side
 * rewording of that is more accurate than the server's own.
 */
export async function deleteRow(
  apiClient: ApiClient,
  kind: ResourceKind,
  id: number,
  fallbackMessage: string
): Promise<string | null> {
  const response = await apiClient(`${basePath(kind)}/${id}`, { method: 'DELETE' });
  if (response.ok) return null;
  const parsed = (await response.json().catch(() => null)) as { error?: string } | null;
  return parsed?.error ?? fallbackMessage;
}
