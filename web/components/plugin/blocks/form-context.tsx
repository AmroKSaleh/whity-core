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
import type { FormBlock } from '@/lib/plugin-features';
import { submitPluginAction, type ActionIssue } from '@/lib/plugin-action-submit';
import { useToast } from '@/lib/toast-context';
import { IconAlertTriangle } from '@tabler/icons-react';

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

// ---- seed defaults ----

/**
 * Walk a `form` block's children once to collect each input's `default` value.
 * We only look one level deep here; inputs nested inside layout containers
 * (card, section, grid, row) are not seeded — they start empty. The plan
 * states "walk block.children once to collect defaults", so direct children
 * only is the correct interpretation.
 */
function collectDefaults(
  children: FormBlock['children']
): Record<string, string | boolean> {
  const defaults: Record<string, string | boolean> = {};
  for (const child of children) {
    if (
      child.type === 'textInput' ||
      child.type === 'textArea' ||
      child.type === 'numberInput' ||
      child.type === 'select' ||
      child.type === 'slider' ||
      child.type === 'dateInput' ||
      child.type === 'colorInput'
    ) {
      if (typeof child.default === 'string') {
        defaults[child.name] = child.default;
      }
    } else if (child.type === 'checkbox') {
      if (typeof child.default === 'boolean') {
        defaults[child.name] = child.default;
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
    // Collect required-field errors from direct children only.
    const newErrors: Record<string, string> = {};
    for (const child of block.children) {
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

    const payload: Record<string, unknown> = { ...values };

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
        {children}
        {serverIssues !== null && serverIssues.length > 0 && (
          <IssuesReport issues={serverIssues} />
        )}
      </div>
    </FormBlockContext.Provider>
  );
}
