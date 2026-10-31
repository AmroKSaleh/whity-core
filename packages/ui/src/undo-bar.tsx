"use client"

import { Button } from "./button"
import { Icon } from "./icon"
import { cn } from "./utils"

/**
 * Undo that stays on an activity item for the tool's own window, after the
 * 10-second toast has gone (docs/design/components/UndoBar; register 7.5c).
 * It says what happened and how long is left, with a thin meter. Once undone
 * it turns dashed, because an undone action is loose again like a proposal;
 * when the window closes it says it can no longer be undone.
 */

export type UndoBarState = "open" | "undone" | "expired"

export interface UndoBarLabels {
  undo?: string
  /** `{time}` is what is left, e.g. "24 minutes". */
  left?: string
  expired?: string
  undone?: string
  /** `{name}` is who undid it. */
  undoneBy?: string
}

export interface UndoBarProps {
  text?: string
  state?: UndoBarState
  /** What is left, already worded ("24 minutes"). */
  remainingLabel?: string
  /** Seconds left and the whole window, for the meter. */
  remaining?: number
  total?: number
  undoneBy?: string
  busy?: boolean
  onUndo?: () => void
  labels?: UndoBarLabels
  className?: string
}

const DEFAULT_LABELS: Required<UndoBarLabels> = {
  undo: "Undo",
  left: "{time} left to undo",
  expired: "Can no longer be undone",
  undone: "Undone",
  undoneBy: "Undone by {name}",
}

function UndoBar({
  text,
  state = "open",
  remainingLabel = "",
  remaining,
  total,
  undoneBy,
  busy = false,
  onUndo,
  labels,
  className,
}: UndoBarProps) {
  const L = { ...DEFAULT_LABELS, ...labels }
  const share = total ? Math.max(0, Math.min(1, (remaining ?? 0) / total)) : null
  const status =
    state === "open"
      ? L.left.replace("{time}", remainingLabel)
      : state === "expired"
        ? L.expired
        : undoneBy
          ? L.undoneBy.replace("{name}", undoneBy)
          : L.undone

  return (
    <div
      role="group"
      aria-label={text}
      data-slot="undo-bar"
      data-state={state}
      className={cn(
        "relative flex flex-wrap items-center gap-2 overflow-hidden rounded-md border border-border bg-card pt-1.5 pe-2 pb-2 ps-2.5 text-[0.8125rem] text-muted-foreground",
        state === "undone" && "border-dashed text-foreground",
        state === "expired" && "bg-muted",
        className
      )}
    >
      <Icon name={state === "undone" ? "undo" : state === "expired" ? "lock" : "clock"} size={16} className="shrink-0" />
      <span className="min-w-0 flex-1">
        {text && <span className="text-foreground">{text}</span>}
        {text && " · "}
        {status}
      </span>
      {state === "open" && onUndo && (
        <Button variant="outline" size="xs" disabled={busy} onClick={onUndo}>
          <Icon name="undo" size={12} />
          {L.undo}
        </Button>
      )}
      {state === "open" && share !== null && (
        <span aria-hidden="true" className="absolute inset-x-0 bottom-0 h-0.5 bg-muted">
          <span className="block h-full bg-primary" style={{ width: `${share * 100}%` }} />
        </span>
      )}
    </div>
  )
}

export { UndoBar }
