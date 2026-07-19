import React from 'react';
import { render, screen } from '@testing-library/react';
import axe from 'axe-core';
import { Chart } from '@amroksaleh/ui/chart';

/**
 * recharts' ResponsiveContainer measures its parent via ResizeObserver and
 * renders nothing until it sees a non-zero size — jsdom never reports one.
 * Stub it to a fixed-size container so the underlying chart (Bar/Line/etc.)
 * actually mounts for these tests.
 */
jest.mock('recharts', () => {
  const actual = jest.requireActual('recharts');
  return {
    ...actual,
    ResponsiveContainer: ({ children }: { children: React.ReactNode }) => (
      <div style={{ width: 400, height: 300 }}>{children}</div>
    ),
  };
});

const monthly = [
  { month: 'Jan', revenue: 186, cost: 120 },
  { month: 'Feb', revenue: 205, cost: 130 },
];

describe('Chart a11y', () => {
  it('has zero axe violations for a bar chart', async () => {
    const { container } = render(
      <Chart
        type="bar"
        data={monthly}
        xKey="month"
        series={[
          { key: 'revenue', label: 'Revenue', color: 1 },
          { key: 'cost', label: 'Cost', color: 2 },
        ]}
      />
    );
    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('exposes an img role labelled with the series names', () => {
    render(
      <Chart
        type="line"
        data={monthly}
        xKey="month"
        series={[{ key: 'revenue', label: 'Revenue', color: 1 }]}
      />
    );
    expect(screen.getByRole('img', { name: 'Revenue' })).toBeInTheDocument();
  });

  it('renders area and pie chart types without throwing', () => {
    const { rerender } = render(
      <Chart
        type="area"
        data={monthly}
        xKey="month"
        series={[{ key: 'revenue', label: 'Revenue', color: 1 }]}
      />
    );
    expect(screen.getByRole('img')).toBeInTheDocument();

    rerender(
      <Chart
        type="pie"
        data={[{ channel: 'Direct', value: 42 }]}
        xKey="channel"
        series={[{ key: 'value', label: 'Share', color: 1 }]}
      />
    );
    expect(screen.getByRole('img')).toBeInTheDocument();
  });
});
