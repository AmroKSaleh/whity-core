/**
 * What the act panel says to somebody a route only TOLD (#1054/#1064).
 *
 * A delivery stage closes every recipient row at the instant it creates them.
 * The panel looked for an OPEN item, found none, and explained the absence with:
 *
 *     "You have no open item on this route. An item you have already acted on
 *      cannot be acted on again..."
 *
 * They never acted. They were sent a document. The panel was describing a
 * different person's situation with complete confidence — the same failure
 * `RouteFanout` made when it labelled a delivered row "Acted", which #1061 fixed
 * in that component and nowhere else.
 *
 * `closed_by_delivery` is on the recipient type precisely so this is
 * answerable; its own docblock says rendering the two the same way "tells
 * somebody they acted when they were only told". The field existed. This panel
 * did not read it.
 *
 * The assertions below are on `data-delivered` and on the specific sentences,
 * because the wording is translated and this repository ships Arabic: a test
 * that only matched English would pass while an Arabic reader saw the wrong
 * branch.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';

const mockApiClient = { post: jest.fn() };

jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 7 } }),
}));

jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast: jest.fn() }),
}));

import { RouteActPanel } from '@/components/documents/route-act-panel';
import type { DocumentRoute, RouteRecipient, RouteStep } from '@/components/documents/routing-wire';

const VIEWER = 12;

function step(overrides: Partial<RouteStep> & Pick<RouteStep, 'id'>): RouteStep {
  return {
    position: 1,
    rule_kind: 'group',
    rule_config: { group_id: 5 },
    label: null,
    decision: false,
    decision_quorum: null,
    satisfied_by: 'act',
    ...overrides,
  } as RouteStep;
}

function recipient(
  overrides: Partial<RouteRecipient> & Pick<RouteRecipient, 'id' | 'step_id'>
): RouteRecipient {
  return {
    document_id: 1,
    route_id: 8,
    profile_id: VIEWER,
    ou_id: null,
    parent_recipient_id: null,
    created_by_event_id: 15,
    closed_by_event_id: 16,
    open: false,
    closed_by_delivery: false,
    created_at: '2026-09-01T00:00:00Z',
    ...overrides,
  } as RouteRecipient;
}

function renderPanel(steps: RouteStep[], recipients: RouteRecipient[]) {
  const route: DocumentRoute = {
    id: 8,
    document_id: 1,
    title: 'Policy circular',
    created_by: 3,
    created_at: '2026-09-01T00:00:00Z',
    steps,
  } as DocumentRoute;

  return render(
    <RouteActPanel
      documentId={1}
      route={route}
      recipients={recipients}
      viewerProfileId={VIEWER}
      onActed={() => {}}
    />
  );
}

describe('the act panel, for somebody who was delivered to', () => {
  it('does not tell them they already acted', () => {
    // The exact false sentence, named so a regression is unmistakable.
    const { container } = renderPanel(
      [step({ id: 10, satisfied_by: 'delivery' })],
      [recipient({ id: 14, step_id: 10, closed_by_delivery: true })]
    );

    expect(container.querySelector('[data-slot="route-act-panel"]')).toHaveAttribute(
      'data-delivered',
      'true'
    );
    expect(screen.queryByText(/already acted on/)).not.toBeInTheDocument();
  });

  it('says what actually happened, and names the step that did it', () => {
    renderPanel(
      [step({ id: 10, position: 3, satisfied_by: 'delivery' })],
      [recipient({ id: 14, step_id: 10, closed_by_delivery: true })]
    );

    expect(screen.getByText(/Step 3 sent you the document/)).toBeInTheDocument();
    expect(screen.getByText('This route sent you the document')).toBeInTheDocument();
  });

  it('still says nothing is awaiting somebody who genuinely acted', () => {
    // The other direction, and the reason the fix is a branch rather than a
    // rewording. A change that told everybody "this route sent you the
    // document" would satisfy the bug report and lose the distinction the
    // opposite way — which is the mistake #1061 explicitly avoided in
    // RouteFanout.
    const { container } = renderPanel(
      [step({ id: 10 })],
      [recipient({ id: 14, step_id: 10, closed_by_delivery: false })]
    );

    expect(container.querySelector('[data-slot="route-act-panel"]')).toHaveAttribute(
      'data-delivered',
      'false'
    );
    expect(screen.getByText(/Nothing on this route is awaiting you/)).toBeInTheDocument();
  });

  it('describes the OPEN item when the same person was delivered to earlier', () => {
    // Both facts are true at once and only one of them is what the panel is
    // for. Somebody told at step 1 and asked at step 4 is being asked; reporting
    // the older, quieter fact would hide a live request behind a stale one.
    const { container } = renderPanel(
      [step({ id: 10, position: 1, satisfied_by: 'delivery' }), step({ id: 11, position: 4 })],
      [
        recipient({ id: 14, step_id: 10, closed_by_delivery: true }),
        recipient({ id: 15, step_id: 11, open: true, closed_by_event_id: null }),
      ]
    );

    expect(container.querySelector('[data-slot="route-act-panel"]')).toHaveAttribute(
      'data-delivered',
      'false'
    );
    expect(screen.getByText(/You have an open item at step 4/)).toBeInTheDocument();
  });

  it('ignores a delivery to somebody else', () => {
    const { container } = renderPanel(
      [step({ id: 10, satisfied_by: 'delivery' })],
      [recipient({ id: 14, step_id: 10, profile_id: 99, closed_by_delivery: true })]
    );

    expect(container.querySelector('[data-slot="route-act-panel"]')).toHaveAttribute(
      'data-delivered',
      'false'
    );
  });
});
