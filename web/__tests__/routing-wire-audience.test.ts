/**
 * #1015 — reading the two rule configs the composer now authors.
 *
 * Every expectation here is written out by hand from what the SERVER's resolvers
 * document, not computed by calling the function under test with a
 * reimplementation beside it:
 *
 *  - `GroupRuleResolver::requireGroupId()` — `{group_id}`, accepting an integer
 *    or its decimal string, because a JSONB round trip returns either depending
 *    on the driver, and refusing one would make a route resolvable on PostgreSQL
 *    and broken on the offline SQLite engine.
 *  - `ExplicitRuleResolver::requireProfileIds()` — `{profile_ids: [...]}`, same
 *    two spellings per entry, de-duplicated, and an EMPTY list refused rather
 *    than treated as "nobody".
 *
 * The point of the whole file is the last of those: `isStepConfigured` is what
 * stands between an author and the 422 that #1015 exists to remove, so it is
 * checked against each kind's own emptiness, not against a shared idea of "has
 * some config".
 */

import {
  EXPLICIT_KIND,
  GROUP_KIND,
  configuredGroupId,
  configuredProfileIds,
  configuredRoleId,
  isCoreConfiguredKind,
  isStepConfigured,
} from '@/components/documents/routing-wire';

describe('configuredGroupId', () => {
  it('reads an integer group id', () => {
    expect(configuredGroupId({ group_id: 7 })).toBe(7);
  });

  it('reads the decimal-string spelling a JSONB round trip can return', () => {
    expect(configuredGroupId({ group_id: '7' })).toBe(7);
  });

  it.each([
    ['absent', {}],
    ['null', { group_id: null }],
    ['zero', { group_id: 0 }],
    ['negative', { group_id: -3 }],
    ['fractional', { group_id: 1.5 }],
    ['not a number', { group_id: 'seven' }],
    ['padded string', { group_id: ' 7 ' }],
    ['another rule’s key', { role_id: 7 }],
  ])('returns null for a %s group id', (_case, config) => {
    expect(configuredGroupId(config)).toBeNull();
  });
});

describe('configuredProfileIds', () => {
  it('reads the ids in the order they were written', () => {
    expect(configuredProfileIds({ profile_ids: [13, 11, 12] })).toEqual([13, 11, 12]);
  });

  it('accepts both spellings of the same number, side by side', () => {
    expect(configuredProfileIds({ profile_ids: [11, '12'] })).toEqual([11, 12]);
  });

  it('de-duplicates, including across the two spellings', () => {
    // Hand-computed: 11 first, its string twin dropped, then 12.
    expect(configuredProfileIds({ profile_ids: [11, '11', 12, 11] })).toEqual([11, 12]);
  });

  it('drops a malformed entry and keeps the rest, rather than reading nothing', () => {
    expect(configuredProfileIds({ profile_ids: [11, 'nope', 0, -2, null, 12] })).toEqual([11, 12]);
  });

  it.each([
    ['absent', {}],
    ['null', { profile_ids: null }],
    ['an object', { profile_ids: { 0: 11 } }],
    ['a bare number', { profile_ids: 11 }],
    ['empty', { profile_ids: [] }],
  ])('returns an empty list for %s', (_case, config) => {
    expect(configuredProfileIds(config)).toEqual([]);
  });
});

describe('isStepConfigured', () => {
  it('requires a role for the two role kinds', () => {
    expect(isStepConfigured('role', {})).toBe(false);
    expect(isStepConfigured('role', { role_id: 3 })).toBe(true);
    expect(isStepConfigured('role_below_actor', {})).toBe(false);
    expect(isStepConfigured('role_below_actor', { role_id: '3' })).toBe(true);
  });

  it('requires a group id for the group kind — the exact case that used to 422', () => {
    expect(isStepConfigured(GROUP_KIND, {})).toBe(false);
    expect(isStepConfigured(GROUP_KIND, { group_id: 7 })).toBe(true);
  });

  it('requires at least one person for the explicit kind', () => {
    expect(isStepConfigured(EXPLICIT_KIND, {})).toBe(false);
    // The server refuses an empty list outright, so the client must not send one.
    expect(isStepConfigured(EXPLICIT_KIND, { profile_ids: [] })).toBe(false);
    expect(isStepConfigured(EXPLICIT_KIND, { profile_ids: [11] })).toBe(true);
  });

  it('does not judge a kind it cannot author — a plugin owns that answer', () => {
    expect(isStepConfigured('acme:committee', {})).toBe(true);
  });

  it('is not fooled by another kind’s key surviving a switch', () => {
    // Carrying config across a kind change is the bug the composer guards against
    // by clearing it; this is the second line of defence.
    expect(isStepConfigured(GROUP_KIND, { role_id: 3 })).toBe(false);
    expect(isStepConfigured('role', { group_id: 7 })).toBe(false);
    expect(isStepConfigured(EXPLICIT_KIND, { group_id: 7 })).toBe(false);
  });
});

describe('isCoreConfiguredKind', () => {
  it('claims exactly core’s four kinds', () => {
    expect(['role', 'role_below_actor', 'group', 'explicit'].map(isCoreConfiguredKind)).toEqual([
      true,
      true,
      true,
      true,
    ]);
  });

  it('claims nothing a plugin registered', () => {
    expect(isCoreConfiguredKind('acme:committee')).toBe(false);
    expect(isCoreConfiguredKind('')).toBe(false);
  });
});

describe('configuredRoleId still reads what it always did', () => {
  it('accepts both spellings and refuses everything else', () => {
    expect(configuredRoleId({ role_id: 3 })).toBe(3);
    expect(configuredRoleId({ role_id: '3' })).toBe(3);
    expect(configuredRoleId({ role_id: 0 })).toBeNull();
    expect(configuredRoleId({})).toBeNull();
  });
});
