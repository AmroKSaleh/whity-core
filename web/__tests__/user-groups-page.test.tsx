/**
 * #1015 — the User Groups admin screen.
 *
 * #999 shipped the group engine with no surface at all, so this screen is the
 * only way to make the thing the route composer's picker picks. The cases here
 * pin the claims that make it a GROUP screen rather than a membership screen —
 * each one is something a well-meaning change would break while the page still
 * rendered:
 *
 *  - a row shows a DEFINITION and never a member count (a count per row would
 *    resolve every rule on every render);
 *  - the kind picker is filled from `/api/v1/group-rules`, the subset that can
 *    answer without a document, which is what makes a group-of-groups
 *    impossible — NOT from `/api/v1/routing-rules`;
 *  - the preview goes to `POST /api/v1/user-groups/preview` (the draft one,
 *    gated on `groups:write`) and reports a snapshot, with the "a group is a
 *    rule" caveat attached;
 *  - Save is disabled WITH ITS REASON until the rule is actually configured,
 *    and the body it posts is the one the resolvers document.
 */

import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 3 } }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

const hasPermission = jest.fn<boolean, [string]>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ permissions: [], loading: false, hasPermission }),
}));

import UserGroupsPage from '@/app/(protected)/admin/user-groups/page';

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  });
}

const GROUP_ROW = {
  id: 1,
  tenant_id: 1,
  name: 'Technicians',
  description: 'Everyone holding the technician role',
  rule_kind: 'role',
  rule_config: { role_id: 6 },
  created_by: 3,
  created_at: '2026-08-24 15:05:57',
  updated_at: '2026-08-24 15:05:57',
};

/** The subset a GROUP may be defined as — `group` itself is absent, on purpose. */
const GROUP_RULES = [
  { kind: 'explicit', label: 'Specific people, chosen by name', source: 'core' },
  { kind: 'role', label: 'Everyone holding a role', source: 'core' },
  { kind: 'role_below_actor', label: 'Everyone holding a role, in my unit and below', source: 'core' },
];

const PAGINATION = { page: 1, perPage: 100, total: 1, totalPages: 1 };

/** Route every GET this page makes; POST/PATCH/DELETE fall through to `write`. */
function stubApi(
  options: {
    groups?: typeof GROUP_ROW[];
    rules?: typeof GROUP_RULES;
    write?: (url: string, init: RequestInit) => ReturnType<typeof jsonResponse>;
    preview?: unknown;
    previewStatus?: number;
  } = {}
) {
  const groups = options.groups ?? [GROUP_ROW];
  mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
    if (init?.method !== undefined && init.method !== 'GET') {
      if (url === '/api/v1/user-groups/preview') {
        return jsonResponse(options.previewStatus ?? 200, options.preview ?? null);
      }
      return options.write !== undefined
        ? options.write(url, init)
        : jsonResponse(200, { data: {} });
    }
    if (url.startsWith('/api/v1/user-groups')) {
      return jsonResponse(200, {
        data: groups,
        pagination: { ...PAGINATION, total: groups.length, totalPages: 1 },
      });
    }
    if (url === '/api/v1/group-rules') {
      return jsonResponse(200, { data: options.rules ?? GROUP_RULES });
    }
    if (url.startsWith('/api/v1/roles')) {
      return jsonResponse(200, {
        data: [
          { id: 3, name: 'admin' },
          { id: 6, name: 'demo-technician' },
        ],
        pagination: { ...PAGINATION, total: 2 },
      });
    }
    if (url.startsWith('/api/v1/users')) {
      return jsonResponse(200, {
        data: [
          { id: 11, name: 'Aisha Karim', email: 'aisha@demo.example.com' },
          { id: 12, name: 'Omar Haddad', email: 'omar@demo.example.com' },
        ],
        pagination: { ...PAGINATION, total: 2 },
      });
    }
    return jsonResponse(404, { error: `unexpected ${url}` });
  });
}

/** The parsed body of the first non-GET request to `url`. */
function writtenBody(url: string): unknown {
  const call = mockApiClient.mock.calls.find(
    ([called, init]) => called === url && init?.method !== undefined && init.method !== 'GET'
  );
  if (call === undefined) throw new Error(`nothing was written to ${url}`);
  return JSON.parse(call[1].body as string);
}

beforeAll(() => {
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

beforeEach(() => {
  jest.clearAllMocks();
  hasPermission.mockReturnValue(true);
});

describe('User Groups admin', () => {
  it('lists a group by its DEFINITION, with no member count anywhere', async () => {
    stubApi();
    render(<UserGroupsPage />);

    await waitFor(() => expect(screen.getByText('Technicians')).toBeInTheDocument());
    // The rule, rendered by its human label from /group-rules.
    expect(screen.getByText('Everyone holding a role')).toBeInTheDocument();
    // No count column, and nothing resolved just to draw the list: the only
    // requests made are the four catalogues.
    expect(screen.queryByRole('columnheader', { name: /members|people|count/i })).toBeNull();
    expect(
      mockApiClient.mock.calls.filter(([url]: [string]) => url.includes('/preview'))
    ).toHaveLength(0);
  });

  it('offers only the kinds a GROUP may be defined as — never `group` itself', async () => {
    stubApi();
    render(<UserGroupsPage />);
    await waitFor(() => expect(screen.getByText('Technicians')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /define a group/i }));
    fireEvent.click(await screen.findByLabelText('Who is in it'));

    expect(screen.getByRole('option', { name: 'Everyone holding a role' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Specific people, chosen by name' })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: /everyone in a user group/i })).toBeNull();
    // Read from the group catalogue, not the route-step one.
    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/group-rules');
    expect(
      mockApiClient.mock.calls.filter(([url]: [string]) => url === '/api/v1/routing-rules')
    ).toHaveLength(0);
  });

  it('blocks Save WITH its reason until the rule has its setting', async () => {
    stubApi();
    render(<UserGroupsPage />);
    await waitFor(() => expect(screen.getByText('Technicians')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /define a group/i }));
    const save = await screen.findByRole('button', { name: 'Save' });
    expect(save).toBeDisabled();
    expect(screen.getAllByText(/a group needs a name/i).length).toBeGreaterThan(0);

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Instructors' } });
    // Named, but the rule still has no role.
    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    expect(screen.getAllByText(/the rule still needs its setting/i).length).toBeGreaterThan(0);
  });

  it('previews the DRAFT rule through the endpoint gated on groups:write', async () => {
    stubApi({
      preview: {
        data: {
          total: 2,
          truncated: false,
          sample: [
            { profile_id: 10, ou_id: 2, display_name: 'civil-technician' },
            { profile_id: 12, ou_id: 3, display_name: null },
          ],
          resolved_for: { profile_id: 3, ou_id: null },
        },
      },
    });
    render(<UserGroupsPage />);
    await waitFor(() => expect(screen.getByText('Technicians')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /define a group/i }));
    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'Instructors' } });
    fireEvent.click(screen.getByLabelText('Who is in it'));
    fireEvent.click(screen.getByRole('option', { name: 'Everyone holding a role' }));
    fireEvent.click(screen.getByLabelText('Role'));
    fireEvent.click(screen.getByRole('option', { name: 'demo-technician' }));

    fireEvent.click(screen.getByRole('button', { name: /who is in this right now/i }));

    await waitFor(() => expect(screen.getByText(/reaches 2 people right now/i)).toBeInTheDocument());
    expect(writtenBody('/api/v1/user-groups/preview')).toEqual({
      rule_kind: 'role',
      rule_config: { role_id: 6 },
    });
    // Everybody, said so — and the id where a name could not be read.
    expect(screen.getByText(/that is everybody/i)).toBeInTheDocument();
    expect(screen.getByText('Profile #12')).toBeInTheDocument();
    // The caveat that stops the sample reading as a stored membership.
    expect(screen.getByText(/a group is a rule, not a saved list of people/i)).toBeInTheDocument();
  });

  it('defines a group with the body the resolver documents', async () => {
    stubApi({ write: () => jsonResponse(201, { data: { id: 2 } }) });
    render(<UserGroupsPage />);
    await waitFor(() => expect(screen.getByText('Technicians')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /define a group/i }));
    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'Instructors' } });
    fireEvent.click(screen.getByLabelText('Who is in it'));
    fireEvent.click(screen.getByRole('option', { name: 'Everyone holding a role' }));
    fireEvent.click(screen.getByLabelText('Role'));
    fireEvent.click(screen.getByRole('option', { name: 'demo-technician' }));

    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(addToast).toHaveBeenCalled());

    expect(writtenBody('/api/v1/user-groups')).toEqual({
      name: 'Instructors',
      description: null,
      rule_kind: 'role',
      rule_config: { role_id: 6 },
    });
  });

  it('surfaces the server’s refusal verbatim rather than a generic failure', async () => {
    stubApi({
      write: () =>
        jsonResponse(409, { error: "A user group called 'Technicians' already exists in this tenant" }),
    });
    render(<UserGroupsPage />);
    await waitFor(() => expect(screen.getByText('Technicians')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /define a group/i }));
    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'Technicians' } });
    fireEvent.click(screen.getByLabelText('Who is in it'));
    fireEvent.click(screen.getByRole('option', { name: 'Everyone holding a role' }));
    fireEvent.click(screen.getByLabelText('Role'));
    fireEvent.click(screen.getByRole('option', { name: 'demo-technician' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    expect(
      await screen.findByText("A user group called 'Technicians' already exists in this tenant")
    ).toBeInTheDocument();
  });

  it('warns that redefining an existing group changes who it reaches everywhere', async () => {
    stubApi();
    render(<UserGroupsPage />);
    await waitFor(() => expect(screen.getByText('Technicians')).toBeInTheDocument());

    // userEvent, not fireEvent: the Radix dropdown opens on real pointer events.
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /actions for technicians/i }));
    await user.click(await screen.findByRole('menuitem', { name: /edit/i }));

    expect(
      await screen.findByText(/including circulations already under way/i)
    ).toBeInTheDocument();
  });

  it('offers no write controls at all without groups:write', async () => {
    hasPermission.mockReturnValue(false);
    stubApi();
    render(<UserGroupsPage />);
    await waitFor(() => expect(screen.getByText('Technicians')).toBeInTheDocument());

    expect(screen.queryByRole('button', { name: /define a group/i })).toBeNull();
    expect(screen.queryByRole('button', { name: /actions for technicians/i })).toBeNull();
  });
});
