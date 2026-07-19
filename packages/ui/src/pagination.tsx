import * as React from "react"
import { IconChevronLeft, IconChevronRight } from "@tabler/icons-react"

import { cn } from "./utils"
import { Button } from "./button"

/**
 * Routing-agnostic prev/next pagination — modeled on the ad-hoc pattern
 * already used on the audit-logs page (total/page/totalPages + prev/next
 * buttons disabled at the boundaries), promoted to a shared, token-styled
 * primitive. Migrating that page's inline markup onto this component is a
 * follow-up, not in scope here.
 *
 * PLATFORM NOTE: `page`/`perPage`/`total`/`onPageChange` are plain
 * numbers/callbacks — no coupling to Next.js routing (no useRouter/
 * useSearchParams anywhere here) — directly mirrorable by a future Flutter
 * pagination widget.
 */
export interface PaginationProps extends Omit<React.ComponentProps<"nav">, "onChange"> {
  /** 1-indexed current page. */
  page: number
  perPage: number
  /** Total row count across all pages (not just the current page). */
  total: number
  onPageChange: (page: number) => void
  /** Optional override for the "N entries" label (e.g. singular/plural, i18n). */
  entriesLabel?: (total: number) => string
}

function Pagination({
  className,
  page,
  perPage,
  total,
  onPageChange,
  entriesLabel,
  ...props
}: PaginationProps) {
  const totalPages = Math.max(1, Math.ceil(total / perPage))
  const label = entriesLabel ?? ((n: number) => (n === 1 ? "1 entry" : `${n} entries`))

  return (
    <nav
      data-slot="pagination"
      aria-label="Pagination"
      className={cn("flex items-center justify-between", className)}
      {...props}
    >
      <p className="text-sm text-muted-foreground">
        {label(total)} &middot; page {page} of {totalPages}
      </p>
      <div className="flex gap-2">
        <Button
          type="button"
          variant="outline"
          size="icon-sm"
          disabled={page <= 1}
          onClick={() => onPageChange(Math.max(1, page - 1))}
          aria-label="Previous page"
        >
          <IconChevronLeft className="rtl:rotate-180" />
        </Button>
        <Button
          type="button"
          variant="outline"
          size="icon-sm"
          disabled={page >= totalPages}
          onClick={() => onPageChange(Math.min(totalPages, page + 1))}
          aria-label="Next page"
        >
          <IconChevronRight className="rtl:rotate-180" />
        </Button>
      </div>
    </nav>
  )
}

export { Pagination }
