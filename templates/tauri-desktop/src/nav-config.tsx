import { IconBox, IconBuildingStore, IconHome, IconPrinter, IconPuzzle, IconShieldLock } from "@tabler/icons-react"
import type { NavConfig } from "@amroksaleh/features/nav"

/**
 * This app's nav config — plain data, per the nav contract
 * (@amroksaleh/features/nav). Add a route by adding an item here; no other
 * wiring needed besides the corresponding branch in App.tsx's view switch.
 *
 * Icons carry an explicit `size-5` (20px, matching the website's sidebar —
 * see web/components/sidebar.tsx's `size={20}` icons): the shared `Button`
 * component (src/sidebar.tsx wraps every nav row in one) shrinks any
 * descendant `<svg>` to 14px UNLESS its class already contains "size-"
 * (`[&_svg:not([class*='size-'])]:size-3.5`), so an icon with no size class
 * of its own would render smaller than the website's.
 */
export const navConfig: NavConfig = {
  groups: [
    {
      id: "general",
      label: "General",
      items: [{ id: "home", label: "Home", href: "/", icon: <IconHome className="size-5" /> }],
    },
    {
      id: "demo",
      label: "Demo",
      items: [
        {
          id: "demo-catalog",
          label: "Demo Catalog",
          href: "/demo-catalog",
          activeMatch: "/demo-catalog/*",
          icon: <IconBox className="size-5" />,
        },
        {
          id: "printer-demo",
          label: "Printer demo",
          href: "/printer-demo",
          icon: <IconPrinter className="size-5" />,
        },
        {
          id: "plugins",
          label: "Plugins",
          href: "/plugins",
          icon: <IconPuzzle className="size-5" />,
        },
        {
          id: "plugin-store",
          label: "Plugin store",
          href: "/plugin-store",
          icon: <IconBuildingStore className="size-5" />,
        },
      ],
    },
    {
      id: "administration",
      label: "Administration",
      items: [
        {
          id: "roles",
          label: "Roles",
          href: "/roles",
          // Server-owned admin surface: RolesScreen fetches through the remote
          // transport and the backend RBAC is authoritative. The entry is shown
          // unconditionally; a user without roles access sees the screen's error
          // state (capability-gating the nav entry itself is a later refinement,
          // once App resolves capabilities at nav-render time).
          icon: <IconShieldLock className="size-5" />,
        },
      ],
    },
  ],
}
