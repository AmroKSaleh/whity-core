"use client"

import * as React from "react"
import { IconCheck, IconChevronDown } from "@tabler/icons-react"

import { cn } from "./utils"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "./dropdown-menu"

/**
 * PRESENTATIONAL scope switcher — the one shared building block behind both
 * a tenant switcher and a team switcher (or any future "which X am I acting
 * as" control): a generic list of items, a current selection, and a change
 * callback. No data fetching, no knowledge of tenants/teams specifically.
 *
 * Renders as a static label (not a dropdown) when there's nothing to switch
 * between (0 or 1 item) — so a caller can pass a user's full list of
 * memberships/teams unconditionally and get the right behavior for free,
 * whether that user has many, one, or none. To hide the control entirely for
 * a user who doesn't need it at all (e.g. no team feature enabled for their
 * tenant), don't render <Switcher> — that's the composed caller's call, same
 * as AppSidebar's optional `tenantSwitcher`/`teamSwitcher` slots.
 */
export interface SwitcherItem {
  id: string
  label: string
}

export interface SwitcherProps {
  items: SwitcherItem[]
  activeId?: string
  onChange: (id: string) => void
  icon: React.ReactNode
  /** Short noun used in labels/aria-labels, e.g. "Tenant" or "Team". */
  switchLabel: string
  /** Current-item text shown when there's no active item (empty items, or activeId not found). */
  emptyLabel?: string
  /** Icon-only rendering to match AppSidebar's collapsed state. */
  collapsed?: boolean
  disabled?: boolean
  className?: string
}

function Switcher({
  items,
  activeId,
  onChange,
  icon,
  switchLabel,
  emptyLabel,
  collapsed = false,
  disabled = false,
  className,
}: SwitcherProps) {
  const active = items.find((item) => item.id === activeId)
  const displayName = active?.label ?? emptyLabel ?? `No ${switchLabel.toLowerCase()}`

  // 0 or 1 item — nothing to switch between, so a plain label instead of a
  // (pointless, empty-or-single-item) dropdown trigger.
  if (items.length < 2) {
    if (collapsed) {
      return (
        <div
          data-slot="switcher"
          title={displayName}
          aria-label={`Current ${switchLabel.toLowerCase()}: ${displayName}`}
          className={cn("flex justify-center rounded-lg border border-border bg-card p-2 shadow-2xs", className)}
        >
          <span className="shrink-0 text-muted-foreground [&_svg]:size-5">{icon}</span>
        </div>
      )
    }
    return (
      <div data-slot="switcher" className={cn("flex items-center gap-2 rounded-lg border border-border bg-card p-2 shadow-2xs", className)}>
        <span className="shrink-0 text-muted-foreground [&_svg]:size-5">{icon}</span>
        <span className="min-w-0">
          <span className="block text-xs text-muted-foreground">{switchLabel}</span>
          <span className="block truncate text-sm font-medium">{displayName}</span>
        </span>
      </div>
    )
  }

  // 2+ items — dropdown.
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          disabled={disabled}
          aria-label={`Switch ${switchLabel.toLowerCase()}, current: ${displayName}`}
          data-slot="switcher"
          className={cn(
            "flex w-full items-center gap-2 rounded-lg border border-border bg-card p-2 text-start shadow-2xs transition-colors outline-none hover:bg-muted/50 hover:border-border/80 focus-visible:ring-2 focus-visible:ring-ring/30 disabled:pointer-events-none disabled:opacity-50",
            collapsed && "justify-center",
            className
          )}
        >
          <span className="shrink-0 text-muted-foreground [&_svg]:size-5">{icon}</span>
          {!collapsed && (
            <>
              <span className="min-w-0 flex-1">
                <span className="block text-xs text-muted-foreground">{switchLabel}</span>
                <span className="block truncate text-sm font-medium">{displayName}</span>
              </span>
              <IconChevronDown aria-hidden="true" className="ms-auto size-3.5 shrink-0 text-muted-foreground" />
            </>
          )}
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent side={collapsed ? "right" : "top"} align={collapsed ? "end" : "start"}>
        <DropdownMenuLabel>Switch {switchLabel.toLowerCase()}</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {items.map((item) => (
          <DropdownMenuItem key={item.id} disabled={disabled} onSelect={() => onChange(item.id)}>
            {item.id === activeId && <IconCheck aria-hidden="true" className="me-1 size-3.5 shrink-0" />}
            <span className="truncate">{item.label}</span>
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

export { Switcher }
