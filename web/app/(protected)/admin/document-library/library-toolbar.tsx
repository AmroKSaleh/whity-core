'use client';

import { useTranslation } from '@amroksaleh/features/i18n';
import { Button } from '@amroksaleh/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { ViewToggle } from '@amroksaleh/ui/view-toggle';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@amroksaleh/ui/dropdown-menu';
import {
  IconLayoutGrid,
  IconList,
  IconPencil,
  IconSearch,
  IconSortAscending,
  IconSortDescending,
  IconTrash,
  IconX,
} from '@tabler/icons-react';
import { Input } from '@/components/ui/input';
import type {
  DocumentCollection,
  DocumentSortField,
  DocumentView,
  LibraryLayout,
  SortDirection,
} from './types';

/**
 * The browser's chrome: search, the unit anchor, sort, the layout switch, and
 * whatever can be done to the open collection.
 *
 * WHY SORT IS A TOOLBAR CONTROL AND NOT A CLICKABLE COLUMN HEADER
 * ---------------------------------------------------------------
 * Drive sorts by column header, and that was the first thing tried. Two things
 * ruled it out, and the second is the one that decided it.
 *
 *  1. The grid has no headers. A sort that only exists in one of two layouts is
 *     a feature that disappears when you switch view.
 *  2. NO LONGER TRUE — FIXED IN #1129, and recorded here so the next reader
 *     does not re-derive a solved problem. It used to read: the shared
 *     `DataTable`'s sortable header is a `<th onClick>` with no `aria-sort` and
 *     no focusable control inside it — mouse-only. It now renders a `<button>`
 *     inside the `th` and carries `aria-sort` in both client- and server-sorted
 *     modes, so header sorting is reachable by keyboard and announced.
 *
 * Reason 1 still stands on its own, so this toolbar is UNCHANGED: the grid
 * layout has no headers, and a sort that vanishes when you switch view is worse
 * than one that lives in the chrome of both. Wiring the table headers up would
 * now be a real option rather than an accessibility regression — but it is a
 * product decision about two layouts, not the a11y fix, and it is not made here.
 *
 * The column headers are therefore left NON-sortable rather than wired to
 * TanStack's client-side sorter, which would have been the easy mistake: the
 * table is in server-pagination mode, so `getSortedRowModel` sorts the twenty
 * five rows it was handed and presents the result as a sorted library. Page 2
 * would then re-sort a different twenty five. Sorting a page and calling it a
 * sorted list is the same class of untruth as an empty folder that cannot be
 * computed.
 *
 * WHY THE DIRECTION TOGGLE IS DISABLED RATHER THAN HIDDEN ON THE DEFAULT ORDER
 * ---------------------------------------------------------------------------
 * The default is the order documents were RECORDED in, and the API refuses
 * `?direction=` without a `?sort=` (it would publish the surrogate key as a
 * sortable column through the back door). So the control that cannot work says
 * why, instead of vanishing and leaving the reader to wonder whether the screen
 * is broken — #951, applied to the smallest control on the page.
 */
export interface LibraryToolbarProps {
  /** What is typed, which is not what has been sent — see the search form below. */
  search: string;
  onSearchChange: (value: string) => void;
  onSearchSubmit: () => void;
  /** The term currently APPLIED, or '' — shown as a clearable chip. */
  appliedSearch: string;
  onSearchClear: () => void;

  /** Null means "whichever unit the server says is mine". */
  anchorOuId: number | null;
  onAnchorChange: (ouId: number | null) => void;
  ous: { id: number; name: string }[];
  selectedView: DocumentView | null;

  sortField: DocumentSortField | null;
  sortDirection: SortDirection;
  onSortFieldChange: (field: DocumentSortField | null) => void;
  onSortDirectionToggle: () => void;

  layout: LibraryLayout;
  onLayoutChange: (layout: LibraryLayout) => void;

  /**
   * The collection whose folder is open, when one is. `builtIn` is the starred
   * folder: a real collection the API refuses to rename or delete (409), so its
   * controls are DISABLED CARRYING THAT REASON rather than absent.
   */
  openCollection: DocumentCollection | null;
  starredFolderOpen: boolean;
  onRenameCollection: () => void;
  onDeleteCollection: () => void;
  /** Why the collections could not be read, when they could not. */
  collectionsUnavailableReason: string | null;
}

/** The anchor selector's "my own unit" sentinel — Radix reserves '' for "no selection". */
const MINE = '__mine__';
/** The sort selector's "the order they were recorded in" sentinel, for the same reason. */
const RECORDED = '__recorded__';

export function LibraryToolbar({
  search,
  onSearchChange,
  onSearchSubmit,
  appliedSearch,
  onSearchClear,
  anchorOuId,
  onAnchorChange,
  ous,
  selectedView,
  sortField,
  sortDirection,
  onSortFieldChange,
  onSortDirectionToggle,
  layout,
  onLayoutChange,
  openCollection,
  starredFolderOpen,
  onRenameCollection,
  onDeleteCollection,
  collectionsUnavailableReason,
}: LibraryToolbarProps) {
  const t = useTranslation('documents');

  const takesAnchor = selectedView?.parameters.some((p) => p.name === 'ou_id') ?? false;

  const sortLabels: Record<DocumentSortField, string> = {
    title: t('organizer.sort.title', 'Title'),
    created_at: t('organizer.sort.created', 'Date created'),
    template_name: t('organizer.sort.template', 'Template'),
  };

  // A keyed collection cannot be renamed or deleted, and the reason is the
  // API's: the star control addresses it BY KEY, so a client that deleted it
  // would find its own star re-creating a different row.
  const builtInReason = t(
    'organizer.collection.builtIn',
    'Starred is built in. The star control addresses it by name, so it cannot be renamed or removed.'
  );
  const collectionReason = starredFolderOpen
    ? builtInReason
    : (collectionsUnavailableReason ?? null);
  const collectionActionsDisabled = collectionReason !== null;

  return (
    <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div className="flex flex-wrap items-end gap-3">
        {/* Two pieces of state for one box: what is typed and what has been
            sent. Refetching per keystroke issues a request per character
            against a table that can be large; debouncing instead would make the
            results and the box disagree for a few hundred milliseconds. */}
        <form
          className="flex items-end gap-2"
          onSubmit={(event) => {
            event.preventDefault();
            onSearchSubmit();
          }}
        >
          <Input
            label={t('organizer.search.label', 'Search titles')}
            value={search}
            onChange={(event) => onSearchChange(event.target.value)}
            placeholder={t('organizer.search.placeholder', 'Invoice, minutes, …')}
          />
          <Button type="submit" variant="outline" size="sm">
            <IconSearch size={14} className="me-2" aria-hidden />
            {t('organizer.search.submit', 'Search')}
          </Button>
        </form>

        {/* The applied term, shown ONCE, next to the control that clears it —
            and in a dir="auto" chip, because it is text in an unknown script.
            Splicing it into an empty-state sentence instead would put an Arabic
            term inside an English paragraph with no bidi isolate. */}
        {appliedSearch !== '' && (
          <button
            type="button"
            onClick={onSearchClear}
            className="flex h-9 max-w-64 items-center gap-1 rounded-full border border-border bg-muted/40 px-3 text-xs hover:bg-muted"
          >
            <span className="truncate" dir="auto">
              {appliedSearch}
            </span>
            <IconX size={12} aria-hidden />
            <span className="sr-only">{t('organizer.search.clear', 'Clear the search')}</span>
          </button>
        )}

        {takesAnchor && (
          // A <div>, not a <label>: a Radix Select's trigger is a button, and a
          // <label> around a button associates with nothing. The accessible name
          // goes on the trigger itself.
          <div className="flex flex-col gap-1 text-sm">
            <span className="font-medium">{t('organizer.anchor.label', 'Unit')}</span>
            <Select
              value={anchorOuId === null ? MINE : String(anchorOuId)}
              onValueChange={(value) => onAnchorChange(value === MINE ? null : Number(value))}
            >
              <SelectTrigger className="h-9 w-56" aria-label={t('organizer.anchor.label', 'Unit')}>
                <SelectValue placeholder={t('organizer.anchor.mine', 'My own unit')} />
              </SelectTrigger>
              <SelectContent>
                {/* Not "all units": this means "whichever unit the server says
                    is mine", which is a different and more honest default than
                    a client-side guess at one. */}
                <SelectItem value={MINE}>{t('organizer.anchor.mine', 'My own unit')}</SelectItem>
                {ous.map((ou) => (
                  <SelectItem key={ou.id} value={String(ou.id)}>
                    {ou.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}

        <div className="flex flex-col gap-1 text-sm">
          <span className="font-medium">{t('organizer.sort.label', 'Sort by')}</span>
          <div className="flex items-center gap-1">
            <Select
              value={sortField ?? RECORDED}
              onValueChange={(value) =>
                onSortFieldChange(value === RECORDED ? null : (value as DocumentSortField))
              }
            >
              <SelectTrigger className="h-9 w-44" aria-label={t('organizer.sort.label', 'Sort by')}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {/* The default order, named for what it IS. Not "date created":
                    documents issued in one transaction share that timestamp
                    exactly, so the recorded order and the created_at order are
                    different answers and only one of them is stable. */}
                <SelectItem value={RECORDED}>
                  {t('organizer.sort.recorded', 'Newest first')}
                </SelectItem>
                {(Object.keys(sortLabels) as DocumentSortField[]).map((field) => (
                  <SelectItem key={field} value={field}>
                    {sortLabels[field]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button
              variant="outline"
              size="sm"
              disabled={sortField === null}
              onClick={onSortDirectionToggle}
              title={
                sortField === null
                  ? t(
                      'organizer.sort.directionFixed',
                      'The newest-first order has no direction to reverse. Choose a column to sort by first.'
                    )
                  : sortDirection === 'asc'
                    ? t('organizer.sort.ascending', 'Ascending — switch to descending')
                    : t('organizer.sort.descending', 'Descending — switch to ascending')
              }
              aria-label={
                sortDirection === 'asc'
                  ? t('organizer.sort.ascending', 'Ascending — switch to descending')
                  : t('organizer.sort.descending', 'Descending — switch to ascending')
              }
            >
              {sortDirection === 'asc' ? (
                <IconSortAscending size={14} aria-hidden />
              ) : (
                <IconSortDescending size={14} aria-hidden />
              )}
            </Button>
          </div>
        </div>
      </div>

      <div className="flex items-end gap-2">
        {(openCollection !== null || starredFolderOpen) && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="sm">
                {t('organizer.collection.manage', 'This collection')}
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {/* Disabled WITH the reason, appended to the label: a disabled
                  menu item cannot be hovered reliably, so a `title` would hide
                  the explanation the control exists to give. */}
              <DropdownMenuItem disabled={collectionActionsDisabled} onSelect={onRenameCollection}>
                <IconPencil size={14} className="me-2" aria-hidden />
                {t('organizer.collection.rename', 'Rename…')}
                {collectionReason !== null && ` — ${collectionReason}`}
              </DropdownMenuItem>
              <DropdownMenuItem
                disabled={collectionActionsDisabled}
                variant="destructive"
                onSelect={onDeleteCollection}
              >
                <IconTrash size={14} className="me-2" aria-hidden />
                {t('organizer.collection.delete', 'Delete this collection')}
                {collectionReason !== null && ` — ${collectionReason}`}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        )}

        {/* `ViewToggle` from the kit, not a pair of buttons written here.
            Nothing about "pick one of these and show me which is on" is about
            documents, and the first draft of this file had it inline — which is
            exactly how a second screen ends up with a copy that disagrees about
            the accessible name or the pressed state. The labels stay here
            because they are translated, and the kit holds no catalogue. */}
        <ViewToggle<LibraryLayout>
          label={t('organizer.layout.label', 'Layout')}
          value={layout}
          onChange={onLayoutChange}
          options={[
            {
              value: 'list',
              label: t('organizer.layout.list', 'List'),
              icon: <IconList size={16} aria-hidden />,
            },
            {
              value: 'grid',
              label: t('organizer.layout.grid', 'Grid'),
              icon: <IconLayoutGrid size={16} aria-hidden />,
            },
          ]}
        />
      </div>
    </div>
  );
}
