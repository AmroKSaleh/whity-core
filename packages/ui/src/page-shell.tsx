import * as React from "react"

import { cn } from "./utils"

/**
 * PRESENTATIONAL application-frame layout only — slots for a sidebar, an
 * optional top bar, and a content region. No auth/redirect/onboarding logic
 * (that's the app-specific route layout wrapping this, e.g.
 * web/app/(protected)/layout.tsx) — this component only owns the chrome
 * arrangement so every client's "logged-in app frame" matches without
 * re-implementing the flex/scroll layout by hand.
 */
export interface PageShellProps {
  sidebar: React.ReactNode
  topBar?: React.ReactNode
  children: React.ReactNode
  className?: string
  /** Class applied to the scrollable content region (defaults to standard page padding). */
  contentClassName?: string
}

export function PageShell({ sidebar, topBar, children, className, contentClassName }: PageShellProps) {
  return (
    <div data-slot="page-shell" className={cn("flex min-h-screen bg-background", className)}>
      {sidebar}
      <div className="flex min-w-0 flex-1 flex-col">
        {topBar}
        <main className={cn("flex-1 overflow-y-auto p-6", contentClassName)}>{children}</main>
      </div>
    </div>
  )
}
