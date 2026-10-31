import type { useAuth } from '@/lib/auth-context';
import type { useToast } from '@/lib/toast-context';
import type { DocumentScope, TemplateKind, TemplateRow } from './types';

export type ApiClient = ReturnType<typeof useAuth>['apiClient'];
export type AddToast = ReturnType<typeof useToast>['addToast'];

const BASE = '/api/v1/document-sources';

/** The kinds this page lists, in the order they are fetched. */
export const TEMPLATE_KINDS: readonly TemplateKind[] = ['template', 'label'];

/** Thrown for a 403 so the page can show its access-denied state. */
export class ForbiddenError extends Error {
  constructor() {
    super('documents:read forbidden');
  }
}

/** The server's own message from an error body, or the fallback. */
async function errorOf(response: Response, fallback: string): Promise<string> {
  const parsed = (await response.json().catch(() => null)) as { error?: unknown } | null;
  return typeof parsed?.error === 'string' && parsed.error !== '' ? parsed.error : fallback;
}

/**
 * A row as the page uses it. Fields the server may leave out (an older server
 * without `starter_key`, a row without own fields) read as their empty value,
 * so a missing column never renders as a badge or a crash.
 */
export function toTemplateRow(raw: Record<string, unknown>): TemplateRow {
  const num = (v: unknown): number | null => (typeof v === 'number' ? v : null);
  const str = (v: unknown): string | null => (typeof v === 'string' && v !== '' ? v : null);
  return {
    id: Number(raw.id),
    kind: raw.kind === 'label' ? 'label' : 'template',
    title: typeof raw.title === 'string' ? raw.title : '',
    lang: raw.lang === 'ar' ? 'ar' : 'en',
    record_type: str(raw.record_type),
    own_fields: Array.isArray(raw.own_fields) ? (raw.own_fields as TemplateRow['own_fields']) : [],
    draft_version: Number(raw.draft_version ?? 0),
    published_version: num(raw.published_version),
    scope: (typeof raw.scope === 'string' ? raw.scope : 'personal') as DocumentScope,
    required_permission: str(raw.required_permission),
    owner_ou_id: num(raw.owner_ou_id),
    created_by: num(raw.created_by),
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : '',
    starter_key: str(raw.starter_key),
    legacy_template_id: num(raw.legacy_template_id),
  };
}

/** Every editor template and label sheet the caller may see (server-filtered). */
export async function listTemplates(apiClient: ApiClient, fallback: string): Promise<TemplateRow[]> {
  const lists = await Promise.all(
    TEMPLATE_KINDS.map(async (kind) => {
      const response = await apiClient(`${BASE}?kind=${kind}`);
      if (response.status === 403) throw new ForbiddenError();
      if (!response.ok) throw new Error(await errorOf(response, fallback));
      const body = (await response.json()) as { data?: Record<string, unknown>[] };
      return (body.data ?? []).map(toTemplateRow);
    })
  );
  return lists.flat();
}

/**
 * Change who can see a row. Returns null on success or the message to show:
 * a 403 carries the server's own sentence ("Sharing a document requires
 * documents:publish"), which says it better than any rewording here.
 */
export async function patchTemplate(
  apiClient: ApiClient,
  id: number,
  body: { scope?: DocumentScope; required_permission?: string | null; owner_ou_id?: number | null },
  fallback: string
): Promise<string | null> {
  const response = await apiClient(`${BASE}/${id}`, { method: 'PATCH', body: JSON.stringify(body) });
  return response.ok ? null : errorOf(response, fallback);
}

/** Delete a row. Returns null on success or the message to show. */
export async function deleteTemplate(apiClient: ApiClient, id: number, fallback: string): Promise<string | null> {
  const response = await apiClient(`${BASE}/${id}`, { method: 'DELETE' });
  return response.ok ? null : errorOf(response, fallback);
}

/**
 * "Use a copy": a personal, unpublished draft of the caller's own, whatever the
 * original's audience was. Resolves to the new row's id, or rejects with the
 * message to show.
 */
export async function copyTemplate(apiClient: ApiClient, id: number, title: string, fallback: string): Promise<number> {
  const response = await apiClient(`${BASE}/${id}/copy`, {
    method: 'POST',
    body: JSON.stringify(title.trim() === '' ? {} : { title: title.trim() }),
  });
  if (!response.ok) throw new Error(await errorOf(response, fallback));
  const body = (await response.json().catch(() => null)) as { data?: { id?: unknown } } | null;
  const newId = body?.data?.id;
  if (typeof newId !== 'number') throw new Error(fallback);
  return newId;
}

/** Where the document editor opens a row. */
export function editorHref(id: number): string {
  return `/admin/document-sources/${id}`;
}
