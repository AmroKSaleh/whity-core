/**
 * #978 — the inbox screen, as a CONSUMER of the #881 source registry.
 *
 * The assertions worth having here are the ones that would catch the screen
 * quietly becoming a routing surface again:
 *
 *  - it asks `/api/v1/me/inbox/sources` and renders whatever came back, rather
 *    than hardcoding routing;
 *  - it always sends `?source=`, because `MeInboxApiHandler` 422s without one and
 *    a missing source would look like an empty inbox;
 *  - it does NOT merge sources — two registered sources produce a tab strip and
 *    one request, never one blended list;
 *  - "nothing awaits you" and "nothing on this install sends work" are DIFFERENT
 *    renderings, because they are different facts (#756).
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

const push = jest.fn();
jest.mock('next/navigation', () => ({
  useRouter: () => ({ push }),
}));

import InboxPage from '@/app/(protected)/admin/inbox/page';

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  });
}

const ROUTING_SOURCE = {
  key: 'document_routing',
  label: 'Documents awaiting you',
  origin: 'core',
  item_fields: ['id', 'title'],
  open_count: 2,
};

const OTHER_SOURCE = {
  key: 'acme_approvals',
  label: 'Approvals',
  origin: 'acme',
  item_fields: ['id', 'title'],
  open_count: 5,
};

function routingItem(overrides: Record<string, unknown> = {}) {
  return {
    id: '41',
    title: 'Purchase order 9912',
    subtitle: 'Purchase order',
    timestamp: '2026-08-20T09:00:00Z',
    status: 'Forwarded to you',
    resource_type: 'document',
    resource_id: '318',
    meta: {
      route_id: 5,
      step_id: 12,
      document_id: 318,
      open: true,
      arrived_by: 'forwarded',
    },
    ...overrides,
  };
}

function pagination(total: number) {
  return { page: 1, perPage: 25, total, totalPages: total === 0 ? 0 : 1 };
}

/** Every inbox request the page can make, keyed by whether it carries a source. */
function wire(sources: unknown[], items: unknown[]) {
  mockApiClient.mockImplementation((url: string) => {
    if (url === '/api/v1/me/inbox/sources') return jsonResponse(200, { data: sources });
    if (url.startsWith('/api/v1/me/inbox?')) {
      const query = new URLSearchParams(url.slice(url.indexOf('?') + 1));
      const source = query.get('source');
      if (source === null || source === '') {
        // What the real server does. If the page ever stops sending a source,
        // this is the failure it should produce rather than an empty list.
        return jsonResponse(422, { error: "'source' is required" });
      }
      return jsonResponse(200, {
        data: items,
        pagination: pagination(items.length),
        source,
      });
    }
    return jsonResponse(404, {});
  });
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('InboxPage', () => {
  it('reads the #881 source catalogue and lists that source’s items', async () => {
    wire([ROUTING_SOURCE], [routingItem()]);

    render(<InboxPage />);

    await waitFor(() => expect(screen.getByText('Purchase order 9912')).toBeInTheDocument());
    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/me/inbox/sources');
    // The subtitle is the template name — what KIND of thing is waiting.
    expect(screen.getByText('Purchase order')).toBeInTheDocument();
    // The status is the trail's own word, surfaced verbatim.
    expect(screen.getByText('Forwarded to you')).toBeInTheDocument();
  });

  it('always sends ?source= — never an unsourced request', async () => {
    wire([ROUTING_SOURCE], [routingItem()]);

    render(<InboxPage />);

    await waitFor(() => expect(screen.getByText('Purchase order 9912')).toBeInTheDocument());

    const listCalls = mockApiClient.mock.calls
      .map((call) => String(call[0]))
      .filter((url) => url.startsWith('/api/v1/me/inbox?'));

    expect(listCalls.length).toBeGreaterThan(0);
    for (const url of listCalls) {
      expect(url).toContain('source=document_routing');
    }
  });

  it('does not merge sources: two sources give a tab strip and one list', async () => {
    wire([ROUTING_SOURCE, OTHER_SOURCE], [routingItem()]);

    render(<InboxPage />);

    await waitFor(() => expect(screen.getByText('Purchase order 9912')).toBeInTheDocument());

    // A tab per source, each carrying its own count.
    const tabs = screen.getAllByRole('tab');
    expect(tabs).toHaveLength(2);
    expect(screen.getByText('5')).toBeInTheDocument();

    // Exactly one source was requested, not both blended together.
    const requested = new Set(
      mockApiClient.mock.calls
        .map((call) => String(call[0]))
        .filter((url) => url.startsWith('/api/v1/me/inbox?'))
        .map((url) => new URLSearchParams(url.slice(url.indexOf('?') + 1)).get('source'))
    );
    expect(requested).toEqual(new Set(['document_routing']));
  });

  it('switching source requests the other source and resets to page 1', async () => {
    wire([ROUTING_SOURCE, OTHER_SOURCE], [routingItem()]);

    render(<InboxPage />);
    await waitFor(() => expect(screen.getByText('Purchase order 9912')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('tab', { name: /approvals/i }));

    await waitFor(() => {
      const urls = mockApiClient.mock.calls.map((call) => String(call[0]));
      expect(urls.some((u) => u.includes('source=acme_approvals'))).toBe(true);
    });

    const switched = mockApiClient.mock.calls
      .map((call) => String(call[0]))
      .find((u) => u.includes('source=acme_approvals'));
    expect(switched).toContain('page=1');
  });

  it('shows no tab strip when only one source is registered', async () => {
    wire([ROUTING_SOURCE], [routingItem()]);

    render(<InboxPage />);
    await waitFor(() => expect(screen.getByText('Purchase order 9912')).toBeInTheDocument());

    expect(screen.queryAllByRole('tab')).toHaveLength(0);
  });

  it('defaults to open items and can include history', async () => {
    wire([ROUTING_SOURCE], [routingItem()]);

    render(<InboxPage />);
    await waitFor(() => expect(screen.getByText('Purchase order 9912')).toBeInTheDocument());

    // Open-only is the default, so `open` is not sent at all.
    const first = mockApiClient.mock.calls
      .map((call) => String(call[0]))
      .find((u) => u.startsWith('/api/v1/me/inbox?'));
    expect(first).not.toContain('open=0');

    fireEvent.click(screen.getByRole('button', { name: /including what i have done/i }));

    await waitFor(() => {
      const urls = mockApiClient.mock.calls.map((call) => String(call[0]));
      expect(urls.some((u) => u.includes('open=0'))).toBe(true);
    });
  });

  it('links a routing item to its circulation page', async () => {
    wire([ROUTING_SOURCE], [routingItem()]);

    render(<InboxPage />);
    await waitFor(() => expect(screen.getByText('Purchase order 9912')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: 'Purchase order 9912' }));
    expect(push).toHaveBeenCalledWith('/admin/document-routing/318');
  });

  it('renders an item with no routing meta as text, never as a dead link', async () => {
    // An item from another source: the named fields are there, `meta` is not
    // routing's. It must still render — #881's aggregate is source-agnostic.
    wire([OTHER_SOURCE], [routingItem({ meta: { ticket: 'abc' }, title: 'Expense claim 4' })]);

    render(<InboxPage />);
    await waitFor(() => expect(screen.getByText('Expense claim 4')).toBeInTheDocument());

    expect(screen.queryByRole('button', { name: 'Expense claim 4' })).not.toBeInTheDocument();
  });

  it('distinguishes an empty inbox from an installation with no sources', async () => {
    wire([ROUTING_SOURCE], []);

    const { unmount } = render(<InboxPage />);
    await waitFor(() => expect(screen.getByText('Nothing is awaiting you')).toBeInTheDocument());
    unmount();

    jest.clearAllMocks();
    wire([], []);

    render(<InboxPage />);
    await waitFor(() =>
      expect(
        screen.getByText(/nothing on this installation sends work to an inbox/i)
      ).toBeInTheDocument()
    );
    // Not the "you are up to date" message — a different fact.
    expect(screen.queryByText('Nothing is awaiting you')).not.toBeInTheDocument();
  });

  it('surfaces the server’s own message when the catalogue fails', async () => {
    mockApiClient.mockImplementation((url: string) => {
      if (url === '/api/v1/me/inbox/sources') {
        return jsonResponse(500, { error: 'The registry could not be read' });
      }
      return jsonResponse(404, {});
    });

    render(<InboxPage />);

    await waitFor(() =>
      expect(screen.getByText('The registry could not be read')).toBeInTheDocument()
    );
  });
});
