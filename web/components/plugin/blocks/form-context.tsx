'use client';

/**
 * WC-235: FormContext for interactive `form` blocks.
 *
 * Provides per-form state (values, errors, isSubmitting) to all descendant
 * input and submit-button renderers. A `textInput` / `checkbox` / etc.
 * rendered outside a `FormProvider` receives `null` from `useFormBlockContext`
 * and degrades to `UnsupportedBlock`.
 */

import * as React from 'react';
import type { Block, FormBlock } from '@/lib/plugin-features';
import { apiClient } from '@/lib/api-client';
import { submitPluginAction, type ActionIssue } from '@/lib/plugin-action-submit';
import { useToast } from '@/lib/toast-context';
import { IconAlertTriangle } from '@tabler/icons-react';

/** Sentinel: when a sensitive field holds this value, it is omitted from the submit payload. */
export const SENSITIVE_SENTINEL = '••••••';

/** The value shape exposed to all form descendants via context. */
export interface FormBlockContextValue {
  values: Record<string, string | boolean>;
  setValue(name: string, value: string | boolean): void;
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

// ---- helpers ----

/**
 * Render the issues report UI (mirrors action-screen's report section) so the
 * form can surface server-side validation feedback inline.
 */
export function IssuesReport({ issues }: { issues: ActionIssue[] }) {
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
        <h3 className="font-heading text-sm font-medium">Validation report</h3>
      </div>
      <ul className="space-y-1.5">
        {issues.map((issue, index) => {
          const where: string[] = [];
          if (typeof issue.item === 'number') {
            where.push(`Item ${issue.item}`);
          }
          if (typeof issue.column === 'string' && issue.column !== '') {
            where.push(issue.column);
          }
          const isError = issue.severity !== 'warning';
          return (
            <li
              key={index}
              className={`rounded-md border-l-4 bg-muted/40 px-3 py-2 text-sm ${
                isError ? 'border-destructive' : 'border-warning'
              }`}
            >
              <span className="mr-2 text-xs font-semibold uppercase tracking-wide">
                {isError ? 'error' : 'warning'}
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
    const nested = (block as { children?: unknown }).children;
    if (Array.isArray(nested)) {
      inputs.push(...collectFormInputs(nested as Block[]));
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
  children: FormBlock['children']
): Record<string, string | boolean> {
  const defaults: Record<string, string | boolean> = {};
  for (const input of collectFormInputs(children)) {
    if (input.type === 'checkbox') {
      if (typeof input.default === 'boolean') {
        defaults[input.name] = input.default;
      }
    } else if (
      input.type === 'textInput' ||
      input.type === 'textArea' ||
      input.type === 'numberInput' ||
      input.type === 'select' ||
      input.type === 'slider' ||
      input.type === 'dateInput' ||
      input.type === 'colorInput'
    ) {
      if (typeof input.default === 'string') {
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
}: {
  block: FormBlock;
  children: React.ReactNode;
}) {
  const { addToast } = useToast();

  const [values, setValues] = React.useState<Record<string, string | boolean>>(
    () => collectDefaults(block.children)
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
            ...(data as Record<string, string | boolean>),
          }));
        }
        setIsLoading(false);
      })
      .catch(() => {
        setLoadError('Failed to load settings');
        setIsLoading(false);
      });
  }, [dataSourcePath, dataSourceMethod]);

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
          child.type === 'fileInput') &&
        child.required === true
      ) {
        const val = values[child.name];
        const filled =
          typeof val === 'string' ? val.trim() !== '' : val !== undefined;
        if (!filled) {
          newErrors[child.name] = `${child.label} is required`;
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

    void submitPluginAction(block.submit.endpoint, block.submit.method, payload).then(
      (result) => {
        setIsSubmitting(false);
        if (result.ok) {
          addToast('Completed successfully', 'success');
        } else if (result.issues && result.issues.length > 0) {
          setServerIssues(result.issues);
          addToast(
            `${result.issues.length} issue(s) — see the report below`,
            'error'
          );
        } else {
          addToast(result.error ?? 'Request failed', 'error');
        }
      }
    );
  }, [block, values, addToast]);

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
