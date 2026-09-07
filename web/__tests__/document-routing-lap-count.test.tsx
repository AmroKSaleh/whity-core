import React from 'react';
import { render, screen } from '@testing-library/react';
import { RouteFanout } from '@/components/documents/route-fanout';
import type { RouteRecipient, RouteStep } from '@/components/documents/routing-wire';

/**
 * A document that has been round the loop says so (#1037).
 *
 * WHAT WAS INVISIBLE. A backwards reject edge — "to the author, to fix" — is the
 * most common approval design there is, and nothing counted the laps. A document
 * on its ninth rejection read exactly like one on its first: the inbox shows one
 * open item, the trail is a long list nobody reads to the end, and no number
 * anywhere said it had been round nine times. The failure is a document quietly
 * ping-ponging for six weeks with nobody able to see why.
 *
 * The count is derived on the server from the trail's verdict rows. These tests
 * are about the half that makes it matter: whether a person looking at the
 * screen can see it.
 */

function step(overrides: Partial<RouteStep> & Pick<RouteStep, 'id'>): RouteStep {
  return {
    position: 1,
    rule_kind: 'group',
    rule_config: { group_id: 5 },
    label: null,
    decision: false,
    decision_quorum: null,
    satisfied_by: 'act',
    rejection_count: 0,
    ...overrides,
  } as RouteStep;
}

function renderFanout(steps: RouteStep[], recipients: RouteRecipient[] = []) {
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

describe('a stage that has sent the document back says so', () => {
  it('shows the lap count on a stage that has rejected', () => {
    renderFanout([step({ id: 10, decision: true, rejection_count: 3 })]);

    expect(screen.getByText('sent back 3x')).toBeInTheDocument();
  });

  it('says nothing on a stage that has never rejected', () => {
    // The server publishes 0 as a real answer, but a "sent back 0 times" badge
    // on every stage is noise that would bury the one stage where it is not
    // zero. Absence is unambiguous here: a stage that HAD been round would be
    // carrying the badge.
    renderFanout([step({ id: 10, decision: true, rejection_count: 0 })]);

    expect(screen.queryByText(/sent back/)).not.toBeInTheDocument();
  });

  it('shows it on a stage holding no recipients at all', () => {
    // THE CASE THAT WAS ACTUALLY INVISIBLE. After a rejection sends the document
    // backwards, the rejecting stage has no open rows — so it renders "Not
    // reached yet" and every other signal on the card is silent. That is exactly
    // the state a reader needs the lap count for, which is why the badge sits
    // outside the `rows.length === 0` branch rather than beside the counts.
    renderFanout([step({ id: 10, decision: true, rejection_count: 2 })], []);

    expect(screen.getByText('Not reached yet')).toBeInTheDocument();
    expect(screen.getByText('sent back 2x')).toBeInTheDocument();
  });

  it('counts per stage rather than per route', () => {
    // Attributed to the stage that REJECTED, not to the one the document was
    // sent back to — which never rejected anything. A route-level total would
    // lose that, and "which gate keeps refusing this" is the question a reader
    // actually has.
    renderFanout([
      step({ id: 10, position: 1, rejection_count: 0 }),
      step({ id: 11, position: 2, decision: true, rejection_count: 4 }),
    ]);

    const badges = screen.getAllByText(/sent back/);
    expect(badges).toHaveLength(1);
    expect(badges[0]).toHaveTextContent('sent back 4x');
  });
});
