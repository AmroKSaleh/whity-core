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

import { useState } from 'react';
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
  /** Constraints on the answer. `ou_type` narrows which KIND of unit an `ou_ref` accepts. */
  validation?: { ou_type?: string } | null;
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
  /** The option's own kind, so a field can accept only some of them. */
  type?: string | null;
}

/**
 * What the server hands back for one uploaded file.
 *
 * `reference` is what the `file` ANSWER carries — the same string the field
 * would have held if somebody had typed it, which is why no type change was
 * needed on `Answer`. Everything else is for showing the person what was
 * received: they attached a file and are entitled to see that the server agrees
 * about which one.
 */
export interface UploadedFileRef {
  reference: string;
  filename: string | null;
  content_type: string;
  byte_size: number;
  checksum_sha256: string;
}

/**
 * Uploads one file and resolves with the reference to answer with.
 *
 * Supplied BY THE PAGE, not by this component, for the same reason `references`
 * is: the two pages post to different endpoints — `/forms/{id}/uploads` and
 * `/public/forms/{slug}/uploads` — and which one a reader may use is a question
 * about the reader. A component that picked its own endpoint would have to know
 * whether it was being rendered publicly, which is exactly the thing it must not
 * decide.
 *
 * It REJECTS with an Error whose message is the server's own sentence ("That
 * file is too large — the limit is 10 MB."), because the server is the only
 * party that knows the limits and it already writes them for a person.
 */
export type FileUploader = (file: File) => Promise<UploadedFileRef>;

/**
 * The kinds the server accepts, as an `accept` attribute.
 *
 * A HINT, not a rule. The server decides from the file's LEADING BYTES and will
 * refuse a renamed `.pdf` this list would have let through — so nothing here is
 * load-bearing, and it is worth having only because a file picker that offers
 * the whole disk makes people choose a file that is then rejected.
 */
const ACCEPTED_FILE_TYPES = 'application/pdf,image/png,image/jpeg';

/** A byte count as a person would read it. */
function humanSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * A `file` field: a file input that UPLOADS, then holds the reference it got
 * back.
 *
 * A separate component rather than a branch inside {@link FormField} because it
 * is the only field kind with state of its own — in flight, failed, attached —
 * and hooks may not be called conditionally. Keeping them here means the other
 * nine kinds pay nothing for this one.
 *
 * THE ANSWER IS THE REFERENCE, NEVER THE FILE. A file input cannot be given a
 * value programmatically, so re-rendering the page would otherwise lose the
 * attachment silently. What is remembered is the server's reference, which is
 * what the submission actually carries — and the file's name is shown beside it
 * so the person can see that something IS attached rather than inferring it from
 * an input that looks empty.
 *
 * A FAILED UPLOAD CLEARS THE ANSWER. Leaving the previous reference in place
 * after a failed replacement would submit the OLD file while showing an error
 * about the new one — the "renders one thing, does another" failure. Clearing it
 * makes a required field refuse, which is the honest outcome.
 */
function FileField({
  id,
  required,
  value,
  upload,
  onChange,
}: {
  id: string;
  required: boolean;
  value: string | undefined;
  upload: FileUploader | undefined;
  onChange: (value: Answer) => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [attached, setAttached] = useState<{ name: string; size: number } | null>(null);

  if (upload === undefined) {
    // No uploader means this surface cannot accept files. Say so rather than
    // drawing an input that would do nothing — a control that silently fails is
    // worse than an absent one.
    return (
      <p className="text-sm text-muted-foreground" role="note">
        Files cannot be attached here.
      </p>
    );
  }

  return (
    <div className="space-y-1.5">
      <input
        id={id}
        type="file"
        accept={ACCEPTED_FILE_TYPES}
        // `required` is passed through for assistive technology; both fill pages
        // set `noValidate`, and the browser could not see the attachment anyway
        // — the input's own value is cleared as soon as the upload finishes.
        required={required && value === undefined}
        disabled={busy}
        className="block w-full text-sm file:me-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-1.5 file:text-sm file:font-medium hover:file:bg-secondary/80"
        onChange={(e) => {
          const picked = e.target.files?.[0];
          if (picked === undefined) return;

          setBusy(true);
          setError(null);
          void upload(picked)
            .then((stored) => {
              setAttached({ name: stored.filename ?? picked.name, size: stored.byte_size });
              onChange(stored.reference);
            })
            .catch((cause: unknown) => {
              setAttached(null);
              // The answer is cleared, not left pointing at whatever was
              // attached before — see the component docblock.
              onChange('');
              setError(cause instanceof Error ? cause.message : 'That file could not be uploaded.');
            })
            .finally(() => setBusy(false));
        }}
      />

      {busy && <p className="text-xs text-muted-foreground">Uploading…</p>}

      {!busy && attached !== null && (
        <p className="text-xs text-muted-foreground">
          Attached: {attached.name} ({humanSize(attached.size)})
        </p>
      )}

      {error !== null && (
        <p className="text-xs text-destructive" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}

export function FormField({
  field,
  value,
  preferArabic,
  onChange,
  references,
  upload,
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
  /**
   * How a `file` field uploads. Supplied by the page for the same reason
   * `references` is — the two fill pages post to different endpoints — and
   * OMITTED means this surface cannot take files, which the field says out loud
   * rather than drawing an input that would do nothing.
   *
   * Both pages supply one. A `file` field is served publicly as well as
   * internally: unlike the person and unit pickers beside it, a file input reads
   * nothing about the organisation, so it is not the oracle they are.
   */
  upload?: FileUploader;
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

      {field.field_type === 'file' ? (
        // AN UPLOAD, not a text box. Before this branch existed a `file` field
        // fell through to the `<Input type="text">` at the bottom, so the form
        // asked somebody to "upload your paper" and gave them a place to type a
        // storage key they had no way to obtain.
        <FileField
          id={id}
          required={field.is_required}
          value={typeof value === 'string' && value !== '' ? value : undefined}
          upload={upload}
          onChange={onChange}
        />
      ) : field.field_type === 'textarea' ? (
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
          {(references?.[field.field_type] ?? [])
            // A department picker offers departments. Filtering here rather
            // than at the fetch keeps ONE list serving every reference field on
            // the form; a field that names no type still sees everything.
            .filter((option) => {
              const wanted = field.validation?.ou_type;

              return wanted === undefined || wanted === null || option.type === wanted;
            })
            .map((option) => (
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
