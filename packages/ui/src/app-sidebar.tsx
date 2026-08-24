import * as React from "react"
import {
  IconChevronDown,
  IconChevronLeft,
  IconChevronRight,
  IconMenu2,
  IconSearch,
  IconX,
} from "@tabler/icons-react"

import { cn } from "./utils"

/**
 * PRESENTATIONAL app-shell sidebar only — a controlled, data-driven nav
 * chrome. Fetching nav items (RBAC/tenant-filtered), branding, tenant/team
 * switching, and theme/direction toggles belong to the app-specific
 * composed wrapper (e.g. web/components/sidebar.tsx's `Sidebar`), which
 * renders this component and passes it `groups` + slot content — the same
 * split Breadcrumb already draws between itself and route-derived crumbs.
 *
 * Multi-tenant + multi-team: `tenantSwitcher` and `teamSwitcher` are
 * independent optional slots (see each prop's doc below) built from the
 * shared `Switcher` primitive — a user might have many tenants and no
 * teams, one tenant and many teams, both, or neither; which nav `groups`
 * to pass is entirely the composed caller's decision (e.g. re-fetch/swap
 * `groups` when the active team changes), this component has no concept
 * of "tenant" or "team" itself.
 *
 * PLATFORM NOTE: `groups`/`items` are plain data (label + href + optional
 * icon) — the same shape a Flutter or Tauri-native nav rail would consume;
 * this component owns only the desktop-web collapse/expand + mobile
 * slide-over chrome around that data.
 *
 * I18N: every user-visible string is a prop with an English default, and
 * nothing here imports a translator — the composed wrapper passes already
 * translated text, so the same component serves a client with no i18n layer
 * and one with a full catalogue.
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
  /**
   * Optional group heading, hidden entirely when the sidebar is collapsed.
   * A group WITHOUT a label is never collapsible — there is no header to
   * click — so its items always render. Use that for a short trailing set
   * that must stay reachable regardless of collapse state.
   */
  label?: string
  items: AppSidebarNavItem[]
}

export interface AppSidebarProps {
  /** Branding/logo slot, rendered above the switchers/nav. */
  header?: React.ReactNode
  /**
   * Optional tenant-switcher slot (e.g. built from the shared `Switcher`
   * primitive). A multi-tenant client renders one here; a single-tenant
   * client omits it entirely — this component has no opinion on tenants.
   * If provided, keep it in sync with `collapsed` (pass this same prop's
   * value to the `Switcher`/custom control you render here) so it matches
   * the sidebar's icon-only state.
   */
  tenantSwitcher?: React.ReactNode
  /**
   * Optional team-switcher slot, independent of `tenantSwitcher` — a user
   * may have many teams and one tenant (show only this), many tenants and
   * no teams (show only that), both, or neither. Whether to render either
   * slot at all is entirely the composed caller's call; this component
   * just reserves the layout position and stacks whatever it's given.
   */
  teamSwitcher?: React.ReactNode
  groups: AppSidebarNavGroup[]
  /** User menu / theme toggle slot, rendered below the nav. */
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
  /**
   * Turn group headings into disclosure buttons. Groups the user has not
   * opened render their items HIDDEN rather than unmounted — see the
   * `hidden` attribute in the group body below for why that distinction is
   * load-bearing.
   */
  collapsibleGroups?: boolean
  /**
   * Controlled set of open group ids. Uncontrolled if omitted, in which
   * case exactly one group starts open: the one holding the active item
   * (falling back to the first group when nothing is active). Navigating to
   * another group's page opens that group, without closing anything the
   * user opened by hand.
   */
  expandedGroupIds?: readonly string[]
  onExpandedGroupsChange?: (expanded: readonly string[]) => void
  /** Render a filter box above the nav that narrows items by label. */
  searchable?: boolean
  /** Controlled search query. Uncontrolled if omitted. */
  searchValue?: string
  onSearchChange?: (value: string) => void
  /** Placeholder AND accessible name for the filter box. Defaults to "Search". */
  searchPlaceholder?: string
  /** Accessible name for the button that empties the filter box. */
  clearSearchLabel?: string
  /** Shown in place of the nav when a query matches no item. */
  searchNoResultsLabel?: string
  /** Accessible name for the navigation landmark. Defaults to "Main". */
  navLabel?: string
  /** Accessible name for the button that opens the mobile drawer. */
  openNavLabel?: string
  /** Accessible name for the button that closes the mobile drawer. */
  closeNavLabel?: string
  /** Text of the collapse toggle. Defaults to "Collapse". */
  collapseLabel?: string
  /**
   * Accessible names for the collapse toggle in each direction. Separate
   * from `collapseLabel`, which is the visible text beside the icon.
   */
  collapseAriaLabel?: string
  expandAriaLabel?: string
  className?: string
}

/** Case-insensitive substring match, tolerant of surrounding whitespace. */
function matches(label: string, normalizedQuery: string): boolean {
  return label.toLocaleLowerCase().includes(normalizedQuery)
}

export function AppSidebar({
  header,
  tenantSwitcher,
  teamSwitcher,
  groups,
  footer,
  collapsed: collapsedProp,
  onCollapsedChange,
  mobileOpen: mobileOpenProp,
  onMobileOpenChange,
  linkComponent,
  collapsibleGroups = false,
  expandedGroupIds,
  onExpandedGroupsChange,
  searchable = false,
  searchValue,
  onSearchChange,
  searchPlaceholder = "Search",
  clearSearchLabel = "Clear search",
  searchNoResultsLabel = "No matching pages",
  navLabel = "Main",
  openNavLabel = "Open navigation",
  closeNavLabel = "Close navigation",
  collapseLabel = "Collapse",
  collapseAriaLabel = "Collapse sidebar",
  expandAriaLabel = "Expand sidebar",
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

  const [queryState, setQueryState] = React.useState("")
  const query = searchValue ?? queryState
  const setQuery = (next: string) => {
    if (searchValue === undefined) setQueryState(next)
    onSearchChange?.(next)
  }
  const normalizedQuery = query.trim().toLocaleLowerCase()
  const searching = normalizedQuery !== ""

  // The group holding the current page, which is what starts open. Falls back
  // to the first group so an unmatched route (a detail page no nav item owns)
  // still shows something rather than a stack of closed headers.
  const openByDefaultGroupId = React.useMemo(() => {
    const active = groups.find((group) => group.items.some((item) => item.active))
    return active?.id ?? groups[0]?.id ?? null
  }, [groups])

  const [expandedState, setExpandedState] = React.useState<readonly string[]>([])
  const expanded = expandedGroupIds ?? expandedState

  // `groups` typically arrives empty on first render and fills in after the
  // nav fetch resolves, so the default cannot be computed in useState's
  // initializer — it has to react to the id appearing. Keyed on the id alone:
  // a group the user opened by hand survives navigation within it, and only a
  // move to a DIFFERENT group re-narrows the sidebar.
  React.useEffect(() => {
    if (expandedGroupIds !== undefined) return
    if (openByDefaultGroupId === null) return
    setExpandedState((prev) =>
      prev.includes(openByDefaultGroupId) ? prev : [openByDefaultGroupId]
    )
  }, [openByDefaultGroupId, expandedGroupIds])

  const toggleGroup = (id: string) => {
    const next = expanded.includes(id)
      ? expanded.filter((groupId) => groupId !== id)
      : [...expanded, id]
    if (expandedGroupIds === undefined) setExpandedState(next)
    onExpandedGroupsChange?.(next)
  }

  const Link = linkComponent ?? "a"

  // While a query is active the disclosure state is bypassed entirely: the
  // point of the box is to reach an item without knowing which group holds
  // it. Groups with no match drop out so the result list stays short.
  const rendered = React.useMemo(() => {
    if (!searching) {
      return groups.map((group) => ({ group, items: group.items }))
    }
    return groups
      .map((group) => ({
        group,
        items: group.items.filter((item) => matches(item.label, normalizedQuery)),
      }))
      .filter((entry) => entry.items.length > 0)
  }, [groups, searching, normalizedQuery])

  // A group is open when it cannot be closed (no header to click, or the
  // feature is off), when the icon rail hides the headers anyway, while a
  // query is bypassing disclosure, or when the user/default opened it.
  const isGroupOpen = (group: AppSidebarNavGroup): boolean =>
    !collapsibleGroups ||
    !group.label ||
    collapsed ||
    searching ||
    expanded.includes(group.id)

  const nav = (
    <nav
      data-slot="app-sidebar-nav"
      aria-label={navLabel}
      className="flex-1 space-y-4 overflow-y-auto px-2 py-4"
    >
      {rendered.map(({ group, items }) => {
        const open = isGroupOpen(group)
        const showHeading = Boolean(group.label) && !collapsed
        const isDisclosure = showHeading && collapsibleGroups && !searching
        const bodyId = `app-sidebar-group-${group.id}`

        return (
          <div key={group.id} className="space-y-1">
            {showHeading && isDisclosure && (
              <button
                type="button"
                data-slot="app-sidebar-group-toggle"
                data-group-id={group.id}
                aria-expanded={open}
                aria-controls={bodyId}
                onClick={() => toggleGroup(group.id)}
                className="flex w-full items-center gap-1 rounded-md px-2 py-1 text-[0.625rem] font-semibold tracking-wider text-muted-foreground uppercase outline-none hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/30"
              >
                {open ? (
                  <IconChevronDown className="size-3 shrink-0" />
                ) : (
                  <IconChevronRight className="size-3 shrink-0 rtl:rotate-180" />
                )}
                <span className="truncate">{group.label}</span>
              </button>
            )}
            {showHeading && !isDisclosure && (
              <div className="px-2 text-[0.625rem] font-semibold tracking-wider text-muted-foreground uppercase">
                {group.label}
              </div>
            )}
            {/*
              A closed group's items are HIDDEN, not unmounted.

              `hidden` is the correct disclosure semantic — collapsed content
              must leave the accessibility tree, or a screen reader announces
              links the sighted user cannot see. It follows that a role/name
              query cannot find an item in a closed group, in Playwright as
              much as in Testing Library.

              Keeping them mounted is still deliberate: a DOM/CSS query can
              tell "this group is closed" (present, hidden) from "RBAC removed
              this link for this role" (absent). Unmounting would collapse
              both into "absent", and any suite asserting absence as proof of
              permission filtering would keep passing after that filtering
              broke. Either query by CSS or open the group first.
            */}
            <div id={bodyId} hidden={!open} className="space-y-1">
              {items.map((item) => (
                <Link
                  key={item.id}
                  href={item.href}
                  aria-current={item.active ? "page" : undefined}
                  title={collapsed ? item.label : undefined}
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
          </div>
        )
      })}
      {searching && rendered.length === 0 && (
        <p data-slot="app-sidebar-no-results" className="px-2 py-1 text-xs/relaxed text-muted-foreground">
          {searchNoResultsLabel}
        </p>
      )}
    </nav>
  )

  // Hidden in the icon rail: a 4rem-wide column has nowhere to put a text
  // field, and the collapse toggle is right there to widen it again.
  const search = searchable && !collapsed && (
    <div className="px-2.5 pt-2.5">
      <div className="relative flex items-center">
        <IconSearch className="pointer-events-none absolute start-2 size-3.5 text-muted-foreground" />
        <input
          type="search"
          data-slot="app-sidebar-search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Escape") setQuery("")
          }}
          placeholder={searchPlaceholder}
          aria-label={searchPlaceholder}
          className="h-8 w-full rounded-md border border-sidebar-border bg-background/50 ps-7 pe-7 text-xs/relaxed text-sidebar-foreground outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 [&::-webkit-search-cancel-button]:hidden"
        />
        {query !== "" && (
          <button
            type="button"
            aria-label={clearSearchLabel}
            onClick={() => setQuery("")}
            className="absolute end-1 flex size-6 items-center justify-center rounded-md text-sidebar-foreground/70 outline-none hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-ring/30"
          >
            <IconX className="size-3.5" />
          </button>
        )}
      </div>
    </div>
  )

  return (
    <>
      {/* Mobile trigger — shown only below the md breakpoint; the sidebar
          itself is fixed off-canvas until toggled open. */}
      <button
        type="button"
        aria-label={openNavLabel}
        onClick={() => setMobileOpen(true)}
        className="fixed start-3 top-3 z-40 flex size-8 items-center justify-center rounded-md border border-border bg-card outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 md:hidden"
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
          "fixed inset-y-0 start-0 z-50 flex h-screen w-64 -translate-x-full flex-col border-e border-sidebar-border bg-sidebar text-sidebar-foreground transition-all duration-200 rtl:translate-x-full",
          mobileOpen && "translate-x-0 rtl:translate-x-0",
          "md:sticky md:top-0 md:z-auto md:h-screen md:shrink-0 md:translate-x-0 rtl:md:translate-x-0",
          collapsed && "md:w-16",
          className
        )}
      >
        <button
          type="button"
          aria-label={closeNavLabel}
          onClick={() => setMobileOpen(false)}
          className="absolute end-2 top-2.5 z-20 flex size-7 items-center justify-center rounded-md text-sidebar-foreground/70 outline-none hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-ring/30 md:hidden"
        >
          <IconX className="size-4" />
        </button>

        {(header || tenantSwitcher || teamSwitcher) && (
          <div className="flex flex-col gap-2.5 px-2.5 pt-3 pe-10 md:pe-2.5">
            {header && <div className="px-1 py-1">{header}</div>}
            {(tenantSwitcher || teamSwitcher) && (
              <div className="flex flex-col gap-1.5">
                {tenantSwitcher}
                {teamSwitcher}
              </div>
            )}
          </div>
        )}

        {search}

        {nav}

        <button
          type="button"
          aria-label={collapsed ? expandAriaLabel : collapseAriaLabel}
          onClick={() => setCollapsed(!collapsed)}
          className="hidden items-center justify-center gap-1.5 border-t border-sidebar-border px-3 py-2 text-xs/relaxed text-sidebar-foreground/70 outline-none hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-ring/30 md:flex"
        >
          {collapsed ? (
            <IconChevronRight className="size-3.5 rtl:rotate-180" />
          ) : (
            <>
              <IconChevronLeft className="size-3.5 rtl:rotate-180" />
              <span>{collapseLabel}</span>
            </>
          )}
        </button>

        {footer && <div className="border-t border-sidebar-border px-3 py-3">{footer}</div>}
      </aside>
    </>
  )
}
