/**
 * #1031 — the route composer can now APPLY a design.
 *
 * THE DEFECT THIS PINS
 * --------------------
 * v0.2.8 shipped a node-based editor for authoring route templates and no way
 * to use one. The store, the canvas, the validator and the branching engine were
 * all correct, tested and complete; there was no button anywhere in the product
 * that turned a design into a running route. A feature nobody can reach is not
 * shipped, so the tests below assert REACHABILITY as much as correctness: the
 * tab exists, a design can be chosen, and pressing Send makes the one request
 * that applies it.
 *
 * WHAT IS ASSERTED, AND WHAT IS DELIBERATELY NOT
 * ----------------------------------------------
 * Every expectation is an observable outcome — which URL was called, what the
 * body contained, what is on screen. Nothing here calls `destinationFor` to
 * check what `destinationFor` should say: the "Approved → …" / "Rejected → …"
 * lines are written out by hand from #1014's three rules (a drawn edge wins; an
 * approval with no edge falls to the next stage; a rejection with no edge ENDS),
 * because a test that compares a function with a second copy of its own logic
 * passes on the day both copies are wrong.
 *
 * The conversion itself is NOT tested here and must not be: it happens on the
 * server, from the design's id, precisely so a client cannot flatten a branching
 * design into a linear one and have the result recorded as though the design
 * produced it. What this file checks is that the client sends the ID and no
 * steps.
 */

import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

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

const RULES = [
  { kind: 'explicit', label: 'Specific people, chosen by name', source: 'core' },
  { kind: 'group', label: 'Everyone in a user group', source: 'core' },
  { kind: 'role', label: 'Everyone holding a role', source: 'core' },
  { kind: 'role_below_actor', label: 'Everyone holding a role, in my unit and below', source: 'core' },
];

const ROLES = [{ id: 3, name: 'Registrar' }];

const TEMPLATES = [
  { id: 4, name: 'Purchase approval', description: 'Heads, then the dean.', step_count: 3 },
  { id: 9, name: 'Leave request', description: null, step_count: 2 },
];

/**
 * The design `GET /api/v1/document-route-templates/4` answers with.
 *
 * Chosen to carry the two shapes that break a naive reader:
 *
 *  - STAGE 3 IS A GATE WITH NO OUTGOING EDGE. A summary that decided what was a
 *    decision by looking at the edges would draw it as an ordinary circulation
 *    stage — the terminal-gate trap #1031's converter is written against, in its
 *    UI form.
 *  - STAGE 2'S REJECTION POINTS BACKWARDS, to stage 1. A reader that assumed
 *    edges run forwards would show "Rejected → the chain ends here", which is a
 *    different document from one that goes back to be fixed.
 */
const GRAPH_4 = {
  id: 4,
  name: 'Purchase approval',
  description: 'Heads, then the dean.',
  step_count: 3,
  default_quorum: 'all',
  max_steps: 20,
  steps: [
    {
      position: 1,
      rule_kind: 'role',
      rule_config: { role_id: 3 },
      label: 'Raise it',
      decision: false,
      decision_quorum: null,
    },
    {
      position: 2,
      rule_kind: 'role_below_actor',
      rule_config: { role_id: 3 },
      label: null,
      decision: true,
      decision_quorum: 'any',
    },
    {
      position: 3,
      rule_kind: 'role',
      rule_config: { role_id: 3 },
      label: 'The dean signs off',
      decision: true,
      decision_quorum: null,
    },
  ],
  edges: [{ from: 2, to: 1, verdict: 'rejected' }],
};

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
      groups={[]}
      groupsUnavailableReason={null}
      people={[]}
      peopleUnavailableReason={null}
      templates={TEMPLATES}
      templatesUnavailableReason={null}
      onIssued={onIssued}
      onCancel={jest.fn()}
      {...props}
    />
  );
}

/**
 * Switch to the template tab.
 *
 * `userEvent`, not `fireEvent.click`: a Radix tab trigger activates on the
 * pointer-down half of a real click, so a bare click event leaves the tab
 * inactive and every assertion after it fails for a reason that has nothing to
 * do with the code under test.
 */
async function openTemplateTab(): Promise<void> {
  await userEvent.setup().click(screen.getByRole('tab', { name: /start from a template/i }));
}

/** Switch to the template tab and choose the "Purchase approval" design. */
async function chooseTemplate(): Promise<void> {
  await openTemplateTab();
  fireEvent.click(screen.getByLabelText('Route template'));
  fireEvent.click(screen.getByRole('option', { name: /purchase approval/i }));
  await waitFor(() => expect(screen.getByText(/the dean signs off/i)).toBeInTheDocument());
}

/** The body of the one apply request that was made. */
function appliedBody(): unknown {
  const call = mockApiClient.mock.calls.find(
    ([url, options]) =>
      url === '/api/v1/documents/318/routes/from-template' && options?.method === 'POST'
  );
  if (call === undefined) throw new Error('no template was applied');
  return JSON.parse(call[1].body as string);
}

describe('RouteComposer — applying a route template', () => {
  it('offers the template tab beside the step list rather than instead of it', () => {
    // Reachability, asserted first because it is the whole bug. Both ways in are
    // visible at once; the step list stays the default, so an install with no
    // designs is unaffected.
    renderComposer();

    expect(screen.getByRole('tab', { name: /step by step/i })).toHaveAttribute(
      'data-state',
      'active'
    );
    expect(screen.getByRole('tab', { name: /start from a template/i })).toBeInTheDocument();
  });

  it('reads the chosen design and shows every stage, gates included', async () => {
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/document-route-templates/4'
        ? jsonResponse(200, { data: GRAPH_4 })
        : jsonResponse(200, { data: {} })
    );

    renderComposer();
    await chooseTemplate();

    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/document-route-templates/4');
    expect(screen.getByText(/Stage 1/)).toBeInTheDocument();
    expect(screen.getByText(/Stage 2/)).toBeInTheDocument();
    expect(screen.getByText(/Stage 3/)).toBeInTheDocument();
    // The rule is always shown, never only the author's label — a stage named
    // "Raise it" that resolves to nobody looks identical to one that works.
    expect(screen.getByText(/Raise it — Everyone holding a role/)).toBeInTheDocument();
  });

  it('draws a TERMINAL gate as a gate, which no edge could have told it', async () => {
    // Stage 3 is a decision with no outgoing edge. This is the UI form of the
    // trap the server-side converter is written against: infer "is this a gate"
    // from the edges and this stage silently becomes an ordinary circulation
    // step — on screen and, if the same mistake were made server-side, in the
    // route itself.
    mockApiClient.mockImplementation(() => jsonResponse(200, { data: GRAPH_4 }));

    renderComposer();
    await chooseTemplate();

    // Two gates, and stage 1 is not one of them.
    expect(screen.getAllByText(/^Decision:/)).toHaveLength(2);
    expect(screen.getByText(/Decision: any one approval carries it/)).toBeInTheDocument();
    // Stage 3 has no quorum of its own, so it follows the tenant's — `all` here.
    expect(screen.getByText(/Decision: everyone must approve/)).toBeInTheDocument();
  });

  it('says where each verdict leads, including a rejection that goes BACKWARDS', async () => {
    mockApiClient.mockImplementation(() => jsonResponse(200, { data: GRAPH_4 }));

    renderComposer();
    await chooseTemplate();

    // Stage 2: no approve edge, so an approval falls through to the next stage
    // in order; the reject edge points back to stage 1.
    expect(screen.getByText(/Approved → stage 3 · Rejected → stage 1/)).toBeInTheDocument();
    // Stage 3: last stage, no edges. An approval has nowhere left to go, and a
    // rejection NEVER falls through to where an approval would have gone.
    expect(
      screen.getByText(/Approved → the chain ends here · Rejected → the chain ends here/)
    ).toBeInTheDocument();
  });

  it('applies the design by ID and sends no steps of its own', async () => {
    // The load-bearing request assertion. A client that loaded the design into
    // the step list and posted that would drop stage 2's branch on the floor —
    // the step list has no control that can express one — and the route would be
    // recorded as having followed a design it does not match.
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/document-route-templates/4'
        ? jsonResponse(200, { data: GRAPH_4 })
        : jsonResponse(201, {
            data: { id: 55, template_id: 4, template_name: 'Purchase approval' },
            resolved: 3,
            delivered: 3,
          })
    );

    renderComposer();
    await chooseTemplate();
    fireEvent.click(screen.getByRole('button', { name: /send document/i }));

    await waitFor(() => expect(onIssued).toHaveBeenCalled());
    expect(appliedBody()).toEqual({ template_id: 4 });
    expect(
      mockApiClient.mock.calls.some(([url]) => url === '/api/v1/documents/318/routes')
    ).toBe(false);
  });

  it('sends a typed route name and omits a blank one so the design names it', async () => {
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/document-route-templates/4'
        ? jsonResponse(200, { data: GRAPH_4 })
        : jsonResponse(201, { data: { id: 55 }, resolved: 1, delivered: 1 })
    );

    renderComposer();
    await chooseTemplate();
    fireEvent.change(screen.getByLabelText(/route name/i), {
      target: { value: '  Q3 stationery  ' },
    });
    fireEvent.click(screen.getByRole('button', { name: /send document/i }));

    await waitFor(() => expect(onIssued).toHaveBeenCalled());
    expect(appliedBody()).toEqual({ template_id: 4, title: 'Q3 stationery' });
  });

  it('blocks Send WITH its reason until a design is chosen', async () => {
    renderComposer();
    await openTemplateTab();

    const submit = await screen.findByRole('button', { name: /send document/i });
    expect(submit).toBeDisabled();
    // TWICE on purpose, and asserted as such: once visibly beside the button and
    // once in an `sr-only` note, because a disabled control whose reason is only
    // visual is a dead end for anybody using a screen reader (#951).
    expect(screen.getAllByText(/choose a route template to apply/i)).toHaveLength(2);
  });

  it('renders the engine refusal verbatim rather than re-keying it', async () => {
    // The server's 422s name the stage and the setting to raise — "over this
    // tenant's limit of 2. Raise documents.routing_max_steps…". Re-keying one
    // would lose the number and the setting name, which is the whole of what an
    // author can act on.
    const refusal =
      'This route declares 3 steps, over this tenant’s limit of 2. Raise ' +
      'documents.routing_max_steps if the route genuinely needs them.';
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/document-route-templates/4'
        ? jsonResponse(200, { data: GRAPH_4 })
        : jsonResponse(422, { error: refusal })
    );

    renderComposer();
    await chooseTemplate();
    fireEvent.click(screen.getByRole('button', { name: /send document/i }));

    await waitFor(() => expect(screen.getByText(refusal)).toBeInTheDocument());
    expect(onIssued).not.toHaveBeenCalled();
  });

  it('explains why there is no picker rather than showing an empty one', async () => {
    // #756: an empty state, never invented content — and never a dropdown that
    // reads as a loading state which never resolves. Somebody who may route a
    // document need not hold `route_templates:read`.
    renderComposer({
      templates: [],
      templatesUnavailableReason:
        'You cannot read this tenant’s route templates, so a design cannot be applied here.',
    });
    await openTemplateTab();

    expect(screen.getByText(/you cannot read this tenant’s route templates/i)).toBeInTheDocument();
    expect(screen.queryByLabelText('Route template')).not.toBeInTheDocument();
  });

  it('says so when the install simply has no designs yet', async () => {
    renderComposer({ templates: [], templatesUnavailableReason: null });
    await openTemplateTab();

    expect(screen.getByText(/no route templates have been designed here yet/i)).toBeInTheDocument();
  });

  it('states that the copy is a snapshot but the recipients are not', async () => {
    // The two halves are opposites and both are true, which is exactly why the
    // sentence is on screen: the STAGES are frozen at apply time, and WHO each
    // stage reaches is not. An author told only the first would expect a stale
    // roster; told only the second, they would expect a redraw to move a
    // circulation already under way.
    mockApiClient.mockImplementation(() => jsonResponse(200, { data: GRAPH_4 }));

    renderComposer();
    await chooseTemplate();

    expect(screen.getByText(/will not move a circulation already under way/i)).toBeInTheDocument();
    expect(screen.getByText(/worked out when the document gets there/i)).toBeInTheDocument();
  });
});
