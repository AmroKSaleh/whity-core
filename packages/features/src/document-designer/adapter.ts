import type { DocTemplate } from '@amroksaleh/ui/documents/types';
import type { DocBlock } from '@amroksaleh/ui/documents/blocks';

import { toSavedTemplate, type DocumentTemplateRow } from './template-model';
import { toDocBlock, type DocumentBlockRow } from './block-model';
import type { DocumentDesignerAdapter, SavedTemplate, Transport } from './types';

/**
 * Builds a {@link DocumentDesignerAdapter} over any {@link Transport}.
 *
 * This factory lives INSIDE the package on purpose, which is where it differs
 * from the roles slice. `createRolesAdapter` sits outside its package and is
 * copy-pasted byte-for-byte between `web/lib/roles-adapter.ts` and
 * `templates/tauri-desktop/src/roles-tauri-adapter.ts`, guarded by nothing but
 * a comment telling the next person to keep them in step. There is no
 * technical reason for that here — the factory depends only on `Transport` —
 * and exporting it collapses each client's adapter to a few lines with
 * nothing left to drift.
 *
 * Every method throws on failure; see the contract note on
 * `DocumentDesignerAdapter`.
 */

const LIST_PATH_TEMPLATES = '/api/v1/document-templates';
const LIST_PATH_BLOCKS = '/api/v1/document-blocks';

/**
 * These two endpoints do NOT paginate, unlike most core list routes.
 *
 * `DocumentTemplatesApiHandler::list()` and its blocks counterpart answer
 * `{data: <every visible row>}` — no `per_page`, no `pagination` envelope, and
 * no query parameters declared in the OpenAPI spec. So there is deliberately
 * no page walk and no per-page cap here: both would be dead code, and a cap in
 * particular would give false confidence.
 *
 * If either endpoint ever gains pagination, the fix is a full walk (as #867
 * did for plugin blocks), NOT a larger page size — a cap would still truncate,
 * just later and less visibly. This note is here so that lands as a decision
 * rather than an oversight.
 */

/** Narrow a `{status, body}` result, throwing the server's own message when it
 *  supplied one (the API's error shape is `{error: string}`). */
function unwrap(res: { status: number; body: unknown }, failure: string): unknown {
  if (res.status < 200 || res.status >= 300) {
    const message =
      res.body && typeof res.body === 'object' && typeof (res.body as { error?: unknown }).error === 'string'
        ? (res.body as { error: string }).error
        : `${failure} (${res.status})`;
    throw new Error(message);
  }
  return res.body;
}

/** Every list endpoint answers `{data: [...]}`. */
function rowsOf(body: unknown): unknown[] {
  const data = (body as { data?: unknown } | null)?.data;
  return Array.isArray(data) ? data : [];
}

/** Create/update answer `{data: {id}}`; the id round-trips as a string. */
function idOf(body: unknown, failure: string): string {
  const id = (body as { data?: { id?: unknown } } | null)?.data?.id;
  if (id === undefined || id === null) throw new Error(failure);
  return String(id);
}

export function createDocumentDesignerAdapter(transport: Transport): DocumentDesignerAdapter {
  return {
    async listTemplates(): Promise<SavedTemplate[]> {
      const body = unwrap(
        await transport.request('GET', LIST_PATH_TEMPLATES),
        'Failed to load templates'
      );
      return rowsOf(body).reduce<SavedTemplate[]>((out, row) => {
        const t = toSavedTemplate(row as DocumentTemplateRow);
        if (t) out.push(t);
        return out;
      }, []);
    },

    async saveTemplate(template: DocTemplate, id?: string): Promise<string> {
      const payload = { name: template.name, data: template as unknown as Record<string, unknown> };
      const res =
        id !== undefined
          ? await transport.request('PATCH', `${LIST_PATH_TEMPLATES}/${id}`, payload)
          : await transport.request('POST', LIST_PATH_TEMPLATES, payload);
      return idOf(unwrap(res, 'Failed to save template'), 'Failed to save template');
    },

    async deleteTemplate(id: string): Promise<void> {
      unwrap(await transport.request('DELETE', `${LIST_PATH_TEMPLATES}/${id}`), 'Failed to delete template');
    },

    async listBlocks(): Promise<DocBlock[]> {
      const body = unwrap(
        await transport.request('GET', LIST_PATH_BLOCKS),
        'Failed to load blocks'
      );
      return rowsOf(body).reduce<DocBlock[]>((out, row) => {
        const b = toDocBlock(row as DocumentBlockRow);
        if (b) out.push(b);
        return out;
      }, []);
    },

    async saveBlock(block: DocBlock): Promise<string> {
      const payload = {
        name: block.name,
        data: block.elements as unknown as Record<string, unknown>[],
        scope: block.scope,
      };
      // A purely-numeric id is a backend id (update); a freshly-authored block
      // carries `makeBlockFromElements`' crypto.randomUUID() (create). Same
      // discriminator the web implementation has always used.
      const res = /^\d+$/.test(block.id)
        ? await transport.request('PATCH', `${LIST_PATH_BLOCKS}/${block.id}`, payload)
        : await transport.request('POST', LIST_PATH_BLOCKS, payload);
      return idOf(unwrap(res, 'Failed to save block'), 'Failed to save block');
    },

    async deleteBlock(id: string): Promise<void> {
      // A 409 here is the backend's reference-integrity guard (a template still
      // holds a live blockInstance pointer); its message reaches the user via
      // the thrown error.
      unwrap(await transport.request('DELETE', `${LIST_PATH_BLOCKS}/${id}`), 'Failed to delete block');
    },
  };
}
