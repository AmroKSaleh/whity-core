'use client';

import { useCallback, useMemo, useState } from 'react';
import Link from 'next/link';
import { IconExternalLink, IconMenu2, IconShieldLock } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useDateDisplay } from '@amroksaleh/features/datetime';
import { collectBlockIds } from '@amroksaleh/ui/documents/blocks';
import { Badge } from '@amroksaleh/ui/badge';
import { Button } from '@amroksaleh/ui/button';
import { ErrorState } from '@amroksaleh/ui/empty-state';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@amroksaleh/ui/tabs';
import { Tooltip, TooltipContent, TooltipTrigger } from '@amroksaleh/ui/tooltip';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@amroksaleh/ui/dropdown-menu';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { DOCUMENTS_PUBLISH, DOCUMENTS_WRITE } from '@/lib/capabilities';
import { fetchAllPages } from '@/lib/api/fetch-all-pages';
import { RenameDialog } from './rename-dialog';
import { ScopeDialog } from './scope-dialog';
import { DeleteDialog } from './delete-dialog';
import { UsageDialog } from './usage-dialog';
import { PlacementText, ScopeBadge } from './audience';
import type {
  BlockRow,
  BlockUsage,
  OuOption,
  PermissionOption,
  PermissionSource,
  TemplateRow,
} from './types';

/**
 * Templates & Blocks — the GOVERNANCE surface for the document designer's saved
 * work (the designer itself is the full-screen editor at `/admin/documents`).
 *
 * WHAT THIS SCREEN IS FOR
 * -----------------------
 * The backend for both tables has been complete for a while and had no UI at
 * all: no way to see what exists, rename it, delete it, change who can see it,
 * or find out what a block is used by. Those are five different jobs and only
 * the last one is subtle, so it sets the shape of the whole page.
 *
 * SCOPE IS THE SUBJECT, NOT A COLUMN
 * ----------------------------------
 * The reason this subsystem exists is that a dean's secretary should reach more
 * templates than a department head's secretary — and both of them hold the same
 * `documents:write`, so the difference is not their kind but their PLACE. That
 * fact lives in three columns (`scope`, `owner_ou_id`, `required_permission`)
 * which are individually unreadable, so the table renders all three AND a
 * plain-language "who can see this" sentence built by ./audience.ts, which the
 * scope dialog reuses live as the publisher changes the fields. A screen that
 * listed names without that would hide the only interesting thing about the data.
 *
 * BLOCKS ARE POINTERS, SO USAGE COMES BEFORE EVERY DESTRUCTIVE ACTION
 * ------------------------------------------------------------------
 * A block is referenced by a `blockInstance` element — Gutenberg synced-pattern
 * semantics, so an EDIT propagates to every template using it and is never
 * refused by anything. Delete has a server-side 409 guard; edit has none and can
 * have none. So the blocks table carries a "Used by" column fetched from
 * `GET /document-blocks/{id}/usage`, and both the scope dialog and the delete
 * dialog show it before offering the action. Critically the count is the
 * SERVER's unfiltered total, not a count of templates this caller can see — see
 * the note on `blockUsage` below for why a visible-only count is worse than none.
 *
 * NOT BUILT, DELIBERATELY: user-owned templates. Personal-vs-shared scoping is
 * here because the server already has it; an ownership/sharing model (transfer,
 * share-with-person, "my templates") is a separate product decision and is out
 * of scope.
 */
export default function DocumentTemplatesPage() {
  const { apiClient, user } = useAuth();
  const { addToast } = useToast();
  const { hasPermission, permissions } = useCapabilities();
  const t = useTranslation('admin');
  const dates = useDateDisplay();

  const canWrite = hasPermission(DOCUMENTS_WRITE);
  const canPublish = hasPermission(DOCUMENTS_PUBLISH);
  const viewerProfileId = user?.id ?? null;

  // ── data ────────────────────────────────────────────────────────────────────

  const templates = useFetch<TemplateRow[]>(async () => {
    const response = await apiClient('/api/v1/document-templates');
    if (response.status === 403) {
      throw new ForbiddenError();
    }
    if (!response.ok) {
      const body = (await response.json().catch(() => null)) as { error?: string } | null;
      throw new Error(body?.error ?? t('documentTemplates.error.templates', 'Failed to load templates'));
    }
    const body = (await response.json()) as { data?: TemplateRow[] };
    return body.data ?? [];
  }, [apiClient]);

  /**
   * Blocks AND their usage, resolved together in one fetch.
   *
   * One fetch rather than two because a block list and a stale usage map would
   * render a row's "Used by" beside another row's name for a frame, and the
   * number is the whole safety property.
   *
   * WHY THE COUNT COMES FROM THE SERVER AND NOT FROM `templates`
   * -----------------------------------------------------------
   * Every referencing template's `data` is already in hand from the list above,
   * so this could be computed locally for free. It would also be WRONG in the
   * one direction that matters. The templates list is row-filtered to what the
   * caller may see; the references are not. A department secretary who may edit
   * an unplaced tenant-wide block would be told "used by 1 template" when nine
   * across the faculty instance it, edit with confidence, and silently rewrite
   * eight documents she cannot see. `/usage` answers with the unfiltered `total`
   * and a `hidden` count precisely so that cannot happen.
   *
   * One request per visible block, in parallel. That is an N+1 and it is the
   * honest one: the alternative is a local count that understates the blast
   * radius for exactly the callers whose reach is narrowest. A bulk
   * usage-summary endpoint is the fix if governance inventories get large; a
   * threshold at which the column silently stops being trustworthy is not.
   *
   * A per-block usage failure is recorded as `null` rather than failing the whole
   * list — the row still needs renaming and re-scoping, and the cell says the
   * count is unavailable instead of showing a zero it did not read.
   */
  const blocks = useFetch<{ rows: BlockRow[]; usage: Record<number, BlockUsage | null> }>(
    async () => {
      const response = await apiClient('/api/v1/document-blocks');
      if (response.status === 403) {
        throw new ForbiddenError();
      }
      if (!response.ok) {
        const body = (await response.json().catch(() => null)) as { error?: string } | null;
        throw new Error(body?.error ?? t('documentTemplates.error.blocks', 'Failed to load blocks'));
      }
      const body = (await response.json()) as { data?: BlockRow[] };
      const rows = body.data ?? [];

      const settled = await Promise.all(
        rows.map(async (row): Promise<[number, BlockUsage | null]> => {
          try {
            const res = await apiClient(`/api/v1/document-blocks/${row.id}/usage`);
            if (!res.ok) return [row.id, null];
            const parsed = (await res.json()) as { data?: BlockUsage };
            return [row.id, parsed.data ?? null];
          } catch {
            return [row.id, null];
          }
        })
      );

      const usage: Record<number, BlockUsage | null> = {};
      for (const [id, value] of settled) usage[id] = value;
      return { rows, usage };
    },
    [apiClient]
  );

  /**
   * Unit names, for the placement column and the placement picker.
   *
   * Listing units needs `ous:read`, which this page's own gate
   * (`documents:read`) does not imply — so a 403 here is an ORDINARY state, not
   * a fault. When it happens, placements render as `#4` with a notice saying
   * why, and the placement field in the scope dialog is disabled rather than
   * offering an empty picker that would silently unfile a row.
   */
  const ous = useFetch<OuOption[]>(async () => {
    const result = await fetchAllPages<OuOption>(apiClient, '/api/v1/ous');
    if (!result.complete) {
      throw new Error(t('documentTemplates.error.ous', 'Failed to load the full unit list'));
    }
    return result.items;
  }, [apiClient]);

  /**
   * Options for the permission tag.
   *
   * `GET /api/v1/permissions` is gated on the `admin` ROLE — not on a permission
   * — so a legitimate publisher holding `documents:publish` without being an
   * admin cannot read it. That is not a reason to hide the field; it is a reason
   * to fall back to the caller's own effective permission set, which is always
   * readable and is the safer default anyway: tagging a row with a permission
   * you hold yourself means you can still see what you published, which the
   * policy does not otherwise guarantee (an author's reach is waived for
   * placement, never for the tag).
   *
   * Either way the value written is the SLUG. Never an id — #992 left holes in
   * the id range, so an id means different things on installs of different ages.
   */
  const permissionOptions = useFetch<{ source: PermissionSource; names: string[] }>(
    async () => {
      const response = await apiClient('/api/v1/permissions?per_page=100');
      if (response.ok) {
        const body = (await response.json()) as { data?: PermissionOption[] };
        const names = (body.data ?? [])
          .map((p) => p.name)
          .filter((n): n is string => typeof n === 'string' && n !== '');
        if (names.length > 0) {
          return { source: 'catalogue' as const, names: names.sort() };
        }
      }
      return { source: 'own' as const, names: [...permissions].sort() };
    },
    [apiClient, permissions]
  );

  // ── dialog state ────────────────────────────────────────────────────────────

  type Kind = 'template' | 'block';
  const [renaming, setRenaming] = useState<{ kind: Kind; row: TemplateRow | BlockRow } | null>(null);
  const [scoping, setScoping] = useState<{ kind: Kind; row: TemplateRow | BlockRow } | null>(null);
  const [deleting, setDeleting] = useState<{ kind: Kind; row: TemplateRow | BlockRow } | null>(null);
  const [inspecting, setInspecting] = useState<BlockRow | null>(null);

  const refetchAll = useCallback(() => {
    templates.refetch();
    blocks.refetch();
  }, [templates, blocks]);

  const ouName = useCallback(
    (ouId: number | null): string | null => {
      if (ouId === null) return null;
      return (ous.data ?? []).find((o) => o.id === ouId)?.name ?? null;
    },
    [ous.data]
  );

  // ── shared cells ────────────────────────────────────────────────────────────

  /**
   * The visibility cell: the scope badge, with the audience sentence as its
   * tooltip. The badge alone would be a label; the tooltip is the "and why".
   */
  const visibilityCell = useCallback(
    (row: TemplateRow | BlockRow) => (
      <ScopeBadge row={row} ouName={ouName(row.owner_ou_id)} viewerProfileId={viewerProfileId} />
    ),
    [ouName, viewerProfileId]
  );

  const placementCell = useCallback(
    (row: TemplateRow | BlockRow) => <PlacementText row={row} ouName={ouName(row.owner_ou_id)} />,
    [ouName]
  );

  const permissionCell = useCallback(
    (row: TemplateRow | BlockRow) =>
      row.required_permission ? (
        <Badge variant="purple">{row.required_permission}</Badge>
      ) : (
        <span className="text-muted-foreground">
          {t('documentTemplates.table.noPermission', 'None')}
        </span>
      ),
    [t]
  );

  const rowActions = useCallback(
    (kind: Kind, row: TemplateRow | BlockRow) => (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={t('documentTemplates.rowActions.label', 'Actions for {name}', {
              name: row.name,
            })}
          >
            <IconMenu2 size={16} />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-auto min-w-52">
          {kind === 'block' && (
            <DropdownMenuItem onClick={() => setInspecting(row as BlockRow)}>
              {t('documentTemplates.rowActions.usage', 'What uses this block?')}
            </DropdownMenuItem>
          )}
          <DropdownMenuItem onClick={() => setRenaming({ kind, row })} disabled={!canWrite}>
            {t('documentTemplates.rowActions.rename', 'Rename…')}
          </DropdownMenuItem>
          {/* Every field in this dialog is a publish action server-side —
              including placement, even on a personal row — so the whole entry is
              gated rather than opening a dialog whose Save always 403s. */}
          <DropdownMenuItem onClick={() => setScoping({ kind, row })} disabled={!canPublish}>
            {t('documentTemplates.rowActions.scope', 'Change who can see this…')}
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem
            onClick={() => setDeleting({ kind, row })}
            disabled={!canWrite}
            variant="destructive"
          >
            {t('documentTemplates.rowActions.delete', 'Delete…')}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    ),
    [canWrite, canPublish, t]
  );

  // ── templates tab ───────────────────────────────────────────────────────────

  const templateRows = useMemo(() => {
    const visibleBlockIds = new Set((blocks.data?.rows ?? []).map((b) => String(b.id)));
    return (templates.data ?? []).map((row) => {
      const referenced = collectBlockIds(row.data);
      return {
        ...row,
        // Split rather than one count: an id that resolves to no VISIBLE block is
        // NOT necessarily a deleted block — it may simply be one this caller
        // cannot see — so it is reported as unresolved, never as broken.
        blocksUsed: referenced.length,
        blocksUnresolved: referenced.filter((id) => !visibleBlockIds.has(id)).length,
      };
    });
  }, [templates.data, blocks.data]);

  type TemplateViewRow = (typeof templateRows)[number];

  const templateColumns: DataTableColumn<TemplateViewRow>[] = useMemo<
    DataTableColumn<TemplateViewRow>[]
  >(
    () => [
      {
        accessorKey: 'name',
        header: t('documentTemplates.table.name', 'Name'),
        enableSorting: true,
        enableColumnFilter: true,
        cell: (row) => (
          <span className="font-medium">
            {row.name}
            {row.is_system && (
              <Badge variant="outline" className="ms-2">
                {t('documentTemplates.table.starter', 'Starter')}
              </Badge>
            )}
          </span>
        ),
      },
      {
        id: 'visibility',
        header: t('documentTemplates.table.visibility', 'Visible to'),
        enableSorting: false,
        cell: visibilityCell,
      },
      {
        id: 'placement',
        header: t('documentTemplates.table.placement', 'Filed at'),
        cell: placementCell,
      },
      {
        id: 'permission',
        header: t('documentTemplates.table.permission', 'Requires'),
        cell: permissionCell,
      },
      {
        id: 'blocksUsed',
        header: t('documentTemplates.table.blocksUsed', 'Blocks used'),
        cell: (row) =>
          row.blocksUsed === 0 ? (
            <span className="text-muted-foreground">
              {t('documentTemplates.table.noBlocks', 'None')}
            </span>
          ) : (
            <span>
              {t('documentTemplates.table.blockCount', '{count}', { count: row.blocksUsed })}
              {row.blocksUnresolved > 0 && (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Badge variant="warning" className="ms-2">
                      {t('documentTemplates.table.unresolved', '{count} unresolved', {
                        count: row.blocksUnresolved,
                      })}
                    </Badge>
                  </TooltipTrigger>
                  <TooltipContent>
                    {t(
                      'documentTemplates.table.unresolvedHint',
                      'This template points at a block that is not in your list. Either it was deleted, or it is one you cannot see — those look the same from here.'
                    )}
                  </TooltipContent>
                </Tooltip>
              )}
            </span>
          ),
      },
      // #1068: the "Updated" column goes when this tenant hides dates — on
      // both tabs, from one call. A template list is navigated by NAME; the
      // stamp is context, and a column of em dashes under "Updated" is worse
      // than the column not being there.
      ...dates.dateColumns<TemplateViewRow>([
        {
          id: 'updated_at',
          header: t('documentTemplates.table.updated', 'Updated'),
          value: (row) => row.updated_at,
          enableSorting: true,
          className: 'text-muted-foreground',
        },
      ]),
    ],
    [t, dates, visibilityCell, placementCell, permissionCell]
  );

  // ── blocks tab ──────────────────────────────────────────────────────────────

  const blockRows = blocks.data?.rows ?? [];
  // Memoised so the `?? {}` fallback is not a fresh object on every render — it
  // is a dependency of the column definitions below, and an unstable one would
  // rebuild them (and every cell) on each paint.
  const blockUsage = useMemo(() => blocks.data?.usage ?? {}, [blocks.data]);

  const blockColumns: DataTableColumn<BlockRow>[] = useMemo<DataTableColumn<BlockRow>[]>(
    () => [
      {
        accessorKey: 'name',
        header: t('documentTemplates.table.name', 'Name'),
        enableSorting: true,
        enableColumnFilter: true,
        cell: (row) => <span className="font-medium">{row.name}</span>,
      },
      {
        id: 'visibility',
        header: t('documentTemplates.table.visibility', 'Visible to'),
        cell: visibilityCell,
      },
      {
        id: 'placement',
        header: t('documentTemplates.table.placement', 'Filed at'),
        cell: placementCell,
      },
      {
        id: 'permission',
        header: t('documentTemplates.table.permission', 'Requires'),
        cell: permissionCell,
      },
      {
        id: 'usedBy',
        header: t('documentTemplates.table.usedBy', 'Used by'),
        cell: (row) => {
          if (blocks.loading) return <Skeleton className="h-4 w-16" />;
          const usage = blockUsage[row.id];
          if (usage === null || usage === undefined) {
            return (
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="cursor-help text-muted-foreground underline decoration-dotted">
                    {t('documentTemplates.table.usageUnknown', 'Unknown')}
                  </span>
                </TooltipTrigger>
                <TooltipContent>
                  {t(
                    'documentTemplates.table.usageUnknownHint',
                    'The usage count could not be read. Treat editing or deleting this block as unsafe until it can — a blank is not a zero.'
                  )}
                </TooltipContent>
              </Tooltip>
            );
          }
          if (usage.total === 0) {
            return (
              <span className="text-muted-foreground">
                {t('documentTemplates.table.usedByNone', 'Nothing')}
              </span>
            );
          }
          return (
            <button
              type="button"
              onClick={() => setInspecting(row)}
              className="text-start font-medium text-primary underline-offset-4 hover:underline"
            >
              {t('documentTemplates.table.usedByCount', '{count} templates', {
                count: usage.total,
              })}
              {usage.hidden > 0 && (
                <Badge variant="warning" className="ms-2">
                  {t('documentTemplates.table.hiddenCount', '{count} you cannot see', {
                    count: usage.hidden,
                  })}
                </Badge>
              )}
            </button>
          );
        },
      },
      // #1068: the "Updated" column goes when this tenant hides dates — on
      // both tabs, from one call. A template list is navigated by NAME; the
      // stamp is context, and a column of em dashes under "Updated" is worse
      // than the column not being there.
      ...dates.dateColumns<BlockRow>([
        {
          id: 'updated_at',
          header: t('documentTemplates.table.updated', 'Updated'),
          value: (row) => row.updated_at,
          enableSorting: true,
          className: 'text-muted-foreground',
        },
      ]),
    ],
    [t, dates, visibilityCell, placementCell, permissionCell, blockUsage, blocks.loading]
  );

  // ── render ──────────────────────────────────────────────────────────────────

  const forbidden = isForbidden(templates.error) || isForbidden(blocks.error);
  const accessDenied = forbidden ? (
    <ErrorState
      icon={<IconShieldLock />}
      title={t('documentTemplates.forbidden.title', 'Access denied')}
      description={t(
        'documentTemplates.forbidden.description',
        'You need the documents:read permission to manage templates and blocks.'
      )}
    />
  ) : undefined;

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('documentTemplates.title', 'Templates & Blocks')}
        description={t(
          'documentTemplates.description',
          'Who can see each saved template and reusable block, where it is filed in the organisation, and what a block is used by. Editing a block changes every template that uses it.'
        )}
        action={
          <Button variant="outline" asChild className="gap-2">
            <Link href="/admin/documents">
              <IconExternalLink size={16} />
              {t('documentTemplates.openDesigner', 'Open designer')}
            </Link>
          </Button>
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

      <Tabs defaultValue="templates">
        <TabsList>
          <TabsTrigger value="templates">
            {t('documentTemplates.tab.templates', 'Templates')}
          </TabsTrigger>
          <TabsTrigger value="blocks">{t('documentTemplates.tab.blocks', 'Blocks')}</TabsTrigger>
        </TabsList>

        <TabsContent value="templates" className="pt-4">
          <DataTable
            columns={templateColumns}
            data={templateRows}
            getRowId={(row) => String(row.id)}
            rowActions={(row) => rowActions('template', row)}
            isLoading={templates.loading}
            overrideContent={accessDenied}
            ariaLabel={t('documentTemplates.tab.templates', 'Templates')}
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
                ? {
                    title: t('documentTemplates.error.templates', 'Failed to load templates'),
                    description: templates.error,
                  }
                : undefined
            }
            pagination={{ pageSize: 25 }}
          />
        </TabsContent>

        <TabsContent value="blocks" className="pt-4">
          <DataTable
            columns={blockColumns}
            data={blockRows}
            getRowId={(row) => String(row.id)}
            rowActions={(row) => rowActions('block', row)}
            isLoading={blocks.loading}
            overrideContent={accessDenied}
            ariaLabel={t('documentTemplates.tab.blocks', 'Blocks')}
            enableGlobalFilter
            globalFilterPlaceholder={t('documentTemplates.searchBlocks', 'Search blocks…')}
            emptyState={{
              title: t('documentTemplates.empty.blocks.title', 'No blocks you can see'),
              description: t(
                'documentTemplates.empty.blocks.description',
                'Blocks are scoped the same way templates are. Create one from a selection in the designer.'
              ),
            }}
            errorState={
              blocks.error !== null && !forbidden
                ? {
                    title: t('documentTemplates.error.blocks', 'Failed to load blocks'),
                    description: blocks.error,
                  }
                : undefined
            }
            pagination={{ pageSize: 25 }}
          />
        </TabsContent>
      </Tabs>

      {renaming !== null && (
        <RenameDialog
          kind={renaming.kind}
          row={renaming.row}
          apiClient={apiClient}
          addToast={addToast}
          onClose={() => setRenaming(null)}
          onSaved={() => {
            setRenaming(null);
            refetchAll();
          }}
        />
      )}

      {scoping !== null && (
        <ScopeDialog
          kind={scoping.kind}
          row={scoping.row}
          usage={scoping.kind === 'block' ? (blockUsage[scoping.row.id] ?? null) : null}
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
            refetchAll();
          }}
        />
      )}

      {deleting !== null && (
        <DeleteDialog
          kind={deleting.kind}
          row={deleting.row}
          usage={deleting.kind === 'block' ? (blockUsage[deleting.row.id] ?? null) : null}
          ouName={ouName(deleting.row.owner_ou_id)}
          apiClient={apiClient}
          addToast={addToast}
          onClose={() => setDeleting(null)}
          onDeleted={() => {
            setDeleting(null);
            refetchAll();
          }}
        />
      )}

      {inspecting !== null && (
        <UsageDialog
          block={inspecting}
          usage={blockUsage[inspecting.id] ?? null}
          ouName={ouName}
          onClose={() => setInspecting(null)}
        />
      )}
    </div>
  );
}

/**
 * A 403 carried through `useFetch`, which flattens rejections to a message
 * string. Marked with a recognisable message so the page can render the
 * access-denied state instead of an error toast loop — the delegations page's
 * pattern, adapted to a fetcher that throws rather than returning a status.
 */
const FORBIDDEN_MARKER = 'documents:read forbidden';

class ForbiddenError extends Error {
  constructor() {
    super(FORBIDDEN_MARKER);
  }
}

function isForbidden(error: string | null): boolean {
  return error === FORBIDDEN_MARKER;
}


