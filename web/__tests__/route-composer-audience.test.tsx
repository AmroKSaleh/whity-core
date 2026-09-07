/**
 * #1015 — the route composer can now author the two core kinds it could not.
 *
 * The defect these pin is specific and was reachable in three clicks: the kind
 * dropdown is filled from `GET /api/v1/routing-rules`, so `group` and `explicit`
 * appeared in it the moment #1003 landed, but the config editor only handled the
 * role kinds. Choosing "Everyone in a user group" therefore submitted
 * `rule_config: {}` and the server answered
 *
 *   Step 1: the 'group' rule needs a 'group_id' naming the user group whose
 *   people it reaches
 *
 * — the feature complete and unusable at the same time.
 *
 * So every case here asserts an OUTCOME the user can observe: what is actually
 * POSTed, whether Send is blocked and why, which request the preview makes. The
 * expected request bodies are written out by hand from the resolvers' documented
 * configs (`GroupRuleResolver` → `{group_id}`, `ExplicitRuleResolver` →
 * `{profile_ids}`), never derived by calling the same helpers the component
 * calls.
 */

import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 7 } }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import { RouteComposer } from '@/components/documents/route-composer';

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  });
}

/** The four kinds a stock install registers, in the order the API sorts them. */
const RULES = [
  { kind: 'explicit', label: 'Specific people, chosen by name', source: 'core' },
  { kind: 'group', label: 'Everyone in a user group', source: 'core' },
  { kind: 'role', label: 'Everyone holding a role', source: 'core' },
  { kind: 'role_below_actor', label: 'Everyone holding a role, in my unit and below', source: 'core' },
];

const GROUPS = [
  { id: 7, name: 'Instructors', description: 'Everyone holding the instructor role.' },
  { id: 9, name: 'Tender committee', description: null },
];

const PEOPLE = [
  { id: 11, name: 'Aisha Karim', secondary: 'aisha@demo.example.com' },
  { id: 12, name: 'Omar Haddad', secondary: 'omar@demo.example.com' },
];

const ROLES = [
  { id: 3, name: 'Registrar' },
  { id: 4, name: 'Instructor' },
];

beforeAll(() => {
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

beforeEach(() => {
  jest.clearAllMocks();
});

const onIssued = jest.fn();

function renderComposer(props: Partial<React.ComponentProps<typeof RouteComposer>> = {}) {
  return render(
    <RouteComposer
      documentId={318}
      documentTitle="Purchase order 9912"
      rules={RULES}
      roles={ROLES}
      rolesUnavailableReason={null}
      groups={GROUPS}
      groupsUnavailableReason={null}
      people={PEOPLE}
      peopleUnavailableReason={null}
      templates={[]}
      templatesUnavailableReason={null}
      onIssued={onIssued}
      onCancel={jest.fn()}
      {...props}
    />
  );
}

/** Choose a rule kind on step 1. */
function chooseKind(label: string): void {
  fireEvent.click(screen.getByLabelText('Rule'));
  fireEvent.click(screen.getByRole('option', { name: label }));
}

/** The JSON body of the one route-creation request that was made. */
function issuedBody(): unknown {
  const call = mockApiClient.mock.calls.find(
    ([url, options]) => url === '/api/v1/documents/318/routes' && options?.method === 'POST'
  );
  if (call === undefined) throw new Error('no route was created');
  return JSON.parse(call[1].body as string);
}

describe('RouteComposer — naming a user group', () => {
  it('sends the chosen group as the rule config, not an empty object', async () => {
    mockApiClient.mockImplementation((url: string) => {
      if (url === '/api/v1/user-groups/7/preview') {
        return jsonResponse(200, {
          data: {
            total: 2,
            truncated: false,
            sample_size: 10,
            sample: [
              { profile_id: 10, ou_id: 2, display_name: 'civil-technician' },
              { profile_id: 12, ou_id: 3, display_name: 'mechanical-technician' },
            ],
            resolved_for: { profile_id: 3, ou_id: null },
          },
        });
      }
      return jsonResponse(201, { data: { id: 5 }, resolved: 2, delivered: 2 });
    });

    renderComposer();
    chooseKind('Everyone in a user group');

    fireEvent.click(screen.getByLabelText('User group'));
    fireEvent.click(screen.getByRole('option', { name: 'Instructors' }));

    const submit = screen.getByRole('button', { name: /send document/i });
    await waitFor(() => expect(submit).toBeEnabled());
    fireEvent.click(submit);

    await waitFor(() => expect(onIssued).toHaveBeenCalled());

    // Written out from GroupRuleResolver's documented config, not derived.
    expect(issuedBody()).toEqual({
      // `satisfied_by` on every step since #1064 — see the note in
      // document-routing-actions.test.tsx for why it is sent even when it is
      // the value the server would have defaulted to.
      steps: [{ rule_kind: 'group', rule_config: { group_id: 7 }, satisfied_by: 'act' }],
    });
  });

  it('blocks Send WITH its reason until a group is named, instead of earning a 422', () => {
    mockApiClient.mockImplementation(() => jsonResponse(201, {}));
    renderComposer();
    chooseKind('Everyone in a user group');

    const submit = screen.getByRole('button', { name: /send document/i });
    expect(submit).toBeDisabled();
    // Visible and sr-only, the #951 shape.
    expect(screen.getAllByText(/step 1 still needs a rule and its setting/i)).toHaveLength(2);
    // And nothing was sent.
    expect(
      mockApiClient.mock.calls.filter(([url]) => url === '/api/v1/documents/318/routes')
    ).toHaveLength(0);
  });

  it('asks the server who the group reaches, and shows the count with its caveat', async () => {
    mockApiClient.mockImplementation((url: string) => {
      if (url === '/api/v1/user-groups/7/preview') {
        return jsonResponse(200, {
          data: {
            total: 1043,
            truncated: true,
            sample_size: 2,
            sample: [
              { profile_id: 10, ou_id: 2, display_name: 'Aisha Karim' },
              { profile_id: 12, ou_id: 3, display_name: null },
            ],
            resolved_for: { profile_id: 3, ou_id: null },
          },
        });
      }
      return jsonResponse(200, {});
    });

    renderComposer();
    chooseKind('Everyone in a user group');
    fireEvent.click(screen.getByLabelText('User group'));
    fireEvent.click(screen.getByRole('option', { name: 'Instructors' }));

    await waitFor(() =>
      expect(screen.getByText(/reaches 1043 people right now/i)).toBeInTheDocument()
    );

    // #999's endpoint, not a second preview mechanism invented here.
    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/user-groups/7/preview');
    // A sample, said to be a sample, with the id standing in for the name the
    // caller may not read.
    expect(screen.getByText(/a sample, not the whole set/i)).toBeInTheDocument();
    expect(screen.getByText('Profile #12')).toBeInTheDocument();
    // And the membership-is-not-stored caveat, on screen and not behind a click.
    expect(
      screen.getByText(/a group is a rule, not a saved list of people/i)
    ).toBeInTheDocument();
  });

  it('shows the server refusal when a preview cannot be resolved, and still lets the step stand', async () => {
    mockApiClient.mockImplementation((url: string) => {
      if (url === '/api/v1/user-groups/9/preview') {
        return jsonResponse(404, { error: 'User group not found' });
      }
      return jsonResponse(200, {});
    });

    renderComposer();
    chooseKind('Everyone in a user group');
    fireEvent.click(screen.getByLabelText('User group'));
    fireEvent.click(screen.getByRole('option', { name: 'Tender committee' }));

    await waitFor(() => expect(screen.getByText('User group not found')).toBeInTheDocument());
    // A failed PREVIEW is not a failed step: the group is chosen, so Send opens.
    expect(screen.getByRole('button', { name: /send document/i })).toBeEnabled();
  });

  it('explains why groups cannot be named rather than showing an empty picker', () => {
    renderComposer({
      groups: [],
      groupsUnavailableReason:
        'You cannot list user groups here, so a step cannot name one. An administrator would need to grant you groups:read.',
    });
    chooseKind('Everyone in a user group');

    expect(screen.getByText(/grant you groups:read/i)).toBeInTheDocument();
    expect(screen.queryByLabelText('User group')).not.toBeInTheDocument();
  });

  it('drops a group id when the step is switched to another kind', async () => {
    mockApiClient.mockImplementation(() => jsonResponse(200, { data: null }));
    renderComposer();

    chooseKind('Everyone in a user group');
    fireEvent.click(screen.getByLabelText('User group'));
    fireEvent.click(screen.getByRole('option', { name: 'Instructors' }));
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /send document/i })).toBeEnabled()
    );

    // A `group_id` surviving onto `role` would be sent as junk the role resolver
    // ignores — and would make an unconfigured step look complete.
    chooseKind('Everyone holding a role');
    expect(screen.getByRole('button', { name: /send document/i })).toBeDisabled();
  });
});

describe('RouteComposer — naming people outright', () => {
  it('sends the chosen profile ids, and refuses to send an empty set', async () => {
    mockApiClient.mockImplementation(() => jsonResponse(201, { data: { id: 6 }, resolved: 2, delivered: 2 }));

    renderComposer();
    chooseKind('Specific people, chosen by name');

    const submit = screen.getByRole('button', { name: /send document/i });
    // The `explicit` resolver refuses an empty list outright — "a set that names
    // nobody would resolve to nobody and still report success" — so the composer
    // must not let one leave.
    expect(submit).toBeDisabled();

    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'aisha' } });
    fireEvent.click(screen.getByRole('button', { name: /Aisha Karim/ }));
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'omar' } });
    fireEvent.click(screen.getByRole('button', { name: /Omar Haddad/ }));

    await waitFor(() => expect(submit).toBeEnabled());
    fireEvent.click(submit);
    await waitFor(() => expect(onIssued).toHaveBeenCalled());

    expect(issuedBody()).toEqual({
      steps: [
        { rule_kind: 'explicit', rule_config: { profile_ids: [11, 12] }, satisfied_by: 'act' },
      ],
    });
  });

  it('warns that a hand-picked step will not pick anybody up later', () => {
    mockApiClient.mockImplementation(() => jsonResponse(200, {}));
    renderComposer();
    chooseKind('Specific people, chosen by name');

    expect(screen.getByText(/will not pick up somebody who joins later/i)).toBeInTheDocument();
  });
});

describe('RouteComposer — a picker that is short is not a picker that is whole', () => {
  it('renders the partial-roles warning BESIDE a populated role picker', () => {
    mockApiClient.mockImplementation(() => jsonResponse(200, {}));
    // The regression: this sentence was passed in beside a populated `roles` and
    // rendered only in the EMPTY branch, so a truncated list looked complete.
    renderComposer({
      rolesIncompleteReason: 'Only some roles could be loaded, so this list may be incomplete.',
    });
    chooseKind('Everyone holding a role');

    expect(screen.getByLabelText('Role')).toBeInTheDocument();
    expect(screen.getByText(/only some roles could be loaded/i)).toBeInTheDocument();
  });

  it('still leaves a plugin kind for the engine to judge', () => {
    mockApiClient.mockImplementation(() => jsonResponse(200, {}));
    renderComposer({
      rules: [...RULES, { kind: 'acme:committee', label: 'Acme committee', source: 'acme' }],
    });
    chooseKind('Acme committee');

    expect(screen.getByText(/configured by the plugin that provides it/i)).toBeInTheDocument();
    // Not blocked by this client: only the plugin's validator knows.
    expect(screen.getByRole('button', { name: /send document/i })).toBeEnabled();
  });
});

describe('RouteComposer — mounted before the rule catalogue has arrived', () => {
  it('seeds its first step as soon as the kinds land, instead of opening empty forever', async () => {
    mockApiClient.mockImplementation(() => jsonResponse(200, {}));
    // Exactly what a host does when somebody clicks Send promptly after a page
    // load: the composer mounts while GET /api/v1/routing-rules is in flight.
    const { rerender } = renderComposer({ rules: [] });

    // While the catalogue is in flight the composer shows the no-rules state.
    expect(screen.getByText(/no routing rules registered/i)).toBeInTheDocument();

    rerender(
      <RouteComposer
        documentId={318}
        documentTitle="Purchase order 9912"
        rules={RULES}
        roles={ROLES}
        rolesUnavailableReason={null}
        groups={GROUPS}
        groupsUnavailableReason={null}
        people={PEOPLE}
        peopleUnavailableReason={null}
        templates={[]}
        templatesUnavailableReason={null}
        onIssued={onIssued}
        onCancel={jest.fn()}
      />
    );

    await waitFor(() => expect(screen.getByLabelText('Rule')).toBeInTheDocument());
    expect(screen.getByText('Step 1')).toBeInTheDocument();
  });

  it('does not put a step back after the author has removed every one', async () => {
    mockApiClient.mockImplementation(() => jsonResponse(200, {}));
    renderComposer();

    fireEvent.click(screen.getByRole('button', { name: /remove step/i }));

    await waitFor(() =>
      expect(screen.getAllByText(/a route needs at least one step/i).length).toBeGreaterThan(0)
    );
    expect(screen.queryByLabelText('Rule')).not.toBeInTheDocument();
  });
});

/**
 * A step that TELLS rather than ASKS (#1054/#1064).
 *
 * The linear path could not express this at all, so #1054's feature was
 * reachable only from the API or the canvas. It is the first per-step flag this
 * path has ever authored, and it earns that on the strength of what it changes:
 * a delivery step closes every item the instant it opens them, which is the
 * difference between a circulation waiting on forty acknowledgements and one
 * waiting on none.
 */
describe('RouteComposer — a step that sends without asking', () => {
  it('defaults to asking', async () => {
    // The default is the ordinary case and must stay it: a composer that
    // silently created delivery steps would close every recipient's item the
    // moment a route was issued, and nobody would be asked for anything.
    renderComposer();
    chooseKind('Everyone in a user group');
    fireEvent.click(screen.getByLabelText('User group'));
    fireEvent.click(screen.getByRole('option', { name: 'Instructors' }));

    const submit = screen.getByRole('button', { name: /send document/i });
    await waitFor(() => expect(submit).toBeEnabled());
    fireEvent.click(submit);

    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    expect(issuedBody()).toEqual({
      steps: [{ rule_kind: 'group', rule_config: { group_id: 7 }, satisfied_by: 'act' }],
    });
  });

  it('sends the choice to the server rather than relying on its default', async () => {
    renderComposer();
    chooseKind('Everyone in a user group');
    fireEvent.click(screen.getByLabelText('User group'));
    fireEvent.click(screen.getByRole('option', { name: 'Instructors' }));

    fireEvent.click(screen.getByRole('checkbox', { name: /Send without asking/i }));

    const submit = screen.getByRole('button', { name: /send document/i });
    await waitFor(() => expect(submit).toBeEnabled());
    fireEvent.click(submit);

    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    expect(issuedBody()).toEqual({
      steps: [{ rule_kind: 'group', rule_config: { group_id: 7 }, satisfied_by: 'delivery' }],
    });
  });

  it('says what the choice does, not just what it is called', async () => {
    // "Send without asking" alone is a setting name. What an author needs to
    // know is the consequence — that the item closes immediately and the route
    // moves on — because that is the part that surprises somebody who expected
    // to see acknowledgements come back.
    renderComposer();
    chooseKind('Everyone in a user group');

    expect(
      screen.getByText(/their item closes immediately, and the route continues/i)
    ).toBeInTheDocument();
  });
});
