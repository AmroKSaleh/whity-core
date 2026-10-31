/**
 * Editor milestone M3: web's live data for rich blocks. A record or chart the
 * reader may not see (404) comes back as null, so the block shows it as
 * hidden; uploads go up as multipart and come back as the block's file.
 */
const GET = jest.fn();
const POST = jest.fn();
const PUT = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: { GET: (...a: unknown[]) => GET(...a), POST: (...a: unknown[]) => POST(...a), PUT: (...a: unknown[]) => PUT(...a) },
}));

import { documentSourcesAdapter, toDocument } from '@/lib/documents/sources';

// jsdom has no Blob#text(); browsers do.
if (!Blob.prototype.text) {
  Blob.prototype.text = function text(this: Blob) {
    return new Promise<string>((resolve) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result));
      reader.readAsText(this);
    });
  };
}

const ok = (data: unknown) => ({ data: { data }, response: new Response(null, { status: 200 }) });
const notFound = () => ({ data: undefined, response: new Response(null, { status: 404 }) });

beforeEach(() => {
  GET.mockReset();
  POST.mockReset();
  PUT.mockReset();
  GET.mockImplementation(async (path: string) =>
    path === '/api/v1/document-embeds'
      ? ok({ record_types: [{ key: 'document', label: 'Issued document' }], chart_sources: [{ key: 'documents-by-month', label: 'Documents issued per month' }] })
      : notFound()
  );
});

it('lists what the blocks can show, and where files are served', async () => {
  const services = await documentSourcesAdapter.loadServices!('7');
  expect(services.recordTypes).toEqual([{ key: 'document', label: 'Issued document' }]);
  expect(services.chartSources).toEqual([{ key: 'documents-by-month', label: 'Documents issued per month' }]);
  expect(services.fileUrl!('12')).toBe('/api/v1/document-sources/7/files/12/content');
});

it('maps a record, and a hidden one is null rather than an error', async () => {
  const services = await documentSourcesAdapter.loadServices!('7');
  GET.mockResolvedValueOnce(ok({ type_label: 'Issued document', title: 'Receipt R-17', subtitle: null, href: '/admin/document-library/3' }));
  await expect(services.resolveRecord!({ type: 'document', id: '3' })).resolves.toEqual({
    typeLabel: 'Issued document',
    title: 'Receipt R-17',
    subtitle: undefined,
    amount: undefined,
    status: undefined,
    href: '/admin/document-library/3',
  });
  expect(GET).toHaveBeenLastCalledWith('/api/v1/document-embeds/records/{type}/{id}', { params: { path: { type: 'document', id: '3' } } });

  GET.mockResolvedValueOnce(notFound());
  await expect(services.resolveRecord!({ type: 'document', id: '4' })).resolves.toBeNull();
});

it('passes chart params as the query string', async () => {
  const services = await documentSourcesAdapter.loadServices!('7');
  GET.mockResolvedValueOnce(ok({ points: [{ label: 'Oct 2026', value: 2 }], value_label: 'Documents' }));
  await expect(services.loadChart!({ query: 'documents-by-month', params: { ou: '3' } })).resolves.toEqual({
    points: [{ label: 'Oct 2026', value: 2 }],
    valueLabel: 'Documents',
    subtitle: undefined,
  });
  const options = GET.mock.calls.at(-1)[1];
  expect(options.params).toEqual({ path: { key: 'documents-by-month' } });
  expect(options.querySerializer()).toBe('ou=3');
});

it('uploads a file as multipart and returns it for the block', async () => {
  const services = await documentSourcesAdapter.loadServices!('7');
  POST.mockResolvedValueOnce({
    data: { data: { id: 12, url: '/api/v1/document-sources/7/files/12/content', filename: 'logo.png', content_type: 'image/png', byte_size: 4, checksum_sha256: 'x' } },
    response: new Response(null, { status: 201 }),
  });
  const file = new File(['abcd'], 'logo.png', { type: 'image/png' });
  await expect(services.uploadFile!(file)).resolves.toEqual({
    fileId: '12',
    url: '/api/v1/document-sources/7/files/12/content',
    name: 'logo.png',
    size: 4,
    mime: 'image/png',
  });
  const [path, { body }] = POST.mock.calls[0];
  expect(path).toBe('/api/v1/document-sources/7/files');
  expect((body as FormData).get('file')).toBeInstanceOf(File);
});

describe('templates (M4)', () => {
  const PARTS = { address: 'Mecca Street 112, Amman', taxId: 'Tax no. 0123456', phone: '+962 6 555 0101', email: null };

  it('loads the field groups, the letterhead lines by name and the logos for a record type', async () => {
    GET.mockImplementation(async (path: string) =>
      path === '/api/v1/branding'
        ? ok({ siteName: 'Example Bakery', logoWideUrl: '/branding/wide.svg', logoSquareUrl: null, faviconUrl: null, mainColor: null, theme: null })
        : ok({
            record_types: [{ key: 'document', label: 'Document' }],
            groups: [{ group: 'Document', items: [{ path: 'document.title', label: 'Document title' }] }],
            letterhead: { name: 'Example Bakery', lines: ['Mecca Street 112, Amman'] },
            letterhead_parts: PARTS,
          })
    );
    const setup = await documentSourcesAdapter.loadTemplate!('document');
    // The print colour comes with the document now (resolvedTheme), not with the fields.
    expect(setup).toEqual({
      fields: [{ group: 'Document', items: [{ path: 'document.title', label: 'Document title' }] }],
      letterhead: { name: 'Example Bakery', lines: ['Mecca Street 112, Amman'] },
      letterheadParts: PARTS,
      logos: { wide: '/branding/wide.svg', square: null },
    });
    expect(setup).not.toHaveProperty('accent');
    expect(GET).toHaveBeenCalledWith('/api/v1/document-fields', { params: { query: { recordType: 'document' } } });
  });

  it('still loads the template when branding fails: the letterhead shows the initials', async () => {
    GET.mockImplementation(async (path: string) => {
      if (path === '/api/v1/branding') throw new Error('offline');
      return ok({ record_types: [], groups: [], letterhead: { name: 'Example Bakery', lines: [] }, letterhead_parts: PARTS });
    });
    const setup = await documentSourcesAdapter.loadTemplate!(null);
    expect(setup.logos).toBeUndefined();
    expect(setup.letterheadParts).toEqual(PARTS);
  });

  it('names records as type:id, and a hidden preview record is null', async () => {
    GET.mockResolvedValueOnce(ok([{ id: '3', label: '#3 · Receipt' }]));
    await expect(documentSourcesAdapter.findRecords!('document', 'rec')).resolves.toEqual([{ ref: 'document:3', label: '#3 · Receipt' }]);
    expect(GET).toHaveBeenLastCalledWith('/api/v1/document-fields/records', { params: { query: { recordType: 'document', q: 'rec' } } });

    GET.mockResolvedValueOnce(notFound());
    await expect(documentSourcesAdapter.preview!('7', 'document:4')).resolves.toBeNull();
  });

  it('publishes the version it saved, as If-Match', async () => {
    POST.mockResolvedValueOnce({ data: { data: { published_version: 4 } }, response: new Response(null, { status: 201 }) });
    await expect(documentSourcesAdapter.publish!('7', 12)).resolves.toBe(4);
    expect(POST).toHaveBeenLastCalledWith('/api/v1/document-sources/{id}/publish', { params: { path: { id: 7 } }, headers: { 'If-Match': '"12"' } });

    POST.mockResolvedValueOnce({ data: undefined, response: new Response(null, { status: 409 }) });
    await expect(documentSourcesAdapter.publish!('7', 11)).rejects.toThrow('409');
  });
});

describe('document themes (M4d, E.44)', () => {
  const THEME = {
    ref: 'tenant.workspace',
    version: 2,
    hash: 'abc',
    tokens: { accent: '#B74C05', accentOnDark: '#F0A070', accentUse: 'title-and-headings', letterhead: { logo: 'square', lines: ['phone', 'address'] } },
    sources: { accent: 'tenant.workspace' },
    adjustments: [],
  };
  const row = {
    id: 7,
    kind: 'template',
    title: 'Payment reminder',
    lang: 'en',
    default_view: 'page',
    full_width: false,
    blocks: [],
    draft_version: 5,
    published_version: null,
    theme: { ref: 'tenant.workspace', overrides: { accent: '#B74C05' } },
    resolvedTheme: THEME,
    updated_at: '2026-10-31 09:00:00',
  };

  it('loads the document with its theme choice and the theme it resolves to', async () => {
    GET.mockResolvedValueOnce({ data: { data: row }, response: new Response(null, { status: 200, headers: { ETag: '"5"' } }) });
    const loaded = await documentSourcesAdapter.load('7');
    expect(loaded.resolvedTheme).toEqual(THEME);
    expect(loaded.document.theme).toEqual({ ref: 'tenant.workspace', overrides: { accent: '#B74C05' } });
    expect(loaded.version).toBe(5);
  });

  it('a row without a theme loads with none', async () => {
    const bare = { ...row, theme: null, resolvedTheme: undefined };
    GET.mockResolvedValueOnce({ data: { data: bare }, response: new Response(null, { status: 200 }) });
    const loaded = await documentSourcesAdapter.load('7');
    expect(loaded.resolvedTheme).toBeNull();
    expect(loaded.document.theme).toBeUndefined();
  });

  it('returns the saved row’s theme after a save', async () => {
    PUT.mockResolvedValueOnce({ data: { data: { ...row, draft_version: 6 } }, response: new Response(null, { status: 200, headers: { ETag: '"6"' } }) });
    await expect(documentSourcesAdapter.save('7', { title: 'Payment reminder', blocks: [] }, 5)).resolves.toEqual({
      ok: true,
      version: 6,
      updatedAt: row.updated_at,
      resolvedTheme: THEME,
    });
  });

  it('loads a published version with the theme frozen when it was published', async () => {
    GET.mockResolvedValueOnce(ok({ version: 2, title: 'Payment reminder', blocks: [], theme_resolved: THEME }));
    await expect(documentSourcesAdapter.loadVersion!('7', 2)).resolves.toMatchObject({ resolvedTheme: THEME });
  });

  it('passes a theme-unavailable blocker through from the publish check', async () => {
    GET.mockResolvedValueOnce(ok([{ kind: 'theme-unavailable', ref: 'tenant.autumn' }]));
    await expect(documentSourcesAdapter.publishCheck!('7')).resolves.toEqual([{ kind: 'theme-unavailable', ref: 'tenant.autumn' }]);
  });
});

describe('label sheets and own fields (M4b)', () => {
  const row = {
    id: 9,
    tenant_id: 1,
    kind: 'label',
    title: 'Shipping labels',
    lang: 'en',
    default_view: 'page',
    full_width: false,
    page: { size: 'A4', marginMm: 16, letterhead: false, labels: { columns: 2, rows: 4, size: { widthMm: 99.1, heightMm: 67.7 }, gapMm: 2.5 } },
    record_type: null,
    own_fields: [{ key: 'ship_to', label: 'Ship to' }],
    blocks: [],
    draft_version: 3,
    published_version: null,
    scope: 'personal',
    required_permission: null,
    created_by: 1,
    owner_ou_id: null,
    updated_by: 1,
    created_at: '2026-10-30 09:00:00',
    updated_at: '2026-10-30 09:00:00',
  };

  it('keeps the label grid beside the page setup, and the own fields', () => {
    const doc = toDocument(row as never);
    expect(doc.page).toEqual({ size: 'A4', marginMm: 16, letterhead: false });
    expect(doc.labels).toEqual(row.page.labels);
    expect(doc.ownFields).toEqual([{ key: 'ship_to', label: 'Ship to' }]);
  });

  it('saves the grid inside page, and the own fields', async () => {
    PUT.mockResolvedValueOnce({ data: { data: { draft_version: 4, updated_at: 'now' } }, response: new Response(null, { status: 200, headers: { ETag: '"4"' } }) });
    const doc = toDocument(row as never);
    await documentSourcesAdapter.save('9', { title: doc.title, blocks: [], page: doc.page, labels: doc.labels, ownFields: doc.ownFields }, 3);
    expect(PUT.mock.calls[0][1].body).toEqual({
      title: 'Shipping labels',
      blocks: [],
      page: { ...row.page },
      own_fields: [{ key: 'ship_to', label: 'Ship to' }],
    });
  });

  describe('printLabels', () => {
    beforeAll(() => {
      Object.assign(URL, { createObjectURL: jest.fn(() => 'blob:x'), revokeObjectURL: jest.fn() });
    });

    it('prints records as type:id and reports the counts', async () => {
      POST.mockResolvedValueOnce({
        data: new Blob(['%PDF']),
        response: new Response(null, { status: 200, headers: { 'X-Labels-Count': '2', 'X-Labels-Clipped': '1' } }),
      });
      const result = await documentSourcesAdapter.printLabels!('9', { kind: 'records', records: [{ type: 'document', id: '3' }, { type: 'document', id: '4' }] }, 'Labels');
      expect(result).toEqual({ count: 2, clipped: 1 });
      expect(POST).toHaveBeenLastCalledWith('/api/v1/document-sources/{id}/print-labels', {
        params: { path: { id: 9 } },
        body: { records: ['document:3', 'document:4'] },
        parseAs: 'blob',
      });
    });

    it("sends the CSV as text with its columns, and throws the server's sentence", async () => {
      POST.mockResolvedValueOnce({ data: undefined, error: { error: 'The CSV has no column "Phone"' }, response: new Response(null, { status: 422 }) });
      const csv = 'Name\nExample Clinic\n';
      const file = new File([csv], 'list.csv', { type: 'text/csv' });
      await expect(
        documentSourcesAdapter.printLabels!('9', { kind: 'csv', file, columns: { Name: 'template.ship_to' } }, 'Labels')
      ).rejects.toThrow('The CSV has no column "Phone"');
      expect(POST.mock.calls.at(-1)[1].body).toEqual({ csv, columns: { Name: 'template.ship_to' } });
    });
  });
});

describe('issuing (M4c)', () => {
  it('loads what a published version says, with its own fields', async () => {
    GET.mockResolvedValueOnce(
      ok({ version: 4, title: 'Example Bakery invoice', page: { size: 'A4' }, blocks: [{ id: 'b1', type: 'paragraph' }], own_fields: [{ key: 'slot', label: 'Delivery slot' }], published_at: '2026-10-30T10:00:00Z' })
    );
    const content = await documentSourcesAdapter.loadVersion!('9', 4);
    expect(GET).toHaveBeenLastCalledWith('/api/v1/document-sources/{id}/versions/{version}', { params: { path: { id: 9, version: 4 } } });
    expect(content.title).toBe('Example Bakery invoice');
    expect(content.blocks).toEqual([{ id: 'b1', type: 'paragraph' }]);
    expect(content.ownFields).toEqual([{ key: 'slot', label: 'Delivery slot' }]);
    expect(content.page).toEqual({ size: 'A4' });
  });

  it('makes a draft letter from the published version to edit before issuing', async () => {
    POST.mockResolvedValueOnce({ data: { data: { id: 21 } }, response: new Response(null, { status: 201 }) });
    await expect(documentSourcesAdapter.copyForIssue!('9')).resolves.toBe('21');
    expect(POST).toHaveBeenLastCalledWith('/api/v1/document-sources/9/drafts', { body: {} });
  });

  it('issues with the record and the own fields as template.<key>', async () => {
    POST.mockResolvedValueOnce({ data: { data: { id: 88 }, render: { attempted: false, stored: false } }, response: new Response(null, { status: 201 }) });
    await expect(documentSourcesAdapter.issue!('9', { record: 'invoice:31', values: { slot: 'Tuesday' } })).resolves.toEqual({ ok: true, documentId: 88 });
    expect(POST).toHaveBeenLastCalledWith('/api/v1/documents', {
      body: { document_source_id: 9, record: 'invoice:31', values: { 'template.slot': 'Tuesday' } },
    });
  });

  it('sends neither record nor values when there are none', async () => {
    POST.mockResolvedValueOnce({ data: { data: { id: 88 }, render: {} }, response: new Response(null, { status: 201 }) });
    await documentSourcesAdapter.issue!('9', { record: null, values: {} });
    expect(POST.mock.calls.at(-1)[1].body).toEqual({ document_source_id: 9 });
  });

  it('returns a 422 with the missing paths, and a 409, as refusals', async () => {
    POST.mockResolvedValueOnce({
      data: undefined,
      error: { error: 'Some required fields have no value', details: { missing: ['template.slot'] } },
      response: new Response(null, { status: 422 }),
    });
    await expect(documentSourcesAdapter.issue!('9', { record: null, values: {} })).resolves.toEqual({
      ok: false,
      message: 'Some required fields have no value',
      missing: ['template.slot'],
    });

    POST.mockResolvedValueOnce({ data: undefined, error: { error: 'Publish the template first' }, response: new Response(null, { status: 409 }) });
    await expect(documentSourcesAdapter.issue!('9', { record: null, values: {} })).resolves.toEqual({
      ok: false,
      message: 'Publish the template first',
      missing: [],
    });
  });

  it('throws on anything else', async () => {
    POST.mockResolvedValueOnce({ data: undefined, error: { error: 'Forbidden' }, response: new Response(null, { status: 403 }) });
    await expect(documentSourcesAdapter.issue!('9', { record: null, values: {} })).rejects.toThrow('Forbidden');
  });
});
