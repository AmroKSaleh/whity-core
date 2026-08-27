'use client';

/**
 * The field rendering shared by the two pages a form can be filled on.
 *
 * There are two, and they differ in more than who may open them:
 *
 *   /f/{slug}   — anyone, signed in or not. Prefill CANNOT resolve here (the
 *                 API refuses to, and sends none), so nothing on the page can
 *                 quietly identify the person filling it.
 *   /forms/{id} — a signed-in member filling a published form. Prefill DOES
 *                 resolve, from their own saved details.
 *
 * Keeping the inputs in one place means those two pages can never drift into
 * validating or presenting the same field differently — which, for a form whose
 * answers end up in the same table, would be a difference nobody could see.
 */

import { Input } from '@amroksaleh/ui/input';
import { Textarea } from '@amroksaleh/ui/textarea';
import { Checkbox } from '@amroksaleh/ui/checkbox';

export interface LocalizedText {
  ar?: string;
  en?: string;
  [key: string]: string | undefined;
}

export interface FormFieldSpec {
  field_key: string;
  field_type: string;
  label: LocalizedText | string | null;
  help_text: string | null;
  is_required: boolean;
  options: Array<{ value?: string; label?: LocalizedText | string } | string>;
  multi_valued: boolean;
  position: number;
}

export type Answer = string | string[] | boolean;

/** Prefer the reading direction's language, then anything that is actually set. */
export function localized(
  value: LocalizedText | string | null | undefined,
  preferArabic: boolean
): string {
  if (typeof value === 'string') return value;
  if (value === null || value === undefined) return '';

  for (const key of preferArabic ? ['ar', 'en'] : ['en', 'ar']) {
    const candidate = value[key];
    if (typeof candidate === 'string' && candidate !== '') return candidate;
  }
  for (const candidate of Object.values(value)) {
    if (typeof candidate === 'string' && candidate !== '') return candidate;
  }

  return '';
}

function optionValue(option: FormFieldSpec['options'][number]): string {
  return typeof option === 'string' ? option : String(option.value ?? '');
}

/**
 * An option's label is a LOCALIZED object, not a string — the server normalises
 * `{"value":"Q1","label":"Q1"}` into `{"value":"Q1","label":{"en":"Q1"}}`, so
 * stringifying it renders "[object Object]" in the dropdown. Falls back to the
 * value, which is always a plain string.
 */
function optionLabel(option: FormFieldSpec['options'][number], preferArabic: boolean): string {
  if (typeof option === 'string') return option;

  return localized(option.label, preferArabic) || String(option.value ?? '');
}

export interface ReferenceOption {
  value: string;
  label: string;
}

export function FormField({
  field,
  value,
  preferArabic,
  onChange,
  references,
}: {
  field: FormFieldSpec;
  value: Answer | undefined;
  preferArabic: boolean;
  onChange: (value: Answer) => void;
  /**
   * Choices for reference fields (`ou_ref`, `profile_ref`), keyed by field type.
   * Supplied by the page, because only the page knows whether the reader is
   * entitled to see the list at all — the PUBLIC page never supplies any, and
   * the API strips reference fields from a public form for the same reason: a
   * picker of real units or real people is a directory, handed to a stranger.
   */
  references?: Partial<Record<string, ReferenceOption[]>>;
}) {
  const label = localized(field.label, preferArabic) || field.field_key;
  const id = `field-${field.field_key}`;
  const multiple = field.field_type === 'multiselect' || field.multi_valued;

  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="text-sm font-medium">
        {label}
        {field.is_required && (
          <span className="ms-1 text-destructive" aria-hidden>
            *
          </span>
        )}
      </label>

      {field.help_text !== null && field.help_text !== '' && (
        <p className="text-xs text-muted-foreground">{field.help_text}</p>
      )}

      {field.field_type === 'textarea' ? (
        <Textarea
          id={id}
          rows={4}
          required={field.is_required}
          value={typeof value === 'string' ? value : ''}
          onChange={(e) => onChange(e.target.value)}
        />
      ) : field.field_type === 'checkbox' ? (
        <div className="flex items-center gap-2">
          <Checkbox
            id={id}
            checked={value === true}
            onCheckedChange={(checked) => onChange(checked === true)}
          />
          <span className="text-sm text-muted-foreground">Yes</span>
        </div>
      ) : multiple ? (
        // CHECKBOXES, not `<select multiple>`. A native multi-select is a list
        // box you operate by ctrl-clicking: on a phone it is barely usable, and
        // on a desktop most people never discover that more than one choice was
        // possible — they pick one and move on, and the form quietly collects a
        // single answer to a question that asked for several.
        <div className="space-y-2 rounded-xl border border-border/60 bg-card p-3 shadow-2xs">
          {field.options.map((option, index) => {
            const optValue = optionValue(option);
            const selected = Array.isArray(value) ? value : [];
            const checked = selected.includes(optValue);
            const optId = `${id}-${index}`;

            return (
              <div key={index} className="flex items-center gap-2">
                <Checkbox
                  id={optId}
                  checked={checked}
                  onCheckedChange={(next) => {
                    // Rebuilt from the declared order rather than by appending,
                    // so what is stored reads the way the form reads.
                    const wanted = new Set(selected);
                    if (next === true) {
                      wanted.add(optValue);
                    } else {
                      wanted.delete(optValue);
                    }
                    onChange(
                      field.options.map(optionValue).filter((v) => wanted.has(v))
                    );
                  }}
                />
                <label htmlFor={optId} className="text-sm">
                  {optionLabel(option, preferArabic)}
                </label>
              </div>
            );
          })}
        </div>
      ) : field.field_type === 'ou_ref' || field.field_type === 'profile_ref' ? (
        // A REFERENCE, so the stored value is an id rather than whatever the
        // person typed. Free text here produced "Civil Eng.", "civil", and
        // "Dept of Civil Engineering" for one department, none of which a
        // report can group by.
        <select
          id={id}
          required={field.is_required}
          className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          value={typeof value === 'string' ? value : ''}
          onChange={(e) => onChange(e.target.value)}
        >
          <option value="">—</option>
          {(references?.[field.field_type] ?? []).map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      ) : field.field_type === 'select' ? (
        // A NATIVE select for the single-choice case. This page is opened by
        // strangers on devices nobody chose, and a custom picker assumes a
        // pointer, a viewport and script behaviour a public form may not.
        <select
          id={id}
          required={field.is_required}
          className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          value={typeof value === 'string' ? value : ''}
          onChange={(e) => onChange(e.target.value)}
        >
          <option value="">—</option>
          {field.options.map((option, index) => (
            <option key={index} value={optionValue(option)}>
              {optionLabel(option, preferArabic)}
            </option>
          ))}
        </select>
      ) : (
        <Input
          id={id}
          type={field.field_type === 'number' ? 'number' : field.field_type === 'date' ? 'date' : 'text'}
          required={field.is_required}
          value={typeof value === 'string' ? value : ''}
          onChange={(e) => onChange(e.target.value)}
        />
      )}
    </div>
  );
}
