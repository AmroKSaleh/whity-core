'use client';

/**
 * Route templates — `/admin/document-route-templates` (#1027).
 *
 * The list, and the door to the node-based editor. Bespoke rather than the
 * schema-driven CrudScreen for the reason every core admin page states: that
 * screen renders from the DYNAMIC `/api/openapi.json`, which describes only
 * plugin routes, so it cannot derive fields for a core resource.
 *
 * NO PEOPLE COUNT ON A ROW, DELIBERATELY
 * --------------------------------------
 * A row shows how many STAGES a design has and never how many people it reaches.
 * The second would mean resolving every rule of every step on every render — a
 * page of forty templates commissioning hundreds of fan-out queries to decorate
 * a screen nobody asked a membership question on — and the number would be stale
 * by the time it was drawn. That question is answered per node, inside the
 * editor, where somebody has asked it.
 */

import { useCallback, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { ROUTE_TEMPLATES_WRITE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@/components/ui/input';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { IconPlus } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import type { RouteTemplateSummary } from './types';

export default function RouteTemplatesPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const router = useRouter();
  const t = useTranslation('admin');

  const canWrite = hasPermission(ROUTE_TEMPLATES_WRITE);

  const { data, loading, error, refetch } = useFetch(async () => {
    const response = await apiClient('/api/v1/document-route-templates');
    if (!response.ok) {
      throw new Error(t('routeTemplates.error.load', 'Failed to load route templates'));
    }
    const body = await response.json();
    return (body.data ?? []) as RouteTemplateSummary[];
  }, [apiClient]);

  const [creating, setCreating] = useState(false);
  const [name, setName] = useState('');
  const [saving, setSaving] = useState(false);

  const create = useCallback(async () => {
    setSaving(true);
    try {
      const response = await apiClient('/api/v1/document-route-templates', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name }),
      });

      if (!response.ok) {
        // The server's message names the conflict or the field, so it is shown
        // verbatim rather than replaced with a generic failure.
        const body = await response.json().catch(() => null);
        const message =
          typeof body?.error === 'string'
            ? body.error
            : t('routeTemplates.error.create', 'Failed to create the route template');
        addToast(message, 'error');
        return;
      }

      const body = await response.json();
      setCreating(false);
      setName('');
      // Straight into the editor: a template is created EMPTY and the only
      // useful next act is to draw it.
      router.push(`/admin/document-route-templates/${body.data.id}`);
    } finally {
      setSaving(false);
    }
  }, [addToast, apiClient, name, router, t]);

  const columns: DataTableColumn<RouteTemplateSummary>[] = [
    {
      accessorKey: 'name',
      header: t('routeTemplates.column.name', 'Name'),
      enableSorting: true,
      enableColumnFilter: true,
      cell: (row) => (
        <button
          type="button"
          onClick={() => router.push(`/admin/document-route-templates/${row.id}`)}
          className="text-start font-medium text-primary hover:underline"
        >
          {row.name}
        </button>
      ),
    },
    {
      accessorKey: 'description',
      header: t('routeTemplates.column.description', 'Description'),
      cell: (row) => <span className="text-muted-foreground">{row.description ?? '—'}</span>,
    },
    {
      accessorKey: 'step_count',
      header: t('routeTemplates.column.stages', 'Stages'),
      enableSorting: true,
      cell: (row) => row.step_count,
    },
  ];

  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('routeTemplates.title', 'Route Templates')}
        description={t(
          'routeTemplates.description',
          'Reusable flows a document travels. A stage names a rule — a role, a group, a unit — never a list of people, so a design authored today reaches whoever holds the role when it is used.'
        )}
        action={
          canWrite ? (
            <Button onClick={() => setCreating(true)}>
              <IconPlus className="me-1 size-4" />
              {t('routeTemplates.action.new', 'New template')}
            </Button>
          ) : undefined
        }
      />

      {error !== null ? (
        <p className="text-sm text-destructive">{error}</p>
      ) : (
        <DataTable
          columns={columns}
          data={data ?? []}
          getRowId={(row) => String(row.id)}
          isLoading={loading}
          enableGlobalFilter
          globalFilterPlaceholder={t('routeTemplates.searchPlaceholder', 'Search route templates…')}
          pagination={{ pageSize: 10 }}
        />
      )}

      <Dialog open={creating} onOpenChange={setCreating}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('routeTemplates.create.title', 'New route template')}</DialogTitle>
            <DialogDescription>
              {t(
                'routeTemplates.create.description',
                'Give the flow a name. You will draw its stages next.'
              )}
            </DialogDescription>
          </DialogHeader>
          <Input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder={t('routeTemplates.create.placeholder', 'Expense approval')}
            maxLength={160}
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => setCreating(false)}>
              {t('routeTemplates.action.cancel', 'Cancel')}
            </Button>
            <Button onClick={create} disabled={saving || name.trim() === ''}>
              {t('routeTemplates.action.create', 'Create')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Refetching is what the list does after a delete elsewhere returns here;
          the button exists so a stale tab has a way back without a reload. */}
      <button type="button" onClick={refetch} className="sr-only">
        {t('routeTemplates.action.refresh', 'Refresh')}
      </button>
    </div>
  );
}
