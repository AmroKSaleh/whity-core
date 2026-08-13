import * as React from "react"
import { IconChevronRight, IconHome } from "@tabler/icons-react"

import { cn } from "./utils"

/**
 * PRESENTATIONAL breadcrumb — a plain, controlled list of items with rich visual feedback.
 */
export interface BreadcrumbItem {
  label: string
  /** Omit (or omit on the LAST item) to render plain text instead of a link — the current page is never a link. */
  href?: string
  /** Optional icon displayed before the label (e.g. <IconHome /> for root). */
  icon?: React.ReactNode
}

export interface BreadcrumbProps extends Omit<React.ComponentProps<"nav">, "children"> {
  items: BreadcrumbItem[]
  /** Custom link component (e.g. Next.js <Link>) — defaults to a plain <a>. */
  linkComponent?: React.ElementType
  /** Automatically render a Home icon on the first item. */
  showHomeIcon?: boolean
  /** Custom separator node between items. Defaults to a subtle chevron. */
  separator?: React.ReactNode
  /** Accessible name for the <nav>. Defaults to "Breadcrumb". */
  navLabel?: string
}

function Breadcrumb({
  className,
  items,
  linkComponent,
  showHomeIcon = false,
  separator,
  navLabel = "Breadcrumb",
  ...props
}: BreadcrumbProps) {
  const Link = linkComponent ?? "a"

  return (
    <nav data-slot="breadcrumb" aria-label={navLabel} className={className} {...props}>
      <ol className="flex flex-wrap items-center gap-1 text-xs/relaxed text-muted-foreground">
        {items.map((item, index) => {
          const isLast = index === items.length - 1
          const isFirst = index === 0
          const itemIcon = item.icon ?? (isFirst && showHomeIcon ? <IconHome /> : null)

          return (
            <li key={`${item.label}-${index}`} className="flex items-center gap-1">
              {index > 0 && (
                <span className="flex items-center justify-center px-0.5 text-muted-foreground/40">
                  {separator ?? (
                    <IconChevronRight
                      aria-hidden="true"
                      className="size-3 shrink-0 rtl:rotate-180"
                    />
                  )}
                </span>
              )}
              {isLast || !item.href ? (
                <span
                  aria-current={isLast ? "page" : undefined}
                  className={cn(
                    "inline-flex items-center gap-1.5 rounded-md px-1.5 py-0.5 text-xs transition-colors",
                    isLast
                      ? "font-semibold text-foreground bg-muted/40 border border-border/40 shadow-2xs"
                      : "font-medium text-muted-foreground"
                  )}
                >
                  {itemIcon && <span className="shrink-0 [&_svg]:size-3.5">{itemIcon}</span>}
                  {item.label}
                </span>
              ) : (
                <Link
                  href={item.href}
                  className="inline-flex items-center gap-1.5 rounded-md px-1.5 py-0.5 text-xs font-medium transition-all hover:bg-muted/70 hover:text-foreground active:scale-95"
                >
                  {itemIcon && <span className="shrink-0 text-muted-foreground/80 [&_svg]:size-3.5">{itemIcon}</span>}
                  {item.label}
                </Link>
              )}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}

export { Breadcrumb }
