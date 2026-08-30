'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { MCP_TOKENS_MANAGE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@amroksaleh/ui/dropdown-menu';
import { IconMenu2, IconPlus } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useDateDisplay } from '@amroksaleh/features/datetime';
import { CreateAiPrincipalModal } from './create-modal';
import { CredentialModal } from './credential-modal';
import { RevokeAiPrincipalModal } from './revoke-modal';
import type { AiPrincipal, AiPrincipalListResponse, NewCredential } from './types';

/**
 * AI Principals admin page (WC-0208ce4d).
 *
 * Lists all active MCP bearer credentials issued within the current tenant.
 * Admins can create new credentials (shown once) and revoke existing ones.
 * Mirrors the loading / empty / error patterns of the other admin pages.
 */
export default function AiPrincipalsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const canManage = hasPermission(MCP_TOKENS_MANAGE);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [pendingCredential, setPendingCredential] = useState<NewCredential | null>(null);
  const [isRevokeModalOpen, setIsRevokeModalOpen] = useState(false);
  const [selectedPrincipal, setSelectedPrincipal] = useState<AiPrincipal | null>(null);

  // Sort/filter/pagination all still run CLIENT-side over a single fetch —
  // fetching the backend's own page-size ceiling (100) rather than its 25-row
  // default fixes the previous silent page-1-only truncation for the common
  // case, and credentials beyond 100 rows remain out of reach.
  //
  // #1102: `GET /api/v1/admin/mcp/tokens` now accepts `sort`/`dir` (name,
  // principalKind, userId, expiresAt, createdAt) and `q` (name, kind, jti) and
  // reports a search-filtered total, so this screen can move to server-side
  // sort and search whenever the table is wired for it.
  const { data, loading: isLoading, error, refetch } = useFetch(async () => {
    const response = await apiClient('/api/v1/admin/mcp/tokens?per_page=100');
    if (!response.ok) {
      throw new Error(t('aiPrincipals.error.load', 'Failed to fetch AI principals'));
    }
    const body = (await response.json()) as AiPrincipalListResponse;
    return body.data ?? [];
  }, [apiClient]);

  const principals = data ?? [];

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  const handleRevokeClick = (principal: AiPrincipal) => {
    setSelectedPrincipal(principal);
    setIsRevokeModalOpen(true);
  };

  const columns: DataTableColumn<AiPrincipal>[] = [
    {
      accessorKey: 'name',
      header: t('aiPrincipals.table.name', 'Name'),
      enableSorting: true,
      enableColumnFilter: true,
    },
    { accessorKey: 'principalKind', header: t('aiPrincipals.table.kind', 'Kind'), enableSorting: true },
    { accessorKey: 'userId', header: t('aiPrincipals.table.userId', 'User ID'), enableSorting: true },
    // #1068: both columns go together when this tenant hides dates. The
    // credential's NAME, kind and holder are what identify it; a revoke button
    // does not need an expiry printed beside it to work.
    ...dates.dateColumns<AiPrincipal>([
      {
        id: 'expiresAt',
        header: t('aiPrincipals.table.expires', 'Expires'),
        value: (row) => row.expiresAt,
        withTime: false,
        enableSorting: true,
      },
      {
        id: 'createdAt',
        header: t('aiPrincipals.table.created', 'Created'),
        value: (row) => row.createdAt,
        withTime: false,
        enableSorting: true,
      },
    ]),
  ];

  const rowActions = (principal: AiPrincipal) => {
    if (!canManage) return null;
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon-sm">
            <IconMenu2 size={16} />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem
            className="text-destructive focus:text-destructive"
            onClick={() => handleRevokeClick(principal)}
          >
            {t('aiPrincipals.action.revoke', 'Revoke')}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    );
  };

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('aiPrincipals.title', 'AI Principals')}
        description={t(
          'aiPrincipals.description',
          'Manage long-lived MCP bearer credentials issued to AI clients'
        )}
        action={
          canManage ? (
            <Button
              onClick={() => setIsCreateModalOpen(true)}
              className="gap-2"
            >
              <IconPlus size={18} />
              {t('aiPrincipals.header.create', 'Create Credential')}
            </Button>
          ) : undefined
        }
      />

      <DataTable
        columns={columns}
        data={principals}
        getRowId={(principal) => String(principal.id)}
        rowActions={canManage ? rowActions : undefined}
        isLoading={isLoading}
        enableGlobalFilter
        globalFilterPlaceholder={t('aiPrincipals.searchPlaceholder', 'Search AI principals…')}
        pagination={{ pageSize: 10 }}
        emptyState={{
          title: t('aiPrincipals.empty.title', 'No active credentials'),
          description: t(
            'aiPrincipals.empty.description',
            'No AI principal tokens have been issued yet. Create one to let an AI client authenticate via MCP.'
          ),
        }}
      />

      {isCreateModalOpen && (
        <CreateAiPrincipalModal
          isOpen={isCreateModalOpen}
          onOpenChange={setIsCreateModalOpen}
          onSuccess={(credential) => {
            setIsCreateModalOpen(false);
            setPendingCredential(credential);
            refetch();
          }}
        />
      )}

      {pendingCredential && (
        <CredentialModal
          isOpen={true}
          onOpenChange={(open) => {
            if (!open) setPendingCredential(null);
          }}
          credential={pendingCredential}
        />
      )}

      {selectedPrincipal && (
        <RevokeAiPrincipalModal
          isOpen={isRevokeModalOpen}
          onOpenChange={setIsRevokeModalOpen}
          principal={selectedPrincipal}
          onSuccess={() => {
            setIsRevokeModalOpen(false);
            setSelectedPrincipal(null);
            refetch();
          }}
        />
      )}
    </div>
  );
}
