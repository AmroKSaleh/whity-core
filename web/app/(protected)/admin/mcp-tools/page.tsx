'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Badge } from '@whity-org/ui/badge';
import { Card, CardContent } from '@whity-org/ui/card';
import { Switch } from '@whity-org/ui/switch';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { IconLock, IconLockOpen } from '@tabler/icons-react';
import { useTranslation } from '@whity-org/features/i18n';
import type { McpAgentPolicy, McpListSize, McpTool, McpToolListResponse, McpToolVisibility } from './types';

/** The setting that holds the plugin tools this workspace lists directly (WHIT-754). */
const ALWAYS_ON_SETTING = 'mcp.always_on_plugin_tools';

/**
 * MCP Tools admin page (WC-0208ce4d, WHIT-754).
 *
 * Every operation an MCP client can be offered in this workspace, core and
 * plugin, with the permission it needs, the toolset it belongs to, how it
 * reaches a client and what an AI agent may do with it on its own. A plugin
 * tool that asks to be listed directly is listed only once an administrator
 * turns it on here (docs/design/MCP-TOOL-SURFACE.md §5.10, owner decision 5).
 * The sizes say what each list mode costs an app before it does anything.
 */
export default function McpToolsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [saving, setSaving] = useState<string | null>(null);

  const { data, loading: isLoading, error, refetch } = useFetch(async () => {
    const response = await apiClient('/api/v1/admin/mcp/tools');
    if (!response.ok) {
      throw new Error(t('mcpTools.error.load', 'Failed to fetch MCP tools'));
    }
    return (await response.json()) as McpToolListResponse;
  }, [apiClient]);

  const tools = data?.data ?? [];
  const sizes = data?.sizes;

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  const setAlwaysOn = async (tool: McpTool, on: boolean) => {
    const enabled = tools
      .filter((other) => other.alwaysOnRequested && other.alwaysOnEnabled && other.name !== tool.name)
      .map((other) => other.name);
    if (on) enabled.push(tool.name);
    setSaving(tool.name);
    try {
      const response = await apiClient('/api/v1/settings', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ settings: { [ALWAYS_ON_SETTING]: enabled.join(',') } }),
      });
      if (!response.ok) {
        const body = (await response.json().catch(() => ({}))) as { message?: string };
        throw new Error(body.message ?? t('mcpTools.alwaysOn.saveFailed', 'Could not change how {name} is listed', { name: tool.name }));
      }
      addToast(
        on
          ? t('mcpTools.alwaysOn.turnedOn', 'Apps now see {name} without searching.', { name: tool.name })
          : t('mcpTools.alwaysOn.turnedOff', 'Apps now find {name} through search.', { name: tool.name }),
        'success'
      );
      refetch();
    } catch (err) {
      addToast(
        err instanceof Error ? err.message : t('mcpTools.alwaysOn.saveFailed', 'Could not change how {name} is listed', { name: tool.name }),
        'error'
      );
    } finally {
      setSaving(null);
    }
  };

  const accessLabel = (tool: McpTool): string => {
    if (tool.requiredPermission) return tool.requiredPermission;
    if (tool.requiredRole) {
      return t('mcpTools.access.role', 'role: {role}', { role: tool.requiredRole });
    }
    return t('mcpTools.access.open', 'open');
  };

  const isRestricted = (tool: McpTool): boolean =>
    tool.requiredPermission !== null || tool.requiredRole !== null;

  const visibilityLabel = (visibility: McpToolVisibility): string =>
    ({
      'always-on': t('mcpTools.visibility.alwaysOn', 'Always listed'),
      discoverable: t('mcpTools.visibility.discoverable', 'Found by search'),
      off: t('mcpTools.visibility.off', 'Not offered'),
    })[visibility] ?? visibility;

  const policyLabel = (policy: McpAgentPolicy): string =>
    ({
      allow: t('mcpTools.agentPolicy.allow', 'Can run'),
      propose: t('mcpTools.agentPolicy.propose', 'Proposes'),
      'suggest-only': t('mcpTools.agentPolicy.suggestOnly', 'Suggests only'),
      refuse: t('mcpTools.agentPolicy.refuse', 'Never'),
    })[policy] ?? policy;

  const columns: DataTableColumn<McpTool>[] = [
    {
      accessorKey: 'name',
      header: t('mcpTools.table.tool', 'Tool'),
      enableSorting: true,
      enableColumnFilter: true,
      cell: (tool) => (
        <span className="space-y-0.5">
          <code className="block text-sm font-medium text-foreground">{tool.name}</code>
          {tool.description !== '' && (
            <span className="block max-w-md text-xs text-muted-foreground">{tool.description}</span>
          )}
        </span>
      ),
    },
    {
      accessorKey: 'toolset',
      header: t('mcpTools.table.toolset', 'Toolset'),
      enableSorting: true,
      enableColumnFilter: true,
      cell: (tool) => <code className="text-xs">{tool.toolset || '-'}</code>,
    },
    {
      id: 'listed',
      header: t('mcpTools.table.listed', 'How apps see it'),
      cell: (tool) =>
        tool.alwaysOnRequested ? (
          <span className="inline-flex items-center gap-2">
            <Switch
              checked={tool.alwaysOnEnabled}
              disabled={saving !== null}
              onCheckedChange={(checked) => void setAlwaysOn(tool, checked)}
              aria-label={t('mcpTools.alwaysOn.toggle', 'List {name} without searching', { name: tool.name })}
            />
            <span className="text-sm">
              {tool.alwaysOnEnabled ? visibilityLabel('always-on') : visibilityLabel('discoverable')}
            </span>
          </span>
        ) : (
          <span className="text-sm">
            {visibilityLabel(tool.alwaysOnEnabled ? 'always-on' : tool.visibility === 'always-on' ? 'discoverable' : tool.visibility)}
          </span>
        ),
    },
    {
      accessorKey: 'agentPolicy',
      header: t('mcpTools.table.agentPolicy', 'Agents'),
      enableSorting: true,
      cell: (tool) => <span className="text-sm">{policyLabel(tool.agentPolicy)}</span>,
    },
    {
      id: 'access',
      header: t('mcpTools.table.access', 'Access'),
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
        title={t('mcpTools.title', 'MCP Tools')}
        description={t(
          'mcpTools.description',
          'What AI apps can be offered here, what each action needs, and how apps find it'
        )}
      />

      {sizes !== undefined && (
        <div className="grid gap-3 sm:grid-cols-3">
          <SizeCard
            title={t('mcpTools.sizes.default', 'Standard connection')}
            help={t('mcpTools.sizes.defaultHelp', 'A few everyday actions, plus search for the rest. What the connect wizard hands out.')}
            size={sizes.default}
          />
          <SizeCard
            title={t('mcpTools.sizes.full', 'Every action at once')}
            help={t('mcpTools.sizes.fullHelp', 'Only for apps that need the whole list (?tools=full). Slower, and some apps drop actions past a limit.')}
            size={sizes.full}
          />
          <SizeCard
            title={t('mcpTools.sizes.discover', 'Search only')}
            help={t('mcpTools.sizes.discoverHelp', 'Search and use, nothing else (?tools=discover).')}
            size={sizes.discover}
          />
        </div>
      )}

      <DataTable
        columns={columns}
        data={tools}
        getRowId={(tool) => tool.name}
        isLoading={isLoading}
        emptyState={{ title: t('mcpTools.empty.title', 'No MCP tools found') }}
        enableGlobalFilter
        globalFilterPlaceholder={t('mcpTools.searchPlaceholder', 'Search tools…')}
        pagination={{ pageSize: 15 }}
      />

      {/* One whole sentence per plural form, with the count as a hole in it —
          rather than a count glued to a bare "tool"/"tools" fragment, which a
          translator cannot decline and cannot reorder. */}
      <p className="text-xs text-muted-foreground">
        {tools.length === 1
          ? t(
              'mcpTools.summary.one',
              '{count} tool. An app never gets more than the person who connected it can do, and only the permissions its connection allows.',
              { count: tools.length }
            )
          : t(
              'mcpTools.summary.other',
              '{count} tools. An app never gets more than the person who connected it can do, and only the permissions its connection allows.',
              { count: tools.length }
            )}
      </p>
    </div>
  );
}

/** What one list mode costs an app before it does anything; tokens are roughly bytes / 4. */
function SizeCard({ title, help, size }: { title: string; help: string; size: McpListSize }) {
  const t = useTranslation('admin');
  return (
    <Card>
      <CardContent className="space-y-1 p-4">
        <p className="text-sm font-medium">{title}</p>
        <p className="text-sm">
          {t('mcpTools.sizes.value', '{tools} actions, about {tokens} tokens', {
            tools: size.tools,
            tokens: Math.round(size.bytes / 4).toLocaleString('en'),
          })}
        </p>
        <p className="text-xs text-muted-foreground">{help}</p>
      </CardContent>
    </Card>
  );
}
