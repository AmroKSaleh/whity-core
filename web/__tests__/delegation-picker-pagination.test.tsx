/**
 * Delegation create-modal — pagination exhaustion (#839).
 *
 * The dialog loads four paginated lists in one `Promise.all`. Three of them
 * rendered page one alone: `/api/v1/roles` and `/api/v1/users` asked for no page
 * at all, and `/api/v1/permissions` asked for `per_page=100`, which is not a fix
 * but a moved cliff — the server clamps `per_page` to 100, so the catalogue
 * simply started dropping entries at 101 instead of 26.
 *
 * This dialog grants authority, so the consequence is worse than the OU pickers'
 * (#824, `ou-picker-pagination.test.tsx`). A grantee that is merely on page 2 is
 * indistinguishable from one that does not exist, and the grantor's next move is
 * to pick the nearest thing that IS listed — a real grant of real permissions
 * against the wrong subject.
 *
 * So each list is checked for the same three properties, per screen decision:
 * entries past the default page size are offered, the walk survives past the
 * server's `per_page` cap (so raising the page size would not have been a fix),
 * and an incomplete walk is stated rather than silently shortened. The last one
 * is checked twice over — once that the refusal is visible, once that a
 * genuinely EMPTY list still reads as empty rather than as a failure.
 *
 * The real Select is driven rather than stubbed, for the reason the OU test
 * gives: a picker test that replaces the picker proves the least interesting
 * half. Hence the pointer-capture shims.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// ---------------------------------------------------------------------------
// Module mocks
// ---------------------------------------------------------------------------

const mockApiGet = jest.fn();
const mockApiPost = jest.fn();

jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => mockApiGet(...args),
    POST: (...args: unknown[]) => mockApiPost(...args),
  },
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

// ---------------------------------------------------------------------------
// Import under test (after the mocks)
// ---------------------------------------------------------------------------
import { CreateDelegationModal } from '@/app/(protected)/admin/delegations/create-modal';

beforeAll(() => {
  // Radix's Select needs these to open; jsdom implements none of them.
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

// ---------------------------------------------------------------------------
// The API, served the way the server serves it
// ---------------------------------------------------------------------------

/** PaginationParams::DEFAULT_PER_PAGE — what a request with no `per_page` got. */
const SERVER_DEFAULT_PER_PAGE = 25;
/** PaginationParams::MAX_PER_PAGE — the server hard-clamps to this. */
const SERVER_MAX_PER_PAGE = 100;

type TypedQuery = { params?: { query?: { page?: number; per_page?: number } } };

/**
 * Serve a dataset the way the API does: a `data` slice plus a `pagination`
 * envelope, with `per_page` defaulted and clamped exactly as the server clamps
 * it. The clamp is the point — no single request returns everything, whatever
 * page size the client asks for.
 */
function typedPage<T>(dataset: T[], options: TypedQuery | undefined) {
  const query = options?.params?.query ?? {};
  const perPage = Math.min(Number(query.per_page ?? SERVER_DEFAULT_PER_PAGE), SERVER_MAX_PER_PAGE);
  const page = Number(query.page ?? 1);
  const offset = (page - 1) * perPage;

  return Promise.resolve({
    data: {
      data: dataset.slice(offset, offset + perPage),
      pagination: {
        page,
        perPage,
        total: dataset.length,
        totalPages: Math.max(1, Math.ceil(dataset.length / perPage)),
      },
    },
    error: undefined,
    response: { ok: true, status: 200 },
  });
}

/**
 * The typed client's failure shape: it never throws, it leaves `data`
 * undefined. That is the only signal a caller gets, and mistaking it for an
 * empty page is exactly the truncation this dialog used to ship.
 */
function typedFailure() {
  return Promise.resolve({
    data: undefined,
    error: { error: 'Internal Server Error' },
    response: { ok: false, status: 500 },
  });
}

/**
 * Zero-padded names so the ordinal is readable in a failure message and so
 * "Role 026" sorts where it reads — just past the default page boundary, which
 * is where the entries the operator calls "missing" begin.
 */
function names(count: number, prefix: string): string[] {
  const width = String(count).length;
  return Array.from(
    { length: count },
    (_, i) => `${prefix} ${String(i + 1).padStart(width, '0')}`
  );
}

function makeRoles(count: number) {
  return names(count, 'Role').map((name, i) => ({
    id: i + 1,
    name,
    description: null,
    tenant_id: 1,
    permission_count: 0,
  }));
}

function makeUsers(count: number) {
  const width = String(count).length;
  return Array.from({ length: count }, (_, i) => ({
    id: i + 1,
    email: `user-${String(i + 1).padStart(width, '0')}@example.com`,
    role: 'user',
    tenant_id: 1,
  }));
}

function makePermissions(count: number) {
  return names(count, 'perm').map((name, i) => ({
    id: i + 1,
    name: name.replace(' ', ':'),
    source: 'db',
  }));
}

type Handler = (options: TypedQuery | undefined) => Promise<unknown>;

/**
 * Answer all four endpoints the dialog touches. The defaults are small,
 * single-page sets so a test only has to describe the ONE list it is about;
 * `overrides` replaces a path's handler wholesale.
 */
function stubApi(overrides: Record<string, Handler> = {}) {
  const handlers: Record<string, Handler> = {
    '/api/v1/permissions': (o) => typedPage(makePermissions(2), o),
    '/api/v1/roles': (o) => typedPage(makeRoles(2), o),
    '/api/v1/users': (o) => typedPage(makeUsers(2), o),
    '/api/v1/ous': (o) => typedPage([], o),
    ...overrides,
  };

  mockApiGet.mockImplementation((path: string, options?: TypedQuery) => {
    const handler = handlers[path];
    if (handler !== undefined) {
      return handler(options);
    }
    return Promise.resolve({
      data: undefined,
      error: { error: `unstubbed ${path}` },
      response: { ok: false, status: 404 },
    });
  });
}

/** Page 1 succeeds, everything after it fails — a mid-walk truncation. */
function failAfterFirstPage<T>(dataset: T[]): Handler {
  return (options) =>
    Number(options?.params?.query?.page ?? 1) === 1 ? typedPage(dataset, options) : typedFailure();
}

/** Which page each request for `path` asked for, in call order. */
function pagesRequested(path: string): number[] {
  return mockApiGet.mock.calls
    .filter((c) => c[0] === path)
    .map((c) => Number((c[1] as TypedQuery | undefined)?.params?.query?.page ?? 1));
}

// ---------------------------------------------------------------------------
// The dialog
// ---------------------------------------------------------------------------

/** Grantee type is the first Select, the grantee itself the second. */
function granteeTypeTrigger(): HTMLElement {
  return screen.getAllByRole('combobox')[0];
}

function granteeTrigger(): HTMLElement {
  return screen.getAllByRole('combobox')[1];
}

function submitButton(): HTMLElement {
  return screen.getByRole('button', { name: /create delegation/i });
}

function renderModal() {
  render(<CreateDelegationModal isOpen onOpenChange={jest.fn()} onSuccess={jest.fn()} />);
}

/** Open a Radix Select and read back every option it offers. */
async function openAndListOptions(trigger: HTMLElement): Promise<string[]> {
  await userEvent.click(trigger);
  const options = await screen.findAllByRole('option');
  return options.map((o) => o.textContent ?? '');
}

/** Switch the grantee type, closing the type Select behind us. */
async function selectGranteeType(label: RegExp) {
  await userEvent.click(granteeTypeTrigger());
  await userEvent.click(await screen.findByRole('option', { name: label }));
}

/**
 * Wait for the load to settle. The dialog shows skeletons until every walk has
 * finished, so `pagesRequested()` can already read [1] while nothing is
 * rendered yet — the three Selects appearing is what says the state landed.
 */
async function awaitFormReady() {
  await waitFor(() => expect(screen.getAllByRole('combobox')).toHaveLength(3));
}

beforeEach(() => {
  jest.clearAllMocks();
});

// ---------------------------------------------------------------------------
// 1. The grantee pickers — the subject of the grant
// ---------------------------------------------------------------------------

describe('CreateDelegationModal grantee pickers', () => {
  it('offers roles that fall beyond the server default page size', async () => {
    // 48 roles: under the old bare GET the server returned 25 and ids 26-48
    // could not be delegated to at all.
    stubApi({ '/api/v1/roles': (o) => typedPage(makeRoles(48), o) });
    renderModal();

    await awaitFormReady();
    expect(pagesRequested('/api/v1/roles')).toEqual([1]);

    const options = await openAndListOptions(granteeTrigger());
    expect(options).toContain('Role 26');
    expect(options).toContain('Role 48');
    expect(options).toHaveLength(48);
  });

  it('offers roles past the server maximum page size', async () => {
    // 240 roles cannot arrive in one request at ANY page size, because the
    // server clamps per_page to 100.
    stubApi({ '/api/v1/roles': (o) => typedPage(makeRoles(240), o) });
    renderModal();

    await awaitFormReady();
    expect(pagesRequested('/api/v1/roles')).toEqual([1, 2, 3]);

    const options = await openAndListOptions(granteeTrigger());
    expect(options).toContain('Role 026');
    expect(options).toContain('Role 240');
    expect(options).toHaveLength(240);
  });

  it('offers users past the server maximum page size', async () => {
    stubApi({ '/api/v1/users': (o) => typedPage(makeUsers(240), o) });
    renderModal();

    await awaitFormReady();
    expect(pagesRequested('/api/v1/users')).toEqual([1, 2, 3]);
    await selectGranteeType(/^user$/i);

    const options = await openAndListOptions(granteeTrigger());
    expect(options).toContain('user-026@example.com');
    expect(options).toContain('user-240@example.com');
    expect(options).toHaveLength(240);
  });

  it('withholds the role list and blocks submission when a later page fails', async () => {
    stubApi({ '/api/v1/roles': failAfterFirstPage(makeRoles(240)) });
    renderModal();

    await waitFor(() =>
      expect(screen.getByText(/loaded only 100 of 240 roles/i)).toBeInTheDocument()
    );

    // The 100 roles that did arrive are not offered as if they were all of
    // them: the grantee is the subject of the grant, and picking the nearest
    // listed alternative hands real permissions to the wrong one.
    expect(granteeTrigger()).toBeDisabled();
    expect(
      screen.getByText(/hands your permissions to the wrong role or user/i)
    ).toBeInTheDocument();
    // Unlike the OU scope, there is no safe fallback grantee, so the dialog
    // cannot be completed either.
    expect(submitButton()).toBeDisabled();
  });

  it('states the failure without a total when even the first page fails', async () => {
    stubApi({ '/api/v1/users': () => typedFailure() });
    renderModal();

    await awaitFormReady();
    await selectGranteeType(/^user$/i);

    // No total arrived, so the size of the gap is unknown and the alert says
    // only that the fetch failed.
    expect(screen.getByText(/failed to fetch users/i)).toBeInTheDocument();
    expect(granteeTrigger()).toBeDisabled();
    expect(submitButton()).toBeDisabled();
  });

  it('refuses only the list that failed — the other grantee type stays usable', async () => {
    // Roles truncate, users are fine. Refusing both would remove a capability
    // that loaded correctly.
    stubApi({
      '/api/v1/roles': failAfterFirstPage(makeRoles(240)),
      '/api/v1/users': (o) => typedPage(makeUsers(48), o),
    });
    renderModal();

    await waitFor(() =>
      expect(screen.getByText(/loaded only 100 of 240 roles/i)).toBeInTheDocument()
    );
    // The type toggle itself is never disabled: switching it is a deliberate
    // change of subject KIND, not the near-miss substitution being guarded
    // against.
    expect(granteeTypeTrigger()).toBeEnabled();

    await selectGranteeType(/^user$/i);

    expect(screen.queryByText(/loaded only 100 of 240 roles/i)).not.toBeInTheDocument();
    expect(granteeTrigger()).toBeEnabled();
    expect(submitButton()).toBeEnabled();

    const options = await openAndListOptions(granteeTrigger());
    expect(options).toContain('user-26@example.com');
    expect(options).toHaveLength(48);
  });
});

// ---------------------------------------------------------------------------
// 2. The permission catalogue — the grant itself
// ---------------------------------------------------------------------------

describe('CreateDelegationModal permission list', () => {
  it('offers permissions past the cap the old per_page=100 workaround relied on', async () => {
    // 240 entries: exactly the case `per_page=100` still got wrong. Core ships a
    // large registry and every installed plugin adds to it, so this grows with
    // installed plugins rather than with tenant size.
    stubApi({ '/api/v1/permissions': (o) => typedPage(makePermissions(240), o) });
    renderModal();

    await awaitFormReady();
    expect(pagesRequested('/api/v1/permissions')).toEqual([1, 2, 3]);

    expect(screen.getByText('perm:101')).toBeInTheDocument();
    expect(screen.getByText('perm:240')).toBeInTheDocument();
    expect(screen.getAllByRole('checkbox')).toHaveLength(240);
  });

  it('withholds the catalogue and blocks the dialog when a later page fails', async () => {
    stubApi({ '/api/v1/permissions': failAfterFirstPage(makePermissions(240)) });
    renderModal();

    await waitFor(() =>
      expect(screen.getByText(/loaded only 100 of 240 permissions/i)).toBeInTheDocument()
    );

    // The permission set IS the grant, so a catalogue that cannot be vouched
    // for takes the whole dialog with it — there is no narrower-but-deliberate
    // fallback the way tenant-wide is for the OU scope.
    expect(screen.getByText(/no delegation can be created from a partial catalogue/i))
      .toBeInTheDocument();
    expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
    expect(submitButton()).toBeDisabled();
  });

  it('still reads as empty, not failed, when the catalogue is genuinely empty', async () => {
    // The distinction is the whole point: a walk that COMPLETED and found
    // nothing must not borrow the failure copy.
    stubApi({ '/api/v1/permissions': (o) => typedPage([], o) });
    renderModal();

    await waitFor(() =>
      expect(screen.getByText(/no permissions available/i)).toBeInTheDocument()
    );

    expect(
      screen.queryByText(/no delegation can be created from a partial catalogue/i)
    ).not.toBeInTheDocument();
    expect(submitButton()).toBeEnabled();
  });
});
