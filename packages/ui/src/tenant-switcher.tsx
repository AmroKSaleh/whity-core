"use client"

import * as React from "react"
import { IconCheck, IconPlus, IconSearch, IconSelector } from "@tabler/icons-react"

import { Popover, PopoverContent, PopoverTrigger } from "./popover"
import { TenantLogo } from "./tenant-logo"
import { cn } from "./utils"

/**
 * The workspace switcher at the top of the sidebar (docs/design/components/
 * TenantSwitcher): the current workspace's logo and name, opening a list of
 * every workspace the person belongs to with their role there. A search box
 * appears above more than four; "Create workspace" sits at the foot when the
 * caller passes `onCreate`. The current one is on the tint and ticked (7.3).
 *
 * Presentational: switching (and reloading in the new tenant's brand) is the
 * caller's job. Every visible string is a prop with an English default.
 */

export interface TenantSwitcherWorkspace {
  id: string
  name: string
  /** The person's role in this workspace, shown under its name. */
  role?: string
  logoUrl?: string | null
}

export interface TenantSwitcherLabels {
  /** Accessible name of the trigger; `{name}` is the current workspace. */
  trigger?: string
  find?: string
  noResults?: string
  create?: string
}

export interface TenantSwitcherProps {
  workspaces: TenantSwitcherWorkspace[]
  currentId?: string
  onChange: (id: string) => void
  onCreate?: () => void
  /** The 56px rail: the logo alone, with the name as its label. */
  collapsed?: boolean
  disabled?: boolean
  labels?: TenantSwitcherLabels
  className?: string
}

const SEARCH_FROM = 5

function TenantSwitcher({
  workspaces,
  currentId,
  onChange,
  onCreate,
  collapsed = false,
  disabled = false,
  labels = {},
  className,
}: TenantSwitcherProps) {
  const [open, setOpen] = React.useState(false)
  const [query, setQuery] = React.useState("")
  const current = workspaces.find((workspace) => workspace.id === currentId) ?? workspaces[0]
  const name = current?.name ?? ""
  const triggerLabel = (labels.trigger ?? "Switch workspace, current: {name}").replace("{name}", name)

  const normalized = query.trim().toLocaleLowerCase()
  const shown = normalized === ""
    ? workspaces
    : workspaces.filter((workspace) => workspace.name.toLocaleLowerCase().includes(normalized))

  const choose = (id: string) => {
    setOpen(false)
    setQuery("")
    if (id !== current?.id) onChange(id)
  }

  return (
    <Popover open={open} onOpenChange={(next) => { setOpen(next); if (!next) setQuery("") }}>
      <PopoverTrigger asChild>
        <button
          type="button"
          data-slot="tenant-switcher"
          disabled={disabled}
          aria-label={triggerLabel}
          title={collapsed ? name : undefined}
          className={cn(
            "flex min-h-10 w-full items-center gap-2 rounded-control-lg border border-border bg-card px-2 py-1.5 text-start text-foreground outline-none transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50",
            collapsed && "w-10 justify-center px-0",
            className
          )}
        >
          <TenantLogo name={name} src={current?.logoUrl} size={22} showName={!collapsed} className="flex-1" />
          {!collapsed && <IconSelector aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />}
        </button>
      </PopoverTrigger>
      <PopoverContent
        align="start"
        side={collapsed ? "right" : "bottom"}
        className="w-72 gap-0 p-1"
      >
        {workspaces.length >= SEARCH_FROM && (
          <div className="-mx-1 -mt-1 mb-1 flex items-center gap-1.5 border-b border-divider px-3 py-1.5 text-muted-foreground">
            <IconSearch aria-hidden="true" className="size-4 shrink-0" />
            <input
              type="search"
              autoFocus
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder={labels.find ?? "Find a workspace"}
              aria-label={labels.find ?? "Find a workspace"}
              className="h-7 min-w-0 flex-1 border-0 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground [&::-webkit-search-cancel-button]:hidden"
            />
          </div>
        )}
        <ul role="listbox" aria-label={labels.find ?? "Find a workspace"} className="grid gap-px">
          {shown.map((workspace) => {
            const selected = workspace.id === current?.id
            return (
              <li key={workspace.id} role="option" aria-selected={selected}>
                <button
                  type="button"
                  onClick={() => choose(workspace.id)}
                  className={cn(
                    "flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start outline-none hover:bg-muted focus-visible:bg-muted",
                    selected && "bg-primary-tint text-primary-tint-foreground hover:bg-primary-tint focus-visible:bg-primary-tint"
                  )}
                >
                  <TenantLogo name={workspace.name} src={workspace.logoUrl} size={24} showName={false} />
                  <span className="min-w-0 flex-1">
                    <span className={cn("block truncate text-sm", selected ? "text-primary-tint-foreground" : "text-foreground")}>
                      {workspace.name}
                    </span>
                    {workspace.role && (
                      <span className="block truncate text-xs text-muted-foreground">{workspace.role}</span>
                    )}
                  </span>
                  {selected && <IconCheck aria-hidden="true" className="size-4 shrink-0" />}
                </button>
              </li>
            )
          })}
        </ul>
        {shown.length === 0 && (
          <p className="px-2 py-2 text-sm text-muted-foreground">{labels.noResults ?? "No workspace matches"}</p>
        )}
        {onCreate && (
          <div className="-mx-1 mt-1 border-t border-divider px-1 pt-1">
            <button
              type="button"
              onClick={() => { setOpen(false); onCreate() }}
              className="flex w-full items-center gap-2 rounded-md px-2 py-2 text-start text-sm text-foreground outline-none hover:bg-muted focus-visible:bg-muted"
            >
              <IconPlus aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
              {labels.create ?? "Create workspace"}
            </button>
          </div>
        )}
      </PopoverContent>
    </Popover>
  )
}

export { TenantSwitcher }
