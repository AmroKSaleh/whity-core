/**
 * #1041 — answering a decision step, and the one thing this screen must not get
 * wrong.
 *
 * THE FAILURE THESE TESTS EXIST FOR
 * ---------------------------------
 * The action response carries `decided` — what the STEP concluded — and it is
 * NULL while a quorum is still short. Under the default quorum of `all`, two of
 * three approvals conclude nothing.
 *
 * A panel that rendered the caller's OWN submitted verdict as the outcome would
 * typecheck, pass a smoke test, look right in the single-approver case that is
 * most of them, and tell the first two of three approvers that a document was
 * approved before it was — confidently, in the exact place a person goes to
 * check, to the two people least able to check it.
 *
 * So the three-approver case below is written as a SEQUENCE, and each of the
 * three is asserted twice: on the machine-readable `data-decided`, and on the
 * sentence a person actually reads. Mutating the panel to render `submitted`
 * instead of `decided` turns both red on the first two approvers.
 *
 * The response fixtures are what `DocumentRouter::decide()` really returns for a
 * cohort of three under `all`, and the arithmetic is the engine's, not a copy of
 * it kept here:
 *
 *   approvals 1, still able 2 → 1 >= 3? no.  1 + 2 < 3? no.  → null
 *   approvals 2, still able 1 → 2 >= 3? no.  2 + 1 < 3? no.  → null
 *   approvals 3, still able 0 → 3 >= 3? YES                  → 'approved'
 *
 * (`\Tests\Core\Document\Routing\RouteQuorumTest` asserts that table
 * exhaustively on the server; nothing here re-derives it.)
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
import type { DocumentRoute, RouteRecipient, RouteStep } from '@/components/documents/routing-wire';

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  });
}

function step(overrides: Partial<RouteStep> & { id: number; position: number }): RouteStep {
  return {
    rule_kind: 'role',
    rule_config: { role_id: 3 },
    label: null,
    decision: false,
    decision_quorum: null,
    ...overrides,
  };
}

/**
 * Two circulation steps and a GATE at position 3.
 *
 * The gate is last on purpose: it has no outgoing edge and still demands a
 * verdict, which is the case a panel that inferred "is this a decision?" from
 * the edges would render as an ordinary circulation.
 */
function decisionRoute(overrides: Partial<DocumentRoute> = {}): DocumentRoute {
  return {
    id: 5,
    document_id: 318,
    title: 'Purchase order 9912',
    created_by: 1,
    created_at: '2026-08-20T09:00:00Z',
    steps: [
      step({ id: 11, position: 1 }),
      step({ id: 12, position: 2 }),
      step({ id: 13, position: 3, label: 'Final sign-off', decision: true }),
    ],
    edges: [],
    default_quorum: 'all',
    ...overrides,
  };
}

function recipient(overrides: Partial<RouteRecipient> & { id: number }): RouteRecipient {
  return {
    document_id: 318,
    route_id: 5,
    step_id: 13,
    profile_id: 7,
    ou_id: null,
    parent_recipient_id: 4,
    created_by_event_id: 90,
    closed_by_event_id: null,
    open: true,
    created_at: '2026-08-20T09:00:00Z',
    ...overrides,
  };
}

/** The viewer alone on the gate. */
const SOLE_APPROVER = [recipient({ id: 1 })];

/**
 * Three people asked at once — one act, so one cohort. This is the shape the
 * feature exists for, in miniature: the real one is a rule that resolves to a
 * thousand instructors.
 */
const THREE_APPROVERS = [
  recipient({ id: 1, profile_id: 7 }),
  recipient({ id: 2, profile_id: 8 }),
  recipient({ id: 3, profile_id: 9 }),
];

function renderPanel(route: DocumentRoute, recipients: RouteRecipient[]) {
  return render(
    <RouteActPanel
      documentId={318}
      route={route}
      recipients={recipients}
      viewerProfileId={7}
      onActed={jest.fn()}
    />
  );
}

/** The outcome banner, or null if the panel is not making a claim. */
function outcome(): HTMLElement | null {
  return document.querySelector('[data-slot="route-act-outcome"]');
}

beforeEach(() => {
  jest.clearAllMocks();
});

// ---------------------------------------------------------------------------
// The affordances
// ---------------------------------------------------------------------------

describe('a decision step offers a verdict', () => {
  it('offers Approve and Reject', () => {
    renderPanel(decisionRoute(), SOLE_APPROVER);

    expect(screen.getByRole('button', { name: /approve/i })).toBeEnabled();
    expect(screen.getByRole('button', { name: /reject/i })).toBeEnabled();
    // And says a decision is what is being asked, rather than leaving the person
    // to infer it from which buttons happen to be present.
    // Matched on the half that is unique to the lede: the disabled Forward
    // carries the engine's own "Step 3 is a decision step: ..." sentence too,
    // and both saying it is the point rather than a collision to work around.
    expect(screen.getByText(/asking you to approve or reject/i)).toBeInTheDocument();
    expect(screen.getByText('Decision')).toBeInTheDocument();
  });

  it('posts `acknowledged` carrying the verdict — the act the engine accepts', async () => {
    mockApiClient.mockImplementation(() =>
      jsonResponse(201, { data: {}, resolved: 0, delivered: 0, decided: 'approved' })
    );

    renderPanel(decisionRoute(), SOLE_APPROVER);
    fireEvent.click(screen.getByRole('button', { name: /approve/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    const [url, options] = mockApiClient.mock.calls[0];
    expect(url).toBe('/api/v1/documents/318/routes/5/actions');
    expect(JSON.parse(String((options as RequestInit).body))).toEqual({
      action: 'acknowledged',
      verdict: 'approved',
    });
  });

  it('sends the reason with the verdict when one is typed', async () => {
    mockApiClient.mockImplementation(() =>
      jsonResponse(201, { data: {}, resolved: 0, delivered: 0, decided: 'rejected' })
    );

    renderPanel(decisionRoute(), SOLE_APPROVER);
    fireEvent.change(screen.getByLabelText(/reason/i), {
      target: { value: 'The budget line is wrong' },
    });
    fireEvent.click(screen.getByRole('button', { name: /reject/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    expect(JSON.parse(String((mockApiClient.mock.calls[0][1] as RequestInit).body))).toEqual({
      action: 'acknowledged',
      note: 'The budget line is wrong',
      verdict: 'rejected',
    });
  });

  it('shuts every control while an answer is in flight', async () => {
    // The trail has no update path and no delete path, so a second act posted
    // during the first one's round trip cannot be taken back. Approve and Reject
    // sit side by side and mean opposite things; disabling only the one that was
    // clicked left the other live for the length of the request.
    let release: ((v: unknown) => void) | undefined;
    mockApiClient.mockImplementation(
      () => new Promise((r) => { release = r; })
    );

    renderPanel(decisionRoute(), SOLE_APPROVER);
    fireEvent.click(screen.getByRole('button', { name: /approve/i }));

    await waitFor(() => expect(screen.getByRole('button', { name: /reject/i })).toBeDisabled());
    expect(screen.getByRole('button', { name: /approve/i })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Return to sender' })).toBeDisabled();
    // Exactly one request went out, and clicking the others adds none.
    fireEvent.click(screen.getByRole('button', { name: /reject/i }));
    fireEvent.click(screen.getByRole('button', { name: 'Return to sender' }));
    expect(mockApiClient).toHaveBeenCalledTimes(1);

    release?.({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: {}, resolved: 0, delivered: 0, decided: 'approved' }),
    });
    await waitFor(() => expect(outcome()).not.toBeNull());
  });

  it('refuses Forward WITH the engine’s own reason, rather than hiding it', () => {
    renderPanel(decisionRoute(), SOLE_APPROVER);

    const forward = screen.getByRole('button', { name: 'Forward' });
    expect(forward).toBeDisabled();
    // Disabled with its reason, twice — visibly and as an sr-only note, because
    // a native title on a disabled control is not reliably announced (#951).
    const reason = screen.getAllByText(
      /forwarding would let you choose the destination the step exists to decide/i
    );
    expect(reason).toHaveLength(2);
    expect(reason.some((node) => node.getAttribute('role') === 'note')).toBe(true);
  });

  it('does not offer a bare Acknowledge on a gate — the engine 422s it', () => {
    renderPanel(decisionRoute(), SOLE_APPROVER);

    // Approve and Reject ARE the acknowledge. A third button beside them would
    // look like a way to answer without deciding, and is in fact a refusal.
    expect(screen.queryByRole('button', { name: 'Acknowledge' })).not.toBeInTheDocument();
    // Return stays available on a gate and carries no verdict.
    expect(screen.getByRole('button', { name: 'Return to sender' })).toBeEnabled();
  });
});

// ---------------------------------------------------------------------------
// THE THREE-APPROVER CASE
// ---------------------------------------------------------------------------

describe('three approvers under a quorum of `all`', () => {
  /** Answer as one of the three, and hand back the panel's claim. */
  async function approveAndRead(decided: 'approved' | null, counts = { resolved: 0, delivered: 0 }) {
    mockApiClient.mockImplementation(() =>
      jsonResponse(201, { data: { verdict: 'approved' }, ...counts, decided })
    );

    renderPanel(decisionRoute(), THREE_APPROVERS);
    fireEvent.click(screen.getByRole('button', { name: /approve/i }));

    await waitFor(() => expect(outcome()).not.toBeNull());
    return outcome() as HTMLElement;
  }

  it('tells the FIRST approver their approval is recorded and the step is not', async () => {
    const banner = await approveAndRead(null);

    // THE SENTENCE FIRST, deliberately. A mutation that renders the caller's own
    // verdict must fail on what a PERSON reads, not on a machine attribute a
    // reader never sees and not on some incidental fixture line that would have
    // broken anyway — otherwise the test could be passing for a reason that has
    // nothing to do with the behaviour it is named for.
    //
    // It refuses to say the step did what the reader did…
    expect(banner.textContent).not.toMatch(/this step is approved/i);
    expect(banner.textContent).not.toMatch(/document has moved on/i);
    // …says instead what is actually true…
    expect(banner.textContent).toMatch(/not approved yet/i);
    expect(banner.textContent).toMatch(/still waiting on the other people/i);
    // …and names what the reader themselves did, which is the one approval that
    // HAS happened.
    expect(banner.textContent).toMatch(/your approval is recorded/i);
    // The machine-readable half of the same claim, last: a second lock, not the
    // one carrying the weight.
    expect(banner).toHaveAttribute('data-decided', 'pending');
  });

  it('says the same to the SECOND approver — two of three still concludes nothing', async () => {
    const banner = await approveAndRead(null);

    expect(banner.textContent).not.toMatch(/this step is approved/i);
    expect(banner.textContent).toMatch(/not approved yet/i);
    expect(banner).toHaveAttribute('data-decided', 'pending');
  });

  it('and only the THIRD is told the document is approved and has moved', async () => {
    const banner = await approveAndRead('approved', { resolved: 4, delivered: 4 });

    expect(banner).toHaveAttribute('data-decided', 'approved');
    expect(banner.textContent).toMatch(/this step is approved/i);
    expect(banner.textContent).toMatch(/document has moved on/i);
    // "the step it went to", never "the next step": an `approved` edge can point
    // anywhere in the route, and the response does not say which step it was.
    expect(banner.textContent).toMatch(/the step it went to/i);
    expect(banner.textContent).not.toMatch(/the next step/i);
    // Both counts, because they answer different questions.
    expect(banner.textContent).toContain('4');
    expect(banner.textContent).not.toMatch(/not approved yet/i);
  });

  it('does not tint a recorded-but-unsettled approval as a success', async () => {
    // The tint is read faster than the sentence. A green toast reading "not
    // approved yet" is the same false claim in a different medium.
    await approveAndRead(null);
    expect(addToast).toHaveBeenCalledTimes(1);
    const [message, kind] = addToast.mock.calls[0];
    expect(kind).toBe('info');
    expect(String(message)).toMatch(/not approved yet/i);
    expect(String(message)).not.toMatch(/this step is approved/i);
  });

  it('puts the answer ABOVE the controls, not below them', async () => {
    // Found by looking at the screen rather than at the DOM. Acting closes the
    // row, so the panel falls back to its no-open-item rendering — a heading
    // reading "Nothing on this route is awaiting you" over three disabled
    // controls — and the outcome was underneath all of it, off the bottom of a
    // 1000px viewport. The sentence a person came for cannot be the last thing
    // on the page.
    const banner = await approveAndRead(null);
    // Anchored on a control that is unambiguously part of the button row.
    const approve = document.querySelector('[data-slot="route-act-verdict-approved"]');

    expect(approve).not.toBeNull();
    const controlsComeAfter =
      banner.compareDocumentPosition(approve as Node) & Node.DOCUMENT_POSITION_FOLLOWING;
    expect(Boolean(controlsComeAfter)).toBe(true);
  });

  it('tints the settling approval as a success, and says so once', async () => {
    await approveAndRead('approved', { resolved: 1, delivered: 1 });
    expect(addToast).toHaveBeenCalledTimes(1);
    const [message, kind] = addToast.mock.calls[0];
    expect(kind).toBe('success');
    expect(String(message)).toMatch(/this step is approved/i);
  });
});

// ---------------------------------------------------------------------------
// Rejection, which is not the mirror image of approval
// ---------------------------------------------------------------------------

describe('rejecting', () => {
  it('does not report a rejection the step has not reached', async () => {
    // Under `any`, one refusal decides nothing while anybody can still approve.
    mockApiClient.mockImplementation(() =>
      jsonResponse(201, { data: { verdict: 'rejected' }, resolved: 0, delivered: 0, decided: null })
    );

    renderPanel(
      decisionRoute({ default_quorum: 'any' }),
      THREE_APPROVERS
    );
    fireEvent.click(screen.getByRole('button', { name: /reject/i }));

    await waitFor(() => expect(outcome()).not.toBeNull());
    const banner = outcome() as HTMLElement;

    expect(banner.textContent).not.toMatch(/can no longer be approved/i);
    expect(banner.textContent).toMatch(/not settled yet/i);
    expect(banner.textContent).toMatch(/your rejection is recorded/i);
    expect(banner).toHaveAttribute('data-decided', 'pending');
  });

  it('says the document ENDS HERE when the route draws no rejection path', async () => {
    // The asymmetry #1030 turns on: an approval with no edge continues to the
    // next ordinal, a rejection with no edge goes NOWHERE. A panel that reported
    // this the way it reports an approval would leave a person believing the
    // document had gone somewhere.
    mockApiClient.mockImplementation(() =>
      jsonResponse(201, { data: { verdict: 'rejected' }, resolved: 0, delivered: 0, decided: 'rejected' })
    );

    renderPanel(decisionRoute(), SOLE_APPROVER);
    fireEvent.click(screen.getByRole('button', { name: /reject/i }));

    await waitFor(() => expect(outcome()).not.toBeNull());
    const banner = outcome() as HTMLElement;

    expect(banner).toHaveAttribute('data-decided', 'rejected');
    // Scoped to the CHAIN, not to the document: other chains of this route, and
    // other routes on the same document, may still be open, and this panel
    // cannot see them.
    expect(banner.textContent).toMatch(/goes no further along this chain/i);
    expect(banner.textContent).not.toMatch(/the document ends here/i);
    expect(banner.textContent).toMatch(/never falls through to where an approval would have sent it/i);
  });

  it('reports where a drawn rejection path actually sent it', async () => {
    mockApiClient.mockImplementation(() =>
      jsonResponse(201, { data: { verdict: 'rejected' }, resolved: 2, delivered: 2, decided: 'rejected' })
    );

    renderPanel(decisionRoute(), SOLE_APPROVER);
    fireEvent.click(screen.getByRole('button', { name: /reject/i }));

    await waitFor(() => expect(outcome()).not.toBeNull());
    const banner = outcome() as HTMLElement;

    expect(banner.textContent).toMatch(/gone where the route sends a rejection/i);
    expect(banner.textContent).not.toMatch(/goes no further/i);
  });
});

// ---------------------------------------------------------------------------
// The quorum, and where it is worth showing
// ---------------------------------------------------------------------------

describe('the quorum', () => {
  it('is shown where the rule resolved to more than one person', () => {
    renderPanel(decisionRoute(), THREE_APPROVERS);

    const block = document.querySelector('[data-slot="route-act-quorum"]');
    expect(block).not.toBeNull();
    expect(block).toHaveAttribute('data-quorum', 'all');
    expect(screen.getByText(/put to 3 people at once/i)).toBeInTheDocument();
    expect(screen.getByText(/every one of them must approve/i)).toBeInTheDocument();
  });

  it('is NOT shown for a single approver, where all three rules are identical', () => {
    renderPanel(decisionRoute(), SOLE_APPROVER);
    expect(document.querySelector('[data-slot="route-act-quorum"]')).toBeNull();
  });

  it('says whose rule it is — the step’s own, or the tenant’s', () => {
    const inherited = renderPanel(decisionRoute(), THREE_APPROVERS);
    expect(screen.getByText(/this step does not override it/i)).toBeInTheDocument();
    inherited.unmount();

    const own = decisionRoute();
    own.steps[2] = { ...own.steps[2], decision_quorum: 'majority' };
    renderPanel(own, THREE_APPROVERS);

    expect(document.querySelector('[data-slot="route-act-quorum"]')).toHaveAttribute(
      'data-quorum',
      'majority'
    );
    expect(screen.getByText(/this step names that rule itself/i)).toBeInTheDocument();
    expect(screen.getByText(/more than half of them approve/i)).toBeInTheDocument();
  });

  it('offers no rejection quorum, because there is not one', () => {
    renderPanel(decisionRoute(), THREE_APPROVERS);
    const block = document.querySelector('[data-slot="route-act-quorum"]') as HTMLElement;
    // Rejection routing is derived: the reject edge fires when approval becomes
    // arithmetically unreachable. Offering a second control would be a rule that
    // does not exist, configurable into contradiction with the one that does.
    expect(block.textContent).not.toMatch(/rejection quorum/i);
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// A NULL verdict is not a pending decision
// ---------------------------------------------------------------------------

describe('a circulation step says nothing about approval', () => {
  const circulation = decisionRoute();
  const onStep2 = [recipient({ id: 1, step_id: 12 })];

  it('offers no verdict, and claims none is outstanding', () => {
    renderPanel(circulation, onStep2);

    expect(screen.queryByRole('button', { name: /^approve$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^reject$/i })).not.toBeInTheDocument();
    // No greyed-out approve, no "awaiting a verdict", no quorum — every act here
    // has verdict NULL and that has never meant "not approved".
    expect(document.querySelector('[data-slot="route-act-quorum"]')).toBeNull();
    expect(screen.queryByText(/decision/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/verdict/i)).not.toBeInTheDocument();
    // The ordinary vocabulary is intact, Forward included.
    expect(screen.getByRole('button', { name: 'Forward' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Acknowledge' })).toBeEnabled();
  });

  it('makes no approval claim after an ordinary acknowledge', async () => {
    // The engine returns `decided: null` here because nothing was decided —
    // which must not render as "not approved yet" on a step that takes no
    // verdict at all.
    mockApiClient.mockImplementation(() =>
      jsonResponse(201, { data: { verdict: null }, resolved: 0, delivered: 0, decided: null })
    );

    renderPanel(circulation, onStep2);
    fireEvent.click(screen.getByRole('button', { name: 'Acknowledge' }));

    await waitFor(() => expect(addToast).toHaveBeenCalled());
    expect(outcome()).toBeNull();
    expect(String(addToast.mock.calls[0][0])).not.toMatch(/approv/i);
  });
});

// ---------------------------------------------------------------------------
// The engine still has the last word
// ---------------------------------------------------------------------------

describe('a refused verdict', () => {
  it('surfaces the engine’s sentence verbatim and claims no outcome', async () => {
    mockApiClient.mockImplementation(() =>
      jsonResponse(422, {
        error:
          'Step 3 is a circulation step, so it takes no verdict — nothing in the route would act on one.',
      })
    );

    renderPanel(decisionRoute(), SOLE_APPROVER);
    fireEvent.click(screen.getByRole('button', { name: /approve/i }));

    await waitFor(() =>
      expect(screen.getByText(/so it takes no verdict/i)).toBeInTheDocument()
    );
    // And no outcome banner beside it. A refusal is not a recorded decision, and
    // a panel that showed both would be asserting a claim on top of a failure.
    expect(outcome()).toBeNull();
  });
});
