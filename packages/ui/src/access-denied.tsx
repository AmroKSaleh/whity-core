import * as React from "react"
import { IconAlertCircle } from "@tabler/icons-react"

import { cn } from "./utils"

/**
 * Full-page permission-denied state — the "you don't have access to this
 * whole screen" card, as opposed to {@link EmptyState}'s `error` variant
 * (a smaller, dashed-border panel meant to sit INLINE inside an otherwise
 * rendered page). Unifies markup that was previously hand-copied across
 * every admin page that gates an entire route on a permission.
 *
 * `action` has no default (e.g. a "Go Back" button) — `history.back()` is a
 * browser API, not something this platform-neutral primitive should assume;
 * the caller supplies whatever action fits (a button, a link to another
 * page, or nothing).
 */
export interface AccessDeniedProps extends Omit<React.ComponentProps<"div">, "title"> {
  icon?: React.ReactNode
  title?: React.ReactNode
  description: React.ReactNode
  action?: React.ReactNode
}

function AccessDenied({
  className,
  icon,
  title = "Access Denied",
  description,
  action,
  ...props
}: AccessDeniedProps) {
  return (
    <div
      data-slot="access-denied"
      role="alert"
      className={cn(
        "flex min-h-[450px] flex-col items-center justify-center rounded-2xl border border-border bg-card p-8 text-center shadow-sm",
        className
      )}
      {...props}
    >
      <div
        data-slot="access-denied-icon"
        className="mb-4 rounded-full bg-destructive/10 p-4 text-destructive [&>svg]:size-12"
      >
        {icon ?? <IconAlertCircle />}
      </div>
      <h2 data-slot="access-denied-title" className="mb-2 text-xl font-bold text-foreground">
        {title}
      </h2>
      <div
        data-slot="access-denied-description"
        className="mb-6 max-w-md text-sm text-muted-foreground"
      >
        {description}
      </div>
      {action}
    </div>
  )
}

export { AccessDenied }
