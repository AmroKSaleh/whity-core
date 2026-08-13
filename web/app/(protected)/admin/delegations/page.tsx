'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import { ErrorState } from '@amroksaleh/ui/empty-state';
import { IconPlus, IconShare, IconShieldLock } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
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
  const t = useTranslation('admin');

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
        throw new Error(t('delegations.error.load', 'Failed to fetch delegations'));
      }

      setDelegations(data.data);
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('delegations.error.load', 'Failed to fetch delegations');
      addToast(message, 'error');
    } finally {
      setIsLoading(false);
    }
  }, [addToast, t]);

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
            ? t('delegations.grantee.role', 'Role #{id}', { id: d.granteeId })
            : t('delegations.grantee.user', 'User #{id}', { id: d.granteeId }),
        scope:
          d.ouId !== null
            ? t('delegations.scope.ou', 'OU #{id}', { id: d.ouId })
            : t('delegations.scope.tenantWide', 'Tenant-wide'),
        status:
          d.revokedAt !== null
            ? t('delegations.status.revoked', 'Revoked')
            : t('delegations.status.active', 'Active'),
        source: d,
      })),
    [delegations, t]
  );

  const columns: DataTableColumn<DelegationRow>[] = [
    {
      accessorKey: 'permission',
      header: t('delegations.table.permission', 'Permission'),
      enableSorting: true,
      enableColumnFilter: true,
    },
    {
      accessorKey: 'grantee',
      header: t('delegations.table.grantee', 'Grantee'),
      enableSorting: true,
      enableColumnFilter: true,
    },
    { accessorKey: 'scope', header: t('delegations.table.scope', 'Scope'), enableSorting: true },
    { accessorKey: 'status', header: t('delegations.table.status', 'Status'), enableSorting: true },
  ];

  const handleRevokeClick = (delegation: Delegation) => {
    setSelected(delegation);
    setIsRevokeOpen(true);
  };

  const rowActions = (row: DelegationRow) => {
    if (row.source.revokedAt !== null) {
      return <Badge variant="outline">{t('delegations.status.revoked', 'Revoked')}</Badge>;
    }
    return (
      <Button
        variant="ghost"
        size="sm"
        className="text-destructive hover:text-destructive"
        onClick={() => handleRevokeClick(row.source)}
      >
        {t('delegations.action.revoke', 'Revoke')}
      </Button>
    );
  };

  const accessDenied = isForbidden ? (
    <ErrorState
      icon={<IconShieldLock />}
      title={t('delegations.forbidden.title', 'Access denied')}
      description={t(
        'delegations.forbidden.description',
        'You need the delegation:manage permission to manage delegations.'
      )}
    />
  ) : undefined;

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('delegations.title', 'Delegations')}
        description={
          isForbidden
            ? t(
                'delegations.description.short',
                'Delegate a subset of your permissions to roles or users.'
              )
            : t(
                'delegations.description',
                'Delegate a subset of your permissions to roles or users, scoped to a tenant or an organizational unit.'
              )
        }
        action={
          isForbidden ? undefined : (
            <Button onClick={() => setIsCreateOpen(true)} className="gap-2">
              <IconPlus size={18} />
              {t('delegations.header.create', 'Create Delegation')}
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
        globalFilterPlaceholder={t('delegations.searchPlaceholder', 'Search delegations…')}
        pagination={{ pageSize: 10 }}
        emptyState={{
          icon: <IconShare size={32} className="text-muted-foreground" />,
          title: t('delegations.empty.title', 'No delegations yet'),
          description: t(
            'delegations.empty.description',
            'Delegate a subset of your permissions to a role or a user.'
          ),
          action: (
            <Button
              onClick={() => setIsCreateOpen(true)}
              variant="outline"
              className="gap-2"
            >
              <IconPlus size={18} />
              {t('delegations.empty.action', 'Create the first delegation')}
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
