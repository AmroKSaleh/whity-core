'use client';

import { useMemo } from 'react';

interface DataPoint {
  date: string;
  count: number;
}

interface StatsChartProps {
  data: DataPoint[];
  label: string;
  color?: string;
}

export function StatsChart({ data, label, color = 'currentColor' }: StatsChartProps) {
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
                {d.count} {label}
              </div>
            </div>
          );
        })}
      </div>
      <div className="flex justify-between mt-2 px-2 text-[10px] text-muted-foreground border-t pt-2">
        <span>{new Date(data[0].date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}</span>
        <span>{new Date(data[data.length - 1].date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}</span>
      </div>
    </div>
  );
}
