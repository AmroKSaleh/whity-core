'use client';

import { useEffect, useState } from 'react';
import { apiClient } from '@/lib/api-client';
import {
  deriveCrudModel,
  effectiveCapabilities,
  type CrudField,
  type CrudModel,
  type LocalizedTextValue,
  type OpenApiSpec,
  type ReferenceConfig,
} from '@/lib/plugin-crud-schema';
import type { PluginFeature } from '@/lib/plugin-features';
import { usePluginData } from '@/lib/use-plugin-data';
import { useToast } from '@/lib/toast-context';
import { useDirection } from '@/lib/direction-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type Column } from '@/components/admin/data-table';
import { Button } from '@amroksaleh/ui/button';
import { BilingualInput } from '@amroksaleh/ui/bilingual-input';
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
import { Input } from '@/components/ui/input';
import { Textarea } from '@amroksaleh/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import { ErrorState } from '@amroksaleh/ui/empty-state';
import {
  IconAlertTriangle,
  IconMenu2,
  IconPlus,
  IconShieldLock,
} from '@tabler/icons-react';
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';

/**
 * i18n (domain `plugin`): only the CHROME this file authors is keyed. The
 * schema-derived model is plugin DATA and is rendered verbatim — every
 * `field.label`, `column.label`, `select` option, `feature.label`,
 * `feature.plugin`, `feature.requiredPermission`, the titleField row title,
 * and any `{ error }` message the backend returns.
 */

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

/** Form values: strings for text-ish inputs, booleans for checkboxes, {ar,en} for LocalizedText. */
export type FormValues = Record<string, string | boolean | LocalizedTextValue>;

/** Narrow an unknown raw value to a {@link LocalizedTextValue}, defensively. */
function toLocalizedTextValue(raw: unknown): LocalizedTextValue {
  if (typeof raw !== 'object' || raw === null) {
    return {};
  }
  const record = raw as Record<string, unknown>;
  const value: LocalizedTextValue = {};
  if (typeof record.ar === 'string') {
    value.ar = record.ar;
  }
  if (typeof record.en === 'string') {
    value.en = record.en;
  }
  return value;
}

/**
 * WC-532: the dir-preferred language for a LocalizedText value, falling back
 * to the other language when the preferred one is empty, or `null` when both
 * are empty (the caller renders an "untranslated" marker in that case).
 */
function preferredLocalizedText(
  value: LocalizedTextValue,
  dir: 'ltr' | 'rtl'
): string | null {
  const preferred = dir === 'rtl' ? value.ar : value.en;
  const fallback = dir === 'rtl' ? value.en : value.ar;
  const chosen = preferred?.trim() ? preferred : fallback?.trim() ? fallback : null;
  return chosen;
}

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
    } else if (field.kind === 'localized-text') {
      values[field.name] = toLocalizedTextValue(raw);
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

/**
 * Client-side required/number/maxLength checks; the server stays authoritative.
 *
 * Takes `t` as a parameter because a plain function cannot call a hook; the
 * file has exactly one domain, so the extractor still attributes these keys to
 * it. `field.label` is plugin data and travels as a placeholder — the sentence
 * around it stays one translatable unit.
 */
function validateFormValues(
  fields: CrudField[],
  values: FormValues,
  t: TranslateFn
): Record<string, string> {
  const errors: Record<string, string> = {};
  for (const field of fields) {
    if (field.kind === 'checkbox') {
      continue;
    }
    if (field.kind === 'localized-text') {
      const value = values[field.name];
      const localized = typeof value === 'object' ? value : {};
      // Required means AT LEAST ONE language is filled — Phase-1 does not
      // force translating both languages before saving.
      if (field.required && !localized.ar?.trim() && !localized.en?.trim()) {
        errors[field.name] = t(
          'crud.field.requiredLocalized',
          '{label} is required (at least one language)',
          { label: field.label }
        );
      }
      continue;
    }
    const value = values[field.name];
    const text = typeof value === 'string' ? value.trim() : '';
    if (field.required && text === '') {
      errors[field.name] = t('crud.field.required', '{label} is required', {
        label: field.label,
      });
      continue;
    }
    if (text !== '' && field.kind === 'number' && Number.isNaN(Number(text))) {
      errors[field.name] = t('crud.field.number', '{label} must be a number', {
        label: field.label,
      });
      continue;
    }
    if (field.maxLength !== undefined && text.length > field.maxLength) {
      errors[field.name] = t(
        'crud.field.maxLength',
        '{label} must be at most {max} characters',
        { label: field.label, max: field.maxLength }
      );
    }
  }
  return errors;
}

/**
 * Convert form values to the JSON payload; empty optional fields are omitted.
 * Exported for unit testing (the reference-id coercion in particular).
 */
export function toPayload(
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
    if (field.kind === 'localized-text') {
      const localized = typeof value === 'object' ? value : {};
      const isEmpty = !localized.ar?.trim() && !localized.en?.trim();
      if (isEmpty && !field.required) {
        continue;
      }
      payload[field.name] = { ar: localized.ar ?? '', en: localized.en ?? '' };
      continue;
    }
    const text = typeof value === 'string' ? value : '';
    if (text === '' && !field.required) {
      continue;
    }
    // A reference submits its chosen value; coerce a numeric id (the common
    // FK) to a number, leave a non-numeric key (a string FK) as-is.
    const asNumber = field.kind === 'number' || (field.kind === 'reference' && /^\d+$/.test(text));
    payload[field.name] = asNumber ? Number(text) : text;
  }
  return payload;
}

/**
 * A `kind: "reference"` form field: a dropdown populated from the referenced
 * collection (usePluginData over `resource`), each row mapped {value:
 * valueField, label: labelField}. The submitted value is the chosen FK id
 * (coerced in toPayload). Mirrors the block-DSL referenceSelect renderer.
 */
function ReferenceField({
  field,
  reference,
  value,
  error,
  onChange,
}: {
  field: CrudField;
  reference: ReferenceConfig;
  value: string;
  error: string | undefined;
  onChange: (value: string) => void;
}) {
  const t = useTranslation('plugin');
  const inputId = `crud-field-${field.name}`;
  const state = usePluginData<Array<Record<string, unknown>>>(
    reference.resource,
    (body) => (Array.isArray(body) ? (body as Array<Record<string, unknown>>) : null)
  );

  const options =
    state.status === 'ready'
      ? state.data.flatMap((row) => {
          const rawValue = row[reference.valueField];
          const rawLabel = row[reference.labelField];
          if (rawValue === undefined || rawValue === null) {
            return [];
          }
          return [
            {
              value: String(rawValue),
              label:
                rawLabel === undefined || rawLabel === null
                  ? String(rawValue)
                  : String(rawLabel),
            },
          ];
        })
      : [];

  return (
    <div className="space-y-2">
      <label htmlFor={inputId} className="text-sm font-medium">
        {field.label}
        {field.required && <span className="text-destructive"> *</span>}
      </label>
      {state.status === 'error' ? (
        <div className="flex items-center gap-3 rounded-lg border border-border bg-card p-2 text-xs text-muted-foreground">
          <span>{t('crud.options.loadError', 'Failed to load options.')}</span>
          <Button type="button" variant="outline" size="sm" onClick={state.retry}>
            {t('crud.retry', 'Retry')}
          </Button>
        </div>
      ) : (
        <Select
          value={value}
          onValueChange={onChange}
          disabled={state.status === 'loading'}
        >
          <SelectTrigger
            id={inputId}
            className="w-full"
            aria-invalid={error !== undefined}
          >
            <SelectValue
              placeholder={
                state.status === 'loading'
                  ? t('crud.loading', 'Loading…')
                  : t('crud.select.placeholder', 'Select {label}', {
                      label: field.label.toLowerCase(),
                    })
              }
            />
          </SelectTrigger>
          <SelectContent>
            {options.map((opt) => (
              <SelectItem key={opt.value} value={opt.value}>
                {opt.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )}
      {error && <p className="text-xs text-destructive">{error}</p>}
    </div>
  );
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
 *
 * `title`, `description`, `submitLabel` and `busyLabel` are the CALLER's
 * strings and are never keyed here — the caller owns their wording (and keys
 * them itself where they are ours).
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
  const t = useTranslation('plugin');
  const [values, setValues] = useState<FormValues>(() =>
    initialFormValues(fields, initialRow)
  );
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);

  const setValue = (name: string, value: string | boolean | LocalizedTextValue) => {
    setValues((current) => ({ ...current, [name]: value }));
  };

  const handleSubmit = async () => {
    const validationErrors = validateFormValues(fields, values, t);
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
              {t('crud.form.empty', 'This action takes no input.')}
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

            if (field.kind === 'localized-text') {
              const localized = typeof value === 'object' ? value : {};
              return (
                <div key={field.name} className="space-y-2">
                  <span className="text-sm font-medium">
                    {field.label}
                    {field.required && <span className="text-destructive"> *</span>}
                  </span>
                  <BilingualInput
                    id={inputId}
                    value={localized}
                    onChange={(next) => setValue(field.name, next)}
                  />
                  {error && <p className="text-xs text-destructive">{error}</p>}
                </div>
              );
            }

            if (field.kind === 'reference' && field.reference) {
              return (
                <ReferenceField
                  key={field.name}
                  field={field}
                  reference={field.reference}
                  value={text}
                  error={error}
                  onChange={(next) => setValue(field.name, next)}
                />
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
                      <SelectValue
                        placeholder={t('crud.select.placeholder', 'Select {label}', {
                          label: field.label.toLowerCase(),
                        })}
                      />
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
            {t('crud.dialog.cancel', 'Cancel')}
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
 * spec, and renders the standard admin list + create/edit/delete dialogs.
 * Write controls render only when the spec publishes the operation AND the
 * server reports the caller may perform it (issue #199), so a read-only
 * delegated caller never sees a control whose submit would 403; a 403 on the
 * list still renders the access-denied card.
 */
export function CrudScreen({ feature }: { feature: PluginFeature }) {
  const { addToast } = useToast();
  const { dir } = useDirection();
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
        await readErrorMessage(response, t('crud.error.update', 'Failed to update record')),
        'error'
      );
      return false;
    }
    addToast(t('crud.toast.updated', 'Record updated successfully'), 'success');
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
                <DropdownMenuItem
                  onClick={() => {
                    setSelected(row);
                    setIsEditOpen(true);
                  }}
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
        <CrudFormDialog
          // Remount on each open so the form resets to its defaults without a
          // synchronous setState in an effect.
          key={isCreateOpen ? 'create-open' : 'create-closed'}
          title={t('crud.create.title', 'Create {resource}', { resource: feature.label })}
          description={t('crud.create.description', 'Add a new record via the {plugin} plugin.', {
            plugin: feature.plugin,
          })}
          fields={model.createFields}
          initialRow={null}
          isOpen={isCreateOpen}
          onOpenChange={setIsCreateOpen}
          submitLabel={t('crud.create.action', 'Create')}
          busyLabel={t('crud.create.pending', 'Creating...')}
          onSubmit={handleCreate}
        />
      )}

      {model !== null && selected !== null && (
        <CrudFormDialog
          key={`edit-${String(selected.id)}-${isEditOpen ? 'open' : 'closed'}`}
          title={t('crud.edit.title', 'Edit {resource}', { resource: feature.label })}
          description={t('crud.edit.description', 'Update {item}.', {
            item: rowTitle(selected),
          })}
          fields={model.editFields}
          initialRow={selected}
          isOpen={isEditOpen}
          onOpenChange={(open) => {
            setIsEditOpen(open);
            if (!open) {
              setSelected(null);
            }
          }}
          submitLabel={t('crud.edit.submit', 'Save changes')}
          busyLabel={t('crud.edit.pending', 'Saving...')}
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
