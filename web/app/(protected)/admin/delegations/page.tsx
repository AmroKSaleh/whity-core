'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@amroksaleh/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import { IconPlus, IconShare, IconShieldLock } from '@tabler/icons-react';
import { CreateDelegationModal } from './create-modal';
import { RevokeDelegationModal } from './revoke-modal';
import type { Delegation } from './types';

/**
 * Row view-model for the delegations table. Display strings are precomputed so
 * the generic DataTable (which renders raw cell values) shows readable labels
 * for the polymorphic grantee, the OU scope and the live/revoked status.
 */
interface DelegationRow {
  id: number;
  permission: string;
  grantee: string;
  scope: string;
  status: string;
  source: Delegation;
}

export default function DelegationsPage() {
  const { addToast } = useToast();

  const [delegations, setDelegations] = useState<Delegation[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isForbidden, setIsForbidden] = useState(false);

  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [isRevokeOpen, setIsRevokeOpen] = useState(false);
  const [selected, setSelected] = useState<Delegation | null>(null);

  // The backend supports page/per_page but not sort/filter query params, so
  // sort/filter/pagination all run CLIENT-side over a single fetch — fetching
  // the backend's own page-size ceiling (100) rather than its default fixes
  // the previous silent page-1-only truncation for the common case. Tenants
  // with >100 delegations are still capped until the backend grows real
  // search/sort support; that's a pre-existing limit, just moved further out.
  const fetchDelegations = useCallback(async () => {
    try {
      setIsLoading(true);
      const { data, response } = await api.GET('/api/v1/delegations', {
        // The generated OpenAPI schema for this endpoint doesn't document
        // per_page (a spec gap — see the migration note above), but the
        // controller runs through the same PaginationParams helper as the
        // other list endpoints and honors it identically.
        params: { query: { per_page: 100 } as never },
      });

      if (response.status === 403) {
        // The acting user lacks delegation:manage — show an access-denied state
        // rather than an error toast loop.
        setIsForbidden(true);
        setDelegations([]);
        return;
      }
      setIsForbidden(false);

      if (data === undefined) {
        throw new Error('Failed to fetch delegations');
      }

      setDelegations(data.data);
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to fetch delegations';
      addToast(message, 'error');
    } finally {
      setIsLoading(false);
    }
  }, [addToast]);

  useEffect(() => {
    void (async () => {
      await fetchDelegations();
    })();
  }, [fetchDelegations]);

  const rows: DelegationRow[] = useMemo(
    () =>
      delegations.map((d) => ({
        id: d.id,
        permission: d.permission,
        grantee:
          d.granteeType === 'role'
            ? `Role #${d.granteeId}`
            : `User #${d.granteeId}`,
        scope: d.ouId !== null ? `OU #${d.ouId}` : 'Tenant-wide',
        status: d.revokedAt !== null ? 'Revoked' : 'Active',
        source: d,
      })),
    [delegations]
  );

  const columns: DataTableColumn<DelegationRow>[] = [
    {
      accessorKey: 'permission',
      header: 'Permission',
      enableSorting: true,
      enableColumnFilter: true,
    },
    {
      accessorKey: 'grantee',
      header: 'Grantee',
      enableSorting: true,
      enableColumnFilter: true,
    },
    { accessorKey: 'scope', header: 'Scope', enableSorting: true },
    { accessorKey: 'status', header: 'Status', enableSorting: true },
  ];

  const handleRevokeClick = (delegation: Delegation) => {
    setSelected(delegation);
    setIsRevokeOpen(true);
  };

  const rowActions = (row: DelegationRow) => {
    if (row.source.revokedAt !== null) {
      return <Badge variant="outline">Revoked</Badge>;
    }
    return (
      <Button
        variant="ghost"
        size="sm"
        className="text-destructive hover:text-destructive"
        onClick={() => handleRevokeClick(row.source)}
      >
        Revoke
      </Button>
    );
  };

  const accessDenied = isForbidden ? (
    <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
      <IconShieldLock size={32} className="mx-auto mb-3 text-muted-foreground" />
      <h2 className="font-heading text-sm font-medium">Access denied</h2>
      <p className="mt-1 text-xs text-muted-foreground">
        You need the delegation:manage permission to manage delegations.
      </p>
    </div>
  ) : undefined;

  return (
    <div className="space-y-8">
      <AdminHeader
        title="Delegations"
        description={
          isForbidden
            ? 'Delegate a subset of your permissions to roles or users.'
            : 'Delegate a subset of your permissions to roles or users, scoped to a tenant or an organizational unit.'
        }
        action={
          isForbidden ? undefined : (
            <Button onClick={() => setIsCreateOpen(true)} className="gap-2">
              <IconPlus size={18} />
              Create Delegation
            </Button>
          )
        }
      />

      <DataTable
        columns={columns}
        data={rows}
        getRowId={(row) => String(row.id)}
        rowActions={rowActions}
        isLoading={isLoading}
        overrideContent={accessDenied}
        enableGlobalFilter
        globalFilterPlaceholder="Search delegations…"
        pagination={{ pageSize: 10 }}
        emptyState={{
          icon: <IconShare size={32} className="text-muted-foreground" />,
          title: 'No delegations yet',
          description: 'Delegate a subset of your permissions to a role or a user.',
          action: (
            <Button
              onClick={() => setIsCreateOpen(true)}
              variant="outline"
              className="gap-2"
            >
              <IconPlus size={18} />
              Create the first delegation
            </Button>
          ),
        }}
      />

      <CreateDelegationModal
        // Remount on each open so the form resets to its defaults without a
        // synchronous setState in an effect (disallowed by this React version).
        key={isCreateOpen ? 'create-open' : 'create-closed'}
        isOpen={isCreateOpen}
        onOpenChange={setIsCreateOpen}
        onSuccess={() => {
          setIsCreateOpen(false);
          void fetchDelegations();
        }}
      />

      {selected && (
        <RevokeDelegationModal
          isOpen={isRevokeOpen}
          onOpenChange={setIsRevokeOpen}
          delegation={selected}
          onSuccess={() => {
            setIsRevokeOpen(false);
            setSelected(null);
            void fetchDelegations();
          }}
        />
      )}
    </div>
  );
}
