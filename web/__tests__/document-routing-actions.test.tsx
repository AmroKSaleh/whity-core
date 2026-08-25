/**
 * #978 — acting on a routed document, and sending one.
 *
 * These tests are written against the two semantics #989 calls load-bearing,
 * because both are things a UI can silently destroy while still rendering:
 *
 *  - DISTRIBUTION FANS OUT, IT DOES NOT BLOCK. So: no progress bar anywhere, and
 *    several steps may hold open items at once without that being an error.
 *  - A STEP SHOWS A RULE, NOT A ROSTER. So: one row per rule, and any list of
 *    people is capped with a count beside it.
 *
 * Plus the three refusal cases, which must be DISABLED WITH THEIR REASON rather
 * than hidden (#951) — each has a different cause and a hidden button renders all
 * three identically.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 7 } }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import { RouteActPanel } from '@/components/documents/route-act-panel';
import { RouteFanout, SAMPLE_LIMIT } from '@/components/documents/route-fanout';
import { RouteComposer } from '@/components/documents/route-composer';
import type { DocumentRoute, RouteRecipient } from '@/components/documents/routing-wire';

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  });
}

/**
 * Three CIRCULATION steps — `decision: false` throughout, which is what keeps
 * the "no Approve" assertion below meaningful after #1030 made an Approve button
 * legitimate elsewhere. The gate's own behaviour lives in
 * `document-routing-decision.test.tsx`.
 */
const ROUTE: DocumentRoute = {
  id: 5,
  document_id: 318,
  title: 'Purchase order 9912',
  created_by: 1,
  created_at: '2026-08-20T09:00:00Z',
  steps: [
    {
      id: 11, position: 1, rule_kind: 'role', rule_config: { role_id: 3 }, label: null,
      decision: false, decision_quorum: null,
    },
    {
      id: 12, position: 2, rule_kind: 'role_below_actor', rule_config: { role_id: 4 }, label: null,
      decision: false, decision_quorum: null,
    },
    {
      id: 13, position: 3, rule_kind: 'role', rule_config: { role_id: 9 }, label: 'Final sign-off',
      decision: false, decision_quorum: null,
    },
  ],
  edges: [],
  default_quorum: 'all',
};

function recipient(overrides: Partial<RouteRecipient> & { id: number }): RouteRecipient {
  return {
    document_id: 318,
    route_id: 5,
    step_id: 11,
    profile_id: 7,
    ou_id: null,
    parent_recipient_id: null,
    created_by_event_id: 1,
    closed_by_event_id: null,
    open: true,
    created_at: '2026-08-20T09:00:00Z',
    ...overrides,
  };
}

const ROLE_NAMES = new Map([
  [3, 'Registrar'],
  [4, 'Instructor'],
  [9, 'Dean'],
]);

beforeEach(() => {
  jest.clearAllMocks();
});

// ---------------------------------------------------------------------------
// Fan-out
// ---------------------------------------------------------------------------

describe('RouteFanout', () => {
  it('renders no progress bar — a fan-out has no single global position', () => {
    render(
      <RouteFanout
        route={ROUTE}
        recipients={[recipient({ id: 1 })]}
        profileNames={new Map([[7, 'Amal']])}
        roleNames={ROLE_NAMES}
        viewerProfileId={7}
      />
    );

    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
    // And no "step N of M" claim about the document as a whole.
    expect(screen.queryByText(/step 1 of 3/i)).not.toBeInTheDocument();
  });

  it('shows a step as a RULE, never as a roster', () => {
    render(
      <RouteFanout
        route={ROUTE}
        recipients={[]}
        profileNames={new Map()}
        roleNames={ROLE_NAMES}
        viewerProfileId={7}
      />
    );

    // One row naming the rule and the role it points at.
    expect(screen.getByText('Everyone holding Registrar')).toBeInTheDocument();
    expect(
      screen.getByText('Everyone holding Instructor, in the sender’s unit and below')
    ).toBeInTheDocument();
    // A step's own label wins over the derived description.
    expect(screen.getByText('Final sign-off')).toBeInTheDocument();
  });

  it('says a step is "not reached yet" rather than showing it at zero', () => {
    render(
      <RouteFanout
        route={ROUTE}
        recipients={[recipient({ id: 1 })]}
        profileNames={new Map()}
        roleNames={ROLE_NAMES}
        viewerProfileId={7}
      />
    );

    // Steps 2 and 3 have no rows at all: a different state from "reached, nobody
    // acted", which a zeroed bar would render identically.
    expect(screen.getAllByText('Not reached yet')).toHaveLength(2);
  });

  it('reports several live steps at once and says the branches are independent', () => {
    render(
      <RouteFanout
        route={ROUTE}
        // One person still holding step 1 while another already holds step 2 —
        // legitimate, and the thing a progress bar would misreport.
        recipients={[
          recipient({ id: 1, step_id: 11, profile_id: 7 }),
          recipient({ id: 2, step_id: 12, profile_id: 8, parent_recipient_id: 1 }),
        ]}
        profileNames={new Map([
          [7, 'Amal'],
          [8, 'Bilal'],
        ])}
        roleNames={ROLE_NAMES}
        viewerProfileId={7}
      />
    );

    expect(screen.getByText(/across 2 of this route’s steps/i)).toBeInTheDocument();
    expect(screen.getByText(/these branches move independently/i)).toBeInTheDocument();
    expect(
      screen.getByText(/no single step the document as a whole is/i)
    ).toBeInTheDocument();
  });

  it('caps a big fan-out with a count instead of rendering every person', () => {
    const many = Array.from({ length: 40 }, (_, i) =>
      recipient({ id: 100 + i, step_id: 11, profile_id: 200 + i })
    );

    const { container } = render(
      <RouteFanout
        route={ROUTE}
        recipients={many}
        profileNames={new Map()}
        roleNames={ROLE_NAMES}
        viewerProfileId={7}
      />
    );

    // The authoritative count is present…
    expect(screen.getByText('40 reached')).toBeInTheDocument();
    // …and the remainder is a count, not 40 rows.
    expect(screen.getByText(`+${40 - SAMPLE_LIMIT} more`)).toBeInTheDocument();

    // Each of the two views caps its own person rows independently — the step
    // summary and the branch list. Neither ever draws all 40, which is the whole
    // contract: a surface that renders 1,043 rows has recreated the problem the
    // rule exists to avoid.
    const stepSection = container.querySelector('[data-slot="route-fanout-step"]');
    expect(stepSection).not.toBeNull();
    expect(
      (stepSection as HTMLElement).querySelectorAll('li').length
    ).toBe(SAMPLE_LIMIT + 1); // the sample, plus the "+N more" row

    const branches = container.querySelectorAll('[data-slot="route-fanout-chain"]');
    expect(branches.length).toBe(SAMPLE_LIMIT);
  });

  it('says so when a route reached nobody', () => {
    render(
      <RouteFanout
        route={ROUTE}
        recipients={[]}
        profileNames={new Map()}
        roleNames={ROLE_NAMES}
        viewerProfileId={7}
      />
    );

    expect(screen.getByText(/this route reached nobody/i)).toBeInTheDocument();
  });

  it('falls back to the profile id rather than inventing a name', () => {
    render(
      <RouteFanout
        route={ROUTE}
        recipients={[recipient({ id: 1, profile_id: 42 })]}
        profileNames={new Map()}
        roleNames={ROLE_NAMES}
        viewerProfileId={7}
      />
    );

    expect(screen.getAllByText('Profile #42').length).toBeGreaterThan(0);
  });
});

// ---------------------------------------------------------------------------
// Acting
// ---------------------------------------------------------------------------

describe('RouteActPanel', () => {
  const renderPanel = (recipients: RouteRecipient[]) =>
    render(
      <RouteActPanel
        documentId={318}
        route={ROUTE}
        recipients={recipients}
        viewerProfileId={7}
        onActed={jest.fn()}
      />
    );

  it('offers exactly the engine’s recipient vocabulary — and no "Approve"', () => {
    renderPanel([recipient({ id: 1, step_id: 12, parent_recipient_id: 99 })]);

    expect(screen.getByRole('button', { name: 'Forward' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Acknowledge' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Return to sender' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add note' })).toBeInTheDocument();
    // Still no Approve, and the reason CHANGED with #1030 rather than going
    // away: approval is a verdict on a DECISION step, and this step is a
    // circulation one. Every act here has `verdict = null`, which has never
    // meant "not approved" — so there is nothing to approve, nothing to grey
    // out, and nothing to say about approval at all.
    expect(screen.queryByRole('button', { name: /approve/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /reject/i })).not.toBeInTheDocument();
    expect(document.querySelector('[data-slot="route-act-quorum"]')).toBeNull();
  });

  it('disables Forward WITH its reason on the last step', () => {
    renderPanel([recipient({ id: 1, step_id: 13, parent_recipient_id: 99 })]);

    const forward = screen.getByRole('button', { name: 'Forward' });
    expect(forward).toBeDisabled();
    // Not hidden — and the reason names what to do instead. It appears TWICE by
    // design: once visibly under the control, once as an `sr-only role="note"`,
    // because a native title on a disabled control is not reliably announced.
    const reason = screen.getAllByText(/this is the last step of the route.*acknowledge it instead/i);
    expect(reason).toHaveLength(2);
    expect(reason.some((node) => node.getAttribute('role') === 'note')).toBe(true);
    // Acknowledge stays available: it is legal at any step.
    expect(screen.getByRole('button', { name: 'Acknowledge' })).toBeEnabled();
  });

  it('disables Return WITH its reason at the first step', () => {
    renderPanel([recipient({ id: 1, step_id: 11, parent_recipient_id: null })]);

    expect(screen.getByRole('button', { name: 'Return to sender' })).toBeDisabled();
    expect(screen.getAllByText(/no earlier recipient to return it to/i)).toHaveLength(2);
    expect(screen.getByRole('button', { name: 'Forward' })).toBeEnabled();
  });

  it('disables all three WITH their reason when nothing is open for the viewer', () => {
    renderPanel([
      // Someone else's open item, plus the viewer's own CLOSED one.
      recipient({ id: 1, profile_id: 8 }),
      recipient({ id: 2, profile_id: 7, open: false, closed_by_event_id: 9 }),
    ]);

    expect(screen.getByRole('button', { name: 'Forward' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Acknowledge' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Return to sender' })).toBeDisabled();
    expect(screen.getAllByText(/you have no open item on this route/i).length).toBeGreaterThan(0);

    // EVERY control still carries the reason for assistive technology — that is
    // #951 and it is per-control by definition.
    const notes = screen
      .getAllByText(/you have no open item on this route/i)
      .filter((n) => n.getAttribute('role') === 'note');
    expect(notes).toHaveLength(3);

    // But the VISIBLE paragraph is printed ONCE, not once per button. All three
    // are denied for the same cause and the same 40-word sentence three times in
    // a row buried what the person had actually come to read (#1041). Repeating
    // a sentence does not make it more findable.
    const visible = screen
      .getAllByText(/you have no open item on this route/i)
      .filter((n) => n.tagName === 'P');
    expect(visible).toHaveLength(1);
  });

  it('never gives a DENIED control the primary fill', () => {
    // A solid primary button at 50% opacity still reads as the thing to press.
    // On a decision step that made "Forward" — the one act the engine refuses
    // there — the most eye-catching control on the panel.
    renderPanel([recipient({ id: 1, step_id: 13, parent_recipient_id: 99 })]);

    const forward = screen.getByRole('button', { name: 'Forward' });
    expect(forward).toBeDisabled();
    expect(forward).toHaveAttribute('data-variant', 'outline');
    // …while an available one keeps it.
    expect(screen.getByRole('button', { name: 'Acknowledge' })).toHaveAttribute(
      'data-variant',
      'default'
    );
  });

  it('still allows a note when nothing is open — the trail’s correction path', async () => {
    mockApiClient.mockImplementation(() => jsonResponse(201, { data: {}, resolved: 0, delivered: 0 }));

    renderPanel([recipient({ id: 2, profile_id: 7, open: false, closed_by_event_id: 9 })]);

    fireEvent.change(screen.getByLabelText(/note/i), { target: { value: 'Wrong unit named' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add note' }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    const [url, options] = mockApiClient.mock.calls[0];
    expect(url).toBe('/api/v1/documents/318/routes/5/actions');
    expect(JSON.parse(String((options as RequestInit).body))).toEqual({
      action: 'noted',
      note: 'Wrong unit named',
    });
  });

  it('reports BOTH counts after a forward', async () => {
    mockApiClient.mockImplementation(() =>
      jsonResponse(201, { data: {}, resolved: 1043, delivered: 1040 })
    );

    renderPanel([recipient({ id: 1, step_id: 11 })]);

    fireEvent.click(screen.getByRole('button', { name: 'Forward' }));

    await waitFor(() => expect(addToast).toHaveBeenCalled());
    const message = String(addToast.mock.calls[0][0]);
    expect(message).toContain('1043');
    expect(message).toContain('1040');
  });

  it('surfaces the engine’s refusal verbatim', async () => {
    mockApiClient.mockImplementation(() =>
      jsonResponse(422, {
        error:
          "Step 2 resolved to 1200 recipients, over this tenant's limit of 1000 for a single step. Narrow the rule, or raise documents.routing_max_recipients_per_step.",
      })
    );

    renderPanel([recipient({ id: 1, step_id: 11 })]);

    fireEvent.click(screen.getByRole('button', { name: 'Forward' }));

    await waitFor(() =>
      expect(
        screen.getByText(/raise documents\.routing_max_recipients_per_step/i)
      ).toBeInTheDocument()
    );
  });
});

// ---------------------------------------------------------------------------
// Composing
// ---------------------------------------------------------------------------

describe('RouteComposer', () => {
  const RULES = [
    { kind: 'role', label: 'Everyone holding a role', source: 'core' },
    { kind: 'role_below_actor', label: 'Everyone holding a role, in my unit and below', source: 'core' },
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

  const renderComposer = (props: Partial<React.ComponentProps<typeof RouteComposer>> = {}) =>
    render(
      <RouteComposer
        documentId={318}
        documentTitle="Purchase order 9912"
        rules={RULES}
        roles={ROLES}
        rolesUnavailableReason={null}
        groups={[]}
        groupsUnavailableReason={null}
        people={[]}
        peopleUnavailableReason={null}
        onIssued={jest.fn()}
        onCancel={jest.fn()}
        {...props}
      />
    );

  it('states the preview contract instead of offering a roster', () => {
    renderComposer();

    expect(screen.getByText(/each step names a RULE, not people/i)).toBeInTheDocument();
    expect(screen.getByText(/relative to whoever forwards it/i)).toBeInTheDocument();
    // No "who will this reach" affordance at all.
    expect(screen.queryByRole('button', { name: /preview|who will/i })).not.toBeInTheDocument();
  });

  it('blocks submission WITH its reason until a step names a role', () => {
    renderComposer();

    const submit = screen.getByRole('button', { name: /send document/i });
    expect(submit).toBeDisabled();
    // Carried both visibly and as an sr-only note, like every other #951 refusal.
    expect(screen.getAllByText(/step 1 still needs a rule and its setting/i)).toHaveLength(2);
  });

  it('explains why roles cannot be named rather than showing an empty picker', () => {
    renderComposer({
      roles: [],
      rolesUnavailableReason:
        'You cannot list roles here, so a rule cannot name one. An administrator would need to grant you roles:read.',
    });

    expect(screen.getByText(/an administrator would need to grant you roles:read/i)).toBeInTheDocument();
    // Not a dropdown with nothing in it.
    expect(screen.queryByRole('combobox', { name: /role/i })).not.toBeInTheDocument();
  });

  it('says so when the installation registers no rules at all', () => {
    renderComposer({ rules: [] });

    expect(screen.getByText(/no routing rules registered/i)).toBeInTheDocument();
  });

  it('sends steps as an ordered ARRAY of rule declarations', async () => {
    mockApiClient.mockImplementation(() =>
      jsonResponse(201, { data: { id: 5 }, resolved: 4, delivered: 4 })
    );

    renderComposer();

    // Choose a role for step 1 through the real picker.
    fireEvent.click(screen.getByRole('combobox', { name: /role/i }));
    fireEvent.click(await screen.findByRole('option', { name: 'Registrar' }));

    fireEvent.click(screen.getByRole('button', { name: /send document/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    const [url, options] = mockApiClient.mock.calls[0];
    expect(url).toBe('/api/v1/documents/318/routes');
    const body = JSON.parse(String((options as RequestInit).body));
    expect(Array.isArray(body.steps)).toBe(true);
    expect(body.steps).toEqual([{ rule_kind: 'role', rule_config: { role_id: 3 } }]);
    // Title omitted so the server falls back to the document's own.
    expect(body.title).toBeUndefined();
  });

  it('warns rather than celebrates when the first step resolved to nobody', async () => {
    mockApiClient.mockImplementation(() =>
      jsonResponse(201, { data: { id: 5 }, resolved: 0, delivered: 0 })
    );

    renderComposer();
    fireEvent.click(screen.getByRole('combobox', { name: /role/i }));
    fireEvent.click(await screen.findByRole('option', { name: 'Registrar' }));
    fireEvent.click(screen.getByRole('button', { name: /send document/i }));

    await waitFor(() => expect(addToast).toHaveBeenCalled());
    expect(String(addToast.mock.calls[0][0])).toMatch(/resolved to nobody/i);
    // Reported as a problem, not a success.
    expect(addToast.mock.calls[0][1]).toBe('error');
  });

  it('surfaces a step-level validation refusal verbatim', async () => {
    mockApiClient.mockImplementation(() =>
      jsonResponse(422, { error: "Step 1: the 'role' rule needs a 'role_id' naming the role its recipients hold" })
    );

    renderComposer();
    fireEvent.click(screen.getByRole('combobox', { name: /role/i }));
    fireEvent.click(await screen.findByRole('option', { name: 'Registrar' }));
    fireEvent.click(screen.getByRole('button', { name: /send document/i }));

    await waitFor(() =>
      expect(screen.getByText(/needs a 'role_id' naming the role/i)).toBeInTheDocument()
    );
  });
});
