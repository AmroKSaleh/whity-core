'use client';

import { useEffect } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Badge } from '@amroksaleh/ui/badge';
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

  return (
    <div className="space-y-8">
      <AdminHeader
        title="MCP Tools"
        description="Read-only view of API operations exposed as MCP tools, with their required access controls"
      />

      {isLoading ? (
        <div className="flex h-64 items-center justify-center rounded-lg border border-border bg-muted/50">
          <div className="text-center">
            <div className="mx-auto mb-2 h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
            <p className="text-sm text-muted-foreground">Loading...</p>
          </div>
        </div>
      ) : tools.length === 0 ? (
        <div className="flex h-64 items-center justify-center rounded-lg border border-border bg-muted/30">
          <p className="text-sm text-muted-foreground">No MCP tools found</p>
        </div>
      ) : (
        <div className="rounded-lg border border-border overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="border-b border-border bg-muted">
                <tr>
                  {['Tool', 'Description', 'Access'].map((heading) => (
                    <th
                      key={heading}
                      className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-foreground"
                    >
                      {heading}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {tools.map((tool) => (
                  <tr
                    key={tool.name}
                    className="transition-colors hover:bg-muted/50"
                  >
                    <td className="whitespace-nowrap px-6 py-4">
                      <code className="text-sm font-medium text-foreground">
                        {tool.name}
                      </code>
                    </td>
                    <td className="px-6 py-4 text-sm text-muted-foreground max-w-md">
                      {tool.description || '-'}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
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
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <p className="text-xs text-muted-foreground">
        {tools.length} {tools.length === 1 ? 'tool' : 'tools'} derived from
        schema-bearing API routes. An AI principal must hold the indicated
        permission to invoke restricted tools.
      </p>
    </div>
  );
}
