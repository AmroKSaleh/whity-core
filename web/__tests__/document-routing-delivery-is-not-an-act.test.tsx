/**
 * A delivery stage tells people; it does not ask them to act.
 *
 * The routing screen rendered every CLOSED recipient row as "Acted" — including
 * rows on a delivery stage, which are closed at the instant they are created
 * and which nobody ever answers. So a technician who had merely been sent a
 * copy read, in the second person:
 *
 *     Profile #12 (you) — Acted
 *
 * #1061 was deliberate about keeping that distinction true in the data: it put
 * `satisfied_by` on the STEP and `closed_by_delivery` on the recipient
 * precisely so a person's act and a system's delivery would stay
 * distinguishable, on the argument that a plausible-but-false entry in an
 * append-only audit log is the worst thing that can be in one. The trail was
 * clean; the screen said it anyway, because this client's wire types never
 * declared either field and the component could not read what it was sent.
 *
 * These pin the three places that made the claim, and — as importantly — that
 * an ordinary act stage still says "acted". A fix that renamed everything to
 * "Sent" would satisfy the bug report and lose the distinction in the other
 * direction.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import { RouteFanout } from '@/components/documents/route-fanout';
import type { RouteRecipient, RouteStep } from '@/components/documents/routing-wire';

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

/** A CLOSED row — the state both an act and a delivery end in. */
function closed(
  overrides: Partial<RouteRecipient> & Pick<RouteRecipient, 'id' | 'step_id'>
): RouteRecipient {
  return {
    document_id: 1,
    route_id: 8,
    profile_id: 12,
    ou_id: null,
    parent_recipient_id: null,
    created_by_event_id: 15,
    closed_by_event_id: 16,
    open: false,
    closed_by_delivery: false,
    created_at: '2026-08-25T00:26:43Z',
    ...overrides,
  } as RouteRecipient;
}

function renderFanout(steps: RouteStep[], recipients: RouteRecipient[]) {
  render(
    <RouteFanout
      route={{
        id: 8,
        document_id: 1,
        title: 'Demo circular',
        created_by: 3,
        created_at: '2026-08-25T00:26:43Z',
        steps,
      }}
      recipients={recipients}
      roleNames={new Map()}
      profileNames={new Map()}
      viewerProfileId={12}
    />
  );
}

describe('a delivery is not an act', () => {
  it('labels a delivered recipient "Sent", not "Acted"', () => {
    renderFanout(
      [step({ id: 10, satisfied_by: 'delivery' })],
      [closed({ id: 14, step_id: 10, closed_by_delivery: true })]
    );

    expect(screen.getByText('Sent')).toBeInTheDocument();
    expect(screen.queryByText('Acted')).not.toBeInTheDocument();
  });

  it('still labels a genuine act "Acted"', () => {
    // The other direction. Renaming everything to "Sent" would close the bug
    // report and lose the distinction the whole fix is about.
    renderFanout(
      [step({ id: 10, satisfied_by: 'act' })],
      [closed({ id: 14, step_id: 10, closed_by_delivery: false })]
    );

    expect(screen.getByText('Acted')).toBeInTheDocument();
    expect(screen.queryByText('Sent')).not.toBeInTheDocument();
  });

  it('counts a delivery stage as sent rather than acted', () => {
    renderFanout(
      [step({ id: 10, satisfied_by: 'delivery' })],
      [
        closed({ id: 14, step_id: 10, profile_id: 10, closed_by_delivery: true }),
        closed({ id: 15, step_id: 10, profile_id: 12, closed_by_delivery: true }),
      ]
    );

    expect(screen.getByText('2 sent')).toBeInTheDocument();
    expect(screen.queryByText('2 acted')).not.toBeInTheDocument();
  });

  it('says a route of deliveries was delivered, not acted on', () => {
    renderFanout(
      [step({ id: 10, satisfied_by: 'delivery' })],
      [closed({ id: 14, step_id: 10, closed_by_delivery: true })]
    );

    expect(
      screen.getByText(/Every chain of this route has been delivered/)
    ).toBeInTheDocument();
  });

  it('says a MIXED route is closed, claiming neither verb of the other half', () => {
    // The one sentence true of both. Calling a mixed route "acted on" is false
    // for its delivery stages and "delivered" is false for its act stages.
    renderFanout(
      [
        step({ id: 10, position: 1, satisfied_by: 'act' }),
        step({ id: 11, position: 2, satisfied_by: 'delivery' }),
      ],
      [
        closed({ id: 14, step_id: 10, profile_id: 10, closed_by_delivery: false }),
        closed({ id: 15, step_id: 11, profile_id: 12, closed_by_delivery: true }),
      ]
    );

    expect(screen.getByText(/Every chain of this route is closed/)).toBeInTheDocument();
  });

  it('still says "acted on" when every stage really was an act', () => {
    renderFanout(
      [step({ id: 10, satisfied_by: 'act' })],
      [closed({ id: 14, step_id: 10, closed_by_delivery: false })]
    );

    expect(
      screen.getByText(/Every chain of this route has been acted on/)
    ).toBeInTheDocument();
  });
});
