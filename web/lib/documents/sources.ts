import type { Block, DocumentKind, WhityDocument } from '@amroksaleh/ui/editor/contract';
import type { DocumentEditorAdapter, LoadedDocument, SaveResult } from '@amroksaleh/features/document-editor';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';

/**
 * Web's HTTP half of the block editor's documents (editor M2), on the typed
 * OpenAPI client. The server keeps a `document_sources` row; this maps it to
 * the WhityDocument contract and back. Saves carry the draft version as
 * If-Match, and a 409 comes back as a conflict, not an error.
 */

type Row = components['schemas']['DocumentSource'];

function versionFrom(response: Response, fallback: number): number {
  const etag = response.headers.get('ETag');
  const m = etag ? /"(\d+)"/.exec(etag) : null;
  return m ? Number(m[1]) : fallback;
}

export function toDocument(row: Row): WhityDocument {
  return {
    id: String(row.id),
    kind: row.kind as DocumentKind,
    title: row.title,
    lang: row.lang === 'ar' ? 'ar' : 'en',
    defaultView: row.default_view === 'page' ? 'page' : 'continuous',
    fullWidth: row.full_width,
    recordType: row.record_type ?? undefined,
    blocks: (row.blocks ?? []) as unknown as Block[],
    // Comments and suggestions have their own endpoints from M5.
    comments: [],
    suggestions: [],
    status: row.published_version != null ? 'published' : 'draft',
    publishedVersion: row.published_version ?? undefined,
    updatedAt: row.updated_at,
    // The editor shows who edited last; names come with the people lookup in M5.
    updatedBy: { kind: 'person', id: String(row.updated_by ?? ''), name: '' },
  };
}

export async function listDocumentSources(kind?: DocumentKind): Promise<Row[]> {
  const { data, response } = await api.GET('/api/v1/document-sources', { params: { query: kind ? { kind } : {} } });
  if (data === undefined) throw new Error(`Failed to load documents (${response.status})`);
  return data.data;
}

export async function createDocumentSource(kind: DocumentKind, title: string): Promise<Row> {
  const { data, response } = await api.POST('/api/v1/document-sources', { body: { kind, title, blocks: [] } });
  if (data === undefined) throw new Error(`Failed to create the document (${response.status})`);
  return data.data;
}

export const documentSourcesAdapter: DocumentEditorAdapter = {
  async load(id: string): Promise<LoadedDocument> {
    const { data, response } = await api.GET('/api/v1/document-sources/{id}', { params: { path: { id: Number(id) } } });
    if (data === undefined) throw new Error(`Failed to load the document (${response.status})`);
    return { document: toDocument(data.data), version: versionFrom(response, data.data.draft_version) };
  },

  async save(id, patch, version): Promise<SaveResult> {
    const { data, response } = await api.PUT('/api/v1/document-sources/{id}/draft', {
      params: { path: { id: Number(id) } },
      headers: { 'If-Match': `"${version}"` },
      body: { title: patch.title, blocks: patch.blocks as unknown as Row['blocks'] },
    });
    if (response.status === 409) {
      return { ok: false, conflict: true, currentVersion: versionFrom(response, version) };
    }
    if (data === undefined) throw new Error(`Failed to save the document (${response.status})`);
    return { ok: true, version: versionFrom(response, data.data.draft_version), updatedAt: data.data.updated_at };
  },
};
