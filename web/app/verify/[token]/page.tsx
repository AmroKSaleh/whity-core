'use client';

/**
 * The PUBLIC document verification page (#1036) — what a QR code opens.
 *
 * Lives OUTSIDE the `(protected)` and `(editor)` route groups, so it renders
 * with no session, which is the point: the person reading it is a courier, a
 * ministry clerk or a citizen holding a printed decision, and the paper is the
 * whole of their relationship with this system. It must never depend on auth,
 * on a tenant, or on any app chrome that does.
 *
 * WHY THE URL IS THIS AND NOT THE API PATH. The QR payload is what a phone
 * camera opens in a browser, so it has to be a human page. `/api/v1/...` would
 * hand a courier raw JSON. The API path this page fetches is versioned, once,
 * here — the version-prefix trap that broke every document viewer for a release
 * (`content_url` emitted `/api/documents/...` while the router served
 * `/api/v1/documents/...`) is a trap for EMITTED paths, and this one is a
 * literal in a client rather than a path built server-side.
 *
 * ARABIC / RTL IS A HARD REQUIREMENT HERE, harder than anywhere else in the app:
 * this is the surface most likely to be read by somebody outside the
 * organisation, and for an Arabic-speaking institution that is most readers.
 * The page is DIRECTION-AGNOSTIC by construction — no physical margins, no
 * `text-left`, no `ml-`/`pr-` anywhere — so it mirrors entirely from `<html dir>`,
 * which `DirectionProvider` in the root layout sets from the chosen language.
 * The one exception is the reference code, which is pinned `dir="ltr"`: it is a
 * Latin-alphanumeric group that bidi reordering would otherwise scramble inside
 * an Arabic sentence, and a verification reference that reads differently from
 * the paper is worse than no reference.
 *
 * WHAT THIS PAGE CANNOT SHOW, because the endpoint cannot return it: the
 * document id, its title, its content, its recipients, or any person's or
 * unit's name. The "open the record" button appears only when a SIGNED-IN
 * caller asks a second, ordinary, RBAC-gated endpoint and it answers — so an
 * anonymous reader never learns the record exists beyond what is on this page,
 * and a signed-in reader without reach gets exactly the same page an anonymous
 * one does.
 */

import { use, useEffect, useState } from 'react';
import Link from 'next/link';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Button } from '@amroksaleh/ui/button';
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';

/** The wire shape of `GET /api/v1/document-verifications/{token}`. */
type Verification = {
  verified: boolean;
  reason?: string;
  revoked_on?: string | null;
  reference?: string;
  issuer?: string;
  issued_on?: string | null;
  stage?: string;
  stage_on?: string | null;
};

/**
 * The routing verbs the `stage` disclosure level can return.
 *
 * @i18n-keys documents
 *   verify.stage.issued = Issued
 *   verify.stage.forwarded = Forwarded on
 *   verify.stage.acknowledged = Acknowledged
 *   verify.stage.returned = Returned
 *   verify.stage.noted = Noted
 */
const STAGE_LABELS: Record<string, { key: string; text: string }> = {
  issued: { key: 'verify.stage.issued', text: 'Issued' },
  forwarded: { key: 'verify.stage.forwarded', text: 'Forwarded on' },
  acknowledged: { key: 'verify.stage.acknowledged', text: 'Acknowledged' },
  returned: { key: 'verify.stage.returned', text: 'Returned' },
  noted: { key: 'verify.stage.noted', text: 'Noted' },
};

/**
 * The sentence for each way a code can fail.
 *
 * `unrecognised` is what the server says by default for an unknown code, a
 * malformed one, a withdrawn one and a superseded one alike — so the copy must
 * not imply the reader mistyped something, and must not imply a document exists.
 * The other two appear only when the tenant chose to disclose them.
 *
 * @i18n-keys documents
 *   verify.refused.unrecognised = This code is not recognised
 *   verify.refused.unrecognisedBody = We cannot confirm a document from this code. It may never have been issued here, or it may no longer be honoured.
 *   verify.refused.withdrawn = This code has been withdrawn
 *   verify.refused.withdrawnBody = The organisation has stopped standing behind the copy this code was printed on.
 *   verify.refused.superseded = This copy has been replaced
 *   verify.refused.supersededBody = A newer version of this document has been issued, so the copy you are holding is not the current one.
 */
const REFUSAL_COPY: Record<string, { titleKey: string; title: string; bodyKey: string; body: string }> = {
  unrecognised: {
    titleKey: 'verify.refused.unrecognised',
    title: 'This code is not recognised',
    bodyKey: 'verify.refused.unrecognisedBody',
    body:
      'We cannot confirm a document from this code. It may never have been issued here, ' +
      'or it may no longer be honoured.',
  },
  withdrawn: {
    titleKey: 'verify.refused.withdrawn',
    title: 'This code has been withdrawn',
    bodyKey: 'verify.refused.withdrawnBody',
    body: 'The organisation has stopped standing behind the copy this code was printed on.',
  },
  superseded: {
    titleKey: 'verify.refused.superseded',
    title: 'This copy has been replaced',
    bodyKey: 'verify.refused.supersededBody',
    body:
      'A newer version of this document has been issued, so the copy you are holding is not the current one.',
  },
};

type Phase =
  | { kind: 'loading' }
  | { kind: 'ready'; data: Verification }
  | { kind: 'throttled' }
  | { kind: 'error' };

export default function VerifyDocumentPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = use(params);
  const t = useTranslation('documents');

  const [phase, setPhase] = useState<Phase>({ kind: 'loading' });
  // The signed-in follow-through. `null` means "no record reachable by this
  // reader", which is both the anonymous case and the signed-in-without-reach
  // case — deliberately the same state, so the page renders identically for
  // both and cannot be used to tell them apart.
  const [recordId, setRecordId] = useState<number | null>(null);

  useEffect(() => {
    let cancelled = false;

    // No credentials: this request must behave identically for a signed-in
    // reader and a stranger, and sending a cookie would make the response
    // depend on who is asking on a surface whose whole design is that it does
    // not.
    fetch(`/api/v1/document-verifications/${encodeURIComponent(token)}`, {
      headers: { Accept: 'application/json' },
    })
      .then(async (response) => {
        if (cancelled) return;
        if (response.status === 429) {
          setPhase({ kind: 'throttled' });
          return;
        }
        if (!response.ok) {
          setPhase({ kind: 'error' });
          return;
        }
        const body = (await response.json()) as { data?: Verification };
        setPhase(body.data ? { kind: 'ready', data: body.data } : { kind: 'error' });
      })
      .catch(() => {
        if (!cancelled) setPhase({ kind: 'error' });
      });

    return () => {
      cancelled = true;
    };
  }, [token]);

  useEffect(() => {
    let cancelled = false;

    // The SECOND request, and the only one that carries a session. It is an
    // ordinary RBAC-gated route: it answers 404 for a reader without reach and
    // 401/403 for one with no session at all, and every one of those outcomes
    // lands in the same silent branch. Failing quietly is correct here — the
    // absence of the button is not an error, it is the answer.
    fetch(`/api/v1/documents/by-verification/${encodeURIComponent(token)}`, {
      credentials: 'include',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(async (response) => {
        if (cancelled || !response.ok) return;
        const body = (await response.json()) as { data?: { id?: number } };
        if (typeof body.data?.id === 'number') setRecordId(body.data.id);
      })
      .catch(() => {
        /* no record for this reader; the button simply does not appear */
      });

    return () => {
      cancelled = true;
    };
  }, [token]);

  return (
    <main className="flex min-h-screen items-center justify-center bg-muted/30 p-6">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle data-testid="verify-title" dir="auto">{headline(t, phase)}</CardTitle>
          <CardDescription data-testid="verify-body" dir="auto">{body(t, phase)}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {phase.kind === 'ready' && phase.data.verified && (
            <dl className="space-y-3 text-sm">
              <Fact label={t('verify.field.issuer', 'Issued by')} value={phase.data.issuer} />
              <Fact label={t('verify.field.issuedOn', 'Issued on')} value={phase.data.issued_on} />
              <Fact
                label={t('verify.field.reference', 'Reference')}
                value={phase.data.reference}
                /* Latin-alphanumeric; bidi reordering would scramble it. */
                monospaceLtr
              />
              {phase.data.stage !== undefined && (
                <Fact
                  label={t('verify.field.stage', 'Current stage')}
                  value={stageLabel(t, phase.data.stage)}
                />
              )}
            </dl>
          )}

          {phase.kind === 'ready' && !phase.data.verified && phase.data.revoked_on && (
            <dl className="space-y-3 text-sm">
              <Fact label={t('verify.field.withdrawnOn', 'Withdrawn on')} value={phase.data.revoked_on} />
            </dl>
          )}

          {recordId !== null && (
            <Button asChild className="w-full" data-testid="verify-open-record">
              <Link href={`/admin/document-library/${recordId}`}>
                {t('verify.openRecord', 'Open the full record')}
              </Link>
            </Button>
          )}

          <p className="text-xs text-muted-foreground" dir="auto">
            {footer(t, phase)}
          </p>
        </CardContent>
      </Card>
    </main>
  );
}

/**
 * One label/value pair, direction-agnostic.
 *
 * `text-start`/`text-end` rather than left/right so the whole row mirrors from
 * `<html dir>` with no per-language branch. Absent values render nothing at all
 * rather than an empty row — a blank beside "Issued by" reads as a broken page
 * on the one surface that has to look trustworthy.
 */
function Fact({
  label,
  value,
  monospaceLtr = false,
}: {
  label: string;
  value?: string | null;
  monospaceLtr?: boolean;
}) {
  if (value === undefined || value === null || value === '') return null;

  return (
    <div className="flex items-baseline justify-between gap-4">
      <dt className="text-muted-foreground text-start" dir="auto">{label}</dt>
      <dd
        className={`font-medium text-end ${monospaceLtr ? 'font-mono' : ''}`}
        dir={monospaceLtr ? 'ltr' : 'auto'}
      >
        {value}
      </dd>
    </div>
  );
}

function headline(t: TranslateFn, phase: Phase): string {
  if (phase.kind === 'loading') return t('verify.checking', 'Checking this document…');
  if (phase.kind === 'throttled') return t('verify.throttled', 'Too many checks');
  if (phase.kind === 'error') return t('verify.error', 'Verification is unavailable');
  if (phase.data.verified) return t('verify.genuine', 'This document is genuine');

  const copy = REFUSAL_COPY[phase.data.reason ?? 'unrecognised'] ?? REFUSAL_COPY.unrecognised;

  return t(copy.titleKey, copy.title);
}

/**
 * The small print, and the reason it is not one sentence.
 *
 * A browser pass caught this saying "This page confirms only that Whity issued
 * a document" on a page that had just REFUSED a code — asserting a confirmation
 * that had not happened, under the platform's own brand name rather than the
 * issuing organisation's. Both halves were wrong and neither could fail a test:
 * the string rendered perfectly, on top of an answer it contradicted.
 *
 * So the confirming sentence exists only where something was confirmed, and it
 * names the ISSUER from the payload — the organisation this page is vouching
 * for — never `branding.siteName`, which on an unauthenticated page is the
 * platform's name and is not who issued anything.
 */
function footer(t: TranslateFn, phase: Phase): string {
  if (phase.kind === 'ready' && phase.data.verified && phase.data.issuer) {
    return t(
      'verify.footerVerified',
      'This page confirms only that {org} issued a document on the date shown. It does not show the document or its contents.',
      { org: phase.data.issuer },
    );
  }

  return t(
    'verify.footerGeneric',
    'This page checks codes printed on documents issued through this service. It never shows a document or its contents.',
  );
}

function body(t: TranslateFn, phase: Phase): string {
  if (phase.kind === 'loading') return '';
  if (phase.kind === 'throttled') {
    return t('verify.throttledBody', 'Please wait a little while and scan again.');
  }
  if (phase.kind === 'error') {
    return t('verify.errorBody', 'We could not check this code just now. Please try again shortly.');
  }
  if (phase.data.verified) {
    return t('verify.genuineBody', 'It was issued by the organisation named below, on the date shown.');
  }

  const copy = REFUSAL_COPY[phase.data.reason ?? 'unrecognised'] ?? REFUSAL_COPY.unrecognised;

  return t(copy.bodyKey, copy.body);
}

/**
 * An UNKNOWN verb renders as itself rather than as an empty string.
 *
 * The server constrains `stage` to a closed vocabulary, so this branch should be
 * unreachable — but a client that silently blanks a value it does not recognise
 * is a client that hides the day the vocabulary grows.
 */
function stageLabel(t: TranslateFn, stage: string): string {
  const label = STAGE_LABELS[stage];

  return label ? t(label.key, label.text) : stage;
}
