'use client';

import { useCallback, useMemo, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { fetchAllPages } from '@/lib/api/fetch-all-pages';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import { Button } from '@amroksaleh/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { IconStar, IconStarFilled, IconTrash } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { ViewRail, viewLabel } from './view-rail';
import type {
  DocumentCollection,
  DocumentListResponse,
  DocumentRow,
  DocumentView,
  DocumentViewsResponse,
} from './types';

/**
 * The document organizer (#978, implementing #947 item 5) — a Drive-shaped
 * browser over documents that stores no folder tree.
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
 * THE RAIL IS SERVER-DRIVEN, AND THAT IS THE POINT
 * ------------------------------------------------
 * This screen does not know which folders exist. It renders what
 * `GET /api/v1/documents/views` returns, which is the folders this installation
 * can actually COMPUTE. Three of the six #947 item 5 specifies are absent: the
 * routing facts they read exist now that item 3 has landed, but each folder
 * still needs a server-side predicate and registration, so the server omits
 * them and this screen shows nothing in their place. An empty "Awaiting me"
 * would state "nothing awaits you" — false, unfalsifiable from the outside, and
 * indistinguishable from having nothing to do.
 *
 * The consequence worth stating: when those three ARE built, they appear here
 * with no change to this file. A hardcoded rail would have needed editing, and
 * would have shipped three empty folders in the meantime.
 *
 * WHAT IS NOT REUSED, AND WHY
 * ---------------------------
 * The `dataTable` and `ouScopePicker` BLOCK TYPES exist in the SDK's
 * BlockContract, and they are for plugin-declared screens: a plugin ships no
 * JavaScript and describes its UI as data for the block renderer to interpret.
 * This is a core admin page, so it composes the same underlying components
 * directly — `DataTable` in server-pagination mode, the OU list — exactly as the
 * roles, users and tag-group pages do. Declaring a core screen as blocks would
 * route it through a renderer built to interpret a plugin's manifest, for no
 * gain.
 */

/** The tenant's own units, for the anchor selector. */
interface OuOption {
  id: number;
  name: string;
  parent_id: number | null;
}

const DEFAULT_VIEW = 'all';

/**
 * The anchor selector's "my own unit" sentinel.
 *
 * A `Select` cannot carry an empty-string value (Radix reserves it for "no
 * selection"), and the absence of `ou_id` on the wire is a real, meaningful
 * choice — "resolve my unit server-side" — rather than a blank.
 */
const MINE = '__mine__';

export default function DocumentLibraryPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('documents');

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
  const [creatingCollection, setCreatingCollection] = useState(false);
  const [newCollectionName, setNewCollectionName] = useState('');
  const [deletingCollection, setDeletingCollection] = useState<DocumentCollection | null>(null);
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

  const takesAnchor = useMemo(
    () => selectedView?.parameters.some((p) => p.name === 'ou_id') ?? false,
    [selectedView]
  );

  const starredCollection = useMemo(
    () => (collections.data ?? []).find((c) => c.system_key === 'starred') ?? null,
    [collections.data]
  );

  // ── the page of documents ───────────────────────────────────────────────

  const documents = useFetch<DocumentListResponse>(async () => {
    const params = new URLSearchParams({ view: viewKey, page: String(page) });
    if (anchorOuId !== null) params.set('ou_id', String(anchorOuId));
    if (collectionId !== null) params.set('collection_id', String(collectionId));
    if (appliedSearch !== '') params.set('q', appliedSearch);

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
  }, [apiClient, viewKey, collectionId, anchorOuId, page, appliedSearch]);

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

  // ── mutations ───────────────────────────────────────────────────────────

  const toggleStar = useCallback(
    async (row: DocumentRow) => {
      setBusy(true);
      try {
        const response = await apiClient(`/api/v1/documents/${row.id}/star`, {
          method: row.starred ? 'DELETE' : 'PUT',
        });
        if (!response.ok) {
          throw new Error(t('organizer.error.star', 'Failed to update the star'));
        }
        documents.refetch();
        // The rail shows a count, and the starred collection may have just come
        // into existence — it is created on first use.
        collections.refetch();
      } catch (error) {
        addToast(error instanceof Error ? error.message : String(error), 'error');
      } finally {
        setBusy(false);
      }
    },
    [apiClient, addToast, documents, collections, t]
  );

  const createCollection = useCallback(async () => {
    setBusy(true);
    try {
      const response = await apiClient('/api/v1/document-collections', {
        method: 'POST',
        body: JSON.stringify({ name: newCollectionName }),
      });
      if (!response.ok) {
        const body = (await response.json().catch(() => null)) as { error?: string } | null;
        throw new Error(body?.error ?? t('organizer.error.createCollection', 'Failed to create the collection'));
      }
      setCreatingCollection(false);
      setNewCollectionName('');
      collections.refetch();
    } catch (error) {
      addToast(error instanceof Error ? error.message : String(error), 'error');
    } finally {
      setBusy(false);
    }
  }, [apiClient, addToast, collections, newCollectionName, t]);

  const deleteCollection = useCallback(async () => {
    if (deletingCollection === null) return;
    setBusy(true);
    try {
      const response = await apiClient(`/api/v1/document-collections/${deletingCollection.id}`, {
        method: 'DELETE',
      });
      if (!response.ok) {
        const body = (await response.json().catch(() => null)) as { error?: string } | null;
        throw new Error(body?.error ?? t('organizer.error.deleteCollection', 'Failed to delete the collection'));
      }
      if (collectionId === deletingCollection.id) {
        selectView(DEFAULT_VIEW);
      }
      setDeletingCollection(null);
      collections.refetch();
    } catch (error) {
      addToast(error instanceof Error ? error.message : String(error), 'error');
    } finally {
      setBusy(false);
    }
  }, [apiClient, addToast, collections, collectionId, deletingCollection, selectView, t]);

  // ── the table ───────────────────────────────────────────────────────────

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

  const columns: DataTableColumn<DocumentRow>[] = useMemo(
    () => [
      {
        id: 'title',
        header: t('organizer.table.title', 'Title'),
        cell: (row) => (
          <div className="flex items-center gap-2">
            <button
              type="button"
              disabled={busy}
              onClick={() => void toggleStar(row)}
              aria-pressed={row.starred === true}
              aria-label={
                row.starred
                  ? t('organizer.table.unstar', 'Remove star')
                  : t('organizer.table.star', 'Star this document')
              }
              className="text-muted-foreground hover:text-foreground disabled:opacity-50"
            >
              {row.starred ? <IconStarFilled size={16} /> : <IconStar size={16} />}
            </button>
            {row.content_url ? (
              <a
                href={row.content_url}
                target="_blank"
                rel="noreferrer"
                className="font-medium text-primary hover:underline"
              >
                {row.title}
              </a>
            ) : (
              <span className="font-medium">{row.title}</span>
            )}
          </div>
        ),
      },
      {
        id: 'template_name',
        accessorKey: 'template_name',
        header: t('organizer.table.template', 'Template'),
      },
      {
        id: 'origin_ou_id',
        header: t('organizer.table.raisedFrom', 'Raised from'),
        cell: (row) => ouName(row.origin_ou_id),
      },
      {
        id: 'created_at',
        header: t('organizer.table.created', 'Created'),
        cell: (row) => new Date(row.created_at).toLocaleString(),
      },
      {
        id: 'artifacts',
        header: t('organizer.table.versions', 'Versions'),
        // More than one artifact means the document has been re-rendered, and
        // every earlier one is still fetchable at its own URL (#947 item 1).
        cell: (row) => String(row.artifacts.length),
      },
    ],
    [busy, ouName, t, toggleStar]
  );

  const listError = documents.error;
  const rows = documents.data?.data ?? [];
  const pagination = documents.data?.pagination ?? null;

  return (
    <div>
      <AdminHeader
        title={t('organizer.title', 'Documents')}
        description={t(
          'organizer.description',
          'Every folder here is a query over what documents record — nothing stores where a document lives. Collections are your own.'
        )}
      />

      {views.error && (
        <p className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
          {views.error}
        </p>
      )}

      <div className="flex gap-8">
        <ViewRail
          views={views.data?.data ?? []}
          collections={collections.data ?? []}
          unavailableSubstrates={views.data?.unavailable_substrates ?? []}
          selectedViewKey={viewKey}
          selectedCollectionId={collectionId}
          onSelectView={selectView}
          onSelectCollection={selectCollection}
          onCreateCollection={() => setCreatingCollection(true)}
        />

        <div className="min-w-0 flex-1">
          <div className="mb-4 flex flex-wrap items-end gap-3">
            <form
              className="flex items-end gap-2"
              onSubmit={(event) => {
                event.preventDefault();
                applySearch(search.trim());
              }}
            >
              <Input
                label={t('organizer.search.label', 'Search titles')}
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder={t('organizer.search.placeholder', 'Invoice, minutes, …')}
              />
              <Button type="submit" variant="outline" size="sm">
                {t('organizer.search.submit', 'Search')}
              </Button>
            </form>

            {takesAnchor && (
              <div className="flex flex-col gap-1 text-sm">
                <span className="font-medium">{t('organizer.anchor.label', 'Unit')}</span>
                <Select
                  value={anchorOuId === null ? MINE : String(anchorOuId)}
                  onValueChange={(value) => selectAnchor(value === MINE ? null : Number(value))}
                >
                  <SelectTrigger className="h-9 w-56">
                    <SelectValue placeholder={t('organizer.anchor.mine', 'My own unit')} />
                  </SelectTrigger>
                  <SelectContent>
                    {/* Not "all units": this means "whichever unit the server
                        says is mine", which is a different and more honest
                        default than a client-side guess at one. A sentinel
                        string rather than '' because SelectItem treats an empty
                        value as no value. */}
                    <SelectItem value={MINE}>{t('organizer.anchor.mine', 'My own unit')}</SelectItem>
                    {(ous.data ?? []).map((ou) => (
                      <SelectItem key={ou.id} value={String(ou.id)}>
                        {ou.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            )}

            {collectionId !== null && (
              <Button
                variant="ghost"
                size="sm"
                onClick={() => {
                  const target = (collections.data ?? []).find((c) => c.id === collectionId);
                  if (target) setDeletingCollection(target);
                }}
              >
                <IconTrash size={14} className="me-2" aria-hidden />
                {t('organizer.collection.delete', 'Delete this collection')}
              </Button>
            )}
          </div>

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
              {collectionId !== null
                ? ((collections.data ?? []).find((c) => c.id === collectionId)?.name ?? '')
                : viewLabel(t, selectedView)}
              {' — '}
              {selectedView.description}
            </p>
          )}

          {listError ? (
            // The server's own sentence, not a generic failure: for a folder the
            // caller cannot anchor this is the explanation, and replacing it
            // with "failed to load" is exactly the loss #951 is about.
            <p className="rounded-md border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
              {listError}
            </p>
          ) : (
            <DataTable<DocumentRow>
              columns={columns}
              data={rows}
              getRowId={(row) => String(row.id)}
              isLoading={documents.loading}
              ariaLabel={t('organizer.table.label', 'Documents')}
              emptyState={{
                title:
                  viewKey === 'starred' && starredCollection === null
                    ? t('organizer.empty.starredTitle', 'You have not starred anything yet')
                    : t('organizer.empty.title', 'No documents in this folder'),
                description:
                  viewKey === 'starred' && starredCollection === null
                    ? t(
                        'organizer.empty.starredHelp',
                        'Use the star beside a document to keep it here. Only you can see it.'
                      )
                    : t(
                        'organizer.empty.help',
                        'This folder is a query over what documents record. Nothing matched it — no document is hidden from it.'
                      ),
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

      <Dialog open={creatingCollection} onOpenChange={(open) => setCreatingCollection(open)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('organizer.collection.newTitle', 'New collection')}</DialogTitle>
            <DialogDescription>
              {t(
                'organizer.collection.newHelp',
                'A collection is yours alone. Filing a document says nothing about where it lives, and nobody else can see your collections.'
              )}
            </DialogDescription>
          </DialogHeader>
          <Input
            label={t('organizer.collection.name', 'Name')}
            value={newCollectionName}
            maxLength={160}
            onChange={(event) => setNewCollectionName(event.target.value)}
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => setCreatingCollection(false)}>
              {t('organizer.cancel', 'Cancel')}
            </Button>
            <Button
              disabled={busy || newCollectionName.trim() === ''}
              onClick={() => void createCollection()}
            >
              {t('organizer.collection.create', 'Create')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={deletingCollection !== null} onOpenChange={(open) => !open && setDeletingCollection(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('organizer.collection.deleteTitle', 'Delete this collection?')}</DialogTitle>
            <DialogDescription>
              {t(
                'organizer.collection.deleteHelp',
                'The documents in it are untouched — a collection holds pointers, not files. Only your own grouping is removed.'
              )}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeletingCollection(null)}>
              {t('organizer.cancel', 'Cancel')}
            </Button>
            <Button variant="destructive" disabled={busy} onClick={() => void deleteCollection()}>
              {t('organizer.collection.confirmDelete', 'Delete')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
