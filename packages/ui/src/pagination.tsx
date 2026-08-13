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
  /**
   * Optional override for "page 2 of 7".
   *
   * A function, not a string, for the same reason `entriesLabel` is one: the
   * numbers sit INSIDE the sentence, and word order around them differs
   * between languages. A caller that could only supply "page" and "of"
   * separately would be pinned to English order.
   */
  pageLabel?: (page: number, totalPages: number) => string
  /** Accessible name for the surrounding <nav>. */
  navLabel?: string
  /** Accessible name for the previous-page button. */
  previousLabel?: string
  /** Accessible name for the next-page button. */
  nextLabel?: string
}

function Pagination({
  className,
  page,
  perPage,
  total,
  onPageChange,
  entriesLabel,
  pageLabel,
  navLabel = "Pagination",
  previousLabel = "Previous page",
  nextLabel = "Next page",
  ...props
}: PaginationProps) {
  const totalPages = Math.max(1, Math.ceil(total / perPage))
  const label = entriesLabel ?? ((n: number) => (n === 1 ? "1 entry" : `${n} entries`))
  const pages = pageLabel ?? ((current: number, last: number) => `page ${current} of ${last}`)

  return (
    <nav
      data-slot="pagination"
      aria-label={navLabel}
      className={cn("flex items-center justify-between", className)}
      {...props}
    >
      <p className="text-sm text-muted-foreground">
        {label(total)} &middot; {pages(page, totalPages)}
      </p>
      <div className="flex gap-2">
        <Button
          type="button"
          variant="outline"
          size="icon-sm"
          disabled={page <= 1}
          onClick={() => onPageChange(Math.max(1, page - 1))}
          aria-label={previousLabel}
        >
          <IconChevronLeft className="rtl:rotate-180" />
        </Button>
        <Button
          type="button"
          variant="outline"
          size="icon-sm"
          disabled={page >= totalPages}
          onClick={() => onPageChange(Math.min(totalPages, page + 1))}
          aria-label={nextLabel}
        >
          <IconChevronRight className="rtl:rotate-180" />
        </Button>
      </div>
    </nav>
  )
}

export { Pagination }
