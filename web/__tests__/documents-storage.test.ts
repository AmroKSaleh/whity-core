import type { DocTemplate } from '@/lib/documents/types';
import { api } from '@/lib/api/client';
import {
  deleteSaved,
  isDocTemplate,
  listSaved,
  migrateTemplate,
  newElement,
  saveTemplate,
} from '@/lib/documents/storage';

jest.mock('@/lib/api/client', () => ({
  api: { GET: jest.fn(), POST: jest.fn(), PATCH: jest.fn(), DELETE: jest.fn() },
}));

const mockGet = api.GET as unknown as jest.Mock;
const mockPost = api.POST as unknown as jest.Mock;
const mockPatch = api.PATCH as unknown as jest.Mock;
const mockDelete = api.DELETE as unknown as jest.Mock;

const PAGE = { widthMm: 100, heightMm: 100, marginMm: 0, background: '#ffffff' };

const V2_TEMPLATE = { version: 2, name: 'Invoice', page: PAGE, placeholders: [], pages: [{ id: 'p1', elements: [] }] };

function templateRow(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    id: 42,
    tenant_id: 1,
    name: 'Invoice',
    data: V2_TEMPLATE,
    scope: 'personal',
    is_system: false,
    created_by: 7,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-02T00:00:00Z',
    ...overrides,
  };
}

describe('listSaved — API-backed template list', () => {
  beforeEach(() => {
    mockGet.mockReset();
  });

  it('maps API rows to SavedTemplate, migrating v1→v2 on read', async () => {
    mockGet.mockResolvedValue({
      data: { data: [templateRow({ data: { version: 1, name: 'Legacy', page: PAGE, placeholders: [], elements: [] } })] },
      response: { status: 200, ok: true },
    });

    const list = await listSaved();
    expect(list).toHaveLength(1);
    expect(list[0]).toMatchObject({ id: '42', name: 'Invoice', updatedAt: '2026-01-02T00:00:00Z' });
    expect(list[0].data.version).toBe(2);
    expect(list[0].data.pages).toHaveLength(1);
  });

  // WHOLE-OBJECT, not toMatchObject. The suite above checks the fields it
  // knows about, which is exactly why nobody noticed that `scope` was not among
  // them: the designer never read it, never sent one, and the server reads a
  // missing scope on create as `personal` — creator-only. Every template
  // authored in the designer was invisible to the rest of the tenant, and no
  // assertion here could fail, because none of them looked at the whole row.
  //
  // Same shape of miss as `satisfied_by` on the route-template canvas (#1064).
  // An equality assertion is the one that notices a field going missing.
  it('maps the WHOLE SavedTemplate, scope included', async () => {
    mockGet.mockResolvedValue({
      data: { data: [templateRow({ scope: 'tenant' })] },
      response: { status: 200, ok: true },
    });

    expect(await listSaved()).toEqual([
      {
        id: '42',
        name: 'Invoice',
        updatedAt: '2026-01-02T00:00:00Z',
        data: V2_TEMPLATE,
        scope: 'tenant',
      },
    ]);
  });

  it('reads a row with no scope as personal — the server default, not undefined', async () => {
    const { scope: _dropped, ...noScope } = templateRow();
    mockGet.mockResolvedValue({ data: { data: [noScope] }, response: { status: 200, ok: true } });

    expect((await listSaved())[0].scope).toBe('personal');
  });

  it('skips a row whose data fails template validation instead of crashing', async () => {
    mockGet.mockResolvedValue({
      data: { data: [templateRow({ data: { garbage: true } })] },
      response: { status: 200, ok: true },
    });

    await expect(listSaved()).resolves.toEqual([]);
  });

  it('throws when the API call fails', async () => {
    mockGet.mockResolvedValue({ data: undefined, response: { status: 500, ok: false } });

    await expect(listSaved()).rejects.toThrow();
  });
});

describe('saveTemplate — create vs. update', () => {
  beforeEach(() => {
    mockPost.mockReset();
    mockPatch.mockReset();
  });

  it('POSTs (create) when no id is given', async () => {
    mockPost.mockResolvedValue({ data: { data: templateRow({ id: 99 }) }, error: undefined, response: { ok: true } });

    const id = await saveTemplate(V2_TEMPLATE as unknown as DocTemplate);

    expect(mockPost).toHaveBeenCalledWith('/api/v1/document-templates', {
      body: { name: 'Invoice', data: V2_TEMPLATE },
    });
    expect(id).toBe('99');
  });

  it('PATCHes (update) when an id is given', async () => {
    mockPatch.mockResolvedValue({ data: { data: templateRow({ id: 42 }) }, error: undefined, response: { ok: true } });

    const id = await saveTemplate(V2_TEMPLATE as unknown as DocTemplate, '42');

    expect(mockPatch).toHaveBeenCalledWith('/api/v1/document-templates/{id}', {
      params: { path: { id: 42 } },
      body: { name: 'Invoice', data: V2_TEMPLATE },
    });
    expect(id).toBe('42');
  });

  it('sends the stated scope on create', async () => {
    mockPost.mockResolvedValue({ data: { data: templateRow({ id: 99 }) }, error: undefined, response: { ok: true } });

    await saveTemplate(V2_TEMPLATE as unknown as DocTemplate, undefined, 'tenant');

    expect(mockPost).toHaveBeenCalledWith('/api/v1/document-templates', {
      body: { name: 'Invoice', data: V2_TEMPLATE, scope: 'tenant' },
    });
  });

  // The create above and this update are a pair, and the asymmetry is the
  // point. A missing scope means two different things to the server: on create
  // it DEFAULTS to personal, on update it LEAVES THE STORED VALUE ALONE. So the
  // designer must state one when creating and must not when updating — sending
  // it on update would let an ordinary save overwrite a visibility somebody set
  // deliberately in Templates & Blocks, using whatever the editor happened to
  // have loaded.
  it('never sends scope on update, whatever the caller passes', async () => {
    mockPatch.mockResolvedValue({ data: { data: templateRow({ id: 42 }) }, error: undefined, response: { ok: true } });

    await saveTemplate(V2_TEMPLATE as unknown as DocTemplate, '42', 'global');

    expect(mockPatch).toHaveBeenCalledWith('/api/v1/document-templates/{id}', {
      params: { path: { id: 42 } },
      body: { name: 'Invoice', data: V2_TEMPLATE },
    });
  });

  it('omits scope entirely when none is stated, leaving the server to default it', async () => {
    mockPost.mockResolvedValue({ data: { data: templateRow({ id: 99 }) }, error: undefined, response: { ok: true } });

    await saveTemplate(V2_TEMPLATE as unknown as DocTemplate);

    expect(mockPost.mock.calls[0][1].body).not.toHaveProperty('scope');
  });

  it('throws the server error message on failure', async () => {
    mockPost.mockResolvedValue({ data: undefined, error: { error: 'name is required' }, response: { ok: false } });

    await expect(saveTemplate(V2_TEMPLATE as unknown as DocTemplate)).rejects.toThrow('name is required');
  });
});

describe('deleteSaved', () => {
  it('DELETEs by id and resolves on success', async () => {
    mockDelete.mockReset();
    mockDelete.mockResolvedValue({ error: undefined, response: { ok: true } });

    await expect(deleteSaved('42')).resolves.toBeUndefined();
    expect(mockDelete).toHaveBeenCalledWith('/api/v1/document-templates/{id}', { params: { path: { id: 42 } } });
  });

  it('throws on failure (e.g. not found)', async () => {
    mockDelete.mockReset();
    mockDelete.mockResolvedValue({ error: { error: 'Template not found' }, response: { ok: false } });

    await expect(deleteSaved('42')).rejects.toThrow('Template not found');
  });
});

describe('template validation + migration', () => {
  it('accepts a legacy v1 template', () => {
    const v1 = { version: 1, name: 'Legacy', page: PAGE, placeholders: [], elements: [] };
    expect(isDocTemplate(v1)).toBe(true);
  });

  it('accepts a v2 template', () => {
    const v2 = { version: 2, name: 'New', page: PAGE, placeholders: [], pages: [] };
    expect(isDocTemplate(v2)).toBe(true);
  });

  it('rejects non-templates and structurally incomplete objects', () => {
    expect(isDocTemplate(null)).toBe(false);
    expect(isDocTemplate({ version: 2, name: 'x' })).toBe(false);
    expect(isDocTemplate({ version: 3, name: 'x', page: PAGE, placeholders: [], pages: [] })).toBe(false);
  });

  it('migrates a v1 template into a single-page v2 (elements → pages[0])', () => {
    const v1 = {
      version: 1,
      name: 'Legacy',
      page: PAGE,
      placeholders: [{ key: 'k', label: 'K', sample: 's' }],
      elements: [{ id: 'e1' }, { id: 'e2' }],
    };
    const m = migrateTemplate(v1 as unknown as DocTemplate);
    expect(m.version).toBe(2);
    expect(m.pages).toHaveLength(1);
    expect(m.pages[0].elements).toHaveLength(2);
    expect(m.pages[0].id).toBeTruthy();
    expect((m as unknown as Record<string, unknown>).elements).toBeUndefined();
  });

  it('treats a v1 template with no elements as an empty single page', () => {
    const v1 = { version: 1, name: 'Empty', page: PAGE, placeholders: [] };
    const m = migrateTemplate(v1 as unknown as DocTemplate);
    expect(m.pages).toHaveLength(1);
    expect(m.pages[0].elements).toEqual([]);
  });

  it('passes a v2 template through unchanged (idempotent)', () => {
    const v2 = {
      version: 2 as const,
      name: 'New',
      page: PAGE,
      placeholders: [],
      pages: [
        { id: 'p1', elements: [] },
        { id: 'p2', elements: [] },
      ],
    };
    const m = migrateTemplate(v2 as unknown as DocTemplate);
    expect(m.version).toBe(2);
    expect(m.pages).toHaveLength(2);
    expect(m.pages[1].id).toBe('p2');
  });
});

describe('newElement — factory defaults', () => {
  it('gives a new math element a non-empty default expression and a sensible size', () => {
    const el = newElement('math', []);
    expect(el.type).toBe('math');
    if (el.type !== 'math') throw new Error('unreachable');
    expect(el.expression.length).toBeGreaterThan(0);
    expect(el.w).toBeGreaterThan(0);
    expect(el.h).toBeGreaterThan(0);
    expect(el.block).toBe(true);
  });

  it('stacks a new element above the current highest z', () => {
    const existing = [{ ...newElement('rect', []), z: 5 }];
    const el = newElement('math', existing);
    expect(el.z).toBeGreaterThan(5);
  });
});
