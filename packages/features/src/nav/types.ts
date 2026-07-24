import type { ComponentType, ReactNode } from "react"

/**
 * The app-shell nav contract every Whity client (Next web, Tauri/Vite SPA,
 * future Flutter) implements against, formalizing what #598's `AppSidebar`
 * already accepts as plain props (`groups` + an injectable `linkComponent`)
 * into a documented, client-supplied config shape.
 *
 * A client describes its navigation ONCE as data (`NavConfig`), supplies its
 * own routing primitive (`NavLinkAdapter`) and, optionally, its own
 * translator and current-path source — `resolveNavGroups` (see
 * `resolve-nav.ts`) does the rest: translating labels, marking the active
 * item, and shaping the result into `AppSidebarNavGroup[]`.
 *
 * Nothing here imports next/navigation, next/link, or any router — a client
 * plugs in whichever routing primitive it has (Next's `<Link>`, a hash-router
 * `<a>` substitute, a React Native/Flutter-bridge equivalent).
 */

/**
 * The link/navigation primitive a client injects. Must accept at minimum an
 * `href` and children, exactly like `next/link`'s `<Link>` or a plain `<a>` —
 * matching `AppSidebarProps.linkComponent`'s contract (see
 * packages/ui/src/app-sidebar.tsx) so this package never needs its own
 * routing opinion.
 */
export type NavLinkAdapter = ComponentType<{
  href: string
  className?: string
  "aria-current"?: "page"
  children?: ReactNode
}>

/**
 * Translate a UI string. Defaults to the identity function when a client has
 * no i18n layer yet (see `resolveNavGroups`) — injected rather than imported
 * so this package never picks an i18n library on a client's behalf.
 */
export type NavTranslate = (key: string) => string

/**
 * The identity translator — a stable, module-level reference (NOT an inline
 * arrow) used as the default `t` wherever it's optional. An inline default
 * (`t = (key) => key`) would allocate a new function every render, and any
 * effect/callback depending on `t` would then re-run every render — this bit
 * `DemoCatalogDetail` as a real infinite-render-loop bug during the pilot's
 * own manual verification. Reuse this constant instead of re-declaring an
 * inline default anywhere `NavTranslate` is optional.
 */
export const identityTranslate: NavTranslate = (key) => key

/** One item in a nav group — plain, serializable data. */
export interface NavItemConfig {
  id: string
  /**
   * Either a literal label (rendered as-is) or an i18n key resolved through
   * the injected `NavTranslate` at render time (see `translationKey`).
   */
  label: string
  /** When set, `label` is treated as a fallback and this key is looked up via `t()` instead. */
  translationKey?: string
  href: string
  icon?: ReactNode
  /**
   * Path this item is considered active for. Defaults to `href`. Supports a
   * trailing `/*` suffix to match the href as a prefix (e.g. a list item
   * that should stay active on its own detail sub-routes).
   */
  activeMatch?: string
}

/** One group of nav items — plain, serializable data. */
export interface NavGroupConfig {
  id: string
  label?: string
  translationKey?: string
  items: NavItemConfig[]
}

/** A full nav configuration — the single value a client authors once. */
export interface NavConfig {
  groups: NavGroupConfig[]
}
