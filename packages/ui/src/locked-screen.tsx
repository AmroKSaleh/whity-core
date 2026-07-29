import * as React from "react"
import { IconLock } from "@tabler/icons-react"

import { cn } from "./utils"

/**
 * Full-page LOCKED state — e.g. an offline-first desktop app whose cached-login
 * TTL has elapsed and must re-authenticate online (WC-desktop-sync). A sibling
 * of {@link AccessDenied}: domain-free, presentational, `role="alert"`.
 *
 * Like AccessDenied, `action` has NO default — the caller supplies the re-login
 * button/link. This primitive owns no auth opinion (a desktop app wires its
 * "sign in again" here; another product might do something else).
 */
export interface LockedScreenProps extends Omit<React.ComponentProps<"div">, "title"> {
  icon?: React.ReactNode
  title?: React.ReactNode
  description: React.ReactNode
  action?: React.ReactNode
}

function LockedScreen({
  className,
  icon,
  title = "Session locked",
  description,
  action,
  ...props
}: LockedScreenProps) {
  return (
    <div
      data-slot="locked-screen"
      role="alert"
      className={cn(
        "flex min-h-[450px] flex-col items-center justify-center rounded-2xl border border-border bg-card p-8 text-center shadow-sm",
        className
      )}
      {...props}
    >
      <div
        data-slot="locked-screen-icon"
        className="mb-4 rounded-full bg-muted p-4 text-muted-foreground [&>svg]:size-12"
      >
        {icon ?? <IconLock />}
      </div>
      <h2 data-slot="locked-screen-title" className="mb-2 text-xl font-bold text-foreground">
        {title}
      </h2>
      <div
        data-slot="locked-screen-description"
        className="mb-6 max-w-md text-sm text-muted-foreground"
      >
        {description}
      </div>
      {action}
    </div>
  )
}

export { LockedScreen }
