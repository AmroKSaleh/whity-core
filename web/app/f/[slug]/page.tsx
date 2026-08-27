'use client';

/**
 * The page a PUBLIC form link actually opens.
 *
 * WHY THIS EXISTS. The public form API shipped first and `public_url` pointed
 * straight at it, so the link a tenant copied and sent to an applicant returned
 * raw JSON in their browser. An endpoint is not a form. This is the half a
 * person sees.
 *
 * IT IS DELIBERATELY OUTSIDE `(protected)`. The whole point is that the reader
 * has no account, so nothing here may touch the auth context or any chrome that
 * assumes a session. It sits beside `login` and `verify/[token]` — the other
 * pages a stranger can open.
 *
 * IT ASKS FOR NOTHING IT DOES NOT NEED. No name, no email, no "who are you";
 * the form's own fields are the whole ask. Prefill cannot resolve for an
 * anonymous caller and the API sends none, so nothing here could quietly
 * identify the person filling it in.
 *
 * ONE REFUSAL SHAPE. A wrong slug, a withdrawn link, an unpublished form and a
 * malformed slug all return the same 404 by design, so a stranger cannot probe
 * which links exist. This page echoes the server's wording rather than guessing
 * a more specific reason and undoing that.
 */

import { use, useEffect, useMemo, useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Button } from '@amroksaleh/ui/button';
import { useDirection } from '@/lib/direction-context';
import {
  FormField,
  localized,
  type Answer,
  type FormFieldSpec,
  type LocalizedText,
} from '@/components/forms/form-fields';

interface PublicForm {
  slug: string;
  name: LocalizedText | string | null;
  description: string | null;
  fields: FormFieldSpec[];
  accepts_submissions: boolean;
  opens_at: string | null;
  closes_at: string | null;
}

export default function PublicFormPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = use(params);
  const { dir } = useDirection();
  const preferArabic = dir === 'rtl';

  const [form, setForm] = useState<PublicForm | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [answers, setAnswers] = useState<Record<string, Answer>>({});
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [submitted, setSubmitted] = useState(false);

  useEffect(() => {
    let cancelled = false;

    fetch(`/api/v1/public/forms/${encodeURIComponent(slug)}`, { headers: { Accept: 'application/json' } })
      .then(async (response) => {
        const body = (await response.json().catch(() => ({}))) as { data?: PublicForm; error?: string };
        if (cancelled) return;
        if (!response.ok || !body.data) {
          setLoadError(body.error ?? 'This form link is not valid, or is no longer open.');

          return;
        }
        setForm(body.data);
      })
      .catch(() => {
        if (!cancelled) setLoadError('This form could not be loaded. Check your connection and try again.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [slug]);

  const ordered = useMemo(
    () => (form?.fields ?? []).slice().sort((a, b) => a.position - b.position),
    [form]
  );

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    if (submitting || form === null) return;

    setSubmitting(true);
    setSubmitError(null);
    try {
      const response = await fetch(`/api/v1/public/forms/${encodeURIComponent(slug)}/submissions`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ data: answers }),
      });
      const body = (await response.json().catch(() => ({}))) as { error?: string };
      if (!response.ok) {
        setSubmitError(body.error ?? 'This could not be submitted. Please check your answers and try again.');

        return;
      }
      setSubmitted(true);
    } catch {
      setSubmitError('This could not be submitted. Check your connection and try again.');
    } finally {
      setSubmitting(false);
    }
  }

  const shell = (children: React.ReactNode) => (
    <main className="flex min-h-screen items-start justify-center bg-background px-4 py-10">
      <div className="w-full max-w-2xl">{children}</div>
    </main>
  );

  if (loading) {
    return shell(
      <Card>
        <CardContent className="py-10 text-center text-sm text-muted-foreground">Loading…</CardContent>
      </Card>
    );
  }

  if (loadError !== null || form === null) {
    return shell(
      <Card>
        <CardHeader>
          <CardTitle>This form is not available</CardTitle>
          <CardDescription>{loadError}</CardDescription>
        </CardHeader>
      </Card>
    );
  }

  const title = localized(form.name, preferArabic) || 'Form';

  if (submitted) {
    return shell(
      <Card>
        <CardHeader>
          <CardTitle>Thank you — your response has been recorded</CardTitle>
          <CardDescription>{title} was submitted successfully. You may close this page.</CardDescription>
        </CardHeader>
      </Card>
    );
  }

  return shell(
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        {form.description !== null && form.description !== '' && (
          <CardDescription>{form.description}</CardDescription>
        )}
      </CardHeader>

      <CardContent>
        {!form.accepts_submissions ? (
          <p className="py-6 text-sm text-muted-foreground">
            This form is not accepting responses
            {form.closes_at !== null ? ` (it closed on ${form.closes_at})` : ''}.
          </p>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-6" noValidate>
            {ordered.map((field) => (
              <FormField
                key={field.field_key}
                field={field}
                value={answers[field.field_key]}
                preferArabic={preferArabic}
                onChange={(v) => setAnswers((prev) => ({ ...prev, [field.field_key]: v }))}
              />
            ))}

            {submitError !== null && (
              <p className="text-sm text-destructive" role="alert">
                {submitError}
              </p>
            )}

            <Button type="submit" disabled={submitting}>
              {submitting ? 'Submitting…' : 'Submit'}
            </Button>
          </form>
        )}
      </CardContent>
    </Card>
  );
}
