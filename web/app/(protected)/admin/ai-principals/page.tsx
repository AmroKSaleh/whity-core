'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { MCP_TOKENS_MANAGE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@amroksaleh/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@amroksaleh/ui/dropdown-menu';
import { IconMenu2, IconPlus } from '@tabler/icons-react';
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
  const canManage = hasPermission(MCP_TOKENS_MANAGE);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [pendingCredential, setPendingCredential] = useState<NewCredential | null>(null);
  const [isRevokeModalOpen, setIsRevokeModalOpen] = useState(false);
  const [selectedPrincipal, setSelectedPrincipal] = useState<AiPrincipal | null>(null);

  // The backend supports page/per_page but not sort/filter query params, so
  // sort/filter/pagination all run CLIENT-side over a single fetch — fetching
  // the backend's own page-size ceiling (100) rather than its 25-row default
  // fixes the previous silent page-1-only truncation for the common case.
  const { data, loading: isLoading, error, refetch } = useFetch(async () => {
    const response = await apiClient('/api/v1/admin/mcp/tokens?per_page=100');
    if (!response.ok) {
      throw new Error('Failed to fetch AI principals');
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

  const formatDate = (value: string | null): string => {
    if (!value) return '-';
    const parsed = new Date(value.replace(' ', 'T'));
    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleDateString();
  };

  const columns: DataTableColumn<AiPrincipal>[] = [
    { accessorKey: 'name', header: 'Name', enableSorting: true, enableColumnFilter: true },
    { accessorKey: 'principalKind', header: 'Kind', enableSorting: true },
    { accessorKey: 'userId', header: 'User ID', enableSorting: true },
    {
      accessorKey: 'expiresAt',
      header: 'Expires',
      enableSorting: true,
      cell: (row) => formatDate(row.expiresAt),
    },
    {
      accessorKey: 'createdAt',
      header: 'Created',
      enableSorting: true,
      cell: (row) => formatDate(row.createdAt),
    },
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
            Revoke
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    );
  };

  return (
    <div className="space-y-8">
      <AdminHeader
        title="AI Principals"
        description="Manage long-lived MCP bearer credentials issued to AI clients"
        action={
          canManage ? (
            <Button
              onClick={() => setIsCreateModalOpen(true)}
              className="gap-2"
            >
              <IconPlus size={18} />
              Create Credential
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
        globalFilterPlaceholder="Search AI principals…"
        pagination={{ pageSize: 10 }}
        emptyState={{
          title: 'No active credentials',
          description:
            'No AI principal tokens have been issued yet. Create one to let an AI client authenticate via MCP.',
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
