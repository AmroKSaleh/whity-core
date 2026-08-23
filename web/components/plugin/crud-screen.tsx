'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { apiClient } from '@/lib/api-client';
import {
  deriveCrudModel,
  effectiveCapabilities,
  fetchSpec,
  type CrudField,
  type CrudModel,
} from '@/lib/plugin-crud-schema';
import type { PluginFeature } from '@/lib/plugin-features';
import {
  CrudFields,
  preferredLocalizedText,
  toLocalizedTextValue,
  useCrudForm,
  type CrudRow,
} from '@/components/plugin/crud-form';
import { recordHref } from '@/lib/plugin-record-route';
import { useToast } from '@/lib/toast-context';
import { useDirection } from '@/lib/direction-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type Column } from '@/components/admin/data-table';
import { Button } from '@amroksaleh/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@amroksaleh/ui/dropdown-menu';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import { ErrorState } from '@amroksaleh/ui/empty-state';
import {
  IconAlertTriangle,
  IconMenu2,
  IconPlus,
  IconShieldLock,
} from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';

/**
 * i18n (domain `plugin`): only the CHROME this file authors is keyed. The
 * schema-derived model is plugin DATA and is rendered verbatim — every
 * `field.label`, `column.label`, `select` option, `feature.label`,
 * `feature.plugin`, `feature.requiredPermission`, the titleField row title,
 * and any `{ error }` message the backend returns.
 */

/** Narrow raw list items to rows; entries without a usable id are dropped. */
function toRows(items: unknown[]): CrudRow[] {
  const rows: CrudRow[] = [];
  for (const item of items) {
    if (typeof item !== 'object' || item === null) {
      continue;
    }
    const record = item as Record<string, unknown>;
    const id = record['id'];
    if (typeof id !== 'string' && typeof id !== 'number') {
      continue;
    }
    rows.push({ ...record, id });
  }
  return rows;
}

/**
 * Extract the backend's `{ error: string }` message from a failed response,
 * falling back when the body is absent or not JSON (a non-ok response without
 * a JSON body is still an error).
 */
async function readErrorMessage(
  response: Response,
  fallback: string
): Promise<string> {
  try {
    const body: unknown = await response.json();
    if (typeof body === 'object' && body !== null && 'error' in body) {
      const message = (body as { error: unknown }).error;
      if (typeof message === 'string' && message.length > 0) {
        return message;
      }
    }
  } catch {
    // No JSON body — use the fallback.
  }
  return fallback;
}

interface CrudCreateDialogProps {
  title: string;
  description: string;
  fields: CrudField[];
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  submitLabel: string;
  busyLabel: string;
  /** Performs the mutation; resolves true on success (parent closes/refetches). */
  onSubmit: (payload: Record<string, unknown>) => Promise<boolean>;
}

/**
 * The CREATE dialog, built from the derived schema fields (#948 narrowed it
 * from create-and-edit to create).
 *
 * Editing left: a record has an address now, so the Edit row action navigates
 * to `/admin/x/[featureId]/[recordId]` and the record page renders the same
 * fields with room to breathe. Creation stays a dialog, and the asymmetry is
 * the point — a record that does not exist yet has no id, so there is no URL
 * to send anybody, nothing to bookmark, and nothing to come back to. An
 * overlay is the honest shape for a transient thing.
 *
 * The parent remounts it via `key` on each open, so plain useState defaults
 * reset without a synchronous setState in an effect.
 *
 * `title`, `description`, `submitLabel` and `busyLabel` are the CALLER's
 * strings and are never keyed here — the caller owns their wording (and keys
 * them itself where they are ours).
 */
function CrudCreateDialog({
  title,
  description,
  fields,
  isOpen,
  onOpenChange,
  submitLabel,
  busyLabel,
  onSubmit,
}: CrudCreateDialogProps) {
  const t = useTranslation('plugin');
  const form = useCrudForm(fields, null);

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>

        <div className="py-2">
          <CrudFields
            fields={fields}
            values={form.values}
            errors={form.errors}
            onChange={form.setValue}
          />
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={form.isSubmitting}
          >
            {t('crud.dialog.cancel', 'Cancel')}
          </Button>
          <Button
            type="button"
            onClick={() => void form.submit(onSubmit)}
            disabled={form.isSubmitting}
          >
            {form.isSubmitting ? busyLabel : submitLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

interface CrudDeleteDialogProps {
  resourceLabel: string;
  /** titleField value (or #id fallback) identifying the row being deleted. */
  itemLabel: string;
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  /** Performs the deletion; resolves true on success. */
  onConfirm: () => Promise<boolean>;
}

/** Destructive confirmation dialog, mirroring the users delete-modal anatomy. */
function CrudDeleteDialog({
  resourceLabel,
  itemLabel,
  isOpen,
  onOpenChange,
  onConfirm,
}: CrudDeleteDialogProps) {
  const t = useTranslation('plugin');
  const [isDeleting, setIsDeleting] = useState(false);

  const handleConfirm = async () => {
    try {
      setIsDeleting(true);
      await onConfirm();
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          {/* `resourceLabel` is the caller's (plugin-supplied) noun; the
              sentence around it is ours, so it stays one unit with a hole. */}
          <DialogTitle>
            {t('crud.delete.title', 'Delete {resource}', { resource: resourceLabel })}
          </DialogTitle>
          <DialogDescription>
            {t(
              'crud.delete.description',
              'Are you sure you want to delete this item? This action cannot be undone.'
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 py-4">
          <div className="rounded-lg bg-muted p-3">
            <div className="text-sm font-medium text-foreground">
              {itemLabel}
            </div>
          </div>
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isDeleting}
          >
            {t('crud.dialog.cancel', 'Cancel')}
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={handleConfirm}
            disabled={isDeleting}
          >
            {isDeleting
              ? t('crud.delete.pending', 'Deleting...')
              : t('crud.delete.submit', 'Delete')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Schema-driven CRUD screen for a plugin feature (WC-169).
 *
 * On mount it fetches the public OpenAPI document (same-origin proxy) and the
 * feature's list endpoint in parallel, derives the table/form model from the
 * spec, and renders the standard admin list, the create dialog and the delete
 * confirmation. Write controls render only when the spec publishes the
 * operation AND the server reports the caller may perform it (issue #199), so a
 * read-only delegated caller never sees a control whose submit would 403; a 403
 * on the list still renders the access-denied card.
 *
 * EDITING IS A NAVIGATION (#948). The Edit row action goes to
 * `/admin/x/[featureId]/[recordId]`, the record's own address, rather than
 * opening a dialog over the list. It applies to EVERY crud feature and needs no
 * plugin declaration to do so: the record page is derived from the same OpenAPI
 * document this screen derives its table from, so a plugin gets record pages
 * for the resource it already published, without shipping a line of JavaScript.
 */
export function CrudScreen({ feature }: { feature: PluginFeature }) {
  const { addToast } = useToast();
  const { dir } = useDirection();
  const router = useRouter();
  const t = useTranslation('plugin');
  const basePath = feature.resource?.basePath ?? null;

  // Resolved at render rather than inside the load effect below. A STRING
  // dependency is compared by value, so the list is not re-fetched merely
  // because `t` took a new identity when the bundle arrived — only if the text
  // itself actually changed (a language switch).
  const schemaErrorText = t(
    'crud.error.schema',
    'Failed to load the API schema for this feature'
  );
  const listErrorText = t('crud.error.list', 'Failed to load records');
  const listShapeErrorText = t('crud.error.listShape', 'Unexpected list response shape');

  const [model, setModel] = useState<CrudModel | null>(null);
  const [rows, setRows] = useState<CrudRow[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isForbidden, setIsForbidden] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [isDeleteOpen, setIsDeleteOpen] = useState(false);
  const [selected, setSelected] = useState<CrudRow | null>(null);

  useEffect(() => {
    if (basePath === null) {
      return;
    }

    // Fetchers live inside the effect so no setState runs synchronously in
    // the effect body (react-hooks/set-state-in-effect).
    const load = async (): Promise<void> => {
      setIsLoading(true);
      try {
        const [spec, listResponse] = await Promise.all([
          fetchSpec(),
          apiClient(basePath),
        ]);

        if (spec === null) {
          setModel(null);
          addToast(schemaErrorText, 'error');
        } else {
          setModel(deriveCrudModel(spec, basePath));
        }

        if (listResponse.status === 403) {
          setIsForbidden(true);
          setRows([]);
          return;
        }
        setIsForbidden(false);

        if (!listResponse.ok) {
          // The backend's own `{ error }` message wins when present; only our
          // fallback is keyed.
          throw new Error(await readErrorMessage(listResponse, listErrorText));
        }

        const body: unknown = await listResponse.json();
        const data =
          typeof body === 'object' && body !== null && 'data' in body
            ? (body as { data: unknown }).data
            : null;
        if (!Array.isArray(data)) {
          throw new Error(listShapeErrorText);
        }
        setRows(toRows(data));
      } catch (error) {
        const message = error instanceof Error ? error.message : listErrorText;
        addToast(message, 'error');
      } finally {
        setIsLoading(false);
      }
    };

    void load();
  }, [
    basePath,
    reloadKey,
    addToast,
    schemaErrorText,
    listErrorText,
    listShapeErrorText,
  ]);

  // Guard: callers only render CrudScreen for crud features with a resource,
  // but a defensive placeholder beats a crash if that invariant slips.
  if (basePath === null) {
    return (
      <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
        <IconAlertTriangle
          size={32}
          className="mx-auto mb-3 text-muted-foreground"
        />
        <h2 className="font-heading text-sm font-medium">
          {t('crud.noResource.title', 'No resource')}
        </h2>
        <p className="mt-1 text-xs text-muted-foreground">
          {t(
            'crud.noResource.description',
            "The '{id}' feature does not declare a REST resource to render.",
            { id: feature.id }
          )}
        </p>
      </div>
    );
  }

  const refetch = () => setReloadKey((key) => key + 1);

  const rowTitle = (row: CrudRow): string => {
    const titleField = feature.resource?.titleField;
    if (titleField) {
      const value = row[titleField];
      if (typeof value === 'string' && value.length > 0) {
        return value;
      }
      if (typeof value === 'number') {
        return String(value);
      }
    }
    return `#${String(row.id)}`;
  };

  const handleCreate = async (
    payload: Record<string, unknown>
  ): Promise<boolean> => {
    const response = await apiClient(basePath, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    if (!response.ok) {
      addToast(
        await readErrorMessage(response, t('crud.error.create', 'Failed to create record')),
        'error'
      );
      return false;
    }
    addToast(t('crud.toast.created', 'Record created successfully'), 'success');
    setIsCreateOpen(false);
    refetch();
    return true;
  };

  const handleDelete = async (): Promise<boolean> => {
    if (selected === null) {
      return false;
    }
    const response = await apiClient(
      `${basePath}/${encodeURIComponent(String(selected.id))}`,
      { method: 'DELETE' }
    );
    if (!response.ok) {
      addToast(
        await readErrorMessage(response, t('crud.error.delete', 'Failed to delete record')),
        'error'
      );
      return false;
    }
    addToast(t('crud.toast.deleted', 'Record deleted successfully'), 'success');
    setIsDeleteOpen(false);
    setSelected(null);
    refetch();
    return true;
  };

  const description = t(
    'crud.header.description',
    'Manage {resource} provided by the {plugin} plugin.',
    { resource: feature.label.toLowerCase(), plugin: feature.plugin }
  );

  if (isForbidden) {
    return (
      <div className="space-y-8">
        <AdminHeader title={feature.label} description={description} />
        <ErrorState
          icon={<IconShieldLock />}
          title={t('crud.forbidden.title', 'Access denied')}
          description={t(
            'crud.forbidden.description',
            'You need the {permission} permission to use this feature.',
            { permission: feature.requiredPermission }
          )}
        />
      </div>
    );
  }

  // A write control renders only when the spec defines the operation AND the
  // caller is permitted to perform it (issue #199) — otherwise the submit would
  // 403. AND the spec-derived capability with the server's per-caller one; a
  // null model (spec failed to load) yields the all-false fallback.
  const capabilities = effectiveCapabilities(
    model?.capabilities,
    feature.capabilities
  );

  const columns: Column<CrudRow>[] = (model?.columns ?? []).map((column) => ({
    key: column.key,
    label: column.label,
    sortable: true,
  }));

  // WC-532: the thin admin DataTable adapter renders cells via String(...) —
  // a raw {ar,en} LocalizedText value would show "[object Object]". Build a
  // DISPLAY-only row set with those columns replaced by the dir-preferred
  // string (falling back to the other language, or a literal "untranslated"
  // marker when both are empty). `rowActions`/edit below look the ORIGINAL
  // row back up by id, so editing still seeds the real {ar,en} object.
  const localizedColumnKeys = (model?.columns ?? [])
    .filter((column) => column.isLocalizedText)
    .map((column) => column.key);

  const displayRows: CrudRow[] =
    localizedColumnKeys.length === 0
      ? rows
      : rows.map((row) => {
          const displayRow: CrudRow = { ...row };
          for (const key of localizedColumnKeys) {
            const preferred = preferredLocalizedText(toLocalizedTextValue(row[key]), dir);
            displayRow[key] = preferred ?? t('crud.localizedText.untranslated', 'Untranslated');
          }
          return displayRow;
        });

  const rowActions =
    capabilities.canEdit || capabilities.canDelete
      ? (displayRow: CrudRow) => {
          const row = rows.find((candidate) => candidate.id === displayRow.id) ?? displayRow;
          return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="icon-sm"
                aria-label={t('crud.rowActions.label', 'Row actions')}
              >
                <IconMenu2 />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {capabilities.canEdit && (
                // #948: a NAVIGATION, not an overlay. The row's record has an
                // address, so editing goes there — which is what makes "send me
                // the link to that record" answerable, the back button work, and
                // a reload land on the same record instead of the list.
                <DropdownMenuItem
                  onClick={() => router.push(recordHref(feature.id, row.id))}
                >
                  {t('crud.rowActions.edit', 'Edit')}
                </DropdownMenuItem>
              )}
              {capabilities.canDelete && (
                <DropdownMenuItem
                  variant="destructive"
                  onClick={() => {
                    setSelected(row);
                    setIsDeleteOpen(true);
                  }}
                >
                  {t('crud.rowActions.delete', 'Delete')}
                </DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
          );
        }
      : undefined;

  return (
    <div className="space-y-8">
      <AdminHeader
        title={feature.label}
        description={description}
        action={
          capabilities.canCreate ? (
            <Button onClick={() => setIsCreateOpen(true)} className="gap-2">
              <IconPlus size={18} />
              {t('crud.create.action', 'Create')}
            </Button>
          ) : undefined
        }
      />

      {model === null ? (
        isLoading ? (
          <div className="space-y-3">
            <Skeleton className="h-10 w-full rounded-md" />
            <Skeleton className="h-64 w-full rounded-lg" />
          </div>
        ) : (
          <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
            <IconAlertTriangle
              size={32}
              className="mx-auto mb-3 text-muted-foreground"
            />
            <h2 className="font-heading text-sm font-medium">
              {t('crud.schemaUnavailable.title', 'Schema unavailable')}
            </h2>
            <p className="mt-1 text-xs text-muted-foreground">
              {t(
                'crud.schemaUnavailable.description',
                'The API schema for this feature could not be loaded, so the screen cannot be rendered.'
              )}
            </p>
          </div>
        )
      ) : (
        <DataTable
          columns={columns}
          data={displayRows}
          rowActions={rowActions}
          isLoading={isLoading}
          emptyState={{
            title: t('crud.empty.title', 'No records yet'),
            description: capabilities.canCreate
              ? t('crud.empty.description.canCreate', 'Create the first record to get started.')
              : t('crud.empty.description', 'Nothing to show for this feature yet.'),
          }}
        />
      )}

      {model !== null && (
        <CrudCreateDialog
          // Remount on each open so the form resets to its defaults without a
          // synchronous setState in an effect.
          key={isCreateOpen ? 'create-open' : 'create-closed'}
          title={t('crud.create.title', 'Create {resource}', { resource: feature.label })}
          description={t('crud.create.description', 'Add a new record via the {plugin} plugin.', {
            plugin: feature.plugin,
          })}
          fields={model.createFields}
          isOpen={isCreateOpen}
          onOpenChange={setIsCreateOpen}
          submitLabel={t('crud.create.action', 'Create')}
          busyLabel={t('crud.create.pending', 'Creating...')}
          onSubmit={handleCreate}
        />
      )}

      {selected !== null && (
        <CrudDeleteDialog
          resourceLabel={feature.label}
          itemLabel={rowTitle(selected)}
          isOpen={isDeleteOpen}
          onOpenChange={(open) => {
            setIsDeleteOpen(open);
            if (!open) {
              setSelected(null);
            }
          }}
          onConfirm={handleDelete}
        />
      )}
    </div>
  );
}
