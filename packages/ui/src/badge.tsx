import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { Slot } from "radix-ui"

import { Icon, type IconName } from "./icon"
import { cn } from "./utils"

/**
 * The reference is docs/design/components/Badge (bundle.css `.wy-badge`).
 * State badges are tinted pills: the status tint behind the status text
 * colour (decision 7.4), never a solid fill. `outline` and `secondary` are
 * for counts and categories; `default` sparingly, for a plan.
 */
const badgeVariants = cva(
  "group/badge inline-flex h-[22px] w-fit shrink-0 items-center justify-center gap-1 overflow-hidden rounded-full border border-transparent px-[9px] text-xs leading-none font-medium whitespace-nowrap focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring [&>svg]:pointer-events-none [&>svg]:size-3!",
  {
    variants: {
      variant: {
        default: "bg-primary text-primary-foreground",
        secondary: "bg-info-tint text-info-text",
        outline: "border-input bg-card text-foreground",
        success: "bg-success-tint text-success-text",
        info: "bg-info-tint text-info-text",
        warning: "bg-warning-tint text-warning-text",
        destructive: "bg-error-tint text-error-text",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Badge({
  className,
  variant = "default",
  asChild = false,
  icon,
  children,
  ...props
}: React.ComponentProps<"span"> &
  VariantProps<typeof badgeVariants> & {
    asChild?: boolean
    /** A curated icon before the word. A state badge always carries its word too. */
    icon?: IconName
  }) {
  const Comp = asChild ? Slot.Root : "span"

  return (
    <Comp
      data-slot="badge"
      data-variant={variant}
      className={cn(badgeVariants({ variant }), className)}
      {...props}
    >
      {icon && !asChild ? (
        <>
          <Icon name={icon} size={12} />
          {children}
        </>
      ) : (
        children
      )}
    </Comp>
  )
}

export { Badge, badgeVariants }
