import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { IconLoader2 } from "@tabler/icons-react"

import { cn } from "./utils"

/**
 * A dedicated loading indicator — replaces the ad-hoc `animate-spin
 * rounded-full border-b-2 border-primary` divs scattered across admin pages
 * (audit-logs, mcp-tools, plugins, registrations, …). Reuses the same
 * IconLoader2 + animate-spin pairing Button already uses for its own
 * `loading` state, so a standalone spinner and a button's inline spinner
 * always look identical.
 *
 * PLATFORM NOTE: `size` and `label` are plain enum/string props — directly
 * mirrorable by a future Flutter CircularProgressIndicator wrapper.
 */
const spinnerVariants = cva("animate-spin text-muted-foreground", {
  variants: {
    size: {
      sm: "size-4",
      default: "size-6",
      lg: "size-8",
    },
  },
  defaultVariants: {
    size: "default",
  },
})

export interface SpinnerProps
  extends Omit<React.ComponentProps<"span">, "children">,
    VariantProps<typeof spinnerVariants> {
  /** Visually-hidden text announced to assistive tech. Defaults to "Loading". */
  label?: string
}

function Spinner({ className, size, label = "Loading", ...props }: SpinnerProps) {
  return (
    <span
      data-slot="spinner"
      role="status"
      // A generic status/region role gets no "name from content" in the
      // accessible-name computation — aria-label is required, a visually-
      // hidden text child alone would be silently ignored by assistive tech.
      aria-label={label}
      className={cn("inline-flex", className)}
      {...props}
    >
      <IconLoader2 aria-hidden="true" className={cn(spinnerVariants({ size }))} />
    </span>
  )
}

export { Spinner, spinnerVariants }
