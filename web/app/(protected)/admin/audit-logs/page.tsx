'use client';

import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { IconChevronLeft, IconChevronRight, IconRefresh } from '@tabler/icons-react';
import type {
  AuditLogEntry,
  AuditLogFilters,
  AuditLogResponse,
} from './types';

const EMPTY_FILTERS: AuditLogFilters = {
  action: '',
  targetType: '',
  actor: '',
  from: '',
  to: '',
};

const PER_PAGE = 25;

/**
 * Audit Logs admin page (WC-34).
 *
 * Lists security audit entries newest-first with filters (action, target type,
 * actor, date range) and pagination. Tenant scoping is enforced by the backend:
 * a tenant sees only its own entries; the system tenant sees all. Mirrors the
 * loading / empty / error patterns and design tokens of the other admin pages
 * (users / roles / OUs).
 */
export default function AuditLogsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();

  const [entries, setEntries] = useState<AuditLogEntry[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);

  // Draft filters bound to the inputs; applied filters drive the query.
  const [draftFilters, setDraftFilters] = useState<AuditLogFilters>(EMPTY_FILTERS);
  const [appliedFilters, setAppliedFilters] = useState<AuditLogFilters>(EMPTY_FILTERS);

  const buildQuery = useCallback(
    (targetPage: number, filters: AuditLogFilters): string => {
      const params = new URLSearchParams();
      params.set('page', String(targetPage));
      params.set('per_page', String(PER_PAGE));
      if (filters.action) params.set('action', filters.action);
      if (filters.targetType) params.set('target_type', filters.targetType);
      if (filters.actor) params.set('actor', filters.actor);
      if (filters.from) params.set('from', filters.from);
      if (filters.to) params.set('to', filters.to);
      return params.toString();
    },
    []
  );

  const fetchEntries = useCallback(
    async (targetPage: number, filters: AuditLogFilters) => {
      try {
        setIsLoading(true);
        const response = await apiClient(
          `/api/audit-logs?${buildQuery(targetPage, filters)}`
        );

        if (!response.ok) {
          throw new Error('Failed to fetch audit logs');
        }

        const data: AuditLogResponse = await response.json();
        setEntries(data.data ?? []);
        setTotalPages(data.pagination?.totalPages ?? 1);
        setTotal(data.pagination?.total ?? 0);
      } catch (error) {
        const message =
          error instanceof Error ? error.message : 'Failed to fetch audit logs';
        addToast(message, 'error');
      } finally {
        setIsLoading(false);
      }
    },
    [apiClient, addToast, buildQuery]
  );

  useEffect(() => {
    // Defer out of the synchronous effect body so the loading-state update made
    // by fetchEntries() does not run synchronously inside the effect (avoids the
    // React 19 cascading-render warning while still loading on mount/filter/page).
    let active = true;
    void Promise.resolve().then(() => {
      if (active) {
        fetchEntries(page, appliedFilters);
      }
    });
    return () => {
      active = false;
    };
  }, [page, appliedFilters, fetchEntries]);

  const handleApplyFilters = () => {
    setPage(1);
    setAppliedFilters(draftFilters);
  };

  const handleClearFilters = () => {
    setDraftFilters(EMPTY_FILTERS);
    setPage(1);
    setAppliedFilters(EMPTY_FILTERS);
  };

  const updateDraft = (key: keyof AuditLogFilters, value: string) => {
    setDraftFilters((prev) => ({ ...prev, [key]: value }));
  };

  const formatTimestamp = (value: string | null): string => {
    if (!value) return '-';
    const parsed = new Date(value.replace(' ', 'T'));
    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString();
  };

  const formatTarget = (entry: AuditLogEntry): string => {
    if (!entry.targetType) return '-';
    return entry.targetId !== null
      ? `${entry.targetType} #${entry.targetId}`
      : entry.targetType;
  };

  const formatMetadata = (metadata: Record<string, unknown>): string => {
    const keys = Object.keys(metadata);
    if (keys.length === 0) return '-';
    return keys.map((key) => `${key}: ${String(metadata[key])}`).join(', ');
  };

  return (
    <div className="space-y-8">
      <AdminHeader
        title="Audit Logs"
        description="Security audit trail of authentication, role, permission, tenant and user activity"
        action={
          <Button
            variant="outline"
            onClick={() => fetchEntries(page, appliedFilters)}
            className="gap-2"
          >
            <IconRefresh size={18} />
            Refresh
          </Button>
        }
      />

      {/* Filters */}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-slate-600 dark:text-slate-400">
            Action
          </label>
          <Input
            value={draftFilters.action}
            onChange={(e) => updateDraft('action', e.target.value)}
            placeholder="e.g. auth.login.success"
          />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-slate-600 dark:text-slate-400">
            Target Type
          </label>
          <Input
            value={draftFilters.targetType}
            onChange={(e) => updateDraft('targetType', e.target.value)}
            placeholder="e.g. role"
          />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-slate-600 dark:text-slate-400">
            Actor (User ID)
          </label>
          <Input
            type="number"
            value={draftFilters.actor}
            onChange={(e) => updateDraft('actor', e.target.value)}
            placeholder="e.g. 42"
          />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-slate-600 dark:text-slate-400">
            From
          </label>
          <Input
            type="date"
            value={draftFilters.from}
            onChange={(e) => updateDraft('from', e.target.value)}
          />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-slate-600 dark:text-slate-400">
            To
          </label>
          <Input
            type="date"
            value={draftFilters.to}
            onChange={(e) => updateDraft('to', e.target.value)}
          />
        </div>
        <div className="flex items-end gap-2">
          <Button onClick={handleApplyFilters} className="flex-1">
            Apply
          </Button>
          <Button variant="outline" onClick={handleClearFilters} className="flex-1">
            Clear
          </Button>
        </div>
      </div>

      {/* Table */}
      <div className="rounded-lg border border-slate-200 dark:border-slate-800 overflow-hidden">
        {isLoading ? (
          <div className="flex h-64 items-center justify-center bg-slate-50 dark:bg-slate-900">
            <div className="text-center">
              <div className="mx-auto mb-2 h-8 w-8 animate-spin rounded-full border-b-2 border-primary"></div>
              <p className="text-sm text-slate-600 dark:text-slate-400">Loading...</p>
            </div>
          </div>
        ) : entries.length === 0 ? (
          <div className="flex h-64 items-center justify-center bg-slate-50 dark:bg-slate-900">
            <p className="text-sm text-slate-600 dark:text-slate-400">
              No audit entries found
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="border-b border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800">
                <tr>
                  {['Time', 'Action', 'Actor', 'Target', 'IP', 'Details'].map(
                    (heading) => (
                      <th
                        key={heading}
                        className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-700 dark:text-slate-300"
                      >
                        {heading}
                      </th>
                    )
                  )}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200 dark:divide-slate-700">
                {entries.map((entry) => (
                  <tr
                    key={entry.id}
                    className="transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50"
                  >
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-slate-900 dark:text-slate-100">
                      {formatTimestamp(entry.createdAt)}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-slate-900 dark:text-slate-100">
                      {entry.action}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-slate-700 dark:text-slate-300">
                      {entry.actorUserId !== null ? `#${entry.actorUserId}` : 'system'}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-slate-700 dark:text-slate-300">
                      {formatTarget(entry)}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-slate-700 dark:text-slate-300">
                      {entry.ipAddress ?? '-'}
                    </td>
                    <td className="max-w-md truncate px-6 py-4 text-sm text-slate-500 dark:text-slate-400">
                      {formatMetadata(entry.metadata)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Pagination */}
      {!isLoading && entries.length > 0 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-slate-600 dark:text-slate-400">
            {total} {total === 1 ? 'entry' : 'entries'} &middot; page {page} of{' '}
            {totalPages}
          </p>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="icon-sm"
              disabled={page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              aria-label="Previous page"
            >
              <IconChevronLeft size={16} />
            </Button>
            <Button
              variant="outline"
              size="icon-sm"
              disabled={page >= totalPages}
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
              aria-label="Next page"
            >
              <IconChevronRight size={16} />
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
