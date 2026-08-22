import { createDocumentDesignerAdapter } from '../adapter';
import type { DocTemplate } from '@amroksaleh/ui/documents/types';
import type { DocBlock } from '@amroksaleh/ui/documents/blocks';
import type { Transport, TransportResponse } from '../types';

/**
 * Contract tests for the transport-backed adapter factory.
 *
 * This is the code path the DESKTOP client runs — web keeps its own
 * openapi-fetch implementation, covered by `web/__tests__/documents-*.test.ts`
 * — so without these the desktop half of the designer's persistence would ship
 * with no coverage at all. They assert the wire contract (method, path, body
 * shape, and how a failure surfaces) rather than restating the implementation.
 */

const PAGE = { widthMm: 100, heightMm: 100, marginMm: 0, background: '#ffffff' };

const V2_TEMPLATE = {
  version: 2,
  name: 'Invoice',
  page: PAGE,
  placeholders: [],
  pages: [{ id: 'p1', elements: [] }],
} as unknown as DocTemplate;

const ELEMENTS = [
  { id: 'e1', type: 'text', x: 0, y: 0, w: 50, h: 10, rotation: 0, z: 1, text: 'Hi', style: {} },
  { id: 'e2', type: 'line', x: 10, y: 20, w: 30, h: 1, rotation: 0, z: 2, stroke: '#000', strokeWidth: 0.4 },
];

/** Records every call and answers from a queue of canned responses. */
function fakeTransport(...responses: TransportResponse[]) {
  const calls: Array<{ method: string; path: string; body?: unknown }> = [];
  const queue = [...responses];
  const transport: Transport = {
    request(method, path, body) {
      calls.push({ method, path, body });
      return Promise.resolve(queue.shift() ?? { status: 200, body: null });
    },
  };
  return { transport, calls };
}

const ok = (body: unknown): TransportResponse => ({ status: 200, body });

describe('createDocumentDesignerAdapter — templates', () => {
  it('lists templates, mapping rows, and requests the collection unadorned', async () => {
    const { transport, calls } = fakeTransport(
      ok({ data: [{ id: 42, name: 'Invoice', updated_at: '2026-08-01T00:00:00Z', data: V2_TEMPLATE }] }),
    );

    const result = await createDocumentDesignerAdapter(transport).listTemplates();

    expect(calls[0].method).toBe('GET');
    // No `per_page`, on purpose: this endpoint answers with every visible row
    // (no pagination envelope, no declared query params). Pinned so that a
    // future page-size parameter has to be a deliberate change, with the page
    // walk that would then be required rather than a cap.
    expect(calls[0].path).toBe('/api/v1/document-templates');
    expect(result).toHaveLength(1);
    expect(result[0]).toMatchObject({ id: '42', name: 'Invoice', updatedAt: '2026-08-01T00:00:00Z' });
  });

  it('skips a row whose data is not a valid template rather than failing the whole list', async () => {
    const { transport } = fakeTransport(
      ok({
        data: [
          { id: 1, name: 'Good', updated_at: 'x', data: V2_TEMPLATE },
          { id: 2, name: 'Corrupt', updated_at: 'x', data: { nope: true } },
        ],
      }),
    );

    const result = await createDocumentDesignerAdapter(transport).listTemplates();

    expect(result.map((t) => t.id)).toEqual(['1']);
  });

  it('throws the message the server supplied on a failure status', async () => {
    const { transport } = fakeTransport({ status: 403, body: { error: 'Forbidden by policy' } });

    await expect(createDocumentDesignerAdapter(transport).listTemplates()).rejects.toThrow(
      'Forbidden by policy',
    );
  });

  it('falls back to a status-bearing message when the body carries none', async () => {
    const { transport } = fakeTransport({ status: 500, body: null });

    await expect(createDocumentDesignerAdapter(transport).listTemplates()).rejects.toThrow(
      'Failed to load templates (500)',
    );
  });

  it('POSTs a create when no id is supplied and returns the new id', async () => {
    const { transport, calls } = fakeTransport(ok({ data: { id: 7 } }));

    const id = await createDocumentDesignerAdapter(transport).saveTemplate(V2_TEMPLATE);

    expect(calls[0]).toMatchObject({ method: 'POST', path: '/api/v1/document-templates' });
    expect(calls[0].body).toEqual({ name: 'Invoice', data: V2_TEMPLATE });
    expect(id).toBe('7');
  });

  it('PATCHes an update when an id is supplied', async () => {
    const { transport, calls } = fakeTransport(ok({ data: { id: 7 } }));

    const id = await createDocumentDesignerAdapter(transport).saveTemplate(V2_TEMPLATE, '7');

    expect(calls[0]).toMatchObject({ method: 'PATCH', path: '/api/v1/document-templates/7' });
    expect(id).toBe('7');
  });

  it('deletes by id', async () => {
    const { transport, calls } = fakeTransport(ok(null));

    await createDocumentDesignerAdapter(transport).deleteTemplate('9');

    expect(calls[0]).toMatchObject({ method: 'DELETE', path: '/api/v1/document-templates/9' });
  });
});

describe('createDocumentDesignerAdapter — blocks', () => {
  it('lists blocks, deriving w/h from the elements bounding box', async () => {
    const { transport, calls } = fakeTransport(
      ok({ data: [{ id: 5, name: 'Header', scope: 'tenant', data: ELEMENTS }] }),
    );

    const result = await createDocumentDesignerAdapter(transport).listBlocks();

    expect(calls[0].path).toBe('/api/v1/document-blocks');
    expect(result[0]).toMatchObject({ id: '5', name: 'Header', scope: 'tenant', w: 50, h: 21 });
  });

  it('falls back to the personal scope for an unrecognised one', async () => {
    const { transport } = fakeTransport(
      ok({ data: [{ id: 5, name: 'Header', scope: 'not-a-scope', data: ELEMENTS }] }),
    );

    const result = await createDocumentDesignerAdapter(transport).listBlocks();

    expect(result[0].scope).toBe('personal');
  });

  it('treats a purely-numeric block id as an UPDATE', async () => {
    const { transport, calls } = fakeTransport(ok({ data: { id: 5 } }));
    const block = {
      id: '5',
      name: 'Header',
      scope: 'tenant',
      w: 1,
      h: 1,
      elements: ELEMENTS,
    } as unknown as DocBlock;

    await createDocumentDesignerAdapter(transport).saveBlock(block);

    expect(calls[0]).toMatchObject({ method: 'PATCH', path: '/api/v1/document-blocks/5' });
  });

  it('treats a client-minted uuid as a CREATE', async () => {
    // The discriminator is the id's SHAPE: makeBlockFromElements mints a uuid
    // for a block that has never been saved, and every id the backend hands
    // back is numeric. Getting this backwards would silently PATCH a
    // nonexistent record the first time a block is saved.
    const { transport, calls } = fakeTransport(ok({ data: { id: 12 } }));
    const block = {
      id: 'b3f1c4d2-0000-4000-8000-000000000000',
      name: 'Header',
      scope: 'personal',
      w: 1,
      h: 1,
      elements: ELEMENTS,
    } as unknown as DocBlock;

    const id = await createDocumentDesignerAdapter(transport).saveBlock(block);

    expect(calls[0]).toMatchObject({ method: 'POST', path: '/api/v1/document-blocks' });
    expect(id).toBe('12');
  });

  it('surfaces the 409 reference-integrity message the backend returns on delete', async () => {
    const { transport } = fakeTransport({
      status: 409,
      body: { error: 'Block is still referenced by 2 templates' },
    });

    await expect(createDocumentDesignerAdapter(transport).deleteBlock('5')).rejects.toThrow(
      'Block is still referenced by 2 templates',
    );
  });
});
