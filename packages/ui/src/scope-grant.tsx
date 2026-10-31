"use client"

import { ActorChip } from "./actor-chip"
import { cn } from "./utils"

/**
 * What an agent may do, per `resource:action` permission: Off, Ask first or
 * Allowed (docs/design/components/ScopeGrant; register "Scope grant"). Ask
 * first routes the action through a proposal, so it wears the dashed outline;
 * Allowed is the tint (7.3). The note states the hard rule: an agent never
 * does more than the person who granted it.
 *
 * Controlled: `modes` maps each permission key to its mode. An action can
 * narrow the modes it offers (`modes` on the action), e.g. a write that can't
 * be undone offers no Allowed; `hint` says why.
 */

export type ScopeGrantMode = "off" | "ask" | "allow"

export interface ScopeGrantAction {
  /** The permission key, e.g. `invoices:send`. */
  key: string
  label: string
  /** The modes this action offers; all three when omitted. */
  modes?: ScopeGrantMode[]
  hint?: string
}

export interface ScopeGrantGroup {
  key: string
  label: string
  actions: ScopeGrantAction[]
}

export interface ScopeGrantLabels {
  off?: string
  ask?: string
  allow?: string
  note?: string
  for?: string
}

export interface ScopeGrantProps {
  agent: string
  onBehalfOf?: string
  groups: ScopeGrantGroup[]
  modes: Record<string, ScopeGrantMode>
  onChange?: (permission: string, mode: ScopeGrantMode) => void
  disabled?: boolean
  labels?: ScopeGrantLabels
  className?: string
}

const ALL_MODES: ScopeGrantMode[] = ["off", "ask", "allow"]

const DEFAULT_LABELS: Required<ScopeGrantLabels> = {
  off: "Off",
  ask: "Ask first",
  allow: "Allowed",
  note: "The agent can never do more than the person who granted it.",
  for: "for",
}

function ScopeGrant({ agent, onBehalfOf, groups, modes, onChange, disabled = false, labels, className }: ScopeGrantProps) {
  const L = { ...DEFAULT_LABELS, ...labels }

  return (
    <div data-slot="scope-grant" className={cn("grid max-w-[640px] gap-3 rounded-card border border-border bg-card p-4", className)}>
      <div className="flex flex-wrap items-center gap-3">
        <ActorChip kind="agent" name={agent} onBehalfOf={onBehalfOf} labels={{ for: L.for }} />
        <span className="text-muted-foreground">{L.note}</span>
      </div>
      <table className="w-full border-collapse">
        <tbody>
          {groups.map((group) =>
            group.actions.map((action, index) => {
              const offered = action.modes ?? ALL_MODES
              const current = modes[action.key] ?? "off"
              return (
                <tr key={action.key}>
                  {index === 0 && (
                    <th
                      scope="row"
                      rowSpan={group.actions.length}
                      className="w-[22%] border-t border-border p-2 text-start align-middle text-sm font-semibold"
                    >
                      {group.label}
                    </th>
                  )}
                  <td className="border-t border-border p-2 align-middle">
                    <span className="grid gap-0.5">
                      <span>{action.label}</span>
                      <code className="justify-self-start rounded-sm bg-muted px-[5px] py-px font-mono text-xs text-foreground">
                        {action.key}
                      </code>
                      {action.hint && <span className="text-xs text-muted-foreground">{action.hint}</span>}
                    </span>
                  </td>
                  <td className="border-t border-border p-2 text-end align-middle">
                    <div
                      role="radiogroup"
                      aria-label={`${group.label} · ${action.label}`}
                      className="inline-flex gap-0.5 rounded-control-sm border border-border bg-muted p-0.5"
                    >
                      {ALL_MODES.map((mode) => {
                        const on = current === mode
                        const available = offered.includes(mode)
                        return (
                          <button
                            key={mode}
                            type="button"
                            role="radio"
                            aria-checked={on}
                            disabled={disabled || !available}
                            onClick={() => !on && onChange?.(action.key, mode)}
                            className={cn(
                              "rounded-[4px] px-2 py-[3px] text-xs font-medium whitespace-nowrap text-muted-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50",
                              on && "bg-card font-semibold text-foreground shadow-[0_0_0_1px_var(--border)]",
                              on && mode === "allow" && "bg-primary-tint text-primary-tint-foreground shadow-[0_0_0_1px_var(--primary)]",
                              on && mode === "ask" && "outline-[1.5px] -outline-offset-[1.5px] outline-brand outline-dashed"
                            )}
                          >
                            {L[mode]}
                          </button>
                        )
                      })}
                    </div>
                  </td>
                </tr>
              )
            })
          )}
        </tbody>
      </table>
    </div>
  )
}

export { ScopeGrant }
