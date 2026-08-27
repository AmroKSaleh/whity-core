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
  type UploadedFileRef,
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

  /**
   * Upload one file and hand back the reference the `file` answer will carry.
   *
   * MULTIPART via FormData, and the `Content-Type` header is deliberately NOT
   * set: the browser has to write it itself so it can include the boundary it
   * generated. Setting it by hand produces a body no parser can read, with a
   * header that says it should be readable.
   *
   * `X-Requested-With` because this call carries the session cookie, and
   * CsrfGuard requires the custom header on any state-changing request with an
   * ambient credential. A cross-site page cannot set it without a preflight the
   * origin allowlist refuses.
   *
   * THROWS the server's own sentence. It is the only party that knows the size
   * ceiling and the accepted kinds, and it already writes them for a person —
   * "That file is too large — the limit is 10 MB." beats anything this page
   * could invent from a status code.
   */
  async function uploadAttachment(file: File): Promise<UploadedFileRef> {
    const body = new FormData();
    body.append('file', file);

    const response = await fetch(`/api/v1/forms/${encodeURIComponent(id)}/uploads`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body,
    });
    const payload = (await response.json().catch(() => ({}))) as {
      data?: UploadedFileRef;
      error?: string;
    };
    if (!response.ok || payload.data === undefined) {
      throw new Error(payload.error ?? 'That file could not be uploaded. Please try again.');
    }

    return payload.data;
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    if (submitting || rendered === null) return;

    setSubmitting(true);
    setSubmitError(null);
    try {
      const response = await fetch(`/api/v1/forms/${encodeURIComponent(id)}/submissions`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          // The CSRF guard requires this on a cookie-authenticated write. It is
          // NOT optional here: a bearer token is exempt from the guard, so every
          // curl check of this endpoint passed while the browser — which has a
          // session cookie and no token — was refused with "cross-site request
          // rejected". The uploader already sent it; the submit did not.
          'X-Requested-With': 'XMLHttpRequest',
        },
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
                  upload={uploadAttachment}
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
