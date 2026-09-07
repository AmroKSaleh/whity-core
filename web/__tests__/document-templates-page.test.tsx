import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * The templates-and-blocks governance screen.
 *
 * WHAT THESE ARE WRITTEN TO CATCH
 * -------------------------------
 * Not "does the page render" — a page renders over a failed fetch. Each case
 * below is an assertion that would FAIL if the governance behaviour broke while
 * everything still painted:
 *
 *  - the scoping columns show the row's actual placement and tag, so a screen
 *    that dropped to `SavedTemplate` (which has neither) fails here;
 *  - the block usage count is the SERVER's unfiltered total and the hidden
 *    count is surfaced, so a "simplification" to counting locally-visible
 *    templates fails here — that is the change that would silently understate an
 *    edit's blast radius;
 *  - delete is refused while a block is in use, BEFORE the request goes out, so
 *    a regression to "let the 409 explain it" fails here;
 *  - publish controls are gated on documents:publish, and NOT on
 *    documents:write, so the two secretaries' screens differ from the dean's.
 */

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 10, email: 'a@b.c', role: '', tenant_id: 1 } }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));

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

const STARTER = {
  id: 1,
  name: 'Invoice',
  scope: 'system',
  required_permission: null,
  owner_ou_id: null,
  is_system: true,
  created_by: null,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
  data: { version: 2, pages: [] },
};

const PLACED_TEMPLATE = {
  id: 6,
  name: 'Demo civil works order',
  scope: 'tenant',
  required_permission: null,
  owner_ou_id: 2,
  is_system: false,
  created_by: 10,
  created_at: '2026-01-02T00:00:00Z',
  updated_at: '2026-01-02T00:00:00Z',
  // Instances block 4 — so the "Blocks used" column must say 1.
  data: {
    version: 2,
    pages: [{ id: 'p1', elements: [{ id: 'e1', type: 'blockInstance', blockId: '4' }] }],
  },
};

const TAGGED_TEMPLATE = {
  id: 8,
  name: 'Demo civil contract',
  scope: 'tenant',
  required_permission: 'documents:publish',
  owner_ou_id: 2,
  is_system: false,
  created_by: 10,
  created_at: '2026-01-03T00:00:00Z',
  updated_at: '2026-01-03T00:00:00Z',
  data: { version: 2, pages: [] },
};

const BLOCK_UNUSED = {
  id: 1,
  name: 'Company header',
  scope: 'system',
  required_permission: null,
  owner_ou_id: null,
  is_system: true,
  created_by: null,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
  data: [{ id: 'e1', type: 'text' }],
};

const BLOCK_USED = {
  id: 4,
  name: 'Demo civil site-safety notice',
  scope: 'tenant',
  required_permission: null,
  owner_ou_id: 2,
  is_system: false,
  created_by: 10,
  created_at: '2026-01-02T00:00:00Z',
  updated_at: '2026-01-02T00:00:00Z',
  data: [{ id: 'e1', type: 'text' }],
};

/**
 * Block 4 is referenced by THREE templates, of which the caller can see ONE.
 * That asymmetry is the fixture's whole purpose: a screen counting the templates
 * it holds would say 1, and 1 is the number that gets documents quietly
 * rewritten.
 */
const USAGE_BLOCK_4 = {
  block_id: 4,
  total: 3,
  hidden: 2,
  templates: [
    {
      id: 6,
      name: 'Demo civil works order',
      scope: 'tenant',
      required_permission: null,
      owner_ou_id: 2,
      is_system: false,
      updated_at: '2026-01-02T00:00:00Z',
    },
  ],
};

interface RouteOptions {
  templates?: unknown[];
  blocks?: unknown[];
  ousStatus?: number;
  permissionsStatus?: number;
  /** Override the usage answer for block 4 (#1186: nesting). */
  usage?: unknown;
}

function routeApi(options: RouteOptions = {}) {
  const templates = options.templates ?? [STARTER, PLACED_TEMPLATE, TAGGED_TEMPLATE];
  const blocks = options.blocks ?? [BLOCK_UNUSED, BLOCK_USED];

  mockApiClient.mockImplementation((url: string) => {
    if (url === '/api/v1/document-templates') return jsonResponse(200, { data: templates });
    if (url === '/api/v1/document-blocks') return jsonResponse(200, { data: blocks });
    if (url === '/api/v1/document-blocks/4/usage') return jsonResponse(200, { data: options.usage ?? USAGE_BLOCK_4 });
    if (url === '/api/v1/document-blocks/1/usage') {
      return jsonResponse(200, { data: { block_id: 1, total: 0, hidden: 0, templates: [] } });
    }
    if (url.startsWith('/api/v1/ous')) {
      const status = options.ousStatus ?? 200;
      return status === 200
        ? jsonResponse(200, {
            data: [
              { id: 1, name: 'Faculty of Engineering' },
              { id: 2, name: 'Civil Engineering' },
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
    // Writes against a single row. Answered 200 so the success path is exercised
    // end to end — a 404 here would make every write test pass its
    // "was the request made" assertion while the screen showed an error, which
    // is precisely the kind of half-green test this suite exists to avoid.
    if (/^\/api\/v1\/document-(templates|blocks)\/\d+$/.test(url)) {
      return jsonResponse(200, { data: {} });
    }
    return jsonResponse(404, {});
  });
}

/** Open the Blocks tab (Radix tabs need real pointer events). */
async function openBlocksTab(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('tab', { name: 'Blocks' }));
}

/**
 * The open dialog. Queries are scoped to it because the page's own description
 * paragraph contains the same phrases as the dialog titles by design — the
 * screen explains itself in both places — so an unscoped text query matches two
 * nodes and fails for the wrong reason.
 */
function dialog() {
  return within(screen.getByRole('dialog'));
}

/** Open one row's actions menu by its accessible name. */
async function openRowMenu(user: ReturnType<typeof userEvent.setup>, name: string) {
  await user.click(screen.getByRole('button', { name: `Actions for ${name}` }));
}

beforeEach(() => {
  jest.clearAllMocks();
  capabilityList.length = 0;
  capabilityList.push('documents:read', 'documents:write', 'documents:publish');
  hasPermission.mockImplementation((slug) => capabilityList.includes(slug));
  routeApi();
});

describe('scope is visible', () => {
  it('shows each template’s tier, unit placement and permission tag', async () => {
    render(<DocumentTemplatesPage />);

    await waitFor(() => expect(screen.getByText('Demo civil works order')).toBeInTheDocument());

    // The unit NAME, resolved from /api/v1/ous — not the raw id.
    expect(screen.getAllByText('Civil Engineering').length).toBeGreaterThan(0);
    // The permission tag as its slug. Resolved by name; never an id.
    expect(screen.getByText('documents:publish')).toBeInTheDocument();
    // An unplaced starter says so positively rather than reading as unknown.
    expect(screen.getAllByText('Not filed at a unit').length).toBeGreaterThan(0);
    // The tier badge.
    expect(screen.getAllByText('Tenant-wide').length).toBeGreaterThan(0);
  });

  it('falls back to the bare unit id, and says why, when units are unreadable', async () => {
    // The demo roles genuinely lack ous:read, so this is an ordinary state.
    routeApi({ ousStatus: 403 });
    render(<DocumentTemplatesPage />);

    await waitFor(() => expect(screen.getByText('Demo civil works order')).toBeInTheDocument());

    expect(screen.getAllByText('#2').length).toBeGreaterThan(0);
    expect(screen.getByText(/Listing units needs the ous:read permission/)).toBeInTheDocument();
    expect(screen.queryByText('Civil Engineering')).not.toBeInTheDocument();
  });

  it('counts the blocks a template instances, from the template’s own data', async () => {
    render(<DocumentTemplatesPage />);

    await waitFor(() => expect(screen.getByText('Demo civil works order')).toBeInTheDocument());

    const row = screen.getByText('Demo civil works order').closest('tr');
    expect(row).not.toBeNull();
    // Instances exactly one block, and that block is visible, so nothing is
    // unresolved.
    expect(row).toHaveTextContent('1');
    expect(row?.textContent).not.toContain('unresolved');
  });

  it('flags a pointer that resolves to no visible block WITHOUT calling it broken', async () => {
    // A blockId with no matching visible block may be deleted OR merely
    // invisible. Those look the same from the client, so the wording must not
    // pick one.
    routeApi({
      templates: [
        {
          ...PLACED_TEMPLATE,
          data: {
            version: 2,
            pages: [{ id: 'p1', elements: [{ id: 'e1', type: 'blockInstance', blockId: '999' }] }],
          },
        },
      ],
    });
    render(<DocumentTemplatesPage />);

    await waitFor(() => expect(screen.getByText('Demo civil works order')).toBeInTheDocument());
    expect(screen.getByText('1 unresolved')).toBeInTheDocument();
  });
});

describe('what uses this block', () => {
  it('shows the server’s unfiltered total, not the number of templates it can see', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByRole('tab', { name: 'Blocks' })).toBeInTheDocument());
    await openBlocksTab(user);

    await waitFor(() =>
      expect(screen.getByText('Demo civil site-safety notice')).toBeInTheDocument()
    );

    // THE assertion. The caller holds one referencing template; the server says
    // three. Three is the number that must be on screen.
    expect(screen.getByText('3 uses')).toBeInTheDocument();
    expect(screen.queryByText('1 templates')).not.toBeInTheDocument();
    expect(screen.getByText('2 you cannot see')).toBeInTheDocument();
  });

  it('says "Nothing" for an unreferenced block rather than leaving it blank', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByRole('tab', { name: 'Blocks' })).toBeInTheDocument());
    await openBlocksTab(user);

    await waitFor(() => expect(screen.getByText('Company header')).toBeInTheDocument());
    const row = screen.getByText('Company header').closest('tr');
    expect(row).toHaveTextContent('Nothing');
  });

  it('reports an unreadable usage count as unknown, never as zero', async () => {
    mockApiClient.mockImplementation((url: string) => {
      if (url === '/api/v1/document-templates') return jsonResponse(200, { data: [] });
      if (url === '/api/v1/document-blocks') return jsonResponse(200, { data: [BLOCK_USED] });
      if (url === '/api/v1/document-blocks/4/usage') return jsonResponse(500, {});
      if (url.startsWith('/api/v1/ous')) {
        return jsonResponse(200, {
          data: [],
          pagination: { page: 1, perPage: 100, total: 0, totalPages: 1 },
        });
      }
      return jsonResponse(404, {});
    });

    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByRole('tab', { name: 'Blocks' })).toBeInTheDocument());
    await openBlocksTab(user);

    await waitFor(() =>
      expect(screen.getByText('Demo civil site-safety notice')).toBeInTheDocument()
    );
    // "Unknown", and NOT "Nothing" — a blank is not a zero, and a zero is what
    // would license the delete.
    expect(screen.getByText('Unknown')).toBeInTheDocument();
    expect(screen.queryByText('Nothing')).not.toBeInTheDocument();
  });

  it('names the referencing templates it may name, and counts the rest', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByRole('tab', { name: 'Blocks' })).toBeInTheDocument());
    await openBlocksTab(user);
    await waitFor(() => expect(screen.getByText('3 uses')).toBeInTheDocument());

    await user.click(screen.getByText('3 uses'));

    await waitFor(() =>
      expect(screen.getByText(/3 things in this tenant use this block/)).toBeInTheDocument()
    );
    expect(screen.getByText('2 are not listed')).toBeInTheDocument();
    // The one it may name is named; the two it may not are not.
    expect(screen.getAllByText('Demo civil works order').length).toBeGreaterThan(0);
  });
});

describe('destructive actions ask first', () => {
  it('refuses to delete a block still in use, before any request is sent', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByRole('tab', { name: 'Blocks' })).toBeInTheDocument());
    await openBlocksTab(user);
    await waitFor(() =>
      expect(screen.getByText('Demo civil site-safety notice')).toBeInTheDocument()
    );

    await openRowMenu(user, 'Demo civil site-safety notice');
    await user.click(screen.getByRole('menuitem', { name: 'Delete…' }));

    await waitFor(() => expect(screen.getByText('Still used in 3 places')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: 'Delete' })).toBeDisabled();

    // And nothing was attempted: the 409 is a backstop, not the explanation.
    expect(
      mockApiClient.mock.calls.filter(
        (call) => (call[1] as { method?: string } | undefined)?.method === 'DELETE'
      )
    ).toHaveLength(0);
  });

  it('allows deleting an unused block', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByRole('tab', { name: 'Blocks' })).toBeInTheDocument());
    await openBlocksTab(user);
    await waitFor(() => expect(screen.getByText('Company header')).toBeInTheDocument());

    await openRowMenu(user, 'Company header');
    await user.click(screen.getByRole('menuitem', { name: 'Delete…' }));

    await waitFor(() => expect(screen.getByRole('button', { name: 'Delete' })).toBeEnabled());
  });

  it('tells you issued documents survive a template delete', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Demo civil works order')).toBeInTheDocument());

    await openRowMenu(user, 'Demo civil works order');
    await user.click(screen.getByRole('menuitem', { name: 'Delete…' }));

    await waitFor(() =>
      expect(screen.getByText('Documents already issued from it are kept')).toBeInTheDocument()
    );
  });
});

describe('publishing is gated on documents:publish, separately from write', () => {
  it('offers the visibility dialog to a publisher', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Demo civil works order')).toBeInTheDocument());

    await openRowMenu(user, 'Demo civil works order');
    expect(screen.getByRole('menuitem', { name: 'Change who can see this…' })).toBeEnabled();
  });

  it('disables it for a writer who cannot publish, while rename stays available', async () => {
    // This is the demo dataset's real split: both secretaries hold
    // documents:read + documents:write and NOT documents:publish, while the dean
    // holds publish. Gating on write instead of publish would hand them a dialog
    // whose every field 403s on submit — placement included, since the server
    // counts filing a row at a unit as publishing even on a personal row.
    capabilityList.length = 0;
    capabilityList.push('documents:read', 'documents:write');

    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Demo civil works order')).toBeInTheDocument());

    await openRowMenu(user, 'Demo civil works order');
    expect(screen.getByRole('menuitem', { name: 'Change who can see this…' })).toHaveAttribute(
      'aria-disabled',
      'true'
    );
    expect(screen.getByRole('menuitem', { name: 'Rename…' })).toBeEnabled();
  });
});

describe('the visibility dialog', () => {
  it('previews the resulting audience live, and warns when the change narrows it', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Demo civil works order')).toBeInTheDocument());

    await openRowMenu(user, 'Demo civil works order');
    await user.click(screen.getByRole('menuitem', { name: 'Change who can see this…' }));

    await waitFor(() =>
      expect(
        screen.getByRole('heading', { name: /Who can see .Demo civil works order./ })
      ).toBeInTheDocument()
    );

    // Opens describing the row as it stands: filed at a unit, no tag.
    expect(
      dialog().getByText(/Visible to everyone at Civil Engineering and below it/)
    ).toBeInTheDocument();
    // Nothing changed yet, so Save is inert.
    expect(screen.getByRole('button', { name: 'Save visibility' })).toBeDisabled();

    // Drop it to personal — the one move that can only reduce the audience.
    await user.click(screen.getByRole('combobox', { name: /Visibility tier/i }));
    await user.click(screen.getByRole('option', { name: 'Personal' }));

    await waitFor(() => expect(screen.getByText('This narrows access')).toBeInTheDocument());
    expect(screen.getByText(/Only you/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save visibility' })).toBeEnabled();
  });

  /**
   * A block held only by ANOTHER BLOCK (#1186). Before nesting, "what uses this"
   * had one possible answer and the dialog said "templates". A block nested in a
   * letterhead would have been reported as used by nothing — and then refused
   * deletion with a 409, which is the client/server disagreement the reference
   * scanners are kept in parity to prevent.
   */
  it('names the blocks that contain this one, not only the templates', async () => {
    routeApi({
      usage: {
        block_id: 4,
        total: 1,
        hidden: 0,
        templates: [],
        blocks: [
          {
            id: 9,
            name: 'Company letterhead',
            scope: 'tenant',
            required_permission: null,
            owner_ou_id: null,
            is_system: false,
            updated_at: '2026-01-02T00:00:00Z',
          },
        ],
      },
    });
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByRole('tab', { name: 'Blocks' })).toBeInTheDocument());
    await openBlocksTab(user);
    await waitFor(() =>
      expect(screen.getByText('Demo civil site-safety notice')).toBeInTheDocument()
    );

    await user.click(screen.getByText('1 uses'));

    await waitFor(() =>
      expect(screen.getByTestId('usage-nesting-blocks')).toBeInTheDocument()
    );
    expect(screen.getByText('Company letterhead')).toBeInTheDocument();
    // And it must NOT be reported as safe to delete.
    expect(screen.queryByText('Nothing uses this block')).not.toBeInTheDocument();
  });

  /**
   * Duplicating a template: the management-surface half of the designer's
   * "Save as a copy".
   *
   * Both exist because both surfaces had the same hole — the only way to base a
   * template on an existing one was to open it and overwrite it, which for a
   * tenant-wide row rewrites the template everybody uses.
   */
  it('copies a row into a new PERSONAL one, leaving the original alone', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Demo civil works order')).toBeInTheDocument());

    await openRowMenu(user, 'Demo civil works order');
    await user.click(screen.getByRole('menuitem', { name: 'Duplicate' }));

    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/document-templates',
        expect.objectContaining({ method: 'POST' })
      )
    );

    const call = mockApiClient.mock.calls.find(
      ([url, init]) => url === '/api/v1/document-templates' && (init as RequestInit)?.method === 'POST'
    );
    const body = JSON.parse((call?.[1] as RequestInit).body as string) as Record<string, unknown>;

    expect(body.name).toBe('Demo civil works order (copy)');
    // Personal whatever the original was: copying somebody's published template
    // is not the same act as publishing your version of it.
    expect(body.scope).toBe('personal');
    // And it carries the body, or the copy would be an empty document.
    expect(body.data).toBeDefined();
  });

  it('leads with the block’s usage before offering any control', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByRole('tab', { name: 'Blocks' })).toBeInTheDocument());
    await openBlocksTab(user);
    await waitFor(() =>
      expect(screen.getByText('Demo civil site-safety notice')).toBeInTheDocument()
    );

    await openRowMenu(user, 'Demo civil site-safety notice');
    await user.click(screen.getByRole('menuitem', { name: 'Change who can see this…' }));

    await waitFor(() => expect(screen.getByText('Used in 3 places')).toBeInTheDocument());
    expect(screen.getByText(/1 you can see, and 2 you cannot/)).toBeInTheDocument();
  });

  it('locks placement, with a reason, when the unit list is unreadable', async () => {
    routeApi({ ousStatus: 403 });
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Demo civil works order')).toBeInTheDocument());

    await openRowMenu(user, 'Demo civil works order');
    await user.click(screen.getByRole('menuitem', { name: 'Change who can see this…' }));

    await waitFor(() => expect(screen.getByRole('dialog')).toBeInTheDocument());
    // Disabled rather than an empty picker: one click on an empty list would
    // unfile the row.
    expect(dialog().getByRole('combobox', { name: /Filed at/i })).toBeDisabled();
    expect(dialog().getByText(/Placement cannot be changed here/)).toBeInTheDocument();
  });

  it('offers the caller’s own permissions, and says so, when the catalogue is admin-only', async () => {
    // GET /api/permissions is gated on the admin ROLE, so a non-admin publisher
    // cannot read it. Hiding the field would remove the publishing decision
    // entirely; falling back to the set they hold keeps it and is honest about
    // where the list came from.
    routeApi({ permissionsStatus: 403 });
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Demo civil works order')).toBeInTheDocument());

    await openRowMenu(user, 'Demo civil works order');
    await user.click(screen.getByRole('menuitem', { name: 'Change who can see this…' }));

    await waitFor(() =>
      expect(screen.getByText(/These are the permissions you hold/)).toBeInTheDocument()
    );
  });

  it('PATCHes all three governance fields together', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Demo civil works order')).toBeInTheDocument());

    await openRowMenu(user, 'Demo civil works order');
    await user.click(screen.getByRole('menuitem', { name: 'Change who can see this…' }));
    await waitFor(() => expect(screen.getByRole('dialog')).toBeInTheDocument());

    await user.click(dialog().getByRole('combobox', { name: /Also requires/i }));
    await user.click(screen.getByRole('option', { name: 'documents:publish' }));
    await user.click(screen.getByRole('button', { name: 'Save visibility' }));

    await waitFor(() => {
      const patch = mockApiClient.mock.calls.find(
        (call) =>
          call[0] === '/api/v1/document-templates/6' &&
          (call[1] as { method?: string } | undefined)?.method === 'PATCH'
      );
      expect(patch).toBeDefined();
      expect(JSON.parse((patch![1] as { body: string }).body)).toEqual({
        scope: 'tenant',
        owner_ou_id: 2,
        required_permission: 'documents:publish',
      });
    });
  });
});

describe('renaming', () => {
  it('PATCHes only the name', async () => {
    const user = userEvent.setup();
    render(<DocumentTemplatesPage />);
    await waitFor(() => expect(screen.getByText('Demo civil works order')).toBeInTheDocument());

    await openRowMenu(user, 'Demo civil works order');
    await user.click(screen.getByRole('menuitem', { name: 'Rename…' }));

    await waitFor(() => expect(screen.getByRole('dialog')).toBeInTheDocument());
    const input = dialog().getByRole('textbox');
    await user.clear(input);
    await user.type(input, 'Civil works order');
    await user.click(screen.getByRole('button', { name: 'Rename' }));

    await waitFor(() => {
      const patch = mockApiClient.mock.calls.find(
        (call) =>
          call[0] === '/api/v1/document-templates/6' &&
          (call[1] as { method?: string } | undefined)?.method === 'PATCH'
      );
      expect(patch).toBeDefined();
      // A rename must not carry the governance fields along — that would make an
      // ordinary write into a publish action and 403 for the people most likely
      // to be renaming.
      expect(JSON.parse((patch![1] as { body: string }).body)).toEqual({
        name: 'Civil works order',
      });
    });
    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith('Renamed to Civil works order', 'success')
    );
  });
});

describe('failure states', () => {
  it('renders the access-denied state on a 403, not an error toast loop', async () => {
    mockApiClient.mockImplementation((url: string) => {
      if (url === '/api/v1/document-templates' || url === '/api/v1/document-blocks') {
        return jsonResponse(403, { error: 'forbidden' });
      }
      return jsonResponse(404, {});
    });

    render(<DocumentTemplatesPage />);

    await waitFor(() => expect(screen.getAllByText('Access denied').length).toBeGreaterThan(0));
    expect(
      screen.getAllByText(/You need the documents:read permission/).length
    ).toBeGreaterThan(0);
  });

  it('lists a row whose data is not a valid template instead of silently dropping it', async () => {
    // The designer's mapper returns null for such a row, which is right for a
    // canvas and wrong for an inventory: the row you cannot see is the one you
    // need to delete, and an omitted row makes the screen disagree with the
    // server's own counts.
    routeApi({ templates: [{ ...PLACED_TEMPLATE, data: 'not a template at all' }] });
    render(<DocumentTemplatesPage />);

    await waitFor(() => expect(screen.getByText('Demo civil works order')).toBeInTheDocument());
  });
});
