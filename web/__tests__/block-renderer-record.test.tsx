/**
 * #883: the record blocks — `dataRecord`, `recordFields`, and the `…From` twins
 * that let a literal leaf bind to a record's own values.
 *
 * The property most of this file exists for is #895's, restated for a tree that
 * is validated at runtime rather than compiled: A RECORD PAGE STATES FACTS
 * ABOUT THE RECORD, NEVER ABOUT THE CALLER. The SDK validator refuses a
 * declaration that names a caller-permission field as a fact; the renderer is
 * the other half, and it is the half tested here — it publishes ONLY the fields
 * the declaration named, so a flag it never named is unreachable from the tree
 * whatever the endpoint chose to call it.
 *
 * The cross-renderer half of these assertions lives in
 * `block-renderer-payload-parity.test.tsx`, which runs the same tree through the
 * desktop twin. Both are needed: this one says what the contract means, that one
 * says both platforms mean the same thing by it.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
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

/** What a role endpoint honestly returns: the record's own fields, AND this
 * caller's permissions on it. Both halves are real; only one is a fact. */
const ROLE = {
  name: 'Regional manager',
  scope: 'Tenant',
  created: '2025-04-02',
  manageable: true,
  canEdit: true,
  readOnly: false,
};

function renderTree(blocks: unknown[], record?: string) {
  return render(<BlockRenderer blocks={blocks as unknown as Block[]} record={record} />, {
    wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider>,
  });
}

// Radix Select needs Pointer Capture + scrollIntoView, absent in jsdom.
beforeAll(() => {
  window.HTMLElement.prototype.hasPointerCapture = jest.fn();
  window.HTMLElement.prototype.setPointerCapture = jest.fn();
  window.HTMLElement.prototype.releasePointerCapture = jest.fn();
  window.HTMLElement.prototype.scrollIntoView = jest.fn();
});

beforeEach(() => {
  jest.clearAllMocks();
  mockApiClient.mockResolvedValue(stubResponse({ data: ROLE }));
});

// ---------------------------------------------------------------------------
// The #895 guard, at runtime
// ---------------------------------------------------------------------------

describe('a record publishes only the facts it declared (#895)', () => {
  const TREE = [
    {
      type: 'dataRecord',
      id: 'role',
      source: '/api/v1/roles/7',
      fields: [
        { field: 'name', label: 'Name' },
        { field: 'scope', label: 'Scope' },
      ],
      children: [{ type: 'recordFields', from: 'role' }],
    },
  ];

  it('renders the declared facts under the labels declared beside them', async () => {
    const { container } = renderTree(TREE);

    await screen.findByText('Regional manager');
    expect(screen.getByText('Name')).toBeInTheDocument();
    expect(screen.getByText('Scope')).toBeInTheDocument();
    expect(screen.getByText('Tenant')).toBeInTheDocument();
    expect(container.querySelectorAll('dd')).toHaveLength(2);
  });

  it('does not publish a caller-permission field the declaration never named', async () => {
    // The payload carries `manageable`, `canEdit` and `readOnly`. None appears,
    // and — the part that matters — none is REACHABLE: a binding that names one
    // resolves to nothing and falls back to its literal, below.
    const { container } = renderTree(TREE);

    await screen.findByText('Regional manager');
    expect(container.textContent).not.toMatch(/manageable|canEdit|readOnly/i);
    expect(container.textContent).not.toContain('Yes');
  });

  it('leaves a binding that names an unpublished field on its literal', async () => {
    // A tree that reached a renderer with such a binding would have to have got
    // past the validator, which refuses it. This is the defence behind that one:
    // even then, the flag is not there to be read.
    renderTree([
      {
        type: 'dataRecord',
        id: 'role',
        source: '/api/v1/roles/7',
        fields: [{ field: 'name', label: 'Name' }],
        children: [
          { type: 'heading', level: 2, text: 'Untitled', textFrom: 'role.name' },
          // `manageable` IS in the payload and is NOT a declared fact.
          { type: 'badge', variant: 'info', label: 'Scope unknown', labelFrom: 'role.manageable' },
        ],
      },
    ]);

    await screen.findByText('Regional manager');
    expect(screen.getByText('Scope unknown')).toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// The `…From` twins
// ---------------------------------------------------------------------------

describe('literal leaves bound to a record', () => {
  const TREE = [
    {
      type: 'dataRecord',
      id: 'role',
      source: '/api/v1/roles/7',
      fields: [
        { field: 'name', label: 'Name' },
        { field: 'scope', label: 'Scope' },
        { field: 'created', label: 'Created' },
      ],
      children: [
        { type: 'heading', level: 1, text: 'Untitled role', textFrom: 'role.name' },
        { type: 'text', value: 'No scope', valueFrom: 'role.scope' },
        { type: 'badge', variant: 'info', label: 'No scope', labelFrom: 'role.scope' },
        {
          type: 'stat',
          label: 'Created',
          value: 'Unknown',
          valueFrom: 'role.created',
          hint: 'no scope',
          hintFrom: 'role.scope',
        },
      ],
    },
  ];

  it('replaces every literal with the record field once it resolves', async () => {
    const { container } = renderTree(TREE);

    await screen.findByRole('heading', { name: 'Regional manager' });
    expect(container.textContent).not.toContain('Untitled role');
    expect(container.textContent).not.toContain('No scope');
    expect(container.textContent).not.toContain('Unknown');
    expect(screen.getByText('2025-04-02')).toBeInTheDocument();
  });

  it('keeps the literal when the reference does not resolve', async () => {
    // The whole reason the literal stays REQUIRED in the contract: a heading
    // bound to a record nothing published is still a heading.
    renderTree([{ type: 'heading', level: 1, text: 'Untitled role', textFrom: 'nothing.here' }]);

    expect(await screen.findByRole('heading', { name: 'Untitled role' })).toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// Which record — the page-level binding (#883 gap 2)
// ---------------------------------------------------------------------------

describe('naming the record', () => {
  const TREE = [
    {
      type: 'dataRecord',
      id: 'role',
      source: '/api/v1/roles/{record}',
      fields: [{ field: 'name', label: 'Name' }],
      children: [{ type: 'recordFields', from: 'role' }],
    },
  ];

  it('fetches nothing until the token resolves', async () => {
    renderTree(TREE);

    // NOT an error, and NOT `/api/v1/roles/` — the collection endpoint, which is
    // what a naive `''` substitution would have requested and then rendered as
    // "the record this page is about".
    expect(await screen.findByText('No record selected.')).toBeInTheDocument();
    expect(mockApiClient).not.toHaveBeenCalled();
  });

  it('fetches exactly the route record once the host seeds it', async () => {
    renderTree(TREE, '7');

    await screen.findByText('Regional manager');
    expect(mockApiClient.mock.calls.map((c) => String(c[0]))).toEqual(['/api/v1/roles/7']);
  });

  it('re-fetches when the route record changes', async () => {
    const { rerender } = renderTree(TREE, '7');
    await screen.findByText('Regional manager');

    mockApiClient.mockResolvedValue(stubResponse({ data: { ...ROLE, name: 'Auditor' } }));
    rerender(
      <ToastProvider>
        <BlockRenderer blocks={TREE as unknown as Block[]} record="9" />
      </ToastProvider>
    );

    await screen.findByText('Auditor');
    expect(mockApiClient.mock.calls.map((c) => String(c[0]))).toContain('/api/v1/roles/9');
  });

  it('URL-encodes a token value rather than letting it change the path', async () => {
    renderTree(TREE, 'a/../b');

    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    expect(String(mockApiClient.mock.calls[0][0])).toBe('/api/v1/roles/a%2F..%2Fb');
  });

  it('resolves a token from a selector, which is master-detail rather than a route', async () => {
    mockApiClient.mockImplementation((url: string) =>
      Promise.resolve(
        url.startsWith('/api/v1/roles/')
          ? stubResponse({ data: ROLE })
          : stubResponse({ data: [{ id: '7', name: 'Regional manager' }] })
      )
    );

    renderTree([
      {
        type: 'selector',
        name: 'pick',
        label: 'Role',
        source: '/api/v1/roles',
        valueField: 'id',
        labelField: 'name',
      },
      { ...TREE[0], source: '/api/v1/roles/{pick}' },
    ]);

    await screen.findByText('No record selected.');
    await waitFor(() => expect(screen.getByRole('combobox', { name: /role/i })).not.toBeDisabled());
    await userEvent.click(screen.getByRole('combobox', { name: /role/i }));
    await userEvent.click(await screen.findByRole('option', { name: 'Regional manager' }));

    await waitFor(() =>
      expect(mockApiClient.mock.calls.map((c) => String(c[0]))).toContain('/api/v1/roles/7')
    );
  });
});

// ---------------------------------------------------------------------------
// recordFields
// ---------------------------------------------------------------------------

describe('recordFields', () => {
  const RECORD = {
    type: 'dataRecord',
    id: 'role',
    source: '/api/v1/roles/7',
    fields: [
      { field: 'name', label: 'Name' },
      { field: 'scope', label: 'Scope' },
      { field: 'created', label: 'Created' },
    ],
  };

  it('honours a subset in the order asked for', async () => {
    const { container } = renderTree([
      { ...RECORD, children: [{ type: 'recordFields', from: 'role', fields: ['created', 'name'] }] },
    ]);

    await screen.findByText('Regional manager');
    const labels = Array.from(container.querySelectorAll('dt')).map((n) => n.textContent);
    expect(labels).toEqual(['Created', 'Name']);
  });

  it('resolves for a SIBLING of the record, not just a descendant', async () => {
    // `from` names a record, not a position in the tree — which is why the label
    // store lives at the provider rather than in a context around the subtree.
    const { container } = renderTree([
      { ...RECORD, children: [] },
      { type: 'recordFields', from: 'role', fields: ['scope'] },
    ]);

    await waitFor(() => expect(container.querySelectorAll('dd')).toHaveLength(1));
    expect(screen.getByText('Tenant')).toBeInTheDocument();
  });

  it('shows an em dash for a declared field the payload did not carry', async () => {
    mockApiClient.mockResolvedValue(stubResponse({ data: { name: 'Regional manager' } }));

    const { container } = renderTree([
      { ...RECORD, children: [{ type: 'recordFields', from: 'role' }] },
    ]);

    await screen.findByText('Regional manager');
    const values = Array.from(container.querySelectorAll('dd')).map((n) => n.textContent);
    expect(values).toEqual(['Regional manager', '—', '—']);
  });

  it('renders nothing when the named record never published', async () => {
    const { container } = renderTree([{ type: 'recordFields', from: 'nobody' }]);

    await waitFor(() => expect(container.querySelectorAll('dd')).toHaveLength(0));
    expect(container.textContent).toBe('');
  });
});

// ---------------------------------------------------------------------------
// States the container owns for its whole subtree
// ---------------------------------------------------------------------------

describe('the states a dataRecord owns', () => {
  const TREE = [
    {
      type: 'dataRecord',
      id: 'role',
      source: '/api/v1/roles/7',
      fields: [{ field: 'name', label: 'Name' }],
      emptyText: 'That role is gone.',
      children: [{ type: 'heading', level: 2, text: 'Untitled', textFrom: 'role.name' }],
    },
  ];

  it('shows one failure for the subtree, with a retry, rather than a page of blanks', async () => {
    mockApiClient.mockResolvedValue(stubResponse({ error: 'boom' }, 500));
    const { container } = renderTree(TREE);

    await screen.findByRole('button', { name: 'Retry' });
    // The children never rendered — a record that failed to load must not read
    // as a record with no values.
    expect(container.textContent).not.toContain('Untitled');
  });

  it('uses the declared emptyText when the resource is not an object', async () => {
    mockApiClient.mockResolvedValue(stubResponse({ data: null }));
    renderTree(TREE);

    expect(await screen.findByText('That role is gone.')).toBeInTheDocument();
  });

  it('seeds a form inside it from the record, which is plumbing rather than a fact', async () => {
    renderTree([
      {
        type: 'dataRecord',
        id: 'role',
        source: '/api/v1/roles/7',
        fields: [{ field: 'name', label: 'Name' }],
        children: [
          {
            type: 'form',
            submit: { method: 'PATCH', endpoint: '/api/v1/roles/7' },
            children: [
              { type: 'textInput', name: 'name', label: 'Name', defaultFrom: 'role.name' },
              { type: 'submitButton', label: 'Save' },
            ],
          },
        ],
      },
    ]);

    expect(await screen.findByDisplayValue('Regional manager')).toBeInTheDocument();
  });
});
