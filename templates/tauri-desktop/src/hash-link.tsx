import type { NavLinkAdapter } from "@amroksaleh/features/nav"

/**
 * The app's `NavLinkAdapter` implementation — a zero-dependency hash router
 * satisfying `AppSidebar`'s `linkComponent` prop (and the nav contract built
 * on top of it). Mirrors packages/spa-harness's HashLinkAdapter.
 *
 * This is a starting point, not a requirement: swap in `react-router` (or
 * any router) by implementing this same three-prop contract (`href` +
 * `children` + pass-through props) against your router's `<Link>`.
 */
export const HashLinkAdapter: NavLinkAdapter = ({ href, children, ...props }) => (
  <a href={`#${href}`} {...props}>
    {children}
  </a>
)
