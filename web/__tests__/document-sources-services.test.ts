/**
 * Editor milestone M3: web's live data for rich blocks. A record or chart the
 * reader may not see (404) comes back as null, so the block shows it as
 * hidden; uploads go up as multipart and come back as the block's file.
 */
const GET = jest.fn();
const POST = jest.fn();
jest.mock('@/lib/api/client', () => ({ api: { GET: (...a: unknown[]) => GET(...a), POST: (...a: unknown[]) => POST(...a) } }));

import { documentSourcesAdapter } from '@/lib/documents/sources';

const ok = (data: unknown) => ({ data: { data }, response: new Response(null, { status: 200 }) });
const notFound = () => ({ data: undefined, response: new Response(null, { status: 404 }) });

beforeEach(() => {
  GET.mockReset();
  POST.mockReset();
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
  it('loads the field groups, letterhead and print colour for a record type', async () => {
    GET.mockResolvedValueOnce(
      ok({
        record_types: [{ key: 'document', label: 'Document' }],
        groups: [{ group: 'Document', items: [{ path: 'document.title', label: 'Document title' }] }],
        letterhead: { name: 'Example Bakery', lines: ['Mecca Street 112, Amman'] },
        accent: '#B74C05',
      })
    );
    await expect(documentSourcesAdapter.loadTemplate!('document')).resolves.toEqual({
      fields: [{ group: 'Document', items: [{ path: 'document.title', label: 'Document title' }] }],
      letterhead: { name: 'Example Bakery', lines: ['Mecca Street 112, Amman'] },
      accent: '#B74C05',
    });
    expect(GET).toHaveBeenLastCalledWith('/api/v1/document-fields', { params: { query: { recordType: 'document' } } });
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
