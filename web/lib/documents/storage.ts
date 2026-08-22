import type { DocTemplate } from '@amroksaleh/ui/documents/types';
import { api } from '@/lib/api/client';
import {
  toSavedTemplate,
  type DocumentDesignerAdapter,
  type SavedTemplate,
} from '@amroksaleh/features/document-designer';
import { deleteBlock, listBlocks, saveBlock } from './blocks';

/**
 * Web's HTTP half of document-template persistence.
 *
 * The pure model (validation, v1->v2 migration, the element factory, row
 * mapping, JSON export) moved to `@amroksaleh/features/document-designer` so
 * the designer itself could be shared with the Tauri desktop client. It is
 * re-exported below, so every existing `@/lib/documents/storage` import — and
 * both Jest suites — keep working unchanged.
 *
 * These three stay HERE, on the typed OpenAPI client, deliberately: the calls
 * are checked against `web/lib/api/schema.d.ts`, and Storybook intercepts them
 * at the network layer with MSW, both of which a transport-shaped indirection
 * would give up. The desktop client has no OpenAPI schema and gets the same
 * six operations from `createDocumentDesignerAdapter` instead.
 *
 * `listSaved`/`saveTemplate`/`deleteSaved` THROW on failure so the caller can
 * catch and toast.
 */

// The public surface this module has always had, now single-sourced.
export {
  exportTemplateJson,
  interpolate,
  isDocTemplate,
  migrateTemplate,
  newElement,
  resolveBound,
  sampleDataOf,
} from '@amroksaleh/features/document-designer';
export type { SavedTemplate };

/** The templates visible to the caller (server-side RBAC-filtered — see
 *  DocumentAccessPolicy), newest first. Throws on a network/API failure; the
 *  caller (document-designer.tsx) catches and toasts. */
export async function listSaved(): Promise<SavedTemplate[]> {
  const { data, response } = await api.GET('/api/v1/document-templates');
  if (data === undefined) {
    throw new Error(`Failed to load templates (${response.status})`);
  }
  return data.data.reduce<SavedTemplate[]>((out, row) => {
    const t = toSavedTemplate(row);
    if (t) out.push(t);
    return out;
  }, []);
}

/** Create (no `id`) or update (existing `id`) a template; returns its id
 *  (the id round-trips through PATCH; a fresh backend id comes back on
 *  create). Throws on failure. */
export async function saveTemplate(data: DocTemplate, id?: string): Promise<string> {
  const body = { name: data.name, data: data as unknown as Record<string, unknown> };

  if (id !== undefined) {
    const { data: updated, error, response } = await api.PATCH('/api/v1/document-templates/{id}', {
      params: { path: { id: Number(id) } },
      body,
    });
    if (error !== undefined || !response.ok || updated === undefined) {
      throw new Error(error?.error ?? 'Failed to save template');
    }
    return String(updated.data.id);
  }

  const { data: created, error, response } = await api.POST('/api/v1/document-templates', { body });
  if (error !== undefined || !response.ok || created === undefined) {
    throw new Error(error?.error ?? 'Failed to save template');
  }
  return String(created.data.id);
}

/** Delete a saved template. Throws on failure. */
export async function deleteSaved(id: string): Promise<void> {
  const { error, response } = await api.DELETE('/api/v1/document-templates/{id}', {
    params: { path: { id: Number(id) } },
  });
  if (error !== undefined || !response.ok) {
    throw new Error(error?.error ?? 'Failed to delete template');
  }
}

/**
 * Web's `DocumentDesignerAdapter`: the same six operations, over the typed
 * client. The desktop twin is `templates/tauri-desktop/src/documents-tauri-adapter.ts`,
 * which builds its own from `createDocumentDesignerAdapter(transport)` — there
 * is no copy-pasted factory to keep in step between the two.
 */
export const webDocumentsAdapter: DocumentDesignerAdapter = {
  listTemplates: listSaved,
  saveTemplate,
  deleteTemplate: deleteSaved,
  listBlocks,
  saveBlock,
  deleteBlock,
};
