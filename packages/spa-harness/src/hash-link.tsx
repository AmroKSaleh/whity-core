import type { NavLinkAdapter } from "@amroksaleh/features/nav"

/**
 * The SPA's `NavLinkAdapter` implementation — a hash-router substitute for
 * `next/link`, proving `AppSidebar`'s `linkComponent` prop (and the nav
 * contract built on top of it) needs nothing Next-specific. No router
 * dependency: `useHashPath` (see `use-hash-path.ts`) just reads
 * `location.hash` directly.
 */
export const HashLinkAdapter: NavLinkAdapter = ({ href, children, ...props }) => (
  <a href={`#${href}`} {...props}>
    {children}
  </a>
)
