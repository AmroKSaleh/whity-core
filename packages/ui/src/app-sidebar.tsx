import * as React from "react"
import { IconChevronLeft, IconChevronRight, IconMenu2, IconX } from "@tabler/icons-react"

import { cn } from "./utils"

/**
 * PRESENTATIONAL app-shell sidebar only — a controlled, data-driven nav
 * chrome. Fetching nav items (RBAC/tenant-filtered), branding, tenant
 * switching, and theme/direction toggles belong to the app-specific
 * composed wrapper (e.g. web/components/sidebar.tsx's `Sidebar`), which
 * renders this component and passes it `groups` + slot content — the same
 * split Breadcrumb already draws between itself and route-derived crumbs.
 *
 * PLATFORM NOTE: `groups`/`items` are plain data (label + href + optional
 * icon) — the same shape a Flutter or Tauri-native nav rail would consume;
 * this component owns only the desktop-web collapse/expand + mobile
 * slide-over chrome around that data.
 */
export interface AppSidebarNavItem {
  id: string
  label: string
  href: string
  icon?: React.ReactNode
  /** Marks the item as the current page (styling only — routing is the caller's job). */
  active?: boolean
}

export interface AppSidebarNavGroup {
  id: string
  /** Optional group heading, hidden entirely when the sidebar is collapsed. */
  label?: string
  items: AppSidebarNavItem[]
}

export interface AppSidebarProps {
  /** Branding/logo slot, rendered above the nav. */
  header?: React.ReactNode
  groups: AppSidebarNavGroup[]
  /** User menu / tenant switcher / theme toggle slot, rendered below the nav. */
  footer?: React.ReactNode
  /** Controlled collapsed (icon-only) state. Uncontrolled if omitted. */
  collapsed?: boolean
  /** Called when the user clicks the collapse toggle. */
  onCollapsedChange?: (collapsed: boolean) => void
  /** Controlled mobile open state. Uncontrolled if omitted. */
  mobileOpen?: boolean
  onMobileOpenChange?: (open: boolean) => void
  /** Custom link component (e.g. Next.js <Link>) — defaults to a plain <a>. */
  linkComponent?: React.ElementType
  className?: string
}

export function AppSidebar({
  header,
  groups,
  footer,
  collapsed: collapsedProp,
  onCollapsedChange,
  mobileOpen: mobileOpenProp,
  onMobileOpenChange,
  linkComponent,
  className,
}: AppSidebarProps) {
  const [collapsedState, setCollapsedState] = React.useState(false)
  const collapsed = collapsedProp ?? collapsedState
  const setCollapsed = (next: boolean) => {
    setCollapsedState(next)
    onCollapsedChange?.(next)
  }

  const [mobileOpenState, setMobileOpenState] = React.useState(false)
  const mobileOpen = mobileOpenProp ?? mobileOpenState
  const setMobileOpen = (next: boolean) => {
    setMobileOpenState(next)
    onMobileOpenChange?.(next)
  }

  const Link = linkComponent ?? "a"

  const nav = (
    <nav aria-label="Main" className="flex-1 space-y-4 overflow-y-auto px-2 py-4">
      {groups.map((group) => (
        <div key={group.id} className="space-y-1">
          {group.label && !collapsed && (
            <div className="px-2 text-[0.625rem] font-semibold tracking-wider text-muted-foreground uppercase">
              {group.label}
            </div>
          )}
          {group.items.map((item) => (
            <Link
              key={item.id}
              href={item.href}
              aria-current={item.active ? "page" : undefined}
              className={cn(
                "flex items-center gap-2 rounded-md px-2 py-1.5 text-xs/relaxed font-medium transition-colors",
                item.active
                  ? "bg-sidebar-accent text-sidebar-accent-foreground"
                  : "text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground",
                collapsed && "justify-center"
              )}
            >
              {item.icon && <span className="shrink-0 [&_svg]:size-4">{item.icon}</span>}
              {!collapsed && <span className="truncate">{item.label}</span>}
            </Link>
          ))}
        </div>
      ))}
    </nav>
  )

  return (
    <>
      {/* Mobile trigger — shown only below the md breakpoint; the sidebar
          itself is fixed off-canvas until toggled open. */}
      <button
        type="button"
        aria-label="Open navigation"
        onClick={() => setMobileOpen(true)}
        className="fixed start-3 top-3 z-40 flex size-8 items-center justify-center rounded-md border border-border bg-card md:hidden"
      >
        <IconMenu2 className="size-4" />
      </button>

      {mobileOpen && (
        <div
          aria-hidden="true"
          onClick={() => setMobileOpen(false)}
          className="fixed inset-0 z-40 bg-foreground/20 md:hidden"
        />
      )}

      <aside
        data-slot="app-sidebar"
        data-collapsed={collapsed || undefined}
        className={cn(
          "fixed inset-y-0 start-0 z-50 flex w-64 -translate-x-full flex-col border-e border-sidebar-border bg-sidebar text-sidebar-foreground transition-transform rtl:translate-x-full",
          mobileOpen && "translate-x-0 rtl:translate-x-0",
          "md:static md:z-auto md:translate-x-0 rtl:md:translate-x-0",
          collapsed && "md:w-16",
          className
        )}
      >
        <button
          type="button"
          aria-label="Close navigation"
          onClick={() => setMobileOpen(false)}
          className="absolute end-2 top-2 flex size-7 items-center justify-center rounded-md text-sidebar-foreground/70 hover:bg-sidebar-accent md:hidden"
        >
          <IconX className="size-4" />
        </button>

        {header && <div className="px-3 py-4">{header}</div>}

        {nav}

        <button
          type="button"
          aria-label={collapsed ? "Expand sidebar" : "Collapse sidebar"}
          onClick={() => setCollapsed(!collapsed)}
          className="hidden items-center justify-center gap-1.5 border-t border-sidebar-border px-3 py-2 text-xs/relaxed text-sidebar-foreground/70 hover:bg-sidebar-accent md:flex"
        >
          {collapsed ? (
            <IconChevronRight className="size-3.5 rtl:rotate-180" />
          ) : (
            <>
              <IconChevronLeft className="size-3.5 rtl:rotate-180" />
              <span>Collapse</span>
            </>
          )}
        </button>

        {footer && <div className="border-t border-sidebar-border px-3 py-3">{footer}</div>}
      </aside>
    </>
  )
}
