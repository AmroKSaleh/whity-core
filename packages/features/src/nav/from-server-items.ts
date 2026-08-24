import type { ReactNode } from "react"

import type { AppSidebarNavGroup } from "@amroksaleh/ui/app-sidebar"

/**
 * Shape `GET /api/navigation`'s flat, RBAC-filtered item list into the
 * `AppSidebarNavGroup[]` that `AppSidebar` renders.
 *
 * This is the SERVER-DRIVEN counterpart to `resolve-nav.ts`, and the two are
 * deliberately separate entry points rather than one:
 *
 *  - `resolveNavGroups` takes a `NavConfig` a client AUTHORED — groups are
 *    already written down in the order they should appear, and an item marks
 *    its own active range explicitly with a trailing `/*`.
 *  - this takes items the SERVER produced, where grouping is a `group` string
 *    per item, sequence has to be reconstructed, and no item can declare its
 *    active range because the server does not know the client's routes.
 *
 * Which pages exist is the server's call (RBAC + tenant + plugins decide);
 * how they are bucketed and sequenced is the shell's. That split is why the
 * group HEADINGS come from the caller (`groupLabel`) instead of the wire: a
 * heading is UI copy that has to translate, and a group id is not a phrase.
 */

/** One item exactly as `GET /api/navigation` returns it. */
export interface ServerNavItem {
  id: string
  label: string
  href: string
  /** Icon NAME (the server has no React); resolved by `renderIcon`. */
  icon?: string
  /** Bucket id. Absent/empty means ungrouped — rendered last, without a heading. */
  group?: string
  order: number
}

export interface NavGroupsFromServerItemsOptions {
  /**
   * The client's current route. However it is sourced (Next's
   * `usePathname()`, a hash router, a Flutter bridge) is the caller's
   * concern — this function only compares strings.
   */
  currentPath: string
  /**
   * The group sequence this shell wants. Ids with no items are skipped, and
   * ids NOT listed here still render — after the declared ones, ordered by
   * their lowest item `order` — so a plugin contributing a brand-new group
   * appears rather than vanishing.
   */
  groupOrder?: readonly string[]
  /**
   * Already-translated heading for a group id. Return `undefined` to render
   * the group with no heading (which also makes it non-collapsible in
   * `AppSidebar`, so use it for a short trailing set).
   */
  groupLabel?: (groupId: string) => string | undefined
  /** Turn an icon name into a node. Omit to render items without icons. */
  renderIcon?: (icon: string | undefined) => ReactNode
}

/** The bucket for items the server sent with no `group`. */
export const UNGROUPED_NAV_GROUP_ID = ""

/**
 * The single most-specific item matching `currentPath`, or null.
 *
 * Ported from web/components/sidebar.tsx, where it was needed because any
 * parent-ish item whose href is a PREFIX of a more specific sibling's href
 * highlighted alongside it: on `/admin/plugins/store`, "Plugin Store"
 * (`/admin/plugins/store`) must win over "Plugins" (`/admin/plugins`).
 *
 * An exact match always wins outright; among prefix matches the longest href
 * (most specific route) wins. Scoring exact matches one tier above the
 * longest possible prefix length is sufficient, since an exact match is by
 * definition at least as specific as any prefix match.
 *
 * Single-segment hrefs (`/admin`) are deliberately excluded from PREFIX
 * matching — otherwise the dashboard would claim every `/admin/*` page and
 * no leaf item could ever look current.
 */
export function mostSpecificActiveItemId(
  items: readonly ServerNavItem[],
  currentPath: string
): string | null {
  let bestId: string | null = null
  let bestScore = -1

  for (const item of items) {
    const hrefSegments = item.href.split("/").filter(Boolean).length
    const isExact = currentPath === item.href
    const isPrefix = hrefSegments > 1 && currentPath.startsWith(item.href + "/")
    if (!isExact && !isPrefix) continue

    const score = item.href.length + (isExact ? 1_000_000 : 0)
    if (score > bestScore) {
      bestScore = score
      bestId = item.id
    }
  }

  return bestId
}

/** Stable within-group sequence: server `order`, then label, then id. */
function compareItems(a: ServerNavItem, b: ServerNavItem): number {
  if (a.order !== b.order) return a.order - b.order
  const byLabel = a.label.localeCompare(b.label)
  return byLabel !== 0 ? byLabel : a.id.localeCompare(b.id)
}

export function navGroupsFromServerItems(
  items: readonly ServerNavItem[],
  options: NavGroupsFromServerItemsOptions
): AppSidebarNavGroup[] {
  const { currentPath, groupOrder = [], groupLabel, renderIcon } = options

  const activeId = mostSpecificActiveItemId(items, currentPath)

  const buckets = new Map<string, ServerNavItem[]>()
  for (const item of items) {
    const groupId = item.group !== undefined && item.group !== "" ? item.group : UNGROUPED_NAV_GROUP_ID
    const bucket = buckets.get(groupId)
    if (bucket) {
      bucket.push(item)
    } else {
      buckets.set(groupId, [item])
    }
  }

  for (const bucket of buckets.values()) {
    bucket.sort(compareItems)
  }

  // Declared groups first, in the sequence the shell asked for; then anything
  // the shell did not declare, by its lowest item order; then the ungrouped
  // bucket, always last. Trailing is the right end for it: it renders without
  // a heading, so it reads as an appendix rather than a nameless preamble —
  // and the previous implementation put it FIRST, which is how a nav item
  // registered with order 100 ended up above every other link.
  const declared = groupOrder.filter(
    (id) => id !== UNGROUPED_NAV_GROUP_ID && buckets.has(id)
  )
  const declaredSet = new Set(declared)
  const undeclared = [...buckets.keys()]
    .filter((id) => id !== UNGROUPED_NAV_GROUP_ID && !declaredSet.has(id))
    .sort((a, b) => {
      const aOrder = buckets.get(a)![0]!.order
      const bOrder = buckets.get(b)![0]!.order
      return aOrder !== bOrder ? aOrder - bOrder : a.localeCompare(b)
    })
  const sequence = [...declared, ...undeclared]
  if (buckets.has(UNGROUPED_NAV_GROUP_ID)) {
    sequence.push(UNGROUPED_NAV_GROUP_ID)
  }

  return sequence.map((groupId) => ({
    id: groupId,
    label: groupId === UNGROUPED_NAV_GROUP_ID ? undefined : groupLabel?.(groupId),
    items: buckets.get(groupId)!.map((item) => ({
      id: item.id,
      label: item.label,
      href: item.href,
      icon: renderIcon?.(item.icon),
      active: item.id === activeId,
    })),
  }))
}
