'use client';

/**
 * Acting on a routed document — forward, acknowledge, return, note, and (on a
 * decision step) approve or reject (#978 over #989's engine, #1041 over #1030's).
 *
 * THE VOCABULARY IS THE ENGINE'S, NOT THIS SCREEN'S
 * -------------------------------------------------
 * Every control here is a verb from the CHECK constraint in migration 112.
 * `RouteAction::recipientActions()` is `forwarded`, `acknowledged`, `returned`;
 * `noted` is open to anyone who may see the document. That is the whole set a
 * person can produce.
 *
 * WHY THERE IS AN "APPROVE" BUTTON NOW, AND WHAT IT POSTS
 * ------------------------------------------------------
 * This file used to say, at length, that there is no Approve — that the terminal
 * act is `acknowledged` and approval as a DECISION is something the engine
 * deliberately does not model. That was accurate until #1030 and false the
 * moment migration 119 landed. It is corrected rather than deleted because the
 * SHAPE of the answer is still load-bearing:
 *
 * Approval is a VERDICT on the act, not a sixth verb. A step may be marked
 * `decision`, and answering one posts `acknowledged` carrying
 * `verdict: 'approved' | 'rejected'`. The act still says the actor chose no
 * destination — which is exactly what a gate means — and where the document goes
 * is DERIVED by the engine from the verdict, the step's edges and the quorum.
 * `forwarded` is refused on a gate for the same reason: it means the ACTOR chose
 * the destination, which is the one thing the step exists to take away from them.
 *
 * So the word in front of the user and the word in the trail agree, which is
 * what the old paragraph was protecting.
 *
 * THE ONE THING THIS PANEL MUST NOT GET WRONG
 * -------------------------------------------
 * The action response carries `decided` — what the STEP concluded — and it is
 * NULL while a quorum is still short. Under the default quorum of `all`, two of
 * three approvals conclude nothing.
 *
 * This panel renders `decided`, never the verdict the caller just submitted. The
 * difference is invisible in the single-approver case that is most of them, and
 * in the fan-out case that the whole feature exists for it is the difference
 * between "your approval is recorded, the others have not answered" and telling
 * two people that a document was authorised before it was — confidently, in the
 * one place they would go to check. {@link readDecided} is shaped so that the
 * substitution cannot be written: it never receives the caller's verdict.
 *
 * A NULL VERDICT IS NOT A REJECTION, AND NOT A PENDING DECISION
 * ------------------------------------------------------------
 * Every act on a circulation step, every note, and every event written before
 * migration 119 has `verdict = null`. This panel therefore renders nothing at
 * all about approval unless the step the viewer is standing on is a decision —
 * no "not approved", no "awaiting a verdict", no greyed-out approve button on a
 * step that will never take one.
 *
 * WHY A DENIED ACTION IS DISABLED WITH ITS REASON (#951)
 * -----------------------------------------------------
 * An act can be unavailable for causes a hidden button would render identically:
 *
 *  - you have no open item on this route (you already acted, or it never
 *    reached you);
 *  - you are standing on the last step, so there is nothing to forward to;
 *  - your item came from the first step, so there is no predecessor to return
 *    it to;
 *  - you are standing on a DECISION step, where forwarding is refused outright.
 *
 * All four are states the person can do something about — approve instead,
 * acknowledge instead, add a note instead — and the reason is what tells them
 * which. #951 landed because a hidden control made unrelated causes look alike.
 *
 * WHY THE PREDICATES ARE EVALUATED HERE AT ALL
 * --------------------------------------------
 * They duplicate checks `DocumentRouter::act()` makes. That is a real cost and
 * the alternative is worse: enabling everything and letting people discover the
 * refusal by clicking turns an explainable state into a failed request, and the
 * person still has to be told the same sentence afterwards.
 *
 * What makes the duplication safe is that it is not a second SOURCE of truth:
 *
 *  - every input is a field the server published for this purpose — `open` (its
 *    own derivation of `closed_by_event_id IS NULL`), `parent_recipient_id`,
 *    `decision`, `decision_quorum`, `default_quorum`, and the steps' `position`
 *    values;
 *  - the reason SENTENCES are the engine's own, quoted from `DocumentRouter`, so
 *    the two cannot say different things about the same state;
 *  - the server re-checks on submit regardless, and its 422 body is surfaced
 *    VERBATIM rather than re-keyed. If this component's reading is ever wrong,
 *    the engine's answer is the one the user sees.
 *
 * The one thing deliberately NOT duplicated is the quorum arithmetic. This panel
 * names the RULE and the size of the audience the step was put to, and never a
 * tally: the recipients endpoint publishes no closing verdict, and the engine
 * also drops from its denominator anybody who is no longer an active member. A
 * client-side count would be wrong in both directions and wrong invisibly.
 *
 * Deliberately NOT gated on a permission. Acting is self-service: being a
 * recipient IS the authorization, which is why `POST .../actions` is registered
 * unpermissioned. Migration 113 records why — a route that resolved to somebody
 * who then needs a grant to answer it leaves the item open forever with no way
 * for them to find out why. A verdict is no different: whether one is available
 * to you is decided by the route that reached you, not by a tenant-wide grant.
 *
 * WHAT THIS PANEL CANNOT TELL YOU, AND SAYS NOTHING ABOUT (#1037)
 * --------------------------------------------------------------
 * How many times this document has already been round this loop. Nothing counts
 * laps, so a document on its ninth rejection is indistinguishable here from one
 * on its first — and this panel is exactly where that would show if it existed.
 * It is not invented: a number nobody derived is worse than an absent one,
 * because it would be believed. The rejection copy names the route's SHAPE
 * ("back to an earlier step for correction") without claiming how often it has
 * been walked.
 */

import { useMemo, useState } from 'react';
import { Button } from '@amroksaleh/ui/button';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { Badge } from '@amroksaleh/ui/badge';
import { Textarea } from '@amroksaleh/ui/textarea';
import { IconCheck, IconX } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import {
  effectiveQuorum,
  readDecided,
  stepCohort,
  type DocumentRoute,
  type RecipientActionName,
  type RouteQuorumName,
  type RouteRecipient,
  type RouteVerdictName,
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

/**
 * What came back from answering a decision step.
 *
 * `submitted` and `decided` are held as two fields because they are two facts,
 * and the panel's whole job here is not to conflate them. `submitted` is only
 * ever used to name the reader's OWN act ("your approval is recorded");
 * `decided` is the only thing allowed to describe the step.
 */
interface DecisionOutcome {
  submitted: RouteVerdictName;
  decided: RouteVerdictName | null;
  resolved: number;
  delivered: number;
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
  /**
   * The step's conclusion, held for the same reason and more urgently.
   *
   * "Your approval is recorded and the step is still waiting on two other
   * people" is the sentence that stops somebody assuming the document has moved.
   * A toast says it for four seconds; this says it until they navigate away.
   */
  const [outcome, setOutcome] = useState<DecisionOutcome | null>(null);

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
   * The viewer's DELIVERED row on this route, if any (#1054/#1064).
   *
   * A delivery stage closes every row the instant it creates them, so a person
   * this route merely TOLD has no open item — and this panel read that as
   * "nothing is awaiting you", then explained it with a sentence about an item
   * "you have already acted on". They never acted. They were sent something.
   *
   * `closed_by_delivery` exists precisely because `open` cannot carry the
   * difference: both a delivered row and an answered row are closed. #1061 added
   * it to the recipient list after that list rendered three hundred delivery
   * rows identically to three hundred people who had acted; this is the same
   * claim, made to the one person it is about.
   *
   * The LAST match rather than the first: a route can reach somebody more than
   * once, and what a panel should describe is the most recent thing that
   * happened to them.
   */
  const myDeliveredItem = useMemo<RouteRecipient | null>(() => {
    if (viewerProfileId === null) return null;
    const mine = recipients.filter(
      (r) => r.route_id === route.id && r.profile_id === viewerProfileId && r.closed_by_delivery
    );
    return mine.length === 0 ? null : mine[mine.length - 1];
  }, [recipients, route.id, viewerProfileId]);

  /**
   * Whether the honest delivery wording applies: nothing is open AND the reason
   * nothing is open is that the document was delivered rather than answered.
   *
   * Both halves matter. Somebody who was delivered to at step 1 and is now
   * holding an open item at step 4 is being asked for something, and the panel
   * must say so rather than reporting the older, quieter fact.
   */
  const wasDeliveredTo = myOpenItem === null && myDeliveredItem !== null;

  const deliveredStep = useMemo(
    () =>
      myDeliveredItem === null
        ? null
        : route.steps.find((s) => s.id === myDeliveredItem.step_id) ?? null,
    [myDeliveredItem, route.steps]
  );

  /**
   * Whether the viewer is being asked for a VERDICT.
   *
   * Read from the step's own `decision` flag, never inferred from the edges: a
   * gate at the end of a route has no outgoing edge and still demands a verdict,
   * so inferring it would render the final sign-off as an ordinary circulation.
   */
  const isDecision = myStep?.decision === true;

  /**
   * How many people this step was put to, in the viewer's own chain.
   *
   * The cohort is one act's rows — see {@link stepCohort}. Not a tally, and the
   * copy that renders it never calls it one.
   */
  const cohortSize = useMemo(
    () => (myOpenItem === null ? 0 : stepCohort(recipients, myOpenItem).length),
    [recipients, myOpenItem]
  );

  const quorum = useMemo<RouteQuorumName>(
    () => (myStep === null ? 'all' : effectiveQuorum(myStep, route)),
    [myStep, route]
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

  // Two reasons, because there are two situations and only one of them is about
  // something the reader did. Told separately rather than softened into one
  // sentence that covers both: a message vague enough to be true of both would
  // stop telling either person what happened to them.
  const noOpenItemReason = wasDeliveredTo
    ? t(
        'routing.act.denied.delivered',
        'This route sent you the document rather than asking you for anything, so there is nothing here to answer. You can still add a note.'
      )
    : t(
        'routing.act.denied.noOpenItem',
        'You have no open item on this route. An item you have already acted on cannot be acted on again — add a note instead, which appends to the trail without changing what happened.'
      );

  const availability = useMemo<Record<RecipientActionName, ActionAvailability>>(() => {
    if (myOpenItem === null || myStep === null) {
      const denied: ActionAvailability = { allowed: false, reason: noOpenItemReason };
      return { forwarded: denied, acknowledged: denied, returned: denied };
    }

    return {
      // A gate refuses a forward outright, and the reason is the engine's own
      // sentence: choosing the destination is precisely what the step exists to
      // take away from the person answering it. Checked BEFORE the last-step
      // rule, because on a decision step "there is nothing to forward to" would
      // be a true sentence about the wrong thing.
      forwarded: myStep.decision
        ? {
            allowed: false,
            reason: t(
              'routing.act.denied.decisionStep',
              'Step {position} is a decision step: it is answered with a verdict, and where the document goes next follows from that verdict. Forwarding would let you choose the destination the step exists to decide.',
              { position: myStep.position }
            ),
          }
        : hasNextStep
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
      // because no aggregate could be waiting on them. On a decision step it is
      // still the act — it is what Approve and Reject post — but it is never
      // offered on its own there, because the engine refuses it without a
      // verdict.
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
  }, [myOpenItem, myStep, hasNextStep, noOpenItemReason, t]);

  /**
   * What to say about a decision that has been recorded.
   *
   * One function, used for BOTH the toast and the panel, so the two can never
   * disagree about whether a document was approved. Every branch is keyed on
   * `decided`; `submitted` names only the reader's own act.
   */
  const decisionMessage = (o: DecisionOutcome): string => {
    if (o.decided === null) {
      // THE CASE THAT MATTERS. The step concluded nothing, so nothing here may
      // say it did — not "approved", not "rejected", not "complete".
      return o.submitted === 'approved'
        ? t(
            'routing.act.decision.pending.approved',
            'Your approval is recorded. This step is not approved yet — it is still waiting on the other people it was put to.'
          )
        : t(
            'routing.act.decision.pending.rejected',
            'Your rejection is recorded. This step is not settled yet — under its approval rule the people who have not answered can still carry it.'
          );
    }

    if (o.decided === 'approved') {
      return o.delivered > 0
        ? t(
            'routing.act.decision.approved.moved',
            // NOT "the next step". An approval with an `approved` edge goes where
            // the edge points, which can be any step in the route including one
            // BEHIND this one; only an approval with no edge falls through to the
            // next authoring ordinal. The response says how many people were
            // reached, not which step reached them, so this says exactly that.
            'Approved. This step is approved and the document has moved on: the step it went to resolved to {resolved} people and reached {delivered}.',
            { resolved: o.resolved, delivered: o.delivered }
          )
        : t(
            'routing.act.decision.approved.ends',
            'Approved. This step is approved, and the route names nowhere for it to go next — so the document goes no further along this chain.'
          );
    }

    return o.delivered > 0
      ? t(
          'routing.act.decision.rejected.moved',
          'Rejected. This step can no longer be approved, and the document has gone where the route sends a rejection: that step resolved to {resolved} people and reached {delivered}.',
          { resolved: o.resolved, delivered: o.delivered }
        )
      : t(
          'routing.act.decision.rejected.ends',
          // "along this chain", not "the document ends here". A document can
          // carry several routes at once and other chains of THIS route can still
          // be open — this panel is inside one route's card and can only speak
          // for the chain the reader is standing in. The wider claim was true of
          // the demo document it was first read on, which is exactly how a
          // sentence like that survives review.
          'Rejected. This step can no longer be approved, and the route draws no path for a rejection — so the document goes no further along this chain. A rejection never falls through to where an approval would have sent it.'
        );
  };

  const submit = async (
    action: RecipientActionName | 'noted',
    verdict?: RouteVerdictName
  ): Promise<void> => {
    setBusy(verdict ?? action);
    setRefusal(null);
    setOutcome(null);
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
            // Likewise omitted on a circulation step, where the engine refuses a
            // verdict outright rather than storing one nothing routes on.
            ...(verdict === undefined ? {} : { verdict }),
          }),
        }
      );

      const body: unknown = await response.json().catch(() => null);

      if (!response.ok) {
        // The server's own sentence, verbatim. Never re-keyed: the engine's
        // refusals are written for the person who hit them and name the thing to
        // do instead.
        const error =
          typeof body === 'object' && body !== null
            ? (body as { error?: unknown }).error
            : undefined;
        const message =
          typeof error === 'string' && error !== ''
            ? error
            : t('routing.act.error', 'The action could not be recorded.');
        setRefusal(message);
        addToast(message, 'error');
        return;
      }

      const counts = (body ?? {}) as { resolved?: number; delivered?: number };

      setNote('');
      if (verdict !== undefined) {
        const settled: DecisionOutcome = {
          submitted: verdict,
          // NOT `verdict`. See the file docblock, and note that `readDecided`
          // is not given the caller's verdict at all, so the fallback that would
          // be wrong here is not expressible.
          decided: readDecided(body),
          resolved: counts.resolved ?? 0,
          delivered: counts.delivered ?? 0,
        };
        setOutcome(settled);
        // 'success' ONLY for an approval the step actually concluded. A recorded
        // verdict that settled nothing is not a success and must not be tinted
        // like one — the tint is read faster than the sentence, and a green
        // toast saying "not approved yet" is the same lie in a different medium.
        addToast(decisionMessage(settled), settled.decided === 'approved' ? 'success' : 'info');
      } else if (action === 'noted') {
        addToast(t('routing.act.noted.ok', 'Note added to the trail.'), 'success');
      } else {
        // BOTH counts, because they answer different questions and the engine
        // sends both. See RoutingOutcome.
        addToast(
          t(
            'routing.act.forwarded.ok',
            'Recorded. The next step resolved to {resolved} people and reached {delivered}.',
            { resolved: counts.resolved ?? 0, delivered: counts.delivered ?? 0 }
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
    <div
      className="space-y-3"
      data-slot="route-act-panel"
      data-decision={isDecision ? 'true' : 'false'}
      // Pinned as an attribute for the same reason `data-decision` is: the
      // wording is translated, so a test that matched on the English sentence
      // would pass while an Arabic reader saw the wrong one.
      data-delivered={wasDeliveredTo ? 'true' : 'false'}
    >
      <div>
        <h4 className="flex flex-wrap items-center gap-2 text-sm font-semibold text-foreground">
          {wasDeliveredTo
            ? // "Your action on this route" over a panel with no action is the
              // heading equivalent of the sentence below it — confidently wrong.
              t('routing.act.delivered.heading', 'This route sent you the document')
            : isDecision
            ? t('routing.act.decision.heading', 'Your decision on this route')
            : t('routing.act.heading', 'Your action on this route')}
          {isDecision && (
            <Badge variant="warning-solid" data-slot="route-act-decision-badge">
              {t('routing.act.decision.badge', 'Decision')}
            </Badge>
          )}
        </h4>
        <p className="mt-1 text-xs text-muted-foreground">
          {wasDeliveredTo
            ? // NOT "nothing is awaiting you". That sentence is true and useless
              // here: it describes an absence, when what actually happened is
              // that somebody sent this person a document deliberately. Naming
              // the step keeps it a fact about the route rather than a shrug.
              deliveredStep === null
              ? t(
                  'routing.act.delivered',
                  'This route sent you the document. You were not asked to act on it, so it is not waiting on you.'
                )
              : t(
                  'routing.act.deliveredAtStep',
                  'Step {position} sent you the document. You were not asked to act on it, so it is not waiting on you.',
                  { position: deliveredStep.position }
                )
            : myOpenItem === null
            ? t(
                'routing.act.noItem',
                'Nothing on this route is awaiting you. You can still add a note — the trail has no edit path, so a correction is appended beside what it corrects.'
              )
            : myStep === null
              ? t('routing.act.itemUnknownStep', 'You have an open item on this route.')
              : isDecision
                ? t(
                    'routing.act.decision.item',
                    'Step {position} is a decision step, and it is asking you to approve or reject. Where the document goes next follows from your verdict rather than from a destination you choose.',
                    { position: myStep.position }
                  )
                : t('routing.act.item', 'You have an open item at step {position}.', {
                    position: myStep.position,
                  })}
        </p>
      </div>

      {/*
        THE ANSWER GOES FIRST.

        It was under the buttons until this was looked at in a browser, and the
        state that exposed the mistake is the one right after acting: the row
        closes, so the panel falls back to the no-open-item rendering — a heading
        that says "Nothing on this route is awaiting you" and three disabled
        controls each repeating why. The one sentence the person needed ("your
        approval is recorded; this step is not approved yet") was below all of
        that, off the bottom of a 1000px viewport.

        Ordering it above the controls is not cosmetics. What you just did, and
        what it concluded, outrank a list of things you may no longer do.
      */}
      {outcome !== null && (
        <Alert
          variant={
            outcome.decided === 'approved'
              ? 'success'
              : outcome.decided === 'rejected'
                ? 'destructive'
                : 'warning'
          }
          data-slot="route-act-outcome"
          // The machine-readable half of the same claim, so a test can pin
          // "the step concluded nothing" without pinning a sentence.
          data-decided={outcome.decided ?? 'pending'}
          data-submitted={outcome.submitted}
        >
          <AlertDescription>{decisionMessage(outcome)}</AlertDescription>
        </Alert>
      )}

      {/*
        THE QUORUM, AND ONLY WHERE IT MEANS ANYTHING.

        `all`, `any` and `majority` are the same rule for a cohort of one, which
        is the overwhelmingly common approval step. They differ exactly where a
        rule fans out to hundreds — the "one node for a thousand instructors"
        case the feature exists for — and that is where somebody genuinely cannot
        tell whether their own approval carries the step. Rendering it for a
        cohort of one would be chrome that explains nothing, every time.
      */}
      {isDecision && cohortSize > 1 && (
        <div
          className="rounded-md border border-border bg-muted/40 p-3 text-xs text-muted-foreground"
          data-slot="route-act-quorum"
          data-quorum={quorum}
        >
          <p className="font-medium text-foreground">
            {t('routing.act.quorum.cohort', 'This step was put to {count} people at once.', {
              count: cohortSize,
            })}
          </p>
          <p className="mt-1">
            {quorum === 'any'
              ? t(
                  'routing.act.quorum.any',
                  'One approval from any of them carries it, so yours may settle it on its own. A rejection decides nothing while anybody is still able to approve.'
                )
              : quorum === 'majority'
                ? t(
                    'routing.act.quorum.majority',
                    'It is approved when more than half of them approve, and refused as soon as a majority in favour has become impossible.'
                  )
                : t(
                    'routing.act.quorum.all',
                    'Every one of them must approve. One rejection settles it, because unanimity is impossible after that.'
                  )}
          </p>
          <p className="mt-1">
            {myStep !== null && myStep.decision_quorum !== null
              ? t('routing.act.quorum.onStep', 'This step names that rule itself.')
              : t(
                  'routing.act.quorum.inherited',
                  'That is this tenant’s rule for approval steps; this step does not override it.'
                )}
          </p>
        </div>
      )}

      <div>
        <label htmlFor={`route-note-${route.id}`} className="text-xs font-medium text-foreground">
          {isDecision
            ? t(
                'routing.act.note.decisionLabel',
                'Reason (optional, appended to the trail beside your verdict)'
              )
            : t('routing.act.note.label', 'Note (optional, appended to the trail)')}
        </label>
        {/*
          The kit's Textarea rather than a bare element. It was a bare one until
          #1041 and the difference is not cosmetic: the kit carries the focus
          ring, the disabled affordances and the `aria-invalid` styling, and a
          hand-rolled copy of a control silently stops tracking the design system
          the other two clients of this component render against.
        */}
        <Textarea
          id={`route-note-${route.id}`}
          value={note}
          onChange={(e) => setNote(e.target.value)}
          rows={3}
          className="mt-1"
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
        {isDecision && (
          <>
            <VerdictButton
              verdict="approved"
              label={t('routing.act.approve', 'Approve')}
              availability={availability.acknowledged}
              busy={busy === 'approved'}
              anyBusy={busy !== null}
              disabledByNote={overLimit}
              onClick={() => void submit('acknowledged', 'approved')}
            />
            <VerdictButton
              verdict="rejected"
              label={t('routing.act.reject', 'Reject')}
              availability={availability.acknowledged}
              busy={busy === 'rejected'}
              anyBusy={busy !== null}
              disabledByNote={overLimit}
              onClick={() => void submit('acknowledged', 'rejected')}
            />
          </>
        )}
        {/*
          On a decision step `acknowledged` is not offered on its own: it is what
          Approve and Reject post, and the engine refuses it there without a
          verdict. Offering it anyway would be a third button that looks like a
          way to answer without deciding and is in fact a 422.

          `forwarded` IS still offered, disabled and carrying the engine's
          reason, because it is the control a person will look for on a step that
          used to have one — #951's whole point.
        */}
        {(isDecision
          ? (['forwarded', 'returned'] as const)
          : (['forwarded', 'acknowledged', 'returned'] as const)
        ).map((action, index, shown) => (
          <ActButton
            key={action}
            action={action}
            availability={availability[action]}
            busy={busy === action}
            anyBusy={busy !== null}
            disabledByNote={overLimit}
            /*
              ONE VISIBLE COPY PER SENTENCE.

              #951's rule is that a denied control carries its reason, and each
              button keeps its own `title` and its own `sr-only role="note"` —
              those are per-control and must stay. What does NOT have to repeat
              is the VISIBLE paragraph: when several controls are denied for the
              SAME cause (which is exactly the no-open-item case, where all three
              share one sentence) the screen printed a 40-word explanation three
              times in a row, and the thing the person actually wanted to read
              was underneath it.

              Repeating a sentence does not make it more findable; it makes
              everything around it less findable.
            */
            showReasonText={
              shown
                .slice(0, index)
                .every((earlier) => availability[earlier].reason !== availability[action].reason)
            }
            onClick={() => void submit(action)}
            t={t}
          />
        ))}
        {/*
          `noted` closes nothing and opens nothing, so it needs no open item and
          has no refusal case of its own — it is the one act available to anyone
          who may see the document. It is the CORRECTION mechanism: the trail has
          no update and no delete path, so a mistaken note or a wrong unit is
          answered by appending beside it. It carries no verdict, and the engine
          refuses one on it: a remark decides nothing and moves nothing.
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
  /** This control's own request is in flight. */
  busy: boolean;
  /**
   * SOME control's request is in flight — including another one's.
   *
   * Every act here is irreversible: the trail has no update path and no delete
   * path. Disabling only the control that was clicked left every OTHER one live
   * while its request was in the air, so a second click during the round trip
   * posted a second act. On a decision step that is Approve and Reject, side by
   * side, and the loser is a 422 — but only because the first act had already
   * closed the row. The ordering is the engine's to enforce and this is not a
   * second opinion about it; it is not offering a person a click whose meaning
   * depends on which request wins.
   */
  anyBusy: boolean;
  disabledByNote: boolean;
  /**
   * Whether to print the reason under THIS control.
   *
   * False when an earlier control in the same row has already printed the same
   * sentence. The `title` and the `sr-only` note are unaffected: they belong to
   * the control, and a screen reader landing on the third button still hears why
   * it is disabled.
   */
  showReasonText: boolean;
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
function ActButton({
  action,
  availability,
  busy,
  anyBusy,
  disabledByNote,
  showReasonText,
  onClick,
  t,
}: ActButtonProps) {
  const labels: Record<RecipientActionName, string> = {
    forwarded: t('routing.act.forward', 'Forward'),
    // Still not "Approve": on a circulation step this act says "this goes no
    // further along my chain" and decides nothing about the content. Approving
    // is a verdict carried BY this act on a decision step, and has its own
    // control there.
    acknowledged: t('routing.act.acknowledge', 'Acknowledge'),
    returned: t('routing.act.return', 'Return to sender'),
  };

  const disabled = !availability.allowed || busy || anyBusy || disabledByNote;
  const reason = availability.allowed ? null : availability.reason;

  return (
    <div className="flex flex-col">
      <span className="inline-flex" title={reason ?? undefined}>
        <Button
          /*
            A DENIED CONTROL IS NEVER THE PRIMARY ONE. A solid primary button at
            50% opacity still reads as the thing to press — which on a decision
            step put a blue "Forward" next to Approve and Reject and made the one
            act the engine REFUSES there the most eye-catching of the three.
            #951 asks for visible-and-explained, not visible-and-inviting.
          */
          variant={action === 'returned' || !availability.allowed ? 'outline' : 'default'}
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
      {reason !== null && showReasonText && (
        <p className="mt-1 max-w-xs text-xs text-muted-foreground">{reason}</p>
      )}
    </div>
  );
}

interface VerdictButtonProps {
  verdict: RouteVerdictName;
  label: string;
  availability: ActionAvailability;
  busy: boolean;
  /** See {@link ActButtonProps.anyBusy} — it matters most on these two. */
  anyBusy: boolean;
  disabledByNote: boolean;
  onClick: () => void;
}

/**
 * Approve or Reject — the same disabled-with-its-reason shape as {@link ActButton}.
 *
 * Both post `acknowledged`; the verdict is what differs, and the `data-slot`
 * names the VERDICT rather than the action so a reader of the DOM (or of a test)
 * sees the distinction the trail records.
 *
 * The two are visually unlike each other on purpose. A reject that looks like an
 * approve is a mis-click that cannot be undone: the trail has no delete path, so
 * the only remedy is a note appended beside a refusal that is already recorded
 * and has already sent the document somewhere else.
 */
function VerdictButton({
  verdict,
  label,
  availability,
  busy,
  anyBusy,
  disabledByNote,
  onClick,
}: VerdictButtonProps) {
  const disabled = !availability.allowed || busy || anyBusy || disabledByNote;
  const reason = availability.allowed ? null : availability.reason;

  return (
    <div className="flex flex-col">
      <span className="inline-flex" title={reason ?? undefined}>
        <Button
          variant={verdict === 'approved' ? 'success-solid' : 'destructive'}
          disabled={disabled}
          aria-disabled={disabled}
          onClick={disabled ? undefined : onClick}
          data-slot={`route-act-verdict-${verdict}`}
        >
          {verdict === 'approved' ? (
            <IconCheck className="size-4 me-1" />
          ) : (
            <IconX className="size-4 me-1" />
          )}
          {label}
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
