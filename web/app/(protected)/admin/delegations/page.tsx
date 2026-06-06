'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type Column } from '@/components/admin/data-table';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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
  const { apiClient } = useAuth();
  const { addToast } = useToast();

  const [delegations, setDelegations] = useState<Delegation[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isForbidden, setIsForbidden] = useState(false);

  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [isRevokeOpen, setIsRevokeOpen] = useState(false);
  const [selected, setSelected] = useState<Delegation | null>(null);

  const fetchDelegations = useCallback(async () => {
    try {
      setIsLoading(true);
      const response = await apiClient('/api/delegations');

      if (response.status === 403) {
        // The acting user lacks delegation:manage — show an access-denied state
        // rather than an error toast loop.
        setIsForbidden(true);
        setDelegations([]);
        return;
      }
      setIsForbidden(false);

      if (!response.ok) {
        throw new Error('Failed to fetch delegations');
      }

      const data = await response.json();
      setDelegations(data.data ?? []);
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to fetch delegations';
      addToast(message, 'error');
    } finally {
      setIsLoading(false);
    }
  }, [apiClient, addToast]);

  useEffect(() => {
    void fetchDelegations();
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

  const columns: Column<DelegationRow>[] = [
    { key: 'permission', label: 'Permission', sortable: true },
    { key: 'grantee', label: 'Grantee', sortable: true },
    { key: 'scope', label: 'Scope', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
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

  if (isForbidden) {
    return (
      <div className="space-y-8">
        <AdminHeader
          title="Delegations"
          description="Delegate a subset of your permissions to roles or users."
        />
        <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
          <IconShieldLock
            size={32}
            className="mx-auto mb-3 text-muted-foreground"
          />
          <h2 className="font-heading text-sm font-medium">Access denied</h2>
          <p className="mt-1 text-xs text-muted-foreground">
            You need the delegation:manage permission to manage delegations.
          </p>
        </div>
      </div>
    );
  }

  const isEmpty = !isLoading && rows.length === 0;

  return (
    <div className="space-y-8">
      <AdminHeader
        title="Delegations"
        description="Delegate a subset of your permissions to roles or users, scoped to a tenant or an organizational unit."
        action={
          <Button onClick={() => setIsCreateOpen(true)} className="gap-2">
            <IconPlus size={18} />
            Create Delegation
          </Button>
        }
      />

      {isEmpty ? (
        <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
          <IconShare
            size={32}
            className="mx-auto mb-3 text-muted-foreground"
          />
          <h2 className="font-heading text-sm font-medium">
            No delegations yet
          </h2>
          <p className="mt-1 text-xs text-muted-foreground">
            Delegate a subset of your permissions to a role or a user.
          </p>
          <Button
            onClick={() => setIsCreateOpen(true)}
            variant="outline"
            className="mt-4 gap-2"
          >
            <IconPlus size={18} />
            Create the first delegation
          </Button>
        </div>
      ) : (
        <DataTable
          columns={columns}
          data={rows}
          rowActions={rowActions}
          isLoading={isLoading}
        />
      )}

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
