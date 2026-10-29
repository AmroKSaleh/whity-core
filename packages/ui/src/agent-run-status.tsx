import * as React from "react"

import { ActorChip } from "./actor-chip"
import { Button } from "./button"
import { Icon, type IconName } from "./icon"
import { cn } from "./utils"

export type AgentRunState = "running" | "waiting" | "done" | "failed"

const STATE_ICON: Record<Exclude<AgentRunState, "running">, IconName> = {
  done: "success",
  failed: "error",
  waiting: "clock",
}

const BADGE: Record<AgentRunState, string> = {
  running: "bg-info-tint text-info-text",
  waiting: "bg-info-tint text-info-text",
  done: "bg-success-tint text-success-text",
  failed: "bg-error-tint text-error-text",
}

const DEFAULT_LABELS = {
  running: "Running",
  waiting: "Waiting for approval",
  done: "Done",
  failed: "Failed",
  for: "for",
  review: "Review",
  retry: "Try again",
  stop: "Stop",
  /** "{done} of {total}" */
  of: "{done} of {total}",
}

function Spinner({ className }: { className?: string }) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        "size-2.5 animate-spin rounded-full border-[1.5px] border-current border-e-transparent [animation-duration:2.4s] motion-reduce:animate-none",
        className
      )}
    />
  )
}

/**
 * What an agent is doing now, in plain words (stage 7): Running ("Reading 48
 * open invoices", with progress when the count is known and Stop), Waiting for
 * approval (dashed, with Review, because nothing has happened yet), Done, and
 * Failed (what went wrong and what to do next, with Try again). The agent
 * comes first, then who it acts for, then the action; state is carried by the
 * word and the icon, never colour alone. The reference is docs/design/
 * components/AgentRunStatus (`.wy-run`).
 */
function AgentRunStatus({
  status,
  agent,
  onBehalfOf,
  title,
  progress,
  detail,
  next,
  time,
  compact = false,
  onStop,
  onReview,
  onRetry,
  actions,
  labels,
  className,
}: {
  status: AgentRunState
  /** The agent's role name. */
  agent: string
  onBehalfOf?: string
  /** What it is doing or did, e.g. "Reading 48 open invoices". */
  title: React.ReactNode
  progress?: { done: number; total: number }
  detail?: React.ReactNode
  /** For `failed`: what to do next. */
  next?: React.ReactNode
  time?: React.ReactNode
  /** A pill for the top bar or a table cell. */
  compact?: boolean
  onStop?: () => void
  onReview?: () => void
  onRetry?: () => void
  actions?: React.ReactNode
  /** Every string has an English default; pass Arabic here. */
  labels?: Partial<typeof DEFAULT_LABELS>
  className?: string
}) {
  const L = { ...DEFAULT_LABELS, ...labels }
  const glyph =
    status === "running" ? <Spinner /> : <Icon name={STATE_ICON[status]} size={compact ? 14 : 12} />

  if (compact) {
    return (
      <span
        role="status"
        data-slot="agent-run-status"
        data-status={status}
        className={cn(
          "inline-flex min-h-7 items-center gap-1.5 rounded-full border border-border bg-card py-1 ps-2 pe-2.5 text-[0.8125rem]",
          status === "running" && "border-transparent bg-primary-tint text-primary-tint-foreground",
          status === "waiting" && "border-dashed border-input [&_svg]:text-muted-foreground",
          status === "done" && "[&_svg]:text-success-text",
          status === "failed" && "border-[color-mix(in_oklab,var(--error-text)_45%,var(--border))] [&_svg]:text-error-text",
          className
        )}
      >
        {glyph}
        <span className="font-medium">{agent}</span>
        <span className="text-muted-foreground">{title}</span>
      </span>
    )
  }

  const pct = progress && progress.total ? Math.round((100 * progress.done) / progress.total) : null
  return (
    <div
      role={status === "failed" ? "alert" : "status"}
      data-slot="agent-run-status"
      data-status={status}
      className={cn(
        "grid gap-2 rounded-card border border-border bg-card p-4",
        status === "waiting" && "border-dashed border-input",
        status === "failed" && "border-[color-mix(in_oklab,var(--error-text)_45%,var(--border))]",
        className
      )}
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <ActorChip kind="agent" name={agent} onBehalfOf={onBehalfOf} labels={{ for: L.for }} />
        <span
          className={cn(
            "inline-flex h-[22px] items-center gap-1 rounded-full px-[9px] text-xs leading-none font-medium whitespace-nowrap",
            BADGE[status]
          )}
        >
          {glyph}
          {L[status]}
        </span>
      </div>
      <div className="text-[0.9375rem] font-semibold">{title}</div>
      {status === "running" && progress && (
        <div className="flex items-center gap-3">
          <div
            role="progressbar"
            aria-valuemin={0}
            aria-valuemax={progress.total}
            aria-valuenow={progress.done}
            aria-label={typeof title === "string" ? title : undefined}
            className="h-1 flex-1 overflow-hidden rounded-[2px] bg-muted"
          >
            <span
              className="block h-full rounded-[2px] bg-primary transition-[width] duration-(--dur-base) ease-out-whity"
              style={{ width: `${pct ?? 0}%` }}
            />
          </div>
          <span className="text-xs whitespace-nowrap text-muted-foreground tabular-nums">
            {L.of.replace("{done}", String(progress.done)).replace("{total}", String(progress.total))}
          </span>
        </div>
      )}
      {detail && <div className="text-[0.8125rem] text-muted-foreground">{detail}</div>}
      {status === "failed" && next && (
        <div className="rounded-control bg-error-tint px-2.5 py-2 text-[0.8125rem] text-foreground">{next}</div>
      )}
      <div className="flex min-h-7 items-center justify-between gap-2">
        <span className="text-xs text-muted-foreground">{time}</span>
        <div className="flex gap-2">
          {status === "running" && onStop && (
            <Button variant="ghost" size="sm" onClick={onStop}>
              {L.stop}
            </Button>
          )}
          {status === "waiting" && onReview && (
            <Button variant="outline" size="sm" onClick={onReview}>
              {L.review}
            </Button>
          )}
          {status === "failed" && onRetry && (
            <Button variant="outline" size="sm" icon="sync" onClick={onRetry}>
              {L.retry}
            </Button>
          )}
          {actions}
        </div>
      </div>
    </div>
  )
}

export { AgentRunStatus }
