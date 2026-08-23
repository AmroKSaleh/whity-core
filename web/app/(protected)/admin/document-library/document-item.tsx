'use client';

import { useTranslation } from '@amroksaleh/features/i18n';
import { Button } from '@amroksaleh/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@amroksaleh/ui/dropdown-menu';
import {
  IconDots,
  IconDownload,
  IconFolderPlus,
  IconFolderMinus,
  IconStar,
  IconStarFilled,
} from '@tabler/icons-react';
import type { DocumentRow } from './types';

/**
 * The affordances one document carries, in ONE place so the list and the grid
 * cannot drift.
 *
 * WHY THIS FILE EXISTS RATHER THAN TWO COPIES
 * -------------------------------------------
 * A browser with a list and a grid has two renderings of the same row, and the
 * failure mode is not that they look different — it is that one of them quietly
 * loses an affordance. A star that only appears in the list makes the grid look
 * like a different, poorer screen; worse, a star that appears in both but reads
 * `row.starred` differently in each shows a document as starred in one layout
 * and not the other. So the *semantics* live here and only the arrangement
 * differs between layouts.
 *
 * It is also the single place the document RECORD PAGE link will land. #987
 * links the title to the rendered artifact (`content_url`); the record page is
 * being built in parallel and will want the title instead. One component to
 * change, not two.
 *
 * ABSENCE IS NOT FALSE
 * --------------------
 * `starred` and `collection_ids` are OPTIONAL on `Document` and their absence is
 * meaningful — only the routes that know who is asking compute them (the render
 * route does not). An undefined `starred` rendered as a hollow star would assert
 * "not starred", which is a claim the server did not make. So both controls are
 * DISABLED and say so when the field is absent, rather than defaulting to the
 * comfortable value. On this screen the list route always computes them; the
 * guard is here because the type says it may not, and a component that trusts a
 * field its type marks optional is one bug away from lying.
 */

export interface DocumentItemHandlers {
  onToggleStar: (row: DocumentRow) => void;
  onFileInto: (row: DocumentRow) => void;
  /** Present only while a collection is the open folder — see `DocumentActions`. */
  onRemoveFromOpenCollection?: (row: DocumentRow) => void;
  /** Blocks every mutation while one is in flight. */
  busy: boolean;
  /**
   * Why filing is unavailable, or null when it is available. Carried rather than
   * inferred so the caller's reason (#951) reaches the control that is disabled.
   */
  filingDisabledReason: string | null;
}

/**
 * The star.
 *
 * `aria-pressed` rather than a checkbox role: this is a toggle button whose two
 * states are "starred" and "not starred", and assistive technology announces
 * pressed/not-pressed for exactly that.
 */
export function StarButton({
  row,
  busy,
  onToggleStar,
}: {
  row: DocumentRow;
  busy: boolean;
  onToggleStar: (row: DocumentRow) => void;
}) {
  const t = useTranslation('documents');
  const unknown = row.starred === undefined;

  const label = unknown
    ? t('organizer.table.starUnknown', 'This list did not report whether you starred this document')
    : row.starred
      ? t('organizer.table.unstar', 'Remove star')
      : t('organizer.table.star', 'Star this document');

  return (
    <button
      type="button"
      disabled={busy || unknown}
      onClick={() => onToggleStar(row)}
      aria-pressed={unknown ? undefined : row.starred === true}
      aria-label={label}
      title={label}
      className="text-muted-foreground hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50"
    >
      {row.starred ? <IconStarFilled size={16} /> : <IconStar size={16} />}
    </button>
  );
}

/**
 * The document's name.
 *
 * `dir="auto"` because a title is TENANT DATA in an unknown script: an Arabic
 * title inside an English interface (or the reverse) renders with its
 * punctuation and any embedded digits at the wrong end without a bidi isolate,
 * and the `dir` attribute is what creates one. `truncate` needs `min-w-0` on the
 * flex parent, which is why the layouts below supply it.
 */
export function DocumentTitle({ row }: { row: DocumentRow }) {
  const t = useTranslation('documents');

  if (row.content_url) {
    return (
      <a
        href={row.content_url}
        target="_blank"
        rel="noreferrer"
        dir="auto"
        title={row.title}
        className="truncate font-medium text-primary hover:underline"
      >
        {row.title}
      </a>
    );
  }

  // No artifact to open. Not an error and not a missing file: a record can exist
  // before anything has been rendered from it.
  return (
    <span
      dir="auto"
      title={t('organizer.table.noContent', 'Nothing has been rendered from this document yet')}
      className="truncate font-medium"
    >
      {row.title}
    </span>
  );
}

/**
 * The per-document menu.
 *
 * WHY A MENU PER ROW RATHER THAN MULTI-SELECT
 * -------------------------------------------
 * Bulk add-to-collection was considered and is not here. The shared `DataTable`
 * has no selection model, so it would have to gain one — a checkbox column and a
 * select-all for all fourteen tables that use it — and on this screen the row
 * already has two direct actions (star, open). A checkbox makes the primary
 * click ambiguous: clicking a row would sometimes select and sometimes open.
 *
 * The payoff would also be smaller than it looks. The API files ONE document per
 * request (`PUT /document-collections/{id}/documents/{documentId}`, idempotent),
 * so "bulk" is a client-side loop either way, and a loop that half-fails has to
 * report which documents were filed — per row, in this menu's place. Flagged in
 * the PR rather than silently dropped.
 */
export function DocumentActions({
  row,
  handlers,
}: {
  row: DocumentRow;
  handlers: DocumentItemHandlers;
}) {
  const t = useTranslation('documents');
  const { busy, filingDisabledReason, onFileInto, onRemoveFromOpenCollection } = handlers;

  // The server did not say which collections hold this document, so "file it"
  // cannot show what is already true. Disabled with the reason rather than
  // hidden (#951) — and rather than offered as if the current state were known.
  const filingUnknown = row.collection_ids === undefined;
  const reason = filingUnknown
    ? t('organizer.table.filingUnknown', 'This list did not report your filing, so it cannot be changed here')
    : filingDisabledReason;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="sm" aria-label={t('organizer.table.rowMenu', 'Document actions')}>
          <IconDots size={16} aria-hidden />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem
          disabled={busy || reason !== null}
          // A disabled menu item cannot be hovered in every browser, so the
          // reason is appended to the label instead of hidden in a tooltip.
          onSelect={() => onFileInto(row)}
        >
          <IconFolderPlus size={14} className="me-2" aria-hidden />
          {reason === null
            ? t('organizer.table.fileInto', 'Add to collection…')
            : `${t('organizer.table.fileInto', 'Add to collection…')} — ${reason}`}
        </DropdownMenuItem>

        {onRemoveFromOpenCollection && (
          <DropdownMenuItem disabled={busy} onSelect={() => onRemoveFromOpenCollection(row)}>
            <IconFolderMinus size={14} className="me-2" aria-hidden />
            {t('organizer.table.removeFromCollection', 'Remove from this collection')}
          </DropdownMenuItem>
        )}

        {row.content_url && (
          <DropdownMenuItem asChild>
            <a href={row.content_url} target="_blank" rel="noreferrer">
              <IconDownload size={14} className="me-2" aria-hidden />
              {t('organizer.table.open', 'Open the rendered file')}
            </a>
          </DropdownMenuItem>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
