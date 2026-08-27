'use client';

/**
 * Filling a PUBLISHED form as a signed-in member.
 *
 * WHY THERE ARE TWO FILL PAGES. A published form is not necessarily a public
 * one. Opening a form to the world is a deliberate, separate act — so a form
 * that is merely published needs an address its own people can use, and that
 * address is this one. `/f/{slug}` is the other case: a link deliberately
 * handed to somebody with no account.
 *
 * WHAT DIFFERS BESIDES WHO MAY OPEN IT. Prefill. A signed-in member's saved
 * details resolve here and seed the answers, which is exactly what must NOT
 * happen anonymously — so the two pages call different endpoints rather than
 * one endpoint behaving differently depending on who asked. Any field the
 * server could not resolve a prefill for is reported rather than silently left
 * blank, because "we tried to fill this and could not" and "this was never
 * meant to be filled" look identical once the box is empty.
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
  type ReferenceOption,
} from '@/components/forms/form-fields';

interface RenderedForm {
  form: {
    id: number;
    name: LocalizedText | string | null;
    description: string | null;
    status: string;
    public_enabled?: boolean;
    public_url?: string | null;
  };
  fields: FormFieldSpec[];
  prefill: Record<string, unknown>;
  unresolved_prefill: string[];
  accepts_submissions: boolean;
}

export default function FillFormPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { dir } = useDirection();
  const preferArabic = dir === 'rtl';

  const [rendered, setRendered] = useState<RenderedForm | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [answers, setAnswers] = useState<Record<string, Answer>>({});
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [submitted, setSubmitted] = useState(false);
  const [units, setUnits] = useState<ReferenceOption[]>([]);

  // The unit list for any `ou_ref` field. Fetched by the PAGE rather than by the
  // field, because whether this reader may see a list of the tenant's units is a
  // question about the reader, not about the field — and the public page
  // deliberately never asks it.
  useEffect(() => {
    let cancelled = false;

    fetch('/api/v1/ous', { headers: { Accept: 'application/json' } })
      .then(async (response) => {
        if (!response.ok) return;
        const body = (await response.json().catch(() => ({}))) as {
          data?: Array<{ id?: number; name?: string }>;
        };
        if (cancelled) return;
        setUnits(
          (body.data ?? [])
            .filter((ou): ou is { id: number; name: string } =>
              typeof ou.id === 'number' && typeof ou.name === 'string')
            .map((ou) => ({ value: String(ou.id), label: ou.name }))
        );
      })
      .catch(() => {
        // A missing list leaves the picker empty and the field refusable by the
        // server; it must not take the whole form down with it.
      });

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    let cancelled = false;

    fetch(`/api/v1/forms/${encodeURIComponent(id)}/render`, { headers: { Accept: 'application/json' } })
      .then(async (response) => {
        const body = (await response.json().catch(() => ({}))) as { data?: RenderedForm; error?: string };
        if (cancelled) return;
        if (!response.ok || !body.data) {
          setLoadError(body.error ?? 'This form could not be opened.');

          return;
        }
        setRendered(body.data);

        // Seed from the server's resolved prefill. Only strings are seeded: a
        // prefill that arrived as some other shape is a mismatch between the
        // source and the field, and guessing a coercion would put a value in
        // front of somebody as though they had entered it.
        const seed: Record<string, Answer> = {};
        for (const [key, value] of Object.entries(body.data.prefill ?? {})) {
          if (typeof value === 'string' && value !== '') seed[key] = value;
        }
        setAnswers(seed);
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
  }, [id]);

  const ordered = useMemo(
    () => (rendered?.fields ?? []).slice().sort((a, b) => a.position - b.position),
    [rendered]
  );

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    if (submitting || rendered === null) return;

    setSubmitting(true);
    setSubmitError(null);
    try {
      const response = await fetch(`/api/v1/forms/${encodeURIComponent(id)}/submissions`, {
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

  if (loading) {
    return (
      <Card>
        <CardContent className="py-10 text-center text-sm text-muted-foreground">Loading…</CardContent>
      </Card>
    );
  }

  if (loadError !== null || rendered === null) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>This form is not available</CardTitle>
          <CardDescription>{loadError}</CardDescription>
        </CardHeader>
      </Card>
    );
  }

  const title = localized(rendered.form.name, preferArabic) || 'Form';

  if (submitted) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Submitted</CardTitle>
          <CardDescription>
            {title} was recorded. It appears under your own submissions.
          </CardDescription>
        </CardHeader>
      </Card>
    );
  }

  return (
    <div className="mx-auto w-full max-w-2xl space-y-4">
      <Card>
        <CardHeader>
          <CardTitle>{title}</CardTitle>
          {rendered.form.description !== null && rendered.form.description !== '' && (
            <CardDescription>{rendered.form.description}</CardDescription>
          )}
        </CardHeader>

        <CardContent>
          {!rendered.accepts_submissions ? (
            <p className="py-6 text-sm text-muted-foreground">
              This form is not accepting responses. Only a published form can be filled in.
            </p>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-6" noValidate>
              {ordered.map((field) => (
                <FormField
                  key={field.field_key}
                  field={field}
                  value={answers[field.field_key]}
                  preferArabic={preferArabic}
                  references={{ ou_ref: units }}
                  onChange={(v) => setAnswers((prev) => ({ ...prev, [field.field_key]: v }))}
                />
              ))}

              {rendered.unresolved_prefill.length > 0 && (
                <p className="text-xs text-muted-foreground">
                  Some fields could not be filled in from your saved details (
                  {rendered.unresolved_prefill.join(', ')}) — please complete them yourself.
                </p>
              )}

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
    </div>
  );
}
