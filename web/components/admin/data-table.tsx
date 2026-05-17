'use client';

import React, { useState, useMemo } from 'react';
import { IconChevronUp, IconChevronDown } from '@tabler/icons-react';
import { cn } from '@/lib/utils';

/**
 * Column configuration for the data table
 */
export interface Column<T> {
  key: keyof T;
  label: string;
  sortable?: boolean;
}

/**
 * Props for the DataTable component
 */
export interface DataTableProps<T extends { id: string | number }> {
  columns: Column<T>[];
  data: T[];
  rowActions?: (item: T) => React.ReactNode;
  isLoading?: boolean;
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
      <IconChevronUp size={16} className="inline ml-1" />
    ) : (
      <IconChevronDown size={16} className="inline ml-1" />
    );
  };

  /**
   * Render loading state
   */
  if (isLoading) {
    return (
      <div className="border border-slate-200 dark:border-slate-800 rounded-lg overflow-hidden">
        <div className="h-64 flex items-center justify-center bg-slate-50 dark:bg-slate-900">
          <div className="text-center">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto mb-2"></div>
            <p className="text-sm text-slate-600 dark:text-slate-400">Loading...</p>
          </div>
        </div>
      </div>
    );
  }

  /**
   * Render empty state
   */
  if (sortedData.length === 0) {
    return (
      <div className="border border-slate-200 dark:border-slate-800 rounded-lg overflow-hidden">
        <div className="h-64 flex items-center justify-center bg-slate-50 dark:bg-slate-900">
          <div className="text-center">
            <p className="text-sm text-slate-600 dark:text-slate-400">
              No data available
            </p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="border border-slate-200 dark:border-slate-800 rounded-lg overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full">
          {/* Table Header */}
          <thead className="bg-slate-100 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
            <tr>
              {columns.map((column) => (
                <th
                  key={String(column.key)}
                  onClick={() => handleSort(column)}
                  className={cn(
                    'px-6 py-3 text-left text-xs font-medium text-slate-700 dark:text-slate-300 uppercase tracking-wider',
                    column.sortable && 'cursor-pointer hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors'
                  )}
                >
                  <div className="flex items-center">
                    <span>{column.label}</span>
                    {getSortIcon(column)}
                  </div>
                </th>
              ))}
              {rowActions && (
                <th className="px-6 py-3 text-left text-xs font-medium text-slate-700 dark:text-slate-300 uppercase tracking-wider">
                  Actions
                </th>
              )}
            </tr>
          </thead>

          {/* Table Body */}
          <tbody className="divide-y divide-slate-200 dark:divide-slate-700">
            {sortedData.map((row) => (
              <tr
                key={String(row.id)}
                className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
              >
                {columns.map((column) => (
                  <td
                    key={String(column.key)}
                    className="px-6 py-4 whitespace-nowrap text-sm text-slate-900 dark:text-slate-100"
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
