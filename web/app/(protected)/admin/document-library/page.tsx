'use client';

import { useCallback, useMemo, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { fetchAllPages } from '@/lib/api/fetch-all-pages';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { IconFilePlus } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useDateDisplay } from '@amroksaleh/features/datetime';
import { useRouter } from 'next/navigation';
import { useCapabilities } from '@/hooks/useCapabilities';
import { DOCUMENTS_RENDER, DOCUMENTS_ROUTE } from '@/lib/capabilities';
import {
  CreateDocumentDialog,
  type CreatableTemplate,
} from '@/components/documents/create-document-dialog';
import { ViewRail, viewLabel, viewDescription } from './view-rail';
import { LibraryToolbar } from './library-toolbar';
import { DocumentGrid } from './document-grid';
import {
  DocumentActions,
  DocumentTitle,
  StarButton,
  type DocumentItemHandlers,
} from './document-item';
import {
  CreateCollectionDialog,
  DeleteCollectionDialog,
  FileIntoCollectionDialog,
  RenameCollectionDialog,
} from './collection-dialogs';
import { useLibraryEmptyState } from './library-empty-state';
import type {
  AppliedSort,
  DocumentCollection,
  DocumentListResponse,
  DocumentRow,
  DocumentSortField,
  DocumentView,
  DocumentViewsResponse,
  LibraryLayout,
  SortDirection,
} from './types';

/**
 * The document library (#947 item 5) — a Drive-shaped browser over documents
 * that stores no folder tree.
 *
 * WHY THERE IS NO TREE ON THIS SCREEN
 * -----------------------------------
 * A document raised centrally and needed by fifteen units has no single home.
 * Store a tree and you must duplicate the row, file it once and make it
 * undiscoverable from the other fourteen, or add shortcuts — the same admission
 * written twice — and then maintain the whole thing as the organisation changes.
 * So every folder in the rail is a QUERY the server names, and the only stored
 * thing is the user's own filing: collections, which claim nothing about where a
 * document lives.
 *
 * A FOLDER IS NOT A DIRECTORY, AND THIS SCREEN MUST NOT IMPLY OTHERWISE
 * ---------------------------------------------------------------------
 * The browser shape this change adds — a list/grid switch, a sort, per-row
 * filing — is borrowed from a file manager, and a file manager's central claim
 * is CONTAINMENT: a file is in exactly one place, and moving it out of one
 * place puts it in another. Nothing here works that way, and every control was
 * chosen so that it cannot be read as if it did.
 *
 *  - One document appears in SEVERAL folders at once. "Awaiting me", "Passed
 *    through my unit" and "All documents" can all list the same row, truthfully.
 *  - There is no move, no cut/paste, and no drag between rail entries. The only
 *    two writes on a row are the star and "Add to collection…", and both are
 *    ADDITIVE — the wording is "add to", never "move to", and every dialog says
 *    out loud that filing changes nothing about where the document lives or who
 *    else can see it.
 *  - "Remove from this collection" is offered only INSIDE a collection, where
 *    the thing being removed from is a list somebody wrote. It is never offered
 *    in a derived folder, because there is nothing there to remove a document
 *    from: a person who "removed" something from "Awaiting me" would expect that
 *    to mean they had dealt with it, and it cannot mean that. What empties that
 *    folder is acting on the document, on the record page.
 *  - There are no breadcrumbs, deliberately, and this is the control most
 *    obviously suggested by the words "browse like a file manager". A breadcrumb
 *    asserts a PATH — that this folder is inside that one, and that the document
 *    is inside this one. Both halves are false here: the rail is flat, and the
 *    folders overlap rather than nest. The rail's own selected entry plus the
 *    sentence under it says which query is running, which is the true version of
 *    what a breadcrumb would have said falsely.
 *
 * THE RAIL IS SERVER-DRIVEN, AND THAT IS THE POINT
 * ------------------------------------------------
 * This screen does not know which folders exist. It renders what
 * `GET /api/v1/documents/views` returns, which is the folders this installation
 * can actually COMPUTE. The three routing-derived folders proved it: they were
 * registered server-side in #995 and appeared here with no change to this file.
 * A hardcoded rail would have needed editing, and would have shipped three empty
 * folders in the meantime — an empty "Awaiting me" states "nothing awaits you",
 * which is false, unfalsifiable from the outside, and indistinguishable from
 * having nothing to do.
 *
 * THE FOUR WAYS THIS SCREEN SHOWS NOTHING, AND WHY THEY LOOK DIFFERENT
 * -------------------------------------------------------------------
 * #987 built a three-way distinction into the rail and it is undone in one line
 * by a tidy empty state. Held apart here as:
 *
 *  1. A folder whose facts this installation does not record is ABSENT — the
 *     server never sends it, the rail renders nothing for it, and the footnote
 *     names the missing fact source in prose that is not clickable.
 *  2. A folder this caller cannot ANCHOR is listed, DISABLED, carrying the
 *     server's own reason (422 on request, surfaced verbatim below).
 *  3. A folder that matched nothing is an ordinary empty state — and
 *     {@link libraryEmptyState} splits that further, because "the folder is
 *     empty", "your search matched nothing" and "what you filed is no longer
 *     readable" are three different facts.
 *  4. A control the caller cannot USE is disabled with its reason, never hidden
 *     (#951): renaming the built-in starred collection, reversing the default
 *     order, filing when the collection list could not be read.
 *
 * WHAT IS NOT REUSED, AND WHY
 * ---------------------------
 * The `dataTable` and `ouScopePicker` BLOCK TYPES exist in the SDK's
 * BlockContract, and they are for plugin-declared screens: a plugin ships no
 * JavaScript and describes its UI as data for the block renderer to interpret.
 * This is a core admin page, so it composes the same underlying components
 * directly — `DataTable` in server-pagination mode, the OU list — exactly as the
 * roles, users and tag-group pages do.
 */

/** The tenant's own units, for the anchor selector. */
interface OuOption {
  id: number;
  name: string;
  parent_id: number | null;
}

const DEFAULT_VIEW = 'all';

/**
 * Where the layout choice is remembered.
 *
 * `wc:<screen>:<thing>`, the convention the units and relations screens already
 * use. localStorage rather than a profile column because core has no per-user
 * preference store, and a migration plus an endpoint to remember a two-state
 * toggle would be a schema change to record something nobody misses on a new
 * machine. The consequence is stated rather than hidden: this follows the
 * BROWSER, not the account.
 */
const LAYOUT_STORAGE_KEY = 'wc:document-library:layout';

function storedLayout(): LibraryLayout {
  if (typeof window === 'undefined') {
    return 'list';
  }
  // A try/catch because a browser with site data blocked THROWS on access
  // rather than returning null, and a library that fails to render because it
  // could not read a cosmetic preference is worse than one that opens as a list.
  try {
    return window.localStorage.getItem(LAYOUT_STORAGE_KEY) === 'grid' ? 'grid' : 'list';
  } catch {
    return 'list';
  }
}

export default function DocumentLibraryPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('documents');
  const dates = useDateDisplay();
  const router = useRouter();
  // UI hints only — the server is authoritative on both. `has()` fails CLOSED, so
  // a payload it could not parse hides the New button rather than dangling an
  // affordance that 403s on submit.
  const { has: hasCapability } = useCapabilities();

  const [viewKey, setViewKey] = useState(DEFAULT_VIEW);
  const [collectionId, setCollectionId] = useState<number | null>(null);
  const [anchorOuId, setAnchorOuId] = useState<number | null>(null);
  const [page, setPage] = useState(1);
  // Two pieces of state for one box: `search` is what is typed, `appliedSearch`
  // is what has been sent. Refetching per keystroke would issue a request per
  // character against a table that can be large, and debouncing here would make
  // the URL and the results disagree for a few hundred milliseconds.
  const [search, setSearch] = useState('');
  const [appliedSearch, setAppliedSearch] = useState('');

  // Sort. `sortDirection` is null until the caller overrides it, because the
  // DEFAULT direction belongs to the server: A→Z for the text columns,
  // newest-first for the date. Holding a client-side default here would make one
  // of the three columns open the wrong way round, and holding the server's rule
  // in two places would let them drift.
  const [sortField, setSortField] = useState<DocumentSortField | null>(null);
  const [sortDirection, setSortDirection] = useState<SortDirection | null>(null);

  const [layout, setLayout] = useState<LibraryLayout>(storedLayout);

  const [creatingDocument, setCreatingDocument] = useState(false);
  const [creatingCollection, setCreatingCollection] = useState(false);
  const [newCollectionName, setNewCollectionName] = useState('');
  const [renamingCollection, setRenamingCollection] = useState<DocumentCollection | null>(null);
  const [renameName, setRenameName] = useState('');
  const [deletingCollection, setDeletingCollection] = useState<DocumentCollection | null>(null);
  const [filingDocument, setFilingDocument] = useState<DocumentRow | null>(null);
  const [busy, setBusy] = useState(false);

  // ── the rail ────────────────────────────────────────────────────────────

  const views = useFetch<DocumentViewsResponse>(async () => {
    const response = await apiClient('/api/v1/documents/views');
    if (!response.ok) {
      throw new Error(t('organizer.error.views', 'Failed to load the document folders'));
    }
    return (await response.json()) as DocumentViewsResponse;
  }, [apiClient]);

  const collections = useFetch<DocumentCollection[]>(async () => {
    const response = await apiClient('/api/v1/document-collections');
    if (!response.ok) {
      throw new Error(t('organizer.error.collections', 'Failed to load your collections'));
    }
    const body = (await response.json()) as { data?: DocumentCollection[] };
    return body.data ?? [];
  }, [apiClient]);

  /**
   * The templates the caller may raise a document from.
   *
   * SERVER-FILTERED, and this screen deliberately does not filter again. The
   * list route applies `DocumentAccessPolicy` row by row, so a template gated
   * behind a permission tag or filed at a unit the caller has no standing in is
   * ABSENT from the payload — which is also why a second, client-side copy of
   * that rule would be a liability rather than defence in depth: it could only
   * ever be wrong in one direction, and it would be the visible one.
   *
   * A partial walk is treated as a FAILURE rather than presented as the whole
   * library, for the reason the unit list below already gives: a picker missing
   * templates silently tells somebody they cannot raise the document they came
   * to raise.
   */
  const templates = useFetch<CreatableTemplate[]>(async () => {
    const result = await fetchAllPages<{
      id: number;
      name: string;
      data?: { placeholders?: unknown };
    }>(apiClient, '/api/v1/document-templates');
    if (!result.complete) {
      throw new Error(t('organizer.error.templates', 'Failed to load the full template list'));
    }
    return result.items.map((row) => ({
      id: row.id,
      name: row.name,
      // `data` is verbatim client JSON, so it is narrowed here rather than
      // trusted. A template whose placeholders are unreadable offers no fields
      // instead of crashing the picker — it can still be raised from.
      placeholders: Array.isArray(row.data?.placeholders)
        ? row.data.placeholders.flatMap((p) => {
            if (typeof p !== 'object' || p === null) return [];
            const candidate = p as { key?: unknown; label?: unknown; sample?: unknown };
            if (typeof candidate.key !== 'string' || candidate.key === '') return [];
            return [
              {
                key: candidate.key,
                label: typeof candidate.label === 'string' ? candidate.label : candidate.key,
                sample: typeof candidate.sample === 'string' ? candidate.sample : '',
              },
            ];
          })
        : [],
    }));
  }, [apiClient]);

  const ous = useFetch<OuOption[]>(async () => {
    // A partial walk is reported rather than presented as the whole tree: an
    // anchor list missing units silently scopes somebody's folder to less than
    // they asked for.
    const result = await fetchAllPages<OuOption>(apiClient, '/api/v1/ous');
    if (!result.complete) {
      throw new Error(t('organizer.error.ous', 'Failed to load the full unit list'));
    }
    return result.items;
  }, [apiClient]);

  const selectedView: DocumentView | null = useMemo(() => {
    const all = views.data?.data ?? [];
    return all.find((v) => v.key === viewKey) ?? null;
  }, [views.data, viewKey]);

  // Memoised so the `?? []` does not mint a new array every render and
  // invalidate the two useMemos below (which the lint rule catches, and which
  // would recompute the rail's props on every keystroke in the search box).
  const collectionList = useMemo(() => collections.data ?? [], [collections.data]);

  const starredCollection = useMemo(
    () => collectionList.find((c) => c.system_key === 'starred') ?? null,
    [collectionList]
  );

  const openCollection = useMemo(
    () => collectionList.find((c) => c.id === collectionId) ?? null,
    [collectionList, collectionId]
  );

  // ── the page of documents ───────────────────────────────────────────────

  const documents = useFetch<DocumentListResponse>(async () => {
    const params = new URLSearchParams({ view: viewKey, page: String(page) });
    if (anchorOuId !== null) params.set('ou_id', String(anchorOuId));
    if (collectionId !== null) params.set('collection_id', String(collectionId));
    if (appliedSearch !== '') params.set('q', appliedSearch);
    if (sortField !== null) {
      params.set('sort', sortField);
      // Sent only when the caller overrode it. The server refuses `direction`
      // without `sort` for a reason worth honouring here rather than working
      // around: the unnamed default order is a surrogate key, and reversing it
      // would publish that key as a sortable column.
      if (sortDirection !== null) params.set('direction', sortDirection);
    }

    const response = await apiClient(`/api/v1/documents?${params.toString()}`);
    if (!response.ok) {
      // A 422 carries the server's own reason — "you do not belong to an
      // organizational unit" — and surfacing it verbatim is the point: a
      // generic "failed to load" would put this screen back in the position
      // #951 describes, where three unrelated causes read the same.
      const body = (await response.json().catch(() => null)) as { error?: string } | null;
      throw new Error(body?.error ?? t('organizer.error.list', 'Failed to load documents'));
    }
    return (await response.json()) as DocumentListResponse;
  }, [apiClient, viewKey, collectionId, anchorOuId, page, appliedSearch, sortField, sortDirection]);

  // `refetch` is stable (useFetch memoises it with no deps); the fetch RESULT
  // object is not, so the mutations below depend on these rather than on
  // `documents`/`collections` and are not rebuilt on every render.
  const refetchDocuments = documents.refetch;
  const refetchCollections = collections.refetch;

  // Every navigation resets the page in the SAME event that changes the folder,
  // rather than in an effect reacting to it. Page 4 of one folder is rarely page
  // 4 of the next, and a stale page number renders an empty table that reads as
  // an empty folder — but doing it in an effect means a render with the new
  // folder and the old page, so the wrong page is fetched and then thrown away.
  const selectView = useCallback((key: string) => {
    setViewKey(key);
    setCollectionId(null);
    setPage(1);
  }, []);

  const selectCollection = useCallback((id: number) => {
    setViewKey('collection');
    setCollectionId(id);
    setPage(1);
  }, []);

  const selectAnchor = useCallback((ouId: number | null) => {
    setAnchorOuId(ouId);
    setPage(1);
  }, []);

  const applySearch = useCallback((term: string) => {
    setAppliedSearch(term);
    setPage(1);
  }, []);

  const changeSortField = useCallback((field: DocumentSortField | null) => {
    setSortField(field);
    // Cleared, not carried over: `desc` means newest-first on a date and Z→A on
    // a title, so keeping the previous direction across a field change hands the
    // caller an order they did not ask for. Null lets the server apply the
    // default for the new field, and the response says which it chose.
    setSortDirection(null);
    setPage(1);
  }, []);

  const chooseLayout = useCallback((next: LibraryLayout) => {
    setLayout(next);
    try {
      window.localStorage.setItem(LAYOUT_STORAGE_KEY, next);
    } catch {
      // Site data blocked. The choice still applies for this visit; only
      // remembering it fails, and refusing to switch layout would be a worse
      // answer to a storage problem.
    }
  }, []);

  // The order the server APPLIED, which is what the direction control reflects.
  // Falling back to the requested field with `desc` covers the first render only.
  const appliedSort: AppliedSort = documents.data?.sort ?? {
    field: sortField,
    direction: sortDirection ?? 'desc',
  };

  const toggleSortDirection = useCallback(() => {
    setSortDirection(appliedSort.direction === 'asc' ? 'desc' : 'asc');
    setPage(1);
  }, [appliedSort.direction]);

  // ── mutations ───────────────────────────────────────────────────────────

  /**
   * One place for every write on this screen.
   *
   * Each of them is: disable the controls, call, surface the server's own
   * sentence on failure, refresh what the change can be seen in. Written once
   * because six copies of that shape is six chances for one of them to swallow
   * an error or to forget that a filing change also changes a rail count.
   */
  const mutate = useCallback(
    async (
      path: string,
      method: 'PUT' | 'POST' | 'PATCH' | 'DELETE',
      fallbackMessage: string,
      body?: unknown
    ): Promise<boolean> => {
      setBusy(true);
      try {
        const response = await apiClient(path, {
          method,
          ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        });
        if (!response.ok) {
          const failure = (await response.json().catch(() => null)) as { error?: string } | null;
          throw new Error(failure?.error ?? fallbackMessage);
        }
        refetchDocuments();
        // The rail shows a count and the starred collection may have just come
        // into existence — it is created on first star, not seeded.
        refetchCollections();
        return true;
      } catch (error) {
        addToast(error instanceof Error ? error.message : String(error), 'error');
        return false;
      } finally {
        setBusy(false);
      }
    },
    [apiClient, addToast, refetchDocuments, refetchCollections]
  );

  const toggleStar = useCallback(
    (row: DocumentRow) => {
      void mutate(
        `/api/v1/documents/${row.id}/star`,
        row.starred ? 'DELETE' : 'PUT',
        t('organizer.error.star', 'Failed to update the star')
      );
    },
    [mutate, t]
  );

  const createCollection = useCallback(async () => {
    const created = await mutate(
      '/api/v1/document-collections',
      'POST',
      t('organizer.error.createCollection', 'Failed to create the collection'),
      { name: newCollectionName }
    );
    if (created) {
      setCreatingCollection(false);
      setNewCollectionName('');
    }
  }, [mutate, newCollectionName, t]);

  const renameCollection = useCallback(async () => {
    if (renamingCollection === null) return;
    const renamed = await mutate(
      `/api/v1/document-collections/${renamingCollection.id}`,
      'PATCH',
      t('organizer.error.renameCollection', 'Failed to rename the collection'),
      { name: renameName }
    );
    if (renamed) {
      setRenamingCollection(null);
    }
  }, [mutate, renamingCollection, renameName, t]);

  const deleteCollection = useCallback(async () => {
    if (deletingCollection === null) return;
    const deleted = await mutate(
      `/api/v1/document-collections/${deletingCollection.id}`,
      'DELETE',
      t('organizer.error.deleteCollection', 'Failed to delete the collection')
    );
    if (deleted) {
      // The open folder just stopped existing. Navigating away is the honest
      // move: leaving `collection_id` pointing at a deleted row would answer
      // 404, which reads as "you cannot open collections".
      if (collectionId === deletingCollection.id) {
        selectView(DEFAULT_VIEW);
      }
      setDeletingCollection(null);
    }
  }, [mutate, collectionId, deletingCollection, selectView, t]);

  const toggleFiling = useCallback(
    (targetCollectionId: number, next: boolean) => {
      if (filingDocument === null) return;
      void mutate(
        `/api/v1/document-collections/${targetCollectionId}/documents/${filingDocument.id}`,
        next ? 'PUT' : 'DELETE',
        t('organizer.error.file', 'Failed to change what this document is filed in')
      );
    },
    [mutate, filingDocument, t]
  );

  const removeFromOpenCollection = useCallback(
    (row: DocumentRow) => {
      if (collectionId === null) return;
      void mutate(
        `/api/v1/document-collections/${collectionId}/documents/${row.id}`,
        'DELETE',
        t('organizer.error.unfile', 'Failed to remove the document from this collection')
      );
    },
    [mutate, collectionId, t]
  );

  // ── rows ────────────────────────────────────────────────────────────────

  const ouName = useCallback(
    (ouId: number | null) => {
      if (ouId === null) {
        // Not "unknown": migration 108 records the absence of a unit as an
        // absence, and a raiser who belongs to none genuinely raised it from
        // nowhere.
        return t('organizer.table.noUnit', 'No unit');
      }
      // A bare `#4` rather than a guess when the unit list could not be read —
      // and the notice below says why, so an id in this column is never mistaken
      // for a unit whose name is missing. Listing units needs `ous:read`, which
      // migration 101 removed from the plain user role, so this is an ordinary
      // state rather than a fault.
      return (ous.data ?? []).find((ou) => ou.id === ouId)?.name ?? `#${ouId}`;
    },
    [ous.data, t]
  );

  // Filing needs the collection list — to show what is already true, and to
  // offer somewhere to file. When it could not be read the control is disabled
  // carrying that sentence rather than hidden (#951).
  const filingDisabledReason = collections.error
    ? t('organizer.error.collections', 'Failed to load your collections')
    : null;

  const itemHandlers: DocumentItemHandlers = useMemo(
    () => ({
      busy,
      filingDisabledReason,
      onToggleStar: toggleStar,
      onFileInto: setFilingDocument,
      // Offered ONLY inside an ordinary collection. In the starred folder the
      // star on the row already removes it, and two controls for one effect can
      // disagree on screen.
      onRemoveFromOpenCollection: collectionId !== null ? removeFromOpenCollection : undefined,
    }),
    [busy, filingDisabledReason, toggleStar, collectionId, removeFromOpenCollection]
  );

  const columns: DataTableColumn<DocumentRow>[] = useMemo(
    () => [
      {
        id: 'title',
        header: t('organizer.table.title', 'Title'),
        // No `enableSorting`. The shared table sorts CLIENT-side, which in
        // server-pagination mode would sort the twenty five rows it was handed
        // and present the result as a sorted library. Sorting is the toolbar's,
        // and it is the server's — see LibraryToolbar.
        cell: (row) => (
          <div className="flex min-w-0 items-center gap-2">
            <StarButton row={row} busy={busy} onToggleStar={toggleStar} />
            <DocumentTitle row={row} />
          </div>
        ),
      },
      {
        id: 'template_name',
        header: t('organizer.table.template', 'Template'),
        cell: (row) => (
          <span dir="auto" className="truncate">
            {row.template_name}
          </span>
        ),
      },
      {
        id: 'origin_ou_id',
        header: t('organizer.table.raisedFrom', 'Raised from'),
        cell: (row) => (
          <span dir="auto">{ouName(row.origin_ou_id)}</span>
        ),
      },
      // #1068: the "Created" column goes when this tenant hides dates. The
      // organizer is a file manager — title, template, unit and version count
      // are what a reader browses by — and the sort menu still offers "Date
      // created", which orders the rows without printing the value.
      ...dates.dateColumns<DocumentRow>([
        {
          id: 'created_at',
          header: t('organizer.table.created', 'Created'),
          value: (row) => row.created_at,
        },
      ]),
      {
        id: 'artifacts',
        header: t('organizer.table.versions', 'Versions'),
        // More than one artifact means the document has been re-rendered, and
        // every earlier one is still fetchable at its own URL (#947 item 1).
        cell: (row) => String(row.artifacts.length),
      },
    ],
    [busy, dates, ouName, t, toggleStar]
  );

  const listError = documents.error;
  const rows = documents.data?.data ?? [];
  const pagination = documents.data?.pagination ?? null;

  const emptyState = useLibraryEmptyState({
    viewKey,
    collection: openCollection,
    starredCollectionExists: starredCollection !== null,
    searchApplied: appliedSearch !== '',
    total: pagination?.total ?? 0,
  });

  const tableLabel = t('organizer.table.label', 'Documents');

  return (
    <div>
      <AdminHeader
        title={t('organizer.title', 'Documents')}
        description={t(
          'organizer.description',
          'Every folder here is a query over what documents record — nothing stores where a document lives. Collections are your own.'
        )}
        action={
          // Gated on `documents:render`, which is the capability the create
          // route itself requires — migration 113 already settled that a role
          // holding it "is precisely a role that can bring a document into
          // existence", and no new slug was minted for this. Hidden rather than
          // disabled: a person who may not raise documents has nothing to act
          // on here, which is the case #951 leaves to hiding (a DISABLED control
          // with a reason is for a capability the viewer could obtain — an
          // anchor they lack, a switch an operator can flip).
          hasCapability(DOCUMENTS_RENDER) ? (
            <Button onClick={() => setCreatingDocument(true)}>
              <IconFilePlus className="me-2 size-4" aria-hidden />
              {t('organizer.new', 'New document')}
            </Button>
          ) : undefined
        }
      />

      <CreateDocumentDialog
        open={creatingDocument}
        onOpenChange={setCreatingDocument}
        templates={templates.data ?? []}
        templatesLoading={templates.loading}
        templatesError={templates.error}
        canRoute={hasCapability(DOCUMENTS_ROUTE)}
        onSend={(documentId) => router.push(`/admin/document-routing/${documentId}`)}
        onCreated={() => {
          // The listing is refetched immediately, not on close: the document is
          // real the moment the server answered, and a browser that still shows
          // the old page while the dialog reports success is a browser the
          // author has to be told to reload.
          refetchDocuments();
        }}
      />

      {views.error && (
        <p className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
          {views.error}
        </p>
      )}

      <div className="flex gap-8">
        <ViewRail
          views={views.data?.data ?? []}
          collections={collectionList}
          unavailableSubstrates={views.data?.unavailable_substrates ?? []}
          selectedViewKey={viewKey}
          selectedCollectionId={collectionId}
          onSelectView={selectView}
          onSelectCollection={selectCollection}
          onCreateCollection={() => setCreatingCollection(true)}
          createDisabledReason={filingDisabledReason}
        />

        <div className="min-w-0 flex-1">
          {/* The browser's chrome is offered only when there ARE folders.
              Found by opening this page as a user without `documents:read`: the
              rail came back empty behind "Failed to load the document folders",
              the list answered "Insufficient permissions" — and between the two
              sentences sat a working Search box, a Sort selector and a
              list/grid switch, over nothing. Every one of them fires a request
              that 403s again, so the screen offered three ways to re-fail while
              telling the reader they were not allowed.

              That is the inverse of the rule the rest of this screen follows
              (#951): a control the caller cannot use is DISABLED CARRYING ITS
              REASON, and one that has nothing to act on is not rendered. These
              have nothing to act on — the reason is already stated, once, in the
              banner above, and repeating it on four controls would be noise.

              Keyed on `views.error` and NOT on the list failing, which is the
              distinction that matters: a 422 from an unanchorable folder ALSO
              empties the list, and the anchor selector inside this toolbar is
              the control that fixes it. Hiding the chrome whenever the list was
              empty would remove the remedy along with the symptom. */}
          {views.error === null && (
            <LibraryToolbar
            search={search}
            onSearchChange={setSearch}
            onSearchSubmit={() => applySearch(search.trim())}
            appliedSearch={appliedSearch}
            onSearchClear={() => {
              setSearch('');
              applySearch('');
            }}
            anchorOuId={anchorOuId}
            onAnchorChange={selectAnchor}
            ous={ous.data ?? []}
            selectedView={selectedView}
            sortField={sortField}
            sortDirection={appliedSort.direction}
            onSortFieldChange={changeSortField}
            onSortDirectionToggle={toggleSortDirection}
            layout={layout}
            onLayoutChange={chooseLayout}
            openCollection={openCollection}
            starredFolderOpen={viewKey === 'starred'}
            onRenameCollection={() => {
              if (openCollection === null) return;
              setRenameName(openCollection.name);
              setRenamingCollection(openCollection);
            }}
            onDeleteCollection={() => setDeletingCollection(openCollection)}
            collectionsUnavailableReason={filingDisabledReason}
            />
          )}

          {ous.error && (
            <p className="mb-3 text-sm text-muted-foreground">
              {t(
                'organizer.ous.unavailable',
                'Unit names could not be loaded, so units are shown by id. Listing units needs the ous:read permission.'
              )}
            </p>
          )}

          {selectedView && (
            <p className="mb-3 text-sm text-muted-foreground">
              <span dir="auto">
                {openCollection !== null ? openCollection.name : viewLabel(t, selectedView)}
              </span>
              {' — '}
              {viewDescription(t, selectedView)}
            </p>
          )}

          {listError ? (
            // The server's own sentence, not a generic failure: for a folder the
            // caller cannot anchor this is the explanation, and replacing it
            // with "failed to load" is exactly the loss #951 is about.
            <p className="rounded-md border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
              {listError}
            </p>
          ) : layout === 'grid' ? (
            <DocumentGrid
              rows={rows}
              isLoading={documents.loading}
              emptyState={emptyState}
              pagination={pagination}
              onPageChange={setPage}
              handlers={itemHandlers}
              ouName={ouName}
              ariaLabel={tableLabel}
            />
          ) : (
            <DataTable<DocumentRow>
              columns={columns}
              data={rows}
              getRowId={(row) => String(row.id)}
              isLoading={documents.loading}
              ariaLabel={tableLabel}
              rowActions={(row) => <DocumentActions row={row} handlers={itemHandlers} />}
              emptyState={{
                title: emptyState.title,
                description: emptyState.description,
                action: emptyState.pastTheEnd ? (
                  <Button variant="outline" size="sm" onClick={() => setPage(1)}>
                    {t('organizer.empty.pastEndAction', 'Go to the first page')}
                  </Button>
                ) : undefined,
              }}
              // Server-driven: the API already paginated, so the table renders
              // controls and calls back rather than re-slicing a page it was
              // handed. Client-side pagination here would page within one page.
              pagination={
                pagination
                  ? {
                      pageIndex: pagination.page - 1,
                      pageSize: pagination.perPage,
                      pageCount: pagination.totalPages,
                      total: pagination.total,
                      onPaginationChange: (pageIndex) => setPage(pageIndex + 1),
                    }
                  : undefined
              }
            />
          )}
        </div>
      </div>

      <CreateCollectionDialog
        open={creatingCollection}
        onOpenChange={setCreatingCollection}
        name={newCollectionName}
        onNameChange={setNewCollectionName}
        busy={busy}
        onSubmit={() => void createCollection()}
      />

      <RenameCollectionDialog
        collection={renamingCollection}
        onClose={() => setRenamingCollection(null)}
        name={renameName}
        onNameChange={setRenameName}
        busy={busy}
        onSubmit={() => void renameCollection()}
      />

      <DeleteCollectionDialog
        collection={deletingCollection}
        onClose={() => setDeletingCollection(null)}
        busy={busy}
        onSubmit={() => void deleteCollection()}
      />

      <FileIntoCollectionDialog
        // Re-read from the freshly fetched page rather than held: filing writes
        // and then refetches, so the row in state is the state BEFORE the write
        // and its checkboxes would not move.
        documentRow={
          filingDocument === null
            ? null
            : (rows.find((row) => row.id === filingDocument.id) ?? filingDocument)
        }
        onClose={() => setFilingDocument(null)}
        collections={collectionList}
        busy={busy}
        onToggle={toggleFiling}
        onCreateNew={() => setCreatingCollection(true)}
      />
    </div>
  );
}
