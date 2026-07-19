import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { IconInbox, IconAlertTriangle } from "@tabler/icons-react"

import { cn } from "./utils"

/**
 * Shared shape for EmptyState ("nothing here yet") and ErrorState ("something
 * went wrong") — the two states share every prop; only the tone/default icon
 * and the announced ARIA role differ. Kept as one variant-driven component so
 * the two never drift apart visually.
 *
 * PLATFORM NOTE: `icon` and `action` are optional slot overrides (web-only —
 * arbitrary React nodes cannot cross to a future Flutter/Dart port). Every
 * OTHER prop (`variant`, `title`, `description`) is a plain string/enum, so a
 * Flutter implementation can mirror the same contract and supply its own
 * platform-appropriate default icon/action per variant.
 */
const emptyStateVariants = cva(
  "flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border p-8 text-center",
  {
    variants: {
      variant: {
        empty: "text-muted-foreground",
        error: "border-destructive/30 text-destructive",
      },
    },
    defaultVariants: {
      variant: "empty",
    },
  }
)

export interface EmptyStateProps
  extends Omit<React.ComponentProps<"div">, "title">,
    VariantProps<typeof emptyStateVariants> {
  /** Slot override for the icon; falls back to a sensible per-variant default. */
  icon?: React.ReactNode
  title: string
  description?: string
  /** Slot for a retry/create/etc. control — typically a <Button>. */
  action?: React.ReactNode
}

function EmptyState({
  className,
  variant,
  icon,
  title,
  description,
  action,
  ...props
}: EmptyStateProps) {
  const resolvedVariant = variant ?? "empty"
  const defaultIcon =
    resolvedVariant === "error" ? <IconAlertTriangle /> : <IconInbox />

  return (
    <div
      data-slot="empty-state"
      // Errors interrupt (assertive); an empty list is just informational
      // (polite) — matches the same distinction Alert/ErrorState conventions
      // draw elsewhere in this package.
      role={resolvedVariant === "error" ? "alert" : "status"}
      className={cn(emptyStateVariants({ variant }), className)}
      {...props}
    >
      <div
        data-slot="empty-state-icon"
        className="text-muted-foreground [&>svg]:size-8 [&>svg]:stroke-1"
      >
        {icon ?? defaultIcon}
      </div>
      <div data-slot="empty-state-title" className="text-sm font-medium text-foreground">
        {title}
      </div>
      {description ? (
        <div
          data-slot="empty-state-description"
          className="max-w-sm text-xs/relaxed text-muted-foreground text-balance"
        >
          {description}
        </div>
      ) : null}
      {action ? (
        <div data-slot="empty-state-action" className="mt-2">
          {action}
        </div>
      ) : null}
    </div>
  )
}

/** ErrorState is EmptyState pinned to variant="error" — same contract, error tone. */
function ErrorState(props: Omit<EmptyStateProps, "variant">) {
  return <EmptyState {...props} variant="error" />
}

export { EmptyState, ErrorState, emptyStateVariants }
