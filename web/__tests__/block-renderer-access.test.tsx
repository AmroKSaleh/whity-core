/**
 * #909: a condition that can read the record and the caller's access.
 *
 * Two properties are under test here, and the second is the one that keeps #895
 * closed while the first opens what it forbade.
 *
 * 1. A GATED REGION HAS THREE STATES, AND THEY COMPOSE. `accessGate` asks the
 *    HOST — `POST /api/v1/me/permitted-actions`, the same authority the `inbox`
 *    block uses — whether one concrete request would be admitted, and renders
 *    `children` or `otherwise` accordingly. Nested, the two gates give
 *    hidden / read-only / editable, which is what a record page needs and what a
 *    block tree could not express before.
 *
 * 2. A GATE'S ANSWER IS A CONTROL BINDING AND NEVER BECOMES A FACT. The answer
 *    is published into a namespace of its own that `resolveContextRef` — the
 *    single resolver behind every `…From` prop — does not read. So a page can
 *    ACT on what the caller may do and still cannot SAY it about the record,
 *    which is exactly the line #908 drew and #895 is the incident behind.
 *
 * The cross-renderer half lives in `block-renderer-payload-parity.test.tsx`.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { BlockRenderer } from '@/components/plugin/blocks/block-renderer';
import type { Block } from '@/lib/plugin-features';
import { apiClient } from '@/lib/api-client';
import { ToastProvider } from '@/lib/toast-context';

jest.mock('@/lib/api-client', () => ({ apiClient: jest.fn() }));
const mockApiClient = apiClient as jest.MockedFunction<typeof apiClient>;

function stubResponse(body: unknown, status = 200): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

/** The record endpoint's payload — facts AND caller flags, as a real one returns. */
const ROLE = {
  name: 'Regional manager',
  scope: 'Tenant',
  manageable: true,
  canEdit: true,
  readOnly: false,
};

/**
 * Answer the permitted-actions batch with a fixed allow-list of refs, and every
 * other request with the record. Returns the recorded permitted-actions bodies
 * so a test can assert WHAT was asked, not only what was answered.
 */
function mockHost(allowed: string[]): { batches: unknown[] } {
  const batches: unknown[] = [];
  mockApiClient.mockImplementation((path: string, init?: RequestInit) => {
    if (path === '/api/v1/me/permitted-actions') {
      batches.push(JSON.parse(String(init?.body ?? '{}')));
      const checks = JSON.parse(String(init?.body ?? '{"checks":[]}')).checks as { ref: string }[];
      return Promise.resolve(
        stubResponse({
          data: checks.map((c) => ({ ref: c.ref, allowed: allowed.includes(c.ref), required: null })),
        })
      );
    }
    return Promise.resolve(stubResponse({ data: ROLE }));
  });
  return { batches };
}

function renderTree(blocks: unknown[], record?: string) {
  return render(<BlockRenderer blocks={blocks as unknown as Block[]} record={record} />, {
    wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider>,
  });
}

beforeEach(() => {
  jest.clearAllMocks();
});

/** The record page's editable/read-only pair, declared as ONE node. */
const PAIR = [
  {
    type: 'dataRecord',
    id: 'role',
    source: '/api/v1/roles/7',
    fields: [
      { field: 'name', label: 'Name' },
      { field: 'scope', label: 'Scope' },
    ],
    children: [
      {
        type: 'accessGate',
        id: 'may-write',
        check: { method: 'PATCH', endpoint: '/api/v1/roles/7' },
        children: [
          {
            type: 'form',
            submit: { method: 'POST', endpoint: '/api/v1/roles/7' },
            children: [
              { type: 'textInput', name: 'name', label: 'Name', defaultFrom: 'role.name' },
              { type: 'submitButton', label: 'Save' },
            ],
          },
        ],
        otherwise: [
          { type: 'alert', variant: 'info', title: 'Read-only', body: 'You may not change this role.' },
          { type: 'recordFields', from: 'role' },
        ],
      },
    ],
  },
];

describe('the read-only state a record page could not express (#909)', () => {
  it('renders the editor when the host permits the write', async () => {
    mockHost(['may-write']);
    renderTree(PAIR);

    expect(await screen.findByRole('button', { name: 'Save' })).toBeInTheDocument();
    expect(screen.queryByText('Read-only')).not.toBeInTheDocument();
  });

  it('renders a DIFFERENT rendering, not a disabled form, when it refuses', async () => {
    mockHost([]);
    const { container } = renderTree(PAIR);

    expect(await screen.findByText('Read-only')).toBeInTheDocument();
    // The point of the pair: no input exists at all. A disabled Save beside
    // editable fields is the failure #882 named, and it cannot occur here
    // because the editor is not in the tree that rendered.
    expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
    expect(container.querySelectorAll('input')).toHaveLength(0);
    expect(container.querySelector('[data-slot="block-access-refused"]')).not.toBeNull();
  });

  it('asks the host about the exact request, and asks it once for the page', async () => {
    const { batches } = mockHost(['may-write']);
    renderTree(PAIR);

    await screen.findByRole('button', { name: 'Save' });
    expect(batches).toHaveLength(1);
    expect(batches[0]).toEqual({
      checks: [{ ref: 'may-write', method: 'PATCH', path: '/api/v1/roles/7' }],
    });
  });

  it('renders NEITHER branch while the answer is still pending', async () => {
    let settle: (value: Response) => void = () => {};
    mockApiClient.mockImplementation((path: string) => {
      if (path === '/api/v1/me/permitted-actions') {
        return new Promise<Response>((resolve) => {
          settle = resolve;
        });
      }
      return Promise.resolve(stubResponse({ data: ROLE }));
    });

    const { container } = renderTree(PAIR);

    // Deliberately not the refused branch: "you may not edit this" is a
    // statement, and stating it before the answer arrives states something not
    // yet known.
    await waitFor(() =>
      expect(container.querySelector('[data-slot="block-access-pending"]')).not.toBeNull()
    );
    expect(screen.queryByText('Read-only')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();

    settle(stubResponse({ data: [{ ref: 'may-write', allowed: true, required: null }] }));
    expect(await screen.findByRole('button', { name: 'Save' })).toBeInTheDocument();
  });

  it('treats a resolver failure as a refusal, not as a page with no body', async () => {
    mockApiClient.mockImplementation((path: string) => {
      if (path === '/api/v1/me/permitted-actions') return Promise.resolve(stubResponse({}, 500));
      return Promise.resolve(stubResponse({ data: ROLE }));
    });

    renderTree(PAIR);

    expect(await screen.findByText('Read-only')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
  });
});

describe('the three states, by composition', () => {
  const THREE_STATE = [
    {
      type: 'accessGate',
      id: 'may-read',
      check: { method: 'GET', endpoint: '/api/v1/roles/7' },
      // No `otherwise`: refused ⇒ the region is ABSENT, which is the state that
      // has no other spelling.
      children: [
        {
          type: 'accessGate',
          id: 'may-write',
          check: { method: 'PATCH', endpoint: '/api/v1/roles/7' },
          children: [{ type: 'text', value: 'EDITABLE' }],
          otherwise: [{ type: 'text', value: 'READ ONLY' }],
        },
      ],
    },
  ];

  it('hides the region entirely when the read is refused', async () => {
    mockHost([]);
    const { container } = renderTree(THREE_STATE);

    await waitFor(() =>
      expect(container.querySelector('[data-slot="block-access-pending"]')).toBeNull()
    );
    expect(container.textContent).not.toContain('EDITABLE');
    expect(container.textContent).not.toContain('READ ONLY');
  });

  it('shows the read-only rendering when the read is permitted and the write is not', async () => {
    mockHost(['may-read']);
    renderTree(THREE_STATE);

    expect(await screen.findByText('READ ONLY')).toBeInTheDocument();
    expect(screen.queryByText('EDITABLE')).not.toBeInTheDocument();
  });

  it('shows the editor when both are permitted', async () => {
    mockHost(['may-read', 'may-write']);
    renderTree(THREE_STATE);

    expect(await screen.findByText('EDITABLE')).toBeInTheDocument();
  });

  it('never asks about the inner gate twice, and asks about both in one batch', async () => {
    const { batches } = mockHost(['may-read', 'may-write']);
    renderTree(THREE_STATE);

    await screen.findByText('EDITABLE');
    expect(batches).toHaveLength(1);
    expect((batches[0] as { checks: { ref: string }[] }).checks.map((c) => c.ref)).toEqual([
      'may-read',
      'may-write',
    ]);
  });
});

describe('a condition attaches to any block, not only to a gate slot', () => {
  const CONDITIONS = [
    {
      type: 'dataRecord',
      id: 'role',
      source: '/api/v1/roles/7',
      fields: [{ field: 'scope', label: 'Scope' }],
      children: [
        {
          type: 'accessGate',
          id: 'may-write',
          check: { method: 'PATCH', endpoint: '/api/v1/roles/7' },
        },
        // A leaf, gated. No wrapper — this is the property that keeps granular
        // gating from becoming a second mechanism.
        {
          type: 'badge',
          variant: 'warning',
          label: 'READ ONLY BADGE',
          visibleWhen: { access: 'may-write', equals: false },
        },
        {
          type: 'badge',
          variant: 'success',
          label: 'WRITER BADGE',
          visibleWhen: { access: 'may-write', equals: true },
        },
        // A conditional notice keyed on a RECORD FACT, which is the other
        // subject the facet grew.
        {
          type: 'alert',
          variant: 'warning',
          body: 'GLOBAL NOTICE',
          visibleWhen: { from: 'role.scope', equals: 'Global' },
        },
        {
          type: 'alert',
          variant: 'info',
          body: 'TENANT NOTICE',
          visibleWhen: { from: 'role.scope', equals: 'Tenant' },
        },
      ],
    },
  ];

  it('shows the polarity that matches the answer, and only that one', async () => {
    mockHost([]);
    renderTree(CONDITIONS);

    expect(await screen.findByText('READ ONLY BADGE')).toBeInTheDocument();
    expect(screen.queryByText('WRITER BADGE')).not.toBeInTheDocument();
  });

  it('flips both when the answer does', async () => {
    mockHost(['may-write']);
    renderTree(CONDITIONS);

    expect(await screen.findByText('WRITER BADGE')).toBeInTheDocument();
    expect(screen.queryByText('READ ONLY BADGE')).not.toBeInTheDocument();
  });

  it('conditions a notice on the record the page is about', async () => {
    mockHost([]);
    renderTree(CONDITIONS);

    expect(await screen.findByText('TENANT NOTICE')).toBeInTheDocument();
    expect(screen.queryByText('GLOBAL NOTICE')).not.toBeInTheDocument();
  });

  it('hides a block naming a gate nothing declared — authority fails CLOSED', async () => {
    mockHost(['may-write']);
    const { container } = renderTree([
      { type: 'text', value: 'GHOST GATE', visibleWhen: { access: 'no-such-gate', equals: true } },
      // ...and the negated form hides too. A gate with no answer is not the same
      // as a gate that answered "no", so BOTH polarities are withheld.
      { type: 'text', value: 'GHOST GATE NEGATED', visibleWhen: { access: 'no-such-gate', equals: false } },
    ]);

    await waitFor(() => expect(container.textContent).not.toContain('GHOST GATE'));
    expect(container.textContent).not.toContain('GHOST GATE NEGATED');
  });

  it('leaves a block visible when a RECORD reference does not resolve — facts fail OPEN', async () => {
    mockHost([]);
    renderTree([
      { type: 'text', value: 'STILL HERE', visibleWhen: { from: 'nothing.at.all', equals: 'x' } },
    ]);

    expect(await screen.findByText('STILL HERE')).toBeInTheDocument();
  });
});

describe('a gate is never asked about a record nobody has named', () => {
  it('does not resolve a gate whose endpoint token is unresolved', async () => {
    const { batches } = mockHost(['may-write']);
    const { container } = renderTree([
      {
        type: 'accessGate',
        id: 'may-write',
        check: { method: 'PATCH', endpoint: '/api/v1/roles/{record}' },
        children: [{ type: 'text', value: 'EDITABLE' }],
        otherwise: [{ type: 'text', value: 'READ ONLY' }],
      },
    ]);

    // No `record` seeded, so the endpoint would be `/api/v1/roles/` — a
    // different route with a different gate. Being told whether you may write
    // the COLLECTION, and rendering an editor for one record on the strength of
    // it, is worse than being told nothing.
    //
    // And it renders NOTHING rather than a skeleton: a skeleton that never
    // resolves is a spinner promising an answer nobody is fetching. That is the
    // difference between "unasked" and "pending".
    await waitFor(() => expect(batches).toHaveLength(0));
    expect(container.querySelector('[data-slot="block-access-pending"]')).toBeNull();
    expect(container.textContent).not.toContain('EDITABLE');
    expect(container.textContent).not.toContain('READ ONLY');
  });

  it('asks about the route the host seeded once the record is named', async () => {
    const { batches } = mockHost(['may-write']);
    renderTree(
      [
        {
          type: 'accessGate',
          id: 'may-write',
          check: { method: 'PATCH', endpoint: '/api/v1/roles/{record}' },
          children: [{ type: 'text', value: 'EDITABLE' }],
        },
      ],
      '42'
    );

    await screen.findByText('EDITABLE');
    expect(batches).toEqual([
      { checks: [{ ref: 'may-write', method: 'PATCH', path: '/api/v1/roles/42' }] },
    ]);
  });
});

// ---------------------------------------------------------------------------
// The seam: a control binding must not become a fact binding
// ---------------------------------------------------------------------------

describe("a gate's answer is unreachable as a fact about the record (#895)", () => {
  const FACT_ATTEMPTS = [
    // A gate declared with no renderings of its own — the pure-declaration form
    // — so the leaves below render whichever way it answers.
    {
      type: 'accessGate',
      id: 'may-write',
      check: { method: 'PATCH', endpoint: '/api/v1/roles/7' },
    },
    // Every fact binding in the contract, each pointed at the gate. All four
    // resolve through `resolveContextRef`, which reads records and selections —
    // never the access namespace — so all four fall back to their required
    // literal.
    { type: 'heading', level: 2, text: 'LITERAL HEADING', textFrom: 'may-write' },
    { type: 'text', value: 'LITERAL TEXT', valueFrom: 'may-write.allowed' },
    { type: 'badge', variant: 'info', label: 'LITERAL BADGE', labelFrom: 'may-write' },
    {
      type: 'stat',
      label: 'Writable',
      value: 'LITERAL STAT',
      valueFrom: 'may-write',
      hint: 'LITERAL HINT',
      hintFrom: 'may-write.allowed',
    },
  ];

  it('renders the literal fallback rather than the answer, in both directions', async () => {
    for (const allowed of [['may-write'], []]) {
      jest.clearAllMocks();
      mockHost(allowed);
      const { container, unmount } = renderTree(FACT_ATTEMPTS);

      await screen.findByText('LITERAL HEADING');
      expect(screen.getByText('LITERAL TEXT')).toBeInTheDocument();
      expect(screen.getByText('LITERAL BADGE')).toBeInTheDocument();
      expect(screen.getByText('LITERAL STAT')).toBeInTheDocument();
      expect(screen.getByText('LITERAL HINT')).toBeInTheDocument();

      // Nothing on the page states the answer. `true`/`false`/`Yes`/`No` would
      // each be a way of saying it.
      expect(container.textContent).not.toMatch(/\b(true|false|Yes|No)\b/);
      unmount();
    }
  });

  it('does not publish the answer into the record context a recordFields reads', async () => {
    mockHost(['may-write']);
    const { container } = renderTree([
      {
        type: 'dataRecord',
        id: 'role',
        source: '/api/v1/roles/7',
        fields: [{ field: 'scope', label: 'Scope' }],
        children: [
          {
            type: 'accessGate',
            id: 'role',
            // Deliberately the SAME id as the record. The two live in different
            // namespaces, so even a colliding name cannot make the answer show
            // up among the record's facts.
            check: { method: 'PATCH', endpoint: '/api/v1/roles/7' },
          },
          { type: 'recordFields', from: 'role' },
        ],
      },
    ]);

    await screen.findByText('Scope');
    expect(container.querySelectorAll('dd')).toHaveLength(1);
    expect(screen.getByText('Tenant')).toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// Granular gating: the amendment's requirement, checked by composition
// ---------------------------------------------------------------------------

describe('a gate composes down to one field inside a form', () => {
  /**
   * "Some parts have permissions, not always everything is allowed." A gate is a
   * container, and it is legal wherever a container is — including between a
   * `form` and one of its inputs. So a form can be editable in one region and a
   * description list in another, with the same primitive and no second
   * mechanism.
   */
  const PER_FIELD = [
    {
      type: 'dataRecord',
      id: 'person',
      source: '/api/v1/people/7',
      fields: [
        { field: 'name', label: 'Name' },
        { field: 'scope', label: 'Salary band' },
      ],
      children: [
        {
          type: 'form',
          submit: { method: 'POST', endpoint: '/api/v1/people/7' },
          children: [
            { type: 'textInput', name: 'name', label: 'Name', defaultFrom: 'person.name' },
            {
              type: 'accessGate',
              id: 'may-set-band',
              check: { method: 'PATCH', endpoint: '/api/v1/people/7/band' },
              children: [{ type: 'textInput', name: 'band', label: 'Salary band' }],
              otherwise: [{ type: 'recordFields', from: 'person', fields: ['scope'] }],
            },
            { type: 'submitButton', label: 'Save' },
          ],
        },
      ],
    },
  ];

  it('keeps the rest of the form editable while one field is read-only', async () => {
    mockHost([]);
    renderTree(PER_FIELD);

    // The ungated field is still an input...
    expect(await screen.findByLabelText('Name')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();
    // ...and the gated one is a description list entry instead.
    expect(screen.queryByLabelText('Salary band')).not.toBeInTheDocument();
    expect(screen.getByText('Salary band')).toBeInTheDocument();
    expect(screen.getByText('Tenant')).toBeInTheDocument();
  });

  it('turns the same field back into an input when the host permits it', async () => {
    mockHost(['may-set-band']);
    renderTree(PER_FIELD);

    expect(await screen.findByLabelText('Salary band')).toBeInTheDocument();
  });
});
