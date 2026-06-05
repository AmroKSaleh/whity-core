'use client';

import React, { useState, useMemo } from 'react';
import { IconChevronUp, IconChevronDown, IconDatabaseOff } from '@tabler/icons-react';
import { cn } from '@/lib/utils';
import { Skeleton } from '@/components/ui/skeleton';

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
 * Sort direction type
 */
type SortDirection = 'asc' | 'desc' | null;

/**
 * Generic, reusable data table component with sorting support
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
  const [sortColumn, setSortColumn] = useState<keyof T | null>(null);
  const [sortDirection, setSortDirection] = useState<SortDirection>(null);

  /**
   * Handle column header click to toggle sort
   */
  const handleSort = (column: Column<T>) => {
    if (!column.sortable) return;

    if (sortColumn === column.key) {
      // Toggle direction or clear sort
      if (sortDirection === 'asc') {
        setSortDirection('desc');
      } else {
        setSortColumn(null);
        setSortDirection(null);
      }
    } else {
      // Set new sort column
      setSortColumn(column.key);
      setSortDirection('asc');
    }
  };

  /**
   * Sort the data based on current sort column and direction
   */
  const sortedData = useMemo(() => {
    if (!sortColumn || !sortDirection) {
      return data;
    }

    const sorted = [...data].sort((a, b) => {
      const aVal = a[sortColumn];
      const bVal = b[sortColumn];

      // Handle null/undefined values
      if (aVal == null && bVal == null) return 0;
      if (aVal == null) return 1;
      if (bVal == null) return -1;

      // Compare values
      let comparison = 0;
      if (typeof aVal === 'string' && typeof bVal === 'string') {
        comparison = aVal.localeCompare(bVal);
      } else if (typeof aVal === 'number' && typeof bVal === 'number') {
        comparison = aVal - bVal;
      } else if (aVal instanceof Date && bVal instanceof Date) {
        comparison = aVal.getTime() - bVal.getTime();
      } else {
        comparison = String(aVal).localeCompare(String(bVal));
      }

      return sortDirection === 'asc' ? comparison : -comparison;
    });

    return sorted;
  }, [data, sortColumn, sortDirection]);

  /**
   * Get sort icon for a column
   */
  const getSortIcon = (column: Column<T>) => {
    if (!column.sortable) return null;

    if (sortColumn !== column.key) {
      return null;
    }

    return sortDirection === 'asc' ? (
      <IconChevronUp className="inline ml-1 size-4" />
    ) : (
      <IconChevronDown className="inline ml-1 size-4" />
    );
  };

  const columnCount = columns.length + (rowActions ? 1 : 0);

  /**
   * Render loading state — skeleton rows preserve the table layout (preferred
   * over a bare spinner per UI-Patterns.md).
   */
  if (isLoading) {
    return (
      <div className="border border-border rounded-lg overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-muted border-b border-border">
              <tr>
                {columns.map((column) => (
                  <th
                    key={String(column.key)}
                    className="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider"
                  >
                    {column.label}
                  </th>
                ))}
                {rowActions && (
                  <th className="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                    Actions
                  </th>
                )}
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {Array.from({ length: 5 }).map((_, rowIndex) => (
                <tr key={rowIndex}>
                  {Array.from({ length: columnCount }).map((__, colIndex) => (
                    <td key={colIndex} className="px-6 py-4">
                      <Skeleton className="h-4 w-3/4" />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <span className="sr-only" role="status" aria-live="polite">
          Loading…
        </span>
      </div>
    );
  }

  /**
   * Render empty state — icon / title / description / optional CTA anatomy
   * (UI-Patterns.md). Defaults keep the generic "No data available" message.
   */
  if (sortedData.length === 0) {
    return (
      <div className="flex h-64 flex-col items-center justify-center gap-2 rounded-lg border border-border bg-muted/30 text-center">
        {emptyState?.icon ?? (
          <IconDatabaseOff className="size-8 text-muted-foreground" />
        )}
        <p className="text-sm font-medium">
          {emptyState?.title ?? 'No data available'}
        </p>
        {emptyState?.description && (
          <p className="text-xs text-muted-foreground">
            {emptyState.description}
          </p>
        )}
        {emptyState?.action}
      </div>
    );
  }

  return (
    <div className="border border-border rounded-lg overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full">
          {/* Table Header */}
          <thead className="bg-muted border-b border-border">
            <tr>
              {columns.map((column) => (
                <th
                  key={String(column.key)}
                  onClick={() => handleSort(column)}
                  className={cn(
                    'px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider',
                    column.sortable && 'cursor-pointer hover:bg-muted/60 transition-colors'
                  )}
                >
                  <div className="flex items-center">
                    <span>{column.label}</span>
                    {getSortIcon(column)}
                  </div>
                </th>
              ))}
              {rowActions && (
                <th className="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  Actions
                </th>
              )}
            </tr>
          </thead>

          {/* Table Body */}
          <tbody className="divide-y divide-border">
            {sortedData.map((row) => (
              <tr
                key={String(row.id)}
                className="hover:bg-muted/50 transition-colors"
              >
                {columns.map((column) => (
                  <td
                    key={String(column.key)}
                    className="px-6 py-4 whitespace-nowrap text-sm text-foreground"
                  >
                    {String(row[column.key] ?? '-')}
                  </td>
                ))}
                {rowActions && (
                  <td className="px-6 py-4 whitespace-nowrap text-sm">
                    {rowActions(row)}
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
