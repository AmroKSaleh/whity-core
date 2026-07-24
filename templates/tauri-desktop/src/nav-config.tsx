import { IconBox, IconHome, IconPrinter } from "@tabler/icons-react"
import type { NavConfig } from "@amroksaleh/features/nav"

/**
 * This app's nav config — plain data, per the nav contract
 * (@amroksaleh/features/nav). Add a route by adding an item here; no other
 * wiring needed besides the corresponding branch in App.tsx's view switch.
 */
export const navConfig: NavConfig = {
  groups: [
    {
      id: "general",
      label: "General",
      items: [{ id: "home", label: "Home", href: "/", icon: <IconHome /> }],
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
          icon: <IconBox />,
        },
        {
          id: "printer-demo",
          label: "Printer demo",
          href: "/printer-demo",
          icon: <IconPrinter />,
        },
      ],
    },
  ],
}
