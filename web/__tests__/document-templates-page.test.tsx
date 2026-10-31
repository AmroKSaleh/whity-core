import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * The Document templates page (A31): editor templates and label sheets, and
 * who can see each one (editor M4c).
 *
 * Each case asserts an outcome a broken page could not produce while still
 * painting: what is listed (both kinds, draft or published, starter and
 * converted marks), what is sent (the PATCH, the copy, the delete), and what is
 * gated (sharing on documents:publish, not on write).
 */

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 10, email: 'a@b.c', role: '', tenant_id: 1 } }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));

const push = jest.fn();
jest.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));

const mockCreateDocumentSource = jest.fn();
jest.mock('@/lib/documents/sources', () => ({
  createDocumentSource: (...args: unknown[]) => mockCreateDocumentSource(...args),
}));

const hasPermission = jest.fn<boolean, [string]>();
const capabilityList: string[] = [];
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ permissions: capabilityList, loading: false, hasPermission }),
}));

import DocumentTemplatesPage from '@/app/(protected)/admin/document-templates/page';

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  });
}

const base = {
  lang: 'en',
  own_fields: [],
  draft_version: 3,
  required_permission: null,
  owner_ou_id: null,
  created_by: 10,
  updated_at: '2026-01-02T00:00:00Z',
  starter_key: null,
  legacy_template_id: null,
};

/** One of Whity's starters, published, bound to invoices. */
const STARTER = {
  ...base,
  id: 1,
  kind: 'template',
  title: 'Example Bakery invoice',
  record_type: 'invoice',
  published_version: 2,
  scope: 'system',
  created_by: null,
  starter_key: 'invoice',
};

/** Converted from the old designer, filed at a unit, never published. */
const CONVERTED = {
  ...base,
  id: 6,
  kind: 'template',
  title: 'Example Bakery works order',
  record_type: null,
  published_version: null,
  scope: 'tenant',
  owner_ou_id: 2,
  legacy_template_id: 41,
};

/** A label sheet. */
const LABELS = {
  ...base,
  id: 8,
  kind: 'label',
  title: 'Example Bakery shelf labels',
  record_type: null,
  published_version: 1,
  scope: 'personal',
};

interface RouteOptions {
  templates?: unknown[];
  labels?: unknown[];
  listStatus?: number;
  ousStatus?: number;
  permissionsStatus?: number;
  patchStatus?: number;
  patchError?: string;
}

function routeApi(options: RouteOptions = {}) {
  mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
    const method = init?.method ?? 'GET';
    if (method === 'GET' && url === '/api/v1/document-sources?kind=template') {
      return options.listStatus
        ? jsonResponse(options.listStatus, { error: 'forbidden' })
        : jsonResponse(200, { data: options.templates ?? [STARTER, CONVERTED] });
    }
    if (method === 'GET' && url === '/api/v1/document-sources?kind=label') {
      return options.listStatus
        ? jsonResponse(options.listStatus, { error: 'forbidden' })
        : jsonResponse(200, { data: options.labels ?? [LABELS] });
    }
    if (url === '/api/v1/document-embeds') {
      return jsonResponse(200, { data: { record_types: [{ key: 'invoice', label: 'Invoice' }], chart_sources: [] } });
    }
    if (url.startsWith('/api/v1/ous')) {
      const status = options.ousStatus ?? 200;
      return status === 200
        ? jsonResponse(200, {
            data: [
              { id: 1, name: 'Example Bakery' },
              { id: 2, name: 'Example Bakery kitchen' },
            ],
            pagination: { page: 1, perPage: 100, total: 2, totalPages: 1 },
          })
        : jsonResponse(status, { error: 'forbidden' });
    }
    if (url.startsWith('/api/v1/permissions')) {
      const status = options.permissionsStatus ?? 200;
      return status === 200
        ? jsonResponse(200, { data: [{ name: 'documents:publish' }, { name: 'documents:write' }] })
        : jsonResponse(status, { error: 'forbidden' });
    }
    if (method === 'PATCH' && /^\/api\/v1\/document-sources\/\d+$/.test(url)) {
      const status = options.patchStatus ?? 200;
      return jsonResponse(status, status === 200 ? { data: {} } : { error: options.patchError ?? 'refused' });
    }
    if (method === 'DELETE' && /^\/api\/v1\/document-sources\/\d+$/.test(url)) {
      return Promise.resolve({ ok: true, status: 204, json: () => Promise.reject(new Error('no body')) });
    }
    if (method === 'POST' && /^\/api\/v1\/document-sources\/\d+\/copy$/.test(url)) {
      return jsonResponse(201, { data: { ...CONVERTED, id: 77, scope: 'personal' } });
    }
    return jsonResponse(404, {});
  });
}

function dialog() {
  return within(screen.getByRole('dialog'));
}

async function openRowMenu(user: ReturnType<typeof userEvent.setup>, name: string) {
  await user.click(screen.getByRole('button', { name: `Actions for ${name}` }));
}

function callsTo(url: string, method: string) {
  return mockApiClient.mock.calls.filter(
    ([u, init]) => u === url && ((init as RequestInit | undefined)?.method ?? 'GET') === method
  );
}

beforeEach(() => {
  jest.clearAllMocks();
  capabilityList.length = 0;
  capabilityList.push('documents:read', 'documents:write', 'documents:publish');
  hasPermission.mockImplementation((slug) => capabilityList.includes(slug));
  routeApi();
});

describe('the list', () => {
  it('lists templates and label sheets together, with kind, record type and status', async () => {
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Example Bakery works order')).toBeInTheDocument());

    const starter = screen.getByText('Example Bakery invoice').closest('tr')!;
    expect(starter).toHaveTextContent('Template');
    // The record type by its name, from the embeds catalogue.
    expect(starter).toHaveTextContent('Invoice');
    expect(starter).toHaveTextContent('Published v2');

    const converted = screen.getByText('Example Bakery works order').closest('tr')!;
    expect(converted).toHaveTextContent('Draft');
    expect(converted).toHaveTextContent('None');

    const labels = screen.getByText('Example Bakery shelf labels').closest('tr')!;
    expect(labels).toHaveTextContent('Label sheet');
    expect(labels).toHaveTextContent('Published v1');
  });

  it('marks Whity starters and templates converted from the old designer', async () => {
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Example Bakery works order')).toBeInTheDocument());

    const starter = screen.getByText('Example Bakery invoice').closest('tr')!;
    expect(within(starter).getByText('Whity starter')).toBeInTheDocument();
    expect(within(starter).queryByText('Converted')).not.toBeInTheDocument();

    const converted = screen.getByText('Example Bakery works order').closest('tr')!;
    expect(within(converted).getByText('Converted')).toBeInTheDocument();
    expect(within(converted).queryByText('Whity starter')).not.toBeInTheDocument();
  });

  it('shows who can see each one as its tier', async () => {
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Example Bakery works order')).toBeInTheDocument());

    expect(screen.getByText('Example Bakery invoice').closest('tr')).toHaveTextContent('System');
    expect(screen.getByText('Example Bakery works order').closest('tr')).toHaveTextContent('Tenant-wide');
    expect(screen.getByText('Example Bakery shelf labels').closest('tr')).toHaveTextContent('Personal');
  });

  it('links each title to the editor', async () => {
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Example Bakery works order')).toBeInTheDocument());

    expect(screen.getByRole('link', { name: 'Example Bakery works order' })).toHaveAttribute(
      'href',
      '/admin/document-sources/6'
    );
  });

  it('names an untitled template rather than leaving the cell blank', async () => {
    routeApi({ templates: [{ ...CONVERTED, title: '' }], labels: [] });
    render(<DocumentTemplatesPage />);

    await waitFor(() => expect(screen.getByRole('link', { name: 'Untitled' })).toBeInTheDocument());
  });

  it('says why units show by id when they cannot be read', async () => {
    routeApi({ ousStatus: 403 });
    render(<DocumentTemplatesPage />);

    await waitFor(() => expect(screen.getByText(/Listing units needs the ous:read permission/)).toBeInTheDocument());
  });
});

describe('New template', () => {
  it('creates an editor template and opens it', async () => {
    mockCreateDocumentSource.mockResolvedValue({ id: 91 });
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);

    await user.click(screen.getByRole('button', { name: 'New template' }));

    await waitFor(() => expect(push).toHaveBeenCalledWith('/admin/document-sources/91'));
    expect(mockCreateDocumentSource).toHaveBeenCalledWith('template', '');
  });

  it('says so when the template was not created', async () => {
    mockCreateDocumentSource.mockRejectedValue(new Error('500'));
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);

    await user.click(screen.getByRole('button', { name: 'New template' }));

    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith('The template wasn’t created. Try again.', 'error')
    );
    expect(push).not.toHaveBeenCalled();
  });

  it('is not offered to somebody who cannot write', async () => {
    capabilityList.length = 0;
    capabilityList.push('documents:read');
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Example Bakery works order')).toBeInTheDocument());

    expect(screen.queryByRole('button', { name: 'New template' })).not.toBeInTheDocument();
  });
});

describe('row actions', () => {
  it('opens a row in the editor', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Example Bakery works order')).toBeInTheDocument());

    await openRowMenu(user, 'Example Bakery works order');
    await user.click(screen.getByRole('menuitem', { name: 'Open' }));

    expect(push).toHaveBeenCalledWith('/admin/document-sources/6');
  });

  it('uses a copy: asks for a title, copies, and opens the copy', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Example Bakery invoice')).toBeInTheDocument());

    await openRowMenu(user, 'Example Bakery invoice');
    await user.click(screen.getByRole('menuitem', { name: 'Use a copy…' }));

    const input = await screen.findByLabelText('Title of the copy');
    expect(input).toHaveValue('Example Bakery invoice (copy)');
    await user.clear(input);
    await user.type(input, 'Example Bakery invoice, kitchen');
    await user.click(dialog().getByRole('button', { name: 'Make a copy' }));

    await waitFor(() => expect(push).toHaveBeenCalledWith('/admin/document-sources/77'));
    const [, init] = callsTo('/api/v1/document-sources/1/copy', 'POST')[0];
    expect(JSON.parse((init as RequestInit).body as string)).toEqual({ title: 'Example Bakery invoice, kitchen' });
  });

  it('deletes after confirming, and says issued documents are kept', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Example Bakery works order')).toBeInTheDocument());

    await openRowMenu(user, 'Example Bakery works order');
    await user.click(screen.getByRole('menuitem', { name: 'Delete…' }));

    expect(await screen.findByText('Documents already issued from it are kept')).toBeInTheDocument();
    // Nothing is sent until the confirmation.
    expect(callsTo('/api/v1/document-sources/6', 'DELETE')).toHaveLength(0);

    await user.click(dialog().getByRole('button', { name: 'Delete' }));

    await waitFor(() => expect(callsTo('/api/v1/document-sources/6', 'DELETE')).toHaveLength(1));
    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith('Deleted “Example Bakery works order”', 'success')
    );
  });

  it('warns before deleting a Whity starter', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Example Bakery invoice')).toBeInTheDocument());

    await openRowMenu(user, 'Example Bakery invoice');
    await user.click(screen.getByRole('menuitem', { name: 'Delete…' }));

    expect(await screen.findByText('This is a Whity starter')).toBeInTheDocument();
  });
});

describe('who can see it', () => {
  it('is gated on documents:publish, while copying stays available to a writer', async () => {
    capabilityList.length = 0;
    capabilityList.push('documents:read', 'documents:write');

    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Example Bakery works order')).toBeInTheDocument());

    await openRowMenu(user, 'Example Bakery works order');
    expect(screen.getByRole('menuitem', { name: 'Who can see it…' })).toHaveAttribute('aria-disabled', 'true');
    expect(screen.getByRole('menuitem', { name: 'Use a copy…' })).toBeEnabled();
  });

  it('previews the audience live and PATCHes the three fields together', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Example Bakery works order')).toBeInTheDocument());

    await openRowMenu(user, 'Example Bakery works order');
    await user.click(screen.getByRole('menuitem', { name: 'Who can see it…' }));

    await waitFor(() =>
      expect(dialog().getByText(/Visible to everyone at Example Bakery kitchen and below it/)).toBeInTheDocument()
    );
    expect(dialog().getByRole('button', { name: 'Save visibility' })).toBeDisabled();

    await user.click(dialog().getByRole('combobox', { name: /Also requires/i }));
    await user.click(screen.getByRole('option', { name: 'documents:publish' }));
    await user.click(dialog().getByRole('button', { name: 'Save visibility' }));

    await waitFor(() => expect(callsTo('/api/v1/document-sources/6', 'PATCH')).toHaveLength(1));
    const [, init] = callsTo('/api/v1/document-sources/6', 'PATCH')[0];
    expect(JSON.parse((init as RequestInit).body as string)).toEqual({
      scope: 'tenant',
      owner_ou_id: 2,
      required_permission: 'documents:publish',
    });
  });

  it('shows the server’s reason when sharing is refused', async () => {
    routeApi({ patchStatus: 403, patchError: 'Sharing a document requires documents:publish' });
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Example Bakery shelf labels')).toBeInTheDocument());

    await openRowMenu(user, 'Example Bakery shelf labels');
    await user.click(screen.getByRole('menuitem', { name: 'Who can see it…' }));
    await user.click(await screen.findByRole('combobox', { name: /Visibility tier/i }));
    await user.click(screen.getByRole('option', { name: 'Tenant-wide' }));
    await user.click(dialog().getByRole('button', { name: 'Save visibility' }));

    expect(await dialog().findByText('Sharing a document requires documents:publish')).toBeInTheDocument();
  });
});

describe('failure states', () => {
  it('renders the access-denied state on a 403', async () => {
    routeApi({ listStatus: 403 });
    render(<DocumentTemplatesPage />);

    await waitFor(() => expect(screen.getAllByText('Access denied').length).toBeGreaterThan(0));
    expect(screen.getByText(/You need the documents:read permission/)).toBeInTheDocument();
  });

  it('treats a row without the M4c fields as neither a starter nor converted', async () => {
    const older: Record<string, unknown> = { ...CONVERTED };
    delete older.starter_key;
    delete older.legacy_template_id;
    routeApi({ templates: [older], labels: [] });
    render(<DocumentTemplatesPage />);

    await waitFor(() => expect(screen.getByText('Example Bakery works order')).toBeInTheDocument());
    expect(screen.queryByText('Converted')).not.toBeInTheDocument();
    expect(screen.queryByText('Whity starter')).not.toBeInTheDocument();
  });
});

describe('Issue a document (M4c)', () => {
  it('opens a published template in the editor’s issuing mode', async () => {
    capabilityList.push('documents:issue');
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Example Bakery invoice')).toBeInTheDocument());

    await openRowMenu(user, 'Example Bakery invoice');
    await user.click(screen.getByRole('menuitem', { name: 'Issue a document' }));

    expect(push).toHaveBeenCalledWith('/admin/document-sources/1?issue=1');
  });

  it('is not offered for a draft template, a label sheet, or without documents:issue', async () => {
    capabilityList.push('documents:issue');
    const user = userEvent.setup();
    const { unmount } = render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Example Bakery works order')).toBeInTheDocument());

    await openRowMenu(user, 'Example Bakery works order');
    expect(screen.queryByRole('menuitem', { name: 'Issue a document' })).not.toBeInTheDocument();
    await user.keyboard('{Escape}');
    await openRowMenu(user, 'Example Bakery shelf labels');
    expect(screen.queryByRole('menuitem', { name: 'Issue a document' })).not.toBeInTheDocument();
    await user.keyboard('{Escape}');
    unmount();

    capabilityList.splice(capabilityList.indexOf('documents:issue'), 1);
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Example Bakery invoice')).toBeInTheDocument());
    await openRowMenu(user, 'Example Bakery invoice');
    expect(screen.queryByRole('menuitem', { name: 'Issue a document' })).not.toBeInTheDocument();
  });
});
