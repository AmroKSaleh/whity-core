'use client';

import { useMemo } from 'react';

interface DataPoint {
  date: string;
  count: number;
}

interface StatsChartProps {
  data: DataPoint[];
  label: string;
  /**
   * Tooltip text for one bar. A function, not a template, because the count
   * and the term sit inside one phrase and languages order them differently —
   * `{count} {label}` spliced together freezes English word order.
   *
   * A prop rather than a translation hook because this file is a PUBLISHED
   * registry item: a downstream consumer installs it verbatim, where
   * `@amroksaleh/features` need not exist.
   */
  tooltipLabel?: (count: number, label: string) => string;
  /**
   * The first and last x-axis labels, ALREADY FORMATTED, or omitted to render
   * no axis strip at all.
   *
   * A prop for the reason `tooltipLabel` is one, and then a second reason.
   * This file is a PUBLISHED registry item installed verbatim downstream, so it
   * cannot import `@amroksaleh/features` — which means it can reach neither the
   * reader's resolved language nor the tenant's `ui.hide_dates` preference
   * (#1068). It used to call `toLocaleDateString(undefined, …)`, i.e. format
   * two dates in the BROWSER's locale, in a component that had no way to know
   * it was wrong to.
   *
   * Omitting it renders no strip, which is also what the caller does when the
   * tenant hides dates: a chart is a shape, and it still reads as a rising or
   * falling one with no dates under it.
   */
  axisLabels?: { start: string; end: string };
  color?: string;
}

export function StatsChart({
  data,
  label,
  color = 'currentColor',
  tooltipLabel = (count, term) => `${count} ${term}`,
  axisLabels,
}: StatsChartProps) {
  const max = useMemo(() => Math.max(...data.map((d) => d.count), 5), [data]);

  if (data.length === 0) {
    return (
      <div className="flex items-center justify-center h-full text-muted-foreground text-sm italic">
        No data for the last 7 days
      </div>
    );
  }

  return (
    <div className="w-full h-full flex flex-col">
      <div className="flex-1 flex items-end gap-1 px-2">
        {data.map((d, i) => {
          const height = (d.count / max) * 100;
          return (
            <div key={i} className="flex-1 group relative flex flex-col items-center">
              <div
                className="w-full rounded-t-sm transition-all duration-500 ease-out hover:opacity-80"
                style={{
                  height: `${height}%`,
                  backgroundColor: color,
                  opacity: 0.7 + (i / data.length) * 0.3
                }}
              />
              <div className="absolute bottom-full mb-2 hidden group-hover:block bg-popover text-popover-foreground text-[10px] px-1.5 py-0.5 rounded border shadow-sm whitespace-nowrap z-10">
                {tooltipLabel(d.count, label)}
              </div>
            </div>
          );
        })}
      </div>
      {axisLabels ? (
        <div className="flex justify-between mt-2 px-2 text-[10px] text-muted-foreground border-t pt-2">
          <span>{axisLabels.start}</span>
          <span>{axisLabels.end}</span>
        </div>
      ) : null}
    </div>
  );
}
