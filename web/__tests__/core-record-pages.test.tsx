/**
 * #882/#884: the CORE record routes — `/admin/tenants/[id]`,
 * `/admin/languages/[id]` and `/admin/tag-groups/[id]`.
 *
 * Every one of these is driven through its ROUTE rather than through the screen
 * component, and every mount seeds nothing but the URL segment. That is the
 * whole claim a record page makes: a pasted link, a refresh and the back button
 * all deliver the same page, because the id comes from the address and not from
 * the click that would otherwise have opened a dialog. A test that rendered the
 * screen with `tenantId={7}` would pass just as happily against a modal.
 *
 * What each suite pins, and why it is a decision rather than a detail:
 *
 *  1. **The address IS the input.** `useParams` is the only thing that says
 *     which record this is, and the request that follows is asserted — a page
 *     that renders while fetching the wrong thing looks identical to one that
 *     does not.
 *  2. **Read-only is a STATE, and it names the gate that produced it.** Each of
 *     these records has more than one rule, in a deliberate order: a caller who
 *     fails both is told the more fundamental one, once. This is what the modals
 *     could not do — they offered an editable form and let Save 403.
 *  3. **An unknown id keeps its URL.** "Deleted", "never existed" and "not
 *     yours to see" are different answers, and a redirect to the list renders
 *     all three as the same silent event (#951).
 *  4. **A truncated read is a LOAD FAILURE, never "not found"** — the tenant
 *     record is found by walking a paginated collection, and row 26 of a
 *     paginated resource is not a deleted row (#867).
 *  5. **The gaps #884 found are actually closed.** A language's NAME and a tag
 *     group's KEY are asserted to reach the wire, because until these pages
 *     existed neither was editable anywhere in the product even though the API
 *     accepted both.
 *  6. **An ungranted side panel is ABSENT, not an error.** Billing and
 *     entitlements sit behind permissions the operator administering workspaces
 *     may simply not hold.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// ---------------------------------------------------------------------------
// Provider seams. The routes own exactly these, so the tests inject them and
// drive the real screens underneath.
// ---------------------------------------------------------------------------

const push = jest.fn();
let params: Record<string, string> = {};
jest.mock('next/navigation', () => ({
  useParams: () => params,
  useRouter: () => ({ push }),
}));

let authUser: { tenant_id: number } | null = { tenant_id: 0 };
const rawApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ user: authUser, apiClient: (...args: unknown[]) => rawApiClient(...args) }),
}));
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => rawApiClient(...args),
}));

const apiGet = jest.fn();
const apiPatch = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => apiGet(...args),
    PATCH: (...args: unknown[]) => apiPatch(...args),
    POST: jest.fn(),
    DELETE: jest.fn(),
  },
}));

const hasPermission = jest.fn<boolean, [string]>();
let capabilitiesLoading = false;
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ hasPermission, loading: capabilitiesLoading, permissions: [] }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));

import TenantRecordPage from '@/app/(protected)/admin/tenants/[id]/page';
import LanguageRecordPage from '@/app/(protected)/admin/languages/[id]/page';
import TagGroupRecordPage from '@/app/(protected)/admin/tag-groups/[id]/page';
import { TENANTS_WRITE, LANGUAGES_MANAGE, TAGS_MANAGE } from '@/lib/capabilities';

// Radix (select, switch) needs Pointer Capture + scrollIntoView, absent in jsdom.
beforeAll(() => {
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

/** A raw-`apiClient` response, as `fetchAllPages` and the PATCH paths read it. */
function rawResponse(body: unknown, status = 200): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

/** An openapi-fetch result: `{ data, error, response }`, never thrown. */
function typedOk<T>(data: T) {
  return { data, error: undefined, response: { status: 200, ok: true } };
}

function typedFail(status: number, error: unknown = { error: 'nope' }) {
  return { data: undefined, error, response: { status, ok: false } };
}

function grant(...slugs: string[]) {
  hasPermission.mockImplementation((slug: string) => slugs.includes(slug));
}

beforeEach(() => {
  jest.clearAllMocks();
  params = {};
  authUser = { tenant_id: 0 };
  capabilitiesLoading = false;
  grant();
});

// ===========================================================================
// Tenants
// ===========================================================================

const TENANT = {
  id: 7,
  name: 'Acme',
  slug: 'acme',
  userCount: 12,
  createdAt: '2026-02-03T09:00:00Z',
};

/**
 * The tenant list as the collection walk sees it.
 *
 * `pagination` matters: without an envelope the walk concludes after one page,
 * so a fixture that omitted it would silently stop testing the walk.
 */
function tenantPage(rows: unknown[], total = rows.length) {
  return { data: rows, pagination: { page: 1, perPage: 100, total, totalPages: 1 } };
}

/** Wire up the three requests a tenant record page makes. */
function mockTenantBackend({
  list = rawResponse(tenantPage([TENANT])),
  subscription = typedOk({
    data: {
      tenant_id: 7,
      status: 'active',
      plan: { id: 2, name: 'Growth' },
      effective_enforcement_mode: 'warn',
      current_period_end: '2026-09-01T00:00:00Z',
    },
  }),
  entitlements = typedOk({
    data: {
      tenant_id: 7,
      effective: { 'storage.gb': 50, 'sso.enabled': true },
      overridden: ['storage.gb'],
      registry: {
        'storage.gb': { type: 'int', default: '10', description: 'Storage in gigabytes' },
      },
    },
  }),
}: {
  list?: Response;
  subscription?: unknown;
  entitlements?: unknown;
} = {}) {
  rawApiClient.mockImplementation((url: string) =>
    Promise.resolve(url.startsWith('/api/v1/tenants?') ? list : rawResponse({}, 404))
  );
  apiGet.mockImplementation((path: string) => {
    if (path === '/api/v1/tenants/{id}/subscription') return Promise.resolve(subscription);
    if (path === '/api/v1/tenants/{id}/entitlements') return Promise.resolve(entitlements);
    return Promise.resolve(typedFail(404));
  });
}

describe('/admin/tenants/[id] — the workspace record', () => {
  it('loads the workspace named by the URL and nothing else', async () => {
    params = { id: '7' };
    grant(TENANTS_WRITE);
    mockTenantBackend({ list: rawResponse(tenantPage([{ ...TENANT, id: 4, name: 'Other' }, TENANT], 2)) });

    render(<TenantRecordPage />);

    expect(await screen.findByTestId('tenant-record')).toBeInTheDocument();
    // The id came from the address, so the page must be about THAT row and not
    // the first one the collection happened to return.
    expect(screen.getByRole('heading', { name: 'Acme' })).toBeInTheDocument();
    expect(screen.getByTestId('tenant-record-stat-users')).toHaveTextContent('12');
    expect(screen.getByTestId('tenant-record-stat-slug')).toHaveTextContent('acme');
  });

  it('saves the name and slug the operator typed', async () => {
    params = { id: '7' };
    grant(TENANTS_WRITE);
    mockTenantBackend();
    const user = userEvent.setup();

    render(<TenantRecordPage />);
    await screen.findByTestId('tenant-record');

    // Save is inert until something actually changed — a Save button that is
    // always live invites a no-op PATCH on every visit.
    expect(screen.getByTestId('tenant-record-save')).toBeDisabled();

    await user.clear(screen.getByTestId('tenant-record-name'));
    await user.type(screen.getByTestId('tenant-record-name'), 'Acme Widgets');
    await user.click(screen.getByTestId('tenant-record-save'));

    await waitFor(() =>
      expect(rawApiClient).toHaveBeenCalledWith(
        '/api/v1/tenants/7',
        expect.objectContaining({ method: 'PATCH' })
      )
    );
    const patch = rawApiClient.mock.calls.find((call) => call[1]?.method === 'PATCH');
    expect(JSON.parse(patch![1].body)).toEqual({ name: 'Acme Widgets', slug: 'acme' });
  });

  it('refuses a slug the backend would accept but no URL should carry', async () => {
    params = { id: '7' };
    grant(TENANTS_WRITE);
    mockTenantBackend();
    const user = userEvent.setup();

    render(<TenantRecordPage />);
    await screen.findByTestId('tenant-record');

    await user.clear(screen.getByTestId('tenant-record-slug'));
    await user.type(screen.getByTestId('tenant-record-slug'), 'Invalid Slug!');
    await user.click(screen.getByTestId('tenant-record-save'));

    expect(await screen.findByTestId('tenant-record-slug-error')).toBeInTheDocument();
    expect(rawApiClient.mock.calls.some((call) => call[1]?.method === 'PATCH')).toBe(false);
  });

  it('names the CAPABILITY gate first when the caller fails both', async () => {
    params = { id: '7' };
    // No tenants:write, and standing in a tenant that is neither 0 nor 7.
    authUser = { tenant_id: 3 };
    mockTenantBackend();

    render(<TenantRecordPage />);

    const notice = await screen.findByTestId('tenant-record-readonly-notice');
    expect(notice).toHaveTextContent(/permission to edit workspaces/i);
    // One notice, and only the first refusal: two sentences saying overlapping
    // things is one sentence too many.
    expect(notice).not.toHaveTextContent(/system workspace/i);
    expect(screen.queryByTestId('tenant-record-save')).not.toBeInTheDocument();
  });

  it('explains the CROSS-TENANT rule to a caller who does hold the capability', async () => {
    params = { id: '7' };
    authUser = { tenant_id: 3 };
    grant(TENANTS_WRITE);
    mockTenantBackend();

    render(<TenantRecordPage />);

    expect(await screen.findByTestId('tenant-record-readonly-notice')).toHaveTextContent(
      /only an operator in the system workspace/i
    );
    // Read-only is a distinct RENDERING, not a disabled form: there is no input
    // at all, and the values are STATED. The slug is on the page twice on
    // purpose — once in the stat strip, once as a stated field — which is why
    // this counts rather than expecting one.
    expect(screen.queryByTestId('tenant-record-name')).not.toBeInTheDocument();
    expect(screen.queryByTestId('tenant-record-slug')).not.toBeInTheDocument();
    expect(screen.getAllByText('acme').length).toBeGreaterThan(0);
  });

  it('keeps the URL and names the cause when no such workspace is visible', async () => {
    params = { id: '99' };
    grant(TENANTS_WRITE);
    mockTenantBackend();

    render(<TenantRecordPage />);

    expect(await screen.findByTestId('tenant-record-missing')).toBeInTheDocument();
    // No bounce: the operator stays where the link put them.
    expect(push).not.toHaveBeenCalled();
  });

  it('reports a TRUNCATED walk as a load failure, not as a missing workspace', async () => {
    params = { id: '99' };
    grant(TENANTS_WRITE);
    // The envelope says two rows exist; only one arrived. The record may well be
    // the one that did not — concluding "deleted" here would be a lie.
    mockTenantBackend({ list: rawResponse(tenantPage([TENANT], 2)) });

    render(<TenantRecordPage />);

    expect(await screen.findByTestId('tenant-record-error')).toBeInTheDocument();
    expect(screen.queryByTestId('tenant-record-missing')).not.toBeInTheDocument();
  });

  it('shows the plan and the entitlement OVERRIDES, which have never had a UI', async () => {
    params = { id: '7' };
    grant(TENANTS_WRITE);
    mockTenantBackend();

    render(<TenantRecordPage />);
    await screen.findByTestId('tenant-record');

    const billing = await screen.findByTestId('tenant-record-billing');
    expect(billing).toHaveTextContent('Growth');
    expect(billing).toHaveTextContent('warn');

    const entitlements = screen.getByTestId('tenant-record-entitlements');
    // Only the overridden key: the effective set also carries `sso.enabled`,
    // which nobody changed for this workspace and which would bury the one that
    // was changed.
    expect(entitlements).toHaveTextContent('storage.gb');
    expect(entitlements).toHaveTextContent('50');
    expect(entitlements).not.toHaveTextContent('sso.enabled');
  });

  it('leaves an ungranted billing panel out of the document entirely', async () => {
    params = { id: '7' };
    grant(TENANTS_WRITE);
    mockTenantBackend({ subscription: typedFail(403) });

    render(<TenantRecordPage />);
    await screen.findByTestId('tenant-record');

    // Absent, not an error box about a capability the operator withheld.
    expect(screen.queryByTestId('tenant-record-billing')).not.toBeInTheDocument();
    expect(screen.getByTestId('tenant-record-entitlements')).toBeInTheDocument();
  });

  it('never fetches for a segment that is not a workspace id', async () => {
    params = { id: 'abc' };
    grant(TENANTS_WRITE);
    mockTenantBackend();

    render(<TenantRecordPage />);

    expect(rawApiClient).not.toHaveBeenCalled();
  });
});

// ===========================================================================
// Languages — #884's clearest gap
// ===========================================================================

const LANGUAGES = [
  {
    id: 1,
    code: 'en',
    name: 'English',
    direction: 'ltr',
    enabled: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
  },
  {
    id: 2,
    code: 'ar',
    name: 'Arabic',
    direction: 'rtl',
    enabled: false,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
  },
];

function mockLanguageBackend({
  list = typedOk({ data: LANGUAGES }),
  coverage = typedOk({
    data: {
      source_language_code: 'en',
      languages: [
        {
          language_code: 'ar',
          name: 'Arabic',
          total: 100,
          translated: 60,
          missing: 40,
          domains: [{ domain: 'admin', total: 60, translated: 30, missing: 30 }],
        },
      ],
    },
  }),
}: { list?: unknown; coverage?: unknown } = {}) {
  apiGet.mockImplementation((path: string) => {
    if (path === '/api/v1/admin/languages') return Promise.resolve(list);
    if (path === '/api/v1/translations/coverage') return Promise.resolve(coverage);
    return Promise.resolve(typedFail(404));
  });
  apiPatch.mockImplementation((_path: string, options: { body: Record<string, unknown> }) =>
    Promise.resolve(typedOk({ data: { ...LANGUAGES[1], ...options.body } }))
  );
}

describe('/admin/languages/[id] — the record that closes #884’s clearest gap', () => {
  it('RENAMES a language, which was impossible anywhere in the product before', async () => {
    params = { id: '2' };
    grant(LANGUAGES_MANAGE);
    mockLanguageBackend();
    const user = userEvent.setup();

    render(<LanguageRecordPage />);
    await screen.findByTestId('language-record');

    await user.clear(screen.getByTestId('language-record-name'));
    await user.type(screen.getByTestId('language-record-name'), 'العربية');
    await user.click(screen.getByTestId('language-record-save'));

    await waitFor(() =>
      expect(apiPatch).toHaveBeenCalledWith(
        '/api/v1/languages/{id}',
        expect.objectContaining({
          params: { path: { id: 2 } },
          body: { name: 'العربية', direction: 'rtl', enabled: false },
        })
      )
    );
  });

  it('states the code with its reason rather than rendering a greyed-out box', async () => {
    params = { id: '2' };
    grant(LANGUAGES_MANAGE);
    mockLanguageBackend();

    render(<LanguageRecordPage />);
    await screen.findByTestId('language-record');

    // The code is TEXT — a disabled input would invite the reader to hunt for
    // the permission that ungreys it, and no permission does.
    expect(screen.getByTestId('language-record-code')).toHaveTextContent('ar');
    expect(screen.getByTestId('language-record-code').tagName).not.toBe('INPUT');
  });

  it('badges a switched-off language and reports coverage per domain', async () => {
    params = { id: '2' };
    grant(LANGUAGES_MANAGE);
    mockLanguageBackend();

    render(<LanguageRecordPage />);
    await screen.findByTestId('language-record');

    expect(screen.getByTestId('language-record-badge-disabled')).toBeInTheDocument();
    expect(await screen.findByTestId('language-record-stat-coverage')).toHaveTextContent(
      '60 of 100'
    );
    expect(screen.getByTestId('language-record-coverage')).toHaveTextContent('admin');
  });

  it('says why coverage is silent for a switched-off language rather than reporting zero', async () => {
    params = { id: '2' };
    grant(LANGUAGES_MANAGE);
    // The report covers ENABLED languages only, so Arabic is simply absent.
    mockLanguageBackend({
      coverage: typedOk({ data: { source_language_code: 'en', languages: [] } }),
    });

    render(<LanguageRecordPage />);
    await screen.findByTestId('language-record');

    expect(await screen.findByTestId('language-record-coverage')).toHaveTextContent(
      /switched on/i
    );
  });

  it('leaves the coverage panel out entirely without translations:manage', async () => {
    params = { id: '2' };
    grant(LANGUAGES_MANAGE);
    mockLanguageBackend({ coverage: typedFail(403) });

    render(<LanguageRecordPage />);
    await screen.findByTestId('language-record');

    expect(screen.queryByTestId('language-record-coverage')).not.toBeInTheDocument();
  });

  it('is READABLE outside the system tenant, and says which rule holds', async () => {
    params = { id: '2' };
    authUser = { tenant_id: 5 };
    grant(LANGUAGES_MANAGE);
    mockLanguageBackend();

    render(<LanguageRecordPage />);

    // The list page refuses this caller outright; a record does not need to —
    // reading one is harmless, and the page states the rule instead.
    expect(await screen.findByTestId('language-record')).toBeInTheDocument();
    expect(screen.getByTestId('language-record-readonly-notice')).toHaveTextContent(
      /only the system workspace/i
    );
    expect(screen.queryByTestId('language-record-name')).not.toBeInTheDocument();
  });

  it('keeps the URL and names the cause for an id the catalogue does not hold', async () => {
    params = { id: '404' };
    grant(LANGUAGES_MANAGE);
    mockLanguageBackend();

    render(<LanguageRecordPage />);

    expect(await screen.findByTestId('language-record-missing')).toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
  });
});

// ===========================================================================
// Tag groups
// ===========================================================================

const TAG_GROUP = {
  id: 3,
  tenant_id: 1,
  key: 'priority',
  display_name: { ar: 'الأولوية', en: 'Priority' },
  created_at: '2026-03-01T00:00:00Z',
  updated_at: '2026-03-01T00:00:00Z',
};

const TAGS = [
  { id: 10, tenant_id: 1, group_id: 3, name: 'high', created_at: '', updated_at: '' },
  { id: 11, tenant_id: 1, group_id: 3, name: 'low', created_at: '', updated_at: '' },
];

function mockTagGroupBackend({
  group = typedOk({ data: TAG_GROUP }),
  tags = typedOk({ data: TAGS }),
}: { group?: unknown; tags?: unknown } = {}) {
  apiGet.mockImplementation((path: string) => {
    if (path === '/api/v1/tag-groups/{id}') return Promise.resolve(group);
    if (path === '/api/v1/tags') return Promise.resolve(tags);
    return Promise.resolve(typedFail(404));
  });
  apiPatch.mockImplementation((_path: string, options: { body: Record<string, unknown> }) =>
    Promise.resolve(typedOk({ data: { ...TAG_GROUP, ...options.body } }))
  );
}

describe('/admin/tag-groups/[id] — the container and what is in it', () => {
  it('lists the tags in THIS group, asked for by group', async () => {
    params = { id: '3' };
    grant(TAGS_MANAGE);
    mockTagGroupBackend();

    render(<TagGroupRecordPage />);
    await screen.findByTestId('tag-group-record');

    // Asserted on the REQUEST: a panel rendering the whole tenant's tags would
    // look identical on a one-group fixture.
    expect(apiGet).toHaveBeenCalledWith('/api/v1/tags', {
      params: { query: { group_id: 3 } },
    });
    const panel = await screen.findByTestId('tag-group-record-tags');
    expect(panel).toHaveTextContent('high');
    expect(panel).toHaveTextContent('low');
    expect(screen.getByTestId('tag-group-record-stat-tags')).toHaveTextContent('2');
  });

  it('RENAMES the key — the field the dialog only ever offered while creating', async () => {
    params = { id: '3' };
    grant(TAGS_MANAGE);
    mockTagGroupBackend();
    const user = userEvent.setup();

    render(<TagGroupRecordPage />);
    await screen.findByTestId('tag-group-record');

    await user.clear(screen.getByTestId('tag-group-record-key'));
    await user.type(screen.getByTestId('tag-group-record-key'), 'severity');
    await user.click(screen.getByTestId('tag-group-record-save'));

    await waitFor(() =>
      expect(apiPatch).toHaveBeenCalledWith(
        '/api/v1/tag-groups/{id}',
        expect.objectContaining({
          params: { path: { id: 3 } },
          body: { key: 'severity', display_name: { ar: 'الأولوية', en: 'Priority' } },
        })
      )
    );
  });

  it('refuses a key the handler would reject, before the request', async () => {
    params = { id: '3' };
    grant(TAGS_MANAGE);
    mockTagGroupBackend();
    const user = userEvent.setup();

    render(<TagGroupRecordPage />);
    await screen.findByTestId('tag-group-record');

    await user.clear(screen.getByTestId('tag-group-record-key'));
    await user.type(screen.getByTestId('tag-group-record-key'), 'not a key');
    await user.click(screen.getByTestId('tag-group-record-save'));

    expect(await screen.findByTestId('tag-group-record-key-error')).toBeInTheDocument();
    expect(apiPatch).not.toHaveBeenCalled();
  });

  it('is readable without tags:manage, and says the record is read-only', async () => {
    params = { id: '3' };
    grant();
    mockTagGroupBackend();

    render(<TagGroupRecordPage />);

    expect(await screen.findByTestId('tag-group-record')).toBeInTheDocument();
    expect(screen.getByTestId('tag-group-record-readonly-notice')).toHaveTextContent(
      /permission to manage tags/i
    );
    expect(screen.queryByTestId('tag-group-record-key')).not.toBeInTheDocument();
  });

  it("keeps the URL on the server's own 404 rather than bouncing to the list", async () => {
    params = { id: '3' };
    grant(TAGS_MANAGE);
    mockTagGroupBackend({ group: typedFail(404) });

    render(<TagGroupRecordPage />);

    expect(await screen.findByTestId('tag-group-record-missing')).toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
  });
});

// ===========================================================================
// The shared route contract
// ===========================================================================

describe('every core record route', () => {
  it('waits for fail-closed capabilities before it can claim you lack them', async () => {
    params = { id: '7' };
    capabilitiesLoading = true;
    grant();
    mockTenantBackend();

    render(<TenantRecordPage />);

    // While `hasPermission` still answers false for everything, "you don't have
    // permission" is not a slow truth — it is a false sentence on screen.
    expect(screen.getByTestId('record-loading')).toBeInTheDocument();
    expect(screen.queryByTestId('tenant-record-readonly-notice')).not.toBeInTheDocument();
  });

  it('sends the back control to the list rather than into browser history', async () => {
    params = { id: '3' };
    grant(TAGS_MANAGE);
    mockTagGroupBackend();
    const user = userEvent.setup();

    render(<TagGroupRecordPage />);
    await screen.findByTestId('tag-group-record');

    await user.click(screen.getByRole('button', { name: /back to tag groups/i }));

    // push, not back(): a record reached from a pasted link has no history entry
    // to go back TO.
    expect(push).toHaveBeenCalledWith('/admin/tag-groups');
  });
});
