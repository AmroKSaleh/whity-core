import { IconBox, IconHome } from "@tabler/icons-react"

import type { NavConfig } from "./types"

/**
 * Example nav config, shipped per the app-shell nav contract's deliverable:
 * a concrete, working reference for any new client author (a downstream
 * product's Vite/Tauri SPA, a future Flutter nav rail) to copy and adapt.
 *
 * This is DATA — icons are the only "component" values here (React nodes
 * created once, reused across renders like `AppSidebar` already expects),
 * everything else is a plain string/href. It has no dependency on any
 * specific application's routes; a real client's config replaces every id/
 * href/translationKey with its own.
 *
 * i18n: labels use `translationKey`, resolved through whatever `t()` the
 * client injects into `resolveNavGroups` — this file never picks an i18n
 * library. RTL: `AppSidebar` itself is fully bidi-aware (logical
 * start/end-* Tailwind classes, `rtl:` variants) — this config needs no RTL
 * concessions at all, another point in favor of describing nav as plain data.
 */
export const exampleNavConfig: NavConfig = {
  groups: [
    {
      id: "general",
      translationKey: "nav.group.general",
      label: "General",
      items: [
        {
          id: "home",
          translationKey: "nav.home",
          label: "Home",
          href: "/",
          icon: <IconHome />,
        },
      ],
    },
    {
      id: "plugins",
      translationKey: "nav.group.plugins",
      label: "Plugins",
      items: [
        {
          id: "demo-catalog",
          translationKey: "nav.demoCatalog",
          label: "Demo Catalog",
          href: "/demo-catalog",
          // A list item stays active on its own detail sub-routes too.
          activeMatch: "/demo-catalog/*",
          icon: <IconBox />,
        },
      ],
    },
  ],
}
