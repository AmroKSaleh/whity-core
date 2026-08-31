/**
 * #1058 — the merge fact, said where there is room to say it truthfully.
 *
 * WHAT THIS PINS
 * --------------
 * The canvas marks a converging stage "Paths merge — 1 item per person". That
 * is true in every case and has to be, because notes are clamped to
 * `ROUTE_FLOW_MAX_NOTES` lines and a longer one pushes a later note off the card
 * (#1042). What thirty-one characters cannot carry is which case an author is
 * actually in, which is the half #1058 asks for. So the consequence is stated in
 * the STAGE INSPECTOR, and this file holds that it appears on the merging stage
 * and only on it.
 *
 * The graph below is the one from the issue, and the same one
 * `route-flow-model.test.ts` asserts `merges` to be `[4]` over and
 * `RouteTemplateInstantiationRealEngineTest` drives a real document through:
 * stage 2 is a gate whose approval jumps to 4 and whose rejection goes to the
 * rework stage 3, which continues into 4 by position. Two arrivals at stage 4.
 *
 * WHY THE CANVAS IS STUBBED. `RouteFlowEditor` is `@xyflow/react`, which needs a
 * layout engine jsdom does not have — and the thing under test is not the
 * canvas. The split this screen's docblock describes is exactly the seam: the
 * canvas REPORTS which stage is selected, and the host decides what that means.
 * The stub reports a selection, which is the whole of the contract this test
 * needs, and it is the same substitution `block-renderer-flow.test.tsx` makes
 * for the read-only flow block.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: (...args: unknown[]) => mockApiClient(...args) }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('@/lib/direction-context', () => ({ useDirection: () => ({ dir: 'ltr' }) }));

// The canvas, reduced to the one thing the host consumes from it: which stage is
// selected. `appendStep` is passed through unchanged — the screen imports it
// from the same module and this file never adds a stage.
jest.mock('@amroksaleh/ui/route-flow/editor', () => ({
  __esModule: true,
  RouteFlowEditor: ({
    graph,
    onSelectStep,
  }: {
    graph: { steps: Array<{ position: number }> };
    onSelectStep?: (position: number | null) => void;
  }) => (
    <div>
      {graph.steps.map((step) => (
        <button key={step.position} onClick={() => onSelectStep?.(step.position)}>
          {`select stage ${step.position}`}
        </button>
      ))}
    </div>
  ),
  appendStep: (graph: unknown) => graph,
}));

import { RouteFlowEditorScreen } from '@/app/(protected)/admin/document-route-templates/[id]/flow-editor-screen';

/** A stage naming a rule with NO config yet, so no audience preview is asked for. */
function step(position: number, decision = false) {
  return {
    position,
    rule_kind: 'role',
    rule_config: {},
    label: null,
    decision,
    decision_quorum: null,
    canvas_x: 0,
    canvas_y: position * 160,
  };
}

const TEMPLATE = {
  id: 1,
  name: 'Converging design',
  description: null,
  step_count: 4,
  created_by: null,
  created_at: '2026-01-01T00:00:00+00:00',
  updated_at: '2026-01-01T00:00:00+00:00',
  default_quorum: 'all' as const,
  max_steps: 20,
  steps: [step(1), step(2, true), step(3), step(4, true)],
  // Stage 2 approves straight into 4; its rejection goes to 3, which continues
  // into 4 by authoring ordinal. Two arrivals at 4, one of them derived.
  edges: [
    { from: 2, to: 4, verdict: 'approved' as const },
    { from: 2, to: 3, verdict: 'rejected' as const },
  ],
};

const RULES = [
  { kind: 'role', label: 'Everyone holding a role', source: 'core' },
  { kind: 'role_below_actor', label: 'Everyone holding a role, in my unit and below', source: 'core' },
];

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  });
}

beforeEach(() => {
  mockApiClient.mockReset();
  mockApiClient.mockImplementation((url: string) => {
    if (url.startsWith('/api/v1/document-route-templates/')) return jsonResponse(200, { data: TEMPLATE });
    if (url.startsWith('/api/v1/routing-rules')) return jsonResponse(200, { data: RULES });
    if (url.startsWith('/api/v1/roles')) return jsonResponse(200, { data: [] });
    if (url.startsWith('/api/v1/user-groups')) return jsonResponse(200, { data: [] });
    throw new Error(`unexpected request: ${url}`);
  });
});

const MERGE_NOTE = /More than one path reaches this stage/;

describe('the stage inspector on a converging design', () => {
  it('states what a merge means on the stage two paths reach', async () => {
    const user = userEvent.setup();
    render(<RouteFlowEditorScreen templateId={1} canWrite />);

    await user.click(await screen.findByRole('button', { name: 'select stage 4' }));

    expect(screen.getByText(MERGE_NOTE)).toBeInTheDocument();
    // The claim is about arrivals, not about settlement: it names the
    // de-duplication rule and both of the things that defeat it. "Settles once"
    // was the wording #1058 is about, and nothing here may say it again.
    expect(screen.getByText(MERGE_NOTE).textContent).toContain('one open item per person');
  });

  it('says nothing about merging on a stage only one path reaches', async () => {
    const user = userEvent.setup();
    render(<RouteFlowEditorScreen templateId={1} canWrite />);

    // 3 is reached only by stage 2's rejection, and 1 by nothing at all.
    await user.click(await screen.findByRole('button', { name: 'select stage 3' }));
    expect(screen.queryByText(MERGE_NOTE)).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'select stage 1' }));
    expect(screen.queryByText(MERGE_NOTE)).not.toBeInTheDocument();
  });
});
