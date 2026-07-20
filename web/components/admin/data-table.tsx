'use client';

import React from 'react';
import {
  DataTable as SharedDataTable,
  type DataTableColumn,
} from '@amroksaleh/ui/data-table';

/**
 * Column configuration for the data table
 */
export interface Column<T> {
  key: keyof T;
  label: string;
  sortable?: boolean;
}

/**
 * Optional customization of the table's empty state, following the
 * icon / title / description / CTA anatomy from UI-Patterns.md. When omitted,
 * a generic "No data available" message is shown.
 */
export interface EmptyState {
  icon?: React.ReactNode;
  title?: string;
  description?: string;
  action?: React.ReactNode;
}

/**
 * Props for the DataTable component
 */
export interface DataTableProps<T extends { id: string | number }> {
  columns: Column<T>[];
  data: T[];
  rowActions?: (item: T) => React.ReactNode;
  isLoading?: boolean;
  /** Optional richer empty-state content (icon/title/description/CTA). */
  emptyState?: EmptyState;
}

/**
 * Thin adapter over `@amroksaleh/ui`'s DataTable, preserving this file's
 * original public shape (Column<T>/DataTableProps<T>) — this component is
 * ALSO baked verbatim into `web/public/r/data-table.json` (a shadcn-style
 * registry entry, built by `npm run build`'s "Building registry" step) for an
 * external `shadcn add` consumption path, so its exported API must not
 * change even though the real implementation now lives in the shared
 * package.
 *
 * @example
 * ```tsx
 * interface User {
 *   id: string;
 *   name: string;
 *   email: string;
 * }
 *
 * const columns: Column<User>[] = [
 *   { key: 'name', label: 'Name', sortable: true },
 *   { key: 'email', label: 'Email', sortable: true },
 * ];
 *
 * <DataTable
 *   columns={columns}
 *   data={users}
 *   rowActions={(user) => <EditButton userId={user.id} />}
 * />
 * ```
 */
export function DataTable<T extends { id: string | number }>({
  columns,
  data,
  rowActions,
  isLoading = false,
  emptyState,
}: DataTableProps<T>) {
  const sharedColumns: DataTableColumn<T>[] = columns.map((column) => ({
    id: String(column.key),
    accessorKey: column.key as Extract<keyof T, string>,
    header: column.label,
    enableSorting: column.sortable,
  }));

  return (
    <SharedDataTable
      columns={sharedColumns}
      data={data}
      getRowId={(row) => String(row.id)}
      rowActions={rowActions}
      isLoading={isLoading}
      emptyState={{ title: 'No data available', ...emptyState }}
    />
  );
}
