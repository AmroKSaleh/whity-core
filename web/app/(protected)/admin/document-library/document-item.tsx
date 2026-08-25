'use client';

import Link from 'next/link';
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
 * It is also the single place the document RECORD PAGE link lands. When this
 * file was written the title still opened the rendered artifact (`content_url`)
 * and the record page was being built in parallel; #993 landed it first, so the
 * link below is the record page and the artifact has moved into the row menu.
 * One component carried that change, not two.
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
 * The document's name, linking to its RECORD.
 *
 * WHY THE RECORD AND NOT THE FILE
 * -------------------------------
 * #993 settled this and this component follows it rather than re-deciding it:
 * the title used to be a new-tab link to `content_url` — the CURRENT artifact —
 * which answers "let me read it" and nothing else. No version history, no trail,
 * no way back, and a superseded document indistinguishable from a current one,
 * because the raw file carries none of that. The record page is the surface that
 * says which version this is and what has happened to it, and the file is one
 * click away from there (and from this row's own menu).
 *
 * A real `<Link>`, not an `onClick`: middle-click, ctrl-click and "copy link
 * address" are what make a row with an ADDRESS different from a row that opens a
 * modal. Both layouts get that, which is the point of it living here.
 *
 * `dir="auto"` because a title is TENANT DATA in an unknown script: an Arabic
 * title inside an English interface (or the reverse) renders its punctuation and
 * any embedded digits at the wrong end without a bidi isolate, and the `dir`
 * attribute is what creates one. `truncate` needs `min-w-0` on the flex parent,
 * which is why the layouts below supply it.
 *
 * The `data-testid` is #993's and is kept BYTE FOR BYTE: the record-page e2e spec
 * reaches the record through `document-row-{id}`, so renaming it here would break
 * a spec in another file that has nothing to do with this change. It is on the
 * link in both layouts, which is what lets one selector work in either.
 */
export function DocumentTitle({ row }: { row: DocumentRow }) {
  return (
    <Link
      href={`/admin/document-library/${row.id}`}
      dir="auto"
      title={row.title}
      data-testid={`document-row-${row.id}`}
      className="truncate font-medium text-primary hover:underline"
    >
      {row.title}
    </Link>
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

        {/* Disabled with the reason rather than absent when nothing has been
            rendered yet (#951). "There is no file" and "this build forgot to
            offer one" are otherwise the same pixels, and a record legitimately
            exists before anything is rendered from it — this is an ordinary
            state, not a fault. */}
        {row.content_url ? (
          <DropdownMenuItem asChild>
            <a href={row.content_url} target="_blank" rel="noreferrer">
              <IconDownload size={14} className="me-2" aria-hidden />
              {t('organizer.table.open', 'Open the rendered file')}
            </a>
          </DropdownMenuItem>
        ) : (
          <DropdownMenuItem disabled>
            <IconDownload size={14} className="me-2" aria-hidden />
            {`${t('organizer.table.open', 'Open the rendered file')} — ${t(
              'organizer.table.noContent',
              'Nothing has been rendered from this document yet'
            )}`}
          </DropdownMenuItem>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
