/**
 * #1041 — the three wire readings that a decision step turns on.
 *
 * These are unit tests on `routing-wire.ts` rather than on a screen, because
 * each one is a rule a screen can get wrong while rendering perfectly:
 *
 *  - `readDecided` must report what the STEP concluded and can never be talked
 *    into reporting what the CALLER submitted;
 *  - `effectiveQuorum` must resolve step-then-tenant and fall back to the
 *    STRICTEST rule, never the most permissive;
 *  - `routingMeta` must keep working against a server that has never heard of
 *    decisions, because absence of a verdict has never meant a verdict.
 *
 * Every expectation here is a LITERAL. None of them recomputes the thing it is
 * checking — a test that re-derives `intdiv(n, 2) + 1` to check `intdiv(n, 2)
 * + 1` passes whatever the function does.
 */

import {
  effectiveQuorum,
  readDecided,
  routingMeta,
  stepCohort,
  ROUTE_QUORUMS,
  ROUTE_VERDICTS,
  type DocumentRoute,
  type InboxItem,
  type RouteRecipient,
  type RouteStep,
} from '@/components/documents/routing-wire';

function step(overrides: Partial<RouteStep> = {}): RouteStep {
  return {
    id: 11,
    position: 1,
    rule_kind: 'role',
    rule_config: { role_id: 3 },
    label: null,
    decision: true,
    decision_quorum: null,
    ...overrides,
  };
}

function route(overrides: Partial<DocumentRoute> = {}): DocumentRoute {
  return {
    id: 5,
    document_id: 318,
    title: 'Purchase order 9912',
    created_by: 1,
    created_at: '2026-08-20T09:00:00Z',
    steps: [step()],
    edges: [],
    default_quorum: 'all',
    ...overrides,
  };
}

function recipient(overrides: Partial<RouteRecipient> & { id: number }): RouteRecipient {
  return {
    document_id: 318,
    route_id: 5,
    step_id: 11,
    profile_id: 7,
    ou_id: null,
    parent_recipient_id: null,
    created_by_event_id: 90,
    closed_by_event_id: null,
    open: true,
    created_at: '2026-08-20T09:00:00Z',
    ...overrides,
  };
}

// ---------------------------------------------------------------------------
// The vocabularies
// ---------------------------------------------------------------------------

describe('the closed vocabularies', () => {
  it('has exactly the two verdicts migration 119 CHECK-constrains', () => {
    expect([...ROUTE_VERDICTS]).toEqual(['approved', 'rejected']);
  });

  it('has exactly the three quorums, and no rejection quorum among them', () => {
    expect([...ROUTE_QUORUMS]).toEqual(['all', 'any', 'majority']);
    // Rejection routing is DERIVED — the reject edge fires when the approval
    // quorum becomes arithmetically unreachable. A quorum named for rejection
    // would be a control over a rule that does not exist.
    expect([...ROUTE_QUORUMS].some((q) => /reject/i.test(q))).toBe(false);
  });
});

// ---------------------------------------------------------------------------
// readDecided — the one that matters
// ---------------------------------------------------------------------------

describe('readDecided', () => {
  it('reports the step’s conclusion', () => {
    expect(readDecided({ decided: 'approved' })).toBe('approved');
    expect(readDecided({ decided: 'rejected' })).toBe('rejected');
  });

  it('reports NOTHING while a quorum is still short', () => {
    // Two of three approvals under `all`. The caller approved; the step did not.
    expect(readDecided({ decided: null, data: { verdict: 'approved' } })).toBeNull();
  });

  it('cannot be talked into reporting the caller’s own verdict instead', () => {
    // Every shape a real 201 can arrive in where the CALLER said "approved" and
    // the STEP concluded nothing. If any of these came back 'approved', two of
    // three approvers would be told a document was authorised before it was.
    const shapes: unknown[] = [
      { decided: null, data: { verdict: 'approved' } },
      { decided: null, verdict: 'approved' },
      // A server predating #1030 sends no `decided` key at all. Missing is not
      // granted: the safe reading of an absent conclusion is that there is none.
      { data: { verdict: 'approved' }, resolved: 0, delivered: 0 },
      { verdict: 'rejected' },
    ];

    for (const body of shapes) {
      expect(readDecided(body)).toBeNull();
    }
  });

  it('refuses a value that is not in the vocabulary', () => {
    expect(readDecided({ decided: 'approved_by_dean' })).toBeNull();
    expect(readDecided({ decided: 'APPROVED' })).toBeNull();
    expect(readDecided({ decided: true })).toBeNull();
    expect(readDecided({ decided: 1 })).toBeNull();
  });

  it('survives a body that is not an object at all', () => {
    expect(readDecided(null)).toBeNull();
    expect(readDecided(undefined)).toBeNull();
    expect(readDecided('approved')).toBeNull();
  });
});

// ---------------------------------------------------------------------------
// effectiveQuorum
// ---------------------------------------------------------------------------

describe('effectiveQuorum', () => {
  it('prefers the step’s own rule over the tenant’s', () => {
    expect(effectiveQuorum(step({ decision_quorum: 'any' }), route({ default_quorum: 'all' }))).toBe(
      'any'
    );
    expect(
      effectiveQuorum(step({ decision_quorum: 'majority' }), route({ default_quorum: 'any' }))
    ).toBe('majority');
  });

  it('falls through to the tenant’s rule when the step names none', () => {
    // NULL on the step is "follow the tenant", never "no quorum".
    expect(effectiveQuorum(step({ decision_quorum: null }), route({ default_quorum: 'any' }))).toBe(
      'any'
    );
    expect(
      effectiveQuorum(step({ decision_quorum: null }), route({ default_quorum: 'majority' }))
    ).toBe('majority');
  });

  it('falls back to the STRICTEST rule when either value is unrecognised', () => {
    // A foreign string means something upstream is broken — a CHECK constraint
    // and a settings validator both had to be got past. Naming a laxer rule than
    // the engine will apply is how a screen tells somebody their single approval
    // is enough when it is not.
    const broken = { decision_quorum: 'one_of_them' } as unknown as Partial<RouteStep>;
    expect(effectiveQuorum(step(broken), route({ default_quorum: 'any' }))).toBe('any');
    expect(
      effectiveQuorum(step(broken), route({ default_quorum: 'nonsense' as never }))
    ).toBe('all');
  });

  it('falls back to `all` against a server that sends no default_quorum', () => {
    // An install running a build older than #1041. Typed as required; not
    // trusted at runtime.
    const older = route();
    delete (older as Partial<DocumentRoute>).default_quorum;
    expect(effectiveQuorum(step({ decision_quorum: null }), older)).toBe('all');
  });
});

// ---------------------------------------------------------------------------
// stepCohort
// ---------------------------------------------------------------------------

describe('stepCohort', () => {
  const mine = recipient({ id: 1, created_by_event_id: 90 });

  it('is the rows ONE act opened at ONE step', () => {
    const rows = [
      mine,
      recipient({ id: 2, profile_id: 8, created_by_event_id: 90 }),
      recipient({ id: 3, profile_id: 9, created_by_event_id: 90 }),
      // A different act at the same step — a second chain, deciding for itself.
      recipient({ id: 4, profile_id: 10, created_by_event_id: 91 }),
      // The same act's rows at a different step.
      recipient({ id: 5, profile_id: 11, step_id: 12, created_by_event_id: 90 }),
      // Another route entirely.
      recipient({ id: 6, profile_id: 12, route_id: 6, created_by_event_id: 90 }),
    ];

    expect(stepCohort(rows, mine).map((r) => r.id)).toEqual([1, 2, 3]);
  });

  it('counts rows that are already closed — the cohort is who was ASKED', () => {
    const rows = [
      mine,
      recipient({ id: 2, profile_id: 8, open: false, closed_by_event_id: 99 }),
    ];
    expect(stepCohort(rows, mine)).toHaveLength(2);
  });
});

// ---------------------------------------------------------------------------
// routingMeta and the decision flag
// ---------------------------------------------------------------------------

function item(meta: Record<string, unknown> | null): InboxItem {
  return {
    id: '41',
    title: 'Purchase order 9912',
    subtitle: 'Purchase order',
    timestamp: '2026-08-20T09:00:00Z',
    status: 'Awaiting your decision',
    resource_type: 'document',
    resource_id: '318',
    meta,
  };
}

const BASE_META = {
  route_id: 5,
  step_id: 12,
  document_id: 318,
  open: true,
  arrived_by: 'forwarded',
};

describe('routingMeta', () => {
  it('reads the decision flag', () => {
    expect(routingMeta(item({ ...BASE_META, decision: true }))?.decision).toBe(true);
    expect(routingMeta(item({ ...BASE_META, decision: false }))?.decision).toBe(false);
  });

  it('still reads an item from a server that has never heard of decisions', () => {
    // The link is the point: requiring `decision` in the guard would drop the
    // WHOLE meta, and this screen renders a meta-less item as plain text — so a
    // routing item on an older server would silently lose its link rather than
    // lose a chip.
    const meta = routingMeta(item(BASE_META));
    expect(meta).not.toBeNull();
    expect(meta?.document_id).toBe(318);
    expect(meta?.decision).toBe(false);
  });

  it('does not read a non-boolean as a decision', () => {
    expect(routingMeta(item({ ...BASE_META, decision: 'yes' }))?.decision).toBe(false);
    expect(routingMeta(item({ ...BASE_META, decision: 1 }))?.decision).toBe(false);
    expect(routingMeta(item({ ...BASE_META, decision: null }))?.decision).toBe(false);
  });

  it('still returns null for an item that is not routing’s at all', () => {
    expect(routingMeta(item({ ticket: 'abc', decision: true }))).toBeNull();
    expect(routingMeta(item(null))).toBeNull();
  });
});
