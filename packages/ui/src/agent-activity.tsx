"use client"

import * as React from "react"

import { ActorChip } from "./actor-chip"
import type { ActorKind } from "./avatar"
import { Icon, type IconName } from "./icon"
import { cn } from "./utils"

/**
 * What agents read, asked and did, as a timeline (docs/design/components/
 * AgentActivity; 15-agents rule 1). `read` is quiet; `propose` is a dashed
 * node, because it has not happened; `approve` is a person's decision; `act`
 * is filled; `error` is red. Every row names its actor; agent rows show the
 * tool they called in mono. `filter` adds chips to show one agent at a time.
 *
 * `extra` renders under a row, e.g. an UndoBar on something still undoable.
 */

export type AgentActivityKind = "read" | "propose" | "approve" | "act" | "undo" | "error"

export interface AgentActivityItem {
  id: string
  kind: AgentActivityKind
  actor: string
  actorKind?: ActorKind
  onBehalfOf?: string
  text: React.ReactNode
  /**
   * Already formatted ("13:58") by the caller's date display, which honours a
   * workspace that hides dates; empty when it does.
   */
  timeLabel: string
  /** The wire timestamp, for the <time> element and ordering. */
  dateTime?: string
  tool?: string
  extra?: React.ReactNode
}

export interface AgentActivityLabels {
  all?: string
  filter?: string
  for?: string
}

export interface AgentActivityProps {
  items: AgentActivityItem[]
  /** Show chips to narrow the list to one agent (from two agents up). */
  filter?: boolean
  /** Accessible name of the list. */
  label?: string
  labels?: AgentActivityLabels
  className?: string
}

const KIND_ICON: Record<AgentActivityKind, IconName> = {
  read: "view",
  propose: "edit",
  act: "send",
  approve: "check",
  undo: "undo",
  error: "alert",
}

/**
 * The row's time, already formatted by the caller's date display (which
 * returns nothing when the workspace hides dates), so nothing here formats
 * or prints a raw timestamp.
 */
function ActivityTime({ item }: { item: AgentActivityItem }) {
  const { timeLabel, dateTime } = item
  if (timeLabel === "") return null
  return <time dateTime={dateTime}>{timeLabel}</time>
}

function AgentActivity({ items, filter = false, label = "Agent activity", labels, className }: AgentActivityProps) {
  const L = { all: "All", filter: "Filter by agent", for: "for", ...labels }
  const [agent, setAgent] = React.useState<string | null>(null)

  const agents = React.useMemo(() => {
    const names: string[] = []
    for (const item of items) {
      if ((item.actorKind ?? "agent") === "agent" && !names.includes(item.actor)) names.push(item.actor)
    }
    return names
  }, [items])

  const shown = agent ? items.filter((item) => item.actor === agent) : items

  const list = (
    <ol aria-label={label} className={cn("m-0 grid list-none p-0", !(filter && agents.length > 1) && className)}>
      {shown.map((item) => (
        <li
          key={item.id}
          data-kind={item.kind}
          className="relative grid grid-cols-[24px_1fr] gap-3 pb-4 before:absolute before:start-[11px] before:top-6 before:bottom-0 before:w-px before:bg-border last:before:hidden"
        >
          <span
            aria-hidden="true"
            className={cn(
              "grid size-6 place-items-center rounded-full bg-muted text-muted-foreground",
              item.kind === "act" && "bg-primary text-primary-foreground",
              item.kind === "approve" && "bg-success-tint text-success-text",
              item.kind === "propose" && "border-[1.5px] border-dashed border-brand bg-card text-brand",
              item.kind === "error" && "bg-error-tint text-error-text"
            )}
          >
            <Icon name={KIND_ICON[item.kind]} size={12} />
          </span>
          <div className="grid min-w-0 gap-0.5 pt-px">
            <div className="flex flex-wrap items-center gap-1.5">
              <ActorChip
                kind={item.actorKind ?? "agent"}
                name={item.actor}
                onBehalfOf={item.onBehalfOf}
                labels={{ for: L.for }}
              />
              <span className={cn("text-sm", item.kind === "read" && "text-muted-foreground")}>{item.text}</span>
            </div>
            <div className="flex flex-wrap gap-2 text-muted-foreground tabular-nums">
              <ActivityTime item={item} />
              {item.tool && (
                <code className="rounded-sm bg-muted px-[5px] py-px font-mono text-xs text-foreground">{item.tool}</code>
              )}
            </div>
            {item.extra && <div className="mt-1.5">{item.extra}</div>}
          </div>
        </li>
      ))}
    </ol>
  )

  if (!filter || agents.length < 2) return list

  return (
    <div className={cn("grid gap-3", className)}>
      <div role="radiogroup" aria-label={L.filter} className="flex flex-wrap gap-1.5">
        {[null, ...agents].map((name) => {
          const on = agent === name
          return (
            <button
              key={name ?? "_all"}
              type="button"
              role="radio"
              aria-checked={on}
              onClick={() => setAgent(name)}
              className={cn(
                "inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-[3px] text-xs font-medium text-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring",
                on && "border-primary bg-primary-tint text-primary-tint-foreground hover:bg-primary-tint"
              )}
            >
              {name && <Icon name="robot" size={12} />}
              {name ?? L.all}
            </button>
          )
        })}
      </div>
      {list}
    </div>
  )
}

export { AgentActivity }
