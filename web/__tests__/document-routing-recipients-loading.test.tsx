/**
 * A route's recipient panels must not speak before the rows arrive (#1015).
 *
 * THE DEFECT, WHICH WAS VISIBLE AND SAID THE OPPOSITE OF THE TRUTH
 * ---------------------------------------------------------------
 * The page issues `routes` and `recipients` as two requests. It gated rendering
 * on `routes.loading` only, so between the two answers `RouteFanout` and
 * `RouteActPanel` were handed `[]` — and both turn an empty list into a definite
 * statement rather than a blank:
 *
 *   "This route reached nobody. Its first step resolved to an empty set of
 *    people — the rule found no one holding that role."
 *
 * Found while photographing the #1015 acceptance run: a route that had just
 * reached two technicians rendered that sentence. Not a premature claim — the
 * inverse of what had happened, on the screen an author checks to see whether
 * their document went anywhere.
 *
 * It is also exactly the reading `RouteFanout`'s own comment says the sentence
 * exists to prevent ("rather than render an empty list that reads as 'still
 * loading'"). The component was right; the host was feeding it a lie.
 *
 * So this asserts the OUTCOME on the screen at each moment, driving the
 * recipients request through a promise this test resolves by hand — the only way
 * to hold the render in the window where the bug lived.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 3 } }),
}));

jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));

jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ has: () => true, hasPermission: () => true, loading: false, permissions: [] }),
}));

jest.mock('next/navigation', () => ({
  useParams: () => ({ documentId: '1' }),
  useRouter: () => ({ push: jest.fn() }),
}));

import DocumentRoutingPage from '@/app/(protected)/admin/document-routing/[documentId]/page';

const json = (status: number, body: unknown) =>
  Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });

/** One route, one `group` step — the shape #1015 makes authorable. */
const ROUTE = {
  id: 8,
  document_id: 1,
  title: 'Demo semester circular 2026/1',
  created_by: 3,
  created_at: '2026-08-25T00:26:43Z',
  steps: [
    { id: 10, position: 1, rule_kind: 'group', rule_config: { group_id: 5 }, label: 'To the technicians' },
  ],
};

/** The two people it actually reached. */
const RECIPIENTS = [
  {
    id: 14, document_id: 1, route_id: 8, step_id: 10, profile_id: 10, ou_id: 2,
    parent_recipient_id: null, created_by_event_id: 15, closed_by_event_id: null,
    open: true, created_at: '2026-08-25T00:26:43Z',
  },
  {
    id: 15, document_id: 1, route_id: 8, step_id: 10, profile_id: 12, ou_id: 3,
    parent_recipient_id: null, created_by_event_id: 15, closed_by_event_id: null,
    open: true, created_at: '2026-08-25T00:26:43Z',
  },
];

beforeEach(() => jest.clearAllMocks());

it('never says a route reached nobody while the recipients are still in flight', async () => {
  // Held open by hand: the render has to be observed INSIDE the window between
  // the two answers, which is where the false claim lived.
  let releaseRecipients: (() => void) | undefined;
  const recipientsArrived = new Promise<void>((resolve) => {
    releaseRecipients = resolve;
  });

  mockApiClient.mockImplementation(async (url: string) => {
    if (url.includes('/recipients')) {
      await recipientsArrived;
      return json(200, { data: RECIPIENTS });
    }
    if (url.includes('/routes')) return json(200, { data: [ROUTE] });
    if (url === '/api/v1/documents/1') return json(200, { data: { id: 1, title: 'Demo semester circular 2026/1' } });
    if (url === '/api/v1/routing-rules') return json(200, { data: [] });
    return json(200, { data: [], pagination: { page: 1, perPage: 100, total: 0, totalPages: 0 } });
  });

  render(<DocumentRoutingPage />);

  // The route's own card is on screen — its request answered — while the
  // recipients have not come back yet. Anchored on the CARD rather than on the
  // step label, because the step label is rendered by the fanout, which is
  // precisely what must not be drawn yet.
  await waitFor(() =>
    expect(document.querySelector('[data-slot="routing-route"]')).not.toBeNull()
  );

  expect(screen.queryByText(/this route reached nobody/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/nothing on this route is awaiting you/i)).not.toBeInTheDocument();
  // And it says what IS true instead of nothing at all (#756).
  expect(screen.getByText(/working out who this reached/i)).toBeInTheDocument();

  releaseRecipients?.();

  // Once they arrive the panels appear, and the fanout reports the two people
  // rather than the empty set it was briefly shown.
  await waitFor(() => expect(screen.getByText('To the technicians')).toBeInTheDocument());
  expect(screen.queryByText(/working out who this reached/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/this route reached nobody/i)).not.toBeInTheDocument();
});
