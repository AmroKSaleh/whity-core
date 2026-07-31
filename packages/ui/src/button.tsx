import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { Slot } from "radix-ui"
import { IconLoader2 } from "@tabler/icons-react"

import { cn } from "./utils"

const buttonVariants = cva(
  "group/button inline-flex shrink-0 items-center justify-center rounded-md border border-transparent bg-clip-padding font-medium whitespace-nowrap transition-all outline-none select-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 active:not-aria-[haspopup]:translate-y-px disabled:pointer-events-none disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-2 aria-invalid:ring-destructive/20 dark:aria-invalid:border-destructive/50 dark:aria-invalid:ring-destructive/40 [&_svg]:pointer-events-none [&_svg]:shrink-0",
  {
    variants: {
      variant: {
        default: "bg-primary text-primary-foreground hover:bg-primary/80 shadow-2xs",
        outline:
          "border-border bg-card text-foreground hover:bg-muted/70 hover:text-foreground aria-expanded:bg-muted aria-expanded:text-foreground shadow-2xs",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-secondary/80 aria-expanded:bg-secondary aria-expanded:text-secondary-foreground",
        ghost:
          "hover:bg-muted hover:text-foreground aria-expanded:bg-muted aria-expanded:text-foreground dark:hover:bg-muted/50",
        info:
          "border border-blue-500/30 bg-blue-500/10 text-blue-800 hover:bg-blue-500/20 dark:border-blue-500/30 dark:bg-blue-950/40 dark:text-blue-300 dark:hover:bg-blue-950/60",
        "info-solid":
          "bg-blue-600 text-white hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-500 shadow-2xs",
        success:
          "border border-emerald-500/30 bg-emerald-500/10 text-emerald-800 hover:bg-emerald-500/20 dark:border-emerald-500/30 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60",
        "success-solid":
          "bg-emerald-600 text-white hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-500 shadow-2xs",
        warning:
          "border border-amber-500/35 bg-amber-500/10 text-amber-900 hover:bg-amber-500/20 dark:border-amber-500/35 dark:bg-amber-950/50 dark:text-amber-300 dark:hover:bg-amber-950/70",
        "warning-solid":
          "bg-amber-600 text-white hover:bg-amber-700 dark:bg-amber-600 dark:hover:bg-amber-500 shadow-2xs",
        destructive:
          "border border-red-500/30 bg-red-500/10 text-red-800 hover:bg-red-500/20 focus-visible:border-red-500/40 focus-visible:ring-red-500/20 dark:border-red-500/30 dark:bg-red-950/40 dark:text-red-300 dark:hover:bg-red-950/60",
        "destructive-solid":
          "bg-destructive text-destructive-foreground hover:bg-destructive/90 shadow-2xs",
        purple:
          "border border-purple-500/30 bg-purple-500/10 text-purple-800 hover:bg-purple-500/20 dark:border-purple-500/30 dark:bg-purple-950/40 dark:text-purple-300 dark:hover:bg-purple-950/60",
        "purple-solid":
          "bg-purple-600 text-white hover:bg-purple-700 dark:bg-purple-600 dark:hover:bg-purple-500 shadow-2xs",
        link: "text-primary underline underline-offset-4 decoration-primary/60 decoration-1 hover:underline-offset-2 hover:decoration-2 hover:decoration-primary active:underline-offset-1 active:decoration-4 active:scale-[0.97] transition-all duration-200 ease-out",
      },
      size: {
        default:
          "h-8 gap-1.5 px-3 text-xs/relaxed has-data-[icon=inline-end]:pe-2.5 has-data-[icon=inline-start]:ps-2.5 [&_svg:not([class*='size-'])]:size-3.5",
        xs: "h-6 gap-1 rounded-md px-2 text-[0.6875rem] font-semibold has-data-[icon=inline-end]:pe-1.5 has-data-[icon=inline-start]:ps-1.5 [&_svg:not([class*='size-'])]:size-3",
        sm: "h-7 gap-1 px-2.5 text-xs/relaxed has-data-[icon=inline-end]:pe-2 has-data-[icon=inline-start]:ps-2 [&_svg:not([class*='size-'])]:size-3.5",
        lg: "h-9 gap-1.5 px-4 text-sm/relaxed has-data-[icon=inline-end]:pe-3 has-data-[icon=inline-start]:ps-3 [&_svg:not([class*='size-'])]:size-4",
        icon: "size-8 [&_svg:not([class*='size-'])]:size-3.5",
        "icon-xs": "size-6 rounded-md [&_svg:not([class*='size-'])]:size-3",
        "icon-sm": "size-7 [&_svg:not([class*='size-'])]:size-3.5",
        "icon-lg": "size-9 [&_svg:not([class*='size-'])]:size-4",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant = "default",
  size = "default",
  asChild = false,
  loading = false,
  active = false,
  disabled,
  children,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
    /** Show an inline spinner, disable the button, and expose `aria-busy`. */
    loading?: boolean
    /** Active/pressed state for toggle buttons. */
    active?: boolean
  }) {
  const Comp = asChild ? Slot.Root : "button"
  // When rendering via `asChild`, Slot requires a single child element, so we
  // cannot inject a spinner alongside it — only apply the busy/disabled state.
  const showSpinner = loading && !asChild

  return (
    <Comp
      data-slot="button"
      data-variant={variant}
      data-size={size}
      data-active={active || undefined}
      aria-pressed={active || undefined}
      className={cn(
        buttonVariants({ variant, size }),
        active && "bg-accent text-accent-foreground border-accent-foreground/20 font-semibold",
        className
      )}
      disabled={disabled || (loading && !asChild)}
      aria-busy={loading || undefined}
      {...props}
    >
      {showSpinner ? (
        <>
          <IconLoader2 className="animate-spin" aria-hidden="true" />
          {children}
        </>
      ) : (
        children
      )}
    </Comp>
  )
}

export { Button, buttonVariants }
