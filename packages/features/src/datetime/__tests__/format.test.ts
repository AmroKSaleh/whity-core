/**
 * The pure half of the one date path (#1068).
 *
 * Two of these pin behaviour that CHANGED when six implementations were
 * consolidated into one, and both changes are invisible without a test:
 *
 *  - a wire timestamp is read as UTC. The old `formatRecordDateTime` used a bare
 *    `new Date(value)`, which reads an offset-less string in the LOCAL zone, so
 *    a UTC instant from PostgreSQL printed hours out on any machine that is not
 *    on UTC — while the status page, which normalised first, printed the same
 *    value correctly.
 *
 *  - the formatters return null rather than the raw value for something they
 *    cannot parse. That fallback is how a hiding setting leaks: the caller
 *    writes `?? raw` and prints the timestamp the formatter declined to.
 */

import {
  formatDate,
  formatDateTime,
  isDateFieldName,
  parseWireDate,
  relativeAge,
} from '../format'

describe('parseWireDate', () => {
  it('reads an offset-less wire timestamp as UTC', () => {
    // PostgreSQL's shape: a space, no zone designator. Every writer in src/
    // uses gmdate() or lets the database default to UTC, so the missing
    // designator is supplied rather than guessed at.
    expect(parseWireDate('2026-08-25 14:02:11')?.toISOString()).toBe('2026-08-25T14:02:11.000Z')
  })

  it('reads an ISO instant as itself', () => {
    expect(parseWireDate('2026-08-25T14:02:11Z')?.toISOString()).toBe('2026-08-25T14:02:11.000Z')
  })

  it('leaves a value that carries its own offset alone', () => {
    // Appending `Z` to this would produce nonsense, which is why the check is
    // for a zone DESIGNATOR rather than merely for a `T`.
    expect(parseWireDate('2026-08-25T14:02:11+03:00')?.toISOString()).toBe(
      '2026-08-25T11:02:11.000Z'
    )
  })

  it('reads a bare date as midnight UTC rather than as carrying an offset', () => {
    // A bare date is all hyphens; the `-` in it must not be read as the start
    // of a negative UTC offset.
    expect(parseWireDate('2026-08-25')?.toISOString()).toBe('2026-08-25T00:00:00.000Z')
  })

  it('returns null for absent and unparseable values', () => {
    expect(parseWireDate(null)).toBeNull()
    expect(parseWireDate(undefined)).toBeNull()
    expect(parseWireDate('')).toBeNull()
    expect(parseWireDate('not a date')).toBeNull()
  })
})

describe('formatDate / formatDateTime', () => {
  it('formats in the locale it is given, not the runtime default', () => {
    expect(formatDate('2026-08-25 14:02:11', 'en-GB')).toBe('25/08/2026')
    expect(formatDate('2026-08-25 14:02:11', 'en-US')).toBe('8/25/2026')
  })

  it('returns null rather than the raw value it could not parse', () => {
    // The one behavioural change from the function this replaces, and the
    // reason for it: a caller that writes `?? raw` around a raw-returning
    // formatter has bypassed every gate above it.
    expect(formatDate('not a date', 'en-GB')).toBeNull()
    expect(formatDateTime('not a date', 'en-GB')).toBeNull()
  })

  it('returns null for an absent value', () => {
    expect(formatDate(null, 'en-GB')).toBeNull()
    expect(formatDateTime(undefined, 'en-GB')).toBeNull()
  })
})

describe('relativeAge', () => {
  const now = Date.parse('2026-08-25T12:00:00Z')

  it('buckets by magnitude', () => {
    expect(relativeAge('2026-08-25T11:59:30Z', now)).toEqual({ unit: 'seconds', n: 30 })
    expect(relativeAge('2026-08-25T11:30:00Z', now)).toEqual({ unit: 'minutes', n: 30 })
    expect(relativeAge('2026-08-24T12:00:00Z', now)).toEqual({ unit: 'hours', n: 24 })
    expect(relativeAge('2026-08-20T12:00:00Z', now)).toEqual({ unit: 'days', n: 5 })
  })

  it('never goes negative', () => {
    // A client clock a few seconds ahead of the server must read "0s ago", not
    // "in 4 seconds" — a whole tense the translated strings do not have.
    expect(relativeAge('2026-08-25T12:00:04Z', now)).toEqual({ unit: 'seconds', n: 0 })
  })

  it('returns null for an absent or unparseable value', () => {
    expect(relativeAge(null, now)).toBeNull()
    expect(relativeAge('nonsense', now)).toBeNull()
  })
})

describe('isDateFieldName', () => {
  // Kept in step BY HAND with DateDisplayScanner::isDateFieldName in PHP, which
  // the CI guard uses. Both list these same examples, so a change to one that
  // is not mirrored fails a test rather than going quiet.
  it.each([
    'created_at',
    'updated_at',
    'occurred_at',
    'createdAt',
    'birthDate',
    'birth_date',
    'timestamp',
    'expires_at',
    'grace_until',
    'releasedAt',
    'last_seen_at',
    'issued_on',
    'revoked_on',
    'stage_on',
  ])('treats %s as a timestamp', (name) => {
    expect(isDateFieldName(name)).toBe(true)
  })

  it.each(['candidate_name', 'update', 'mandate', 'seat', 'format', 'name', 'status'])(
    'does not treat %s as a timestamp',
    (name) => {
      // A false positive hides a column a tenant asked to see, so a name that
      // merely CONTAINS a date word must not match.
      expect(isDateFieldName(name)).toBe(false)
    }
  )
})
