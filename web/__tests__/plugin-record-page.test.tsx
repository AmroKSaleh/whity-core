/**
 * #948: the plugin RECORD route — `/admin/x/[featureId]/[recordId]`.
 *
 * Everything a plugin record page needs shipped with SDK 1.33/1.34 except the
 * address: `dataRecord.source` accepts `{record}`, the reserved binding the SDK
 * documents as "the record a record-page ROUTE is about", and `BlockRenderer`
 * accepts a `record` prop — but no route ever passed one, so in a real session
 * `{record}` could not bind. These tests pin the three things that closes:
 *
 *  1. **The route binds `{record}`.** The segment reaches the block tree and a
 *     `dataRecord` fetches THAT record — asserted on the request, because a
 *     page that renders while requesting the collection looks identical.
 *  2. **A crud row's Edit action navigates** to the record's address instead of
 *     opening a dialog over the list.
 *  3. **A hard reload on the record URL renders.** Nothing is carried over from
 *     the click: the page is mounted with only the URL, exactly as a pasted
 *     link or a refresh delivers it, and it still shows the record.
 *
 * The failure modes around an unknown id are pinned too, because getting them
 * wrong is what turns a permission problem into a "broken page" (#951): the URL
 * is kept and the cause is named, rather than bouncing the caller back to a
 * list that will not say why.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { OpenApiSpec } from '@/lib/plugin-crud-schema';
import type { PluginFeature } from '@/lib/plugin-features';

// ---------------------------------------------------------------------------
// Provider seams. The route owns exactly these, so the tests inject them and
// drive the real components underneath.
// ---------------------------------------------------------------------------

const push = jest.fn();
let params: Record<string, string> = {};
jest.mock('next/navigation', () => ({
  useParams: () => params,
  useRouter: () => ({ push }),
}));

let features: PluginFeature[] = [];
let featuresLoading = false;
jest.mock('@/lib/plugin-features-context', () => ({
  usePluginFeatures: () => ({ features, isLoading: featuresLoading }),
}));

const mockApiClient = jest.fn();
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => mockApiClient(...args),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('@/lib/direction-context', () => ({ useDirection: () => ({ dir: 'ltr' }) }));

import PluginRecordPage from '@/app/(protected)/admin/x/[featureId]/[recordId]/page';
import { CrudScreen } from '@/components/plugin/crud-screen';

// Radix (dropdown menu, select) needs Pointer Capture + scrollIntoView, absent
// in jsdom.
beforeAll(() => {
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

function stubResponse(body: unknown, status = 200): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/** A `screen:'blocks'` feature whose record source names `{record}`. */
const BLOCKS_FEATURE: PluginFeature = {
  id: 'acme-widgets',
  plugin: 'Acme',
  label: 'Widgets',
  icon: null,
  group: 'plugins',
  order: 100,
  screen: 'blocks',
  resource: null,
  action: null,
  embed: null,
  blocks: [
    {
      type: 'dataRecord',
      id: 'widget',
      source: '/api/v1/acme/widgets/{record}',
      fields: [
        { field: 'name', label: 'Name' },
        { field: 'status', label: 'Status' },
      ],
      children: [{ type: 'recordFields', from: 'widget' }],
    },
  ] as PluginFeature['blocks'],
  requiredPermission: 'acme:view',
  capabilities: { canCreate: false, canEdit: false, canDelete: false },
};

const CRUD_SPEC: OpenApiSpec = {
  paths: {
    '/api/v1/demo/things': {
      get: {
        responses: {
          '200': {
            content: {
              'application/json': {
                schema: {
                  type: 'object',
                  properties: {
                    data: { type: 'array', items: { $ref: '#/components/schemas/Thing' } },
                  },
                },
              },
            },
          },
        },
      },
      post: {
        requestBody: {
          content: { 'application/json': { schema: { $ref: '#/components/schemas/ThingInput' } } },
        },
      },
    },
    '/api/v1/demo/things/{id}': {
      patch: {
        requestBody: {
          content: { 'application/json': { schema: { $ref: '#/components/schemas/ThingInput' } } },
        },
      },
      delete: {},
    },
  },
  components: {
    schemas: {
      Thing: {
        type: 'object',
        properties: {
          id: { type: 'integer' },
          name: { type: 'string' },
        },
      },
      ThingInput: {
        type: 'object',
        required: ['name'],
        properties: { name: { type: 'string' } },
      },
    },
  },
};

const CRUD_FEATURE: PluginFeature = {
  id: 'demo-things',
  plugin: 'Demo',
  label: 'Things',
  icon: null,
  group: 'plugins',
  order: 100,
  screen: 'crud',
  resource: { basePath: '/api/v1/demo/things', titleField: 'name' },
  action: null,
  embed: null,
  requiredPermission: 'demo:view',
  capabilities: { canCreate: true, canEdit: true, canDelete: true },
};

const THINGS = [
  { id: 1, name: 'First thing' },
  { id: 2, name: 'Second thing' },
];

/** Serve the OpenAPI document `fetchSpec()` reads over the same-origin proxy. */
function serveSpec(): void {
  global.fetch = jest.fn(() =>
    Promise.resolve(stubResponse(CRUD_SPEC))
  ) as unknown as typeof fetch;
}

beforeEach(() => {
  jest.clearAllMocks();
  params = {};
  features = [];
  featuresLoading = false;
  serveSpec();
});

// ---------------------------------------------------------------------------
// 1. The route binds {record}
// ---------------------------------------------------------------------------

describe('the record route binds its segment to {record}', () => {
  it('fetches the record the URL names, not the collection', async () => {
    features = [BLOCKS_FEATURE];
    params = { featureId: 'acme-widgets', recordId: '42' };
    mockApiClient.mockResolvedValue(stubResponse({ data: { name: 'Sprocket', status: 'Active' } }));

    render(<PluginRecordPage />);

    expect(await screen.findByText('Sprocket')).toBeInTheDocument();
    // The whole point, stated as a request: `/api/v1/acme/widgets/42`. An
    // unbound `{record}` would either fetch nothing or — the dangerous shape —
    // fetch `/api/v1/acme/widgets`, the COLLECTION, and render whatever came
    // back as "the record this page is about".
    expect(mockApiClient.mock.calls.map((call) => String(call[0]))).toEqual([
      '/api/v1/acme/widgets/42',
    ]);
  });

  it('percent-decodes the segment before binding it', async () => {
    features = [BLOCKS_FEATURE];
    params = { featureId: 'acme-widgets', recordId: 'a%20b' };
    mockApiClient.mockResolvedValue(stubResponse({ data: { name: 'Sprocket', status: 'Active' } }));

    render(<PluginRecordPage />);

    await screen.findByText('Sprocket');
    // Decoded on the way in, re-encoded by the renderer on the way out — so the
    // id the plugin sees is the id the link carried, and a space cannot become
    // a path separator.
    expect(String(mockApiClient.mock.calls[0][0])).toBe('/api/v1/acme/widgets/a%20b');
  });

  it('sends the back control to the feature, not into browser history', async () => {
    features = [BLOCKS_FEATURE];
    params = { featureId: 'acme-widgets', recordId: '42' };
    mockApiClient.mockResolvedValue(stubResponse({ data: { name: 'Sprocket', status: 'Active' } }));

    render(<PluginRecordPage />);
    await screen.findByText('Sprocket');

    await userEvent.click(screen.getByRole('button', { name: /Back to Widgets/i }));

    // push, not back(): a record reached from a pasted link has no history
    // entry to go back TO.
    expect(push).toHaveBeenCalledWith('/admin/x/acme-widgets');
  });

  it('refuses to name a record when the segment is empty', async () => {
    features = [BLOCKS_FEATURE];
    params = { featureId: 'acme-widgets', recordId: '' };

    render(<PluginRecordPage />);

    expect(await screen.findByText(/No record named/i)).toBeInTheDocument();
    // `''` would read downstream as "a record WAS named" and resolve the token
    // to the collection path.
    expect(mockApiClient).not.toHaveBeenCalled();
  });
});

// ---------------------------------------------------------------------------
// 2. A crud row's Edit action navigates
// ---------------------------------------------------------------------------

describe("a crud row's Edit action navigates to the record", () => {
  it('pushes the record address instead of opening a dialog', async () => {
    mockApiClient.mockResolvedValue(stubResponse({ data: THINGS }));

    render(<CrudScreen feature={CRUD_FEATURE} />);
    await screen.findByText('First thing');

    await userEvent.click(screen.getAllByRole('button', { name: 'Row actions' })[0]);
    await userEvent.click(await screen.findByRole('menuitem', { name: 'Edit' }));

    expect(push).toHaveBeenCalledWith('/admin/x/demo-things/1');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('still opens a dialog for CREATE, which has no record to address', async () => {
    mockApiClient.mockResolvedValue(stubResponse({ data: THINGS }));

    render(<CrudScreen feature={CRUD_FEATURE} />);
    await screen.findByText('First thing');

    await userEvent.click(screen.getByRole('button', { name: 'Create' }));

    expect(await screen.findByRole('dialog')).toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
  });
});

// ---------------------------------------------------------------------------
// 3. A hard reload on the record URL renders
// ---------------------------------------------------------------------------

describe('a hard reload on the record URL renders the record', () => {
  it('derives the crud record page from the URL alone', async () => {
    features = [CRUD_FEATURE];
    params = { featureId: 'demo-things', recordId: '2' };
    mockApiClient.mockResolvedValue(stubResponse({ data: THINGS }));

    // Nothing is carried over from a click: this mount IS the reload.
    render(<PluginRecordPage />);

    // The record names itself by its title field, and the form arrives seeded
    // with its current values.
    expect(await screen.findByRole('heading', { name: 'Second thing' })).toBeInTheDocument();
    await waitFor(() =>
      expect(document.querySelector('#crud-field-name')).toHaveValue('Second thing')
    );
  });

  it('saves to the record item route and stays on the page', async () => {
    features = [CRUD_FEATURE];
    params = { featureId: 'demo-things', recordId: '2' };
    mockApiClient.mockResolvedValue(stubResponse({ data: THINGS }));

    render(<PluginRecordPage />);
    await screen.findByRole('heading', { name: 'Second thing' });

    const input = document.querySelector('#crud-field-name') as HTMLInputElement;
    await userEvent.clear(input);
    await userEvent.type(input, 'Renamed thing');
    await userEvent.click(screen.getByTestId('crud-record-save'));

    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/demo/things/2',
        expect.objectContaining({ method: 'PATCH' })
      )
    );
    // A save is not a reason to leave a page the caller navigated to.
    expect(push).not.toHaveBeenCalled();
  });

  it('shows what the server holds after a save, not what it held before', async () => {
    features = [CRUD_FEATURE];
    params = { featureId: 'demo-things', recordId: '2' };
    let saved = false;
    mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
      if (init?.method === 'PATCH') {
        saved = true;
        return Promise.resolve(stubResponse({ data: { id: 2, name: 'Renamed by the server' } }));
      }
      return Promise.resolve(
        stubResponse({
          data: saved ? [THINGS[0], { id: 2, name: 'Renamed by the server' }] : THINGS,
        })
      );
    });

    render(<PluginRecordPage />);
    await screen.findByRole('heading', { name: 'Second thing' });

    const input = document.querySelector('#crud-field-name') as HTMLInputElement;
    await userEvent.clear(input);
    await userEvent.type(input, 'Renamed thing');
    await userEvent.click(screen.getByTestId('crud-record-save'));

    // The page reseeds from the REFETCHED record, not from the one it was
    // holding when the save was issued — the version the caller edited away
    // from. Reseeding on "a refetch was requested" makes a successful save look
    // like it reverted.
    expect(await screen.findByRole('heading', { name: 'Renamed by the server' })).toBeInTheDocument();
    await waitFor(() =>
      expect(document.querySelector('#crud-field-name')).toHaveValue('Renamed by the server')
    );
  });

  it('shows the record read-only, with the reason, when the caller may not edit it', async () => {
    features = [{ ...CRUD_FEATURE, capabilities: { canCreate: false, canEdit: false, canDelete: false } }];
    params = { featureId: 'demo-things', recordId: '2' };
    mockApiClient.mockResolvedValue(stubResponse({ data: THINGS }));

    render(<PluginRecordPage />);

    expect(await screen.findByTestId('crud-record-fields')).toBeInTheDocument();
    expect(screen.getByTestId('crud-record-readonly-notice')).toHaveTextContent(
      /do not hold the permission/i
    );
    // Read-only is a STATE, not a disabled form (#882).
    expect(document.querySelector('#crud-field-name')).toBeNull();
    expect(screen.queryByTestId('crud-record-save')).not.toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// 4. Unknown and unauthorised ids keep the address and name the cause
// ---------------------------------------------------------------------------

describe('an id that resolves to nothing', () => {
  it('says the record was not found and does not bounce back to the list', async () => {
    features = [CRUD_FEATURE];
    params = { featureId: 'demo-things', recordId: '999' };
    mockApiClient.mockResolvedValue(stubResponse({ data: THINGS }));

    render(<PluginRecordPage />);

    expect(await screen.findByText(/Record not found/i)).toBeInTheDocument();
    // The URL stays. A redirect here would make "deleted", "never existed" and
    // "not yours to see" the same silent bounce (#951).
    expect(push).not.toHaveBeenCalled();
  });

  it('names the permission when the collection refuses the caller', async () => {
    features = [CRUD_FEATURE];
    params = { featureId: 'demo-things', recordId: '2' };
    mockApiClient.mockResolvedValue(stubResponse({ error: 'Forbidden' }, 403));

    render(<PluginRecordPage />);

    expect(await screen.findByText(/Access denied/i)).toBeInTheDocument();
    expect(screen.getByText(/demo:view/)).toBeInTheDocument();
  });

  it('does not conclude "not found" from a page walk it could not finish', async () => {
    features = [CRUD_FEATURE];
    params = { featureId: 'demo-things', recordId: '26' };
    // Page 1 of 2, and the second page never arrives. Row 26 of a paginated
    // resource is not a missing row (#867) — reporting it as one is how a
    // truncated list reads as deleted data.
    mockApiClient.mockImplementation((url: string) =>
      Promise.resolve(
        url.includes('page=')
          ? stubResponse({ error: 'Boom' }, 500)
          : stubResponse({
              data: THINGS,
              pagination: { page: 1, perPage: 25, total: 30, totalPages: 2 },
            })
      )
    );

    render(<PluginRecordPage />);

    expect(await screen.findByText(/could not be loaded/i)).toBeInTheDocument();
    expect(screen.queryByText(/Record not found/i)).not.toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// 5. The feature itself
// ---------------------------------------------------------------------------

describe('the feature behind the record', () => {
  it('renders the same unavailable card the feature page does for an unknown id', async () => {
    features = [];
    params = { featureId: 'nope', recordId: '1' };

    render(<PluginRecordPage />);

    // The server already filtered by permission, so "not in the list" covers
    // both an unknown feature and one this caller may not use.
    expect(await screen.findByText(/does not exist or you do not have permission/i))
      .toBeInTheDocument();
  });

  it('says so when a feature kind describes no records', async () => {
    features = [
      {
        ...CRUD_FEATURE,
        id: 'demo-tool',
        screen: 'embed',
        resource: null,
        embed: { path: '/api/v1/demo/tool' },
      },
    ];
    params = { featureId: 'demo-tool', recordId: '1' };

    render(<PluginRecordPage />);

    expect(await screen.findByText(/No record page/i)).toBeInTheDocument();
  });
});
