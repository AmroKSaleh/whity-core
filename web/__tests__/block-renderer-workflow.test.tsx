/**
 * #868: the `timeline` and `inbox` workflow blocks (web renderer).
 *
 * `timeline` is the easy half — an ordered, append-only event list, read-only
 * by construction.
 *
 * `inbox` is the half worth testing hard. The plugin supplies the items; CORE
 * resolves which of the declared actions the caller may take on each, through
 * `POST /api/v1/me/permitted-actions`. The tests pin the CONTRACT of that seam,
 * not the pixels:
 *
 *   - the concrete request each button would make is exactly what is asked
 *     about (asking about a different string is what makes an answer non-binding);
 *   - a refused action is ABSENT, not disabled;
 *   - while resolving, and on any resolution failure, NO action renders
 *     (fail-closed — the alternative renders buttons that 403 on click);
 *   - the per-record scope declared by the block reaches the resolver;
 *   - a successful action refreshes BOTH the queue and the permission answer.
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

interface Call {
  url: string;
  method: string;
  body: unknown;
}

function stubResponse(ok: boolean, status: number, body: unknown): Response {
  return { ok, status, json: () => Promise.resolve(body) } as unknown as Response;
}

const TASKS = [
  { id: 1, title: 'Expense claim #4821', requester: 'Bjorn Larsen', submitted: '2026-08-16', status: 'pending' },
  { id: 2, title: 'Access request', requester: 'Camille Dupont', submitted: '2026-08-15', status: 'pending' },
];

const EVENTS = [
  { actor: 'Anika Patel', action: 'approved the request', at: '2026-08-17 09:12', note: 'Within limit.', from: 'in review', to: 'approved' },
  { actor: 'Bjorn Larsen', action: 'submitted the request', at: '2026-08-16 14:03', note: '', from: '', to: 'submitted' },
];

const inboxTree: Block[] = [
  {
    type: 'inbox',
    source: '/api/v1/tasks/mine',
    idField: 'id',
    titleField: 'title',
    subtitleField: 'requester',
    timestampField: 'submitted',
    statusField: 'status',
    actions: [
      { key: 'approve', label: 'Approve', method: 'POST', endpoint: '/api/v1/tasks/{id}/approve' },
      { key: 'reject', label: 'Reject', method: 'POST', endpoint: '/api/v1/tasks/{id}/reject' },
    ],
  } as Block,
];

/** Every request the renderer made, in order. */
function calls(): Call[] {
  return mockApiClient.mock.calls.map(([url, options]) => ({
    url: url as string,
    method: (options?.method as string) ?? 'GET',
    body: options?.body !== undefined ? JSON.parse(options.body as string) : undefined,
  }));
}

/** The `checks` array of the last permitted-actions request. */
function lastChecks(): Array<Record<string, unknown>> {
  const permission = [...calls()].reverse().find((c) => c.url === '/api/v1/me/permitted-actions');
  return (permission?.body as { checks: Array<Record<string, unknown>> } | undefined)?.checks ?? [];
}

/**
 * Answer the queue fetch with `rows`, and the permitted-actions batch by
 * allowing exactly the refs in `allow`.
 */
function stubHost(options: {
  rows?: unknown;
  allow?: (ref: string) => boolean;
  permissionsFail?: boolean;
  permissionsPending?: boolean;
} = {}) {
  const { rows = TASKS, allow = () => true, permissionsFail = false, permissionsPending = false } = options;

  mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
    if (url === '/api/v1/me/permitted-actions') {
      if (permissionsPending) return new Promise<Response>(() => {});
      if (permissionsFail) return Promise.resolve(stubResponse(false, 500, {}));
      const body = JSON.parse((init?.body as string) ?? '{"checks":[]}') as {
        checks: Array<{ ref: string }>;
      };
      return Promise.resolve(
        stubResponse(true, 200, {
          data: body.checks.map((c) => ({ ref: c.ref, allowed: allow(c.ref), required: null })),
        })
      );
    }
    if (url === '/api/v1/tasks/mine') {
      return Promise.resolve(stubResponse(true, 200, { data: rows }));
    }
    if (url === '/api/v1/tasks/1/events') {
      return Promise.resolve(stubResponse(true, 200, { data: EVENTS }));
    }
    // Every action endpoint succeeds.
    return Promise.resolve(stubResponse(true, 200, { data: {} }));
  });
}

function renderWrapped(ui: React.ReactElement) {
  return render(ui, { wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider> });
}

beforeEach(() => {
  jest.clearAllMocks();
  stubHost();
});

// ==================== timeline ====================

describe('timeline block', () => {
  it('renders each event with its actor, action, timestamp, note and from/to', async () => {
    renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'timeline',
            source: '/api/v1/tasks/1/events',
            actorField: 'actor',
            actionField: 'action',
            timestampField: 'at',
            noteField: 'note',
            fromField: 'from',
            toField: 'to',
          } as Block,
        ]}
      />
    );

    expect(await screen.findByText('Anika Patel')).toBeInTheDocument();
    expect(screen.getByText('approved the request')).toBeInTheDocument();
    expect(screen.getByText('2026-08-17 09:12')).toBeInTheDocument();
    expect(screen.getByText('Within limit.')).toBeInTheDocument();
    expect(screen.getByText('in review')).toBeInTheDocument();
    expect(screen.getAllByText('approved').length).toBeGreaterThan(0);
    // The second event carries no note and no `from`; neither renders empty.
    expect(screen.getByText('Bjorn Larsen')).toBeInTheDocument();
  });

  it('preserves declaration order — the order IS the information', async () => {
    renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'timeline',
            source: '/api/v1/tasks/1/events',
            actorField: 'actor',
            actionField: 'action',
            timestampField: 'at',
          } as Block,
        ]}
      />
    );

    await screen.findByText('Anika Patel');
    const items = screen.getAllByText(/Anika Patel|Bjorn Larsen/);
    expect(items.map((el) => el.textContent)).toEqual(['Anika Patel', 'Bjorn Larsen']);
  });

  it('makes no write request of any kind — it is read-only by construction', async () => {
    renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'timeline',
            source: '/api/v1/tasks/1/events',
            actorField: 'actor',
            actionField: 'action',
            timestampField: 'at',
          } as Block,
        ]}
      />
    );

    await screen.findByText('Anika Patel');
    expect(calls().every((c) => c.method === 'GET')).toBe(true);
  });

  it('shows the plugin emptyText when the source has no events', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, { data: [] }));

    renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'timeline',
            source: '/api/v1/tasks/1/events',
            actorField: 'actor',
            actionField: 'action',
            timestampField: 'at',
            emptyText: 'No events recorded yet.',
          } as Block,
        ]}
      />
    );

    expect(await screen.findByText('No events recorded yet.')).toBeInTheDocument();
  });
});

// ==================== inbox ====================

describe('inbox block', () => {
  it('renders the plugin-supplied items', async () => {
    renderWrapped(<BlockRenderer blocks={inboxTree} />);

    expect(await screen.findByText('Expense claim #4821')).toBeInTheDocument();
    expect(screen.getByText('Bjorn Larsen')).toBeInTheDocument();
    expect(screen.getByText('Access request')).toBeInTheDocument();
    expect(screen.getAllByText('pending')).toHaveLength(2);
  });

  it('asks about the CONCRETE request each button would make, one check per item and action', async () => {
    renderWrapped(<BlockRenderer blocks={inboxTree} />);

    await screen.findByText('Expense claim #4821');
    await waitFor(() => expect(lastChecks()).toHaveLength(4));

    expect(lastChecks()).toEqual([
      { ref: '1 approve', method: 'POST', path: '/api/v1/tasks/1/approve' },
      { ref: '1 reject', method: 'POST', path: '/api/v1/tasks/1/reject' },
      { ref: '2 approve', method: 'POST', path: '/api/v1/tasks/2/approve' },
      { ref: '2 reject', method: 'POST', path: '/api/v1/tasks/2/reject' },
    ]);
  });

  it('renders only the actions core said this caller may take', async () => {
    stubHost({ allow: (ref) => ref === '1 approve' });

    renderWrapped(<BlockRenderer blocks={inboxTree} />);

    await screen.findByText('Expense claim #4821');
    await waitFor(() => expect(screen.getAllByRole('button', { name: 'Approve' })).toHaveLength(1));
    // Refused actions are ABSENT, not disabled — a greyed button still
    // advertises work the user cannot do.
    expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
  });

  it('renders no action while the answer is still resolving', async () => {
    stubHost({ permissionsPending: true });

    renderWrapped(<BlockRenderer blocks={inboxTree} />);

    await screen.findByText('Expense claim #4821');
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
  });

  it('renders no action when the resolution fails, and says so', async () => {
    stubHost({ permissionsFail: true });

    renderWrapped(<BlockRenderer blocks={inboxTree} />);

    await screen.findByText('Expense claim #4821');
    expect(await screen.findByText(/permissions could not be resolved/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
  });

  it('sends the block resource scope and the action scoped permission with each check', async () => {
    renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'inbox',
            source: '/api/v1/tasks/mine',
            idField: 'id',
            titleField: 'title',
            resourceType: 'task',
            actions: [
              {
                key: 'approve',
                label: 'Approve',
                method: 'POST',
                endpoint: '/api/v1/tasks/{id}/approve',
                scopedPermission: 'tasks:approve',
              },
            ],
          } as Block,
        ]}
      />
    );

    await screen.findByText('Expense claim #4821');
    await waitFor(() => expect(lastChecks()).toHaveLength(2));

    expect(lastChecks()[0]).toEqual({
      ref: '1 approve',
      method: 'POST',
      path: '/api/v1/tasks/1/approve',
      resourceType: 'task',
      resourceId: '1',
      scopedPermission: 'tasks:approve',
    });
  });

  it('submits the same path it asked about, with the action verb', async () => {
    renderWrapped(<BlockRenderer blocks={inboxTree} />);

    await screen.findByText('Expense claim #4821');
    await waitFor(() => expect(screen.getAllByRole('button', { name: 'Approve' })).toHaveLength(2));

    await userEvent.click(screen.getAllByRole('button', { name: 'Approve' })[0]);

    await waitFor(() => {
      const write = calls().find((c) => c.url === '/api/v1/tasks/1/approve');
      expect(write?.method).toBe('POST');
    });
  });

  it('refetches BOTH the queue and the permission answer after a successful action', async () => {
    renderWrapped(<BlockRenderer blocks={inboxTree} />);

    await screen.findByText('Expense claim #4821');
    await waitFor(() => expect(screen.getAllByRole('button', { name: 'Approve' })).toHaveLength(2));

    const queueBefore = calls().filter((c) => c.url === '/api/v1/tasks/mine').length;
    const permsBefore = calls().filter((c) => c.url === '/api/v1/me/permitted-actions').length;

    await userEvent.click(screen.getAllByRole('button', { name: 'Approve' })[0]);

    await waitFor(() => {
      expect(calls().filter((c) => c.url === '/api/v1/tasks/mine').length).toBeGreaterThan(queueBefore);
      expect(calls().filter((c) => c.url === '/api/v1/me/permitted-actions').length).toBeGreaterThan(permsBefore);
    });
  });

  it('asks for a confirmation before running an action that declares one', async () => {
    renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'inbox',
            source: '/api/v1/tasks/mine',
            idField: 'id',
            titleField: 'title',
            actions: [
              {
                key: 'reject',
                label: 'Reject',
                method: 'POST',
                endpoint: '/api/v1/tasks/{id}/reject',
                confirm: 'Reject this request?',
              },
            ],
          } as Block,
        ]}
      />
    );

    await screen.findByText('Expense claim #4821');
    await waitFor(() => expect(screen.getAllByRole('button', { name: 'Reject' })).toHaveLength(2));

    await userEvent.click(screen.getAllByRole('button', { name: 'Reject' })[0]);

    expect(await screen.findByText('Reject this request?')).toBeInTheDocument();
    expect(calls().some((c) => c.url === '/api/v1/tasks/1/reject')).toBe(false);
  });

  it('shows the plugin emptyText when nothing awaits the caller', async () => {
    stubHost({ rows: [] });

    renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'inbox',
            source: '/api/v1/tasks/mine',
            idField: 'id',
            titleField: 'title',
            emptyText: 'Nothing awaiting you.',
            actions: [{ key: 'a', label: 'Approve', method: 'POST', endpoint: '/api/v1/tasks/{id}/approve' }],
          } as Block,
        ]}
      />
    );

    expect(await screen.findByText('Nothing awaiting you.')).toBeInTheDocument();
  });

  it('degrades to a placeholder when the declaration is malformed', async () => {
    renderWrapped(
      <BlockRenderer
        blocks={[
          // No `actions` — a block whose whole purpose is actions.
          { type: 'inbox', source: '/api/v1/tasks/mine', idField: 'id', titleField: 'title' } as unknown as Block,
        ]}
      />
    );

    expect(await screen.findByText(/unsupported block/i)).toBeInTheDocument();
  });
});
