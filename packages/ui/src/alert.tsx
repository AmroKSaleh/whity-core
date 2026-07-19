import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"

import { cn } from "./utils"

/**
 * Tone backgrounds are deliberately pastel (a sheer `/10` tint over the
 * surface, `/20` in dark mode) — the tone reads from the tinted surface +
 * colored border, not from saturated fills. `AlertDescription` always stays
 * `text-muted-foreground` (never tone-colored): at the description's small
 * size, several tone tokens fall short of 4.5:1 against a pastel tint, so
 * color carries the state via icon/title + surface/border, never body copy.
 *
 * `warning` is the one tone whose own color (designed to be legible as WHITE
 * text on a solid warning fill) reads at only ~2.6:1 as foreground text on
 * its own pastel tint — nowhere near the ~3:1 floor even large/bold text
 * needs. Its icon keeps the amber accent (a small decorative glyph, not
 * scrutinized as text), but the title/description fall back to the same
 * high-contrast `warning-foreground` pairing used for solid warning fills.
 */
const alertVariants = cva(
  "group/alert relative grid w-full gap-1 rounded-lg border px-3 py-2.5 text-start text-xs/relaxed has-data-[slot=alert-action]:relative has-data-[slot=alert-action]:pe-18 has-[>svg]:grid-cols-[auto_1fr] has-[>svg]:gap-x-2 *:[svg]:row-span-2 *:[svg]:translate-y-0.5 *:[svg]:text-current *:[svg:not([class*='size-'])]:size-4",
  {
    variants: {
      variant: {
        default: "border-border bg-card text-card-foreground",
        info: "border-info/30 bg-info/10 text-info dark:bg-info/20",
        success: "border-success/30 bg-success/10 text-success dark:bg-success/20",
        warning:
          "border-warning/30 bg-warning/10 text-warning-foreground dark:bg-warning/20 *:[svg]:text-warning",
        destructive:
          "border-destructive/30 bg-destructive/10 text-destructive dark:bg-destructive/20",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Alert({
  className,
  variant,
  ...props
}: React.ComponentProps<"div"> & VariantProps<typeof alertVariants>) {
  return (
    <div
      data-slot="alert"
      role="alert"
      className={cn(alertVariants({ variant }), className)}
      {...props}
    />
  )
}

function AlertTitle({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-title"
      className={cn(
        "text-sm font-semibold group-has-[>svg]/alert:col-start-2 [&_a]:underline [&_a]:underline-offset-3 [&_a]:hover:text-foreground",
        className
      )}
      {...props}
    />
  )
}

function AlertDescription({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-description"
      className={cn(
        "text-xs/relaxed text-balance text-muted-foreground md:text-pretty [&_a]:underline [&_a]:underline-offset-3 [&_a]:hover:text-foreground [&_p:not(:last-child)]:mb-4",
        className
      )}
      {...props}
    />
  )
}

function AlertAction({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-action"
      className={cn("absolute top-2.5 end-3", className)}
      {...props}
    />
  )
}

export { Alert, AlertTitle, AlertDescription, AlertAction }
