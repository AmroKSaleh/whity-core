'use client';

import { useEffect, useState } from 'react';
import { apiClient } from '@/lib/api-client';
import {
  deriveCrudModel,
  effectiveCapabilities,
  type CrudField,
  type CrudModel,
  type OpenApiSpec,
} from '@/lib/plugin-crud-schema';
import type { PluginFeature } from '@/lib/plugin-features';
import { useToast } from '@/lib/toast-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type Column } from '@/components/admin/data-table';
import { Button } from '@whity/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@whity/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { Input } from '@whity/ui/input';
import { Textarea } from '@whity/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@whity/ui/select';
import { Skeleton } from '@whity/ui/skeleton';
import {
  IconAlertTriangle,
  IconMenu2,
  IconPlus,
  IconShieldLock,
} from '@tabler/icons-react';

/**
 * Row view-model for the schema-driven table: a plain record of unknown cell
 * values that still satisfies DataTable's `{ id: string | number }` bound.
 * Cells are rendered by DataTable via String(...), and column keys come from
 * the derived model, so `unknown` + narrowing keeps this fully typed without
 * resorting to `any`.
 */
type CrudRow = { id: string | number } & Record<string, unknown>;

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

/**
 * Fetch the public OpenAPI document through the same-origin proxy route
 * (`app/openapi.json/route.ts`). A plain fetch is deliberate: apiClient
 * rewrites non-/api relative paths to the backend origin, which would bypass
 * the proxy and require backend CORS — and the public document needs none of
 * apiClient's cookie/refresh machinery.
 */
async function fetchSpec(): Promise<OpenApiSpec | null> {
  try {
    const response = await fetch('/openapi.json');
    if (!response.ok) {
      return null;
    }
    const body: unknown = await response.json();
    if (typeof body !== 'object' || body === null) {
      return null;
    }
    return body as OpenApiSpec;
  } catch {
    return null;
  }
}

/** Form values: strings for text-ish inputs, booleans for checkboxes. */
type FormValues = Record<string, string | boolean>;

/** Seed form values from the field list and (for edit) the selected row. */
function initialFormValues(
  fields: CrudField[],
  row: CrudRow | null
): FormValues {
  const values: FormValues = {};
  for (const field of fields) {
    const raw = row?.[field.name];
    if (field.kind === 'checkbox') {
      values[field.name] = typeof raw === 'boolean' ? raw : false;
    } else if (
      typeof raw === 'string' ||
      typeof raw === 'number' ||
      typeof raw === 'boolean'
    ) {
      values[field.name] = String(raw);
    } else {
      values[field.name] = '';
    }
  }
  return values;
}

/** Client-side required/number/maxLength checks; the server stays authoritative. */
function validateFormValues(
  fields: CrudField[],
  values: FormValues
): Record<string, string> {
  const errors: Record<string, string> = {};
  for (const field of fields) {
    if (field.kind === 'checkbox') {
      continue;
    }
    const value = values[field.name];
    const text = typeof value === 'string' ? value.trim() : '';
    if (field.required && text === '') {
      errors[field.name] = `${field.label} is required`;
      continue;
    }
    if (text !== '' && field.kind === 'number' && Number.isNaN(Number(text))) {
      errors[field.name] = `${field.label} must be a number`;
      continue;
    }
    if (field.maxLength !== undefined && text.length > field.maxLength) {
      errors[field.name] =
        `${field.label} must be at most ${field.maxLength} characters`;
    }
  }
  return errors;
}

/** Convert form values to the JSON payload; empty optional fields are omitted. */
function toPayload(
  fields: CrudField[],
  values: FormValues
): Record<string, unknown> {
  const payload: Record<string, unknown> = {};
  for (const field of fields) {
    const value = values[field.name];
    if (field.kind === 'checkbox') {
      payload[field.name] = value === true;
      continue;
    }
    const text = typeof value === 'string' ? value : '';
    if (text === '' && !field.required) {
      continue;
    }
    payload[field.name] = field.kind === 'number' ? Number(text) : text;
  }
  return payload;
}

interface CrudFormDialogProps {
  title: string;
  description: string;
  fields: CrudField[];
  /** The row being edited, or null when creating. */
  initialRow: CrudRow | null;
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  submitLabel: string;
  busyLabel: string;
  /** Performs the mutation; resolves true on success (parent closes/refetches). */
  onSubmit: (payload: Record<string, unknown>) => Promise<boolean>;
}

/**
 * Generic create/edit dialog built from derived schema fields. The parent
 * remounts it via `key` on each open, so plain useState defaults reset
 * without a synchronous setState in an effect.
 */
function CrudFormDialog({
  title,
  description,
  fields,
  initialRow,
  isOpen,
  onOpenChange,
  submitLabel,
  busyLabel,
  onSubmit,
}: CrudFormDialogProps) {
  const [values, setValues] = useState<FormValues>(() =>
    initialFormValues(fields, initialRow)
  );
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);

  const setValue = (name: string, value: string | boolean) => {
    setValues((current) => ({ ...current, [name]: value }));
  };

  const handleSubmit = async () => {
    const validationErrors = validateFormValues(fields, values);
    setErrors(validationErrors);
    if (Object.keys(validationErrors).length > 0) {
      return;
    }

    try {
      setIsSubmitting(true);
      await onSubmit(toPayload(fields, values));
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-2">
          {fields.length === 0 && (
            <p className="text-sm text-muted-foreground">
              This action takes no input.
            </p>
          )}
          {fields.map((field) => {
            const inputId = `crud-field-${field.name}`;
            const error = errors[field.name];
            const value = values[field.name];
            const text = typeof value === 'string' ? value : '';

            if (field.kind === 'checkbox') {
              return (
                <div key={field.name} className="space-y-2">
                  <label
                    htmlFor={inputId}
                    className="flex w-fit cursor-pointer items-center gap-2"
                  >
                    <input
                      id={inputId}
                      type="checkbox"
                      checked={value === true}
                      onChange={(event) =>
                        setValue(field.name, event.target.checked)
                      }
                      className="size-4 rounded border-border"
                    />
                    <span className="text-sm font-medium">{field.label}</span>
                  </label>
                  {error && <p className="text-xs text-destructive">{error}</p>}
                </div>
              );
            }

            return (
              <div key={field.name} className="space-y-2">
                <label htmlFor={inputId} className="text-sm font-medium">
                  {field.label}
                  {field.required && (
                    <span className="text-destructive"> *</span>
                  )}
                </label>

                {field.kind === 'select' ? (
                  <Select
                    value={text}
                    onValueChange={(next) => setValue(field.name, next)}
                  >
                    <SelectTrigger
                      id={inputId}
                      className="w-full"
                      aria-invalid={error !== undefined}
                    >
                      <SelectValue placeholder={`Select ${field.label.toLowerCase()}`} />
                    </SelectTrigger>
                    <SelectContent>
                      {(field.options ?? []).map((option) => (
                        <SelectItem key={option} value={option}>
                          {option}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                ) : field.kind === 'textarea' ? (
                  <Textarea
                    id={inputId}
                    value={text}
                    maxLength={field.maxLength}
                    aria-invalid={error !== undefined}
                    onChange={(event) => setValue(field.name, event.target.value)}
                  />
                ) : (
                  <Input
                    id={inputId}
                    type={field.kind === 'number' ? 'number' : 'text'}
                    value={text}
                    maxLength={field.maxLength}
                    aria-invalid={error !== undefined}
                    onChange={(event) => setValue(field.name, event.target.value)}
                  />
                )}

                {error && <p className="text-xs text-destructive">{error}</p>}
              </div>
            );
          })}
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isSubmitting}
          >
            Cancel
          </Button>
          <Button type="button" onClick={handleSubmit} disabled={isSubmitting}>
            {isSubmitting ? busyLabel : submitLabel}
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
          <DialogTitle>Delete {resourceLabel}</DialogTitle>
          <DialogDescription>
            Are you sure you want to delete this item? This action cannot be
            undone.
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
            Cancel
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={handleConfirm}
            disabled={isDeleting}
          >
            {isDeleting ? 'Deleting...' : 'Delete'}
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
 * spec, and renders the standard admin list + create/edit/delete dialogs.
 * Write controls render only when the spec publishes the operation AND the
 * server reports the caller may perform it (issue #199), so a read-only
 * delegated caller never sees a control whose submit would 403; a 403 on the
 * list still renders the access-denied card.
 */
export function CrudScreen({ feature }: { feature: PluginFeature }) {
  const { addToast } = useToast();
  const basePath = feature.resource?.basePath ?? null;

  const [model, setModel] = useState<CrudModel | null>(null);
  const [rows, setRows] = useState<CrudRow[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isForbidden, setIsForbidden] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [isEditOpen, setIsEditOpen] = useState(false);
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
          addToast('Failed to load the API schema for this feature', 'error');
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
          throw new Error(
            await readErrorMessage(listResponse, 'Failed to load records')
          );
        }

        const body: unknown = await listResponse.json();
        const data =
          typeof body === 'object' && body !== null && 'data' in body
            ? (body as { data: unknown }).data
            : null;
        if (!Array.isArray(data)) {
          throw new Error('Unexpected list response shape');
        }
        setRows(toRows(data));
      } catch (error) {
        const message =
          error instanceof Error ? error.message : 'Failed to load records';
        addToast(message, 'error');
      } finally {
        setIsLoading(false);
      }
    };

    void load();
  }, [basePath, reloadKey, addToast]);

  // Guard: callers only render CrudScreen for crud features with a resource,
  // but a defensive placeholder beats a crash if that invariant slips.
  if (basePath === null) {
    return (
      <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
        <IconAlertTriangle
          size={32}
          className="mx-auto mb-3 text-muted-foreground"
        />
        <h2 className="font-heading text-sm font-medium">No resource</h2>
        <p className="mt-1 text-xs text-muted-foreground">
          The &apos;{feature.id}&apos; feature does not declare a REST resource
          to render.
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
        await readErrorMessage(response, 'Failed to create record'),
        'error'
      );
      return false;
    }
    addToast('Record created successfully', 'success');
    setIsCreateOpen(false);
    refetch();
    return true;
  };

  const handleEdit = async (
    payload: Record<string, unknown>
  ): Promise<boolean> => {
    if (selected === null) {
      return false;
    }
    const response = await apiClient(
      `${basePath}/${encodeURIComponent(String(selected.id))}`,
      {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }
    );
    if (!response.ok) {
      addToast(
        await readErrorMessage(response, 'Failed to update record'),
        'error'
      );
      return false;
    }
    addToast('Record updated successfully', 'success');
    setIsEditOpen(false);
    setSelected(null);
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
        await readErrorMessage(response, 'Failed to delete record'),
        'error'
      );
      return false;
    }
    addToast('Record deleted successfully', 'success');
    setIsDeleteOpen(false);
    setSelected(null);
    refetch();
    return true;
  };

  const description = `Manage ${feature.label.toLowerCase()} provided by the ${feature.plugin} plugin.`;

  if (isForbidden) {
    return (
      <div className="space-y-8">
        <AdminHeader title={feature.label} description={description} />
        <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
          <IconShieldLock
            size={32}
            className="mx-auto mb-3 text-muted-foreground"
          />
          <h2 className="font-heading text-sm font-medium">Access denied</h2>
          <p className="mt-1 text-xs text-muted-foreground">
            You need the {feature.requiredPermission} permission to use this
            feature.
          </p>
        </div>
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

  const rowActions =
    capabilities.canEdit || capabilities.canDelete
      ? (row: CrudRow) => (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon-sm" aria-label="Row actions">
                <IconMenu2 />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {capabilities.canEdit && (
                <DropdownMenuItem
                  onClick={() => {
                    setSelected(row);
                    setIsEditOpen(true);
                  }}
                >
                  Edit
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
                  Delete
                </DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        )
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
              Create
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
              Schema unavailable
            </h2>
            <p className="mt-1 text-xs text-muted-foreground">
              The API schema for this feature could not be loaded, so the
              screen cannot be rendered.
            </p>
          </div>
        )
      ) : (
        <DataTable
          columns={columns}
          data={rows}
          rowActions={rowActions}
          isLoading={isLoading}
          emptyState={{
            title: 'No records yet',
            description: capabilities.canCreate
              ? 'Create the first record to get started.'
              : 'Nothing to show for this feature yet.',
          }}
        />
      )}

      {model !== null && (
        <CrudFormDialog
          // Remount on each open so the form resets to its defaults without a
          // synchronous setState in an effect.
          key={isCreateOpen ? 'create-open' : 'create-closed'}
          title={`Create ${feature.label}`}
          description={`Add a new record via the ${feature.plugin} plugin.`}
          fields={model.createFields}
          initialRow={null}
          isOpen={isCreateOpen}
          onOpenChange={setIsCreateOpen}
          submitLabel="Create"
          busyLabel="Creating..."
          onSubmit={handleCreate}
        />
      )}

      {model !== null && selected !== null && (
        <CrudFormDialog
          key={`edit-${String(selected.id)}-${isEditOpen ? 'open' : 'closed'}`}
          title={`Edit ${feature.label}`}
          description={`Update ${rowTitle(selected)}.`}
          fields={model.editFields}
          initialRow={selected}
          isOpen={isEditOpen}
          onOpenChange={(open) => {
            setIsEditOpen(open);
            if (!open) {
              setSelected(null);
            }
          }}
          submitLabel="Save changes"
          busyLabel="Saving..."
          onSubmit={handleEdit}
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
