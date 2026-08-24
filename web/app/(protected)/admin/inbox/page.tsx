'use client';

/**
 * The inbox — what is awaiting the signed-in person (#978, consuming #881).
 *
 * WHY THIS IS A CONSUMER OF THE #881 SOURCE REGISTRY, NOT A ROUTING SCREEN
 * -----------------------------------------------------------------------
 * The obvious build was `/admin/document-routing/inbox`, reading routing's
 * recipient rows. Everything about the engine says not to.
 *
 * `document_route_recipients` IS an inbox, and #947 rejected giving it a surface
 * of its own in a line — "two inbox surfaces would be the same mistake as two
 * audit trails". #989 acted on that: routing ships
 * `DocumentRoutingInboxSource`, registers it as an #881 source, and owns NO
 * endpoint. `MeInboxApiHandler`'s docblock spells out the consequence — "routing's
 * recipients are a SOURCE, not a surface. The endpoint belongs to the registry;
 * routing contributes to it."
 *
 * A screen that read routing's rows directly would undo that on the client side.
 * The person receiving work from several systems would open the app and find
 * several correct lists whose union exists nowhere — and the moment a second
 * source registers, the aggregate becomes a migration rather than a read.
 *
 * So this screen knows nothing about routing except how to LINK to it. It reads
 * `GET /api/v1/me/inbox/sources`, renders whatever is registered, and treats
 * routing as one source among N. Today N is 1. Nothing here changes when it is 3.
 *
 * WHY IT DOES NOT MERGE THE SOURCES ITSELF
 * ----------------------------------------
 * This is the subtle half, and it is why the screen shows a tab per source
 * rather than one blended list.
 *
 * `MeInboxApiHandler` REQUIRES `?source=`. An unsourced request is a 422 naming
 * the registered keys, and the docblock says exactly why: #881 lists three
 * questions that arise only when sources are aggregated — ordering across
 * heterogeneous sources, per-source failure isolation, and pagination across
 * sources — and they need deciding before an aggregate ships. "Answering an
 * unsourced request would be deciding all three by accident."
 *
 * Merging client-side would decide all three by accident too, just somewhere the
 * server could not see. Interleaving two sources by `timestamp` picks an ordering;
 * dropping a source that errored picks a failure policy and hides it; paginating
 * the union picks a scheme that silently drops items when one source is deeper
 * than the other. Each would then be the de facto contract, established by a
 * screen rather than by #881.
 *
 * So: ONE surface (this page), one source in view at a time, each paginated by
 * its own server. The tab strip is the honest rendering of "several sources, no
 * decided merge" — and it is not a compromise the user pays for while there is
 * one source, because with one source there is no strip at all.
 *
 * When the server aggregate lands, this page gains an "All" tab that calls the
 * same endpoint with no `source`. Every sourced call it already makes keeps
 * working, which is the property `MeInboxApiHandler` was shaped to preserve.
 */

import { useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Badge } from '@amroksaleh/ui/badge';
import { Button } from '@amroksaleh/ui/button';
import { useTranslation } from '@amroksaleh/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import {
  routingMeta,
  type InboxItem,
  type InboxListResponse,
  type InboxSource,
  type InboxSourcesResponse,
} from '@/components/documents/routing-wire';

/** The registry key routing registers under (`InboxSourceRegistry::CORE_DOCUMENT_ROUTING`). */
const DOCUMENT_ROUTING_SOURCE = 'document_routing';

export default function InboxPage() {
  const t = useTranslation('documents');
  const { apiClient } = useAuth();
  const router = useRouter();

  const [selectedSource, setSelectedSource] = useState<string | null>(null);
  const [openOnly, setOpenOnly] = useState(true);
  const [page, setPage] = useState(1);

  const sources = useFetch<InboxSourcesResponse>(async () => {
    const response = await apiClient('/api/v1/me/inbox/sources');
    if (!response.ok) {
      const body = (await response.json().catch(() => null)) as { error?: string } | null;
      throw new Error(body?.error ?? t('inbox.error.sources', 'Your inbox sources could not be loaded.'));
    }
    return (await response.json()) as InboxSourcesResponse;
  }, [apiClient]);

  const sourceList = useMemo<InboxSource[]>(() => sources.data?.data ?? [], [sources.data]);

  /**
   * Which source is in view.
   *
   * Derived rather than defaulted in an effect: a `setState` in an effect to pick
   * the first source would render one frame with no source, fire a request for
   * nothing, and re-render — the cascading-render hazard the house hooks avoid.
   * An explicit choice wins; otherwise the first registered key.
   */
  const activeSource = useMemo<string | null>(() => {
    if (selectedSource !== null && sourceList.some((s) => s.key === selectedSource)) {
      return selectedSource;
    }
    return sourceList.length > 0 ? sourceList[0].key : null;
  }, [selectedSource, sourceList]);

  const items = useFetch<InboxListResponse | null>(async () => {
    // No registered source means there is nothing to ask for. Sending
    // `?source=` empty would earn a 422 whose message is about a malformed
    // request, which is not what is happening.
    if (activeSource === null) return null;

    const params = new URLSearchParams({ source: activeSource, page: String(page) });
    if (!openOnly) params.set('open', '0');

    const response = await apiClient(`/api/v1/me/inbox?${params.toString()}`);
    if (!response.ok) {
      const body = (await response.json().catch(() => null)) as { error?: string } | null;
      // Verbatim: a 422 here names the registered source keys, which is the one
      // thing that makes it fixable.
      throw new Error(body?.error ?? t('inbox.error.list', 'Your inbox could not be loaded.'));
    }
    return (await response.json()) as InboxListResponse;
  }, [apiClient, activeSource, openOnly, page]);

  const rows = items.data?.data ?? [];
  const pagination = items.data?.pagination ?? null;

  const selectSource = (key: string): void => {
    // Page reset happens in the SAME handler as the change that invalidates it,
    // never in an effect watching the source — the document-library records why.
    setSelectedSource(key);
    setPage(1);
  };

  const columns: DataTableColumn<InboxItem>[] = useMemo(
    () => [
      {
        id: 'title',
        header: t('inbox.column.item', 'Item'),
        cell: (row) => {
          const meta = routingMeta(row);
          return (
            <div>
              {meta === null ? (
                // An item from a source this screen has no link for. Its title
                // is rendered as text rather than as a dead link — a link that
                // goes nowhere is worse than no link (#756).
                <span className="font-medium text-foreground">{row.title}</span>
              ) : (
                <button
                  type="button"
                  className="text-start font-medium text-primary underline"
                  onClick={() => router.push(`/admin/document-routing/${meta.document_id}`)}
                >
                  {row.title}
                </button>
              )}
              {row.subtitle !== null && row.subtitle !== '' && (
                <p className="text-xs text-muted-foreground">{row.subtitle}</p>
              )}
            </div>
          );
        },
      },
      {
        id: 'status',
        header: t('inbox.column.status', 'Status'),
        cell: (row) => {
          if (row.status === null || row.status === '') return null;
          const meta = routingMeta(row);
          // The qualifier is the SERVER's word, read through the trail event
          // that created the row ("Forwarded to you", "Returned to you",
          // "Done"). Never re-derived here, and never re-keyed: it is the
          // trail's own account of what happened to this person.
          const variant = meta !== null && !meta.open ? 'secondary' : 'warning';
          return <Badge variant={variant}>{row.status}</Badge>;
        },
      },
      {
        id: 'timestamp',
        header: t('inbox.column.arrived', 'Arrived'),
        cell: (row) =>
          row.timestamp === null ? null : (
            <span className="text-xs text-muted-foreground">
              {new Date(row.timestamp).toLocaleString()}
            </span>
          ),
      },
    ],
    [t, router]
  );

  if (sources.error !== null) {
    return (
      <div>
        <AdminHeader title={t('inbox.title', 'Inbox')} />
        <p className="rounded-md border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
          {sources.error}
        </p>
      </div>
    );
  }

  return (
    <div>
      <AdminHeader
        title={t('inbox.title', 'Inbox')}
        description={t('inbox.description', 'Work awaiting you, from every part of the platform that sends any.')}
      />

      {/*
        The tab strip appears only when there is more than one source. With one,
        a single tab is chrome that explains nothing; the heading already names
        what the list is.
      */}
      {sourceList.length > 1 && (
        <div className="mb-4 flex flex-wrap gap-2" role="tablist" aria-label={t('inbox.sources', 'Inbox sources')}>
          {sourceList.map((source) => (
            <Button
              key={source.key}
              role="tab"
              aria-selected={source.key === activeSource}
              variant={source.key === activeSource ? 'default' : 'outline'}
              onClick={() => selectSource(source.key)}
            >
              {source.key === DOCUMENT_ROUTING_SOURCE
                ? t('inbox.source.documentRouting', 'Documents awaiting you')
                : source.label}
              {/*
                The count is the source's own, from the same predicate its list
                uses — so this badge cannot disagree with the page it opens.
              */}
              {source.open_count > 0 && (
                <Badge variant="secondary" className="ms-2">
                  {source.open_count}
                </Badge>
              )}
            </Button>
          ))}
        </div>
      )}

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <Button
          variant={openOnly ? 'default' : 'outline'}
          size="sm"
          onClick={() => {
            setOpenOnly(true);
            setPage(1);
          }}
        >
          {t('inbox.filter.open', 'Awaiting me')}
        </Button>
        <Button
          variant={openOnly ? 'outline' : 'default'}
          size="sm"
          onClick={() => {
            setOpenOnly(false);
            setPage(1);
          }}
        >
          {t('inbox.filter.all', 'Including what I have done')}
        </Button>
      </div>

      {items.error !== null ? (
        <p className="rounded-md border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
          {items.error}
        </p>
      ) : sourceList.length === 0 && !sources.loading ? (
        // No registered sources at all. Distinct from "no items": nothing on this
        // installation sends work to an inbox, which is a different fact and one
        // an empty list would misreport as "you are up to date".
        <p className="rounded-md border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
          {t(
            'inbox.noSources',
            'Nothing on this installation sends work to an inbox yet, so there is nothing to show.'
          )}
        </p>
      ) : (
        <DataTable<InboxItem>
          columns={columns}
          data={rows}
          getRowId={(row) => row.id}
          isLoading={items.loading || sources.loading}
          ariaLabel={t('inbox.table.label', 'Inbox items')}
          emptyState={{
            title: openOnly
              ? t('inbox.empty.open.title', 'Nothing is awaiting you')
              : t('inbox.empty.all.title', 'Nothing has reached you yet'),
            description: openOnly
              ? t(
                  'inbox.empty.open.description',
                  'When a document is routed to you, it appears here. Switch to “Including what I have done” to see items you have already acted on.'
                )
              : t(
                  'inbox.empty.all.description',
                  'No item from this source has ever been addressed to you.'
                ),
          }}
          pagination={
            pagination !== null
              ? {
                  pageIndex: pagination.page - 1,
                  pageSize: pagination.perPage,
                  pageCount: pagination.totalPages,
                  total: pagination.total,
                  onPaginationChange: (pageIndex: number) => setPage(pageIndex + 1),
                }
              : undefined
          }
        />
      )}
    </div>
  );
}
