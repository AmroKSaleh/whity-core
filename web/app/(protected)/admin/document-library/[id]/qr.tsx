'use client';

/**
 * The VERIFICATION CODE region of the document record page (#1052, over #1036).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS SCREEN EXISTS AT ALL
 * ─────────────────────────────────────────────────────────────────────────────
 * A QR printed on paper is a bearer token in the physical world. Anyone who
 * photographs the sheet holds it permanently, and paper cannot be recalled — so
 * REVOCATION IS THE ONLY CONTROL the organisation keeps over a code already in
 * circulation. #1036 shipped that control as three API routes and no screen,
 * which left the single safety mechanism for the one irreversible thing as the
 * hardest thing in the product to reach. This is the panel that reaches it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE FOUR THINGS THIS PANEL IS NOT ALLOWED TO GET WRONG
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * 1. REVOCATION IS A ONE-WAY LATCH. `document_qr_tokens.revoked_at` is set under
 *    a `WHERE revoked_at IS NULL` predicate, so a second withdrawal matches
 *    nothing and the first timestamp is the one that survives. There is
 *    therefore NO un-withdraw, and this panel must not imply one exists: the
 *    withdraw control is rendered only while a live code exists, the confirm
 *    says the act cannot be reversed, and the copy for "issue a new code" is
 *    careful to call it a DIFFERENT code rather than a restoration.
 *
 * 2. MINTING AND RE-RENDERING ARE DIFFERENT ACTS. `POST /qr` always rotates and
 *    retires the previous code as `superseded`; re-rendering a document calls
 *    `ensure()`, which never rotates. So there is no "refresh" button here —
 *    that word would blur an act that voids every printed copy into an act that
 *    does not. And because minting does not re-render, the new code appears on
 *    NOTHING until the document is issued again, which the confirm says out loud
 *    because it is the least obvious consequence of pressing it.
 *
 * 3. THE SCAN TRAIL RECORDS THE ACT, NEVER THE ACTOR. Migration 122 gives
 *    `document_qr_scans` no address, no user-agent, no device and no location
 *    columns — the guarantee is structural, not a promise. This panel says so
 *    where a reader would otherwise assume the columns exist but are empty, and
 *    it never renders a public scan as an unnamed person. A "visitor" here would
 *    be a fiction.
 *
 * 4. A REVOKED CODE AND AN UNKNOWN ONE ARE THE SAME ANSWER PUBLICLY, by default,
 *    and that is deliberate anti-enumeration. So nothing here claims what a
 *    scanner was TOLD. `refused` means the server did not confirm the document;
 *    whether the holder learned why is the tenant's `documents.qr_public_detail`
 *    decision, which this panel neither reads nor surfaces.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * `token: null` IS TWO DIFFERENT FACTS
 * ─────────────────────────────────────────────────────────────────────────────
 * Never minted, and withdrawn. Reporting both as "this document has no
 * verification code" would be a true-sounding sentence that is false in the
 * state that matters: an operator who has just withdrawn a code would be told
 * the document simply has none, hiding that paper is in the field carrying a
 * symbol the server has stopped honouring. `retired` is what separates them, and
 * every other sentence in this file was written by asking which states it
 * renders in and whether it is true in all of them.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS COMPONENT FETCHES FOR ITSELF
 * ─────────────────────────────────────────────────────────────────────────────
 * Unlike the trail and the recipient list, which the screen loads through
 * `useRecordResource` and hands down, this panel owns its own request. Two
 * reasons, both about writes:
 *
 *   - IT REFETCHES AFTER A MUTATION, and `useRecordResource` returns to
 *     `{status:'loading'}` when its deps change. A panel that rendered a
 *     skeleton on every refetch would UNMOUNT its own content mid-interaction —
 *     the defect #1041's browser pass found next door, where a panel discarded
 *     the answer it had just been given. Here the last good payload stays on
 *     screen and is REPLACED, never blanked, so the operator never watches the
 *     code they are working on disappear and come back.
 *   - THE HIDDEN-REGION RULE HOLDS STRUCTURALLY. `RecordSection` returns null
 *     for a hidden region, so this component is never mounted and its effect
 *     never runs. The screen does not need a boolean to remember not to fetch:
 *     there is nothing to remember.
 *
 * WHO DECIDES WHETHER THE BUTTONS EXIST. Not this file. `canWrite` is the
 * server's `qr` region verdict arriving through `sectionAccessFrom` — resolved
 * against the same `RoleChecker` the middleware enforces with, plus a record
 * predicate no route table could express. This component renders what it was
 * told and holds no opinion about authorization.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@amroksaleh/ui/alert';
import { Badge } from '@amroksaleh/ui/badge';
import { Button } from '@amroksaleh/ui/button';
import { Separator } from '@amroksaleh/ui/separator';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@amroksaleh/ui/table';
import { BarcodeSvg } from '@amroksaleh/ui/documents/barcode-svg';
import { RecordList, RecordListItem, formatRecordDateTime } from '@amroksaleh/features/record';
import { useFormattingLocale, useTranslation } from '@amroksaleh/features/i18n';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';

import { personName } from './trail';
import type { Directory, DocumentQrPanelData, QrRetiredCode } from './types';

/** The translator shape this file shares with the screen above it. */
type Translate = (
  key: string,
  fallback?: string,
  vars?: Record<string, string | number>
) => string;

/** Minimal shape of the app's fetch wrapper — the panel needs nothing more. */
type ApiClient = (path: string, init?: RequestInit) => Promise<Response>;

/** Which confirm is open, if any. Both are destructive in different ways. */
type PendingAct = 'mint' | 'withdraw' | null;

export interface DocumentQrPanelProps {
  documentId: number;
  /** Named in both confirms, so the operator is sure which document they are acting on. */
  documentTitle: string;
  /**
   * How many versions this document has issued.
   *
   * Used in the withdraw confirm to say how much paper the code is on. It comes
   * from the record's own `artifacts` array — a count of rows the server sent,
   * never a stored counter — so it cannot be stale relative to the page.
   */
  versionCount: number;
  /** Names for the ids, and whether this caller was allowed to look them up. */
  directory: Directory;
  /**
   * The SERVER's `qr` region verdict, folded to a boolean by the screen.
   *
   * `true` means the caller may rotate or withdraw. It is not re-derived here
   * and no permission slug appears in this file: a client that combined
   * permissions into a third answer the deployment never granted would be
   * holding an opinion about authorization (#910).
   */
  canWrite: boolean;
  /**
   * The screen's "ids are showing because you may not read the directory"
   * notice, rendered ONLY when this panel actually numbers somebody.
   *
   * Passed as a node rather than rebuilt here so there is one copy of that
   * explanation on the page — two copies is two chances for them to explain it
   * differently, which is the reason the screen owns it. But the SCREEN cannot
   * know whether this panel has a person to number: the panel fetches for
   * itself, and a document with no code and no signed-in scanner shows nobody.
   * Rendering the notice there anyway would put a true sentence on screen that
   * applies to nothing in front of the reader.
   */
  directoryNotice?: ReactNode;
  apiClient: ApiClient;
}

export function DocumentQrPanel({
  documentId,
  documentTitle,
  versionCount,
  directory,
  canWrite,
  directoryNotice,
  apiClient,
}: DocumentQrPanelProps) {
  const t = useTranslation('documents');
  const locale = useFormattingLocale();

  const [data, setData] = useState<DocumentQrPanelData | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [actError, setActError] = useState<string | null>(null);
  const [busy, setBusy] = useState<'mint' | 'withdraw' | null>(null);
  const [pending, setPending] = useState<PendingAct>(null);

  // Guards the two async paths against a late resolve landing on an unmounted
  // tree — the same cancellation `useRecordResource` does, written here because
  // this panel owns its own requests.
  const live = useRef(true);
  useEffect(() => {
    live.current = true;
    return () => {
      live.current = false;
    };
  }, []);

  const read = useCallback(async (): Promise<DocumentQrPanelData | null> => {
    const response = await apiClient(`/api/v1/documents/${documentId}/qr`);
    if (!response.ok) {
      throw new Error(String(response.status));
    }
    const body = (await response.json()) as { data?: Partial<DocumentQrPanelData> };
    return body.data === undefined ? null : normalize(body.data);
  }, [apiClient, documentId]);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      try {
        const next = await read();
        if (cancelled) return;
        setData(next);
        setLoadError(null);
      } catch {
        if (cancelled) return;
        // The panel's own failure, kept out of the page's: the document, its
        // versions and its trail are all still readable without this region.
        setLoadError(t('record.qr.error.load', "Failed to read this document's verification code"));
      }
    })();
    return () => {
      cancelled = true;
    };
    // `read` is stable for a given document and client; `t` is not a request key.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [documentId]);

  /**
   * Run a mutation, then REPLACE the payload rather than reloading the panel.
   *
   * `setData` is only ever called with a fresh successful read, so a failed
   * refetch leaves the previous — correct — payload on screen beside an error,
   * instead of blanking a panel the operator is mid-decision on.
   */
  const act = useCallback(
    async (kind: 'mint' | 'withdraw') => {
      setActError(null);
      setBusy(kind);
      try {
        const response = await apiClient(`/api/v1/documents/${documentId}/qr`, {
          method: kind === 'mint' ? 'POST' : 'DELETE',
        });
        if (!response.ok) {
          // The server's own sentence where it sent one: a 409 says WHICH switch
          // is off and a 503 says the instance has no address, and both are more
          // useful than "that did not work".
          let detail: string | null = null;
          try {
            const body = (await response.json()) as { error?: unknown };
            detail = typeof body.error === 'string' && body.error !== '' ? body.error : null;
          } catch {
            detail = null;
          }
          setActError(
            detail ??
              (kind === 'mint'
                ? t('record.qr.error.mint', 'The verification code could not be issued')
                : t('record.qr.error.withdraw', 'The verification code could not be withdrawn'))
          );
          return;
        }
        setPending(null);
        const next = await read();
        if (!live.current) return;
        if (next !== null) {
          setData(next);
          setLoadError(null);
        }
      } catch {
        if (!live.current) return;
        setActError(
          kind === 'mint'
            ? t('record.qr.error.mint', 'The verification code could not be issued')
            : t('record.qr.error.withdraw', 'The verification code could not be withdrawn')
        );
      } finally {
        if (live.current) setBusy(null);
      }
    },
    [apiClient, documentId, read, t]
  );

  if (data === null) {
    return loadError !== null ? (
      <p className="text-sm text-destructive" data-testid="document-record-qr-error">
        {loadError}
      </p>
    ) : (
      <p className="text-sm text-muted-foreground" data-testid="document-record-qr-loading">
        {t('record.qr.loading', 'Reading the verification code…')}
      </p>
    );
  }

  const live_ = data.token;
  const lastRetired: QrRetiredCode | null = data.retired.recent[0] ?? null;
  const withdrawn =
    live_ === null && lastRetired !== null && lastRetired.reason === 'withdrawn' ? lastRetired : null;
  const strandedSuperseded =
    live_ === null && lastRetired !== null && lastRetired.reason !== 'withdrawn' ? lastRetired : null;

  // DISABLED BEFORE ANY REQUEST, not after a refusal — the shape #1022 settled
  // for a destructive control next door. The 503 (no public address) and the 409
  // (switched off) stay as backstops rather than as the mechanism, because by
  // the time a refusal arrives the operator has already decided.
  const mintBlocked = !data.configured || !data.enabled;

  // Whether anybody at all is named on this panel. Only these three render a
  // profile id: the retired list shows references and dates, never actors.
  const namesSomebody =
    live_?.issued_by != null ||
    withdrawn?.revoked_by != null ||
    data.scans.recent.some((scan) => scan.scanner_profile_id !== null);

  return (
    <div className="space-y-4" data-testid="document-record-qr">
      {namesSomebody && directoryNotice}

      {!data.configured && (
        <Alert variant="warning" data-testid="document-record-qr-unconfigured">
          <AlertTitle>
            {t('record.qr.unconfigured.title', 'This installation has no public address')}
          </AlertTitle>
          <AlertDescription>
            {t(
              'record.qr.unconfigured.body',
              'A verification code encodes a web address, and nobody has told this installation its own. Until an operator sets APP_URL, a code issued here would lead nowhere.'
            )}
          </AlertDescription>
        </Alert>
      )}

      {!data.enabled && (
        <Alert
          variant={live_ === null ? 'warning' : 'info'}
          data-testid="document-record-qr-disabled"
        >
          <AlertTitle>
            {t('record.qr.disabled.title', 'QR verification is switched off here')}
          </AlertTitle>
          <AlertDescription>
            {live_ === null
              ? t(
                  'record.qr.disabled.body',
                  'It is off for this template or for this organisation, so this document cannot be given a code. The switch is an organisation setting, and a template can opt out of it on its own.'
                )
              : // A live code under a switch that has since been turned off is a
                // real and reachable state, and the honest thing to say about it
                // is that the paper already out there still works.
                t(
                  'record.qr.disabled.stillHonoured',
                  'It has been switched off since this code was issued. The code below is still honoured, and documents issued from now on will not be given one.'
                )}
          </AlertDescription>
        </Alert>
      )}

      {live_ !== null && (
        <div className="flex flex-wrap items-start gap-4" data-testid="document-record-qr-live">
          {data.configured ? (
            // Drawn from the URL the SERVER minted, never from one composed
            // here, so what a phone camera reads and what the server honours
            // cannot drift apart.
            <div className="size-40 shrink-0 rounded-md border border-border bg-white p-2">
              <BarcodeSvg symbology="qrcode" value={live_.verification_url} eclevel="M" />
            </div>
          ) : (
            // With no public address the minted URL is a bare path, and a symbol
            // encoding it would be a picture of something no camera can follow.
            // Better to draw nothing and say so than to draw a working-looking
            // code that is not one.
            <p
              className="text-sm text-muted-foreground"
              data-testid="document-record-qr-no-symbol"
            >
              {t(
                'record.qr.noSymbol',
                'The symbol is not drawn here: with no public address configured, the code has no address to encode.'
              )}
            </p>
          )}

          <div className="min-w-0 flex-1 space-y-2">
            <div className="flex items-center gap-2">
              <Badge variant="success" data-testid="document-record-qr-status">
                {t('record.qr.inForce', 'In force')}
              </Badge>
              <span className="font-mono text-sm font-medium" data-testid="document-record-qr-reference">
                {live_.reference}
              </span>
            </div>

            <p className="text-xs text-muted-foreground" data-testid="document-record-qr-issued">
              {live_.issued_by === null
                ? t('record.qr.issued', 'Issued {when}', {
                    when: formatRecordDateTime(live_.issued_at ?? '', locale) ?? '—',
                  })
                : t('record.qr.issuedBy', 'Issued {when} by {who}', {
                    when: formatRecordDateTime(live_.issued_at ?? '', locale) ?? '—',
                    who: personName(t, directory, live_.issued_by),
                  })}
            </p>

            <p className="text-sm text-muted-foreground">
              {t(
                'record.qr.whatItDoes',
                'Scanning it opens a public page that confirms this document is genuine and names this organisation. The page shows no content, no recipients and nobody’s name — and it grants nothing: somebody who may not read this document is refused exactly as they are today, whether or not they hold the paper.'
              )}
            </p>

            <p className="break-all font-mono text-xs text-muted-foreground" data-testid="document-record-qr-url">
              {live_.verification_url}
            </p>
          </div>
        </div>
      )}

      {withdrawn !== null && (
        <Alert variant="destructive" data-testid="document-record-qr-withdrawn">
          <AlertTitle>{t('record.qr.withdrawn.title', 'This code was withdrawn')}</AlertTitle>
          <AlertDescription>
            <p>
              {withdrawn.revoked_by === null
                ? t('record.qr.withdrawn.when', 'Reference {reference} stopped being honoured {when}.', {
                    reference: withdrawn.reference,
                    when: formatRecordDateTime(withdrawn.revoked_at, locale) ?? withdrawn.revoked_at,
                  })
                : t(
                    'record.qr.withdrawn.whenBy',
                    'Reference {reference} stopped being honoured {when}, withdrawn by {who}.',
                    {
                      reference: withdrawn.reference,
                      when:
                        formatRecordDateTime(withdrawn.revoked_at, locale) ?? withdrawn.revoked_at,
                      who: personName(t, directory, withdrawn.revoked_by),
                    }
                  )}
            </p>
            <p className="mt-2">
              {t(
                'record.qr.withdrawn.consequence',
                'Every copy already printed still carries that symbol, and scanning one no longer confirms anything. A withdrawal cannot be reversed — issuing a code now creates a different one and does not bring this back.'
              )}
            </p>
          </AlertDescription>
        </Alert>
      )}

      {strandedSuperseded !== null && (
        <Alert variant="warning" data-testid="document-record-qr-stranded">
          <AlertTitle>{t('record.qr.stranded.title', 'No code is in force')}</AlertTitle>
          <AlertDescription>
            {t(
              'record.qr.stranded.body',
              'Reference {reference} was retired {when} when a newer code was issued, but this document is not carrying one now.',
              {
                reference: strandedSuperseded.reference,
                when:
                  formatRecordDateTime(strandedSuperseded.revoked_at, locale) ??
                  strandedSuperseded.revoked_at,
              }
            )}
          </AlertDescription>
        </Alert>
      )}

      {live_ === null && lastRetired === null && data.enabled && data.configured && (
        <p className="text-sm text-muted-foreground" data-testid="document-record-qr-none">
          {t(
            'record.qr.none',
            'This document does not carry a verification code. Issuing one gives it a symbol anyone can scan to confirm it is genuine — it appears on versions issued from now on, and copies already printed are unchanged.'
          )}
        </p>
      )}

      {actError !== null && (
        <p className="text-sm text-destructive" data-testid="document-record-qr-act-error">
          {actError}
        </p>
      )}

      {canWrite && (
        <div className="flex flex-wrap gap-2" data-testid="document-record-qr-actions">
          <Button
            // Prominent ONLY for a document that has never carried a code,
            // where issuing one is the obvious next step. After a withdrawal it
            // is deliberately quiet: an operator who has just decided a code is
            // not to be trusted should not be met with a primary button that
            // reads as the recommended fix, and the code it would issue is a
            // different one that appears on nothing already printed.
            variant={live_ === null && lastRetired === null ? 'default' : 'outline'}
            onClick={() => setPending('mint')}
            disabled={mintBlocked || busy !== null}
            data-testid="document-record-qr-mint"
          >
            {live_ === null
              ? t('record.qr.action.issue', 'Issue a code')
              : // Never "refresh": minting retires the previous code, and
                // re-rendering — which does not — is a different act entirely.
                t('record.qr.action.rotate', 'Issue a new code')}
          </Button>

          {/*
            OFFERED ONLY WHILE A LIVE CODE EXISTS. The route answers 204 whether
            or not one was live, so the server would accept a second withdrawal
            silently; the point of not rendering the control is that an operator
            is never shown a destructive act with nothing to destroy, and is
            never given a reason to believe a withdrawn code could be withdrawn
            again — or un-withdrawn.
          */}
          {live_ !== null && (
            <Button
              variant="destructive"
              onClick={() => setPending('withdraw')}
              disabled={busy !== null}
              data-testid="document-record-qr-withdraw"
            >
              {t('record.qr.action.withdraw', 'Withdraw this code')}
            </Button>
          )}
        </div>
      )}

      {data.retired.total > 0 && (
        <div className="space-y-2" data-testid="document-record-qr-retired">
          <Separator />
          <p className="text-xs font-medium text-foreground">
            {t('record.qr.retired.title', 'Codes this document no longer honours')}
          </p>
          <p className="text-xs text-muted-foreground">
            {t(
              'record.qr.retired.why',
              'Kept so somebody holding an older printing can be told which code it carries, and when it stopped being honoured.'
            )}
          </p>
          <RecordList>
            {data.retired.recent.map((code) => (
              <RecordListItem
                key={`${code.reference}-${code.revoked_at}`}
                primary={<span className="font-mono">{code.reference}</span>}
                secondary={retiredLine(t, locale, code)}
              />
            ))}
          </RecordList>
          {data.retired.total > data.retired.recent.length && (
            <p className="text-xs text-muted-foreground" data-testid="document-record-qr-retired-more">
              {t('record.qr.retired.more', 'Showing the {shown} most recent of {total}.', {
                shown: data.retired.recent.length,
                total: data.retired.total,
              })}
            </p>
          )}
        </div>
      )}

      <Separator />

      <ScanTrail data={data} directory={directory} t={t} locale={locale} />

      {pending === 'withdraw' && live_ !== null && (
        <WithdrawDialog
          t={t}
          documentTitle={documentTitle}
          reference={live_.reference}
          versionCount={versionCount}
          scanTotal={data.scans.total}
          submitting={busy === 'withdraw'}
          onCancel={() => setPending(null)}
          onConfirm={() => void act('withdraw')}
        />
      )}

      {pending === 'mint' && (
        <MintDialog
          t={t}
          documentTitle={documentTitle}
          replacing={live_?.reference ?? null}
          submitting={busy === 'mint'}
          onCancel={() => setPending(null)}
          onConfirm={() => void act('mint')}
        />
      )}
    </div>
  );
}

/**
 * Fill in what a payload did not carry, so a MISSING half never becomes a blank
 * record page.
 *
 * `retired` arrived with #1052; a web build deployed against a backend that
 * predates it gets a payload without the key, and `data.retired.recent` would
 * then throw during render. A throw in one region is not contained — it takes
 * the whole record page with it, so a mixed-version deployment would lose the
 * document, its versions and its trail to a panel that could simply have said
 * "no retired codes".
 *
 * WHAT IS NOT DEFAULTED HERE, and this is the important half: nothing that
 * would state a fact the server did not. `enabled` and `configured` default to
 * FALSE, so an unanswered instance renders as one that cannot mint rather than
 * one that can — the direction that withholds a control rather than offering a
 * broken one. Absent lists default to empty WITH a zero total, which is what
 * "the server sent none" honestly means; an absent total beside present rows
 * would be the "unknown rendered as zero" failure, and that case cannot arise
 * because the two always travel together.
 */
function normalize(data: Partial<DocumentQrPanelData>): DocumentQrPanelData {
  return {
    enabled: data.enabled === true,
    configured: data.configured === true,
    token: data.token ?? null,
    retired: {
      total: data.retired?.total ?? 0,
      recent: data.retired?.recent ?? [],
    },
    scans: {
      total: data.scans?.total ?? 0,
      recent: data.scans?.recent ?? [],
    },
  };
}

/**
 * One retired code's detail line.
 *
 * The VERB is rendered, not a generic "retired": `withdrawn` and `superseded`
 * mean opposite things to whoever holds the sheet — one says the organisation
 * stopped standing behind the document, the other says their copy is an older
 * printing of a document that is fine. An unrecognised verb renders as itself
 * rather than as a blank, so a newer server cannot make a row say nothing.
 */
function retiredLine(t: Translate, locale: string | undefined, code: QrRetiredCode): string {
  const when = formatRecordDateTime(code.revoked_at, locale) ?? code.revoked_at;
  if (code.reason === 'withdrawn') {
    return t('record.qr.retired.withdrawn', 'Withdrawn {when}', { when });
  }
  if (code.reason === 'superseded') {
    return t('record.qr.retired.superseded', 'Replaced by a newer code {when}', { when });
  }
  return t('record.qr.retired.other', 'Retired {when} ({reason})', { when, reason: code.reason });
}

/**
 * The scan trail — WHAT HAPPENED, never WHO VISITED.
 *
 * The paragraph above the table is not decoration. Without it a reader looks at
 * a "Who" column full of "A member of the public" and concludes the system knows
 * who they are and is withholding it, or that the lookup failed. Neither is
 * true: `document_qr_scans` has no address, user-agent, device or location
 * column, so there is nothing to withhold and nothing that failed. Saying so is
 * the only way the absence reads as a guarantee rather than as a gap.
 *
 * The coalescing window is stated for a different reason: a count that silently
 * merges reloads is not a page-view log, and an operator comparing "12 scans" to
 * a courier's account of the day should know the number is scans, not requests.
 */
function ScanTrail({
  data,
  directory,
  t,
  locale,
}: {
  data: DocumentQrPanelData;
  directory: Directory;
  t: Translate;
  locale: string | undefined;
}) {
  const anyRefused = data.scans.recent.some((scan) => scan.outcome === 'refused');

  return (
    <div className="space-y-2" data-testid="document-record-qr-scans">
      {/*
        A KEY PER GRAMMATICAL CASE, chosen here, because this platform's `t()`
        does no plural selection — it interpolates and nothing else. Rendering
        "Scanned 1 times." was on screen in the browser pass, which is the kind
        of thing types cannot see and a passing test suite will happily ship.
        Choosing in the component also lets each language phrase its own way:
        the Arabic plural form is written count-neutral rather than trying to
        satisfy its number-noun agreement with one string.
      */}
      <p className="text-xs font-medium text-foreground" data-testid="document-record-qr-scan-total">
        {data.scans.total === 0
          ? t('record.qr.scans.none', 'This document has not been scanned.')
          : data.scans.total === 1
            ? t('record.qr.scans.totalOne', 'Scanned once.')
            : t('record.qr.scans.total', 'Scanned {count} times.', { count: data.scans.total })}
      </p>

      <p className="text-xs text-muted-foreground">
        {t(
          'record.qr.scans.privacy',
          'A scan records the act, not the visitor. For a public scan nothing about the scanner is stored — no address, no device, no location — because there is no column that could hold it. Repeat scans of the same code within a minute count once, so this is a record of scans rather than of page views.'
        )}
      </p>

      {data.scans.recent.length > 0 && (
        <>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('record.qr.scans.when', 'When')}</TableHead>
                  <TableHead>{t('record.qr.scans.who', 'Who')}</TableHead>
                  <TableHead>{t('record.qr.scans.result', 'Result')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.scans.recent.map((scan) => (
                  <TableRow key={scan.id} data-testid={`document-record-qr-scan-${scan.id}`}>
                    <TableCell>
                      {formatRecordDateTime(scan.scanned_at, locale) ?? scan.scanned_at}
                    </TableCell>
                    <TableCell>
                      {scan.scanner_profile_id === null
                        ? // NOT "unknown" and NOT an empty cell. Both would read
                          // as a person the system failed to name. Nobody was
                          // named because nobody was recorded.
                          t('record.qr.scans.public', 'A member of the public')
                        : personName(t, directory, scan.scanner_profile_id)}
                    </TableCell>
                    <TableCell>
                      <ScanOutcome outcome={scan.outcome} t={t} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>

          {data.scans.total > data.scans.recent.length && (
            <p className="text-xs text-muted-foreground" data-testid="document-record-qr-scans-more">
              {t('record.qr.scans.more', 'Showing the {shown} most recent of {total}.', {
                shown: data.scans.recent.length,
                total: data.scans.total,
              })}
            </p>
          )}

          {anyRefused && (
            <p className="text-xs text-muted-foreground" data-testid="document-record-qr-refused-note">
              {t(
                'record.qr.scans.refusedNote',
                '“Not confirmed” means the code had already been withdrawn or replaced when it was scanned, so the public page did not confirm the document.'
              )}
            </p>
          )}
        </>
      )}
    </div>
  );
}

/** The two outcomes, with an unknown one rendered as itself rather than dropped. */
function ScanOutcome({ outcome, t }: { outcome: string; t: Translate }) {
  if (outcome === 'verified') {
    return <Badge variant="success">{t('record.qr.outcome.verified', 'Confirmed')}</Badge>;
  }
  if (outcome === 'refused') {
    return <Badge variant="destructive">{t('record.qr.outcome.refused', 'Not confirmed')}</Badge>;
  }
  return <Badge variant="secondary">{outcome}</Badge>;
}

/**
 * The withdraw confirm — where the irreversible act is made legible BEFORE the
 * click rather than explained after it.
 *
 * It names what stops working in concrete terms — this reference, this many
 * issued versions, this many scans already recorded — because "this cannot be
 * undone" on its own is a phrase people click past. And it states plainly that
 * issuing a new code later is a DIFFERENT code, since the natural assumption
 * from every other undo-shaped control in software is that something can be put
 * back.
 */
function WithdrawDialog({
  t,
  documentTitle,
  reference,
  versionCount,
  scanTotal,
  submitting,
  onCancel,
  onConfirm,
}: {
  t: Translate;
  documentTitle: string;
  reference: string;
  versionCount: number;
  scanTotal: number;
  submitting: boolean;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  return (
    <Dialog open onOpenChange={(open) => !open && onCancel()}>
      <DialogContent className="sm:max-w-lg" data-testid="document-record-qr-withdraw-dialog">
        <DialogHeader>
          <DialogTitle>
            {t('record.qr.withdrawDialog.title', 'Withdraw the verification code from “{title}”?', {
              title: documentTitle,
            })}
          </DialogTitle>
          <DialogDescription>
            {t('record.qr.withdrawDialog.reference', 'Reference {reference}.', { reference })}
          </DialogDescription>
        </DialogHeader>

        <Alert variant="destructive">
          <AlertTitle>
            {t('record.qr.withdrawDialog.finalTitle', 'This cannot be undone')}
          </AlertTitle>
          <AlertDescription>
            {t(
              'record.qr.withdrawDialog.finalBody',
              'A code can never be un-withdrawn. Issuing one later creates a different code; it does not bring this one back, and it does not change paper that is already printed.'
            )}
          </AlertDescription>
        </Alert>

        <div className="space-y-1 text-sm text-muted-foreground">
          <p>
            {versionCount === 1
              ? t(
                  'record.qr.withdrawDialog.paperOne',
                  'The one issued version of this document carries this code. Every copy already printed keeps the symbol and stops confirming anything.'
                )
              : t(
                  'record.qr.withdrawDialog.paper',
                  'All {versions} issued versions of this document carry this code. Every copy already printed keeps the symbol and stops confirming anything.',
                  { versions: versionCount }
                )}
          </p>
          <p>
            {t(
              'record.qr.withdrawDialog.scanner',
              'Anyone who scans it from now on — a courier, a clerk, somebody holding your letter — is refused a confirmation.'
            )}
          </p>
          <p data-testid="document-record-qr-withdraw-scans">
            {scanTotal === 0
              ? t(
                  'record.qr.withdrawDialog.scansNone',
                  'No scan of this code has been recorded yet.'
                )
              : scanTotal === 1
                ? t(
                    'record.qr.withdrawDialog.scansOne',
                    'One scan of it is already recorded.'
                  )
                : t('record.qr.withdrawDialog.scans', '{count} scans of it are already recorded.', {
                    count: scanTotal,
                  })}
          </p>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onCancel} disabled={submitting}>
            {t('record.qr.cancel', 'Cancel')}
          </Button>
          <Button
            variant="destructive"
            onClick={onConfirm}
            loading={submitting}
            data-testid="document-record-qr-withdraw-confirm"
          >
            {t('record.qr.withdrawDialog.submit', 'Withdraw the code')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * The mint confirm.
 *
 * Destructive in a quieter way than the withdrawal, and worth confirming for
 * exactly that reason: pressing it retires the code on every sheet already
 * printed, and the word most people would reach for — "refresh" — describes
 * something else entirely.
 *
 * The second alert is the sentence this dialog exists for. Minting rotates the
 * TOKEN; it does not re-render the document. So the new code appears on nothing
 * until the document is issued again, and an operator who assumed otherwise has
 * just voided their paper and printed no replacement.
 */
function MintDialog({
  t,
  documentTitle,
  replacing,
  submitting,
  onCancel,
  onConfirm,
}: {
  t: Translate;
  documentTitle: string;
  /** The reference about to be retired, or null when there is nothing to retire. */
  replacing: string | null;
  submitting: boolean;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  return (
    <Dialog open onOpenChange={(open) => !open && onCancel()}>
      <DialogContent className="sm:max-w-lg" data-testid="document-record-qr-mint-dialog">
        <DialogHeader>
          <DialogTitle>
            {replacing === null
              ? t('record.qr.mintDialog.titleFirst', 'Issue a verification code for “{title}”?', {
                  title: documentTitle,
                })
              : t('record.qr.mintDialog.titleRotate', 'Issue a new verification code for “{title}”?', {
                  title: documentTitle,
                })}
          </DialogTitle>
          <DialogDescription>
            {t(
              'record.qr.mintDialog.subtitle',
              'A code identifies this document so anyone can confirm it is genuine. It never grants access to it.'
            )}
          </DialogDescription>
        </DialogHeader>

        {replacing !== null && (
          <Alert variant="warning">
            <AlertTitle>
              {t('record.qr.mintDialog.retiresTitle', 'The current code stops being honoured')}
            </AlertTitle>
            <AlertDescription>
              {t(
                'record.qr.mintDialog.retiresBody',
                'Reference {reference} is retired the moment this succeeds. Every copy already printed carries it, and scanning one will no longer confirm this document.',
                { reference: replacing }
              )}
            </AlertDescription>
          </Alert>
        )}

        <Alert variant="info">
          <AlertTitle>
            {t('record.qr.mintDialog.printTitle', 'Paper already printed does not change')}
          </AlertTitle>
          <AlertDescription>
            {t(
              'record.qr.mintDialog.printBody',
              'Issuing a code does not re-render this document. The new code appears on versions issued from now on, so nothing in circulation carries it until a corrected version is issued.'
            )}
          </AlertDescription>
        </Alert>

        <DialogFooter>
          <Button variant="outline" onClick={onCancel} disabled={submitting}>
            {t('record.qr.cancel', 'Cancel')}
          </Button>
          <Button
            onClick={onConfirm}
            loading={submitting}
            data-testid="document-record-qr-mint-confirm"
          >
            {replacing === null
              ? t('record.qr.mintDialog.submitFirst', 'Issue the code')
              : t('record.qr.mintDialog.submitRotate', 'Issue the new code')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
