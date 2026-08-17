/**
 * OU pickers — pagination exhaustion (#824).
 *
 * Three screens choose an organizational unit from `GET /api/v1/ous`, and all
 * three used to render page 1 alone. That is worse in a picker than it was in
 * the tree (#823): a tree at least draws something wrong, while a picker just
 * omits the option, so the operator concludes the unit does not exist. On a
 * 48-unit instance, 23 units could not be selected.
 *
 * These tests pin the same properties `ous-page-pagination.test.tsx` pins for
 * the tree, once per screen: later pages are fetched, the walk survives past
 * the server's `per_page` cap (so raising the page size would not have been a
 * fix), and a list that could not be completed is withheld and explained rather
 * than offered short.
 *
 * The real Select is driven rather than stubbed — a picker test that replaces
 * the picker proves the least interesting half — which is why the pointer-
 * capture shims below exist.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// ---------------------------------------------------------------------------
// Module mocks
// ---------------------------------------------------------------------------

const mockApiGet = jest.fn();
const mockApiPost = jest.fn();
const mockApiPatch = jest.fn();
const mockApiDelete = jest.fn();

jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => mockApiGet(...args),
    POST: (...args: unknown[]) => mockApiPost(...args),
    PATCH: (...args: unknown[]) => mockApiPatch(...args),
    DELETE: (...args: unknown[]) => mockApiDelete(...args),
  },
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

const hasPermission = jest.fn<boolean, [string]>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ permissions: [], loading: false, hasPermission }),
}));

jest.mock('@/hooks/useApproverCoverage', () => ({
  useApproverCoverage: () => ({ coverage: null }),
  wouldStrandTenant: () => false,
}));

// ---------------------------------------------------------------------------
// Imports under test (after all mocks)
// ---------------------------------------------------------------------------
import { EditUserModal } from '@/app/(protected)/admin/users/edit-modal';
import type { User } from '@/app/(protected)/admin/users/page';
import { CreateDelegationModal } from '@/app/(protected)/admin/delegations/create-modal';
import SecurityPolicySettingsPage from '@/app/(protected)/admin/settings/security/page';

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

interface Ou {
  id: number;
  tenant_id: number;
  parent_id: number | null;
  name: string;
  slug: string;
  description: string | null;
  created_at: string;
}

/**
 * `count` units, zero-padded so the ordinal is readable in a failure message
 * and so "Unit 048" is id 48 — past the default page boundary, which is where
 * the units the original report called "never created" begin.
 */
function makeOus(count: number): Ou[] {
  const width = String(count).length;
  return Array.from({ length: count }, (_, i) => ({
    id: i + 1,
    tenant_id: 1,
    parent_id: null,
    name: `Unit ${String(i + 1).padStart(width, '0')}`,
    slug: `unit-${i + 1}`,
    description: null,
    created_at: '2026-01-01T00:00:00Z',
  }));
}

type TypedQuery = { params?: { query?: { page?: number; per_page?: number } } };

/**
 * Serve a dataset the way the API does: a `data` slice plus a `pagination`
 * envelope, with `per_page` defaulted and clamped exactly as the server clamps
 * it. The clamp is the point — a client that trusts one request to return
 * everything is wrong no matter what page size it asks for.
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
        totalPages: Math.ceil(dataset.length / perPage),
      },
    },
    error: undefined,
    response: { ok: true, status: 200 },
  });
}

/**
 * The typed client's failure shape: it never throws, it leaves `data`
 * undefined. That is the only signal a caller gets, and mistaking it for an
 * empty page is exactly the truncation these screens used to ship.
 */
function typedFailure() {
  return Promise.resolve({
    data: undefined,
    error: { error: 'Internal Server Error' },
    response: { ok: false, status: 500 },
  });
}

/** Which page each `/api/v1/ous` request asked for, in call order. */
function ouPagesRequested(): number[] {
  return mockApiGet.mock.calls
    .filter((c) => c[0] === '/api/v1/ous')
    .map((c) => Number((c[1] as TypedQuery | undefined)?.params?.query?.page ?? 1));
}

/**
 * Answer every endpoint these screens touch, with `/api/v1/ous` delegated to
 * `ous` so each test decides how the OU walk behaves.
 */
function stubApi(ous: (options: TypedQuery | undefined) => Promise<unknown>) {
  mockApiGet.mockImplementation((path: string, options?: TypedQuery) => {
    switch (path) {
      case '/api/v1/ous':
        return ous(options);
      case '/api/v1/roles':
        return typedPage([{ id: 1, name: 'user' }, { id: 2, name: 'admin' }], options);
      case '/api/v1/users':
        return typedPage([{ id: 1, email: 'alice@example.com', role: 'admin', tenant_id: 1 }], options);
      case '/api/v1/permissions':
        return typedPage([{ id: 1, name: 'users:read', source: 'db' }], options);
      case '/api/v1/2fa-policies':
      case '/api/v1/2fa-policies/status':
      case '/api/v1/settings/tabs':
        return typedPage([], options);
      default:
        return Promise.resolve({
          data: undefined,
          error: { error: `unstubbed ${path}` },
          response: { ok: false, status: 404 },
        });
    }
  });
}

/** Page 1 succeeds, everything after it fails — a mid-walk truncation. */
function failAfterFirstPage(dataset: Ou[]) {
  return (options: TypedQuery | undefined) =>
    Number(options?.params?.query?.page ?? 1) === 1 ? typedPage(dataset, options) : typedFailure();
}

/** Open a Radix Select and read back every option it offers. */
async function openAndListOptions(trigger: HTMLElement): Promise<string[]> {
  await userEvent.click(trigger);
  const options = await screen.findAllByRole('option');
  return options.map((o) => o.textContent ?? '');
}

beforeEach(() => {
  jest.clearAllMocks();
  hasPermission.mockReturnValue(true);
});

// ---------------------------------------------------------------------------
// 1. The user → OU assignment picker (the most consequential of the three)
// ---------------------------------------------------------------------------

const mockUser: User = {
  id: 42,
  name: 'alice',
  email: 'alice@example.com',
  role: 'user',
  tenantId: 1,
  ou_id: null,
  createdAt: '2026-01-01T00:00:00Z',
};

/** Role is the first Select in the edit form, the OU picker is the second. */
function ouTrigger(): HTMLElement {
  return screen.getAllByRole('combobox')[1];
}

function renderEditUserModal() {
  render(<EditUserModal isOpen onOpenChange={jest.fn()} user={mockUser} onSuccess={jest.fn()} />);
}

describe('EditUserModal OU picker', () => {
  it('offers units that fall beyond the server default page size', async () => {
    // 48 units: the reporting instance. Under the old single request the server
    // returned 25 and ids 26-48 were unassignable.
    stubApi((options) => typedPage(makeOus(48), options));
    renderEditUserModal();

    await waitFor(() => expect(ouPagesRequested()).toEqual([1]));

    const options = await openAndListOptions(ouTrigger());
    expect(options).toContain('Unit 26');
    expect(options).toContain('Unit 48');
    // 48 units plus the "None (root)" sentinel.
    expect(options).toHaveLength(49);
  });

  it('offers units past the server maximum page size', async () => {
    // 240 units cannot arrive in one request at ANY page size, because the
    // server clamps per_page to 100 — the case a bare "?per_page=100" fix would
    // still have got wrong.
    stubApi((options) => typedPage(makeOus(240), options));
    renderEditUserModal();

    await waitFor(() => expect(ouPagesRequested()).toEqual([1, 2, 3]));

    const options = await openAndListOptions(ouTrigger());
    expect(options).toContain('Unit 240');
    expect(options).toHaveLength(241);
  });

  it('withholds the list and says so when a later page fails', async () => {
    stubApi(failAfterFirstPage(makeOus(240)));
    renderEditUserModal();

    await waitFor(() => expect(screen.getByText(/loaded only 100 of 240/i)).toBeInTheDocument());

    // The 100 units that did arrive must not be offered as if they were all of
    // them: choosing from a short list writes the wrong OU onto a real person.
    expect(ouTrigger()).toBeDisabled();
    expect(screen.getByText(/partial list would hide units that exist/i)).toBeInTheDocument();
  });

  it('withholds the list when even the first page fails', async () => {
    stubApi(() => typedFailure());
    renderEditUserModal();

    await waitFor(() =>
      expect(screen.getByText(/failed to fetch organizational units/i)).toBeInTheDocument()
    );

    expect(ouTrigger()).toBeDisabled();
    expect(addToast).toHaveBeenCalled();
  });
});

// ---------------------------------------------------------------------------
// 2. The delegation scope picker
// ---------------------------------------------------------------------------

/** Grantee type is the first Select, grantee the second, OU scope the third. */
function scopeTrigger(): HTMLElement {
  return screen.getAllByRole('combobox')[2];
}

function renderDelegationModal() {
  render(<CreateDelegationModal isOpen onOpenChange={jest.fn()} onSuccess={jest.fn()} />);
}

describe('CreateDelegationModal OU scope picker', () => {
  it('offers every unit past the server maximum page size', async () => {
    stubApi((options) => typedPage(makeOus(240), options));
    renderDelegationModal();

    await waitFor(() => expect(ouPagesRequested()).toEqual([1, 2, 3]));

    const options = await openAndListOptions(scopeTrigger());
    expect(options).toContain('Unit 026');
    expect(options).toContain('Unit 240');
    // 240 units plus the "Tenant-wide" sentinel.
    expect(options).toHaveLength(241);
  });

  it('refuses the scope picker and states the consequence when the walk fails', async () => {
    stubApi(failAfterFirstPage(makeOus(240)));
    renderDelegationModal();

    await waitFor(() => expect(screen.getByText(/loaded only 100 of 240/i)).toBeInTheDocument());

    expect(scopeTrigger()).toBeDisabled();
    // The fallback the form still offers is BROADER than an OU scope, so the
    // alert has to say so rather than leave an empty-looking dropdown.
    expect(screen.getByText(/grants more than an OU scope would/i)).toBeInTheDocument();
    // The dialog itself stays usable — a tenant-wide delegation is still a
    // complete, deliberate choice.
    expect(screen.getByRole('button', { name: /create delegation/i })).toBeEnabled();
  });
});

// ---------------------------------------------------------------------------
// 3. The 2FA policy scope picker
// ---------------------------------------------------------------------------

/** Open the policy form and switch its scope to "an organizational unit". */
async function openOuScopedPolicyForm() {
  await userEvent.click(await screen.findByTestId('add-two-factor-policy'));
  await userEvent.click(screen.getByTestId('policy-scope-type'));
  await userEvent.click(await screen.findByRole('option', { name: /an organizational unit/i }));
}

describe('2FA policy OU scope picker', () => {
  it('offers every unit past the server maximum page size', async () => {
    stubApi((options) => typedPage(makeOus(240), options));

    render(<SecurityPolicySettingsPage />);
    await waitFor(() => expect(ouPagesRequested()).toEqual([1, 2, 3]));
    await openOuScopedPolicyForm();

    const options = await openAndListOptions(screen.getByTestId('policy-scope-ou'));
    expect(options).toContain('Unit 026');
    expect(options).toContain('Unit 240');
    expect(options).toHaveLength(240);
  });

  it('withholds the list and says so when a later page fails', async () => {
    stubApi(failAfterFirstPage(makeOus(240)));

    render(<SecurityPolicySettingsPage />);
    await openOuScopedPolicyForm();

    const error = await screen.findByTestId('policy-scope-ou-error');
    expect(error).toHaveTextContent(/loaded only 100 of 240/i);
    expect(error).toHaveTextContent(/partial list would hide units that exist/i);

    // Enforcing 2FA on the wrong part of the organization is the failure here,
    // so the 100 units that arrived are not offered at all.
    expect(screen.getByTestId('policy-scope-ou')).toBeDisabled();
  });
});
