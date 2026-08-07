import * as React from "react"
import { Separator as SeparatorPrimitive } from "radix-ui"

import { cn } from "./utils"

/**
 * Thin horizontal/vertical divider. Replaces the repeated hand-rolled
 * `border-t`/`border-b`/`divide-y divide-border` idiom used across
 * web/app and web/components (onboarding, branding settings, admin pages,
 * etc.) with a single accessible primitive (decorative by default —
 * `role="none"` — so it doesn't clutter the a11y tree of the many pages
 * that already convey structure via headings).
 */
function Separator({
  className,
  orientation = "horizontal",
  decorative = true,
  ...props
}: React.ComponentProps<typeof SeparatorPrimitive.Root>) {
  return (
    <SeparatorPrimitive.Root
      data-slot="separator"
      decorative={decorative}
      orientation={orientation}
      className={cn(
        "shrink-0 bg-border data-[orientation=horizontal]:h-px data-[orientation=horizontal]:w-full data-[orientation=vertical]:h-full data-[orientation=vertical]:w-px",
        className
      )}
      {...props}
    />
  )
}

export { Separator }
