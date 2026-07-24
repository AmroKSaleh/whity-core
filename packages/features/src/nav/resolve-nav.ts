import type { AppSidebarNavGroup } from "@amroksaleh/ui/app-sidebar"

import { identityTranslate, type NavConfig, type NavTranslate } from "./types"

/**
 * Match an item's `activeMatch` (or `href`, when unset) against the current
 * path. A trailing `/*` marks a prefix match (e.g. a list item that should
 * stay highlighted while a detail sub-route is open); everything else is an
 * exact match, matching `AppSidebarNavItem.active`'s "styling only" contract
 * (packages/ui/src/app-sidebar.tsx) — routing itself stays the caller's job.
 */
function isActive(matchAgainst: string, currentPath: string): boolean {
  if (matchAgainst.endsWith("/*")) {
    const prefix = matchAgainst.slice(0, -2)
    return currentPath === prefix || currentPath.startsWith(prefix + "/")
  }
  return currentPath === matchAgainst
}

/**
 * Resolve one label: prefer `t(translationKey)` when a translationKey is set,
 * EXCEPT under the default identity translator, where the literal `label` is
 * shown instead of the raw key — matching the documented "falls back to
 * literal strings when omitted" behavior. `t === identityTranslate` is a
 * stable reference-equality check against the shared exported singleton (see
 * `types.ts`), not a behavioral guess: a caller who wants raw-key passthrough
 * even without real i18n can still pass `(key) => key` explicitly (a
 * different, non-identical function) to get it.
 */
function resolveLabel(label: string, translationKey: string | undefined, t: NavTranslate): string {
  if (translationKey === undefined) return label
  if (t === identityTranslate) return label
  return t(translationKey)
}

/**
 * Resolve a client-authored `NavConfig` (plain data) plus the current path
 * into `AppSidebarNavGroup[]` — the shape `AppSidebar` (packages/ui) actually
 * renders. This is the one piece of "logic" the nav contract owns: label
 * translation and active-route matching. Everything else (collapse state,
 * mobile slide-over, RTL) already lives in `AppSidebar` itself.
 *
 * @param config The client's nav configuration (plain, serializable data).
 * @param currentPath The client's current route/path (however it sources
 *   this — Next's `usePathname()`, a hash-router's location, a Flutter-bridge
 *   equivalent — is entirely the caller's concern).
 * @param t Optional translator for `translationKey` fields; defaults to the
 *   identity function (labels render as literal strings) so a client with no
 *   i18n layer yet needs nothing extra.
 */
export function resolveNavGroups(
  config: NavConfig,
  currentPath: string,
  t: NavTranslate = identityTranslate
): AppSidebarNavGroup[] {
  return config.groups.map((group) => ({
    id: group.id,
    label: resolveLabel(group.label ?? "", group.translationKey, t),
    items: group.items.map((item) => ({
      id: item.id,
      label: resolveLabel(item.label, item.translationKey, t),
      href: item.href,
      icon: item.icon,
      active: isActive(item.activeMatch ?? item.href, currentPath),
    })),
  }))
}
