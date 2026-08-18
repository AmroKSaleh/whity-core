/**
 * OU admin page — pagination exhaustion.
 *
 * GET /api/v1/ous is paginated (25 per page by default, 100 maximum). The page
 * used to request it with no parameters and build the tree from page 1 alone,
 * so on an instance with more than 25 units the rest vanished from a tree that
 * still rendered as complete — reported by a user as "the department was never
 * created". These tests pin the three properties that make that impossible:
 * later pages are fetched, the walk survives past the server's page-size cap
 * (so raising `per_page` to 100 would not have been a fix), and a hierarchy
 * that could not be fully loaded is reported rather than drawn.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

const hasPermission = jest.fn<boolean, [string]>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ permissions: [], loading: false, hasPermission }),
}));

import OUsPage from '@/app/(protected)/admin/ous/page';

beforeAll(() => {
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  });
}

/** PaginationParams::MAX_PER_PAGE — the server hard-clamps to this. */
const SERVER_MAX_PER_PAGE = 100;
/** PaginationParams::DEFAULT_PER_PAGE — what a request with no `per_page` got. */
const SERVER_DEFAULT_PER_PAGE = 25;

/**
 * One faculty with `count - 1` departments under it — the reporting instance's
 * shape. Names are zero-padded so the tree's case-insensitive name sort agrees
 * with numeric order ("Department 10" would otherwise precede "Department 2").
 */
function makeOus(count: number) {
  const width = String(count).length;
  return [
    {
      id: 1,
      tenant_id: 1,
      parent_id: null,
      name: 'Faculty of Engineering',
      slug: 'faculty-of-engineering',
      created_at: '2026-01-01T00:00:00Z',
    },
    ...Array.from({ length: count - 1 }, (_, i) => ({
      id: i + 2,
      tenant_id: 1,
      parent_id: 1,
      name: `Department ${String(i + 1).padStart(width, '0')}`,
      slug: `department-${i + 1}`,
      created_at: '2026-01-01T00:00:00Z',
    })),
  ];
}

/**
 * Serve a dataset the way the API does: a `data` slice plus a `pagination`
 * envelope, with `per_page` defaulted and clamped exactly as the server clamps
 * it. The clamp is the point — a client that trusts one request to return
 * everything is wrong no matter what page size it asks for.
 */
function serve(dataset: ReturnType<typeof makeOus>, url: string) {
  const query = new URLSearchParams(url.split('?')[1] ?? '');
  const requested = Number(query.get('per_page') ?? SERVER_DEFAULT_PER_PAGE);
  const perPage = Math.min(requested, SERVER_MAX_PER_PAGE);
  const page = Number(query.get('page') ?? '1');
  const offset = (page - 1) * perPage;

  return jsonResponse(200, {
    data: dataset.slice(offset, offset + perPage),
    pagination: {
      page,
      perPage,
      total: dataset.length,
      totalPages: Math.ceil(dataset.length / perPage),
    },
  });
}

function listCallUrls() {
  return mockApiClient.mock.calls
    .map((c) => String(c[0]))
    .filter((u) => u.startsWith('/api/v1/ous'));
}

beforeEach(() => {
  jest.clearAllMocks();
  hasPermission.mockReturnValue(true);
  window.localStorage.clear();
});

describe('OUsPage pagination', () => {
  it('renders units that fall beyond the server default page size', async () => {
    // 48 units: the reporting instance. Under the old no-parameter request the
    // server returned 25 and the remaining 23 silently disappeared.
    const dataset = makeOus(48);
    mockApiClient.mockImplementation((url: string) => serve(dataset, url));

    render(<OUsPage />);

    await waitFor(() =>
      expect(screen.getByText('Faculty of Engineering')).toBeInTheDocument()
    );

    // Department 40 is the 41st row, so it is past the default page boundary.
    // This is the unit the reporting user was told "was never created".
    expect(screen.getByText('Department 40')).toBeInTheDocument();
    expect(screen.getByText('Department 47')).toBeInTheDocument();
    expect(screen.getAllByRole('treeitem')).toHaveLength(48);
  });

  it('renders every unit past the server maximum page size', async () => {
    // 240 units cannot be fetched in one request at ANY page size, because the
    // server clamps per_page to 100. This is the case that a bare
    // "?per_page=100" fix would still get wrong.
    const dataset = makeOus(240);
    mockApiClient.mockImplementation((url: string) => serve(dataset, url));

    render(<OUsPage />);

    await waitFor(() =>
      expect(screen.getByText('Department 239')).toBeInTheDocument()
    );

    expect(screen.getAllByRole('treeitem')).toHaveLength(240);
    // Three pages at the 100 clamp, so the walk must have made 3 requests.
    expect(listCallUrls()).toHaveLength(3);
  });

  it('requests an explicit page size and stops at the last page', async () => {
    const dataset = makeOus(240);
    mockApiClient.mockImplementation((url: string) => serve(dataset, url));

    render(<OUsPage />);

    await waitFor(() =>
      expect(screen.getByText('Department 239')).toBeInTheDocument()
    );

    const urls = listCallUrls();
    // Relying on the server's default page size is what caused the truncation,
    // so every request must name the size it wants.
    for (const url of urls) {
      expect(url).toMatch(/[?&]per_page=\d+/);
    }

    const pages = urls.map((u) =>
      Number(new URLSearchParams(u.split('?')[1] ?? '').get('page') ?? '1')
    );
    expect(pages).toEqual([1, 2, 3]);
  });

  it('reports a partial load instead of drawing an incomplete tree', async () => {
    // Page 1 succeeds, the follow-up fails: what we hold is missing units, so
    // it must not be presented as the hierarchy.
    const dataset = makeOus(240);
    mockApiClient.mockImplementation((url: string) => {
      const page = Number(
        new URLSearchParams(url.split('?')[1] ?? '').get('page') ?? '1'
      );
      return page === 1 ? serve(dataset, url) : jsonResponse(500, {});
    });

    render(<OUsPage />);

    await waitFor(() => expect(addToast).toHaveBeenCalled());

    // The truncated set must not be rendered as if it were everything...
    expect(screen.queryByRole('tree')).not.toBeInTheDocument();
    expect(screen.queryByText('Faculty of Engineering')).not.toBeInTheDocument();
    // ...nor may the page claim there is nothing to show.
    expect(screen.queryByText(/no organizational units yet/i)).not.toBeInTheDocument();
    // ...and the failure must be stated on the page, not only in a toast.
    expect(
      screen.getByText(/could not load the organizational units/i)
    ).toBeInTheDocument();
    // The user is told the size of the gap, not just that something broke.
    expect(screen.getByText(/loaded only 100 of 240/i)).toBeInTheDocument();
  });

  it('reports a total failure when even the first page fails', async () => {
    mockApiClient.mockImplementation(() => jsonResponse(500, {}));

    render(<OUsPage />);

    await waitFor(() => expect(addToast).toHaveBeenCalled());

    expect(screen.queryByRole('tree')).not.toBeInTheDocument();
    expect(
      screen.getByText(/could not load the organizational units/i)
    ).toBeInTheDocument();
    expect(
      screen.getByText(/failed to fetch organizational units/i)
    ).toBeInTheDocument();
  });
});
