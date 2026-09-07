/**
 * #1041 — the act panel's answer must survive the refetch the act itself causes.
 *
 * THE DEFECT, WHICH 1,794 GREEN UNIT TESTS COULD NOT SEE
 * -----------------------------------------------------
 * Answering a decision step calls `onActed`, the host bumps a version, and both
 * `routes` and `recipients` refetch. `useFetch` raises `loading` on EVERY fetch,
 * not only the first — so the host's two loading gates replaced the whole route
 * list, and every act panel inside it, with a one-line placeholder.
 *
 * The panel had already rendered the only sentence it exists to render:
 *
 *     "Your approval is recorded. This step is not approved yet — it is still
 *      waiting on the other people it was put to."
 *
 * and it was unmounted, along with its state, a few hundred milliseconds later.
 * The most important message in the feature flashed and vanished.
 *
 * Every existing test mounted `RouteActPanel` DIRECTLY, so none of them could
 * see it: the component was right and the host was throwing it away. It was
 * found by opening the page in a browser, which is also the only reason the
 * SECOND copy of it was found — the `recipients` gate was fixed first and the
 * `routes` gate sat two lines above, identical, still green.
 *
 * So these tests mount the PAGE, drive a real click, and assert on what is on
 * screen after everything has settled. They fail if either gate regresses.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 7 } }),
}));

jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));

jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({
    has: () => true,
    hasPermission: () => true,
    loading: false,
    permissions: [],
  }),
}));

jest.mock('next/navigation', () => ({
  useParams: () => ({ documentId: '1' }),
  useRouter: () => ({ push: jest.fn() }),
}));

import DocumentRoutingPage from '@/app/(protected)/admin/document-routing/[documentId]/page';

const json = (status: number, body: unknown) =>
  Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });

/** One route, one GATE, put to three people. */
const ROUTE = {
  id: 8,
  document_id: 1,
  title: 'Three must sign',
  created_by: 3,
  created_at: '2026-08-25T00:26:43Z',
  steps: [
    {
      id: 10,
      position: 1,
      rule_kind: 'explicit',
      rule_config: { profile_ids: [7, 8, 9] },
      label: 'All three must sign',
      decision: true,
      decision_quorum: null,
    },
  ],
  edges: [],
  default_quorum: 'all',
};

function row(id: number, profileId: number) {
  return {
    id,
    document_id: 1,
    route_id: 8,
    step_id: 10,
    profile_id: profileId,
    ou_id: null,
    parent_recipient_id: null,
    created_by_event_id: 15,
    closed_by_event_id: null,
    open: true,
    created_at: '2026-08-25T00:26:43Z',
  };
}

const RECIPIENTS = [row(14, 7), row(15, 8), row(16, 9)];

/**
 * A server that answers, and can be told to make the two page requests SLOW —
 * which is what puts the refetch's `loading` window on screen at all.
 */
function wire(actBody: unknown, listDelayMs = 0) {
  mockApiClient.mockImplementation(async (url: string, options?: RequestInit) => {
    if (options?.method === 'POST' && url.includes('/actions')) return json(201, actBody);
    if (url.includes('/recipients')) {
      if (listDelayMs > 0) await new Promise((r) => setTimeout(r, listDelayMs));
      return json(200, { data: RECIPIENTS });
    }
    if (url.includes('/routes')) {
      if (listDelayMs > 0) await new Promise((r) => setTimeout(r, listDelayMs));
      return json(200, { data: [ROUTE] });
    }
    if (url === '/api/v1/documents/1') return json(200, { data: { id: 1, title: 'A circular' } });
    if (url === '/api/v1/routing-rules') return json(200, { data: [] });
    return json(200, { data: [], pagination: { page: 1, perPage: 100, total: 0, totalPages: 0 } });
  });
}

beforeEach(() => jest.clearAllMocks());

it('keeps "not approved yet" on screen through the refetch the approval triggers', async () => {
  // The step concluded nothing: one of three, under `all`.
  wire({ data: { verdict: 'approved' }, resolved: 0, delivered: 0, decided: null }, 60);

  render(<DocumentRoutingPage />);

  await waitFor(() =>
    expect(document.querySelector('[data-slot="route-act-verdict-approved"]')).not.toBeNull()
  );

  fireEvent.click(
    document.querySelector('[data-slot="route-act-verdict-approved"]') as HTMLElement
  );

  await waitFor(() => expect(screen.getByText(/not approved yet/i)).toBeInTheDocument());

  // …and it is STILL there once both refetches have come back. Under the defect
  // the host swapped the whole list for "Loading…" here and the panel — with its
  // state — was gone.
  await waitFor(() => {
    expect(mockApiClient.mock.calls.filter((c) => String(c[0]).includes('/recipients')).length)
      .toBeGreaterThan(1);
  });
  await waitFor(() =>
    expect(document.querySelector('[data-slot="route-act-outcome"]')).not.toBeNull()
  );
  expect(screen.getByText(/not approved yet/i)).toBeInTheDocument();
  expect(document.querySelector('[data-slot="route-act-outcome"]')).toHaveAttribute(
    'data-decided',
    'pending'
  );
});

it('never shows the whole route list as "Loading…" once it has one', async () => {
  wire({ data: { verdict: 'approved' }, resolved: 0, delivered: 0, decided: null }, 60);

  render(<DocumentRoutingPage />);
  await waitFor(() =>
    expect(document.querySelector('[data-slot="route-act-verdict-approved"]')).not.toBeNull()
  );

  fireEvent.click(
    document.querySelector('[data-slot="route-act-verdict-approved"]') as HTMLElement
  );

  // Poll across the whole refetch window rather than sampling one instant: the
  // gate is only wrong WHILE the second request is in flight, which is exactly
  // the window a single assertion is most likely to miss.
  for (let i = 0; i < 12; i++) {
    expect(screen.queryByText('Loading…')).not.toBeInTheDocument();
    expect(screen.queryByText(/working out who this reached/i)).not.toBeInTheDocument();
    expect(document.querySelector('[data-slot="routing-route"]')).not.toBeNull();
    await new Promise((r) => setTimeout(r, 15));
  }
});

it('still gates the FIRST load, so #1039 stays fixed', async () => {
  // Nothing held yet: the panels must not speak, because "this route reached
  // nobody" and "the rows have not arrived" are different facts (#1039).
  let release: (() => void) | undefined;
  const arrived = new Promise<void>((r) => (release = r));

  mockApiClient.mockImplementation(async (url: string) => {
    if (url.includes('/recipients')) {
      await arrived;
      return json(200, { data: RECIPIENTS });
    }
    if (url.includes('/routes')) return json(200, { data: [ROUTE] });
    if (url === '/api/v1/documents/1') return json(200, { data: { id: 1, title: 'A circular' } });
    if (url === '/api/v1/routing-rules') return json(200, { data: [] });
    return json(200, { data: [], pagination: { page: 1, perPage: 100, total: 0, totalPages: 0 } });
  });

  render(<DocumentRoutingPage />);

  await waitFor(() =>
    expect(document.querySelector('[data-slot="routing-route"]')).not.toBeNull()
  );
  expect(screen.getByText(/working out who this reached/i)).toBeInTheDocument();
  expect(screen.queryByText(/this route reached nobody/i)).not.toBeInTheDocument();
  expect(document.querySelector('[data-slot="route-act-panel"]')).toBeNull();

  release?.();
  await waitFor(() =>
    expect(document.querySelector('[data-slot="route-act-panel"]')).not.toBeNull()
  );
});
