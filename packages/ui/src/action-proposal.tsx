"use client"

import * as React from "react"

import { ActorChip } from "./actor-chip"
import { Badge } from "./badge"
import { Button } from "./button"
import { Icon } from "./icon"
import { cn } from "./utils"

/**
 * The approve-before-act card (docs/design/components/ActionProposal; register
 * 7.5a, 5.4, 4.3). A waiting proposal is a loose sheet: dashed and flat,
 * because nothing has happened yet. Approving sets it down on the plane (solid
 * brand edge, 200 ms); declined goes grey with the title struck through;
 * expired goes grey with Ask again; undone is dashed again and says by whom.
 * The plane never sits under something that has not happened.
 *
 * Summary first: the title says what will happen and how much, then at most
 * two changes and "Show all" past three. The footer always names the
 * permissions used and whether, and for how long, it can be undone.
 *
 * Controlled: `status` is whatever the server says. Every visible string is a
 * label with an English default.
 */

export type ActionProposalStatus = "waiting" | "approved" | "declined" | "expired" | "undone"

export interface ActionProposalChange {
  label: string
  /** The value now; omitted for something new. */
  before?: React.ReactNode
  after: React.ReactNode
}

export interface ActionProposalLabels {
  for?: string
  waiting?: string
  approved?: string
  declined?: string
  expired?: string
  /** `{name}` is who undid it. */
  undoneBy?: string
  undone?: string
  approve?: string
  decline?: string
  edit?: string
  undo?: string
  askAgain?: string
  uses?: string
  reversible?: string
  /** `{window}` is the undo window, e.g. "30 minutes". */
  reversibleFor?: string
  irreversible?: string
  before?: string
  after?: string
  /** `{count}` is the number of changes. */
  showAll?: string
  showLess?: string
}

export interface ActionProposalProps {
  /** The agent's role name ("Billing assistant"). */
  agent: string
  /** The person it acts for. */
  onBehalfOf?: string
  title: string
  summary?: React.ReactNode
  changes?: ActionProposalChange[]
  /** The permissions it uses (`resource:action`). */
  scope?: string[]
  /** False when it can't be undone; then there is no undo window. */
  reversible?: boolean
  /** How long it can be undone for, already worded ("30 minutes"). */
  undoWindow?: string
  status?: ActionProposalStatus
  /** When it was decided, already formatted ("14:02"). */
  decidedAt?: string
  undoneBy?: string
  /** Disables the actions while a decision is on its way. */
  busy?: boolean
  onApprove?: () => void
  onDecline?: () => void
  onEdit?: () => void
  onUndo?: () => void
  onAskAgain?: () => void
  labels?: ActionProposalLabels
  className?: string
}

const DEFAULT_LABELS: Required<ActionProposalLabels> = {
  for: "for",
  waiting: "Waiting for your approval",
  approved: "Approved",
  declined: "Declined",
  expired: "Expired",
  undoneBy: "Undone by {name}",
  undone: "Undone",
  approve: "Approve",
  decline: "Decline",
  edit: "Edit",
  undo: "Undo",
  askAgain: "Ask again",
  uses: "Uses",
  reversible: "Can be undone",
  reversibleFor: "Can be undone for {window}",
  irreversible: "Can't be undone",
  before: "Now",
  after: "After",
  showAll: "Show all {count} changes",
  showLess: "Show less",
}

const NEUTRAL_BADGE = "bg-muted text-muted-foreground"

function ActionProposal({
  agent,
  onBehalfOf,
  title,
  summary,
  changes = [],
  scope = [],
  reversible = true,
  undoWindow,
  status = "waiting",
  decidedAt,
  undoneBy,
  busy = false,
  onApprove,
  onDecline,
  onEdit,
  onUndo,
  onAskAgain,
  labels,
  className,
}: ActionProposalProps) {
  const L = { ...DEFAULT_LABELS, ...labels }
  const [showAll, setShowAll] = React.useState(false)
  const folded = changes.length > 3 && !showAll
  const shown = folded ? changes.slice(0, 2) : changes
  const titleId = React.useId()

  const badge = {
    waiting: (
      <Badge variant="outline">
        <span aria-hidden="true" className="size-2 rounded-full border-[1.5px] border-dashed border-brand" />
        {L.waiting}
      </Badge>
    ),
    approved: (
      <Badge variant="success" icon="check">
        {decidedAt ? `${L.approved} · ${decidedAt}` : L.approved}
      </Badge>
    ),
    declined: (
      <Badge icon="close" className={NEUTRAL_BADGE}>
        {L.declined}
      </Badge>
    ),
    expired: (
      <Badge icon="clock" className={NEUTRAL_BADGE}>
        {L.expired}
      </Badge>
    ),
    undone: (
      <Badge icon="undo" className={NEUTRAL_BADGE}>
        {undoneBy ? L.undoneBy.replace("{name}", undoneBy) : L.undone}
      </Badge>
    ),
  }[status]

  const undoText = !reversible
    ? L.irreversible
    : undoWindow
      ? L.reversibleFor.replace("{window}", undoWindow)
      : L.reversible

  return (
    <article
      data-slot="action-proposal"
      data-status={status}
      aria-labelledby={titleId}
      className={cn(
        "grid max-w-[560px] gap-3 rounded-card border-[1.5px] bg-card p-4 text-card-foreground",
        "transition-[box-shadow,border-color,margin] duration-200 ease-out-whity",
        status === "waiting" && "border-dashed border-brand/60",
        // Set down: solid brand edge on the plane (4.3). The plane never mirrors.
        status === "approved" && "mb-(--plane-offset) ml-(--plane-offset) border-solid border-brand shadow-plane",
        status === "declined" && "border-solid border-border",
        status === "expired" && "border-solid border-border bg-muted",
        status === "undone" && "border-dashed border-input",
        className
      )}
    >
      <header className="flex flex-wrap items-center justify-between gap-2">
        <ActorChip kind="agent" name={agent} onBehalfOf={onBehalfOf} labels={{ for: L.for }} />
        {badge}
      </header>
      <h3
        id={titleId}
        className={cn(
          "m-0 text-base leading-snug font-semibold text-balance",
          (status === "declined" || status === "expired") && "text-muted-foreground",
          status === "declined" && "line-through decoration-1"
        )}
      >
        {title}
      </h3>
      {summary && <p className="m-0 text-sm text-muted-foreground">{summary}</p>}
      {shown.length > 0 && (
        <dl className="m-0 grid overflow-hidden rounded-control border border-divider">
          {shown.map((change, index) => (
            <div
              key={index}
              className="grid grid-cols-[minmax(96px,30%)_1fr] gap-3 border-t border-border px-3 py-2 first:border-t-0"
            >
              <dt className="text-muted-foreground">{change.label}</dt>
              <dd className="m-0 flex min-w-0 flex-wrap items-center gap-1.5 tabular-nums">
                {change.before != null && (
                  <>
                    <span className="text-muted-foreground line-through">
                      <span className="sr-only">{L.before}: </span>
                      {change.before}
                    </span>
                    <Icon name="arrow-right" size={12} className="text-muted-foreground rtl:-scale-x-100" />
                  </>
                )}
                <span className="min-w-0 font-semibold break-words">
                  {change.before != null && <span className="sr-only">{L.after}: </span>}
                  {change.after}
                </span>
              </dd>
            </div>
          ))}
        </dl>
      )}
      {changes.length > 3 && (
        <button
          type="button"
          aria-expanded={showAll}
          onClick={() => setShowAll(!showAll)}
          className="justify-self-start border-0 bg-transparent p-0 text-[0.8125rem] font-medium text-primary outline-none focus-visible:underline"
        >
          {showAll ? L.showLess : L.showAll.replace("{count}", String(changes.length))}
        </button>
      )}
      <footer className="flex flex-wrap items-center justify-between gap-3">
        <div className="grid min-w-0 gap-0.5 text-sm text-muted-foreground">
          {scope.length > 0 && (
            <span>
              {L.uses}{" "}
              {scope.map((permission) => (
                <code
                  key={permission}
                  className="me-1 rounded-sm bg-muted px-[5px] py-px font-mono text-xs text-foreground"
                >
                  {permission}
                </code>
              ))}
            </span>
          )}
          <span>{undoText}</span>
        </div>
        {status === "waiting" && (
          <div className="flex flex-wrap items-center gap-2">
            {onDecline && (
              <Button variant="ghost" size="sm" disabled={busy} onClick={onDecline}>
                {L.decline}
              </Button>
            )}
            {onEdit && (
              <Button variant="outline" size="sm" disabled={busy} onClick={onEdit}>
                <Icon name="edit" size={16} />
                {L.edit}
              </Button>
            )}
            {onApprove && (
              <Button size="sm" disabled={busy} onClick={onApprove}>
                <Icon name="check" size={16} />
                {L.approve}
              </Button>
            )}
          </div>
        )}
        {status === "approved" && reversible && onUndo && (
          <Button variant="ghost" size="sm" disabled={busy} onClick={onUndo}>
            <Icon name="undo" size={16} />
            {L.undo}
          </Button>
        )}
        {status === "expired" && onAskAgain && (
          <Button variant="outline" size="sm" disabled={busy} onClick={onAskAgain}>
            <Icon name="sync" size={16} />
            {L.askAgain}
          </Button>
        )}
      </footer>
    </article>
  )
}

export { ActionProposal }
