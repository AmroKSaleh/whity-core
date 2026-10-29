import * as React from "react"
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  LabelList,
  Legend,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts"

import { cn } from "./utils"

/**
 * Minimal `--chart-1..5`-token-wired chart primitive built on recharts.
 *
 * Colors are NEVER passed as hex/rgb: each series picks one of the five
 * semantic chart tokens (`--chart-1` .. `--chart-5`, defined in
 * `globals.css`) via `var(--chart-N)`, so the same series set re-themes with
 * the rest of the design system (including dark mode) with zero recharts
 * config changes.
 *
 * This is intentionally small — one shared primitive for both the plugin
 * Blocks DSL `chart` block type and (future) first-party chart call sites
 * (e.g. `StatsChart`) to build on, so a second, divergent recharts wrapper
 * never needs to exist.
 */

export type ChartColor = 1 | 2 | 3 | 4 | 5

export interface ChartSeries {
  /** Key into each row of `data`. */
  key: string
  /** Legend / tooltip label. */
  label: string
  /** One of the five semantic chart tokens. */
  color: ChartColor
}

export type ChartType = "bar" | "line" | "area" | "pie"

export interface ChartProps extends React.ComponentProps<"div"> {
  type: ChartType
  data: Record<string, string | number>[]
  series: ChartSeries[]
  /** Category key for the x-axis; ignored for `type="pie"`. */
  xKey?: string
  /** Fixed render height in pixels (the width always fills the container). */
  height?: number
  /** Show the legend. Defaults to on; a single series rarely needs one. */
  legend?: boolean
  /** For `bar`: the index of the one bar whose value is printed above it (BarChart card). */
  highlight?: number
  /** Formats the highlighted value. */
  formatValue?: (value: number) => string
  /** For `pie`: draw a ring instead of a full disc. */
  donut?: boolean
}

/** The next round axis end at or above `value` (38.5 → 40, 5346 → 6000). */
function niceCeil(value: number): number {
  if (value <= 0) return 1
  const step = 10 ** Math.floor(Math.log10(value))
  const m = value / step
  return ([1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].find((n) => m <= n) ?? 10) * step
}

function chartColorVar(color: ChartColor): string {
  return `var(--chart-${color})`
}

function ChartTooltipContent({
  active,
  payload,
  label,
}: {
  active?: boolean
  payload?: { name?: string; value?: string | number; color?: string }[]
  label?: string | number
}) {
  if (active !== true || !payload || payload.length === 0) return null
  return (
    <div className="rounded-lg border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-sm">
      {label !== undefined && <div className="font-medium">{label}</div>}
      <div className="mt-1 space-y-0.5">
        {payload.map((entry, index) => (
          <div key={index} className="flex items-center gap-1.5">
            <span
              className="size-2 rounded-full"
              style={{ backgroundColor: entry.color }}
              aria-hidden
            />
            <span className="text-muted-foreground">{entry.name}</span>
            <span className="font-medium">{entry.value}</span>
          </div>
        ))}
      </div>
    </div>
  )
}

function Chart({
  type,
  data,
  series,
  xKey,
  height = 240,
  legend = true,
  highlight,
  formatValue = String,
  donut = false,
  className,
  ...props
}: ChartProps) {
  const content = React.useMemo(() => {
    switch (type) {
      case "bar":
        return (
          <BarChart data={data}>
            <CartesianGrid vertical={false} stroke="var(--border)" />
            {xKey !== undefined && <XAxis dataKey={xKey} tickLine={false} axisLine={false} />}
            {/* Headroom above the tallest bar, so its printed value is not clipped. */}
            <YAxis tickLine={false} axisLine={false} domain={highlight !== undefined ? [0, (max: number) => niceCeil(max * 1.1)] : undefined} />
            <Tooltip content={<ChartTooltipContent />} />
            {legend && <Legend />}
            {series.map((s, i) => (
              // At most 24px thick, rounded at the end and square on the baseline (BarChart card).
              <Bar key={s.key} dataKey={s.key} name={s.label} fill={chartColorVar(s.color)} radius={[4, 4, 0, 0]} maxBarSize={24}>
                {i === 0 && highlight !== undefined && (
                  <LabelList
                    dataKey={s.key}
                    content={(p) => (p.index === highlight ? <text x={Number(p.x) + Number(p.width) / 2} y={Number(p.y) - 6} textAnchor="middle" className="fill-foreground text-xs font-semibold">{formatValue(Number(p.value))}</text> : null)}
                  />
                )}
              </Bar>
            ))}
          </BarChart>
        )
      case "line":
        return (
          <LineChart data={data}>
            <CartesianGrid vertical={false} stroke="var(--border)" />
            {xKey !== undefined && <XAxis dataKey={xKey} tickLine={false} axisLine={false} />}
            <YAxis tickLine={false} axisLine={false} />
            <Tooltip content={<ChartTooltipContent />} />
            {legend && <Legend />}
            {series.map((s) => (
              <Line
                key={s.key}
                type="monotone"
                dataKey={s.key}
                name={s.label}
                stroke={chartColorVar(s.color)}
                strokeWidth={2}
                dot={false}
              />
            ))}
          </LineChart>
        )
      case "area":
        return (
          <AreaChart data={data}>
            <CartesianGrid vertical={false} stroke="var(--border)" />
            {xKey !== undefined && <XAxis dataKey={xKey} tickLine={false} axisLine={false} />}
            <YAxis tickLine={false} axisLine={false} />
            <Tooltip content={<ChartTooltipContent />} />
            {legend && <Legend />}
            {series.map((s) => (
              <Area
                key={s.key}
                type="monotone"
                dataKey={s.key}
                name={s.label}
                stroke={chartColorVar(s.color)}
                fill={chartColorVar(s.color)}
                fillOpacity={0.2}
              />
            ))}
          </AreaChart>
        )
      case "pie": {
        const first = series[0]
        if (first === undefined || xKey === undefined) return null
        return (
          <PieChart>
            <Tooltip content={<ChartTooltipContent />} />
            {legend && <Legend />}
            <Pie data={data} dataKey={first.key} nameKey={xKey} label={!donut} innerRadius={donut ? "60%" : 0}>
              {data.map((_, index) => (
                <Cell
                  key={index}
                  // A ring of one series takes the palette in its fixed order, one colour per slice.
                  fill={chartColorVar(donut ? (((index % 5) + 1) as ChartColor) : (series[index % series.length]?.color ?? 1))}
                />
              ))}
            </Pie>
          </PieChart>
        )
      }
    }
  }, [type, data, series, xKey, legend, highlight, formatValue, donut])

  return (
    <div
      data-slot="chart"
      role="img"
      aria-label={series.map((s) => s.label).join(", ")}
      className={cn("w-full", className)}
      style={{ height }}
      {...props}
    >
      <ResponsiveContainer width="100%" height="100%">
        {content ?? <svg />}
      </ResponsiveContainer>
    </div>
  )
}

export { Chart }
