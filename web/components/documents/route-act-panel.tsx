'use client';

/**
 * Acting on a routed document — forward, acknowledge, return, or note
 * (#978, over #989's engine).
 *
 * THE VOCABULARY IS THE ENGINE'S, NOT THIS SCREEN'S
 * -------------------------------------------------
 * Four buttons, and every one of them is a verb from the CHECK constraint in
 * migration 112. `RouteAction::recipientActions()` is `forwarded`,
 * `acknowledged`, `returned`; `noted` is open to anyone who may see the
 * document. That is the whole set a person can produce.
 *
 * There is NO "Approve". It is the first word anybody reaches for and the engine
 * does not have it: the terminal act is `acknowledged` — "this goes no further
 * along my chain" — and approval as a DECISION is something #989 deliberately
 * does not model (its "configured effects" seam records the distinction:
 * routing says who must act and when a step is satisfied; a judgement about the
 * content is not routing). Labelling this button "Approve" would put a word in
 * front of the user that the append-only trail does not contain, and the trail
 * is the thing anybody auditing this will read.
 *
 * WHY A DENIED ACTION IS DISABLED WITH ITS REASON (#951)
 * -----------------------------------------------------
 * Three of these four can be unavailable, and each has a DIFFERENT cause that a
 * hidden button would render identically:
 *
 *  - you have no open item on this route (you already acted, or it never
 *    reached you);
 *  - you are standing on the last step, so there is nothing to forward to;
 *  - your item came from the first step, so there is no predecessor to return
 *    it to.
 *
 * All three are states the person can do something about — acknowledge instead,
 * add a note instead — and the reason is what tells them which. #951 landed
 * because a hidden control made unrelated causes look the same.
 *
 * WHY THE PREDICATES ARE EVALUATED HERE AT ALL
 * --------------------------------------------
 * They duplicate three checks `DocumentRouter::act()` makes. That is a real cost
 * and the alternative is worse: enabling all four and letting people discover
 * the refusal by clicking turns an explainable state into a failed request, and
 * the person still has to be told the same sentence afterwards.
 *
 * What makes the duplication safe is that it is not a second SOURCE of truth:
 *
 *  - every input is a field the server published for this purpose — `open` (its
 *    own derivation of `closed_by_event_id IS NULL`), `parent_recipient_id`, and
 *    the steps' `position` values;
 *  - the reason SENTENCES are the engine's own, quoted from `DocumentRouter`, so
 *    the two cannot say different things about the same state;
 *  - the server re-checks on submit regardless, and its 422 body is surfaced
 *    VERBATIM rather than re-keyed. If this component's reading is ever wrong,
 *    the engine's answer is the one the user sees.
 *
 * Deliberately NOT gated on a permission. Acting is self-service: being a
 * recipient IS the authorization, which is why `POST .../actions` is registered
 * unpermissioned. Migration 113 records why — a route that resolved to somebody
 * who then needs a grant to answer it leaves the item open forever with no way
 * for them to find out why.
 */

import { useMemo, useState } from 'react';
import { Button } from '@amroksaleh/ui/button';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import type {
  ActOnRouteResponse,
  DocumentRoute,
  RecipientActionName,
  RouteRecipient,
} from './routing-wire';

/**
 * The server's own ceiling on a note, from `DocumentRoutingApiHandler`.
 *
 * Mirrored rather than discovered, because the server does not publish it — and
 * it is a structural property of an append-only record (a megabyte pasted into a
 * note can never be edited down, and every future reader of that trail pays for
 * it) rather than a tenant-tunable capacity, so it will not drift per install.
 */
export const MAX_NOTE_LENGTH = 4000;

/** One button's availability, and why it is what it is. */
interface ActionAvailability {
  allowed: boolean;
  /** The engine's own sentence. Null only when allowed. */
  reason: string | null;
}

export interface RouteActPanelProps {
  documentId: number;
  route: DocumentRoute;
  /** Every recipient row of this document, from `GET .../recipients`. */
  recipients: RouteRecipient[];
  viewerProfileId: number | null;
  /** Called after a successful act so the host can refetch. */
  onActed: () => void;
}

export function RouteActPanel({
  documentId,
  route,
  recipients,
  viewerProfileId,
  onActed,
}: RouteActPanelProps) {
  const t = useTranslation('documents');
  const { apiClient } = useAuth();
  const { addToast } = useToast();

  const [note, setNote] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  /**
   * The server's refusal, held on the panel rather than only toasted.
   *
   * A 422 from the engine is a sentence the person needs while they decide what
   * to do instead, and a toast that has already faded is not available then.
   */
  const [refusal, setRefusal] = useState<string | null>(null);

  /** The viewer's own OPEN item on this route, if they have one. */
  const myOpenItem = useMemo<RouteRecipient | null>(() => {
    if (viewerProfileId === null) return null;
    return (
      recipients.find(
        (r) => r.route_id === route.id && r.profile_id === viewerProfileId && r.open
      ) ?? null
    );
  }, [recipients, route.id, viewerProfileId]);

  const myStep = useMemo(
    () => (myOpenItem === null ? null : route.steps.find((s) => s.id === myOpenItem.step_id) ?? null),
    [myOpenItem, route.steps]
  );

  /**
   * Whether a later step exists. Mirrors `RouteStepRepository::findNext()` —
   * the smallest `position` strictly greater than mine — rather than comparing
   * against the maximum, because `position` is a unique authoring ordinal and
   * need not be contiguous.
   */
  const hasNextStep = useMemo(() => {
    if (myStep === null) return false;
    return route.steps.some((s) => s.position > myStep.position);
  }, [myStep, route.steps]);

  const noOpenItemReason = t(
    'routing.act.denied.noOpenItem',
    'You have no open item on this route. An item you have already acted on cannot be acted on again — add a note instead, which appends to the trail without changing what happened.'
  );

  const availability = useMemo<Record<RecipientActionName, ActionAvailability>>(() => {
    if (myOpenItem === null) {
      const denied: ActionAvailability = { allowed: false, reason: noOpenItemReason };
      return { forwarded: denied, acknowledged: denied, returned: denied };
    }

    return {
      forwarded: hasNextStep
        ? { allowed: true, reason: null }
        : {
            allowed: false,
            reason: t(
              'routing.act.denied.lastStep',
              'This is the last step of the route, so there is nothing to forward to. Acknowledge it instead.'
            ),
          },
      // Legal at ANY step, not only the last: a person who is the intended end
      // of their own branch says so here, and the other chains are unaffected
      // because no aggregate could be waiting on them.
      acknowledged: { allowed: true, reason: null },
      returned:
        myOpenItem.parent_recipient_id !== null
          ? { allowed: true, reason: null }
          : {
              allowed: false,
              reason: t(
                'routing.act.denied.firstStep',
                'This item came from the first step of the route, so there is no earlier recipient to return it to. Acknowledge it, or add a note explaining the problem.'
              ),
            },
    };
  }, [myOpenItem, hasNextStep, noOpenItemReason, t]);

  const submit = async (action: RecipientActionName | 'noted'): Promise<void> => {
    setBusy(action);
    setRefusal(null);
    try {
      const trimmed = note.trim();
      const response = await apiClient(
        `/api/v1/documents/${documentId}/routes/${route.id}/actions`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action,
            // Omitted rather than sent empty: the column is nullable and a
            // zero-length note is a row in an append-only table that says
            // nothing.
            ...(trimmed === '' ? {} : { note: trimmed }),
          }),
        }
      );

      const body = (await response.json().catch(() => null)) as
        | (ActOnRouteResponse & { error?: string })
        | null;

      if (!response.ok) {
        // The server's own sentence, verbatim. Never re-keyed: the engine's
        // refusals are written for the person who hit them and name the thing to
        // do instead.
        const message =
          body?.error ?? t('routing.act.error', 'The action could not be recorded.');
        setRefusal(message);
        addToast(message, 'error');
        return;
      }

      setNote('');
      if (action === 'noted') {
        addToast(t('routing.act.noted.ok', 'Note added to the trail.'), 'success');
      } else {
        // BOTH counts, because they answer different questions and the engine
        // sends both. See RoutingOutcome.
        addToast(
          t(
            'routing.act.forwarded.ok',
            'Recorded. The next step resolved to {resolved} people and reached {delivered}.',
            { resolved: body?.resolved ?? 0, delivered: body?.delivered ?? 0 }
          ),
          'success'
        );
      }
      onActed();
    } catch {
      const message = t('routing.act.error.network', 'The action could not be sent.');
      setRefusal(message);
      addToast(message, 'error');
    } finally {
      setBusy(null);
    }
  };

  const overLimit = note.length > MAX_NOTE_LENGTH;

  return (
    <div className="space-y-3" data-slot="route-act-panel">
      <div>
        <h4 className="text-sm font-semibold text-foreground">
          {t('routing.act.heading', 'Your action on this route')}
        </h4>
        <p className="mt-1 text-xs text-muted-foreground">
          {myOpenItem === null
            ? t(
                'routing.act.noItem',
                'Nothing on this route is awaiting you. You can still add a note — the trail has no edit path, so a correction is appended beside what it corrects.'
              )
            : myStep === null
              ? t('routing.act.itemUnknownStep', 'You have an open item on this route.')
              : t('routing.act.item', 'You have an open item at step {position}.', {
                  position: myStep.position,
                })}
        </p>
      </div>

      <div>
        <label htmlFor={`route-note-${route.id}`} className="text-xs font-medium text-foreground">
          {t('routing.act.note.label', 'Note (optional, appended to the trail)')}
        </label>
        <textarea
          id={`route-note-${route.id}`}
          value={note}
          onChange={(e) => setNote(e.target.value)}
          rows={3}
          className="mt-1 w-full rounded-md border border-border bg-background p-2 text-sm text-foreground"
          placeholder={t('routing.act.note.placeholder', 'What should the record say?')}
        />
        <p
          className={`mt-1 text-xs ${overLimit ? 'text-destructive' : 'text-muted-foreground'}`}
          aria-live="polite"
        >
          {t('routing.act.note.count', '{used} of {max} characters', {
            used: note.length,
            max: MAX_NOTE_LENGTH,
          })}
        </p>
      </div>

      <div className="flex flex-wrap gap-2">
        {(['forwarded', 'acknowledged', 'returned'] as const).map((action) => (
          <ActButton
            key={action}
            action={action}
            availability={availability[action]}
            busy={busy === action}
            disabledByNote={overLimit}
            onClick={() => void submit(action)}
            t={t}
          />
        ))}
        {/*
          `noted` closes nothing and opens nothing, so it needs no open item and
          has no refusal case of its own — it is the one act available to anyone
          who may see the document. It is the CORRECTION mechanism: the trail has
          no update and no delete path, so a mistaken note or a wrong unit is
          answered by appending beside it.
        */}
        <Button
          variant="outline"
          disabled={busy !== null || overLimit || note.trim() === ''}
          title={
            note.trim() === ''
              ? t('routing.act.noted.needsText', 'A note needs some text to append.')
              : undefined
          }
          onClick={() => void submit('noted')}
        >
          {t('routing.act.noted', 'Add note')}
        </Button>
      </div>

      {refusal !== null && (
        <Alert variant="destructive" data-slot="route-act-refusal">
          <AlertDescription>{refusal}</AlertDescription>
        </Alert>
      )}
    </div>
  );
}

interface ActButtonProps {
  action: RecipientActionName;
  availability: ActionAvailability;
  busy: boolean;
  disabledByNote: boolean;
  onClick: () => void;
  t: (key: string, fallback?: string, vars?: Record<string, string | number>) => string;
}

/**
 * One act, disabled WITH its reason when it is not available (#951).
 *
 * The reason is carried three ways, following `PermissionButton`: a `title` for
 * hover, an `sr-only role="note"` for assistive technology (a native title on a
 * disabled control is not reliably announced), and — unlike `PermissionButton` —
 * as visible help text underneath, which is the `RailButton` shape from the
 * document organizer. Hover alone is touch-inaccessible, and these sentences are
 * long enough to be worth reading rather than discovering.
 *
 * The wrapping span is load-bearing: a disabled button emits no pointer events
 * of its own, so a `title` on the button itself never fires.
 */
function ActButton({ action, availability, busy, disabledByNote, onClick, t }: ActButtonProps) {
  const labels: Record<RecipientActionName, string> = {
    forwarded: t('routing.act.forward', 'Forward'),
    // Not "Approve" — see the file docblock.
    acknowledged: t('routing.act.acknowledge', 'Acknowledge'),
    returned: t('routing.act.return', 'Return to sender'),
  };

  const disabled = !availability.allowed || busy || disabledByNote;
  const reason = availability.allowed ? null : availability.reason;

  return (
    <div className="flex flex-col">
      <span className="inline-flex" title={reason ?? undefined}>
        <Button
          variant={action === 'returned' ? 'outline' : 'default'}
          disabled={disabled}
          aria-disabled={disabled}
          onClick={disabled ? undefined : onClick}
          data-slot={`route-act-${action}`}
        >
          {labels[action]}
        </Button>
        {reason !== null && (
          <span className="sr-only" role="note">
            {reason}
          </span>
        )}
      </span>
      {reason !== null && (
        <p className="mt-1 max-w-xs text-xs text-muted-foreground">{reason}</p>
      )}
    </div>
  );
}
