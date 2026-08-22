'use client';

/**
 * WC-235: FormContext for interactive `form` blocks.
 *
 * Provides per-form state (values, errors, isSubmitting) to all descendant
 * input and submit-button renderers. A `textInput` / `checkbox` / etc.
 * rendered outside a `FormProvider` receives `null` from `useFormBlockContext`
 * and degrades to `UnsupportedBlock`.
 *
 * i18n: the chrome this file authors goes through `t()` (domain `plugin`).
 * Every `child.label`, the server's issue `message`/`column`, and a plugin's
 * own validation copy are DATA and are rendered verbatim.
 */

import * as React from 'react';
import type { Block, FormBlock, LocalizedTextValue, OuScopeValue } from '@/lib/plugin-features';
import { isOuScopeValue } from '@/lib/plugin-features';
import { apiClient } from '@/lib/api-client';
import { submitPluginAction, type ActionIssue } from '@/lib/plugin-action-submit';
import { useToast } from '@/lib/toast-context';
import { IconAlertTriangle } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';

/** Sentinel: when a sensitive field holds this value, it is omitted from the submit payload. */
export const SENSITIVE_SENTINEL = '••••••';

/** A `fieldArray` (WC-532 A2) value: an ordered list of per-row sub-records. */
export type FieldArrayValue = Record<string, string | boolean | LocalizedTextValue | OuScopeValue>[];

/**
 * A single form field's value. Most inputs are `string | boolean`; a
 * `bilingualText` input (WC-532 A4) holds a `{ar?, en?}` object; a `fieldArray`
 * (WC-532 A2) holds an array of row records; an `ouScopePicker` (#868) holds a
 * `{unit, scope, type}` rule — the picker always writes the WHOLE object, so a
 * value in this map can never be a rule missing its `scope`.
 */
export type FormValue = string | boolean | LocalizedTextValue | FieldArrayValue | OuScopeValue;

/** The value shape exposed to all form descendants via context. */
export interface FormBlockContextValue {
  values: Record<string, FormValue>;
  setValue(name: string, value: FormValue): void;
  errors: Record<string, string>;
  isSubmitting: boolean;
  submit(): void;
}

const FormBlockContext = React.createContext<FormBlockContextValue | null>(null);

/**
 * Returns the nearest `FormProvider`'s context value, or `null` when the
 * calling component is rendered outside any form.
 */
export function useFormBlockContext(): FormBlockContextValue | null {
  return React.useContext(FormBlockContext);
}

/**
 * WC-532 A2: provide a scoped form context to a subtree. A `fieldArray` uses
 * this to give each row its own `{values, setValue}` (backed by that row's
 * slice of the array) so the ORDINARY input renderers work unchanged inside a
 * row — their `name`s resolve against the row record, not the outer form.
 */
export function FormScopeProvider({
  value,
  children,
}: {
  value: FormBlockContextValue;
  children: React.ReactNode;
}) {
  return <FormBlockContext.Provider value={value}>{children}</FormBlockContext.Provider>;
}

// ---- helpers ----

/**
 * Render the issues report UI (mirrors action-screen's report section) so the
 * form can surface server-side validation feedback inline.
 */
export function IssuesReport({ issues }: { issues: ActionIssue[] }) {
  // Before the early return: a hook may not run conditionally.
  const t = useTranslation('plugin');
  if (issues.length === 0) {
    return null;
  }
  return (
    <div
      className="space-y-2 rounded-lg border border-border bg-card p-4"
      data-slot="form-issues-report"
    >
      <div className="flex items-center gap-2">
        <IconAlertTriangle size={16} className="text-destructive" aria-hidden />
        <h3 className="font-heading text-sm font-medium">
          {t('action.issues.title', 'Validation report')}
        </h3>
      </div>
      <ul className="space-y-1.5">
        {issues.map((issue, index) => {
          // `issue.column` and `issue.message` are the plugin's own report
          // text — rendered verbatim. Only the "Item n" locator is ours.
          const where: string[] = [];
          if (typeof issue.item === 'number') {
            where.push(t('action.issues.item', 'Item {index}', { index: issue.item }));
          }
          if (typeof issue.column === 'string' && issue.column !== '') {
            where.push(issue.column);
          }
          const isError = issue.severity !== 'warning';
          return (
            <li
              key={index}
              className={`rounded-md border-s-4 bg-muted/40 px-3 py-2 text-sm ${
                isError ? 'border-destructive' : 'border-warning'
              }`}
            >
              <span className="me-2 text-xs font-semibold uppercase tracking-wide">
                {isError
                  ? t('action.issues.severity.error', 'error')
                  : t('action.issues.severity.warning', 'warning')}
              </span>
              {where.length > 0 && (
                <span className="font-medium text-muted-foreground">{where.join(' / ')}: </span>
              )}
              {issue.message ?? ''}
            </li>
          );
        })}
      </ul>
    </div>
  );
}

// ---- collect inputs (any depth) ----

/** The input-leaf block types that participate in a form's value map. */
const FORM_INPUT_TYPES = [
  'textInput',
  'textArea',
  'numberInput',
  'select',
  'checkbox',
  'slider',
  'dateInput',
  'fileInput',
  'colorInput',
  'bilingualText',
  'referenceSelect',
  'richTextInput',
  'ouScopePicker',
] as const;

/**
 * Flatten every input-leaf descendant of a form's children at ANY depth —
 * inputs nested inside layout containers (section, card, grid, row, tabs) are
 * included. This mirrors the SDK `BlockValidator`, which permits inputs
 * anywhere inside a `form` (the `inForm` ancestor rule), so default-seeding and
 * required-validation must reach them too. A nested `form` owns its own
 * inputs, so we never descend into one.
 */
function collectFormInputs(blocks: Block[]): Block[] {
  const inputs: Block[] = [];
  for (const block of blocks) {
    if ((FORM_INPUT_TYPES as readonly string[]).includes(block.type)) {
      inputs.push(block);
      continue;
    }
    if (block.type === 'form') {
      continue;
    }
    if (block.type === 'fieldArray') {
      // WC-532 A2: a fieldArray owns ONE flat value (its row array) keyed by
      // its own name — include it, but never flatten its per-row template
      // inputs into the outer form (their names are row-scoped).
      inputs.push(block);
      continue;
    }
    // #909: BOTH child lists. An `accessGate` carries two, and a walk that knew
    // only `children` would seed defaults for the permitted rendering and not
    // for the refused one — so the same field name would be in the value map or
    // absent from it depending on which branch the author put it in, which is
    // not a distinction anyone declared. Hidden inputs staying in the value map
    // is the standing convention for `visibleWhen` (the server re-validates and
    // is authoritative over what it accepts); this keeps the two slots equal to
    // each other rather than inventing a third rule for one of them.
    for (const slot of ['children', 'otherwise'] as const) {
      const nested = (block as { children?: unknown; otherwise?: unknown })[slot];
      if (Array.isArray(nested)) {
        inputs.push(...collectFormInputs(nested as Block[]));
      }
    }
  }
  return inputs;
}

// ---- seed defaults ----

/**
 * Collect each input's `default` value across ALL descendant inputs of a form
 * (any depth — see {@link collectFormInputs}).
 */
function collectDefaults(
  children: FormBlock['children'],
  resolveRef?: (ref: string) => string | undefined
): Record<string, FormValue> {
  const defaults: Record<string, FormValue> = {};
  for (const input of collectFormInputs(children)) {
    if (input.type === 'fieldArray') {
      // Seed `min` empty rows (each with the template's own defaults) so a
      // required-min array starts populated; 0 min → an empty array.
      const min = typeof input.min === 'number' && input.min > 0 ? input.min : 0;
      const rowDefault = collectDefaults(input.children, resolveRef) as FieldArrayValue[number];
      defaults[input.name] = Array.from({ length: min }, () => ({ ...rowDefault }));
    } else if (input.type === 'checkbox') {
      // WC-block-modal-drawer: `defaultFrom` (a master-detail context ref) wins
      // over the literal `default`. A row's boolean serialises as 'true'/'1'.
      const seeded = input.defaultFrom !== undefined ? resolveRef?.(input.defaultFrom) : undefined;
      if (seeded !== undefined) {
        defaults[input.name] = seeded === 'true' || seeded === '1';
      } else if (typeof input.default === 'boolean') {
        defaults[input.name] = input.default;
      }
    } else if (input.type === 'bilingualText') {
      // Seed an empty {ar, en} so the field exists in the value map and the
      // renderer/required-check have a stable object to read.
      defaults[input.name] = {};
    } else if (
      input.type === 'textInput' ||
      input.type === 'textArea' ||
      input.type === 'numberInput' ||
      input.type === 'select' ||
      input.type === 'slider' ||
      input.type === 'dateInput' ||
      input.type === 'colorInput' ||
      input.type === 'referenceSelect' ||
      input.type === 'richTextInput'
    ) {
      // WC-block-modal-drawer: `defaultFrom` resolved from context seeds the
      // input (e.g. an edit form opened from a table row) BEFORE the literal
      // `default`; an unresolved ref falls through to `default`.
      const seeded = input.defaultFrom !== undefined ? resolveRef?.(input.defaultFrom) : undefined;
      if (seeded !== undefined) {
        defaults[input.name] = seeded;
      } else if (typeof input.default === 'string') {
        defaults[input.name] = input.default;
      }
    }
  }
  return defaults;
}

// ---- provider ----

/**
 * Wraps a `form` block's children with form state (values, errors,
 * isSubmitting) and the `submit()` action. On submit:
 *   - required fields are validated → `errors` map set
 *   - if valid → `submitPluginAction` is called
 *   - 2xx → success toast
 *   - 422/issues → issues report rendered + error toast
 *   - other error → error toast
 */
export function FormProvider({
  block,
  children,
  resolveRef,
  onSubmitSuccess,
}: {
  block: FormBlock;
  children: React.ReactNode;
  /** WC-block-modal-drawer: resolves an input's `defaultFrom` against the master-detail context. */
  resolveRef?: (ref: string) => string | undefined;
  /** WC-block-modal-drawer: called after a successful submit (e.g. to close + refetch an enclosing overlay). */
  onSubmitSuccess?: () => void;
}) {
  const { addToast } = useToast();
  const t = useTranslation('plugin');

  // Resolved at render, not inside the effect below: a STRING dependency is
  // compared by value, so the load effect re-runs only if the text actually
  // changes (a language switch) — never merely because `t` got a new identity
  // when the bundle arrived, which would re-fetch and clobber user edits.
  const loadErrorText = t('form.error.load', 'Failed to load settings');

  const [values, setValues] = React.useState<Record<string, FormValue>>(
    () => collectDefaults(block.children, resolveRef)
  );
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [isSubmitting, setIsSubmitting] = React.useState(false);
  const [serverIssues, setServerIssues] = React.useState<ActionIssue[] | null>(null);
  const [isLoading, setIsLoading] = React.useState(block.dataSource !== undefined);
  const [loadError, setLoadError] = React.useState<string | null>(null);

  const dataSourcePath = block.dataSource?.path;
  const dataSourceMethod = block.dataSource?.method;

  React.useEffect(() => {
    if (!dataSourcePath || !dataSourceMethod) return;
    apiClient(dataSourcePath, { method: dataSourceMethod })
      .then((response) => response.json())
      .then((data: unknown) => {
        if (data !== null && typeof data === 'object') {
          setValues((prev) => ({
            ...prev,
            ...(data as Record<string, FormValue>),
          }));
        }
        setIsLoading(false);
      })
      .catch(() => {
        setLoadError(loadErrorText);
        setIsLoading(false);
      });
  }, [dataSourcePath, dataSourceMethod, loadErrorText]);

  const setValue = React.useCallback(
    (name: string, value: string | boolean) => {
      setValues((prev) => ({ ...prev, [name]: value }));
      // Clear the field error when the user edits the field.
      setErrors((prev) => {
        if (!(name in prev)) return prev;
        const next = { ...prev };
        delete next[name];
        return next;
      });
    },
    []
  );

  const submit = React.useCallback(() => {
    // Collect required-field errors across all descendant inputs (any depth).
    const newErrors: Record<string, string> = {};
    for (const child of collectFormInputs(block.children)) {
      if (
        (child.type === 'textInput' ||
          child.type === 'textArea' ||
          child.type === 'numberInput' ||
          child.type === 'select' ||
          child.type === 'dateInput' ||
          child.type === 'fileInput' ||
          child.type === 'richTextInput') &&
        child.required === true
      ) {
        const val = values[child.name];
        const filled =
          typeof val === 'string' ? val.trim() !== '' : val !== undefined;
        if (!filled) {
          // `child.label` is plugin data; the sentence around it is ours.
          newErrors[child.name] = t('form.error.required', '{label} is required', {
            label: child.label,
          });
        }
      } else if (child.type === 'bilingualText' && child.required === true) {
        // A required bilingual field is satisfied by at least one language
        // (mirrors the CRUD localized-text rule).
        const val = values[child.name];
        const filled =
          val !== null &&
          typeof val === 'object' &&
          !Array.isArray(val) &&
          !isOuScopeValue(val) &&
          ((val.ar ?? '').trim() !== '' || (val.en ?? '').trim() !== '');
        if (!filled) {
          newErrors[child.name] = t('form.error.required', '{label} is required', {
            label: child.label,
          });
        }
      } else if (child.type === 'fieldArray' && typeof child.min === 'number' && child.min > 0) {
        // WC-532 A2: enforce the minimum row count.
        const val = values[child.name];
        const count = Array.isArray(val) ? val.length : 0;
        if (count < child.min) {
          // NOT translated, deliberately: the message picks "entry"/"entries"
          // from the count, and there is no plural machinery behind t(). A
          // two-arm English branch does not survive a language with more than
          // two plural categories (Arabic has six), and splitting the sentence
          // to key the halves would be worse than leaving it in English.
          newErrors[child.name] = `${child.label} needs at least ${child.min} ${child.min === 1 ? 'entry' : 'entries'}`;
        }
      }
    }

    setErrors(newErrors);
    if (Object.keys(newErrors).length > 0) {
      return;
    }

    setIsSubmitting(true);
    setServerIssues(null);

    // Omit sensitive sentinel values — they mean "unchanged, don't overwrite".
    const payload: Record<string, unknown> = {};
    for (const [key, val] of Object.entries(values)) {
      if (val === SENSITIVE_SENTINEL) continue;
      payload[key] = val;
    }

    // WC-block-submit-templating: interpolate {targetId.field}/{selector} context
    // tokens in the endpoint (e.g. an edit form inside a modal PATCHing
    // /api/persons/{edit-person.id} for the opened row). Unresolved → '' (a
    // runtime no-op), same as the SDK contract's no-cross-reference stance.
    const endpoint = block.submit.endpoint.replace(
      /\{([^}]+)\}/g,
      (_match: string, ref: string) => encodeURIComponent(resolveRef?.(ref) ?? '')
    );

    void submitPluginAction(endpoint, block.submit.method, payload).then(
      (result) => {
        setIsSubmitting(false);
        if (result.ok) {
          addToast(t('action.toast.completed', 'Completed successfully'), 'success');
          // WC-block-modal-drawer: close the enclosing overlay + refetch the tree.
          onSubmitSuccess?.();
        } else if (result.issues && result.issues.length > 0) {
          setServerIssues(result.issues);
          addToast(
            t('action.issues.summary', '{count} issue(s) — see the report below', {
              count: result.issues.length,
            }),
            'error'
          );
        } else {
          // `result.error` is the server's own message — never keyed.
          addToast(result.error ?? t('action.toast.requestFailed', 'Request failed'), 'error');
        }
      }
    );
  }, [block, values, addToast, t, onSubmitSuccess, resolveRef]);

  const contextValue: FormBlockContextValue = {
    values,
    setValue,
    errors,
    isSubmitting,
    submit,
  };

  return (
    <FormBlockContext.Provider value={contextValue}>
      <div className="space-y-3" data-slot="form-block">
        {loadError !== null && (
          <p className="text-sm text-destructive" role="alert">{loadError}</p>
        )}
        <fieldset disabled={isLoading} className="contents">
          {children}
        </fieldset>
        {serverIssues !== null && serverIssues.length > 0 && (
          <IssuesReport issues={serverIssues} />
        )}
      </div>
    </FormBlockContext.Provider>
  );
}
