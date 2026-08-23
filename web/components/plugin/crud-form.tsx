'use client';

/**
 * The schema-derived CRUD FORM, extracted from `crud-screen.tsx` (#948).
 *
 * It moved out of that file the moment editing stopped being a dialog. A crud
 * record now has a URL (`/admin/x/[featureId]/[recordId]`), and the record page
 * renders the SAME inputs the create dialog does — so the inputs, the value
 * seeding, the client-side validation and the payload mapping had to stop being
 * private to a `<Dialog>`. Two copies of "how a derived field renders" is two
 * copies that disagree the first time one grows a field kind.
 *
 * Everything here is derived from the plugin's OpenAPI schema and knows nothing
 * about where it is mounted: the dialog passes its values in, the record page
 * passes its values in, and neither owns the rendering.
 *
 * i18n (domain `plugin`): only the CHROME this file authors is keyed. The
 * schema-derived model is plugin DATA and is rendered verbatim — every
 * `field.label`, `select` option, and any backend message.
 */

import { useState } from 'react';
import type {
  CrudField,
  LocalizedTextValue,
  ReferenceConfig,
} from '@/lib/plugin-crud-schema';
import { usePluginData } from '@/lib/use-plugin-data';
import { Button } from '@amroksaleh/ui/button';
import { BilingualInput } from '@amroksaleh/ui/bilingual-input';
import { Input } from '@/components/ui/input';
import { Textarea } from '@amroksaleh/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';

/**
 * Row view-model for the schema-driven table: a plain record of unknown cell
 * values that still satisfies DataTable's `{ id: string | number }` bound.
 * Cells are rendered by DataTable via String(...), and column keys come from
 * the derived model, so `unknown` + narrowing keeps this fully typed without
 * resorting to `any`.
 */
export type CrudRow = { id: string | number } & Record<string, unknown>;

/** Form values: strings for text-ish inputs, booleans for checkboxes, {ar,en} for LocalizedText. */
export type FormValues = Record<string, string | boolean | LocalizedTextValue>;

/** Narrow an unknown raw value to a {@link LocalizedTextValue}, defensively. */
export function toLocalizedTextValue(raw: unknown): LocalizedTextValue {
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
export function preferredLocalizedText(
  value: LocalizedTextValue,
  dir: 'ltr' | 'rtl'
): string | null {
  const preferred = dir === 'rtl' ? value.ar : value.en;
  const fallback = dir === 'rtl' ? value.en : value.ar;
  const chosen = preferred?.trim() ? preferred : fallback?.trim() ? fallback : null;
  return chosen;
}

/** Seed form values from the field list and (for edit) the selected row. */
export function initialFormValues(
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
export function validateFormValues(
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

/**
 * The derived inputs themselves — one per {@link CrudField}, in declaration
 * order, with no surrounding chrome.
 *
 * Deliberately NOT a `<form>`: the dialog puts these in a dialog body with a
 * footer, and the record page puts them in the record shell's editor slot. The
 * element that wraps them (and the button that submits them) belongs to
 * whichever surface is mounting them.
 *
 * The input `id`s stay `crud-field-<name>` on both surfaces, so a label, an
 * end-to-end selector, or a browser autofill hint means the same thing wherever
 * the field is rendered.
 */
export function CrudFields({
  fields,
  values,
  errors,
  onChange,
}: {
  fields: CrudField[];
  values: FormValues;
  errors: Record<string, string>;
  onChange: (name: string, value: string | boolean | LocalizedTextValue) => void;
}) {
  const t = useTranslation('plugin');

  return (
    <div className="space-y-4">
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
                  onChange={(event) => onChange(field.name, event.target.checked)}
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
                onChange={(next) => onChange(field.name, next)}
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
              onChange={(next) => onChange(field.name, next)}
            />
          );
        }

        return (
          <div key={field.name} className="space-y-2">
            <label htmlFor={inputId} className="text-sm font-medium">
              {field.label}
              {field.required && <span className="text-destructive"> *</span>}
            </label>

            {field.kind === 'select' ? (
              <Select
                value={text}
                onValueChange={(next) => onChange(field.name, next)}
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
                onChange={(event) => onChange(field.name, event.target.value)}
              />
            ) : (
              <Input
                id={inputId}
                type={field.kind === 'number' ? 'number' : 'text'}
                value={text}
                maxLength={field.maxLength}
                aria-invalid={error !== undefined}
                onChange={(event) => onChange(field.name, event.target.value)}
              />
            )}

            {error && <p className="text-xs text-destructive">{error}</p>}
          </div>
        );
      })}
    </div>
  );
}

/**
 * The values + errors + submit state a derived form needs, on whichever surface
 * mounts {@link CrudFields}.
 *
 * A hook rather than a component so the surface keeps its own layout: the
 * dialog's submit lives in a `DialogFooter` and the record page's lives in the
 * shell's header action slot, and a component owning both would have to grow a
 * prop to say which.
 */
export function useCrudForm(fields: CrudField[], row: CrudRow | null) {
  const t = useTranslation('plugin');
  const [values, setValues] = useState<FormValues>(() =>
    initialFormValues(fields, row)
  );
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);

  const setValue = (
    name: string,
    value: string | boolean | LocalizedTextValue
  ) => {
    setValues((current) => ({ ...current, [name]: value }));
  };

  /**
   * Validate, then hand the payload to `onSubmit`. Resolves false when the
   * client-side checks refused, so a caller can tell "did not submit" from
   * "submitted and the server said no".
   */
  const submit = async (
    onSubmit: (payload: Record<string, unknown>) => Promise<boolean>
  ): Promise<boolean> => {
    const validationErrors = validateFormValues(fields, values, t);
    setErrors(validationErrors);
    if (Object.keys(validationErrors).length > 0) {
      return false;
    }

    try {
      setIsSubmitting(true);
      return await onSubmit(toPayload(fields, values));
    } finally {
      setIsSubmitting(false);
    }
  };

  return { values, errors, isSubmitting, setValue, submit };
}
