import * as React from "react"
import { IconChevronRight } from "@tabler/icons-react"

import { cn } from "./utils"

/**
 * PRESENTATIONAL breadcrumb only — a plain, controlled list of items. Deriving
 * crumbs from the current route (pathname-driven logic) belongs to the
 * app-chrome-shell task, which wraps this component; this component itself
 * takes no dependency on Next.js routing.
 *
 * PLATFORM NOTE: `items` (label + optional href) is a plain data array — the
 * same shape a Flutter breadcrumb widget would consume.
 */
export interface BreadcrumbItem {
  label: string
  /** Omit (or omit on the LAST item) to render plain text instead of a link — the current page is never a link. */
  href?: string
}

export interface BreadcrumbProps extends Omit<React.ComponentProps<"nav">, "children"> {
  items: BreadcrumbItem[]
  /** Custom link component (e.g. Next.js <Link>) — defaults to a plain <a>. */
  linkComponent?: React.ElementType
}

function Breadcrumb({ className, items, linkComponent, ...props }: BreadcrumbProps) {
  const Link = linkComponent ?? "a"

  return (
    <nav data-slot="breadcrumb" aria-label="Breadcrumb" className={className} {...props}>
      <ol className="flex flex-wrap items-center gap-1.5 text-xs/relaxed text-muted-foreground">
        {items.map((item, index) => {
          const isLast = index === items.length - 1

          return (
            <li key={`${item.label}-${index}`} className="flex items-center gap-1.5">
              {index > 0 ? (
                <IconChevronRight
                  aria-hidden="true"
                  className="size-3.5 shrink-0 text-muted-foreground/60 rtl:rotate-180"
                />
              ) : null}
              {isLast || !item.href ? (
                <span
                  aria-current={isLast ? "page" : undefined}
                  className={cn(isLast && "font-medium text-foreground")}
                >
                  {item.label}
                </span>
              ) : (
                <Link
                  href={item.href}
                  className="transition-colors hover:text-foreground"
                >
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
