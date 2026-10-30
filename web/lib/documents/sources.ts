import type { Block, DocumentKind, PublishBlocker, WhityDocument } from '@whity-org/ui/editor/contract';
import type { FieldGroup } from '@whity-org/ui/editor/fields';
import type { EditorServices, FieldPreview, RecordView, UploadedFile } from '@whity-org/ui/editor/services';
import type { DocumentEditorAdapter, LoadedDocument, SaveResult, TemplateSetup } from '@whity-org/features/document-editor';
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

/** The server keeps a label sheet's grid inside `page` (E.43); the editor has it beside. */
function pageOf(page: Row['page']): Pick<WhityDocument, 'page' | 'labels'> {
  if (!page) return { page: undefined, labels: undefined };
  const { labels, ...rest } = page as Record<string, unknown>;
  return { page: rest as WhityDocument['page'], labels: (labels ?? undefined) as WhityDocument['labels'] };
}

export function toDocument(row: Row): WhityDocument {
  return {
    id: String(row.id),
    kind: row.kind as DocumentKind,
    title: row.title,
    lang: row.lang === 'ar' ? 'ar' : 'en',
    defaultView: row.default_view === 'page' ? 'page' : 'continuous',
    fullWidth: row.full_width,
    ...pageOf(row.page),
    recordType: row.record_type ?? undefined,
    ownFields: row.own_fields?.length ? row.own_fields : undefined,
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

type FileResponse = components['schemas']['DocumentSourceFileResponse'];
type RecordResponse = components['schemas']['DocumentEmbedRecordResponse']['data'];

/**
 * The upload is multipart, which the typed client cannot express for a body
 * (openapi-typescript types it as `{ file: string }`), so `api.POST` is recast
 * for this one path, as branding-upload.ts and plugin-upload.ts do.
 */
type MultipartPost = (url: string, options: { body: FormData }) => Promise<{ data?: FileResponse; response: Response }>;

function toRecordView(row: RecordResponse): RecordView {
  return {
    typeLabel: row.type_label,
    title: row.title,
    subtitle: row.subtitle ?? undefined,
    amount: row.amount ?? undefined,
    status: row.status ?? undefined,
    href: row.href ?? undefined,
  };
}

/**
 * Live data for one document's rich blocks (editor M3). Every call is the
 * reader's own: a record or chart they may not see comes back as null, which
 * the block shows as hidden rather than as an error.
 */
async function loadServices(id: string): Promise<EditorServices> {
  const { data } = await api.GET('/api/v1/document-embeds');
  const fileUrl = (fileId: string) => `/api/v1/document-sources/${Number(id)}/files/${encodeURIComponent(fileId)}/content`;

  return {
    recordTypes: data?.data.record_types ?? [],
    chartSources: data?.data.chart_sources ?? [],
    fileUrl,
    async resolveRecord(ref) {
      const { data: record, response } = await api.GET('/api/v1/document-embeds/records/{type}/{id}', {
        params: { path: { type: ref.type, id: ref.id } },
      });
      if (response.status === 404) return null;
      if (record === undefined) throw new Error(`Failed to load the record (${response.status})`);
      return toRecordView(record.data);
    },
    async loadChart(source) {
      // The block's params travel as the query string; the spec has no fixed list of them.
      const query = new URLSearchParams(source.params ?? {}).toString();
      const { data: chart, response } = await api.GET('/api/v1/document-embeds/charts/{key}', {
        params: { path: { key: source.query } },
        querySerializer: () => query,
      });
      if (response.status === 404) return null;
      if (chart === undefined) throw new Error(`Failed to load the chart (${response.status})`);
      return { points: chart.data.points, valueLabel: chart.data.value_label, subtitle: chart.data.subtitle };
    },
    async uploadFile(file): Promise<UploadedFile> {
      const body = new FormData();
      body.append('file', file);
      const post = api.POST as unknown as MultipartPost;
      const { data: up, response } = await post(`/api/v1/document-sources/${Number(id)}/files`, { body });
      if (up === undefined) throw new Error(`Failed to upload the file (${response.status})`);
      return {
        fileId: String(up.data.id),
        url: up.data.url,
        name: up.data.filename ?? file.name,
        size: up.data.byte_size,
        mime: up.data.content_type,
      };
    },
  };
}

/** Saves the PDF the server returns under the template's name. */
function download(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `${filename || 'document'}.pdf`;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export const documentSourcesAdapter: DocumentEditorAdapter = {
  loadServices,

  async loadTemplate(recordType): Promise<TemplateSetup> {
    const { data, response } = await api.GET('/api/v1/document-fields', {
      params: { query: recordType ? { recordType } : {} },
    });
    if (data === undefined) throw new Error(`Failed to load the fields (${response.status})`);
    return {
      fields: data.data.groups as FieldGroup[],
      letterhead: data.data.letterhead,
      accent: data.data.accent ?? null,
    };
  },

  async findRecords(recordType, query) {
    const { data, response } = await api.GET('/api/v1/document-fields/records', {
      params: { query: { recordType, ...(query ? { q: query } : {}) } },
    });
    if (data === undefined) throw new Error(`Failed to find records (${response.status})`);
    return data.data.map((r) => ({ ref: `${recordType}:${r.id}`, label: r.label }));
  },

  async preview(id, record): Promise<FieldPreview | null> {
    const { data, response } = await api.GET('/api/v1/document-sources/{id}/preview', {
      params: { path: { id: Number(id) }, query: record ? { record } : {} },
    });
    if (response.status === 404) return null;
    if (data === undefined) throw new Error(`Failed to preview (${response.status})`);
    return { label: data.data.label, values: data.data.values };
  },

  async publishCheck(id): Promise<PublishBlocker[]> {
    const { data, response } = await api.GET('/api/v1/document-sources/{id}/publish-check', { params: { path: { id: Number(id) } } });
    if (data === undefined) throw new Error(`Failed to check the template (${response.status})`);
    return data.data as PublishBlocker[];
  },

  async publish(id, version): Promise<number> {
    const { data, response } = await api.POST('/api/v1/document-sources/{id}/publish', {
      params: { path: { id: Number(id) } },
      headers: { 'If-Match': `"${version}"` },
    });
    if (data === undefined) throw new Error(`Failed to publish (${response.status})`);
    return data.data.published_version;
  },

  async exportPdf(id, record, filename) {
    const { data, response } = await api.POST('/api/v1/document-sources/{id}/render', {
      params: { path: { id: Number(id) } },
      body: record ? { record } : {},
      parseAs: 'blob',
    });
    if (data === undefined) throw new Error(`Failed to print (${response.status})`);
    download(data as Blob, filename);
  },

  async printLabels(id, source, filename) {
    // The CSV goes as text with this print and is never stored (E.43).
    const body =
      source.kind === 'records'
        ? { records: source.records.map((r) => `${r.type}:${r.id}`) }
        : { csv: await source.file.text(), columns: source.columns };
    const { data, error, response } = await api.POST('/api/v1/document-sources/{id}/print-labels', {
      params: { path: { id: Number(id) } },
      body,
      parseAs: 'blob',
    });
    if (data === undefined) {
      const message = (error as { error?: unknown } | undefined)?.error;
      throw new Error(typeof message === 'string' ? message : `Failed to print the labels (${response.status})`);
    }
    download(data as Blob, filename);
    return {
      count: Number(response.headers.get('X-Labels-Count') ?? 0),
      clipped: Number(response.headers.get('X-Labels-Clipped') ?? 0),
    };
  },

  async load(id: string): Promise<LoadedDocument> {
    const { data, response } = await api.GET('/api/v1/document-sources/{id}', { params: { path: { id: Number(id) } } });
    if (data === undefined) throw new Error(`Failed to load the document (${response.status})`);
    return { document: toDocument(data.data), version: versionFrom(response, data.data.draft_version) };
  },

  async save(id, patch, version): Promise<SaveResult> {
    const { data, response } = await api.PUT('/api/v1/document-sources/{id}/draft', {
      params: { path: { id: Number(id) } },
      headers: { 'If-Match': `"${version}"` },
      body: {
        title: patch.title,
        blocks: patch.blocks as unknown as Row['blocks'],
        ...(patch.page || patch.labels ? { page: { ...patch.page, ...(patch.labels ? { labels: patch.labels } : {}) } as Row['page'] } : {}),
        ...(patch.ownFields ? { own_fields: patch.ownFields } : {}),
      },
    });
    if (response.status === 409) {
      return { ok: false, conflict: true, currentVersion: versionFrom(response, version) };
    }
    if (data === undefined) throw new Error(`Failed to save the document (${response.status})`);
    return { ok: true, version: versionFrom(response, data.data.draft_version), updatedAt: data.data.updated_at };
  },
};
