/**
 * The gate itself (#1068).
 *
 * `ui.hide_dates` promises a tenant that no date reaches any screen, and this
 * hook is where that promise is kept. The assertions below are deliberately
 * about the CONTRACT rather than about a rendering: a call site reads `hidden`
 * to drop a whole column or row, and reads `null` from the formatters to leave
 * a label out — so if either stopped being true, seventy call sites would each
 * silently start printing again.
 */

import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'

import { DateDisplayProvider } from '../date-display-context'
import { useDateDisplay } from '../useDateDisplay'

const WIRE = '2026-08-25 14:02:11'

function Probe() {
  const { hidden, date, dateTime, age, relative, dateColumns } = useDateDisplay()

  const columns = dateColumns<{ created_at: string }>([
    { id: 'created', header: 'Created', value: (row) => row.created_at },
  ])

  return (
    <dl>
      <dt>hidden</dt>
      <dd data-testid="hidden">{String(hidden)}</dd>
      <dt>date</dt>
      <dd data-testid="date">{date(WIRE) ?? 'NULL'}</dd>
      <dt>dateTime</dt>
      <dd data-testid="dateTime">{dateTime(WIRE) ?? 'NULL'}</dd>
      <dt>age</dt>
      <dd data-testid="age">{age(WIRE)?.unit ?? 'NULL'}</dd>
      <dt>relative</dt>
      <dd data-testid="relative">
        {relative(WIRE, {
          seconds: (n) => `${n}s`,
          minutes: (n) => `${n}m`,
          hours: (n) => `${n}h`,
          days: (n) => `${n}d`,
        }) ?? 'NULL'}
      </dd>
      <dt>columns</dt>
      <dd data-testid="columns">{String(columns.length)}</dd>
    </dl>
  )
}

function mount(node: ReactNode) {
  return render(<>{node}</>)
}

describe('useDateDisplay', () => {
  it('formats every shape when the tenant has not hidden dates', () => {
    mount(
      <DateDisplayProvider hidden={false}>
        <Probe />
      </DateDisplayProvider>
    )

    expect(screen.getByTestId('hidden')).toHaveTextContent('false')
    expect(screen.getByTestId('date')).not.toHaveTextContent('NULL')
    expect(screen.getByTestId('dateTime')).not.toHaveTextContent('NULL')
    expect(screen.getByTestId('age')).not.toHaveTextContent('NULL')
    expect(screen.getByTestId('relative')).not.toHaveTextContent('NULL')
    expect(screen.getByTestId('columns')).toHaveTextContent('1')
  })

  it('returns null from EVERY formatter when the tenant has hidden dates', () => {
    mount(
      <DateDisplayProvider hidden>
        <Probe />
      </DateDisplayProvider>
    )

    expect(screen.getByTestId('hidden')).toHaveTextContent('true')
    // All four, not three: the one that is forgotten is the one that leaks.
    expect(screen.getByTestId('date')).toHaveTextContent('NULL')
    expect(screen.getByTestId('dateTime')).toHaveTextContent('NULL')
    expect(screen.getByTestId('age')).toHaveTextContent('NULL')
    // Relative phrasing is NOT a middle ground: "3m ago" is still a date.
    expect(screen.getByTestId('relative')).toHaveTextContent('NULL')
    // A table drops the whole column rather than showing em dashes under a
    // header that says "Created".
    expect(screen.getByTestId('columns')).toHaveTextContent('0')
  })

  /**
   * An unwired client — Storybook, an isolated unit test, a Vite harness that
   * has not mounted the provider — behaves EXACTLY as it did before this
   * feature existed. That is what makes the hook safe to adopt one screen at a
   * time, and it is why the context is non-throwing.
   */
  it('shows dates when no provider is mounted at all', () => {
    mount(<Probe />)

    expect(screen.getByTestId('hidden')).toHaveTextContent('false')
    expect(screen.getByTestId('dateTime')).not.toHaveTextContent('NULL')
  })
})
