'use client';

import { useCallback, useMemo, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { IconMenu2, IconShieldLock } from '@tabler/icons-react';
import { useTranslation } from '@whity-org/features/i18n';
import { useDateDisplay } from '@whity-org/features/datetime';
import { Badge } from '@whity-org/ui/badge';
import { Button } from '@whity-org/ui/button';
import { ErrorState } from '@whity-org/ui/empty-state';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@whity-org/ui/dropdown-menu';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { DOCUMENTS_ISSUE, DOCUMENTS_PUBLISH, DOCUMENTS_WRITE } from '@/lib/capabilities';
import { issueHref } from '@/lib/documents/issue';
import { fetchAllPages } from '@/lib/api/fetch-all-pages';
import { createDocumentSource } from '@/lib/documents/sources';
import { ScopeDialog } from './scope-dialog';
import { DeleteDialog } from './delete-dialog';
import { CopyDialog } from './copy-dialog';
import { ScopeBadge } from './audience';
import { ForbiddenError, editorHref, listTemplates } from './api';
import type { OuOption, PermissionOption, PermissionSource, TemplateRow } from './types';

/**
 * Document templates (A31) — the editor templates and label sheets documents
 * are issued from, and who can see each one.
 *
 * Since editor M4c every template is an editor document (`document_sources`,
 * kinds `template` and `label`): the old canvas designer's templates were
 * converted, and are marked "Converted" here so their authors can find and
 * check them. Templates are written and renamed in the editor (a title is draft
 * content, E.9); this page lists them, opens them, copies them and governs who
 * can see them.
 *
 * WHO CAN SEE IT is the column worth reading. A dean's secretary and a
 * department head's secretary hold the same permissions and still reach
 * different templates, because of where each template is filed. That fact lives
 * in three columns (`scope`, `owner_ou_id`, `required_permission`), so the
 * visibility badge carries the plain-language sentence from ./audience.tsx as
 * its tooltip, and the scope dialog shows the same sentence live.
 *
 * Saved blocks were part of the old designer and come back in M7.
 */
export default function DocumentTemplatesPage() {
  const { apiClient, user } = useAuth();
  const { addToast } = useToast();
  const router = useRouter();
  const { hasPermission, permissions } = useCapabilities();
  const t = useTranslation('admin');
  const dates = useDateDisplay();

  const canWrite = hasPermission(DOCUMENTS_WRITE);
  const canPublish = hasPermission(DOCUMENTS_PUBLISH);
  const canIssue = hasPermission(DOCUMENTS_ISSUE);
  const viewerProfileId = user?.id ?? null;

  // ── data ────────────────────────────────────────────────────────────────────

  const templates = useFetch<TemplateRow[]>(
    () => listTemplates(apiClient, t('documentTemplates.error.templates', 'Failed to load templates')),
    [apiClient]
  );

  /**
   * Record type names ("Invoice" rather than `invoice`). Best effort: when the
   * catalogue cannot be read the key is shown as it is.
   */
  const recordTypes = useFetch<Record<string, string>>(async () => {
    const response = await apiClient('/api/v1/document-embeds');
    if (!response.ok) return {};
    const body = (await response.json().catch(() => null)) as {
      data?: { record_types?: { key: string; label: string }[] };
    } | null;
    return Object.fromEntries((body?.data?.record_types ?? []).map((r) => [r.key, r.label]));
  }, [apiClient]);

  /**
   * Unit names, for the audience sentence and the placement picker. Listing
   * units needs `ous:read`, which this page's own gate does not imply, so a 403
   * here is an ordinary state: units show by id and placement is locked.
   */
  const ous = useFetch<OuOption[]>(async () => {
    const result = await fetchAllPages<OuOption>(apiClient, '/api/v1/ous');
    if (!result.complete) {
      throw new Error(t('documentTemplates.error.ous', 'Failed to load the full unit list'));
    }
    return result.items;
  }, [apiClient]);

  /**
   * Options for the permission tag: the catalogue when the caller may read it
   * (admin role), otherwise the caller's own permissions. Either way the value
   * written is the slug, never an id.
   */
  const permissionOptions = useFetch<{ source: PermissionSource; names: string[] }>(async () => {
    const response = await apiClient('/api/v1/permissions?per_page=100');
    if (response.ok) {
      const body = (await response.json()) as { data?: PermissionOption[] };
      const names = (body.data ?? []).map((p) => p.name).filter((n): n is string => typeof n === 'string' && n !== '');
      if (names.length > 0) return { source: 'catalogue' as const, names: names.sort() };
    }
    return { source: 'own' as const, names: [...permissions].sort() };
  }, [apiClient, permissions]);

  // ── dialog state ────────────────────────────────────────────────────────────

  const [copying, setCopying] = useState<TemplateRow | null>(null);
  const [scoping, setScoping] = useState<TemplateRow | null>(null);
  const [deleting, setDeleting] = useState<TemplateRow | null>(null);
  const [creating, setCreating] = useState(false);

  const nameOf = useCallback(
    (row: TemplateRow) => (row.title.trim() === '' ? t('documentTemplates.untitled', 'Untitled') : row.title),
    [t]
  );

  const ouName = useCallback(
    (ouId: number | null): string | null => {
      if (ouId === null) return null;
      return (ous.data ?? []).find((o) => o.id === ouId)?.name ?? null;
    },
    [ous.data]
  );

  const createTemplate = async () => {
    setCreating(true);
    try {
      const row = await createDocumentSource('template', '');
      router.push(editorHref(row.id));
    } catch {
      addToast(t('documentTemplates.create.failed', 'The template wasn’t created. Try again.'), 'error');
      setCreating(false);
    }
  };

  // ── table ───────────────────────────────────────────────────────────────────

  const recordTypeNames = recordTypes.data;

  const columns = useMemo<DataTableColumn<TemplateRow>[]>(
    () => [
      {
        accessorKey: 'title',
        header: t('documentTemplates.table.title', 'Title'),
        enableSorting: true,
        enableColumnFilter: true,
        cell: (row) => (
          <span className="flex flex-wrap items-center gap-2">
            <Link
              href={editorHref(row.id)}
              dir="auto"
              className="font-medium text-foreground underline-offset-4 hover:underline"
            >
              {nameOf(row)}
            </Link>
            {row.starter_key !== null && (
              <Badge variant="outline">{t('documentTemplates.badge.starter', 'Whity starter')}</Badge>
            )}
            {row.legacy_template_id !== null && (
              <Badge variant="outline">{t('documentTemplates.badge.converted', 'Converted')}</Badge>
            )}
          </span>
        ),
      },
      {
        id: 'kind',
        header: t('documentTemplates.table.kind', 'Kind'),
        cell: (row) =>
          row.kind === 'label'
            ? t('documentTemplates.kind.label', 'Label sheet')
            : t('documentTemplates.kind.template', 'Template'),
      },
      {
        id: 'recordType',
        header: t('documentTemplates.table.recordType', 'Record type'),
        cell: (row) =>
          row.record_type === null ? (
            <span className="text-muted-foreground">{t('documentTemplates.table.noRecordType', 'None')}</span>
          ) : (
            (recordTypeNames?.[row.record_type] ?? row.record_type)
          ),
      },
      {
        id: 'status',
        header: t('documentTemplates.table.status', 'Status'),
        cell: (row) =>
          row.published_version === null ? (
            <Badge variant="secondary">{t('documentTemplates.status.draft', 'Draft')}</Badge>
          ) : (
            <Badge variant="success">
              {t('documentTemplates.status.published', 'Published v{version}', { version: row.published_version })}
            </Badge>
          ),
      },
      {
        id: 'visibility',
        header: t('documentTemplates.table.visibility', 'Visible to'),
        cell: (row) => (
          <ScopeBadge row={row} ouName={ouName(row.owner_ou_id)} viewerProfileId={viewerProfileId} />
        ),
      },
      // The "Updated" column goes when this tenant hides dates (#1068).
      ...dates.dateColumns<TemplateRow>([
        {
          id: 'updated_at',
          header: t('documentTemplates.table.updated', 'Updated'),
          value: (row) => row.updated_at,
          enableSorting: true,
          className: 'text-muted-foreground',
        },
      ]),
    ],
    [t, dates, nameOf, ouName, viewerProfileId, recordTypeNames]
  );

  const rowActions = (row: TemplateRow) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={t('documentTemplates.rowActions.label', 'Actions for {name}', { name: nameOf(row) })}
        >
          <IconMenu2 size={16} />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-auto min-w-52">
        <DropdownMenuItem onClick={() => router.push(editorHref(row.id))}>
          {t('documentTemplates.rowActions.open', 'Open')}
        </DropdownMenuItem>
        {/* Issuing opens the editor on the published version (M4c); a label
            sheet prints labels instead, so it has no document to issue. */}
        {row.kind === 'template' && row.published_version !== null && canIssue && (
          <DropdownMenuItem onClick={() => router.push(issueHref(row.id))}>
            {t('documentTemplates.rowActions.issue', 'Issue a document')}
          </DropdownMenuItem>
        )}
        {/* Copying needs write, not publish: the copy lands personal, the one
            scope everybody with write already has. */}
        <DropdownMenuItem onClick={() => setCopying(row)} disabled={!canWrite}>
          {t('documentTemplates.rowActions.copy', 'Use a copy…')}
        </DropdownMenuItem>
        {/* Every field in the scope dialog is a publish action server-side,
            so the entry is gated rather than opening a dialog whose save 403s. */}
        <DropdownMenuItem onClick={() => setScoping(row)} disabled={!canPublish}>
          {t('documentTemplates.rowActions.audience', 'Who can see it…')}
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem onClick={() => setDeleting(row)} disabled={!canWrite} variant="destructive">
          {t('documentTemplates.rowActions.delete', 'Delete…')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );

  // ── render ──────────────────────────────────────────────────────────────────

  const forbidden = templates.error === new ForbiddenError().message;

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('documentTemplates.title', 'Document templates')}
        description={t(
          'documentTemplates.subtitle',
          'The templates and label sheets documents are issued from, and who can see each one. A document is issued from the published version.'
        )}
        action={
          canWrite ? (
            <Button icon="plus" loading={creating} disabled={creating} onClick={() => void createTemplate()}>
              {t('documentTemplates.new', 'New template')}
            </Button>
          ) : undefined
        }
      />

      {ous.error !== null && (
        <p className="text-sm text-muted-foreground">
          {t(
            'documentTemplates.ous.unavailable',
            'Unit names could not be loaded, so units are shown by id and placements cannot be changed. Listing units needs the ous:read permission.'
          )}
        </p>
      )}

      <DataTable
        columns={columns}
        data={templates.data ?? []}
        getRowId={(row) => String(row.id)}
        rowActions={rowActions}
        isLoading={templates.loading}
        overrideContent={
          forbidden ? (
            <ErrorState
              icon={<IconShieldLock />}
              title={t('documentTemplates.forbidden.title', 'Access denied')}
              description={t(
                'documentTemplates.forbidden.templates',
                'You need the documents:read permission to see document templates.'
              )}
            />
          ) : undefined
        }
        ariaLabel={t('documentTemplates.title', 'Document templates')}
        enableGlobalFilter
        globalFilterPlaceholder={t('documentTemplates.searchTemplates', 'Search templates…')}
        emptyState={{
          title: t('documentTemplates.empty.templates.title', 'No templates you can see'),
          description: t(
            'documentTemplates.empty.templates.description',
            'Templates are filtered by where you stand in the organisation and what you hold, so an empty list can also mean there are templates filed elsewhere.'
          ),
        }}
        errorState={
          templates.error !== null && !forbidden
            ? { title: t('documentTemplates.error.templates', 'Failed to load templates'), description: templates.error }
            : undefined
        }
        pagination={{ pageSize: 25 }}
      />

      {copying !== null && (
        <CopyDialog
          row={copying}
          name={nameOf(copying)}
          apiClient={apiClient}
          onClose={() => setCopying(null)}
          onCopied={(id) => {
            setCopying(null);
            router.push(editorHref(id));
          }}
        />
      )}

      {scoping !== null && (
        <ScopeDialog
          row={scoping}
          name={nameOf(scoping)}
          ous={ous.data ?? []}
          ousUnavailable={ous.error !== null}
          permissionNames={permissionOptions.data?.names ?? []}
          permissionSource={permissionOptions.data?.source ?? 'own'}
          viewerProfileId={viewerProfileId}
          apiClient={apiClient}
          addToast={addToast}
          onClose={() => setScoping(null)}
          onSaved={() => {
            setScoping(null);
            templates.refetch();
          }}
        />
      )}

      {deleting !== null && (
        <DeleteDialog
          row={deleting}
          name={nameOf(deleting)}
          ouName={ouName(deleting.owner_ou_id)}
          apiClient={apiClient}
          addToast={addToast}
          onClose={() => setDeleting(null)}
          onDeleted={() => {
            setDeleting(null);
            templates.refetch();
          }}
        />
      )}
    </div>
  );
}
