import * as React from "react"

/**
 * PRESENTATIONAL page header only — title/description/breadcrumb/action
 * slots, no data fetching. A generalized, registry-published version of
 * web/components/admin/admin-header.tsx's shape so non-admin pages (and
 * non-Next clients) get the same header chrome without re-deriving it.
 */
export interface PageHeaderProps {
  title: string
  description?: string
  action?: React.ReactNode
  breadcrumb?: React.ReactNode
  className?: string
}

export function PageHeader({ title, description, action, breadcrumb, className }: PageHeaderProps) {
  return (
    <div className={className ? `mb-8 border-b border-border pb-6 ${className}` : "mb-8 border-b border-border pb-6"}>
      {breadcrumb && <div className="mb-2 text-sm text-muted-foreground">{breadcrumb}</div>}
      <div className="flex items-center justify-between">
        <div className="flex-1">
          <h1 className="text-3xl font-bold text-foreground">{title}</h1>
          {description && <p className="mt-2 text-sm text-muted-foreground">{description}</p>}
        </div>
        {action && <div className="ms-6">{action}</div>}
      </div>
    </div>
  )
}
