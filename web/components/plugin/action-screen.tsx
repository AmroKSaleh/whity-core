'use client';

import { useState } from 'react';
import { apiClient } from '@/lib/api-client';
import type { PluginFeature } from '@/lib/plugin-features';
import { useToast } from '@/lib/toast-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { IconAlertTriangle } from '@tabler/icons-react';

/**
 * Generic "action" screen (WC-169 follow-up): renders the form declared by a
 * `screen: 'action'` feature descriptor and submits it to the descriptor's
 * route as a JSON body. A `file` field is read client-side as TEXT into its
 * named property (the host is a JSON API). On success the screen downloads a
 * returned file (response carries `Content-Disposition`) or reports success;
 * on a 4xx it renders the server's JSON report (an `issues` array) or error.
 *
 * Unlike the CRUD screen this needs no OpenAPI fetch — the descriptor's
 * `action.fields` fully describe the form.
 */

/** One issue from a server validation report (best-effort shape). */
interface ActionIssue {
  severity?: string;
  message?: string;
  item?: number | null;
  column?: string | null;
}

function extractIssues(body: unknown): ActionIssue[] | null {
  if (typeof body === 'object' && body !== null && 'issues' in body) {
    const issues = (body as { issues: unknown }).issues;
    if (Array.isArray(issues)) {
      return issues as ActionIssue[];
    }
  }
  return null;
}

function extractError(body: unknown): string | null {
  if (typeof body === 'object' && body !== null && 'error' in body) {
    const error = (body as { error: unknown }).error;
    if (typeof error === 'string') {
      return error;
    }
  }
  return null;
}

function filenameFromDisposition(disposition: string | null, fallback: string): string {
  if (disposition === null) {
    return fallback;
  }
  const match = /filename="?([^";]+)"?/.exec(disposition);
  return match?.[1] ?? fallback;
}

function downloadBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);
}

export function ActionScreen({ feature }: { feature: PluginFeature }) {
  const { addToast } = useToast();
  const action = feature.action;

  const [texts, setTexts] = useState<Record<string, string>>({});
  const [files, setFiles] = useState<Record<string, File | null>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [issues, setIssues] = useState<ActionIssue[] | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  // The page only renders this for action features, but narrow defensively.
  if (action === null) {
    return null;
  }
  const submitAction = action;

  const validate = (): Record<string, string> => {
    const errors: Record<string, string> = {};
    for (const field of submitAction.fields) {
      if (!field.required) {
        continue;
      }
      const filled =
        field.kind === 'file'
          ? files[field.name] instanceof File
          : (texts[field.name] ?? '').trim() !== '';
      if (!filled) {
        errors[field.name] = `${field.label} is required`;
      }
    }
    return errors;
  };

  const buildPayload = async (): Promise<Record<string, string>> => {
    const payload: Record<string, string> = {};
    for (const field of submitAction.fields) {
      if (field.kind === 'file') {
        const file = files[field.name];
        payload[field.name] = file ? await file.text() : '';
      } else {
        payload[field.name] = texts[field.name] ?? '';
      }
    }
    return payload;
  };

  const submit = async (): Promise<void> => {
    const errors = validate();
    setFieldErrors(errors);
    if (Object.keys(errors).length > 0) {
      return;
    }

    setIsSubmitting(true);
    setIssues(null);
    try {
      const payload = await buildPayload();
      const response = await apiClient(submitAction.path, {
        method: submitAction.method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });

      if (response.ok) {
        const disposition = response.headers.get('Content-Disposition');
        const contentType = response.headers.get('Content-Type') ?? '';
        if (disposition !== null || !contentType.includes('application/json')) {
          const blob = await response.blob();
          downloadBlob(blob, filenameFromDisposition(disposition, `${feature.id}.out`));
          addToast('Generated successfully — your download has started', 'success');
        } else {
          addToast('Completed successfully', 'success');
        }
        return;
      }

      const body: unknown = await response.json().catch(() => null);
      const reportIssues = extractIssues(body);
      if (reportIssues !== null) {
        setIssues(reportIssues);
        addToast(`${reportIssues.length} issue(s) — see the report below`, 'error');
        return;
      }
      addToast(extractError(body) ?? `Request failed (HTTP ${response.status})`, 'error');
    } catch (error) {
      addToast(error instanceof Error ? error.message : 'Request failed', 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  const submitLabel = submitAction.submitLabel ?? 'Submit';

  return (
    <div className="space-y-8">
      <AdminHeader title={feature.label} description={`Provided by the ${feature.plugin} plugin.`} />

      <div className="max-w-2xl space-y-5 rounded-lg border border-border bg-card p-6">
        {submitAction.fields.length === 0 && (
          <p className="text-sm text-muted-foreground">This action takes no input.</p>
        )}

        {submitAction.fields.map((field) => {
          const inputId = `action-field-${field.name}`;
          const error = fieldErrors[field.name];
          return (
            <div key={field.name} className="space-y-2">
              <label htmlFor={inputId} className="text-sm font-medium">
                {field.label}
                {field.required && <span className="text-destructive"> *</span>}
              </label>

              {field.kind === 'file' ? (
                <Input
                  id={inputId}
                  type="file"
                  accept={field.accept ?? undefined}
                  onChange={(event) => {
                    const file = event.target.files?.[0] ?? null;
                    setFiles((current) => ({ ...current, [field.name]: file }));
                  }}
                />
              ) : field.kind === 'textarea' ? (
                <Textarea
                  id={inputId}
                  value={texts[field.name] ?? ''}
                  onChange={(event) =>
                    setTexts((current) => ({ ...current, [field.name]: event.target.value }))
                  }
                />
              ) : (
                <Input
                  id={inputId}
                  type="text"
                  value={texts[field.name] ?? ''}
                  onChange={(event) =>
                    setTexts((current) => ({ ...current, [field.name]: event.target.value }))
                  }
                />
              )}

              {error && <p className="text-xs text-destructive">{error}</p>}
            </div>
          );
        })}

        <Button onClick={() => void submit()} disabled={isSubmitting}>
          {isSubmitting ? 'Working…' : submitLabel}
        </Button>
      </div>

      {issues !== null && issues.length > 0 && (
        <div className="max-w-2xl space-y-2 rounded-lg border border-border bg-card p-6">
          <div className="flex items-center gap-2">
            <IconAlertTriangle size={18} className="text-destructive" />
            <h2 className="font-heading text-sm font-medium">Validation report</h2>
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
                    isError ? 'border-destructive' : 'border-yellow-500'
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
      )}
    </div>
  );
}
