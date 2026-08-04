import { api } from '@/lib/api/client';
import { deleteBlock, listBlocks, saveBlock } from '@/lib/documents/blocks';
import type { DocBlock } from '@amroksaleh/ui/documents/blocks';

jest.mock('@/lib/api/client', () => ({
  api: { GET: jest.fn(), POST: jest.fn(), PATCH: jest.fn(), DELETE: jest.fn() },
}));

const mockGet = api.GET as unknown as jest.Mock;
const mockPost = api.POST as unknown as jest.Mock;
const mockPatch = api.PATCH as unknown as jest.Mock;
const mockDelete = api.DELETE as unknown as jest.Mock;

const HEADER_ELEMENTS = [
  { id: 'e1', type: 'dynamicText', x: 0, y: 0, w: 120, h: 10, rotation: 0, z: 1, template: '{{company_name}}', style: {} },
  { id: 'e2', type: 'line', x: 0, y: 20, w: 180, h: 0.4, rotation: 0, z: 2, stroke: '#333', strokeWidth: 0.4 },
];

function blockRow(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    id: 17,
    tenant_id: 1,
    name: 'Company header',
    data: HEADER_ELEMENTS,
    scope: 'system',
    is_system: true,
    created_by: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-02T00:00:00Z',
    ...overrides,
  };
}

describe('listBlocks — API-backed block list', () => {
  beforeEach(() => {
    mockGet.mockReset();
  });

  it('maps rows to DocBlock, deriving w/h from the elements bounding box', async () => {
    mockGet.mockResolvedValue({ data: { data: [blockRow()] }, response: { status: 200, ok: true } });

    const list = await listBlocks();
    expect(list).toHaveLength(1);
    expect(list[0]).toMatchObject({ id: '17', name: 'Company header', scope: 'system' });
    // bounding box: max(x+w) = 180 (the line), max(y+h) = 20.4 (the line's y+h)
    expect(list[0].w).toBe(180);
    expect(list[0].h).toBeCloseTo(20.4);
    expect(list[0].elements).toEqual(HEADER_ELEMENTS);
  });

  it('falls back to "personal" for an unrecognised scope value', async () => {
    mockGet.mockResolvedValue({
      data: { data: [blockRow({ scope: 'something-unexpected' })] },
      response: { status: 200, ok: true },
    });

    const list = await listBlocks();
    expect(list[0].scope).toBe('personal');
  });

  it('skips a row whose data is not an element list', async () => {
    mockGet.mockResolvedValue({ data: { data: [blockRow({ data: { not: 'a list' } })] }, response: { status: 200, ok: true } });

    await expect(listBlocks()).resolves.toEqual([]);
  });

  it('throws when the API call fails', async () => {
    mockGet.mockResolvedValue({ data: undefined, response: { status: 500, ok: false } });

    await expect(listBlocks()).rejects.toThrow();
  });
});

describe('saveBlock — create vs. update by id shape', () => {
  beforeEach(() => {
    mockPost.mockReset();
    mockPatch.mockReset();
  });

  it('POSTs (create) when the id is a fresh client-generated (non-numeric) id', async () => {
    mockPost.mockResolvedValue({ data: { data: blockRow({ id: 55 }) }, error: undefined, response: { ok: true } });

    const block: DocBlock = { id: 'a1b2c3d4-uuid', name: 'New block', scope: 'personal', w: 40, h: 10, elements: HEADER_ELEMENTS as never };
    const id = await saveBlock(block);

    expect(mockPost).toHaveBeenCalledWith('/api/v1/document-blocks', {
      body: { name: 'New block', data: HEADER_ELEMENTS, scope: 'personal' },
    });
    expect(mockPatch).not.toHaveBeenCalled();
    expect(id).toBe('55');
  });

  it('PATCHes (update) when the id is a purely-numeric (existing backend) id', async () => {
    mockPatch.mockResolvedValue({ data: { data: blockRow({ id: 17 }) }, error: undefined, response: { ok: true } });

    const block: DocBlock = { id: '17', name: 'Company header', scope: 'system', w: 180, h: 21, elements: HEADER_ELEMENTS as never };
    const id = await saveBlock(block);

    expect(mockPatch).toHaveBeenCalledWith('/api/v1/document-blocks/{id}', {
      params: { path: { id: 17 } },
      body: { name: 'Company header', data: HEADER_ELEMENTS, scope: 'system' },
    });
    expect(mockPost).not.toHaveBeenCalled();
    expect(id).toBe('17');
  });

  it('throws the server error message on failure (e.g. publish gate)', async () => {
    mockPatch.mockResolvedValue({ data: undefined, error: { error: 'Publishing a shared block requires documents:publish' }, response: { ok: false } });

    const block: DocBlock = { id: '17', name: 'Company header', scope: 'tenant', w: 180, h: 21, elements: HEADER_ELEMENTS as never };
    await expect(saveBlock(block)).rejects.toThrow('documents:publish');
  });
});

describe('deleteBlock', () => {
  beforeEach(() => {
    mockDelete.mockReset();
  });

  it('DELETEs by id and resolves on success', async () => {
    mockDelete.mockResolvedValue({ error: undefined, response: { ok: true } });

    await expect(deleteBlock('17')).resolves.toBeUndefined();
    expect(mockDelete).toHaveBeenCalledWith('/api/v1/document-blocks/{id}', { params: { path: { id: 17 } } });
  });

  it('throws on the reference-integrity 409 (a template still references the block)', async () => {
    mockDelete.mockResolvedValue({
      error: { error: 'Cannot delete a block that is still referenced by a template' },
      response: { ok: false },
    });

    await expect(deleteBlock('17')).rejects.toThrow('still referenced');
  });
});
