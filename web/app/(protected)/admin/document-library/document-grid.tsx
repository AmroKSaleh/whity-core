'use client';

import { useFormattingLocale, useTranslation } from '@amroksaleh/features/i18n';
import { Button } from '@amroksaleh/ui/button';
import { EmptyState } from '@amroksaleh/ui/empty-state';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import { IconFileText } from '@tabler/icons-react';
import { Pagination } from '@/components/ui/pagination';
import { DocumentActions, DocumentTitle, StarButton, type DocumentItemHandlers } from './document-item';
import type { LibraryEmptyState } from './library-empty-state';
import type { DocumentRow, Pagination as PaginationMeta } from './types';

/**
 * The tile layout — the same documents, arranged as cards.
 *
 * WHY A GRID EARNS ITS PLACE ON A DOCUMENT BROWSER
 * ------------------------------------------------
 * Not for decoration. A list is the better shape for comparing a column across
 * rows (which unit raised these, when); a grid is the better shape for
 * recognising ONE thing by its name, because the name gets the width of a card
 * instead of the width of a column shared with four other columns. Document
 * titles are long — "Purchase order 2026-0455, Faculty of Engineering" — and in
 * a list every one of them truncates to the same prefix.
 *
 * WHAT IT DELIBERATELY DOES NOT SHOW
 * ----------------------------------
 * There are no thumbnails. A grid of documents wants page previews, and this
 * screen has none to show: `content_url` is a PDF, and rendering a first page to
 * an image is a render-service job nobody has asked for. Drawing a generic sheet
 * icon and calling it a preview would be inventing content (#756) — the icon
 * here is explicitly `aria-hidden` chrome, not a picture OF the document.
 *
 * WHY IT RENDERS ITS OWN EMPTY STATE AND PAGINATION
 * -------------------------------------------------
 * `DataTable` supplies both for the list. The grid is not a table, so it carries
 * the same two, from the same sources: the empty state comes in as data from
 * {@link libraryEmptyState} so the two layouts cannot say different things about
 * the same zero rows, and the pagination is the app's translated `Pagination`
 * over the SERVER's page meta — a grid that re-sliced the page it was handed
 * would page within one page.
 *
 * DIRECTION AND LOCALE
 * --------------------
 * Nothing here is direction-aware, and that is the intent: a CSS grid flows
 * along the inline axis, so it fills right-to-left under `dir="rtl"` on its own.
 * The only physical properties would have been the spacings, and those are
 * logical (`me-`, `text-start`). Titles carry `dir="auto"` in `DocumentTitle`,
 * because a card's own direction follows the interface while the title inside it
 * follows the title.
 *
 * The date is formatted through `useFormattingLocale()` rather than the
 * browser's default, which is what the list column already does. Left bare it
 * renders Gregorian Latin digits inside an otherwise Arabic card — the interface
 * says one thing and the number says another, on the same line — and the
 * difference is invisible to anybody testing in English.
 */
export function DocumentGrid({
  rows,
  isLoading,
  emptyState,
  pagination,
  onPageChange,
  handlers,
  ouName,
  ariaLabel,
}: {
  rows: DocumentRow[];
  isLoading: boolean;
  emptyState: LibraryEmptyState;
  pagination: PaginationMeta | null;
  /** 1-indexed, matching the server's own envelope. */
  onPageChange: (page: number) => void;
  handlers: DocumentItemHandlers;
  ouName: (ouId: number | null) => string;
  ariaLabel: string;
}) {
  const t = useTranslation('documents');
  const locale = useFormattingLocale();

  if (isLoading) {
    return (
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {Array.from({ length: 6 }).map((_, index) => (
          <Skeleton key={index} className="h-32" />
        ))}
      </div>
    );
  }

  if (rows.length === 0) {
    return (
      <EmptyState
        title={emptyState.title}
        description={emptyState.description}
        action={
          emptyState.pastTheEnd ? (
            <Button variant="outline" size="sm" onClick={() => onPageChange(1)}>
              {t('organizer.empty.pastEndAction', 'Go to the first page')}
            </Button>
          ) : undefined
        }
      />
    );
  }

  return (
    <div>
      {/* A list of documents IS a list, so it is marked up as one: a grid of
          divs gives assistive technology no count and no way to step between
          items. `grid` on a <ul> keeps both.

          `role="list"` is written out even though <ul> already has it, because
          `list-none` removes it again: Safari/VoiceOver drop the list semantics
          from any list whose `list-style` is `none`, so the count and the
          "2 of 12" stepping this markup exists to provide would be missing on
          exactly the pairing that needs them most. The redundancy is the fix,
          and it is invisible in Chromium — which is what both the jest and the
          Playwright assertion run on, so neither would have caught it. */}
      <ul
        role="list"
        aria-label={ariaLabel}
        className="grid list-none grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3"
      >
        {rows.map((row) => (
          <li key={row.id} className="rounded-lg border border-border p-3">
            <div className="flex items-start gap-2">
              <IconFileText size={18} className="mt-0.5 shrink-0 text-muted-foreground" aria-hidden />
              {/* min-w-0 is what lets the title truncate instead of stretching
                  the card past the grid track. */}
              <div className="flex min-w-0 flex-1 flex-col gap-1">
                <div className="flex min-w-0 items-center gap-2">
                  <DocumentTitle row={row} />
                </div>
                <p dir="auto" className="truncate text-xs text-muted-foreground">
                  {row.template_name}
                </p>
              </div>
              <StarButton row={row} busy={handlers.busy} onToggleStar={handlers.onToggleStar} />
              <DocumentActions row={row} handlers={handlers} />
            </div>

            <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-muted-foreground">
              <div className="min-w-0">
                <dt className="font-medium">{t('organizer.table.raisedFrom', 'Raised from')}</dt>
                <dd dir="auto" className="truncate">
                  {ouName(row.origin_ou_id)}
                </dd>
              </div>
              <div className="min-w-0">
                <dt className="font-medium">{t('organizer.table.created', 'Created')}</dt>
                <dd className="truncate">
                  {new Date(row.created_at).toLocaleDateString(locale)}
                </dd>
              </div>
              <div className="min-w-0">
                <dt className="font-medium">{t('organizer.table.versions', 'Versions')}</dt>
                <dd>{row.artifacts.length}</dd>
              </div>
            </dl>
          </li>
        ))}
      </ul>

      {pagination && (
        <Pagination
          className="mt-4"
          page={pagination.page}
          perPage={pagination.perPage}
          total={pagination.total}
          onPageChange={onPageChange}
        />
      )}
    </div>
  );
}
