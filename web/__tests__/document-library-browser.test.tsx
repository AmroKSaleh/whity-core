/**
 * The document library's browser affordances: layout, sorting, collections
 * management, and — the part that matters most — the several DIFFERENT things a
 * page with no rows can mean.
 *
 * WHAT IS PINNED HERE AND WHY
 * ---------------------------
 * #987 built a rail whose whole purpose is that "absent", "unavailable to you"
 * and "empty" never look alike, and `document-organizer-rail.test.tsx` pins that
 * for the rail. This file pins the same distinction one pane to the right, where
 * it is much easier to lose: every zero-row page answered with one tidy sentence
 * erases it completely, and nothing fails when it does.
 *
 * It also pins that SORTING IS THE SERVER'S. The shared DataTable can sort
 * client-side, which in server-pagination mode sorts the page it was handed and
 * presents the result as a sorted library — so the assertions below are about
 * the request that goes out, not about the order of the rows that come back.
 */

import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';

const mockApiClient = jest.fn();
/**
 * The forwarder is built ONCE, inside the factory, and that is load-bearing.
 *
 * `useFetch` keys its effect on the function identity it is handed, and this
 * screen passes `apiClient` as a dependency of all four of its fetches. A mock
 * returning a fresh arrow from every `useAuth()` call therefore refetches on
 * every render, forever: the first draft of this file did exactly that and every
 * assertion failed on a table stuck at "Loading…". The real `useAuth` memoises
 * `apiClient` with `useCallback`, so the stable forwarder is the faithful mock —
 * a per-render one would be testing a component in a state production never
 * reaches.
 */
jest.mock('@/lib/auth-context', () => {
  const apiClient = (...args: unknown[]) => mockApiClient(...args);
  return { useAuth: () => ({ apiClient }) };
});

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

/**
 * The screen's two non-fetch dependencies, both added by changes that landed
 * while this file sat on a branch.
 *
 * `useRouter` is #1023's — "New document" can hand the caller straight to the
 * routing screen — and an unmocked one throws "expected app router to be
 * mounted" before a single assertion runs.
 *
 * `useCapabilities` gates the New button. It is given BOTH capabilities by
 * default so the button is present in the tests that are not about it: the
 * alternative, letting it fail closed, would hide a control every layout
 * assertion below renders around, and the absence would look like a layout bug.
 */
jest.mock('next/navigation', () => ({ useRouter: () => ({ push: jest.fn() }) }));

const heldCapabilities = new Set<string>(['documents:render', 'documents:route']);
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({
    loading: false,
    permissions: [...heldCapabilities],
    has: (slug: string) => heldCapabilities.has(slug),
    hasPermission: (slug: string) => heldCapabilities.has(slug),
  }),
}));

import DocumentLibraryPage from '@/app/(protected)/admin/document-library/page';
import { libraryEmptyState } from '@/app/(protected)/admin/document-library/library-empty-state';
import type {
  DocumentCollection,
  DocumentRow,
  DocumentView,
} from '@/app/(protected)/admin/document-library/types';

// ── fixtures ───────────────────────────────────────────────────────────────

function view(overrides: Partial<DocumentView> & Pick<DocumentView, 'key'>): DocumentView {
  return {
    label: overrides.key,
    description: `The ${overrides.key} folder.`,
    group: 'derived',
    parameters: [],
    requires: [],
    available: true,
    unavailable_reason: null,
    ...overrides,
  };
}

const VIEWS: DocumentView[] = [
  view({ key: 'all', label: 'All documents' }),
  view({ key: 'created-by-me', label: 'Created by me' }),
  view({
    key: 'raised-by-my-unit',
    label: 'Raised by my unit',
    parameters: [{ name: 'ou_id', required: false }],
  }),
  view({ key: 'starred', label: 'Starred', group: 'personal' }),
  view({
    key: 'collection',
    label: 'Collection',
    group: 'personal',
    parameters: [{ name: 'collection_id', required: true }],
  }),
];

const Q3: DocumentCollection = {
  id: 5,
  tenant_id: 1,
  profile_id: 10,
  name: 'Q3 audit',
  system_key: null,
  created_at: '2026-08-01 10:00:00',
  item_count: 2,
};

const STARRED: DocumentCollection = {
  id: 6,
  tenant_id: 1,
  profile_id: 10,
  name: 'Starred',
  system_key: 'starred',
  created_at: '2026-08-01 10:00:00',
  item_count: 1,
};

function row(overrides: Partial<DocumentRow> & Pick<DocumentRow, 'id' | 'title'>): DocumentRow {
  return {
    template_name: 'Invoice template',
    origin_ou_id: 3,
    created_by: 10,
    created_at: '2026-08-01 10:00:00',
    content_url: `/api/v1/documents/${overrides.id}/content`,
    artifacts: [],
    collection_ids: [],
    starred: false,
    ...overrides,
  };
}

const INVOICE = row({ id: 1, title: 'Invoice 42' });
// An Arabic title, because a document browser is where bidi filenames land.
const ARABIC = row({ id: 2, title: 'تقرير الربع الثالث', collection_ids: [5] });

interface Handled {
  ok: boolean;
  status?: number;
  body: unknown;
}

/** Every request the page issues, in order, so an assertion can name one. */
let calls: { url: string; method: string }[] = [];

/**
 * Route requests by URL rather than by call order.
 *
 * The page fires four independent fetches on mount and their completion order is
 * not defined; an ordered mock queue would pass or fail depending on which
 * microtask won. Also lets one test change one endpoint's answer without
 * restating the other three.
 */
function installApi(options: {
  views?: Handled;
  collections?: Handled;
  ous?: Handled;
  templates?: Handled;
  documents?: (url: string) => Handled;
  mutation?: Handled;
} = {}) {
  calls = [];
  mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
    const method = init?.method ?? 'GET';
    calls.push({ url, method });

    const answer = (handled: Handled) =>
      Promise.resolve({
        ok: handled.ok,
        status: handled.status ?? (handled.ok ? 200 : 500),
        json: () => Promise.resolve(handled.body),
      } as Response);

    if (method !== 'GET') {
      return answer(options.mutation ?? { ok: true, body: { data: {} } });
    }
    if (url.startsWith('/api/v1/documents/views')) {
      return answer(options.views ?? { ok: true, body: { data: VIEWS, unavailable_substrates: [] } });
    }
    // #1023's template picker walks EVERY page through fetchAllPages, and treats
    // a partial walk as a failure — so this answers with a complete envelope
    // rather than a bare array.
    if (url.startsWith('/api/v1/document-templates')) {
      return answer(
        options.templates ?? {
          ok: true,
          body: {
            data: [{ id: 1, name: 'Invoice template', data: { placeholders: [] } }],
            pagination: { page: 1, perPage: 100, total: 1, totalPages: 1 },
          },
        }
      );
    }
    if (url.startsWith('/api/v1/document-collections')) {
      return answer(options.collections ?? { ok: true, body: { data: [Q3, STARRED] } });
    }
    if (url.startsWith('/api/v1/ous')) {
      return answer(
        options.ous ?? {
          ok: true,
          body: {
            data: [{ id: 3, name: 'Registry', parent_id: null }],
            pagination: { page: 1, perPage: 100, total: 1, totalPages: 1 },
          },
        }
      );
    }
    if (url.startsWith('/api/v1/documents')) {
      return answer(
        options.documents?.(url) ?? {
          ok: true,
          body: {
            data: [INVOICE, ARABIC],
            pagination: { page: 1, perPage: 25, total: 2, totalPages: 1 },
            view: { key: 'all', ou_id: null, collection_id: null },
            sort: { field: null, direction: 'desc' },
          },
        }
      );
    }
    return answer({ ok: false, status: 404, body: { error: `unrouted ${method} ${url}` } });
  });
}

/** The most recent GET /api/v1/documents URL — what a sort assertion is about. */
function lastListUrl(): string {
  const listCalls = calls.filter((c) => c.method === 'GET' && c.url.startsWith('/api/v1/documents?'));
  return listCalls[listCalls.length - 1]?.url ?? '';
}

beforeEach(() => {
  jest.clearAllMocks();
  window.localStorage.clear();
  heldCapabilities.clear();
  heldCapabilities.add('documents:render');
  heldCapabilities.add('documents:route');
  installApi();
});

// ── 1. the empty states stay distinguishable ───────────────────────────────

/**
 * Asserted against the pure resolver rather than through five renders: the
 * property under test is that the five causes produce five DIFFERENT sentences,
 * and that is a statement about the function, not about the DOM.
 */
describe('the several ways a page can have no rows', () => {
  const t = ((_key: string, fallback: string) => fallback) as never;

  const base = {
    viewKey: 'all',
    collection: null,
    starredCollectionExists: false,
    searchApplied: false,
    total: 0,
  };

  it('never gives two different causes the same sentence', () => {
    const cases = [
      base,
      { ...base, searchApplied: true },
      { ...base, viewKey: 'starred' },
      { ...base, viewKey: 'collection', collection: { ...Q3, item_count: 0 } },
      { ...base, viewKey: 'collection', collection: Q3 },
      { ...base, total: 40 },
    ];

    const titles = cases.map((input) => libraryEmptyState(t, input).title);

    expect(new Set(titles).size).toBe(cases.length);
  });

  it('does not claim the folder is empty when it is the SEARCH that matched nothing', () => {
    const searched = libraryEmptyState(t, { ...base, searchApplied: true });

    // The folder may be full. "No documents in this folder" would simply be
    // false, and it is the sentence the reader would act on.
    expect(searched.title).not.toMatch(/no documents in this folder/i);
    expect(searched.title.toLowerCase()).toContain('search');
  });

  /**
   * The one an empty page cannot express on its own. `item_count` counts FILED
   * rows and reading through a collection re-applies visibility, so a positive
   * count with zero rows means the documents are still filed and no longer
   * readable — not that the collection emptied itself.
   */
  it('distinguishes a collection nothing was filed in from one whose filings became unreadable', () => {
    const nothingFiled = libraryEmptyState(t, {
      ...base,
      viewKey: 'collection',
      collection: { ...Q3, item_count: 0 },
    });
    const filedButHidden = libraryEmptyState(t, {
      ...base,
      viewKey: 'collection',
      collection: Q3,
    });

    expect(nothingFiled.title).not.toBe(filedButHidden.title);
    // And the second says nothing was removed, because nothing was.
    expect(filedButHidden.description.toLowerCase()).toContain('nothing has been removed');
  });

  it('reports a page past the end instead of calling it an empty folder', () => {
    const stale = libraryEmptyState(t, { ...base, total: 40 });

    expect(stale.pastTheEnd).toBe(true);
    expect(libraryEmptyState(t, base).pastTheEnd).toBeUndefined();
  });
});

// ── 2. sorting is the server's ─────────────────────────────────────────────

describe('sorting', () => {
  it('asks the SERVER to sort, and sends no direction until one is chosen', async () => {
    render(<DocumentLibraryPage />);
    await waitFor(() => expect(screen.getByText('Invoice 42')).toBeInTheDocument());

    // The default order names no sort at all — the plain list #947 item 1
    // shipped, byte for byte.
    expect(lastListUrl()).not.toContain('sort=');

    await userEvent.click(screen.getByRole('combobox', { name: /Sort by/ }));
    await userEvent.click(screen.getByRole('option', { name: 'Title' }));

    await waitFor(() => expect(lastListUrl()).toContain('sort=title'));
    // No `direction`: the per-field default belongs to the server (A→Z for text,
    // newest-first for a date) and holding a copy of that rule here would make
    // one of the three columns open the wrong way round.
    expect(lastListUrl()).not.toContain('direction=');
  });

  it('sends an explicit direction once the caller reverses the one that was applied', async () => {
    installApi({
      documents: (url) => ({
        ok: true,
        body: {
          data: [INVOICE],
          pagination: { page: 1, perPage: 25, total: 1, totalPages: 1 },
          view: { key: 'all', ou_id: null, collection_id: null },
          // The server reports what it APPLIED. Ascending, for a title.
          sort: { field: url.includes('sort=title') ? 'title' : null, direction: url.includes('sort=title') ? 'asc' : 'desc' },
        },
      }),
    });

    render(<DocumentLibraryPage />);
    await waitFor(() => expect(screen.getByText('Invoice 42')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('combobox', { name: /Sort by/ }));
    await userEvent.click(screen.getByRole('option', { name: 'Title' }));
    await waitFor(() => expect(lastListUrl()).toContain('sort=title'));

    // The toggle reverses the direction the SERVER reported, not a guess: the
    // echo is the only thing that knows ascending was applied.
    await userEvent.click(screen.getByRole('button', { name: /Ascending/ }));

    await waitFor(() => expect(lastListUrl()).toContain('direction=desc'));
  });

  /**
   * The header must NOT be a sorting control. In server-pagination mode
   * TanStack's sorter would reorder the twenty five rows it was handed and
   * present that as a sorted library — and page 2 would re-sort a different
   * twenty five.
   */
  it('leaves the column headers non-sortable so no page is sorted in isolation', async () => {
    render(<DocumentLibraryPage />);
    await waitFor(() => expect(screen.getByText('Invoice 42')).toBeInTheDocument());

    const before = lastListUrl();
    await userEvent.click(screen.getByRole('columnheader', { name: 'Title' }));

    expect(lastListUrl()).toBe(before);
  });
});

// ── 3. list and grid ───────────────────────────────────────────────────────

describe('the layout switch', () => {
  it('shows the same documents in both layouts and remembers the choice', async () => {
    const { unmount } = render(<DocumentLibraryPage />);
    await waitFor(() => expect(screen.getByRole('table', { name: 'Documents' })).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: 'Grid' }));

    // Not a table any more, but the same rows — including the star, which is
    // the affordance a second layout most easily drops.
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
    const grid = screen.getByRole('list', { name: 'Documents' });
    expect(within(grid).getByText('Invoice 42')).toBeInTheDocument();
    expect(within(grid).getByText('تقرير الربع الثالث')).toBeInTheDocument();
    expect(within(grid).getAllByRole('button', { name: /Star this document/ })).toHaveLength(2);

    // Remembered — per browser, which is what localStorage is, and what the
    // types say this is.
    expect(window.localStorage.getItem('wc:document-library:layout')).toBe('grid');

    unmount();
    render(<DocumentLibraryPage />);
    await waitFor(() => expect(screen.getByRole('list', { name: 'Documents' })).toBeInTheDocument());
  });

  it('opens as a list when the stored value is unreadable rather than failing to render', async () => {
    window.localStorage.setItem('wc:document-library:layout', 'carousel');

    render(<DocumentLibraryPage />);

    await waitFor(() => expect(screen.getByRole('table', { name: 'Documents' })).toBeInTheDocument());
  });

  /**
   * The title goes to the document's RECORD, in BOTH layouts.
   *
   * #993 moved it there from `content_url` — the current artifact, which answers
   * "let me read it" and nothing else: no version history, no trail, and a
   * superseded document indistinguishable from a current one. This branch was
   * written before that landed and still linked the bytes, so the destination is
   * pinned here rather than left to survive the next merge on trust.
   *
   * Both layouts, in one test, because "the grid quietly links somewhere else"
   * is the exact failure a second rendering of the same row produces — and it is
   * invisible to anyone who only ever opens the list.
   */
  it('links the title to the record page in both layouts, not to the file', async () => {
    render(<DocumentLibraryPage />);
    await waitFor(() => expect(screen.getByRole('table', { name: 'Documents' })).toBeInTheDocument());

    const inList = screen.getByTestId('document-row-1');
    expect(inList).toHaveAttribute('href', '/admin/document-library/1');
    // The negative half: the artifact URL must not be what the title opens.
    expect(inList).not.toHaveAttribute('href', '/api/v1/documents/1/content');

    await userEvent.click(screen.getByRole('button', { name: 'Grid' }));
    const grid = await screen.findByRole('list', { name: 'Documents' });
    expect(within(grid).getByTestId('document-row-1')).toHaveAttribute(
      'href',
      '/admin/document-library/1'
    );

    // The file is still reachable — from the row menu, which is where it moved.
    await userEvent.click(within(grid).getAllByRole('button', { name: 'Document actions' })[0]);
    expect(await screen.findByRole('menuitem', { name: /Open the rendered file/ })).toHaveAttribute(
      'href',
      '/api/v1/documents/1/content'
    );
  });

  /**
   * The card gives a title TWO lines; the table row gives it one.
   *
   * Pinned as a class rather than as rendered width because jsdom has no layout
   * — and that is exactly why this defect survived every test until the page was
   * opened. CSS truncation does not change `textContent`, so the grid assertions
   * above passed while each card showed "Demo tenant-wide r…". The class is the
   * mechanism, so the class is what is asserted; the alternative is no coverage
   * at all for the one thing the grid exists to do.
   */
  it('lets a card title use two lines, so the grid does not clip harder than the list', async () => {
    render(<DocumentLibraryPage />);
    // The row, not the table: DataTable renders its skeleton inside a <table>
    // that already carries the aria-label, so waiting on the table alone can win
    // the race and find no rows at all.
    expect(await screen.findByTestId('document-row-1')).toHaveClass('truncate');

    await userEvent.click(screen.getByRole('button', { name: 'Grid' }));
    const grid = await screen.findByRole('list', { name: 'Documents' });
    const card = within(grid).getByTestId('document-row-1');
    expect(card).toHaveClass('line-clamp-2');
    expect(card).not.toHaveClass('truncate');
    // The whole title stays reachable on hover even when the clamp bites.
    expect(card).toHaveAttribute('title', 'Invoice 42');
  });

  /**
   * An empty GRID says the same thing an empty list says.
   *
   * WITH A POSITIVE CONTROL, because "the folder is empty" and "the grid never
   * rendered" produce identical assertions: `queryBy…` finds nothing either way,
   * and a grid that crashed, or that was never reached because the layout switch
   * silently failed, would pass an emptiness check forever. So the same render
   * must also show something that is definitely there — the empty state's own
   * sentence, and the toolbar around it.
   */
  it('renders an empty grid as an empty state, not as a grid that failed to appear', async () => {
    installApi({
      documents: () => ({
        ok: true,
        body: {
          data: [],
          pagination: { page: 1, perPage: 25, total: 0, totalPages: 0 },
          view: { key: 'all', ou_id: null, collection_id: null },
          sort: { field: null, direction: 'desc' },
        },
      }),
    });

    render(<DocumentLibraryPage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Grid' }));

    // The positive control: this render definitely happened and definitely
    // reached the grid layout.
    expect(await screen.findByText('No documents in this folder')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Grid' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.queryByRole('table')).not.toBeInTheDocument();

    // Only now is the absence meaningful.
    expect(screen.queryByRole('list', { name: 'Documents' })).not.toBeInTheDocument();
    expect(screen.queryByTestId('document-row-1')).not.toBeInTheDocument();
  });
});

// ── 4. bidi ────────────────────────────────────────────────────────────────

it('wraps every piece of tenant text in a bidi isolate', async () => {
  render(<DocumentLibraryPage />);

  // A title is text in an unknown script inside an interface with its own
  // direction. Without dir="auto" its punctuation and digits render at the
  // wrong end.
  await waitFor(() =>
    expect(screen.getByText('تقرير الربع الثالث').closest('[dir="auto"]')).not.toBeNull()
  );
  expect(screen.getByText('Invoice 42').closest('[dir="auto"]')).not.toBeNull();
  expect(screen.getAllByText('Invoice template')[0].closest('[dir="auto"]')).not.toBeNull();
});

// ── 5. a denied action is disabled with its reason ─────────────────────────

describe('controls the caller cannot use', () => {
  it('offers rename and delete on the built-in starred folder, disabled, saying why', async () => {
    render(<DocumentLibraryPage />);
    await waitFor(() => expect(screen.getByText('Invoice 42')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /^Starred$/ }));
    await userEvent.click(screen.getByRole('button', { name: 'This collection' }));

    // Present, not hidden: hiding them would make "this one is built in", "you
    // lack a permission" and "the feature was removed" pixel-identical (#951).
    const rename = screen.getByRole('menuitem', { name: /Rename/ });
    expect(rename).toHaveAttribute('data-disabled');
    expect(rename).toHaveTextContent(/built in/i);
    expect(screen.getByRole('menuitem', { name: /Delete this collection/ })).toHaveAttribute(
      'data-disabled'
    );
  });

  it('disables creating and filing — with the reason — when the collection list could not be read', async () => {
    installApi({ collections: { ok: false, status: 500, body: { error: 'boom' } } });

    render(<DocumentLibraryPage />);
    await waitFor(() => expect(screen.getByText('Invoice 42')).toBeInTheDocument());

    const create = screen.getByRole('button', { name: /New collection/ });
    expect(create).toBeDisabled();
    // Visible text, not only a title attribute — hover is touch-inaccessible.
    expect(screen.getByText(/Failed to load your collections/)).toBeInTheDocument();

    await userEvent.click(screen.getAllByRole('button', { name: 'Document actions' })[0]);
    const fileInto = screen.getByRole('menuitem', { name: /Add to collection/ });
    expect(fileInto).toHaveAttribute('data-disabled');
    expect(fileInto).toHaveTextContent(/Failed to load your collections/);
  });

  it('will not toggle a star the list did not report, instead of guessing "not starred"', async () => {
    installApi({
      documents: () => ({
        ok: true,
        body: {
          // `starred` absent — the shape the render route returns. A hollow star
          // here would assert something the server never said.
          data: [{ ...INVOICE, starred: undefined, collection_ids: undefined }],
          pagination: { page: 1, perPage: 25, total: 1, totalPages: 1 },
          view: { key: 'all', ou_id: null, collection_id: null },
          sort: { field: null, direction: 'desc' },
        },
      }),
    });

    render(<DocumentLibraryPage />);
    await waitFor(() => expect(screen.getByText('Invoice 42')).toBeInTheDocument());

    expect(screen.getByRole('button', { name: /did not report whether you starred/ })).toBeDisabled();
  });

  it('disables the direction toggle on the default order and says what would enable it', async () => {
    render(<DocumentLibraryPage />);
    await waitFor(() => expect(screen.getByText('Invoice 42')).toBeInTheDocument());

    const toggle = screen.getByRole('button', { name: /Descending/ });
    expect(toggle).toBeDisabled();
    expect(toggle).toHaveAttribute('title', expect.stringMatching(/no direction to reverse/i));
  });
});

// ── 6. collections management ──────────────────────────────────────────────

describe('managing a collection', () => {
  it('renames one through the API the rail reads back', async () => {
    render(<DocumentLibraryPage />);
    await waitFor(() => expect(screen.getByText('Invoice 42')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /Q3 audit/ }));
    await userEvent.click(screen.getByRole('button', { name: 'This collection' }));
    await userEvent.click(screen.getByRole('menuitem', { name: /Rename/ }));

    const field = screen.getByLabelText('Name');
    await userEvent.clear(field);
    await userEvent.type(field, 'Q4 audit');
    await userEvent.click(screen.getByRole('button', { name: 'Rename' }));

    await waitFor(() =>
      expect(calls).toContainEqual({ url: '/api/v1/document-collections/5', method: 'PATCH' })
    );
  });

  it('files a document into a collection, and shows what it is already filed in', async () => {
    render(<DocumentLibraryPage />);
    await waitFor(() => expect(screen.getByText('تقرير الربع الثالث')).toBeInTheDocument());

    // The Arabic document is already in Q3 audit (collection_ids: [5]).
    await userEvent.click(screen.getAllByRole('button', { name: 'Document actions' })[1]);
    await userEvent.click(screen.getByRole('menuitem', { name: /Add to collection/ }));

    const already = screen.getByRole('checkbox');
    expect(already).toBeChecked();

    // Unchecking un-files it. Idempotent, and written per toggle so a failure
    // lands next to the click that caused it.
    await userEvent.click(already);
    await waitFor(() =>
      expect(calls).toContainEqual({
        url: '/api/v1/document-collections/5/documents/2',
        method: 'DELETE',
      })
    );
  });

  it('says there is nowhere to file rather than showing an empty checkbox list', async () => {
    installApi({ collections: { ok: true, body: { data: [STARRED] } } });

    render(<DocumentLibraryPage />);
    await waitFor(() => expect(screen.getByText('Invoice 42')).toBeInTheDocument());

    await userEvent.click(screen.getAllByRole('button', { name: 'Document actions' })[0]);
    await userEvent.click(screen.getByRole('menuitem', { name: /Add to collection/ }));

    // The starred collection is deliberately not offered here — the star on the
    // row already addresses it, and one thing with two controls can disagree.
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(screen.getByText(/You have no collections yet/)).toBeInTheDocument();
  });

  it('offers removal from a collection only while one is open', async () => {
    render(<DocumentLibraryPage />);
    await waitFor(() => expect(screen.getByText('Invoice 42')).toBeInTheDocument());

    await userEvent.click(screen.getAllByRole('button', { name: 'Document actions' })[0]);
    expect(screen.queryByRole('menuitem', { name: /Remove from this collection/ })).toBeNull();
    await userEvent.keyboard('{Escape}');

    await userEvent.click(screen.getByRole('button', { name: /Q3 audit/ }));
    await waitFor(() => expect(lastListUrl()).toContain('collection_id=5'));

    await userEvent.click(screen.getAllByRole('button', { name: 'Document actions' })[0]);
    expect(screen.getByRole('menuitem', { name: /Remove from this collection/ })).toBeInTheDocument();
  });
});

// ── 7. the server's own refusal survives to the screen ─────────────────────

/**
 * A caller who cannot read the folders at all gets the reason and NOTHING to
 * operate.
 *
 * Found by opening the page as the fixture user, who does not hold
 * `documents:read`: the rail was empty behind "Failed to load the document
 * folders", the list said "Insufficient permissions", and between those two
 * sentences sat a Search box, a Sort selector and a list/grid switch — three
 * live controls over nothing, each of which re-fires a request that 403s.
 *
 * The positive control matters more than usual here, because every assertion in
 * this test is an ABSENCE: a page that threw on render, or that never finished
 * loading, would satisfy all of them. So the banner and the list's own sentence
 * must both be present first — only then does the missing chrome mean anything.
 */
it('offers no search, sort or layout switch to a caller who cannot read the folders', async () => {
  installApi({
    views: { ok: false, status: 403, body: { error: 'Insufficient permissions' } },
    documents: () => ({ ok: false, status: 403, body: { error: 'Insufficient permissions' } }),
  });

  render(<DocumentLibraryPage />);

  // Positive control: this render happened, and it reached both failure paths.
  expect(await screen.findByText('Failed to load the document folders')).toBeInTheDocument();
  expect(await screen.findByText('Insufficient permissions')).toBeInTheDocument();

  // Only now is the absence evidence.
  expect(screen.queryByLabelText('Sort by')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Grid' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'List' })).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Search titles')).not.toBeInTheDocument();
});

/**
 * …but a folder that merely cannot be ANCHORED keeps the toolbar, because the
 * anchor selector in it is the remedy.
 *
 * This is the case a blunter fix breaks: a 422 empties the list exactly like a
 * 403 does, so "hide the chrome when the list is empty" would take away the one
 * control that resolves the error and leave the reader stuck on a sentence with
 * nothing to act on.
 */
it('keeps the toolbar when the FOLDER is unanchorable, since the anchor picker is the fix', async () => {
  installApi({
    documents: () => ({
      ok: false,
      status: 422,
      body: { error: 'You do not belong to an organizational unit. Select one to use this folder.' },
    }),
  });

  render(<DocumentLibraryPage />);
  await screen.findByText(/You do not belong to an organizational unit/);

  // The rail loaded, so the browser chrome is still meaningful.
  expect(screen.getByLabelText('Sort by')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Grid' })).toBeInTheDocument();
});

it("shows the server's reason for a folder this caller cannot anchor, not a generic failure", async () => {
  const reason = 'You do not belong to an organizational unit. Select one to use this folder.';
  installApi({
    documents: (url) =>
      url.includes('raised-by-my-unit')
        ? { ok: false, status: 422, body: { error: reason } }
        : {
            ok: true,
            body: {
              data: [INVOICE],
              pagination: { page: 1, perPage: 25, total: 1, totalPages: 1 },
              view: { key: 'all', ou_id: null, collection_id: null },
              sort: { field: null, direction: 'desc' },
            },
          },
  });

  render(<DocumentLibraryPage />);
  await waitFor(() => expect(screen.getByText('Invoice 42')).toBeInTheDocument());

  await userEvent.click(screen.getByRole('button', { name: /Raised by my unit/ }));

  await waitFor(() => expect(screen.getByText(reason)).toBeInTheDocument());
});
