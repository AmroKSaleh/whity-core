'use client';

import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
import { DataTable, type DataTableColumn } from '@amroksaleh/ui/data-table';
import { IconRefresh } from '@tabler/icons-react';
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
          `/api/v1/audit-logs?${buildQuery(targetPage, filters)}`
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

  const columns: DataTableColumn<AuditLogEntry>[] = [
    { id: 'time', header: 'Time', cell: (entry) => formatTimestamp(entry.createdAt) },
    { accessorKey: 'action', header: 'Action' },
    {
      id: 'actor',
      header: 'Actor',
      cell: (entry) => (entry.actorUserId !== null ? `#${entry.actorUserId}` : 'system'),
    },
    { id: 'target', header: 'Target', cell: formatTarget },
    { accessorKey: 'ipAddress', header: 'IP' },
    {
      id: 'details',
      header: 'Details',
      cell: (entry) => formatMetadata(entry.metadata),
      className: 'max-w-md truncate',
    },
  ];

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
          <label className="text-xs font-medium text-muted-foreground">
            Action
          </label>
          <Input
            value={draftFilters.action}
            onChange={(e) => updateDraft('action', e.target.value)}
            placeholder="e.g. auth.login.success"
          />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-muted-foreground">
            Target Type
          </label>
          <Input
            value={draftFilters.targetType}
            onChange={(e) => updateDraft('targetType', e.target.value)}
            placeholder="e.g. role"
          />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-muted-foreground">
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
          <label className="text-xs font-medium text-muted-foreground">
            From
          </label>
          <Input
            type="date"
            value={draftFilters.from}
            onChange={(e) => updateDraft('from', e.target.value)}
          />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-muted-foreground">
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

      {/* Table — real server-side pagination (the backend already supports
          page/per_page and this table's own filters, unlike the other admin
          list endpoints, so it stays in DataTable's manual/server mode rather
          than the single-fetch client mode used elsewhere). */}
      <DataTable
        columns={columns}
        data={entries}
        getRowId={(entry) => String(entry.id)}
        isLoading={isLoading}
        emptyState={{ title: 'No audit entries found' }}
        pagination={{
          pageIndex: page - 1,
          pageSize: PER_PAGE,
          pageCount: totalPages,
          total,
          onPaginationChange: (nextPageIndex) => setPage(nextPageIndex + 1),
        }}
      />
    </div>
  );
}
