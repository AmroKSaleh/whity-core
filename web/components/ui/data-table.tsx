'use client';

/**
 * The design system's DataTable with this app's translations. See ./dialog.tsx
 * for why this wrapper exists rather than props at each of the fourteen call
 * sites.
 *
 * `paginationLabels` is forwarded explicitly because the table renders its own
 * <Pagination>: without it the footer stays English on an otherwise translated
 * table, and no call site can reach it.
 */

import { useTranslation } from '@amroksaleh/features/i18n';
import { DataTable as BaseDataTable } from '@amroksaleh/ui/data-table';

export type {
  DataTableColumn,
  DataTableServerPagination,
  DataTableServerSorting,
  DataTableServerSearch,
  DataTableProps,
  DataTableQuery,
  DataTableQueryRequest,
  UseDataTableQueryOptions,
} from '@amroksaleh/ui/data-table';
// Re-exported through this wrapper so a screen reaches the table and the state
// that drives it from ONE import, rather than half from here and half from the
// package the wrapper exists to hide.
export {
  DATA_TABLE_SEARCH_DEBOUNCE_MS,
  DEFAULT_DATA_TABLE_PER_PAGE,
  dataTableQueryString,
  useDataTableQuery,
} from '@amroksaleh/ui/data-table';

export function DataTable<TData>(props: React.ComponentProps<typeof BaseDataTable<TData>>) {
  const t = useTranslation('common');

  return (
    <BaseDataTable<TData>
      rowActionsLabel={t('ui.table.actions', 'Actions')}
      columnFilterPlaceholder={t('ui.table.columnFilter', 'Filter…')}
      globalFilterPlaceholder={t('ui.table.search', 'Search…')}
      emptyStateTitle={t('ui.table.empty', 'No data available')}
      columnsMenuLabel={t('ui.table.columns', 'Columns')}
      paginationLabels={{
        // Both take the count/page numbers as arguments rather than being
        // spliced together, so a translation can put them where its grammar
        // needs them.
        entriesLabel: (total) =>
          total === 1
            ? t('ui.pagination.entry', '1 entry')
            : t('ui.pagination.entries', '{count} entries', { count: total }),
        pageLabel: (page, totalPages) =>
          t('ui.pagination.page', 'page {page} of {total}', { page, total: totalPages }),
        navLabel: t('ui.pagination.nav', 'Pagination'),
        previousLabel: t('ui.pagination.previous', 'Previous page'),
        nextLabel: t('ui.pagination.next', 'Next page'),
      }}
      {...props}
    />
  );
}
