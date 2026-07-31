import * as React from "react"

import { cn } from "./utils"

export interface PageHeaderProps {
  /**
   * Main page title text or node.
   */
  title: React.ReactNode
  /**
   * Subtitle or description explaining the page context.
   */
  description?: React.ReactNode
  /**
   * Optional icon displayed in a rounded container next to the title.
   */
  icon?: React.ReactNode
  /**
   * Optional status or version badge node displayed next to the title.
   */
  badge?: React.ReactNode
  /**
   * Action buttons or controls rendered on the right side of the header.
   */
  action?: React.ReactNode
  /**
   * Breadcrumb navigation trail rendered above the title.
   */
  breadcrumb?: React.ReactNode
  /**
   * Secondary controls rendered below the header (e.g. tabs, search bar, filters).
   */
  children?: React.ReactNode
  /**
   * Visual layout style: "default" (clean section with border) or "card" (elevated card container).
   */
  variant?: "default" | "card"
  className?: string
}

export function PageHeader({
  title,
  description,
  icon,
  badge,
  action,
  breadcrumb,
  children,
  variant = "default",
  className,
}: PageHeaderProps) {
  if (variant === "card") {
    return (
      <div
        data-slot="page-header"
        className={cn(
          "mb-6 flex flex-col gap-4 rounded-xl border border-border bg-card p-5 md:p-6 shadow-2xs transition-colors",
          className
        )}
      >
        {breadcrumb && <div className="text-xs text-muted-foreground">{breadcrumb}</div>}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div className="flex items-start gap-3.5 min-w-0">
            {icon && (
              <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary shadow-2xs">
                <span className="[&_svg]:size-5">{icon}</span>
              </div>
            )}
            <div className="flex flex-col gap-1 min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h1 className="text-xl md:text-2xl font-bold tracking-tight text-foreground truncate">
                  {title}
                </h1>
                {badge && <div className="shrink-0">{badge}</div>}
              </div>
              {description && (
                <p className="text-xs md:text-sm text-muted-foreground leading-relaxed max-w-3xl">
                  {description}
                </p>
              )}
            </div>
          </div>
          {action && <div className="flex items-center gap-2 shrink-0 self-start md:self-center">{action}</div>}
        </div>
        {children && <div className="pt-2 border-t border-border/50">{children}</div>}
      </div>
    )
  }

  return (
    <div
      data-slot="page-header"
      className={cn("mb-6 flex flex-col gap-3.5 border-b border-border pb-5", className)}
    >
      {breadcrumb && <div className="text-xs text-muted-foreground">{breadcrumb}</div>}
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div className="flex items-start gap-3.5 min-w-0">
          {icon && (
            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary shadow-2xs mt-0.5">
              <span className="[&_svg]:size-4.5">{icon}</span>
            </div>
          )}
          <div className="flex flex-col gap-1 min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-xl md:text-2xl font-bold tracking-tight text-foreground truncate">
                {title}
              </h1>
              {badge && <div className="shrink-0">{badge}</div>}
            </div>
            {description && (
              <p className="text-xs md:text-sm text-muted-foreground leading-relaxed max-w-3xl">
                {description}
              </p>
            )}
          </div>
        </div>
        {action && <div className="flex items-center gap-2 shrink-0 self-start md:self-center">{action}</div>}
      </div>
      {children && <div className="pt-2">{children}</div>}
    </div>
  )
}
