/**
 * Authoring a delivery stage on the canvas (#1054/#1064).
 *
 * A stage can TELL its people or ASK them, and it cannot do both — a decision
 * needs somebody holding the item to answer it, and a delivery stage closes
 * every item the moment it is sent. The server refuses that pair at three
 * layers (`DocumentRouter::validateSteps`, `RouteTemplateGraph::validateSteps`,
 * `RouteTemplateInstantiation`).
 *
 * So the canvas must not OFFER it. The editor already argues this for verdict
 * handles — "an affordance that produces a refusal is worse than no
 * affordance" — and the inspector already applies it one control up, clearing a
 * quorum when a stage stops being a gate so "the refusal is never reached
 * rather than reported". This is the same rule for the same reason.
 *
 * WHAT IS PINNED: that each switch disables the other, that turning one on says
 * WHY the other went grey, and that an ordinary stage still offers both. The
 * last is not padding — a guard that disabled the decision switch
 * unconditionally would pass the first two assertions and quietly remove
 * approvals from the product.
 *
 * The canvas is stubbed for the reason `route-flow-inspector-merge.test.tsx`
 * gives: `@xyflow/react` needs a layout engine jsdom does not have, and the
 * thing under test is the host's inspector rather than the canvas.
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

function step(position: number, overrides: Record<string, unknown> = {}) {
  return {
    position,
    rule_kind: 'role',
    rule_config: {},
    label: null,
    decision: false,
    decision_quorum: null,
    satisfied_by: 'act',
    canvas_x: 0,
    canvas_y: position * 160,
    ...overrides,
  };
}

const TEMPLATE = {
  id: 1,
  name: 'Circular',
  description: null,
  step_count: 3,
  created_by: null,
  created_at: '2026-01-01T00:00:00+00:00',
  updated_at: '2026-01-01T00:00:00+00:00',
  default_quorum: 'all' as const,
  max_steps: 20,
  steps: [
    step(1),
    step(2, { decision: true }),
    step(3, { satisfied_by: 'delivery' }),
  ],
  edges: [],
};

const RULES = [{ kind: 'role', label: 'Everyone holding a role', source: 'core' }];

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

async function selectStage(position: number) {
  const user = userEvent.setup();
  render(<RouteFlowEditorScreen templateId={1} canWrite />);
  await user.click(await screen.findByRole('button', { name: `select stage ${position}` }));
  return user;
}

const decisionSwitch = () => screen.getByRole('switch', { name: /Requires a decision/i });
const deliverySwitch = () => screen.getByRole('switch', { name: /Sends without asking/i });

describe('authoring a delivery stage', () => {
  it('offers both choices on an ordinary stage', async () => {
    // The control case, and the one a too-broad guard would break silently.
    await selectStage(1);

    expect(decisionSwitch()).toBeEnabled();
    expect(deliverySwitch()).toBeEnabled();
  });

  it('will not let a delivery stage also become a decision', async () => {
    await selectStage(3);

    expect(deliverySwitch()).toBeChecked();
    expect(decisionSwitch()).toBeDisabled();
  });

  it('says why the decision switch is unavailable', async () => {
    // A disabled control with no reason is a dead end. #951's convention across
    // this repo is that a refusal names itself.
    await selectStage(3);

    expect(
      screen.getByText(/asked for nothing.*cannot also be a decision/is)
    ).toBeInTheDocument();
  });

  it('will not let a decision stage also send without asking', async () => {
    await selectStage(2);

    expect(decisionSwitch()).toBeChecked();
    expect(deliverySwitch()).toBeDisabled();
    expect(
      screen.getByText(/cannot also send without asking/i)
    ).toBeInTheDocument();
  });
});
