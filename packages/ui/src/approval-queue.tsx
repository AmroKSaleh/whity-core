"use client"

import { ActionProposal, type ActionProposalProps } from "./action-proposal"
import { Button } from "./button"
import { EmptyState } from "./empty-state"
import { Icon } from "./icon"
import { cn } from "./utils"

/**
 * The approvals inbox, one request at a time (docs/design/components/
 * ApprovalQueue; register 7.5b): "1 of 3 waiting · oldest first", Previous and
 * Next, the current proposal, and the edges of the cards behind it. When
 * nothing is left it says so.
 *
 * Controlled: the caller holds the list (oldest first), which one is shown,
 * and what deciding does. Moving on after a decision is the caller's too, so
 * the list can shrink only once the server has answered.
 */

export interface ApprovalQueueItem extends ActionProposalProps {
  id: string
}

export interface ApprovalQueueLabels {
  /** `{index}` and `{count}`. */
  position?: string
  order?: string
  previous?: string
  next?: string
  doneTitle?: string
  doneDescription?: string
}

export interface ApprovalQueueProps {
  items: ApprovalQueueItem[]
  currentId?: string
  onCurrentChange: (id: string) => void
  /** Accessible name of the section. */
  label?: string
  labels?: ApprovalQueueLabels
  className?: string
}

const DEFAULT_LABELS: Required<ApprovalQueueLabels> = {
  position: "{index} of {count} waiting",
  order: "oldest first",
  previous: "Previous",
  next: "Next",
  doneTitle: "All caught up",
  doneDescription: "Nothing is waiting for you. New requests from agents appear here.",
}

function ApprovalQueue({ items, currentId, onCurrentChange, label = "Approvals", labels, className }: ApprovalQueueProps) {
  const L = { ...DEFAULT_LABELS, ...labels }
  const found = items.findIndex((item) => item.id === currentId)
  const index = found >= 0 ? found : 0
  const current = items[index]

  return (
    <section data-slot="approval-queue" aria-label={label} className={cn("grid max-w-[560px] gap-2", className)}>
      {!current ? (
        <EmptyState
          icon={<Icon name="success" size={24} />}
          title={L.doneTitle}
          description={L.doneDescription}
        />
      ) : (
        <>
          <div className="flex items-center justify-between text-[0.8125rem] text-muted-foreground">
            <span aria-live="polite">
              {L.position.replace("{index}", String(index + 1)).replace("{count}", String(items.length))} · {L.order}
            </span>
            <span className="flex items-center gap-1">
              <Button
                variant="ghost"
                size="sm"
                disabled={index <= 0}
                onClick={() => onCurrentChange(items[index - 1].id)}
              >
                <Icon name="chevron-left" size={16} className="rtl:-scale-x-100" />
                {L.previous}
              </Button>
              <Button
                variant="ghost"
                size="sm"
                disabled={index >= items.length - 1}
                onClick={() => onCurrentChange(items[index + 1].id)}
              >
                {L.next}
                <Icon name="chevron-right" size={16} className="rtl:-scale-x-100" />
              </Button>
            </span>
          </div>
          {/* key: a new card per proposal, so its "Show all" starts folded. */}
          <ActionProposal key={current.id} {...current} />
          {items.length > 1 && (
            <div aria-hidden="true" className="relative mx-3 -mt-2 h-2 rounded-b-card border border-t-0 border-border bg-card" />
          )}
          {items.length > 2 && (
            <div aria-hidden="true" className="relative mx-6 -mt-2 h-2 rounded-b-card border border-t-0 border-border bg-card" />
          )}
        </>
      )}
    </section>
  )
}

export { ApprovalQueue }
