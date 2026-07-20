'use client';

import { useEffect } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Badge } from '@amroksaleh/ui/badge';
import { DataTable, type DataTableColumn } from '@amroksaleh/ui/data-table';
import { IconLock, IconLockOpen } from '@tabler/icons-react';
import type { McpTool } from './types';

interface McpToolListResponse {
  data: McpTool[];
}

/**
 * MCP Tools admin page (WC-0208ce4d).
 *
 * Read-only view of the MCP tools available in this tenant, showing each
 * tool's name, description, and the permission or role an AI principal must
 * hold to invoke it. Helps admins understand what capabilities they expose
 * when issuing MCP credentials.
 */
export default function McpToolsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();

  const { data, loading: isLoading, error } = useFetch(async () => {
    const response = await apiClient('/api/v1/admin/mcp/tools');
    if (!response.ok) {
      throw new Error('Failed to fetch MCP tools');
    }
    const body = (await response.json()) as McpToolListResponse;
    return body.data ?? [];
  }, [apiClient]);

  const tools = data ?? [];

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  const accessLabel = (tool: McpTool): string => {
    if (tool.requiredPermission) return tool.requiredPermission;
    if (tool.requiredRole) return `role: ${tool.requiredRole}`;
    return 'open';
  };

  const isRestricted = (tool: McpTool): boolean =>
    tool.requiredPermission !== null || tool.requiredRole !== null;

  const columns: DataTableColumn<McpTool>[] = [
    {
      accessorKey: 'name',
      header: 'Tool',
      enableSorting: true,
      enableColumnFilter: true,
      cell: (tool) => (
        <code className="text-sm font-medium text-foreground">{tool.name}</code>
      ),
    },
    {
      accessorKey: 'description',
      header: 'Description',
      cell: (tool) => tool.description || '-',
      className: 'max-w-md',
    },
    {
      id: 'access',
      header: 'Access',
      cell: (tool) => (
        <span className="inline-flex items-center gap-1.5">
          {isRestricted(tool) ? (
            <IconLock size={14} className="text-muted-foreground" />
          ) : (
            <IconLockOpen size={14} className="text-muted-foreground" />
          )}
          <Badge
            variant={isRestricted(tool) ? 'secondary' : 'outline'}
            className="text-xs font-mono"
          >
            {accessLabel(tool)}
          </Badge>
        </span>
      ),
    },
  ];

  return (
    <div className="space-y-8">
      <AdminHeader
        title="MCP Tools"
        description="Read-only view of API operations exposed as MCP tools, with their required access controls"
      />

      <DataTable
        columns={columns}
        data={tools}
        getRowId={(tool) => tool.name}
        isLoading={isLoading}
        emptyState={{ title: 'No MCP tools found' }}
        enableGlobalFilter
        globalFilterPlaceholder="Search tools…"
        pagination={{ pageSize: 15 }}
      />

      <p className="text-xs text-muted-foreground">
        {tools.length} {tools.length === 1 ? 'tool' : 'tools'} derived from
        schema-bearing API routes. An AI principal must hold the indicated
        permission to invoke restricted tools.
      </p>
    </div>
  );
}
