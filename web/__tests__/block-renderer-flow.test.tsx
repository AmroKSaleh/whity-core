/**
 * #950: the `flow` graph block (web renderer).
 *
 * The tests are split the way the code is. `buildFlowModel` holds every decision
 * the CONTRACT makes — which rows become nodes, which references become edges,
 * where the graph is cut — so it is pinned directly, without a canvas. The
 * renderer tests then pin the seam: which model reached the canvas, which
 * affordances went with it, and that truncation is SAID rather than silently
 * applied.
 *
 * react-flow is not exercised here. It is loaded by `next/dynamic` behind a
 * stub, because a canvas in jsdom measures nothing and asserting on it would
 * pin an empty SVG, not behaviour. What is worth pinning about the canvas is
 * the model it is handed, which is exactly what the stub reports.
 */

import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { BlockRenderer } from '@/components/plugin/blocks/block-renderer';
import { buildFlowModel } from '@/components/plugin/blocks/flow-model';
import { FLOW_MAX_NODES, type Block, type FlowBlock } from '@/lib/plugin-features';
import { apiClient } from '@/lib/api-client';
import { ToastProvider } from '@/lib/toast-context';

jest.mock('@/lib/api-client', () => ({ apiClient: jest.fn() }));

// The canvas stands in for react-flow and reports what it was given: the nodes
// in order, the edges, and whether the block gave a node anything to do. Every
// assertion below is about the model, so the stub is the observation point.
jest.mock('@/components/plugin/blocks/flow-canvas', () => ({
  __esModule: true,
  default: ({
    model,
    orientation,
    onActivate,
    renderNodeActions,
  }: {
    model: { nodes: { id: string; label: string; subtitle: string; depth: number; row: Record<string, string> }[]; edges: { source: string; target: string }[] };
    orientation?: string;
    onActivate?: (row: Record<string, string>) => void;
    renderNodeActions?: (row: Record<string, string>) => React.ReactNode;
  }) => (
    <div data-testid="flow-canvas" data-orientation={orientation ?? 'unset'}>
      <ul>
        {model.nodes.map((node) => (
          <li key={node.id} data-testid={`node-${node.id}`} data-depth={node.depth}>
            <button type="button" onClick={() => onActivate?.(node.row)} disabled={onActivate === undefined}>
              {node.label}
            </button>
            <span>{node.subtitle}</span>
            {renderNodeActions?.(node.row)}
          </li>
        ))}
      </ul>
      <p data-testid="flow-edges">{model.edges.map((e) => `${e.source}>${e.target}`).join(' ')}</p>
    </div>
  ),
}));

const mockApiClient = apiClient as jest.MockedFunction<typeof apiClient>;

function stubResponse(body: unknown): Response {
  return { ok: true, status: 200, json: () => Promise.resolve(body) } as unknown as Response;
}

/** An expense route that BRANCHES, which is the case worth drawing. */
const STEPS = [
  { id: 'submitted', name: 'Submitted', owner: 'Requester', next: ['review'] },
  { id: 'review', name: 'Manager review', owner: 'Line manager', next: ['finance', 'rejected'] },
  { id: 'finance', name: 'Finance approval', owner: 'Finance team', next: ['paid'] },
  { id: 'paid', name: 'Paid', owner: 'Payroll', next: [] },
  { id: 'rejected', name: 'Rejected', owner: 'Requester', next: [] },
];

const base: FlowBlock = {
  type: 'flow',
  source: '/api/v1/acme/steps',
  nodeIdField: 'id',
  nodeLabelField: 'name',
};

function flow(overrides: Partial<FlowBlock> = {}): FlowBlock {
  return { ...base, ...overrides };
}

function renderTree(blocks: Block[]) {
  return render(
    <ToastProvider>
      <BlockRenderer blocks={blocks} />
    </ToastProvider>
  );
}

beforeEach(() => {
  mockApiClient.mockReset();
});

// =========================================================================
// The model: every decision the contract makes
// =========================================================================

describe('buildFlowModel', () => {
  it('chains the nodes in payload order when no edge field is declared', () => {
    const model = buildFlowModel(STEPS, flow());

    expect(model.nodes.map((n) => n.id)).toEqual([
      'submitted',
      'review',
      'finance',
      'paid',
      'rejected',
    ]);
    expect(model.edges.map((e) => `${e.source}>${e.target}`)).toEqual([
      'submitted>review',
      'review>finance',
      'finance>paid',
      'paid>rejected',
    ]);
  });

  it('expands a list-valued successor field into one edge per branch', () => {
    const model = buildFlowModel(STEPS, flow({ edgeToField: 'next' }));

    expect(model.edges.map((e) => `${e.source}>${e.target}`)).toEqual([
      'submitted>review',
      'review>finance',
      'review>rejected',
      'finance>paid',
    ]);
  });

  it('reads a predecessor pointer in the other direction', () => {
    const rows = [
      { id: 'root', name: 'Root' },
      { id: 'a', name: 'A', parent: 'root' },
      { id: 'b', name: 'B', parent: 'root' },
      { id: 'c', name: 'C', parent: 'a' },
    ];
    const model = buildFlowModel(rows, flow({ edgeFromField: 'parent' }));

    expect(model.edges.map((e) => `${e.source}>${e.target}`)).toEqual([
      'root>a',
      'root>b',
      'a>c',
    ]);
  });

  /**
   * A box the plugin never described, labelled with a raw id, is not
   * information — so an unresolvable reference is dropped, not materialised.
   */
  it('drops a reference to an id no row declared, rather than inventing a node', () => {
    const rows = [{ id: 'a', name: 'A', next: ['ghost', 'b'] }, { id: 'b', name: 'B' }];
    const model = buildFlowModel(rows, flow({ edgeToField: 'next' }));

    expect(model.nodes.map((n) => n.id)).toEqual(['a', 'b']);
    expect(model.edges.map((e) => `${e.source}>${e.target}`)).toEqual(['a>b']);
  });

  it('drops a self-edge and a duplicate edge', () => {
    const rows = [
      { id: 'a', name: 'A', next: ['a', 'b', 'b'] },
      { id: 'b', name: 'B', prev: ['a'] },
    ];
    const model = buildFlowModel(rows, flow({ edgeToField: 'next' }));

    expect(model.edges.map((e) => `${e.source}>${e.target}`)).toEqual(['a>b']);
  });

  it('keeps the first row for a repeated id and skips a row with no id', () => {
    const rows = [
      { id: 'a', name: 'First' },
      { id: 'a', name: 'Duplicate' },
      { id: '', name: 'Nameless' },
      { name: 'Missing the field entirely' },
    ];
    const model = buildFlowModel(rows, flow());

    expect(model.nodes.map((n) => n.label)).toEqual(['First']);
    expect(model.total).toBe(1);
  });

  it('layers a node by its LONGEST path in, so every edge points forward', () => {
    const model = buildFlowModel(STEPS, flow({ edgeToField: 'next' }));
    const depth = Object.fromEntries(model.nodes.map((n) => [n.id, n.depth]));

    expect(depth).toEqual({ submitted: 0, review: 1, finance: 2, rejected: 2, paid: 3 });
  });

  /**
   * A plugin's data can contain a rework loop, and a layering that relaxes until
   * nothing changes does not terminate over one. Bounded by the node count
   * instead: a readable-enough answer beats a hang.
   */
  it('terminates on a cycle instead of relaxing forever', () => {
    const rows = [
      { id: 'a', name: 'A', next: ['b'] },
      { id: 'b', name: 'B', next: ['c'] },
      { id: 'c', name: 'C', next: ['a'] },
    ];
    const model = buildFlowModel(rows, flow({ edgeToField: 'next' }));

    expect(model.nodes).toHaveLength(3);
    expect(model.edges).toHaveLength(3);
    for (const node of model.nodes) {
      expect(node.depth).toBeLessThan(rows.length);
    }
  });

  // ---- the #192 inheritance ----

  it('cuts at the declared ceiling, in payload order, and says the total', () => {
    const rows = Array.from({ length: 10 }, (_, i) => ({ id: `n${i}`, name: `Node ${i}` }));
    const model = buildFlowModel(rows, flow({ maxNodes: 4 }));

    expect(model.nodes.map((n) => n.id)).toEqual(['n0', 'n1', 'n2', 'n3']);
    expect(model.total).toBe(10);
    expect(model.truncated).toBe(true);
  });

  it('cuts at the contract ceiling when the payload is larger than anyone declared', () => {
    const rows = Array.from({ length: FLOW_MAX_NODES + 25 }, (_, i) => ({ id: `n${i}`, name: `Node ${i}` }));
    const model = buildFlowModel(rows, flow());

    expect(model.nodes).toHaveLength(FLOW_MAX_NODES);
    expect(model.total).toBe(FLOW_MAX_NODES + 25);
    expect(model.truncated).toBe(true);
  });

  /**
   * The validator refuses a `maxNodes` above the ceiling, but the renderer must
   * not TRUST that: the block arrives over the network.
   */
  it('refuses to be raised above the contract ceiling by a block that asks', () => {
    const rows = Array.from({ length: FLOW_MAX_NODES + 50 }, (_, i) => ({ id: `n${i}`, name: `Node ${i}` }));
    const model = buildFlowModel(rows, flow({ maxNodes: FLOW_MAX_NODES + 40 }));

    expect(model.nodes).toHaveLength(FLOW_MAX_NODES);
  });

  it('drops an edge whose other end the ceiling removed', () => {
    const rows = [
      { id: 'a', name: 'A', next: ['b'] },
      { id: 'b', name: 'B', next: ['c'] },
      { id: 'c', name: 'C' },
    ];
    const model = buildFlowModel(rows, flow({ edgeToField: 'next', maxNodes: 2 }));

    expect(model.nodes.map((n) => n.id)).toEqual(['a', 'b']);
    expect(model.edges.map((e) => `${e.source}>${e.target}`)).toEqual(['a>b']);
  });

  it('publishes the WHOLE row, not only the mapped fields', () => {
    const model = buildFlowModel(
      [{ id: 'a', name: 'A', owner: 'Ada', costCentre: 'CC-9', next: ['b'] }],
      flow({ nodeSubtitleField: 'owner' })
    );

    expect(model.nodes[0].row).toEqual({ id: 'a', name: 'A', owner: 'Ada', costCentre: 'CC-9', next: 'b' });
  });

  it('is total over a payload it did not expect', () => {
    const rows = [
      { id: 'a', name: null, owner: undefined, next: null },
      { id: 'b', name: { nested: true }, next: [null, ''] },
    ] as unknown as Record<string, unknown>[];

    expect(() => buildFlowModel(rows, flow({ edgeToField: 'next', nodeSubtitleField: 'owner' }))).not.toThrow();
  });
});

// =========================================================================
// The renderer
// =========================================================================

describe('FlowRenderer', () => {
  it('fetches its source once and hands the canvas the derived graph', async () => {
    mockApiClient.mockResolvedValue(stubResponse({ data: STEPS }));

    renderTree([flow({ edgeToField: 'next', nodeSubtitleField: 'owner', orientation: 'vertical' }) as Block]);

    await waitFor(() => expect(screen.getByTestId('flow-canvas')).toBeInTheDocument());

    expect(mockApiClient).toHaveBeenCalledTimes(1);
    expect(mockApiClient.mock.calls[0][0]).toBe('/api/v1/acme/steps');
    expect(screen.getByTestId('flow-canvas')).toHaveAttribute('data-orientation', 'vertical');
    expect(screen.getByText('Manager review')).toBeInTheDocument();
    expect(screen.getByText('Line manager')).toBeInTheDocument();
    expect(screen.getByTestId('flow-edges')).toHaveTextContent(
      'submitted>review review>finance review>rejected finance>paid'
    );
  });

  it('renders the plugin\'s own emptyText when the source has no rows', async () => {
    mockApiClient.mockResolvedValue(stubResponse({ data: [] }));

    renderTree([flow({ emptyText: 'No steps configured yet.' }) as Block]);

    await waitFor(() => expect(screen.getByText('No steps configured yet.')).toBeInTheDocument());
    expect(screen.queryByTestId('flow-canvas')).not.toBeInTheDocument();
  });

  it('offers a retry when the source fails', async () => {
    mockApiClient.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) } as unknown as Response);

    renderTree([flow() as Block]);

    await waitFor(() => expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument());
  });

  /**
   * The whole point of the ceiling: above it the reader is TOLD, in the page,
   * with the numbers. A partial graph that looks complete is a worse failure
   * than a crowded one.
   */
  it('says so, with the numbers, when the graph was cut', async () => {
    const rows = Array.from({ length: 9 }, (_, i) => ({ id: `n${i}`, name: `Node ${i}` }));
    mockApiClient.mockResolvedValue(stubResponse({ data: rows }));

    renderTree([flow({ maxNodes: 3 }) as Block]);

    await waitFor(() => expect(screen.getByTestId('flow-canvas')).toBeInTheDocument());

    expect(screen.getByText(/Showing the first 3 of 9 nodes/)).toBeInTheDocument();
    expect(screen.getByTestId('node-n0')).toBeInTheDocument();
    expect(screen.queryByTestId('node-n3')).not.toBeInTheDocument();
  });

  it('says nothing about truncation when the whole graph is drawn', async () => {
    mockApiClient.mockResolvedValue(stubResponse({ data: STEPS }));

    renderTree([flow() as Block]);

    await waitFor(() => expect(screen.getByTestId('flow-canvas')).toBeInTheDocument());
    expect(screen.queryByText(/Showing the first/)).not.toBeInTheDocument();
  });

  it('gives a node no click affordance when the block declared no open action', async () => {
    mockApiClient.mockResolvedValue(stubResponse({ data: STEPS }));

    renderTree([flow() as Block]);

    await waitFor(() => expect(screen.getByTestId('flow-canvas')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: 'Paid' })).toBeDisabled();
  });

  /**
   * The seam that makes the block useful: a node click publishes that node's row
   * into the master-detail context under the overlay's id and opens it, which is
   * bit-for-bit the path a `dataTable` `open` row action already takes. An
   * overlay cannot tell which one opened it.
   */
  it('opens the targeted overlay on a node click, publishing that node row', async () => {
    mockApiClient.mockResolvedValue(stubResponse({ data: STEPS }));
    const user = userEvent.setup();

    renderTree([
      flow({
        nodeSubtitleField: 'owner',
        edgeToField: 'next',
        nodeActions: [{ label: 'Details', open: 'step-drawer' }],
      }) as Block,
      {
        type: 'drawer',
        id: 'step-drawer',
        title: 'Step detail',
        children: [
          { type: 'heading', level: 3, text: 'Step', textFrom: 'step-drawer.name' },
          { type: 'text', value: 'unknown owner', valueFrom: 'step-drawer.owner' },
        ],
      } as Block,
    ]);

    await waitFor(() => expect(screen.getByTestId('flow-canvas')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: 'Finance approval' }));

    // Asserted INSIDE the overlay: the same strings are on the canvas behind it,
    // and finding them there would prove nothing about what was published.
    const drawer = await screen.findByRole('dialog');
    expect(within(drawer).getByRole('heading', { name: 'Finance approval' })).toBeInTheDocument();
    expect(within(drawer).getByText('Finance team')).toBeInTheDocument();
  });

  it('renders a node action as a control of its own as well', async () => {
    mockApiClient.mockResolvedValue(stubResponse({ data: STEPS }));
    const user = userEvent.setup();

    renderTree([
      flow({ nodeActions: [{ label: 'Details', open: 'step-drawer' }] }) as Block,
      {
        type: 'drawer',
        id: 'step-drawer',
        title: 'Step detail',
        children: [{ type: 'heading', level: 3, text: 'Step', textFrom: 'step-drawer.name' }],
      } as Block,
    ]);

    await waitFor(() => expect(screen.getByTestId('flow-canvas')).toBeInTheDocument());

    // One "Details" control per node, from the same rowActionList the table uses.
    const details = screen.getAllByRole('button', { name: 'Details' });
    expect(details).toHaveLength(STEPS.length);

    await user.click(details[0]);
    const drawer = await screen.findByRole('dialog');
    expect(within(drawer).getByRole('heading', { name: 'Submitted' })).toBeInTheDocument();
  });

  it('degrades to a placeholder rather than fetching when a required mapping is missing', () => {
    renderTree([{ type: 'flow', source: '/api/v1/acme/steps', nodeIdField: 'id' } as unknown as Block]);

    expect(screen.getByText(/Unsupported block/i)).toBeInTheDocument();
    expect(mockApiClient).not.toHaveBeenCalled();
  });
});
